<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Exception\N8nApiException;
use Psr\Log\LoggerInterface;

/**
 * Three-step delete/restore rule table for §17.7. Listeners delegate the
 * effective-state branching to this one place so it's trivial to audit.
 *
 * Lifecycle (saga Ch2 §14 — mode is `sync` | `link`):
 *   - **softDelete** — user moved the file to NC trash.
 *       sync → archive workflow (`POST /workflows/{id}/archive`).
 *       link → strip the mapping tag (additive untag).
 *   - **hardDelete** — final purge from trash (or trash-bypassed direct delete).
 *       sync → hard-delete the workflow (`DELETE /workflows/{id}`).
 *       link → no-op (the workflow has been live in n8n all along; only the tag
 *       was touched on softDelete).
 *   - **restore** — user restored the file from trash.
 *       sync → unarchive (`POST /workflows/{id}/unarchive`).
 *       link → re-add the mapping tag (idempotent).
 *
 * Error policy:
 *   - 404 → idempotent success (something already happened on the n8n side).
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
		// link → untag (additive: remove just our tag).
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
	 * @throws N8nApiException on n8n failure (caller chooses to log+swallow)
	 */
	public function restore(string $id, string $mode, ?Mapping $mapping): void {
		if ($mode === Mapping::MODE_SYNC) {
			$this->callIdempotent(fn () => $this->n8n->unarchiveWorkflow($id), $id, 'unarchive');
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
