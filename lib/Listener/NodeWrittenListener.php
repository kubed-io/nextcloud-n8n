<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\BackgroundJob\PushWorkflowJob;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\PushService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\SyncNotifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCA\N8nSync\Service\WritebackStrategy;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Pushes a saved workflow file back to n8n (Phase 4 writeback). NodeWrittenEvent
 * fires for the text editor, WebDAV PUTs, and desktop-client syncs.
 *
 * The decision is made entirely from the file's own Files-Metadata (stamped on
 * pull), so it survives renames/moves and needs no path/mapping lookup. We push
 * only when **all** hold:
 *   - guard not active (i.e. not our own pull/push write),
 *   - name ends in `.n8n` (cheap bail for everything else),
 *   - the file is ours (`n8n_id` set) and effective state is `sync` + `two-way`
 *     (reference/backup never push),
 *   - the content actually changed since the last sync (sha1 != n8n_syncedHash) —
 *     this is the loop guard against re-pushing our own / unchanged content.
 *
 * Timing (Fork C): `sync` pushes inline; `async` enqueues {@see PushWorkflowJob}.
 *
 * @implements IEventListener<NodeWrittenEvent>
 */
final class NodeWrittenListener implements IEventListener {
	public function __construct(
		private IJobList $jobList,
		private PushService $pushService,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private SyncNotifier $notifier,
		private WritebackStrategy $strategy,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof NodeWrittenEvent)) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isWorkflowFile($node)) {
			return;
		}

		// NO MIMETYPE RE-STAMP HERE ANY MORE. Under the old `.n8n.json` extension this ran a
		// table-wide filecache UPDATE on every single write — including our own guarded ones,
		// because NC's scanner re-detected the mime off the path's last extension (`.json` →
		// application/json) and clobbered our row each time. `.n8n` IS the last extension, so
		// core's own detector returns application/n8n+json and there is nothing to correct.
		// Everything below is the writeback push, which IS the loop we guard against.
		if ($this->guard->active()) {
			return;
		}

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // not (yet) one of ours — new-file create is a future step
		}
		if (!$managed->isSync()) {
			return; // only sync pushes; link never does
		}

		try {
			$content = $node->getContent();
		} catch (\Throwable) {
			return;
		}
		if ($managed->syncedHash === sha1($content)) {
			return; // unchanged since last sync (or our own write) — loop guard
		}

		// Who to notify if the push fails (and which Files view the async job
		// re-resolves the node through).
		$uid = $this->userSession->getUser()?->getUID() ?? $node->getOwner()?->getUID() ?? '';

		// QUEUED WHEN THAT WILL ACTUALLY RUN, INLINE OTHERWISE. The admin radio that
		// used to answer this is gone (saga Ch5); {@see WritebackStrategy} derives it
		// from whether there is a user for the job to act as and whether anything
		// drains the queue. The `$uid !== ''` half of the old condition lives in there
		// now, which is how `ContentTagListener` stopped answering it differently.
		if ($this->strategy->canQueue($uid)) {
			// Defer to the job, which pushes and surfaces its own failure toast.
			$this->jobList->add(PushWorkflowJob::class, ['fileId' => $node->getId(), 'userId' => $uid]);
			return;
		}

		// Inline push. Best-effort: never let a writeback failure break the
		// user's save — surface it as a notification (n8n's own message) instead.
		try {
			if ($this->pushService->push($node)) {
				$this->notifier->cleared($node->getId());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync writeback failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'exception' => $e,
			]);
			$this->notifier->failed($uid, $node->getId(), $node->getName(), $e->getMessage());
		}
	}
}
