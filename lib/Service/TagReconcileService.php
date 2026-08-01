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
 * Two reactive surfaces were designed to converge here (saga Ch5 §5.6.2):
 *
 *  - **Pills** ({@see reconcileFile}) — a system-tag pill toggle. NC pills are truth,
 *    and this path is LIVE (wired by the ContentTagListener). It carries the pill to
 *    n8n and leaves the file body untouched; the body's `tags` mirror self-heals on
 *    the next pull.
 *  - **Body** ({@see reconcileFromBody}) — a hand-edit of the file's JSON `tags` array.
 *    This is the deferred Slice B: the engine below is built and unit-tested but has
 *    **no production caller** (the writeback push does not invoke it). See saga
 *    §5.6.2.3 for why body-tag input was deferred and the shape it must take when
 *    picked up (a dedicated body-write trigger, never a refactor of the pill merge).
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
				$this->tagSync->reconcilePush($fileId, $managed, $protected);
				// Slice A only: the pill is carried to n8n and the pills converge.
				// We deliberately do NOT rewrite the file body `tags` array here — that
				// (body ⇆ pills projection) is the deferred Slice B (saga §5.6.2.3). The
				// body is a derived mirror and self-heals on the next pull, so a briefly
				// stale on-disk `tags` array reverts nothing.
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
	 * Reconcile one file's **body** `tags` array to n8n and the pills (Slice B).
	 *
	 * DORMANT / NOT WIRED. This is the deferred Slice B engine (saga §5.6.2.3): it is
	 * fully built and unit-tested but has **no production caller**. The writeback push
	 * ({@see PushService}) intentionally does NOT invoke it, because the REST `PUT` body
	 * omits tags and driving a body-tag push through the shared merge regressed the
	 * live pill path. When body-tag input is picked up it must run from a dedicated
	 * body-write trigger that leaves the pill merge alone — see saga §5.6.2.3.
	 *
	 * When wired, the body tags are the NC-side truth. A user may add a bare
	 * `{"name":"foo"}` to the array; we ensure/set it on n8n and then rewrite the body
	 * with n8n's canonical `{id,name}` object, so the id is filled in from the source.
	 * Returns the file's final content so the caller can stamp the loop-guard hash
	 * against what is actually on disk; returns `$content` unchanged when nothing
	 * reconciled. A no-op fast path skips the n8n round-trip entirely when the body's
	 * tags still match the baseline.
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
		$wf['tags'] = $rows;
		// JSON_PRETTY carries JSON_THROW_ON_ERROR, so json_encode returns a string
		// (or throws) — never false; only the "unchanged" case yields no write.
		$new = json_encode($wf, N8nWorkflowBody::JSON_PRETTY);
		if ($new === $original) {
			return null;
		}
		$node->putContent($new);
		return $new;
	}

	/** True when two name lists are the same set (order/dupes ignored). */
	private static function sameSet(array $a, array $b): bool {
		$a = array_unique($a);
		$b = array_unique($b);
		sort($a);
		sort($b);
		return $a === $b;
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
