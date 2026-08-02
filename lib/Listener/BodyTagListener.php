<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TagReconcileService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * The THIRD tag direction: a hand-edit of the `tags` array inside a `.n8n.json`
 * reaches n8n and the Nextcloud pills (saga §5.9).
 *
 * ## ITS OWN TRIGGER, ON PURPOSE
 *
 * This is a dedicated listener rather than a branch inside {@see NodeWrittenListener},
 * and that is the direct lesson of the attempt that was reverted (saga §5.6.2.3): that
 * commit made the pill path and the body path share one "read the Nextcloud side"
 * step, which changed the pill path's behaviour and broke a shipping feature. The two
 * directions share the merge ENGINE and nothing else.
 *
 * The body PUT and the tags PUT are separate calls by necessity, not by choice — n8n
 * marks `tags` readOnly on both create and update (`workflowCreate.yml`,
 * `additionalProperties: false`), so `PUT /workflows/{id}/tags` is the only writer
 * there is. That is why a body save cannot carry tags and this listener exists at all.
 *
 * ## WHY IT IS CHEAP ON AN ORDINARY SAVE
 *
 * Most saves change nodes, not tags. {@see TagReconcileService::reconcileFromBody}
 * compares the body's tags to the pills and returns before touching n8n when they
 * agree — which is reliable only because a pill edit now keeps the body in step, so
 * the body cannot lag. See that method for why the comparison is against the pills
 * and not the `n8n_syncedTags` baseline.
 *
 * @implements IEventListener<NodeWrittenEvent>
 */
final class BodyTagListener implements IEventListener {
	public function __construct(
		private TagReconcileService $reconcile,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeWrittenEvent) {
			return;
		}
		// Our own writes — the pull's body write, and the lockstep write a pill edit
		// makes — re-fire this event under the guard. Never re-enter them.
		if ($this->guard->active()) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isWorkflowFile($node)) {
			return;
		}

		try {
			$content = $node->getContent();
		} catch (\Throwable) {
			return;
		}

		try {
			// Gates (managed + sync), the merge, the guard and the failure handling all
			// live in the service, shared with nothing that touches the pill merge.
			$this->reconcile->reconcileFromBody($node, $content);
		} catch (\Throwable $e) {
			// Belt and braces: the service already swallows n8n failures, so reaching
			// here means something unexpected. A tag hiccup must never break a save.
			$this->logger->warning('n8n_sync body-tag listener failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'exception' => $e,
			]);
		}
	}
}
