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
use Psr\Log\LoggerInterface;

/**
 * Three-step delete/restore rule table for §17.7. Listeners delegate the
 * effective-state branching to this one place so it's trivial to audit.
 *
 * Lifecycle (saga Ch3 §14 — mode is `sync` | `link`):
 *   - **softDelete** — user moved the file to NC trash.
 *       sync → archive workflow (`POST /workflows/{id}/archive`).
 *       link → strip the mapping tag (additive untag).
 *   - **hardDelete** — final purge from trash (or trash-bypassed direct delete).
 *       sync → hard-delete the workflow (`DELETE /workflows/{id}`).
 *       link → no-op (the workflow has been live in n8n all along; only the tag
 *       was touched on softDelete).
 *   - **restore** — user restored the file from trash.
 *       sync → unarchive (`POST /workflows/{id}/unarchive`), or create the workflow
 *       again from the file when n8n no longer has it ({@see restore}).
 *       link → re-add the mapping tag (idempotent).
 *
 * Error policy:
 *   - 404 → idempotent success (something already happened on the n8n side) —
 *     EXCEPT on restore, the one step trying to bring something BACK rather than
 *     remove it, where "it isn't there" is the case to act on, not to shrug at.
 *   - 5xx / transport → throw `N8nApiException`; the **listener** decides
 *     whether to abort the NC operation or just log (soft/hard abort vs.
 *     restore log+swallow — see §17.7 design notes).
 *
 * The service itself never throws on a "tag wasn't there" / "tag was already
 * there" — those are no-ops by design (idempotency).
 */
final class DeleteService {
	public function __construct(
		private N8nClient $n8n,
		private CreateService $createService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Soft step (trash-move). See class docblock for the rule table.
	 *
	 * @throws N8nApiException on n8n failure (caller decides to abort)
	 */
	public function softDelete(string $id, string $mode, ?Mapping $mapping): void {
		if ($mode === Mapping::MODE_SYNC) {
			$this->callIdempotent(fn () => $this->n8n->archiveWorkflow($id), $id, 'archive');
			return;
		}
		// LINK NO LONGER REACHES THIS. {@see \OCA\N8nSync\Listener\DeleteToN8nListener}
		// refuses a link delete outright — a pointer is not Nextcloud's to remove — so
		// the only mode that arrives here besides `sync` is `unmapped`, whose file left
		// its mapping and should take its tag with it. Kept rather than deleted because
		// untagging is the right answer for that case and the branch is still the one
		// that serves it.
		if ($mapping === null) {
			$this->logger->info('n8n_sync delete: no mapping resolvable; skipping untag', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
			]);
			return;
		}
		$this->untagWorkflow($id, $mapping->n8nTag);
	}

	/**
	 * Hard step (trash-purge or trash-bypassed delete). Only sync actually removes
	 * the n8n workflow; link is a no-op because the workflow was never NC's to delete.
	 *
	 * @throws N8nApiException on n8n failure (caller decides to abort)
	 */
	public function hardDelete(string $id, string $mode): void {
		if ($mode !== Mapping::MODE_SYNC) {
			return;
		}
		$this->callIdempotent(fn () => $this->n8n->deleteWorkflow($id), $id, 'delete');
	}

	/**
	 * Restore step (NC restore-from-trash). Mirror of softDelete: unarchive the
	 * workflow for sync, or re-add the mapping tag for link. The caller is expected
	 * to log + swallow failures here (don't abort a restore just because n8n is down).
	 *
	 * ## A 404 HERE IS NOT SUCCESS, IT IS A MISSING WORKFLOW
	 *
	 * Every other step in this class treats 404 as idempotent success, because every
	 * other step is trying to REMOVE something. A restore is trying to bring something
	 * back, and there is nothing to bring back — so swallowing the 404 returned the
	 * file to a mapped folder carrying a DEAD id, with nothing created and no sign
	 * anything was wrong. That is precisely the state the user reported from live use
	 * for the archive case: a file in a mapped folder that n8n cannot show you.
	 *
	 * So the sync branch does NOT go through {@see callIdempotent}. It catches its own
	 * 404 and creates the workflow fresh from the bytes the file still holds — the
	 * same move {@see MotionService::moveIn} has always made for the identical
	 * situation (a file carrying an id lands in a mapping, and n8n no longer has it).
	 * `createForFile()` stamps the new id, mode and mapping, so the file comes back
	 * fully attached rather than fully detached.
	 *
	 * ## THIS RUNS TWICE FOR A HOME-STORAGE RESTORE, AND THAT IS STILL SAFE
	 *
	 * {@see \OCA\N8nSync\Listener\TrashRestoreHook} and
	 * {@see \OCA\N8nSync\Listener\RestoreFromTrashListener} both fire for a restore
	 * off the home storage — deliberately, so the working path does not depend on a
	 * legacy hook. Redundant unarchiving was free; a redundant CREATE would mint a
	 * second workflow, which is not.
	 *
	 * It cannot happen: both entry points read the file's metadata fresh at the top
	 * (`FilesMetadataManager::getMetadata()` has no request cache — verified in core),
	 * and `createForFile()` stamps the new id synchronously. Whichever runs second
	 * therefore sees the NEW id and unarchives a workflow that is already live, which
	 * is a no-op. That holds whichever order they fire in, so it does not rest on
	 * core's current ordering (the legacy hook, then the typed event).
	 *
	 * @param File $node the file that has just come back, and the only remaining copy
	 *                   of the workflow's body if n8n no longer has one
	 * @throws N8nApiException on n8n failure (caller chooses to log+swallow)
	 */
	public function restore(File $node, string $id, string $mode, ?Mapping $mapping): void {
		if ($mode === Mapping::MODE_SYNC) {
			$this->unarchiveOrRecreate($node, $id, $mapping);
			return;
		}
		if ($mapping === null) {
			$this->logger->info('n8n_sync restore: no mapping resolvable; skipping re-tag', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
			]);
			return;
		}
		$this->ensureTag($id, $mapping->n8nTag);
	}

	/**
	 * Unarchive $id, or — when n8n no longer has it — create it again from $node.
	 *
	 * A restore into a mapping whose mapping has since been DELETED cannot create:
	 * there is no tag to create under and no folder binding to stamp. The file comes
	 * back as a plain document holding its old id, which is the same end state as any
	 * other file sitting outside every mapping, and moving it into a live mapping is
	 * the gesture that revives it ({@see MotionService::moveIn}). Logged at info so
	 * the dead id in the metadata has an explanation next to it.
	 */
	private function unarchiveOrRecreate(File $node, string $id, ?Mapping $mapping): void {
		try {
			$this->n8n->unarchiveWorkflow($id);
			return;
		} catch (N8nApiException $e) {
			if ($e->httpStatus !== 404) {
				throw $e;
			}
		}

		if ($mapping === null) {
			$this->logger->info('n8n_sync restore: workflow gone in n8n and no mapping to create it in', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
				'fileId' => $node->getId(),
			]);
			return;
		}

		$this->logger->info('n8n_sync restore: workflow gone in n8n; creating it fresh from the restored file', [
			'app' => Application::APP_ID,
			'workflowId' => $id,
			'fileId' => $node->getId(),
		]);
		$this->createService->createForFile($node, $mapping);
	}

	/**
	 * Read-modify-write the workflow's tag list so it no longer contains
	 * $tagName. n8n's `PUT /workflows/{id}/tags` is set-style (replaces the
	 * list), so we fetch first, filter, and only PUT if there's an actual
	 * change. No-op if the tag wasn't on the workflow (idempotent).
	 */
	private function untagWorkflow(string $id, string $tagName): void {
		try {
			$workflow = $this->n8n->getWorkflow($id);
		} catch (N8nApiException $e) {
			if ($e->httpStatus === 404) {
				// Workflow already gone — nothing left to untag. Treat as success.
				return;
			}
			throw $e;
		}
		$existing = $this->extractTagIds($workflow);
		$desired = [];
		foreach (($workflow['tags'] ?? []) as $t) {
			if (!is_array($t)) {
				continue;
			}
			$name = is_string($t['name'] ?? null) ? $t['name'] : '';
			$tid = is_string($t['id'] ?? null) ? $t['id'] : '';
			if ($tid !== '' && $name !== $tagName) {
				$desired[] = $tid;
			}
		}
		if ($desired === $existing) {
			return; // tag wasn't on the workflow → idempotent noop
		}
		$this->n8n->setWorkflowTags($id, $desired);
	}

	/**
	 * Read-modify-write the workflow's tag list so it includes $tagName. Looks
	 * up (or creates) the tag id, then PUTs `existing + [tagId]` if missing.
	 * No-op if the tag is already there (idempotent).
	 */
	private function ensureTag(string $id, string $tagName): void {
		try {
			$workflow = $this->n8n->getWorkflow($id);
		} catch (N8nApiException $e) {
			if ($e->httpStatus === 404) {
				// Workflow was hard-deleted while in trash; nothing to tag.
				$this->logger->info('n8n_sync restore: workflow gone in n8n; skipping re-tag', [
					'app' => Application::APP_ID,
					'workflowId' => $id,
				]);
				return;
			}
			throw $e;
		}
		$existing = $this->extractTagIds($workflow);
		$tagId = $this->n8n->ensureTag($tagName);
		if (in_array($tagId, $existing, true)) {
			return;
		}
		$desired = $existing;
		$desired[] = $tagId;
		$this->n8n->setWorkflowTags($id, $desired);
	}

	/**
	 * Pull tag ids out of a `GET /workflows/{id}` response in a stable order so
	 * the "no change" comparison in {@see untagWorkflow} works as a fast path.
	 *
	 * @param array<string,mixed> $workflow
	 * @return list<string>
	 */
	private function extractTagIds(array $workflow): array {
		$out = [];
		foreach (($workflow['tags'] ?? []) as $t) {
			if (is_array($t) && is_string($t['id'] ?? null) && $t['id'] !== '') {
				$out[] = $t['id'];
			}
		}
		return $out;
	}

	/**
	 * Run an n8n call and swallow `404 Not Found` as success. Anything else
	 * bubbles to the caller (which decides abort vs. log+swallow per the §17.7
	 * rule table). The $operation label is purely for the log line.
	 *
	 * @param callable():mixed $fn
	 */
	private function callIdempotent(callable $fn, string $workflowId, string $operation): void {
		try {
			$fn();
		} catch (N8nApiException $e) {
			if ($e->httpStatus === 404) {
				$this->logger->info('n8n_sync: ' . $operation . ' on missing workflow — treating as success', [
					'app' => Application::APP_ID,
					'workflowId' => $workflowId,
					'operation' => $operation,
				]);
				return;
			}
			throw $e;
		}
	}
}
