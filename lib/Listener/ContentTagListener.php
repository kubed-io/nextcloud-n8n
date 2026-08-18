<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\BackgroundJob\ReconcileTagsJob;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TagReconcileService;
use OCA\N8nSync\Service\TeamFolderService;
use OCA\N8nSync\Service\WritebackStrategy;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use Psr\Log\LoggerInterface;

/**
 * Reactive **surface 3** trigger (saga Ch5 §5.6.2, Slice A): a user adds or removes a
 * **content** system-tag pill on a managed `sync` workflow file, and that one tag is
 * reconciled to n8n **on its own** — no "Sync to n8n" click.
 *
 * It is the tag-side sibling of {@see NodeWrittenListener} (which pushes a saved body):
 * both watch a user edit, and both take the same inline-vs-queued decision from
 * {@see \OCA\N8nSync\Service\WritebackStrategy}: queued as {@see ReconcileTagsJob}
 * where a worker will actually drain it, reconciled inline where none will.
 *
 * Every tag is a content tag — the reserved `n8n:*` pill namespace this listener
 * used to split on is gone. The gating (managed? sync?), the unbind check, the
 * guard, and the best-effort error handling live in {@see TagReconcileService} —
 * shared with the async job.
 *
 * Loop safety: the reconcile writes pills inside {@see SyncGuard}, so the
 * `TagAssignedEvent`/`TagUnassignedEvent` it re-fires land here with the guard
 * active and bail.
 *
 * @implements IEventListener<TagAssignedEvent|TagUnassignedEvent>
 */
final class ContentTagListener implements IEventListener {
	public function __construct(
		private TagReconcileService $reconcile,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private TeamFolderService $teamFolders,
		private SyncGuard $guard,
		private IJobList $jobList,
		private WritebackStrategy $strategy,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof TagAssignedEvent && !$event instanceof TagUnassignedEvent) {
			return;
		}
		// Our own pill writes (pull reconcile, this listener's own reconcile) re-fire
		// tag events under the guard — never re-enter them.
		if ($event->getObjectType() !== 'files' || $this->guard->active()) {
			return;
		}
		// Every tag is a content tag (see the class docblock) — a tag change is a
		// tag change, with no namespace to split on.

		// A tag change does NOT always have a session. `occ tag:files:add`, and any
		// other CLI or background caller, dispatches the same event with nobody
		// logged in — and bailing there made this listener silently do nothing on a
		// channel admins actually use (penpot saga §C6.18 hit exactly this and it
		// changed their design). Fall back to the sync actor, which is the same uid
		// the pull already writes as.
		$uid = $this->actingUid();
		if ($uid === '') {
			return; // no session and no resolvable sync actor — nothing to act as
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync content-tag: could not open a Files view', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return;
		}
		// See NodeWrittenListener: one derived decision, so the two listeners cannot
		// answer "is there anybody to act as?" differently again.
		$canQueue = $this->strategy->canQueue($uid);

		foreach ($event->getObjectIds() as $objectId) {
			try {
				$node = $userFolder->getById((int)$objectId)[0] ?? null;
			} catch (\Throwable $e) {
				$this->logger->debug('n8n_sync content-tag: could not resolve file', [
					'app' => Application::APP_ID,
					'objectId' => $objectId,
					'exception' => $e,
				]);
				continue;
			}
			if (!FilenameCodec::isWorkflowFile($node)) {
				continue;
			}
			if ($canQueue) {
				// Background: enqueue and return fast; the job re-resolves + reconciles.
				$this->jobList->add(ReconcileTagsJob::class, ['fileId' => $node->getId(), 'userId' => $uid]);
			} else {
				// Inline: reconcile now (the service gates on managed + sync itself).
				$this->reconcile->reconcileFile($node);
			}
		}
	}

	/**
	 * The acting user: the session user when there is one, else the configured sync
	 * actor ({@see \OCA\N8nSync\Service\TeamFolderService::resolveActorUid}) so an
	 * `occ`-driven or background tag change still reconciles. Returns '' when
	 * neither resolves, which is the only case worth giving up on.
	 */
	private function actingUid(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid !== '') {
			return $uid;
		}
		try {
			return $this->teamFolders->resolveActorUid();
		} catch (\Throwable) {
			return '';
		}
	}
}
