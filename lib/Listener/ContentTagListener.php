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
use OCA\N8nSync\Service\TagSyncService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use Psr\Log\LoggerInterface;

/**
 * Reactive **surface 3** trigger (saga Ch5 §5.6.2, Slice A): a user adds or removes a
 * **content** system-tag pill on a managed `sync` workflow file, and that one tag is
 * reconciled to n8n **on its own** — no "Sync to n8n" click.
 *
 * It is the tag-side sibling of {@see NodeWrittenListener} (which pushes a saved body):
 * both watch a user edit, both honour the same `timing` knob — `sync` reconciles inline
 * during the request, `async` enqueues {@see ReconcileTagsJob} for the cron worker.
 *
 * It is deliberately NOT {@see ModeTagListener}. That listener owns the **reserved**
 * `n8n:ignore` control tag (exclude/restore); this one owns **content** tags (the actual
 * workflow labels). So the two split cleanly by namespace: a change whose tags are ALL
 * reserved (`n8n:*`) is ignored here (it's the control plane, or a marker a pull will
 * reconcile), and a change touching at least one content tag is a real label edit we act
 * on. The actual gating (managed? sync?), protected-tag lookup, guard, and best-effort
 * error handling live in {@see TagReconcileService} — shared with the async job.
 *
 * Loop safety: the reconcile writes pills inside {@see SyncGuard}, so the
 * `TagAssignedEvent`/`TagUnassignedEvent` it re-fires (including a force-kept mapping-tag
 * pop-back) land here with the guard active and bail.
 *
 * @implements IEventListener<TagAssignedEvent|TagUnassignedEvent>
 */
final class ContentTagListener implements IEventListener {
	public function __construct(
		private TagReconcileService $reconcile,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private ISystemTagManager $tagManager,
		private SyncGuard $guard,
		private IJobList $jobList,
		private IAppConfig $config,
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
		// Only a real CONTENT-tag edit is a surface-3 gesture. A change confined to the
		// reserved namespace (`n8n:ignore` and friends) is the control plane — left to
		// ModeTagListener / the next pull.
		if (!$this->touchesContentTag($event->getTags())) {
			return;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return; // unattributable tag change — nothing to resolve a Files view against
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$timing = $this->config->getValueString(Application::APP_ID, 'timing', 'async');

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
			if (!$node instanceof File || !FilenameCodec::isWorkflowFile($node)) {
				continue;
			}
			if ($timing === 'sync') {
				// Inline: reconcile now (the service gates on managed + sync itself).
				$this->reconcile->reconcileFile($node);
			} else {
				// Background: enqueue and return fast; the job re-resolves + reconciles.
				$this->jobList->add(ReconcileTagsJob::class, ['fileId' => $node->getId(), 'userId' => $uid]);
			}
		}
	}

	/**
	 * True when at least one of the changed tag ids is a **content** tag (outside the
	 * reserved `n8n:` namespace). Unresolvable ids are skipped.
	 *
	 * @param array<int|string> $tagIds
	 */
	private function touchesContentTag(array $tagIds): bool {
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			if (!str_starts_with($tag->getName(), TagSyncService::RESERVED_PREFIX)) {
				return true;
			}
		}
		return false;
	}
}
