<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\N8nSync\Service\DeleteService;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Mirrors NC's "restore from trash" into n8n (§17.7).
 *
 * Fires after a restore from the trashbin completes. NC preserves the file id
 * through trash, so `WorkflowMetadata` still carries the original `n8n_id` and
 * mapping reference — we can reverse the soft step without any extra state.
 *
 *   - `sync` → `POST /workflows/{id}/unarchive` (full content restore; verified
 *     live, same id, tags preserved), or a fresh create when n8n hard-deleted it.
 *   - anything else with a mapping → re-add the mapping tag (additive `ensureTag`).
 *   - detached (no `n8n_id`) → no-op.
 *
 * **Failures here are logged + swallowed.** Don't abort the user's restore just
 * because n8n is down: we'd rather end up with a local file that's temporarily
 * out-of-sync (which the user can re-tag manually or which the next manual sync
 * fixes) than leave the file stuck in trash.
 *
 * @implements IEventListener<NodeRestoredEvent>
 */
final class RestoreFromTrashListener implements IEventListener {
	public function __construct(
		private DeleteService $deleteService,
		private SyncGuard $guard,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeRestoredEvent || $this->guard->active()) {
			return;
		}
		$target = $event->getTarget();
		if (!FilenameCodec::isWorkflowFile($target)) {
			return;
		}
		// The stamp read, the mapping lookup, and the log-and-swallow error policy
		// all live in restoreNode — shared with the legacy hook, on purpose.
		$this->deleteService->restoreNode($target);
	}
}
