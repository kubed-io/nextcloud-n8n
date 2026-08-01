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
 *  1. **Gates.** Only a managed **sync** file reaches n8n. An `unmapped`/`ignored`/
 *     untracked file still keeps its own two Nextcloud surfaces in step, because that
 *     pair needs no remote system (§5.10) — that is what lets a tag survive until the
 *     file is moved into a mapping. A `link` is the one full exclusion: its body is a
 *     POINTER, not the workflow, and its pills are a read-only projection of n8n.
 *  2. **Resolves the protected set.** n8n binds a workflow to its folder BY TAG, so the
 *     mapping's own tag is force-kept on both sides; we look it up from the file's
 *     recorded `n8n_mapping`. A file whose mapping has vanished protects nothing.
 *  3. **Guards + swallows.** The reconcile writes pills (which re-fire tag events) and
 *     talks to n8n, so it runs inside {@see SyncGuard} (no re-entrancy — the pills it
 *     writes, including a force-kept mapping-tag pop-back, don't re-trigger the
 *     listener) and best-effort logs a failure instead of surfacing it (a tag hiccup
 *     must never break the user's Files action).
 *
 * Two reactive surfaces, both LIVE (saga §5.9):
 *
 *  - **Pills** ({@see reconcileFile}) — a system-tag pill toggle, wired by
 *    {@see \OCA\N8nSync\Listener\ContentTagListener}. Carries the pill to n8n AND
 *    writes the file's `tags` array so the body never lags.
 *  - **Body** ({@see reconcileFromBody}) — a hand-edit of the file's JSON `tags`
 *    array, wired by {@see \OCA\N8nSync\Listener\BodyTagListener}. The body is the
 *    NC-side truth for that save; the pills converge to the merged result.
 *
 * ## WHY THE LOCKSTEP IS LOAD-BEARING, NOT COSMETIC
 *
 * The body was the only surface that could go stale, and a pill edit was the ONLY
 * thing that could make it stale (enumerated in `features/tag-sync.feature`). That
 * mattered because it made a body edit *undecidable*: `body ≠ pills` could mean "the
 * user removed a tag" or "a pill was added and the body lagged" — the same on-disk
 * state with opposite correct answers, so no precedence rule could tell them apart.
 *
 * Rather than track staleness with a fourth stamp, {@see reconcileFile} removes its
 * only cause. After that, `body != pills` can only mean a body edit, and the third
 * direction needs no extra state at all.
 *
 * The two paths share the merge ENGINE but not their entry points, and that is
 * deliberate: an earlier attempt refactored `reconcilePush` so both went through one
 * "read the NC side" step and regressed the shipping pill path (saga §5.6.2.3).
 * {@see TagSyncService::reconcilePush} must stay untouched by the body path.
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
		$fileId = $node->getId();
		$managed = $this->metadata->read($fileId);
		if ($managed === null || !$managed->isManaged() || !$managed->isSync()) {
			// A LINK IS THE ONE EXCLUSION, and it is not an oversight. A link's body is a
			// POINTER (id, name, url, tags), not the workflow, and its pills are a
			// read-only projection of n8n that the next pull overwrites — so writing
			// either from the other would fabricate agreement that n8n never asked for.
			if ($managed !== null && $managed->isLink()) {
				return false;
			}
			// Otherwise NOT A DEAD END — the n8n leg needs a mapping, the Nextcloud pair
			// does not. A `.n8n.json` outside any mapping still has pills and still has a
			// `tags` array, and keeping those two in step is purely local. This is what
			// makes the transport case work: tags added while a file sits outside a
			// mapping survive in the body and reach n8n when it is moved in (saga §5.10).
			return $this->syncBodyFromPills($node);
		}
		$protected = $this->protectedTagsFor($managed);
		$this->guard->run(function () use ($fileId, $managed, $protected, $node): void {
			try {
				$rows = $this->tagSync->reconcilePush($fileId, $managed, $protected);
				// THE LOCKSTEP, AND IT IS THE WHOLE POINT (saga §5.9). A pill edit used to
				// carry the tag to n8n and leave the file body alone, which made the body
				// the ONE surface that could go stale — and the only cause of it. That
				// staleness is what made a body-tag edit undecidable: `body ≠ pills` could
				// mean "the user removed a tag" or "a pill was added and the body lagged",
				// two identical states with opposite correct answers.
				//
				// Writing the body here removes the cause instead of tracking it. After
				// this, `body ≠ pills` can ONLY mean the user edited the body, which is
				// what makes {@see reconcileFromBody} decidable at all.
				$this->syncBodyTags($node, $rows);
			} catch (\Throwable $e) {
				// The user's pill click already landed in Nextcloud; a failure to carry
				// it to n8n is logged and retried by the next sync, never surfaced as a
				// broken Files action.
				$this->logger->warning('n8n_sync reactive tag reconcile failed', [
					'app' => Application::APP_ID,
					'fileId' => $fileId,
					'exception' => $e,
				]);
				// STILL KEEP THE TWO NEXTCLOUD SURFACES TOGETHER. n8n being unreachable is
				// no reason to let the body disagree with the pills — that disagreement is
				// the ambiguity the whole design exists to prevent, and it must not be
				// reachable through an outage either. The pills are what the user just
				// changed, so the body follows them; ids fill in on the next pull.
				$this->syncBodyTags($node, array_map(
					static fn (string $name): array => ['name' => $name],
					$this->tagSync->readNcContentTags($fileId),
				));
			}
		});
		return true;
	}

	/**
	 * Reconcile one file's **body** `tags` array to n8n and the pills — the third
	 * direction, and the one "full sync" means (saga §5.9).
	 *
	 * ## THE FAST PATH COMPARES THE BODY TO THE **PILLS**, AND THAT IS THE DESIGN
	 *
	 * It used to compare the body to `n8n_syncedTags` (the agreed baseline), which
	 * cannot work: the baseline moves on a pill edit while the body does not, so an
	 * ordinary nodes-only save looked exactly like a deliberate tag removal. That is
	 * the ambiguity that deferred this feature twice.
	 *
	 * With {@see reconcileFile} now keeping the body in step, the body can no longer
	 * lag the pills — so `body == pills` means *the user did not touch the tags* and
	 * costs nothing (no `getWorkflow`, no `setWorkflowTags`), and `body != pills`
	 * means *the user edited the body*, unambiguously. The decidability comes from
	 * the lockstep, not from a cleverer comparison here.
	 *
	 * ## THE BODY IS LEFT EXACTLY AS TYPED
	 *
	 * A human may write `{"name": "prod"}` with no id. We resolve the name for n8n
	 * and converge the pills, but we do NOT rewrite the file: the array stays as the
	 * user typed it and gains its canonical `{id,name}` rows on the next pull. That
	 * is deliberate — rewriting a file the user is actively editing to "correct" it
	 * is hostile, and it re-introduces the re-entrant write this path exists without.
	 *
	 * Returns true when a reconcile was attempted.
	 */
	public function reconcileFromBody(File $node, string $content): bool {
		$wf = json_decode($content, true);
		if (!is_array($wf)) {
			return false; // not a JSON object — leave the file untouched
		}
		$fileId = $node->getId();
		$bodyContent = $this->tagSync->contentTagsFromWorkflow($wf);
		if (self::sameSet($bodyContent, $this->tagSync->readNcContentTags($fileId))) {
			return false; // tags untouched by this save — the common case, and free
		}

		// Null-checked explicitly rather than with `?->`: mixing a nullsafe call into the
		// mode gate left Psalm unable to narrow $managed afterwards, and it reported the
		// managed branch as dead code. Being explicit costs a line and keeps the analyser
		// able to prove the branch is reachable.
		$managed = $this->metadata->read($fileId);
		if ($managed !== null && $managed->isLink()) {
			// See reconcileFile(): a link's pills are a read-only projection of n8n, so a
			// hand-edit of its pointer body must not move them.
			return false;
		}
		if ($managed === null || !$managed->isManaged() || !$managed->isSync()) {
			// Nextcloud-local only: converge the pills on what the body says and stop.
			// There is no workflow to tell, and nothing here needs one (saga §5.10).
			$this->guard->run(fn () => $this->tagSync->writeNcContentTags($fileId, $bodyContent));
			return true;
		}

		$protected = $this->protectedTagsFor($managed);
		$this->guard->run(function () use ($fileId, $managed, $bodyContent, $protected): void {
			try {
				$this->tagSync->reconcilePushFromBody($fileId, $managed, $bodyContent, $protected);
			} catch (\Throwable $e) {
				// The user's save already landed; a failure to carry its tags to n8n is
				// logged and retried by the next sync, never surfaced as a broken save.
				$this->logger->warning('n8n_sync reactive body-tag reconcile failed', [
					'app' => Application::APP_ID,
					'fileId' => $fileId,
					'exception' => $e,
				]);
			}
		});
		return true;
	}

	/**
	 * The Nextcloud-local half of the lockstep, for a file with no live workflow to
	 * consult: write the file's own pills into its `tags` array.
	 *
	 * Rows carry a `name` and NOTHING ELSE, and that is correct rather than lazy — n8n
	 * has never seen these tags, so there is no id to write. A bare `{"name": "foo"}`
	 * is exactly what the file should hold until n8n mints an id for it, which happens
	 * when the file is adopted into a mapping and the pull writes canonical rows back.
	 *
	 * Returns true when a reconcile was attempted.
	 */
	private function syncBodyFromPills(File $node): bool {
		if (!FilenameCodec::isWorkflowFile($node)) {
			return false;
		}
		$pills = $this->tagSync->readNcContentTags($node->getId());
		$rows = array_map(static fn (string $name): array => ['name' => $name], $pills);
		$this->guard->run(fn () => $this->syncBodyTags($node, $rows));
		return true;
	}

	/**
	 * Write n8n's canonical tag rows into the file's `tags` array so the body never
	 * lags the pills — the lockstep that makes the third direction decidable.
	 *
	 * Rows are sorted by name for a stable on-disk order, and the file is written only
	 * when the encoded body actually changed, so a pill toggle that resolves to the
	 * same set never churns the file. The loop guard is a re-stamped
	 * `n8n_syncedHash`: without it the next unrelated save would see a hash mismatch
	 * and push a body that is already current.
	 *
	 * NEVER THROWS. Four things in here can — `getContent()`, `json_encode` (it carries
	 * JSON_THROW_ON_ERROR), `putContent()` and the metadata write — and one call site is
	 * inside the failure handler of {@see reconcileFile}, where an escaping exception
	 * would replace a logged n8n failure with an unhandled one and break the user's
	 * Files action. Keeping the body in step is best-effort by definition: the next pull
	 * rewrites it regardless.
	 *
	 * Caller must hold the {@see SyncGuard} — the write fires a NodeWrittenEvent that
	 * both the writeback push and {@see \OCA\N8nSync\Listener\BodyTagListener} must
	 * recognise as the app's own.
	 *
	 * @param list<array<string,mixed>> $rows n8n's canonical tag rows
	 */
	private function syncBodyTags(File $node, array $rows): void {
		try {
			$content = $node->getContent();
			$wf = json_decode($content, true);
			if (!is_array($wf)) {
				return; // a link pointer or a hand-mangled file — nothing to keep in step
			}
				// STRIP THE RESERVED NAMESPACE BEFORE IT REACHES THE FILE. reconcilePush()
			// returns n8n's canonical rows for the FINAL set, and that set deliberately
			// re-sends any `n8n:*` marker the workflow already carried (setWorkflowTags is
			// a full replace, so preserving them is the only way not to drop them).
			// Correct for n8n; wrong for the body. The body is CONTENT, and it is also
			// the one PORTABLE surface — a reserved marker written here would travel with
			// the file and seed itself as a content tag on adoption somewhere else.
			$rows = array_values(array_filter(
				$rows,
				static fn (array $r): bool => !str_starts_with((string)($r['name'] ?? ''), TagSyncService::RESERVED_PREFIX),
			));
			usort($rows, static fn (array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
			$wf['tags'] = $rows;
			$new = json_encode($wf, N8nWorkflowBody::JSON_PRETTY);
			if ($new === $content) {
				return;
			}
			$node->putContent($new);
			$this->metadata->write($node->getId(), [WorkflowMetadata::KEY_SYNCED_HASH => sha1($new)]);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: could not keep the file body tags in step', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'exception' => $e,
			]);
		}
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
