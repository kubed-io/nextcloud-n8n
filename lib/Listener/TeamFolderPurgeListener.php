<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\DeleteService;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * The purge step for **every trash that is not the home one** — which in this app
 * means the only trash its mappings actually use.
 *
 * ## THE HOME TRASH'S PURGE SIGNAL DOES NOT EXIST FOR A TEAM FOLDER
 *
 * {@see TrashPurgeHook} listens to the legacy `\OCP\Trashbin` `preDelete` hook, and
 * that hook is emitted by exactly one place in Nextcloud: `Files_Trashbin\Trashbin`.
 * groupfolders implements its own {@see \OCA\Files_Trashbin\Trash\ITrashBackend}, and
 * its `removeItem()` is four lines that emit **nothing at all** — no legacy hook, no
 * typed event:
 *
 *     $node->getStorage()->unlink($node->getInternalPath());
 *     $node->getStorage()->getCache()->remove($node->getInternalPath());
 *
 * So emptying a Team Folder's trash was completely silent, and the workflow it was
 * supposed to finish off stayed in n8n's archive forever. Reported from live use: a
 * file purged out of a Team Folder's trash, its workflow still sitting in the n8n
 * archive minutes later, with nothing in the log because nothing had run.
 *
 * ## THIS IS THE THIRD TIME, AND THE REASON IS ALWAYS THE SAME
 *
 * A signal that exists for the home storage and not for the one in use:
 *
 *   - the restore (#73) — `NodeRestoredEvent` is `files_trashbin`-only; fixed by
 *     moving to `post_restore`, which groupfolders DOES emit ({@see TrashRestoreHook})
 *   - reading the trash (#75) — `listTrashRoot()` answers an empty list until the
 *     user's filesystem is set up ({@see \OCA\N8nSync\Service\TrashControl::listTrashed})
 *   - the purge — here
 *
 * `restore.feature`'s notes claimed the purge had *already* been fixed for this reason.
 * It had not: what was fixed there was the trashed filename shape. Believing a note
 * instead of the backend is what let this ship.
 *
 * ## `CacheEntryRemovedEvent` IS THE SIGNAL, BECAUSE IT IS THE ONE THING BOTH DO
 *
 * Whatever a trash backend emits, it must drop the file's cache entry to destroy it —
 * that line is right there in groupfolders' `removeItem()`. `Cache::remove()` dispatches
 * {@see CacheEntryRemovedEvent}, a typed OCP event carrying the file id, so this needs no
 * node, no session, no filesystem setup and no knowledge of which backend ran.
 *
 * **The metadata is still readable when it fires.** The event is dispatched after the
 * `filecache` row is gone, but `files_metadata` is a separate table keyed by file id, and
 * core drops it from a listener on `CacheEntriesRemovedEvent` — the PLURAL, dispatched
 * only by `removeChildren()`. A single-file removal never reaches it. Verified in core
 * rather than assumed, because the whole listener rests on it.
 *
 * ## SCOPED THREE WAYS, BECAUSE THIS EVENT IS NOT ABOUT THE TRASH
 *
 * It fires for every cache-entry removal anywhere in the instance, so acting on it
 * loosely would mean deleting workflows in n8n for things that are not purges at all.
 * Three filters, each of which alone rules out most of the traffic:
 *
 *   1. NOT the home trash. `files_trashbin/…` is {@see TrashPurgeHook}'s, which runs
 *      BEFORE the unlink with the node still resolvable. That path works and is left
 *      exactly as it is; this covers what it cannot see.
 *   2. The TRASHED name shape (`<stem>.n8n.d<timestamp>`). A file only ever carries
 *      that spelling while it is in a trash, so this rules out every ordinary delete —
 *      including this app's own permanent delete of a `link` file, which unlinks a
 *      file still named `<stem>.n8n`.
 *   3. This app's metadata, and `sync` mode. {@see DeleteService::hardDelete} enforces
 *      the mode itself; a `link`'s workflow is never Nextcloud's to destroy.
 *
 * Plus the {@see SyncGuard}, so the app's own reaping of a trashed mirror
 * ({@see \OCA\N8nSync\Service\TrashReconcileService::reap}) does not come back round
 * and ask n8n to delete a workflow it just proved was already gone.
 *
 * ## A TRASHED FOLDER PURGED WHOLE IS STILL NOT COVERED, DELIBERATELY
 *
 * `removeChildren()` dispatches the plural event FIRST — so by the time the per-child
 * events arrive, core has already dropped their metadata and there is no workflow id
 * left to act on. That matches the rest of this app's trash handling, which is root-only
 * on purpose ({@see \OCA\N8nSync\Service\TrashControl::listTrashed}): a folder is trashed,
 * restored and purged as a unit, and its contents settle on the pull that follows.
 */
final class TeamFolderPurgeListener implements IEventListener {
	/** The home trash, which {@see TrashPurgeHook} already covers on a better signal. */
	private const HOME_TRASH_PREFIX = 'files_trashbin/';

	public function __construct(
		private DeleteService $deleteService,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof CacheEntryRemovedEvent) {
			return;
		}
		if ($this->guard->active()) {
			return;
		}

		$path = $event->getPath();
		if (str_starts_with($path, self::HOME_TRASH_PREFIX)) {
			return;
		}
		// Cheapest first: this event fires for every cache-entry removal in the
		// instance, and the overwhelming majority are not even `.n8n` files. Nothing
		// is logged above this line for the same reason.
		if (!FilenameCodec::isTrashedWorkflowName(basename($path))) {
			return;
		}

		// PAST THIS POINT EVERY BAIL SAYS WHY. A trashed workflow file really was
		// purged out of some trash; if this app then does nothing, the reason it did
		// nothing is the only thing worth knowing.
		$fileId = $event->getFileId();
		$managed = $this->metadata->read($fileId);
		if (!$managed?->isManaged()) {
			$this->logger->debug('n8n_sync purge: purged trashed file carries no n8n metadata', [
				'app' => Application::APP_ID,
				'path' => $path,
				'fileId' => $fileId,
			]);
			return;
		}
		if (!$managed->isSync()) {
			$this->logger->debug('n8n_sync purge: nothing to delete for a non-sync file', [
				'app' => Application::APP_ID,
				'workflowId' => $managed->workflowId,
				'mode' => $managed->mode,
			]);
			return;
		}

		$this->logger->debug('n8n_sync purge: deleting the workflow of a file purged from a Team Folder trash', [
			'app' => Application::APP_ID,
			'path' => $path,
			'workflowId' => $managed->workflowId,
		]);

		try {
			$this->deleteService->hardDelete($managed->workflowId, $managed->mode);
		} catch (\Throwable $e) {
			// Log and swallow, exactly as the home-trash purge does: the file is already
			// destroyed by the time this event exists, so there is nothing left to abort.
			// A workflow left alive in n8n is a leak the admin can clean up by hand — it
			// is never data loss, and never a reason to fail the delete that caused it.
			$this->logger->warning('n8n_sync purge: could not delete the workflow in n8n', [
				'app' => Application::APP_ID,
				'path' => $path,
				'workflowId' => $managed->workflowId,
				'exception' => $e,
			]);
		}
	}
}
