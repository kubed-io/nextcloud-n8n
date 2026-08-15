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
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Unarchives the workflow when its file is restored from the trash — for **every**
 * storage backend, which {@see RestoreFromTrashListener} cannot do.
 *
 * ## THE TYPED EVENT ONLY FIRES FOR ONE TRASH
 *
 * `OCA\Files_Trashbin\Events\NodeRestoredEvent` is dispatched by
 * `Files_Trashbin\Trashbin::restore()` and nowhere else. A Team Folder has its OWN
 * trash backend — groupfolders implements `ITrashBackend::restoreItem()` — and it
 * emits no typed event at all. So restoring a workflow file from a Team Folder's trash
 * put the file back and left the workflow archived in n8n, silently, forever.
 *
 * Reported from live use, and the state it leaves behind is worse than a no-op: the
 * file sits in a MAPPED folder while its workflow is invisible in n8n, which is a
 * contradiction the app itself will act on. The next pull sees an archived workflow,
 * decides its mirror should not be there, and trashes the file again — so the user
 * restores, waits, and watches it vanish. A loop, with no error anywhere.
 *
 * This is the same shape as the purge bug ({@see TrashPurgeHook}): a signal that
 * exists for the home storage and not for the one this app's mappings actually use.
 *
 * ## `post_restore` IS EMITTED BY BOTH, AND IS THE ONLY THING THAT IS
 *
 * Both backends emit the legacy `\OCA\Files_Trashbin\Trashbin` `post_restore` hook —
 * `Trashbin::restore()` and groupfolders' `TrashBackend::restoreItem()` — carrying the
 * RESTORED path. Reading it here is what makes one code path cover both, so the
 * deprecation is unavoidable in the same way the purge hook's is.
 *
 * ## BOTH ENTRY POINTS ARE KEPT, ON PURPOSE
 *
 * The typed listener still runs for a home-storage restore, so that case now reaches
 * n8n twice. That is deliberate: unarchiving is idempotent ({@see DeleteService::restore}
 * runs through `callIdempotent`, which treats 404 as success, and unarchiving a live
 * workflow is a no-op), and one redundant call on the backend that already worked is
 * cheaper than betting the working path on a legacy hook firing identically in every
 * Nextcloud version. The Team Folder case has only this hook, which is what the
 * integration scenario pins.
 *
 * Failures are logged and swallowed, exactly as the typed listener does: the file is
 * already back, and stranding it because n8n is down would be worse than a workflow
 * that is one manual sync away from correct.
 */
final class TrashRestoreHook {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private DeleteService $deleteService,
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Slot for the legacy `\OCA\Files_Trashbin\Trashbin` `post_restore` hook.
	 *
	 * `$params['filePath']` is the path the file was restored TO, relative to the
	 * user's files root — `/N8N Tasking/Fleet Health.n8n` for a Team Folder, because
	 * groupfolders composes it from the mount point. `$params['trashPath']` is where it
	 * came from and is not needed: the file is already back, and its id carried its
	 * metadata through the trash.
	 *
	 * @param array{filePath?: string, trashPath?: string} $params
	 */
	public function postRestore(array $params): void {
		if ($this->guard->active()) {
			return;
		}

		$path = $params['filePath'] ?? '';
		// Cheap pre-filter. Unlike the purge hook's, the restored name is the ORIGINAL
		// one — the deletion timestamp came off on the way out of the trash — so the
		// extension really is last here.
		if ($path === '' || !FilenameCodec::isWorkflowName($path)) {
			return;
		}

		$uid = $this->resolveUid();
		if ($uid === '') {
			$this->logger->warning('n8n_sync restore: no user context for the restored node; skipping', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);
			return;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($path, '/'));
		} catch (\Throwable $e) {
			// WARNING, not debug: a restore that cannot find the file it just restored is
			// the failure this class exists to prevent, and silence here is what let the
			// Team Folder case go unnoticed.
			$this->logger->warning('n8n_sync restore: could not resolve the restored node', [
				'app' => Application::APP_ID,
				'path' => $path,
				'uid' => $uid,
				'exception' => $e,
			]);
			return;
		}
		if (!$node instanceof File) {
			return;
		}

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // detached file — nothing in n8n to bring back
		}
		$mapping = $managed->mappingId !== ''
			? $this->mappings->getById($managed->mappingId)
			: null;

		try {
			$this->deleteService->restore($managed->workflowId, $managed->mode, $mapping);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync restore: n8n-side restore failed; NC file already back', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $managed->workflowId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Whose restore this is. An interactive restore has a session user; a restore
	 * driven from occ sets up the filesystem for the user it is processing instead, so
	 * `\OC_User::getUser()` names them. Same resolution the purge hook uses, and for
	 * the same reason.
	 */
	private function resolveUid(): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid !== null && $uid !== '') {
			return $uid;
		}
		$fsUser = \OC_User::getUser();
		return $fsUser === false ? '' : $fsUser;
	}
}
