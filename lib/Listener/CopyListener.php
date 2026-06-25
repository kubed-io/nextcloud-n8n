<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\CopyService;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Copy half of the motion lifecycle (saga Ch2 §14.2 `copy.feature`). Runs on
 * {@see NodeCopiedEvent} — the event Nextcloud fires for a copy, as opposed to the
 * {@see \OCP\Files\Events\Node\NodeRenamedEvent} it fires for a move. That split at
 * the event layer is exactly what lets us treat the two oppositely: a move keeps the
 * same workflow (see {@see MotionListener}), a copy always makes a NEW one.
 *
 * All the work lives in {@see CopyService::onCopy}: strip the copy's inherited
 * identity, then register it as a fresh workflow if it landed in a mapping. This
 * listener is the thin event adapter — resolve the target node, cheap bail checks,
 * delegate.
 *
 * Bail conditions (cheap → expensive):
 *   1. {@see SyncGuard::active()} — our own re-stamp writes never re-enter.
 *   2. target is a {@see File} ending in `.n8n.json`.
 *
 * Failures are logged and swallowed (the copy already happened on disk).
 *
 * @implements IEventListener<NodeCopiedEvent>
 */
final class CopyListener implements IEventListener {
	public function __construct(
		private CopyService $copy,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($this->guard->active()) {
			return;
		}
		if (!$event instanceof NodeCopiedEvent) {
			return;
		}
		$target = $event->getTarget();
		if (!FilenameCodec::isWorkflowFile($target)) {
			return;
		}

		try {
			$this->copy->onCopy($target);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync copy: register-on-copy failed', [
				'app' => Application::APP_ID,
				'fileId' => $target->getId(),
				'path' => $target->getPath(),
				'exception' => $e,
			]);
		}
	}
}
