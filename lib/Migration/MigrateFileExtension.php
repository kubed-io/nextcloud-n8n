<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Migration;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\StorageService;
use OCA\N8nSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames the workflow files this app already wrote from the retired compound
 * extension to the single segment: `Fleet Health.n8n.json` → `Fleet Health.n8n`.
 *
 * Runs once on upgrade. Without it, an instance that was synced before the change wakes
 * up with every workflow file invisible to the app — {@see FilenameCodec::isWorkflowName}
 * is the single predicate for "is this one of ours?", and after the cut a `.n8n.json`
 * file is not. The files would keep their metadata and their workflows, and nothing
 * would ever act on them again.
 *
 * ## IT ALSO SWEEPS UP THE NAMES THE OLD EXTENSION MADE US LIVE WITH
 *
 * Nextcloud's collision counter goes before the LAST extension, so under `.n8n.json`
 * a copy landing beside its source was born `Fleet Health.n8n (1).json` — the shape
 * the whole `canonicalise()` layer existed to read. Those names are on disk on any
 * instance where somebody copied a workflow, so this converts them too, to the name the
 * new extension would have produced in the first place: `Fleet Health (1).n8n`.
 *
 * ## SCOPE: THE MAPPED FOLDERS, WHICH IS WHERE THE APP'S FILES LIVE
 *
 * It walks each mapping's folder rather than the whole filecache, because those folders
 * ARE the app's footprint and a repair step has no business renaming a file somebody
 * hand-made outside one. A stray `.n8n.json` in a user's home is left exactly as it
 * is; dropping it into a mapped folder still registers it, under whatever name it has.
 *
 * Guarded by {@see SyncGuard} so the renames don't echo: a `NodeRenamedEvent` would
 * otherwise enqueue a name reconcile per file and push each one to n8n, turning an
 * upgrade into a write storm against the user's n8n. The names are equivalent
 * either way — the same stem, the same counter — so there is nothing for a reconcile
 * to do.
 *
 * ## THIS ONE STAYS, UNLIKE THE GRAFANA SIBLING'S
 *
 * `grafana_sync` deleted its copy of this class the moment it had run: that app is not
 * published, so there was exactly one instance to migrate and it was already done. This
 * app IS on the Nextcloud App Store. Its population is not ours to count, and an admin
 * upgrading from an older release a year from now still needs their files renamed. It
 * stays for a version or two, and comes out when the release it migrates from is far
 * enough behind to be unsupported.
 *
 * Idempotent and fail-soft: a file already carrying the new extension is skipped, and a
 * rename that cannot happen is logged and stepped over rather than failing the upgrade.
 */
final class MigrateFileExtension implements IRepairStep {
	/** The extension this app used to write. */
	private const LEGACY_EXT = '.n8n.json';

	/**
	 * Nextcloud's spelling of a collision under the legacy extension: the counter landed
	 * before `.json`, because to Nextcloud the file was a `.json` called `<stem>.n8n`.
	 */
	private const LEGACY_COUNTED_RE = '/^(?<stem>.+)\.n8n \((?<n>\d+)\)\.json$/';

	/** Runaway guard when stepping a counter to find a free name; see {@see freeName()}. */
	private const MAX_COLLISION = 1000;

	public function __construct(
		private MappingService $mappings,
		private StorageService $storage,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Migrate .n8n.json workflow files to .n8n';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$renamed = 0;
		$failed = 0;

		foreach ($this->mappings->list() as $mapping) {
			try {
				$folder = $this->storage->findFolder($mapping);
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync: could not open a mapped folder to migrate its file extensions', [
					'app' => Application::APP_ID,
					'mapping' => $mapping->id,
					'exception' => $e,
				]);
				continue;
			}
			if ($folder === null) {
				continue; // mapping configured but never synced — no files to rename
			}
			$this->guard->run(function () use ($folder, &$renamed, &$failed): void {
				$this->migrateFolder($folder, $renamed, $failed);
			});
		}

		if ($renamed > 0 || $failed > 0) {
			$output->info(sprintf(
				'n8n_sync: renamed %d workflow file(s) to %s (%d could not be renamed)',
				$renamed,
				FilenameCodec::EXT,
				$failed,
			));
		}
	}

	/** Depth-first walk of one mapped folder, renaming every legacy workflow file in it. */
	private function migrateFolder(Folder $folder, int &$renamed, int &$failed): void {
		foreach ($folder->getDirectoryListing() as $child) {
			if ($child instanceof Folder) {
				$this->migrateFolder($child, $renamed, $failed);
				continue;
			}
			if (!$child instanceof File) {
				continue;
			}
			$wanted = self::newName($child->getName());
			if ($wanted === null) {
				continue; // already migrated, or never one of ours
			}
			try {
				$target = $this->freeName($folder, $wanted);
				$child->move($folder->getPath() . '/' . $target);
				$renamed++;
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning('n8n_sync: could not migrate a workflow file to the new extension', [
					'app' => Application::APP_ID,
					'fileId' => $child->getId(),
					'from' => $child->getName(),
					'to' => $wanted,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * The new-extension name for a legacy filename, or null when there isn't one.
	 *
	 * The counted form is checked first: `Fleet Health.n8n (1).json` also ends in
	 * `.json`, and testing the plain suffix first would leave it unmatched and untouched,
	 * which is the one shape most in need of fixing.
	 */
	private static function newName(string $old): ?string {
		if (preg_match(self::LEGACY_COUNTED_RE, $old, $m) === 1) {
			return $m['stem'] . ' (' . $m['n'] . ')' . FilenameCodec::EXT;
		}
		if (strlen($old) > strlen(self::LEGACY_EXT) && str_ends_with($old, self::LEGACY_EXT)) {
			return substr($old, 0, -strlen(self::LEGACY_EXT)) . FilenameCodec::EXT;
		}
		return null;
	}

	/**
	 * $wanted if it is free in $folder, otherwise the same name wearing the next unused
	 * collision counter.
	 *
	 * Two legacy names CAN want one new name — `Fleet Health.n8n (1).json` and a
	 * hand-made `Fleet Health (1).n8n.json` both migrate to `Fleet Health (1).n8n` —
	 * and a migration that threw there would strand every remaining file in the folder.
	 * Stepping the counter is what Nextcloud itself would do with the second one.
	 */
	private function freeName(Folder $folder, string $wanted): string {
		if (!$folder->nodeExists($wanted)) {
			return $wanted;
		}
		$stem = substr($wanted, 0, -strlen(FilenameCodec::EXT));
		for ($n = 1; $n <= self::MAX_COLLISION; $n++) {
			$candidate = $stem . ' (' . $n . ')' . FilenameCodec::EXT;
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException('no free name for ' . $wanted);
	}
}
