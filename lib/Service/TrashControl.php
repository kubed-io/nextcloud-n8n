<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\N8nSync\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Run a delete that must NOT be recoverable — the one gesture in this app where the
 * Nextcloud trash is the wrong answer.
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
 */
final class TrashControl {
	public function __construct(
		private ContainerInterface $container,
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
