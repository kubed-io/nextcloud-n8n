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
 *  1. **Gates.** Only a managed **sync** file reaches n8n. An `unmapped`/
 *     untracked file still keeps its own two Nextcloud surfaces in step, because that
 *     pair needs no remote system (§5.10) — that is what lets a tag survive until the
 *     file is moved into a mapping. A `link` is the one full exclusion: its body is a
 *     POINTER, not the workflow, and its pills are a read-only projection of n8n.
 *  2. **Detects the unbind.** n8n binds a workflow to its folder BY TAG, so the
 *     mapping's own tag going missing from the pills is a request to leave, answered
 *     before any merge ({@see unbindIfMappingTagDropped}); we look the tag up from
 *     the file's recorded `n8n_mapping`. A file whose mapping has vanished has no
 *     tag to watch.
 *  3. **Guards + swallows.** The reconcile writes pills (which re-fire tag events) and
 *     talks to n8n, so it runs inside {@see SyncGuard} (no re-entrancy — the pills it
 *     writes don't re-trigger the listener) and best-effort logs a failure instead of
 *     surfacing it (a tag hiccup must never break the user's Files action).
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
			// does not. A `.n8n` outside any mapping still has pills and still has a
			// `tags` array, and keeping those two in step is purely local. This is what
			// makes the transport case work: tags added while a file sits outside a
			// mapping survive in the body and reach n8n when it is moved in (saga §5.10).
			return $this->syncBodyFromPills($node);
		}
		// DROPPING THE MAPPING TAG IS AN UNBIND, NOT AN EDIT, so it is answered before
		// the merge — the merge's job is to settle a set of LABELS, and the mapping tag
		// is not one.
		if ($this->unbindIfMappingTagDropped($node, $managed)) {
			return true;
		}
		$this->guard->run(function () use ($fileId, $managed, $node): void {
			try {
				$rows = $this->tagSync->reconcilePush($fileId, $managed);
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
				$this->syncBodyTags($node, self::nameRows($this->tagSync->readNcContentTags($fileId)));
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

		if ($this->unbindIfMappingTagDropped($node, $managed, $bodyContent)) {
			return true;
		}
		$this->guard->run(function () use ($fileId, $managed, $bodyContent): void {
			try {
				$this->tagSync->reconcilePushFromBody($fileId, $managed, $bodyContent);
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
	/**
	 * Bare pill names → the `{name: …}` rows {@see syncBodyTags} writes; ids fill
	 * in on the next pull.
	 *
	 * @param list<string> $names
	 * @return list<array{name:string}>
	 */
	private static function nameRows(array $names): array {
		return array_map(static fn (string $name): array => ['name' => $name], $names);
	}

	private function syncBodyFromPills(File $node): bool {
		if (!FilenameCodec::isWorkflowFile($node)) {
			return false;
		}
		$rows = self::nameRows($this->tagSync->readNcContentTags($node->getId()));
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
			// DECODE AS OBJECTS, NOT ARRAYS. n8n's schema is strict about JSON *types*:
			// `connections`/`settings`/`staticData` must be objects, and an empty `{}`
			// round-tripped through an assoc array re-encodes as `[]`, which earns a
			// `400 … must be object` on the next push. PushService::pushViaApi already
			// carries this lesson; re-encoding the body here without it would have
			// quietly corrupted every workflow whose settings or connections were empty.
			$wf = json_decode($content, false);
			if (!$wf instanceof \stdClass) {
				return; // a link pointer or a hand-mangled file — nothing to keep in step
			}
			// Rows without a usable name are dropped. Every current caller already
			// guarantees non-empty (pushSourceTags filters them, readNcContentTags cannot
			// produce one), so this is defence in depth — but the alternative is a
			// nameless `{}` or a bare `{"id":…}` written into a user's file, and this
			// method should not depend on all of its callers staying careful.
			$rows = array_values(array_filter($rows, static function (array $r): bool {
				$name = (string)($r['name'] ?? '');
				return $name !== '';
			}));
			usort($rows, static fn (array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
			// `tags` is a LIST, so an array of arrays encodes correctly as a JSON array
			// of objects — only the surrounding body needed to stay stdClass.
			$wf->tags = array_map(static fn (array $r): object => (object)$r, $rows);
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
	 * Removing the MAPPING TAG in Nextcloud takes the workflow out of the mapping.
	 *
	 * The tag a mapping binds to is not a label — it is the membership itself, so
	 * dropping it is not an edit to reconcile but a request to leave. This used to be
	 * REFUSED: the tag was force-kept on both sides so a pill could never unbind, and
	 * the sanctioned way out was to hand-apply `n8n:ignore`, a reserved tag that
	 * archived the workflow and left the file sitting in a mapped folder that no longer
	 * owned it. That was a whole feature defending a gesture the user meant, and it
	 * contradicted the app's own premise — a file in a mapped folder is IN the mapping.
	 *
	 * So it is honoured instead, and it costs n8n nothing:
	 *
	 *   1. the tag is removed from the workflow in n8n — the ONLY change made there;
	 *      every other tag stays, the workflow itself is untouched and not archived;
	 *   2. the mirror is removed from Nextcloud.
	 *
	 * NOT A DELETE, and the difference matters: nothing is lost, because the workflow
	 * is still in n8n exactly as it was minus one tag. The file is not trashed either —
	 * trashing a managed file MEANS something here (it archives the workflow), and
	 * routing an unsync through it would fire that. It is unmirrored.
	 *
	 * A LINK NEVER REACHES THIS. Its pills are a read-only projection of n8n that the
	 * next pull overwrites, so {@see reconcileFile} returns before here.
	 *
	 * @param list<string>|null $ncContent the tag names to judge — the file body's on
	 *                                     the body path, else the pills
	 * @return bool true when the file was unbound (the caller must stop)
	 */
	private function unbindIfMappingTagDropped(File $node, ManagedFile $managed, ?array $ncContent = null): bool {
		if ($managed->mappingId === '') {
			return false;
		}
		$mapping = $this->mappings->getById($managed->mappingId);
		if ($mapping === null || $mapping->n8nTag === '') {
			return false;
		}
		$names = $ncContent ?? $this->tagSync->readNcContentTags($node->getId());
		if (in_array($mapping->n8nTag, $names, true)) {
			return false; // still a member
		}

		// FROM HERE THE MERGE MUST NOT RUN, whether or not the unbind succeeds — which
		// is why this returns true even on failure. Falling through would hand the
		// merge a Nextcloud set with the mapping tag missing, and the merge would read
		// that as an ordinary removal and push it: the workflow would leave the mapping
		// anyway, without the mirror being cleaned up. A half-unbind is worse than
		// either outcome.
		try {
			$this->guard->run(function () use ($node, $managed, $mapping): void {
				$this->tagSync->dropSourceTag($managed->workflowId, $mapping->n8nTag);
				$node->delete();
			});
		} catch (\Throwable $e) {
			// LEAVE THE MIRROR ALONE. If n8n could not be told, deleting the file would
			// strand a workflow that still carries the mapping tag — the next pull would
			// mirror it straight back, and the user would watch their gesture undo
			// itself. Keep everything as it was and let the next sync settle it.
			$this->logger->warning('n8n_sync unbind failed; the mirror was kept', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $managed->workflowId,
				'exception' => $e,
			]);
		}
		return true;
	}
}
