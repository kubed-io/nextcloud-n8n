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
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\ReplacedByMoveStore;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the user's NC delete UX into n8n (§17.7).
 *
 * NC's right-click "Delete file" fires {@see BeforeNodeDeletedEvent} **before**
 * storage->unlink. The View-layer dispatch supports {@see AbortedEventException},
 * which sets `arguments['run']=false` and prevents the delete — exactly the
 * "abort if n8n is unreachable" semantics we want.
 *
 * THIS LISTENER OWNS THE **SOFT** STEP ONLY — the user's first delete, with the
 * file still at its normal path on its way to the trash: archive in n8n (`sync`)
 * or strip the mapping tag (`link`).
 *
 * ## THE HARD STEP IS NOT THIS EVENT, AND THE COMMENT THAT SAID IT WAS DID DAMAGE
 *
 * This docblock used to claim the same event fires again on the final purge, with
 * the node under `<uid>/files_trashbin/files/…`, discriminated by path prefix. It
 * does not. The trashbin's `removeItem` emits nothing typed, so that branch never
 * ran and a `sync` workflow whose file was purged stayed alive in n8n forever —
 * a quiet leak nobody goes looking for.
 *
 * It was in fact dead twice over: the trashed node is renamed
 * `<name>.n8n.d<timestamp>`, so {@see FilenameCodec::isWorkflowFile}'s
 * `str_ends_with` guard rejected it several lines earlier, before the path was
 * ever consulted.
 *
 * `nextcloud-penpot` followed this comment into the same bug (penpot saga §C6.13)
 * while `nextcloud-grafana` had it right all along. The purge now has its own
 * entry point, {@see TrashPurgeHook}, on the legacy `\OCP\Trashbin` `preDelete`
 * hook — the only signal Nextcloud offers.
 *
 * ## KNOWN GAP: A TRASH-BYPASSED DELETE ARCHIVES RATHER THAN DELETES
 *
 * When the trash is skipped entirely (admin disabled the trashbin, the
 * `X-NC-Skip-Trashbin` header, or another listener called
 * `MoveToTrashEvent::disableTrashBin()`) this event fires at the normal path and
 * no purge ever follows, so a `sync` workflow is left **archived** instead of
 * deleted. That is the deliberate choice: nothing here can tell "on its way to the
 * trash" from "gone for good", and archiving something that should have been
 * deleted is recoverable, while deleting something that was only trashed is not.
 * Recorded in `features/delete.feature`.
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
		private ReplacedByMoveStore $replaced,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent || $this->guard->active()) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isWorkflowFile($node)) {
			return;
		}

		// AN OVERWRITE IS NOT A DELETE, and this is the only place that can know.
		// Sabre performs a MOVE onto an existing name as `tree->delete($destination)`
		// followed by the move, so the file being REPLACED arrives here looking exactly
		// like one a user asked to delete. It is not: the user answered "keep the new
		// version" in a conflict dialog, and the workflow they kept must stay live.
		// {@see \OCA\N8nSync\DAV\ReplacedByMovePlugin} marks it from sabre's `beforeMove`,
		// which fires while both halves are still one gesture.
		if ($this->replaced->isReplaced($node->getId())) {
			$this->logger->info('n8n_sync delete: this file is being replaced by a move, not deleted', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'file' => $node->getName(),
			]);
			return;
		}

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			// Detached file — no n8n side. Let NC do its normal delete.
			return;
		}
		$id = $managed->workflowId;
		$mode = $managed->mode;
		$mapping = $managed->mappingId !== ''
			? $this->mappings->getById($managed->mappingId)
			: null;

		// A LINK IS NOT NEXTCLOUD'S TO DELETE. The file is a read-only projection of a
		// workflow that lives in n8n and is perfectly fine; removing the pointer only
		// makes the mapped folder disagree with the tag it mirrors, and the next pull
		// writes the file straight back — so the delete was never durable, it was just
		// silent. Refusing says so at the moment the user asks.
		//
		// This is the same rule the DAV write guard already enforces for content
		// ({@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin}); existence is the half that
		// was missing. The way OUT of a link folder is to remove the mapping, or to
		// untag the workflow in n8n — both of which are decisions about the mapping
		// rather than about one file.
		if ($mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'This file is a link to a workflow in n8n, so it cannot be deleted from Nextcloud. '
				. 'Remove the workflow from the mapping\'s tag in n8n instead.',
			);
		}

		try {
			$this->deleteService->softDelete($id, $mode, $mapping);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync soft-delete failed; aborting NC delete', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $id,
				'exception' => $e,
			]);
			throw new AbortedEventException('Couldn’t sync delete to n8n: ' . $e->getMessage());
		}
	}
}
