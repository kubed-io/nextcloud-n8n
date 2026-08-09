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
 * EVERY TAG IS CONTENT NOW, and that is the whole simplification. This class used
 * to carve two namespaces out before the merge could run: the app's own `n8n:*`
 * mode pills, and the mapping tag it force-kept on both sides. The pills are gone
 * (nothing writes them, and a repair step swept the definitions), and dropping the
 * mapping tag is now the sanctioned way to leave a mapping rather than something to
 * defend against. So {@see TagMerge} sees the sets as they are, and a tag is just a
 * string on both sides.
 */
final class TagSyncService {
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
	 */
	public function reconcilePull(int $fileId, array $workflow, ManagedFile $managed): void {
		$source = $this->tagNames($workflow);
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
		$desired = array_values(array_unique(array_merge($source, $localAdds)));

		// Reuse the `$nc` we just read — nothing has touched the pills since.
		$this->writeNcContentTags($fileId, $desired, $nc);
		// Baseline = the source's content tags
		// tags — but NOT the NC-local additions. A local add is not agreed until a push
		// lands it in n8n, so it stays out of the baseline and the next push reads it as
		// `nc − baseline` and propagates it. (Protected tags are in the baseline so a
		// later genuine remove of one is still measured against a set that contained it.)
		$this->metadata->stampTags($fileId, $source);
	}

	/**
	 * Push: reconcile the Nextcloud **pills** onto the n8n workflow and re-stamp the
	 * baseline. Fetches the live workflow so the deterministic {@see TagMerge} sees
	 * n8n's current tags and any reserved markers are preserved. Sync files only.
	 * Returns n8n's canonical tag rows after the set, so callers can write the real
	 * `{id,name}` objects back into the file body.
	 *
	 * @return list<array<string,mixed>> n8n's canonical tag rows (id+name+…) after the set
	 */
	public function reconcilePush(int $fileId, ManagedFile $managed): array {
		return $this->reconcilePushWith($fileId, $managed, $this->readNcContentTags($fileId));
	}

	/**
	 * Push from the file **body**: same three-way reconcile as {@see reconcilePush},
	 * but the Nextcloud-side content set is the file's own `tags` array rather than
	 * the pills (Slice B — an edit to the JSON `tags` is an authoritative NC-side
	 * statement). The pills are then converged to the merged result too, so the two
	 * NC surfaces never disagree. Returns n8n's canonical tag rows after the set.
	 *
	 * @param list<string> $bodyContentTags reserved-free content tags read from the body
	 * @return list<array<string,mixed>> n8n's canonical tag rows after the set
	 */
	public function reconcilePushFromBody(int $fileId, ManagedFile $managed, array $bodyContentTags): array {
		return $this->reconcilePushWith($fileId, $managed, $bodyContentTags);
	}

	/**
	 * Shared push reconcile: merge `$ncContent` (pills or body tags) with n8n against
	 * the baseline, converge the pills and the n8n workflow on the result, re-stamp
	 * the baseline. Preserves n8n's reserved markers ({@see pushSourceTags}). Returns
	 * n8n's canonical tag rows so the caller can mirror the real `{id,name}` objects
	 * into the file body.
	 *
	 * @param list<string> $ncContent the NC-side content set to treat as truth
	 * @return list<array<string,mixed>> n8n's canonical tag rows after the set
	 */
	private function reconcilePushWith(int $fileId, ManagedFile $managed, array $ncContent): array {
		$workflow = $this->n8n->getWorkflow($managed->workflowId);
		$source = $this->tagNames($workflow);
		$baseline = $managed->syncedTagList();

		$merged = TagMerge::merge($baseline, $ncContent, $source);

		// Converge both NC surfaces (pills read fresh — $ncContent may be body tags,
		// not pills) and n8n on the merged set, preserving n8n's reserved markers.
		$this->writeNcContentTags($fileId, $merged);
		$rows = $this->pushSourceTags($managed->workflowId, $merged);
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
		return $this->tagNames($workflow);
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
		return $names;
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
		$desired = array_values(array_unique(array_filter($desired, static fn (string $n): bool => $n !== '')));
		$current = $current ?? $this->readNcContentTags($fileId);

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
	 * Replace the n8n workflow's tags with `$content`. {@see N8nClient::setWorkflowTags}
	 * is a full replace, and now that nothing is carved out of the set, $content IS
	 * what the workflow should carry — there is no longer a class of tag to re-send so
	 * the replace does not drop it. Returns n8n's canonical tag rows for the final set
	 * (each with the real tag id), so the caller can mirror the authoritative
	 * `{id,name}` objects into the file body — a user can add a bare `{"name":"foo"}`
	 * and get the id filled in from n8n.
	 *
	 * @param list<string> $content the tags to set
	 * @return list<array<string,mixed>> canonical tag rows (id+name) for the final set
	 */
	private function pushSourceTags(string $workflowId, array $content): array {
		$finalNames = array_values(array_unique($content));

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
	 * Remove ONE tag from a workflow in n8n, leaving every other tag on it.
	 *
	 * The unbind's only write ({@see TagReconcileService::unbindIfMappingTagDropped}).
	 * Reserved `n8n:*` markers are preserved along with the rest, because
	 * {@see N8nClient::setWorkflowTags} is a full replace and this is a subtraction of
	 * exactly one name, not a re-statement of what the workflow should carry.
	 */
	public function dropSourceTag(string $workflowId, string $tag): void {
		$workflow = $this->n8n->getWorkflow($workflowId);
		$keep = array_values(array_filter(
			$this->tagNames($workflow),
			static fn (string $n): bool => $n !== $tag,
		));
		$this->pushSourceTags($workflowId, $keep);
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
