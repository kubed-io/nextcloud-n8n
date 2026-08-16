<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\DeleteService;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Mirrors NC's "restore from trash" into n8n (§17.7).
 *
 * Fires after a restore from the trashbin completes. NC preserves the file id
 * through trash, so `WorkflowMetadata` still carries the original `n8n_id` and
 * mapping reference — we can reverse the soft step without any extra state.
 *
 *   - `sync + two-way` → `POST /workflows/{id}/unarchive` (full content restore;
 *     verified live, same id, tags preserved).
 *   - reference / sync+readonly → re-add the mapping tag (additive `ensureTag`).
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
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
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

		$managed = $this->metadata->read($target->getId());
		if (!$managed?->isManaged()) {
			return;
		}
		$id = $managed->workflowId;
		$mode = $managed->mode;
		$mapping = $managed->mappingId !== ''
			? $this->mappings->getById($managed->mappingId)
			: null;

		try {
			$this->deleteService->restore($target, $id, $mode, $mapping);
		} catch (\Throwable $e) {
			// Log + swallow: see class docblock for rationale.
			$this->logger->warning('n8n_sync restore: n8n-side restore failed; NC file already back', [
				'app' => Application::APP_ID,
				'fileId' => $target->getId(),
				'workflowId' => $id,
				'exception' => $e,
			]);
		}
	}
}
