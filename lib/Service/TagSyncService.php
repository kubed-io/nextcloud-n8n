<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;

/**
 * Keeps a workflow's **content tags** in sync three ways (saga Ch5 §5.6): the n8n
 * workflow's `tags`, the Nextcloud system-tag pills on the mirror file, and the
 * `n8n_syncedTags` baseline that records what the two last agreed on. The set
 * algebra lives in {@see TagMerge}; this class is the IO around it — reading and
 * writing NC system tags and pushing tags to n8n.
 *
 * Two directions, one baseline:
 *
 *  - **Pull** ({@see reconcilePull}) runs for every synced file (sync *and* link —
 *    searchability is mode-independent). n8n is authoritative, but a pull never
 *    destroys a tag the user added on the Nextcloud side since the last sync, so the
 *    pill set becomes `source ∪ (nc-local additions)`. Those local additions reach
 *    n8n on the next push.
 *  - **Push** ({@see reconcilePush}) runs for synced files on writeback. Nextcloud's
 *    pills are reconciled with n8n through the deterministic {@see TagMerge} — an
 *    NC-side removal of a baseline tag propagates to n8n, an n8n-side addition since
 *    the baseline is kept, and there is no ambiguous conflict to break (see TagMerge).
 *
 * Two namespaces are handled here so {@see TagMerge} only ever sees plain content:
 *
 *  - **Reserved** — anything under {@see RESERVED_PREFIX} (`n8n:sync`, `n8n:link`,
 *    `n8n:ignore`, …). These are the app's own pills and the user's n8n-side
 *    override markers; they are NEVER mirrored to Nextcloud as content and NEVER
 *    imported from n8n as content. The push does NOT *originate* reserved tags onto
 *    n8n — but because {@see N8nClient::setWorkflowTags} is a full replace, any
 *    reserved marker a user already set on the n8n workflow is re-sent verbatim so
 *    the replace doesn't drop it (see {@see pushSourceTags}). Preserving is not
 *    pushing: we never send a reserved tag n8n didn't already have.
 *  - **Protected** — n8n binds a workflow to a folder BY TAG, so a mapping's tag is a
 *    content tag whose removal would unmap the workflow. It is shown as a pill but is
 *    force-kept on both sides: removing its pill never unbinds the workflow (to
 *    unmap, move the file out of the folder).
 */
final class TagSyncService {
	/** Namespace owned by the app / reserved for n8n-side markers — never content. */
	public const RESERVED_PREFIX = 'n8n:';

	private const OBJECT_TYPE = 'files';

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
	) {
	}

	/**
	 * Pull: reconcile n8n's tags onto the Nextcloud file and re-stamp the baseline.
	 * n8n wins. For a **sync** file, NC-local additions survive (they push out next
	 * time); for a **link** file the pull is a pure mirror — n8n's tags are the whole
	 * read-only set, a locally-added pill can never push and is dropped here so a link
	 * is a faithful, searchable projection of n8n and nothing more.
	 *
	 * @param array<string,mixed> $workflow the n8n workflow row (carries `tags`)
	 * @param list<string> $protected mapping tags that must never be dropped
	 */
	public function reconcilePull(int $fileId, array $workflow, ManagedFile $managed, array $protected): void {
		$source = $this->contentTags($this->tagNames($workflow));
		$nc = $this->readNcContentTags($fileId);
		$baseline = $managed->syncedTagList();

		// Pull is NOT the symmetric TagMerge — deliberately. n8n is authoritative
		// here, so the desired pill set is `source ∪ (tags NC gained since the last
		// sync)`. A tag the user *removed* on the NC side that n8n still carries is
		// re-added (source wins); removing it durably is a push (NC wins) or an n8n
		// edit, not a pull. Running the symmetric merge on every pull would instead
		// flip-flop such a tag (pull N removes it, pull N+1 re-adds it from source).
		//
		// A **link** keeps no local adds: it has no push channel, so a pill added on a
		// link could never reach n8n and would linger as a phantom. Links are a
		// read-only projection — the pull mirrors n8n's content tags exactly (plus the
		// force-kept mapping tag), and a stray local pill is wiped on the next pull.
		$localAdds = $managed->isSync() ? array_values(array_diff($nc, $baseline)) : [];
		$desired = $this->withProtected(array_merge($source, $localAdds), $protected);

		// Reuse the `$nc` we just read — nothing has touched the pills since.
		$this->writeNcContentTags($fileId, $desired, $nc);
		// Baseline = the source's content tags, plus the force-kept protected (mapping)
		// tags — but NOT the NC-local additions. A local add is not agreed until a push
		// lands it in n8n, so it stays out of the baseline and the next push reads it as
		// `nc − baseline` and propagates it. (Protected tags are in the baseline so a
		// later genuine remove of one is still measured against a set that contained it.)
		$this->metadata->stampTags($fileId, $this->withProtected($source, $protected));
	}

	/**
	 * Push: reconcile the Nextcloud **pills** onto the n8n workflow and re-stamp the
	 * baseline. Fetches the live workflow so the deterministic {@see TagMerge} sees
	 * n8n's current tags and any reserved markers are preserved. Sync files only.
	 * Returns n8n's canonical tag rows after the set, so callers can write the real
	 * `{id,name}` objects back into the file body.
	 *
	 * @param list<string> $protected mapping tags that must never be dropped
	 * @return list<array<string,mixed>> n8n's canonical tag rows (id+name+…) after the set
	 */
	public function reconcilePush(int $fileId, ManagedFile $managed, array $protected): array {
		return $this->reconcilePushWith($fileId, $managed, $this->readNcContentTags($fileId), $protected);
	}

	/**
	 * Push from the file **body**: same three-way reconcile as {@see reconcilePush},
	 * but the Nextcloud-side content set is the file's own `tags` array rather than
	 * the pills (Slice B — an edit to the JSON `tags` is an authoritative NC-side
	 * statement). The pills are then converged to the merged result too, so the two
	 * NC surfaces never disagree. Returns n8n's canonical tag rows after the set.
	 *
	 * @param list<string> $bodyContentTags reserved-free content tags read from the body
	 * @param list<string> $protected mapping tags that must never be dropped
	 * @return list<array<string,mixed>> n8n's canonical tag rows after the set
	 */
	public function reconcilePushFromBody(int $fileId, ManagedFile $managed, array $bodyContentTags, array $protected): array {
		return $this->reconcilePushWith($fileId, $managed, $bodyContentTags, $protected);
	}

	/**
	 * Shared push reconcile: merge `$ncContent` (pills or body tags) with n8n against
	 * the baseline, converge the pills and the n8n workflow on the result, re-stamp
	 * the baseline. Preserves n8n's reserved markers ({@see pushSourceTags}). Returns
	 * n8n's canonical tag rows so the caller can mirror the real `{id,name}` objects
	 * into the file body.
	 *
	 * @param list<string> $ncContent the NC-side content set to treat as truth
	 * @param list<string> $protected mapping tags that must never be dropped
	 * @return list<array<string,mixed>> n8n's canonical tag rows after the set
	 */
	private function reconcilePushWith(int $fileId, ManagedFile $managed, array $ncContent, array $protected): array {
		$workflow = $this->n8n->getWorkflow($managed->workflowId);
		$sourceNames = $this->tagNames($workflow);
		$source = $this->contentTags($sourceNames);
		$baseline = $managed->syncedTagList();

		$merged = $this->withProtected(TagMerge::merge($baseline, $this->contentTags($ncContent), $source), $protected);

		// Converge both NC surfaces (pills read fresh — $ncContent may be body tags,
		// not pills) and n8n on the merged set, preserving n8n's reserved markers.
		$this->writeNcContentTags($fileId, $merged);
		$rows = $this->pushSourceTags($managed->workflowId, $sourceNames, $merged);
		$this->metadata->stampTags($fileId, $merged);
		return $rows;
	}

	/**
	 * The reserved-free content tag names carried in an n8n workflow row / file body
	 * (`tags: [{name}, …]`). Shared shape for the row and the on-disk sync body.
	 *
	 * @param array<string,mixed> $workflow
	 * @return list<string>
	 */
	public function contentTagsFromWorkflow(array $workflow): array {
		return $this->contentTags($this->tagNames($workflow));
	}

	/**
	 * The content-tag names on a Nextcloud file: every system tag minus the reserved
	 * namespace (which is where the ownership pills live).
	 *
	 * @return list<string>
	 */
	public function readNcContentTags(int $fileId): array {
		$objId = (string)$fileId;
		$map = $this->tagMapper->getTagIdsForObjects([$objId], self::OBJECT_TYPE);
		$tagIds = $map[$objId] ?? [];
		if ($tagIds === []) {
			return [];
		}
		$names = [];
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			$names[] = $tag->getName();
		}
		return $this->contentTags($names);
	}

	/**
	 * Reconcile the Nextcloud content pills on a file to exactly `$desired`: assign
	 * the missing ones (creating tags as needed), unassign the stale ones. Reserved
	 * ownership pills are invisible to this method, so they are never touched.
	 *
	 * @param list<string> $desired reserved-free content tag names
	 * @param list<string>|null $current the file's already-read content tags; pass to skip the re-read when the caller just read them (the reconcile paths do)
	 */
	public function writeNcContentTags(int $fileId, array $desired, ?array $current = null): void {
		$objId = (string)$fileId;
		// Defensively strip the reserved namespace from both sides: the docblock
		// promises this method never touches ownership pills, so enforce it here
		// rather than trusting every caller to pre-filter (a raw system-tag read
		// would otherwise let us create/assign/unassign control tags).
		$desired = $this->contentTags(array_values(array_unique(array_filter($desired, static fn (string $n): bool => $n !== ''))));
		$current = $this->contentTags($current ?? $this->readNcContentTags($fileId));

		$toAssign = array_values(array_diff($desired, $current));
		if ($toAssign !== []) {
			$ids = array_map(fn (string $name): string => $this->ensureTag($name)->getId(), $toAssign);
			$this->tagMapper->assignTags($objId, self::OBJECT_TYPE, $ids);
		}

		$toRemove = array_values(array_diff($current, $desired));
		foreach ($toRemove as $name) {
			try {
				$tag = $this->tagManager->getTag($name, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], self::OBJECT_TYPE, $tag->getId())) {
				$this->tagMapper->unassignTags($objId, self::OBJECT_TYPE, [$tag->getId()]);
			}
		}
	}

	/**
	 * Replace the n8n workflow's tags with `$content` while preserving whatever
	 * reserved markers (`n8n:*`) were already on it — {@see N8nClient::setWorkflowTags}
	 * is a full replace, so the reserved ones must be re-sent or they vanish. Returns
	 * n8n's canonical tag rows for the final set (each with the real tag id), so the
	 * caller can mirror the authoritative `{id,name}` objects into the file body — a
	 * user can add a bare `{"name":"foo"}` and get the id filled in from n8n.
	 *
	 * @param list<string> $currentNames the workflow's current tag names (from the row)
	 * @param list<string> $content reserved-free content tags to set
	 * @return list<array<string,mixed>> canonical tag rows (id+name) for the final set
	 */
	private function pushSourceTags(string $workflowId, array $currentNames, array $content): array {
		$reserved = array_filter($currentNames, fn (string $n): bool => $this->isReserved($n));
		$finalNames = array_values(array_unique(array_merge($content, $reserved)));

		$ids = $this->n8n->ensureTags($finalNames);
		$resp = $this->n8n->setWorkflowTags($workflowId, $ids);

		// n8n returns the workflow's new tag list ([{id,name,…}, …]); normalise to
		// rows that carry a non-empty string name, dropping anything malformed.
		$rows = [];
		foreach ($resp as $row) {
			if (is_array($row) && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Extract tag names from an n8n workflow row shape (`tags: [{id,name}, …]`).
	 *
	 * @param array<string,mixed> $workflow
	 * @return list<string>
	 */
	private function tagNames(array $workflow): array {
		$tags = $workflow['tags'] ?? [];
		if (!is_array($tags)) {
			return [];
		}
		$names = [];
		foreach ($tags as $tag) {
			$name = is_array($tag) ? ($tag['name'] ?? null) : null;
			if (is_string($name) && $name !== '') {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Drop the reserved namespace from a name list, leaving plain content tags.
	 *
	 * @param list<string> $names
	 * @return list<string>
	 */
	private function contentTags(array $names): array {
		return array_values(array_filter($names, fn (string $n): bool => !$this->isReserved($n)));
	}

	private function isReserved(string $name): bool {
		return str_starts_with($name, self::RESERVED_PREFIX);
	}

	/**
	 * Force the protected (mapping) tags into a set — they must stay on both sides so
	 * a dropped pill can never unmap the workflow.
	 *
	 * @param list<string> $set
	 * @param list<string> $protected
	 * @return list<string>
	 */
	private function withProtected(array $set, array $protected): array {
		return array_values(array_unique(array_merge($set, $this->contentTags($protected))));
	}

	/** Look up (or first-time create) a Nextcloud content tag — visible + assignable. */
	private function ensureTag(string $name): ISystemTag {
		try {
			return $this->tagManager->createTag($name, true, true);
		} catch (TagAlreadyExistsException) {
			return $this->tagManager->getTag($name, true, true);
		}
	}
}
