<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\N8nSync\AppInfo\Application;
use OCP\Files\FileInfo;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every conversation this app has with the Nextcloud trash: making a delete permanent,
 * reading what is in there, and destroying one entry.
 *
 * ## MAKING A DELETE PERMANENT
 *
 * The one gesture in this app where the Nextcloud trash is the wrong answer.
 *
 * A `link` file is a read-only projection of a workflow. When the workflow leaves the
 * mapping's tag — archived in n8n, or untagged there — the pointer stops meaning
 * anything, and putting it in the trash would offer the user a restore that reconnects
 * nothing: the workflow is still perfectly fine, sitting in n8n's archive. There is
 * nothing to restore FROM. `sync` files are the opposite and keep the trash, because
 * their file IS the workflow's content and an archive in n8n is reversible.
 *
 * ## `pauseTrash()` IS THE SUPPORTED BYPASS, AND THE ONLY ONE
 *
 * `Files_Trashbin\Storage::unlink()` consults a private `$trashEnabled`, and
 * `Trashbin::move2trash()` offers no opt-out — neither is reachable from an app. The
 * one public seam is {@see ITrashManager::pauseTrash()}: `TrashManager::moveToTrash()`
 * returns false while paused, and the storage wrapper then performs a real unlink.
 *
 * It is also **backend-agnostic**, which is why it is worth reaching for rather than
 * calling `Trashbin::delete()` afterwards to undo a trashing we just did. Every trash
 * backend registers with the same manager, so this covers a Team Folder's trash exactly
 * as it covers a user's home — and Team Folders are what this app's mappings actually
 * use. A `Trashbin::`-based purge would have quietly missed them.
 *
 * ## RESOLVED LAZILY, BECAUSE THE TRASH IS AN APP
 *
 * `files_trashbin` is shipped but removable, and `ITrashManager` lives in ITS namespace,
 * not OCP — a constructor dependency would make this app fail to boot on an instance
 * without it. When it is absent there is no trash to pause and `delete()` is already
 * permanent, so the fallback is simply to run the callback.
 *
 * ## THE TRASH APP'S TYPES STOP HERE
 *
 * {@see listTrashed} and the two operations it hands back are the reading half, used by
 * {@see TrashReconcileService} to reap mirrors whose workflow no longer exists and to
 * bring back the ones whose workflow came out of the archive. They answer in
 * {@see TrashedFile}, this app's own shape, for the reason above: a signature naming
 * `ITrashItem` is a file the unit suite cannot load and psalm cannot resolve. One class
 * pays that cost; everything downstream is ordinary code.
 *
 * Both halves are also **backend-agnostic in the same way and for the same reason** —
 * `ITrashManager` aggregates every registered backend, so a Team Folder's trash is
 * listed and purged exactly like a user's home. That is not a nicety here: Team Folders
 * are what this app's mappings actually use, and every trash bug this app has had came
 * from reaching for a home-storage-only mechanism.
 */
final class TrashControl {
	public function __construct(
		private ContainerInterface $container,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run $fn with the trash paused, so any delete inside it is permanent.
	 *
	 * The pause is process-wide while it is held, so $fn must be exactly the delete and
	 * nothing else — `finally` restores it even if the delete throws, because leaving
	 * the trash paused would silently make every later delete on the request
	 * unrecoverable, including the user's own.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function withoutTrash(callable $fn): mixed {
		$manager = $this->trashManager();
		if ($manager === null) {
			return $fn();
		}
		$manager->pauseTrash();
		try {
			return $fn();
		} finally {
			$manager->resumeTrash();
		}
	}

	/**
	 * Every file in the root of $uid's trash — their home trash AND the trash of every
	 * Team Folder they can see, because `ITrashManager::listTrashRoot()` folds in each
	 * registered backend.
	 *
	 * ROOT ONLY, DELIBERATELY. A file trashed on its own is a root item; one that went
	 * in as part of a deleted FOLDER is nested inside it, and this does not recurse
	 * into those. Descending would mean destroying single files out of the middle of a
	 * folder the user trashed as a unit, leaving them a restore that silently comes
	 * back incomplete. A folder is restored or purged whole, and its contents settle
	 * on the pull that follows.
	 *
	 * Cost is one query per backend — a directory listing of `files_trashbin/files`
	 * for the home, one indexed lookup for the Team Folders — not one per entry. The
	 * caller filters the result by name and metadata before spending anything on it.
	 *
	 * Answers `[]` for an unknown user, or when there is no trash app at all: an
	 * instance without `files_trashbin` cannot have a trashed mirror to reap.
	 *
	 * @return list<TrashedFile>
	 */
	public function listTrashed(string $uid): array {
		$manager = $this->trashManager();
		if ($manager === null) {
			return [];
		}
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}

		try {
			$items = $manager->listTrashRoot($user);
		} catch (\Throwable $e) {
			// A trash we cannot read is not a reason to fail the pull that asked. The
			// reconcile simply finds nothing this time round and runs again next tick.
			$this->logger->warning('n8n_sync: could not list the trash', [
				'app' => Application::APP_ID,
				'user' => $uid,
				'exception' => $e,
			]);
			return [];
		}

		$out = [];
		foreach ($items as $item) {
			if (!$item instanceof ITrashItem || $item->getType() !== FileInfo::TYPE_FILE) {
				continue;
			}
			// `FileInfo::getId()` is `int|null`. Without an id there is no metadata to
			// read, so there is no way to know whether this is one of ours — and a file
			// this app cannot identify is never a file it may destroy.
			$fileId = $item->getId();
			if ($fileId === null) {
				continue;
			}
			$out[] = new TrashedFile(
				$fileId,
				// The ORIGINAL name. `getName()` answers the trash's own spelling, which
				// carries the deletion timestamp AFTER the extension — the exact shape
				// that made `str_ends_with($name, '.n8n')` false for every trashed file
				// and left the purge step dead for a whole release.
				basename($item->getOriginalLocation()),
				function () use ($manager, $item): void {
					$manager->removeItem($item);
				},
				function () use ($manager, $item): void {
					$manager->restoreItem($item);
				},
			);
		}
		return $out;
	}

	/** The trash manager, or null when `files_trashbin` is not installed/enabled. */
	private function trashManager(): ?ITrashManager {
		if (!interface_exists(ITrashManager::class)) {
			return null;
		}
		try {
			$manager = $this->container->get(ITrashManager::class);
		} catch (\Throwable $e) {
			$this->logger->debug('n8n_sync: no trash manager available; a delete will be permanent anyway', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return null;
		}
		return $manager instanceof ITrashManager ? $manager : null;
	}
}
