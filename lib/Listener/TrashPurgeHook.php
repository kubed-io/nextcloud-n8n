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
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The PURGE step — emptying the Nextcloud trash — which is **not** an event.
 *
 * ## NEXTCLOUD DOES NOT FIRE BeforeNodeDeletedEvent FOR A TRASH PURGE
 *
 * {@see DeleteToN8nListener} used to claim it did, and discriminated the two
 * lifecycle steps by path prefix — `<uid>/files/…` for the first delete,
 * `<uid>/files_trashbin/files/…` for the purge. The second half never ran: the
 * trashbin's `removeItem` emits nothing typed at all.
 *
 * This app's own comment is what led a sibling astray. `nextcloud-grafana` had it
 * right in writing (*"proven live: the trashbin's removeItem fires nothing
 * typed"*); `nextcloud-penpot` followed THIS repo's docblock instead, built the
 * path-discriminating version, and its integration test failed on the first run
 * (penpot saga §C6.13). Two siblings disagreed in comments and the wrong one was
 * believed — so the correction is written here loudly rather than quietly fixed.
 *
 * **It was dead twice over here.** Even if the event did fire, the trashed node is
 * renamed `<name>.n8n.d<timestamp>` on its way into the trash, and the old
 * guard was `str_ends_with($name, '.n8n')` — false at purge time, so the
 * listener bailed before it ever consulted the path. The test harness already
 * documented that suffix ({@see \OCA\N8nSync\Tests\Integration\Support\WebDavTrait}); the
 * production side did not know about it. Hence {@see FilenameCodec::isTrashedWorkflowName}.
 *
 * The purge signal is the legacy `\OCP\Trashbin` `preDelete` hook, wired with
 * `\OCP\Util::connectHook` in {@see \OCA\N8nSync\AppInfo\Application::boot()}. Its
 * deprecation is unavoidable: it is the only entry point that exists.
 *
 * ## …AND IT ONLY EXISTS FOR THE HOME TRASH
 *
 * `Files_Trashbin\Trashbin` is the only class in Nextcloud that emits it. A Team Folder
 * has its own trash backend, which emits nothing, so this listener covers the home
 * storage and only the home storage — see {@see TeamFolderPurgeListener} for the other
 * half and for why the two use different signals rather than one.
 *
 * ## THE NODE STILL EXISTS WHEN THIS RUNS
 *
 * `preDelete` fires just BEFORE the unlink, so the trashed node is still
 * resolvable and still carries its Files-Metadata — which is the only reason the
 * workflow id and mode are available at the one moment they are needed.
 *
 * ## WHOSE TRASH IS IT
 *
 * An interactive purge has a session user. A background retention cleanup
 * (`Files_Trashbin`'s `ExpireTrash` job) has none — but it sets up the filesystem
 * for the user it is processing, so `\OC_User::getUser()` names them. Both are
 * tried, because otherwise a workflow would survive in n8n whenever Nextcloud
 * expired the mirror on its own schedule — exactly the case nobody is watching.
 */
final class TrashPurgeHook {
	public function __construct(
		private DeleteService $deleteService,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Slot for the legacy `\OCP\Trashbin` `preDelete` hook.
	 *
	 * `$params['path']` is the trash-relative path of the node about to be
	 * unlinked: `/files_trashbin/files/<name>.n8n.d<timestamp>`.
	 *
	 * @param array{path?: string} $params
	 */
	public function preDelete(array $params): void {
		// FIRST LINE, BEFORE EVERY FILTER — deliberately. The previous attempt logged
		// after the path filter, so "the hook never fired" and "the hook fired and the
		// filter rejected the path" produced identical silence and cost a whole CI
		// cycle to tell apart. Instrumentation that sits behind a condition cannot
		// answer whether that condition was reached.
		$this->logger->debug('n8n_sync purge: preDelete entered', [
			'app' => Application::APP_ID,
			'params' => $params,
		]);

		if ($this->guard->active()) {
			return;
		}

		$path = $params['path'] ?? '';
		// Cheap pre-filter. The trashed name carries the deletion time AFTER the
		// extension, so the extension is not last — `str_contains`, not
		// `str_ends_with`. This is the whole bug in one line.
		if ($path === '' || !str_contains($path, FilenameCodec::EXT)) {
			$this->logger->debug('n8n_sync purge: path is not one of ours', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);
			return;
		}

		// EVERY BAIL BELOW SAYS WHY. Each one is a legitimate reason to do nothing,
		// which is exactly what makes them indistinguishable from "the hook never
		// fired" when they are silent. The integration workflow sets loglevel 0 so
		// these reach the log it dumps on failure.

		$uid = $this->resolveUid();
		if ($uid === '') {
			$this->logger->warning('n8n_sync purge: no user context for the trashed node; skipping', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);
			return;
		}

		try {
			// The home is …/<uid>/files and the trash is …/<uid>/files_trashbin, so
			// the hook path resolves against the home's PARENT. The path can carry a
			// double slash (`Trashbin::delete()` concatenates a already-rooted
			// filename onto its prefix); Folder::get() normalises it away.
			$node = $this->rootFolder->getUserFolder($uid)->getParent()->get(ltrim($path, '/'));
		} catch (\Throwable $e) {
			// WARNING, not debug: production runs at loglevel 2, so a debug line here
			// means the leak this listener exists to fix comes back with NO TRAIL. The
			// benign bails below stay at debug; this one is a failure.
			$this->logger->warning('n8n_sync purge: could not resolve the trashed node', [
				'app' => Application::APP_ID,
				'path' => $path,
				'uid' => $uid,
				'exception' => $e,
			]);
			return;
		}
		if (!$node instanceof File) {
			$this->logger->debug('n8n_sync purge: trashed node is not a file', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);
			return;
		}
		if (!FilenameCodec::isTrashedWorkflowName($node->getName())) {
			$this->logger->debug('n8n_sync purge: name is not a trashed workflow file', [
				'app' => Application::APP_ID,
				'name' => $node->getName(),
			]);
			return;
		}

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			// Detached file — nothing in n8n is ours to remove.
			$this->logger->debug('n8n_sync purge: trashed file carries no n8n metadata', [
				'app' => Application::APP_ID,
				'name' => $node->getName(),
				'fileId' => $node->getId(),
			]);
			return;
		}
		// Say what will ACTUALLY happen. hardDelete() only deletes a `sync` workflow and
		// no-ops for everything else, so an unconditional "deleting…" line made purge
		// diagnostics lie for link/unmapped/ignored files.
		if ($managed->isSync()) {
			$this->logger->debug('n8n_sync purge: deleting the workflow in n8n', [
				'app' => Application::APP_ID,
				'workflowId' => $managed->workflowId,
			]);
		} else {
			$this->logger->debug('n8n_sync purge: nothing to delete for a non-sync file', [
				'app' => Application::APP_ID,
				'workflowId' => $managed->workflowId,
				'mode' => $managed->mode,
			]);
		}

		try {
			$this->deleteService->hardDelete($managed->workflowId, $managed->mode);
		} catch (\Throwable $e) {
			// Log and swallow: a legacy hook cannot cleanly abort the purge, and a
			// workflow left alive in n8n is a recoverable leak — the admin can delete
			// it there — never data loss. This is deliberately UNLIKE the soft step,
			// which aborts the Nextcloud delete so the two sides cannot drift.
			$this->logger->warning('n8n_sync purge: could not delete the workflow in n8n', [
				'app' => Application::APP_ID,
				'path' => $path,
				'workflowId' => $managed->workflowId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * The session user, else the user the filesystem is currently set up for —
	 * which is how the retention job identifies whose trash it is expiring.
	 */
	private function resolveUid(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid !== '') {
			return $uid;
		}
		$fsUser = \OC_User::getUser();
		return $fsUser === false ? '' : (string)$fsUser;
	}
}
