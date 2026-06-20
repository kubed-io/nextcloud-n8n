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
 * Registers the `application/n8n+json` mimetype + its icon so `.n8n.json`
 * files render with our SVG in the Files row, not the generic "code" glyph.
 *
 * Runs on every install/upgrade. All three steps are idempotent:
 *   1. Merge our extension/alias mappings into the live config files
 *      (`config/mimetypemapping.json`, `config/mimetypealiases.json`) so the
 *      Detection layer + frontend resolver see them.
 *   2. Copy the SVG to `core/img/filetypes/n8n.svg` — that's where
 *      GenerateMimetypeFileBuilder enumerates icon basenames from.
 *   3. Insert the mimetype into `oc_mimetypes`, rewrite filecache rows whose
 *      name ends in `.n8n.json` to that id, and regenerate
 *      `core/js/mimetypelist.js` so the frontend map carries the alias.
 *
 * Equivalent to running `occ maintenance:mimetype:update-db` +
 * `update-js`, but inline with the app's lifecycle: no human step.
 */
final class RegisterMimetype implements IRepairStep {
	private const APP_MIMETYPE = 'application/n8n+json';
	private const APP_ALIAS_KEY = self::APP_MIMETYPE;
	private const APP_ICON_NAME = 'n8n';
	private const FILE_EXT = 'n8n.json';

	public function __construct(
		private IMimeTypeDetector $detector,
		private IMimeTypeLoader $loader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Register the n8n_sync mimetype + icon';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$serverRoot = \OC::$SERVERROOT;
		$appRoot = $serverRoot . '/custom_apps/' . Application::APP_ID;
		// Custom config dir — Server::getServerRoot() . '/config' is the standard
		// location, but kubernetes mounts may place it elsewhere; resolve via OC.
		$configDir = \OC::$configDir;

		try {
			$this->mergeJson(
				$configDir . 'mimetypemapping.json',
				[self::FILE_EXT => [self::APP_MIMETYPE]],
			);
			$this->mergeJson(
				$configDir . 'mimetypealiases.json',
				[self::APP_ALIAS_KEY => self::APP_ICON_NAME],
			);
		} catch (\Throwable $e) {
			$this->logger->error('n8n_sync: failed to merge mimetype config', ['exception' => $e]);
			$output->warning('n8n_sync: could not update config/mimetype*.json (' . $e->getMessage() . ')');
		}

		// Copy SVG into core/img/filetypes/. GenerateMimetypeFileBuilder scans
		// that directory verbatim, so the icon name MUST match the alias value
		// from above ("n8n.svg" for alias "n8n").
		$src = $appRoot . '/img/' . self::APP_ICON_NAME . '.svg';
		$dst = $serverRoot . '/core/img/filetypes/' . self::APP_ICON_NAME . '.svg';
		if (file_exists($src)) {
			$existing = is_file($dst) ? @file_get_contents($dst) : null;
			$incoming = @file_get_contents($src);
			if ($incoming !== false && $existing !== $incoming) {
				if (@file_put_contents($dst, $incoming) === false) {
					$output->warning('n8n_sync: could not write ' . $dst);
				}
			}
		} else {
			$output->warning('n8n_sync: icon source missing at ' . $src);
		}

		// update-db: insert the mimetype row, then rewrite filecache rows
		// whose extension matches. The detector cache is rebuilt because we
		// just touched the on-disk config files.
		$this->detector->getAllMappings(); // primes lazy load (no public reset)
		$id = $this->loader->getId(self::APP_MIMETYPE);
		$touched = $this->loader->updateFilecache(self::FILE_EXT, $id);
		$output->info(sprintf(
			'n8n_sync: mimetype id=%d, %d filecache row(s) updated',
			$id,
			$touched,
		));

		// update-js: regenerate core/js/mimetypelist.js so the frontend map
		// includes our alias. This is the same code path as
		// `occ maintenance:mimetype:update-js`.
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
	 * Read a JSON file (creating it if missing), merge `$additions` on top,
	 * and write it back. Atomic via tempfile + rename.
	 *
	 * @param array<string,mixed> $additions
	 */
	private function mergeJson(string $path, array $additions): void {
		$existing = [];
		if (is_file($path)) {
			$raw = file_get_contents($path);
			if ($raw !== false && trim($raw) !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$existing = $decoded;
				}
			}
		}
		$changed = false;
		foreach ($additions as $key => $value) {
			if (!array_key_exists($key, $existing) || $existing[$key] !== $value) {
				$existing[$key] = $value;
				$changed = true;
			}
		}
		if (!$changed && is_file($path)) {
			return;
		}
		$encoded = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
