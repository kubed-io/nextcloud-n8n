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
		private PushService $push,
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
	 * ## THE PILL MOVES WITH IT, THE BODY IS THE SYNC'S JOB
	 *
	 * The tag lives on three surfaces: n8n's tags, the Nextcloud pills, and the file's
	 * own JSON `tags` array. The first two are settled here — a pill write takes no file
	 * lock, so it costs nothing to be exact about the folder the user is looking at.
	 *
	 * The body is NOT written here, and not by a job either. The file is locked for the
	 * length of a rename, so `putContent()` from this call throws (the trap
	 * {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob} exists to avoid) — and a
	 * deferred writer racing the user is a worse cure than the disease. The file's body
	 * is a mirror of n8n, n8n is correct the moment the two calls above return, and
	 * writing mirrors is what the pull already does. So the body settles on the next
	 * sync, exactly like every other change made on the n8n side.
	 *
	 * ## WHAT THIS DELIBERATELY DOES NOT DO
	 *
	 * It does not rewrite the rest of the file's body when the two mappings differ in
	 * mode. A `link` moving into a `sync` mapping is re-stamped `sync` here and still
	 * holds a pointer until the next pull writes the full JSON over it — the same way a
	 * link file is materialised in the first place.
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

		// Best-effort against the n8n write that has already landed: a failure here
		// leaves a stale pill that the next pull corrects, and throwing would tell
		// MotionListener the rebind failed when it did not.
		try {
			$this->tagSync->swapMappingPill($node->getId(), $srcMapping->n8nTag, $tgtMapping->n8nTag);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync motion: swapping the mapping pill failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $id,
				'exception' => $e,
			]);
		}
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
		// workflow, leaving the existing file and its live workflow untouched.
		//
		// THIS IS THE "KEEP BOTH VERSIONS" ANSWER, and it needs nothing special: the
		// conflict picker is client-side, so `Turnbuckle (1).n8n` reaches us as an
		// ordinary MOVE to a free name and lands here like any other duplicate.
		//
		// A same-name duplicate DOES reach here, and the note that used to sit on this
		// line said otherwise: it claimed Nextcloud refuses that move with a 412 before
		// the rename event fires. It does not. Sabre defaults an absent `Overwrite`
		// header to T and performs an overwrite as a delete of the destination followed
		// by a move — so the sibling this scan looks for has been TRASHED by the time we
		// run, no duplicate is found, and the unarchive path below is what answers.
		// Whether that is right is undecided (features/workflows/move.feature, `@todo`).
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

		$this->pushIfTheFileIsAhead($node);
	}

	/**
	 * Send the arriving file's body up when it is not the mirror we last wrote.
	 *
	 * ## WITHOUT THIS, A MOVE-IN SILENTLY UNDOES ITSELF
	 *
	 * Unarchiving and re-stamping settles the file's IDENTITY and says nothing about
	 * its CONTENT, and the two can disagree: a file sitting outside every mapping is
	 * editable and is never pushed ({@see \OCA\N8nSync\Listener\NodeWrittenListener},
	 * and `edit.feature` says so), so it can come back carrying changes n8n has never
	 * seen. Left alone, the next pull compares the two, finds n8n authoritative, and
	 * overwrites the file with the older body — so the user's edit survives the move
	 * and is destroyed by a scheduled job minutes later. A data loss with no gesture
	 * attached to it, which is the worst kind to diagnose.
	 *
	 * It is most visible in an overwrite ("keep the new version" is a statement about
	 * WHICH BODY WINS, and would mean nothing if the body never travelled), but the
	 * bug is not specific to one: every move-in of an edited unmapped file had it.
	 *
	 * ## THE HASH IS THE GATE, AND IT IS THE ONE ALREADY THERE
	 *
	 * `n8n_syncedHash` is the app's memory of the bytes the two sides last agreed on.
	 * Equal means this file IS the mirror and there is nothing to send — which is the
	 * ordinary move-out-and-back, and it stays a pure identity operation with no n8n
	 * write. Different means the file has something n8n does not.
	 *
	 * ## LOGGED, NOT THROWN
	 *
	 * The move has already happened and the identity work already succeeded; throwing
	 * would answer the client with a 500 for a file that did move, and would not put
	 * the body back. A failure here leaves `syncedHash` stale, so the next save or
	 * push retries on its own.
	 */
	private function pushIfTheFileIsAhead(File $node): void {
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isSync()) {
			return;
		}
		try {
			$content = $node->getContent();
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync motion: could not read the arriving file to compare it with n8n', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'exception' => $e,
			]);
			return;
		}
		if ($managed->syncedHash !== '' && $managed->syncedHash === sha1($content)) {
			return; // this file is the mirror we last wrote — nothing to send
		}
		try {
			$this->push->push($node);
			$this->logger->info('n8n_sync motion: the arriving file carried changes n8n had not seen; pushed them', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $managed->workflowId,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync motion: could not push the arriving file’s body; the next save will retry', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $managed->workflowId,
				'exception' => $e,
			]);
		}
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
