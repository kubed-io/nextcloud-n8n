<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Reactive NC → n8n tag reconcile for a **single** file (saga Ch5 §5.6.2, Slice A).
 * The bulk sync reconciles tags inside its own pull/push loop; this is the small
 * orchestrator the *event-driven* path shares — a content-tag pill edit caught by
 * {@see \OCA\N8nSync\Listener\ContentTagListener} (inline) or drained by
 * {@see \OCA\N8nSync\BackgroundJob\ReconcileTagsJob} (async).
 *
 * It does three things the raw {@see TagSyncService::reconcilePush} can't do on its
 * own, and nothing else:
 *
 *  1. **Gates.** Only a managed **sync** file reconciles — a `link` file's pills are a
 *     read-only projection of n8n (pull-only), and an `unmapped`/`ignored` file is a
 *     plain Nextcloud file the tag machinery must not touch (§5.6 scope).
 *  2. **Resolves the protected set.** n8n binds a workflow to its folder BY TAG, so the
 *     mapping's own tag is force-kept on both sides; we look it up from the file's
 *     recorded `n8n_mapping`. A file whose mapping has vanished protects nothing.
 *  3. **Guards + swallows.** The reconcile writes pills (which re-fire tag events) and
 *     talks to n8n, so it runs inside {@see SyncGuard} (no re-entrancy — the pills it
 *     writes, including a force-kept mapping-tag pop-back, don't re-trigger the
 *     listener) and best-effort logs a failure instead of surfacing it (a tag hiccup
 *     must never break the user's Files action).
 *
 * Two reactive surfaces converge here (saga Ch5 §5.6.2):
 *
 *  - **Pills** ({@see reconcileFile}) — a system-tag pill toggle. NC pills are truth.
 *  - **Body** ({@see reconcileFromBody}) — a hand-edit of the file's JSON `tags` array
 *    (Slice B). The body tags are truth. Both paths then lockstep the *other* NC
 *    surface to the merged result, so the pills and the on-disk `tags` never disagree
 *    and a later edit of either can't diff against a stale sibling.
 */
final class TagReconcileService {
	public function __construct(
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private TagSyncService $tagSync,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile one file's Nextcloud content pills to n8n. No-op (returns false) for a
	 * non-managed, non-sync file; true when a reconcile was attempted (success or a
	 * swallowed failure). Safe to call from an event handler or a background job.
	 */
	public function reconcileFile(File $node): bool {
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged() || !$managed->isSync()) {
			return false; // link/unmapped/ignored/foreign → not a reactive push surface
		}
		$protected = $this->protectedTagsFor($managed);
		$fileId = $node->getId();
		$this->guard->run(function () use ($fileId, $managed, $protected, $node): void {
			try {
				$rows = $this->tagSync->reconcilePush($fileId, $managed, $protected);
				// Lockstep the on-disk `tags` array to n8n's canonical rows so a later
				// hand-edit of the JSON can never diff against a body left stale by
				// this pill change.
				$this->lockstepBody($node, $rows, true);
			} catch (\Throwable $e) {
				// The user's pill click already landed in Nextcloud; a failure to carry
				// it to n8n is logged and retried by the next sync, never surfaced as a
				// broken Files action.
				$this->logger->warning('n8n_sync reactive tag reconcile failed', [
					'app' => Application::APP_ID,
					'fileId' => $fileId,
					'exception' => $e,
				]);
			}
		});
		return true;
	}

	/**
	 * Reconcile one file's **body** `tags` array to n8n and the pills (Slice B). Called
	 * from the writeback push ({@see PushService}) so an edit to the JSON `tags` array
	 * reaches n8n on its own — the REST `PUT` body omits tags, so without this a
	 * body-tag edit is silently dropped.
	 *
	 * The body tags are the NC-side truth. A user may add a bare `{"name":"foo"}` to the
	 * array; we ensure/set it on n8n and then rewrite the body with n8n's canonical
	 * `{id,name}` object, so the id is filled in from the source. Returns the file's
	 * final content so the caller can stamp the loop-guard hash against what is actually
	 * on disk; returns `$content` unchanged when nothing reconciled. A no-op fast path
	 * skips the n8n round-trip entirely when the body's tags still match the baseline.
	 */
	public function reconcileFromBody(File $node, string $content): string {
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged() || !$managed->isSync()) {
			return $content;
		}
		$wf = json_decode($content, true);
		if (!is_array($wf)) {
			return $content; // not a JSON object — leave the file untouched
		}
		$bodyContent = $this->tagSync->contentTagsFromWorkflow($wf);
		// Fast path: the body's tags are unchanged vs the last agreed baseline, so
		// there is nothing NC-side to push. n8n-side drift is the pull's job. This
		// keeps an ordinary nodes-only save from a pointless getWorkflow + setTags.
		if (self::sameSet($bodyContent, $managed->syncedTagList())) {
			return $content;
		}
		$protected = $this->protectedTagsFor($managed);
		$fileId = $node->getId();
		$final = $content;
		$this->guard->run(function () use (&$final, $fileId, $managed, $bodyContent, $protected, $node, $wf, $content): void {
			try {
				$rows = $this->tagSync->reconcilePushFromBody($fileId, $managed, $bodyContent, $protected);
				$final = $this->rewriteBodyTags($node, $wf, $content, $rows) ?? $content;
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync reactive body-tag reconcile failed', [
					'app' => Application::APP_ID,
					'fileId' => $fileId,
					'exception' => $e,
				]);
			}
		});
		return $final;
	}

	/**
	 * Lockstep the on-disk `tags` array to n8n's canonical rows and, when asked,
	 * re-stamp the loop-guard hash — used by the pill path to keep the body in step
	 * after a pill change (the re-stamp means the body write it triggers is recognised
	 * as ours and pushes nothing). Must run inside the guard.
	 *
	 * @param list<array<string,mixed>> $rows n8n's canonical tag rows
	 */
	private function lockstepBody(File $node, array $rows, bool $restampHash): void {
		try {
			$content = $node->getContent();
		} catch (\Throwable) {
			return;
		}
		$wf = json_decode($content, true);
		if (!is_array($wf)) {
			return;
		}
		$new = $this->rewriteBodyTags($node, $wf, $content, $rows);
		if ($new !== null && $restampHash) {
			$this->metadata->write($node->getId(), [WorkflowMetadata::KEY_SYNCED_HASH => sha1($new)]);
		}
	}

	/**
	 * Set the decoded workflow's `tags` to n8n's canonical rows (content + reserved
	 * markers, each carrying the real tag id), sorted by name for a stable on-disk
	 * order, and write the re-encoded body only when it actually changed — so a bare
	 * `{"name":"foo"}` gains its id, a removed tag disappears, and an unchanged set
	 * never churns the file. Returns the new content when written, else null.
	 * Guard-scoped (the write fires a guarded, ignored NodeWrittenEvent).
	 *
	 * @param array<string,mixed> $wf decoded body
	 * @param list<array<string,mixed>> $rows n8n's canonical tag rows
	 */
	private function rewriteBodyTags(File $node, array $wf, string $original, array $rows): ?string {
		usort($rows, static fn (array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
		$wf['tags'] = array_values($rows);
		$new = json_encode($wf, N8nWorkflowBody::JSON_PRETTY);
		if (!is_string($new) || $new === $original) {
			return null;
		}
		$node->putContent($new);
		return $new;
	}

	/** True when two name lists are the same set (order/dupes ignored). */
	private static function sameSet(array $a, array $b): bool {
		return array_fill_keys($a, true) == array_fill_keys($b, true);
	}

	/**
	 * The mapping tags to force-keep for this file: its own mapping's binding tag, or
	 * none if the mapping id is unset or no longer resolves.
	 *
	 * @return list<string>
	 */
	private function protectedTagsFor(ManagedFile $managed): array {
		if ($managed->mappingId === '') {
			return [];
		}
		$mapping = $this->mappings->getById($managed->mappingId);
		return $mapping !== null ? [$mapping->n8nTag] : [];
	}
}
