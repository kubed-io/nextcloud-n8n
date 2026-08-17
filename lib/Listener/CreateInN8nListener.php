<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\CreateService;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\MotionService;
use OCA\N8nSync\Service\ReplacedByMoveStore;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\SyncNotifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Server-side half of UC-6: when a `*.n8n` file with no `n8n_id` lands in
 * a mapped folder (created via the Files "New" menu, saved by the Text editor,
 * uploaded by WebDAV, or moved in from elsewhere), create it as a real
 * workflow in n8n + tag + stamp metadata.
 *
 * Listens to two events:
 *  - {@see NodeWrittenEvent}  — covers create + content writes (the New menu's
 *                               WebDAV PUT, Text-editor saves, desktop-client
 *                               uploads). The file's *content* exists at this
 *                               point so we can build the create body.
 *  - {@see NodeRenamedEvent}  — covers move-in from outside any mapping (NC
 *                               does not fire NodeWrittenEvent on a move).
 *
 * Bail conditions (cheap → expensive, so the hot path is fast):
 *   1. {@see SyncGuard::active()} — our own pull/stamp writes never re-enter.
 *   2. extension is `.n8n`.
 *   3. file resolves into a mapping via {@see MappingService::resolveForPath}.
 *   4. {@see WorkflowMetadata::read} returns no `n8n_id` (otherwise it's
 *      already a managed file → the writeback listener owns it).
 *
 * Failures are non-blocking for the user's save: logged + surfaced as a
 * notification (reusing the `push_failed` subject — the message text "Couldn't
 * sync X to n8n" applies to creates too). The user can fix the JSON and
 * re-save; the next NodeWrittenEvent retries.
 *
 * Listener-order safety vs. {@see NodeWrittenListener}:
 *  - If our listener runs first: we create + stamp `syncedHash`. The writeback
 *    listener then sees `n8n_id` set, computes `sha1(content) === syncedHash`,
 *    and bails (loop guard).
 *  - If the writeback listener runs first: it sees no `n8n_id` and bails. We
 *    then run and create.
 *  Either order works — there is no race.
 *
 * @implements IEventListener<NodeWrittenEvent|NodeRenamedEvent>
 */
final class CreateInN8nListener implements IEventListener {
	public function __construct(
		private CreateService $createService,
		private MappingService $mappings,
		private MotionService $motion,
		private ReplacedByMoveStore $replaced,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private SyncNotifier $notifier,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($this->guard->active()) {
			return;
		}

		$node = $this->resolveNode($event);
		if (!FilenameCodec::isWorkflowFile($node)) {
			return;
		}

		$mapping = $this->mappings->resolveForPath($node->getPath());
		if ($mapping === null) {
			return; // outside any mapping — let the user keep a "free" .n8n
		}

		$managed = $this->metadata->read($node->getId());
		if ($managed?->isManaged()) {
			return; // already an n8n-tracked file — writeback owns it
		}

		// AN OVERWRITE INHERITS, IT DOES NOT CREATE — even from a file that arrived
		// carrying nothing. A copied `.n8n` has no `n8n_id` (a copy does not inherit
		// the metadata row), so dragging one over a synced file lands here rather than
		// in {@see \OCA\N8nSync\Service\MotionService::moveIn}. Create-on-land would
		// mint a second workflow and leave the one the file replaced live, tagged for
		// this mapping and file-less — which the next pull writes back beside it.
		//
		// The rule is the same whatever the arrival carried: the destination's identity
		// survives and the arrival contributes only its body. `moveIn` already knows how
		// to adopt, so this hands over rather than repeating it.
		$adopted = $node instanceof File ? $this->replaced->adoptedWorkflowId($node->getId()) : null;
		if ($adopted !== null) {
			$this->logger->info('n8n_sync create-on-land: an overwrite inherits the workflow it replaced', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $adopted,
			]);
			try {
				$this->motion->moveIn($node, $adopted, $mapping);
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync create-on-land: inheriting the replaced workflow failed', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'workflowId' => $adopted,
					'exception' => $e,
				]);
			}
			return;
		}

		try {
			$this->createService->createForFile($node, $mapping);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync create-on-land failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
				'exception' => $e,
			]);
			$uid = $this->userSession->getUser()?->getUID() ?? $node->getOwner()?->getUID() ?? '';
			if ($uid !== '') {
				// Reuse the push_failed subject — same notification surface, same
				// "Couldn't sync X to n8n" copy applies cleanly to create errors.
				$this->notifier->failed($uid, $node->getId(), $node->getName(), $e->getMessage());
			}
		}
	}

	/**
	 * Pull the post-event file node out of either supported event. Returns null
	 * for any unexpected event type.
	 */
	private function resolveNode(Event $event): ?\OCP\Files\Node {
		if ($event instanceof NodeWrittenEvent) {
			return $event->getNode();
		}
		if ($event instanceof NodeRenamedEvent) {
			return $event->getTarget();
		}
		return null;
	}
}
