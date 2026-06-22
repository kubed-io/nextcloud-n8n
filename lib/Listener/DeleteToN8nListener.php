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
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the user's NC delete UX into n8n (§17.7).
 *
 * NC's right-click "Delete file" fires {@see BeforeNodeDeletedEvent} **before**
 * storage->unlink. The View-layer dispatch supports {@see AbortedEventException},
 * which sets `arguments['run']=false` and prevents the delete — exactly the
 * "abort if n8n is unreachable" semantics we want.
 *
 * The same event fires for BOTH lifecycle steps:
 *   - The user's first delete (file is at its normal path, on its way to trash)
 *     → **soft step**: archive in n8n (sync+two-way) or untag (reference / sync+readonly).
 *   - The final purge from trash (file lives under `<uid>/files_trashbin/files/…`)
 *     → **hard step**: `DELETE /workflows/{id}` for sync+two-way (the others are
 *     no-ops because their tag was already stripped on the soft step).
 *
 * Discriminated by path prefix. A "trash-bypassed" direct delete (admin
 * disabled trash, or `X-NC-Skip-Trashbin` header, or another listener called
 * `MoveToTrashEvent::disableTrashBin()`) bypasses the soft step entirely — we
 * treat it like the hard step so a `sync+two-way` workflow still gets removed.
 *
 * Restore is handled by {@see RestoreFromTrashListener}.
 *
 * @implements IEventListener<BeforeNodeDeletedEvent>
 */
final class DeleteToN8nListener implements IEventListener {
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
		if (!$event instanceof BeforeNodeDeletedEvent || $this->guard->active()) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof File || !str_ends_with($node->getName(), FilenameCodec::EXT)) {
			return;
		}

		$meta = $this->metadata->read($node->getId());
		if ($meta === null) {
			return;
		}
		$id = $meta[WorkflowMetadata::KEY_ID] ?? null;
		if (!is_string($id) || $id === '') {
			// Detached file — no n8n side. Let NC do its normal delete.
			return;
		}
		$mode = $meta[WorkflowMetadata::KEY_MODE] ?? '';
		$mappingId = $meta[WorkflowMetadata::KEY_MAPPING] ?? null;
		$mapping = is_string($mappingId) && $mappingId !== ''
			? $this->mappings->getById($mappingId)
			: null;

		$isHardStep = $this->isInTrashbin($node->getPath());
		try {
			if ($isHardStep) {
				$this->deleteService->hardDelete($id, $mode);
			} else {
				$this->deleteService->softDelete($id, $mode, $mapping);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync ' . ($isHardStep ? 'hard' : 'soft') . '-delete failed; aborting NC delete', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $id,
				'exception' => $e,
			]);
			throw new AbortedEventException(
				($isHardStep
					? 'Couldn’t delete the workflow in n8n: '
					: 'Couldn’t sync delete to n8n: ')
				. $e->getMessage()
			);
		}
	}

	/**
	 * True when the node path is inside the user's trashbin
	 * (`/<uid>/files_trashbin/files/...`). NC node paths are slash-rooted at
	 * the storage root so a prefix match on the second segment is enough; we
	 * don't try to pin the uid (which we'd have to guess anyway).
	 */
	private function isInTrashbin(string $path): bool {
		$segments = explode('/', ltrim($path, '/'));
		return count($segments) >= 3
			&& $segments[1] === 'files_trashbin'
			&& $segments[2] === 'files';
	}
}
