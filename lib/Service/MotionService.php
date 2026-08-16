<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Exception\N8nApiException;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * The motion lifecycle (saga Ch3 §14.2) — what happens when a *managed* workflow
 * file (one that already carries an `n8n_id`) is moved between folders. A MOVE is
 * the SAME workflow relocating, never a duplicate; the stable link is the workflow
 * id, so a move OUT then back IN is an **archive** then an **unarchive**, not a
 * delete then a create. (COPY is the opposite — see saga §14.2 `copy.feature`.)
 *
 * Two entry points, called by {@see \OCA\N8nSync\Listener\MotionListener}:
 *
 *   - **moveOut** — a `sync` file left its mapping for an unmapped location.
 *       Archive the workflow in n8n (`POST /workflows/{id}/archive`), then re-stamp
 *       the file `mode=unmapped` with its mapping cleared. The id + versionId +
 *       full JSON stay on the file, so nothing is lost and it is restorable.
 *
 *   - **moveIn** — a file carrying an `n8n_id` (an *unmapped* one) landed in a
 *       mapping. Unarchive (`POST /workflows/{id}/unarchive`) the SAME workflow and
 *       re-stamp `mode=sync` in the target mapping. If the workflow was hard-deleted
 *       in n8n in the meantime (404), fall back to creating it fresh from the file.
 *
 * Error policy mirrors {@see DeleteService}: a 404 from archive is idempotent
 * success (the workflow is already gone); a 404 from unarchive triggers the
 * create-fallback; anything else bubbles as {@see N8nApiException} for the caller
 * to log.
 */
final class MotionService {
	public function __construct(
		private N8nClient $n8n,
		private CreateService $createService,
		private TagSyncService $tagSync,
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A `sync` file was moved OUT of its mapping. Archive it in n8n and re-stamp it
	 * `unmapped` (mapping cleared; id + versionId preserved). Idempotent on the n8n
	 * side — a missing workflow (404) is treated as already-archived.
	 *
	 * @throws N8nApiException on a non-404 n8n failure
	 */
	public function moveOut(File $node, string $id): void {
		try {
			$this->n8n->archiveWorkflow($id);
		} catch (N8nApiException $e) {
			if ($e->httpStatus !== 404) {
				throw $e;
			}
			$this->logger->info('n8n_sync motion: archive on missing workflow — treating as success', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
			]);
		}

		$this->guard->run(function () use ($node): void {
			$this->metadata->write($node->getId(), [
				WorkflowMetadata::KEY_MODE => WorkflowMetadata::MODE_UNMAPPED,
				WorkflowMetadata::KEY_MAPPING => '', // ejected — no longer in a mapping
			]);
		});
	}

	/**
	 * A managed file moved from one mapping straight into another. The SAME workflow
	 * changes which mapping owns it: the old mapping's tag comes off, the new one's goes
	 * on, and the file is re-stamped with the mapping it landed in.
	 *
	 * ## THE TAG IS THE MEMBERSHIP, SO THE TAG IS THE WHOLE MOVE
	 *
	 * A mapping owns a workflow by its tag and nothing else. Swapping the tag is
	 * therefore the entire n8n-side gesture — there is no archive step, because the
	 * workflow never stops being mirrored; it is mirrored somewhere else. Dropping the
	 * old tag FIRST would leave a window where the workflow belongs to no mapping and a
	 * pull could decide its file is stale, so the new tag goes on before the old comes
	 * off.
	 *
	 * ## WHAT THIS DELIBERATELY DOES NOT DO
	 *
	 * It does not rewrite the file's body when the two mappings differ in mode. A `link`
	 * moving into a `sync` mapping is re-stamped `sync` here and still holds a pointer
	 * until the next pull writes the full JSON over it — the same way a link file is
	 * materialised in the first place. Writing it here would mean `putContent()` on a
	 * node the move handler still holds locks on, which is the trap
	 * {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob} exists to avoid.
	 *
	 * @throws N8nApiException on a non-404 n8n failure
	 */
	public function rebind(File $node, string $id, Mapping $srcMapping, Mapping $tgtMapping): void {
		try {
			$this->tagSync->addMappingTag($id, $tgtMapping->n8nTag);
			$this->tagSync->dropSourceTag($id, $srcMapping->n8nTag);
		} catch (N8nApiException $e) {
			if ($e->httpStatus !== 404) {
				throw $e;
			}
			// The workflow is gone from n8n entirely, so there is no membership left to
			// move. Create it fresh in the mapping it landed in, from the bytes the file
			// still holds — the same fallback {@see moveIn} makes for the same reason.
			$this->logger->info('n8n_sync motion: workflow gone in n8n; creating fresh on rebind', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
			]);
			$this->createService->createForFile($node, $tgtMapping);
			return;
		}

		$this->guard->run(function () use ($node, $tgtMapping): void {
			$this->metadata->write($node->getId(), [
				WorkflowMetadata::KEY_MODE => $tgtMapping->mode,
				WorkflowMetadata::KEY_MAPPING => $tgtMapping->id,
			]);
		});
	}

	/**
	 * An unmapped file (carrying its `n8n_id`) was moved INTO a mapping. Restore the
	 * SAME workflow — unarchive it, re-stamp `mode=sync` in the target mapping. If the
	 * workflow no longer exists in n8n (it was permanently deleted), recreate it from
	 * the file content instead.
	 *
	 * @throws N8nApiException on a non-404 n8n failure
	 */
	public function moveIn(File $node, string $id, Mapping $tgtMapping): void {
		// Collision (saga §14.19): a sibling in the landing folder already tracks this
		// workflow — someone has already restored it here. The incoming file is a
		// DUPLICATE, not the same workflow relocating, so mint it as a brand-new instance
		// (copy semantics, §14.5): createForFile strips the carried id and creates a fresh
		// workflow, leaving the existing file and its live workflow untouched. (A same-NAME
		// duplicate never reaches here — Nextcloud refuses that move with a 412 before the
		// rename event fires; only a differently-named duplicate lands.)
		if ($this->findSyncedSibling($node, $id) !== null) {
			$this->logger->info('n8n_sync motion: move-in duplicate of an already-synced workflow; minting a new instance', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
				'fileId' => $node->getId(),
			]);
			$this->createService->createForFile($node, $tgtMapping);
			return;
		}

		try {
			$this->n8n->unarchiveWorkflow($id);
		} catch (N8nApiException $e) {
			if ($e->httpStatus !== 404) {
				throw $e;
			}
			// Workflow was hard-deleted in n8n — recreate from the file we still hold.
			// createForFile() stamps a fresh id + mode=sync + mapping itself.
			$this->logger->info('n8n_sync motion: workflow gone in n8n; creating fresh on move-in', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
			]);
			$this->createService->createForFile($node, $tgtMapping);
			return;
		}

		$this->guard->run(function () use ($node, $tgtMapping): void {
			$this->metadata->write($node->getId(), [
				WorkflowMetadata::KEY_MODE => Mapping::MODE_SYNC,
				WorkflowMetadata::KEY_MAPPING => $tgtMapping->id,
			]);
		});
	}

	/**
	 * Look in the landing folder for a *different* managed workflow file that already
	 * carries the same `n8n_id` as the incoming one — i.e. the workflow is already
	 * synced here. Pull writes workflow files flat into the mapping root, so a
	 * duplicate (if any) sits beside the incoming file; a sibling scan is enough.
	 * Returns the existing synced file, or null when there is no duplicate.
	 */
	private function findSyncedSibling(File $node, string $id): ?File {
		$parent = $node->getParent();
		if (!$parent instanceof Folder) {
			return null;
		}
		foreach ($parent->getDirectoryListing() as $sibling) {
			if (!$sibling instanceof File || $sibling->getId() === $node->getId()) {
				continue; // skip non-files and the incoming file itself
			}
			if (!FilenameCodec::isWorkflowName($sibling->getName())) {
				continue; // only managed workflow files can be a duplicate
			}
			$managed = $this->metadata->read($sibling->getId());
			if ($managed !== null && $managed->workflowId === $id) {
				return $sibling;
			}
		}
		return null;
	}
}
