<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Migration;

use OC\Core\Command\Maintenance\Mimetype\GenerateMimetypeFileBuilder;
use OCA\N8nSync\AppInfo\Application;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Reverses {@see RegisterMimetype} on app removal (the `<uninstall>` repair step),
 * so removing the app leaves the Nextcloud core tree as it found it — the store's
 * clean-uninstall rule (uninstall.feature). The mirror image of install:
 *   1. Drop our `n8n` / `application/n8n+json` keys from the live config files
 *      (`config/mimetypemapping.json`, `config/mimetypealiases.json`).
 *   2. Delete the icon we copied to `core/img/filetypes/n8n.svg`.
 *   3. Re-stamp every `*.n8n` filecache row back to `application/json`, so the
 *      files become plain JSON again (and any that the user keeps still open).
 *   4. Regenerate `core/js/mimetypelist.js` without our alias.
 *
 * It touches **only** the system registration — never the user's `.n8n` files,
 * their metadata, the mappings, or n8n. Idempotent and fail-soft (a half-present
 * registration reverts cleanly).
 */
final class UnregisterMimetype implements IRepairStep {
	private const APP_MIMETYPE = 'application/n8n+json';
	private const APP_ALIAS_KEY = self::APP_MIMETYPE;
	private const APP_ICON_NAME = 'n8n';
	private const FILE_EXT = 'n8n';

	/**
	 * What a `.n8n` file is once we stop claiming it. Core's detector has no opinion on
	 * the extension any more, so without this the files would fall back to content
	 * sniffing and a generic icon. The bytes are a workflow in JSON, and saying so keeps
	 * them openable by the app this one is about to stop managing them for.
	 */
	private const FALLBACK_MIMETYPE = 'application/json';

	public function __construct(
		private IMimeTypeDetector $detector,
		private IMimeTypeLoader $loader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the n8n_sync mimetype + icon';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$serverRoot = \OC::$SERVERROOT;
		$configDir = \OC::$configDir;

		try {
			$this->removeJsonKey($configDir . 'mimetypemapping.json', self::FILE_EXT);
			$this->removeJsonKey($configDir . 'mimetypealiases.json', self::APP_ALIAS_KEY);
		} catch (\Throwable $e) {
			$this->logger->error('n8n_sync: failed to revert mimetype config', ['exception' => $e]);
			$output->warning('n8n_sync: could not clean config/mimetype*.json (' . $e->getMessage() . ')');
		}

		// Delete the icon we copied into core/img/filetypes/.
		$dst = $serverRoot . '/core/img/filetypes/' . self::APP_ICON_NAME . '.svg';
		if (is_file($dst) && !@unlink($dst)) {
			$output->warning('n8n_sync: could not remove ' . $dst);
		}

		// Re-stamp the filecache rows back to plain JSON so nothing dangles on the
		// now-removed mimetype id. The detector cache is rebuilt because we just
		// edited the on-disk config files.
		$this->detector->getAllMappings(); // primes lazy load (no public reset)
		$jsonId = $this->loader->getId(self::FALLBACK_MIMETYPE);
		$touched = $this->loader->updateFilecache(self::FILE_EXT, $jsonId);
		$output->info(sprintf('n8n_sync: %d filecache row(s) reverted to application/json', $touched));

		// Regenerate core/js/mimetypelist.js without our alias.
		try {
			$gen = new GenerateMimetypeFileBuilder();
			$js = $gen->generateFile(
				$this->detector->getAllAliases(),
				$this->detector->getAllNamings(),
			);
			@file_put_contents($serverRoot . '/core/js/mimetypelist.js', $js);
		} catch (\Throwable $e) {
			$this->logger->error('n8n_sync: failed to regenerate mimetypelist.js', ['exception' => $e]);
		}
	}

	/**
	 * Remove a single key from a JSON object file, atomically (tempfile + rename).
	 * No-op when the file or key is absent. Leaves every other entry untouched.
	 */
	private function removeJsonKey(string $path, string $key): void {
		if (!is_file($path)) {
			return;
		}
		$raw = file_get_contents($path);
		if ($raw === false || trim($raw) === '') {
			return;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !array_key_exists($key, $decoded)) {
			return;
		}
		unset($decoded[$key]);
		$encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			throw new \RuntimeException('json_encode failed for ' . $path);
		}
		$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $encoded) === false) {
			throw new \RuntimeException('write failed: ' . $tmp);
		}
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			throw new \RuntimeException('rename failed: ' . $path);
		}
	}
}
