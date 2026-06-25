<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\MotionService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use Psr\Log\LoggerInterface;

/**
 * Post-move half of the motion lifecycle (saga Ch2 §14.2). Runs on
 * {@see NodeRenamedEvent} *after* a move {@see MoveGuardListener} let through,
 * and applies the n8n-side consequence via {@see MotionService}.
 *
 * Only files that are *already managed* (carry an `n8n_id`) are handled here;
 * an untracked `*.n8n.json` moving into a mapping is create-on-land, owned by
 * {@see CreateInN8nListener}. The two never collide — this listener bails when
 * there is no id, that one bails when there is.
 *
 * Branches:
 *   - **move OUT** — a `sync` file's source was in a mapping and its target is in
 *     no mapping → {@see MotionService::moveOut} (archive + mark `unmapped`).
 *   - **move IN** — an `unmapped` file's target is in a mapping → {@see
 *     MotionService::moveIn} (unarchive/restore, or create-fallback if it was
 *     hard-deleted in n8n).
 *   - everything else (within-mapping move, unmapped→unmapped relocation) → no-op.
 *
 * Failures are logged and swallowed: the NC move already happened, and we'd
 * rather leave the file with stale-but-recoverable metadata than blow up the
 * user's move. The next sync reconciles.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class MotionListener implements IEventListener {
	public function __construct(
		private MotionService $motion,
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($this->guard->active()) {
			return; // our own re-stamp writes never re-enter
		}
		if (!$event instanceof NodeRenamedEvent) {
			return;
		}
		$target = $event->getTarget();
		if (!FilenameCodec::isWorkflowFile($target)) {
			return;
		}

		$managed = $this->metadata->read($target->getId());
		if (!$managed?->isManaged()) {
			return; // untracked — CreateInN8nListener owns create-on-land
		}
		$id = $managed->workflowId;

		$srcMapping = $this->mappings->resolveForPath($event->getSource()->getPath());
		$tgtMapping = $this->mappings->resolveForPath($target->getPath());

		// Move OUT: a sync file left its mapping for an unmapped location. (A move
		// into a *different* mapping was already blocked by MoveGuardListener, so
		// here a non-null source mapping + null target mapping means "ejected".)
		if ($managed->isSync() && $srcMapping !== null && $tgtMapping === null) {
			try {
				$this->motion->moveOut($target, $id);
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync motion: move-out (archive/unmap) failed', [
					'app' => Application::APP_ID,
					'fileId' => $target->getId(),
					'workflowId' => $id,
					'exception' => $e,
				]);
			}
			return;
		}

		// Move IN: an unmapped file landed in a mapping → restore the same workflow.
		if ($managed->isUnmapped() && $tgtMapping !== null) {
			try {
				$this->motion->moveIn($target, $id, $tgtMapping);
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync motion: move-in (restore) failed', [
					'app' => Application::APP_ID,
					'fileId' => $target->getId(),
					'workflowId' => $id,
					'exception' => $e,
				]);
			}
			return;
		}

		// within-mapping move, or unmapped→unmapped relocation: nothing to do.
	}
}
