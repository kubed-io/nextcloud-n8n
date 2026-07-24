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
use Psr\Log\LoggerInterface;

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
 *  - **Push** ({@see reconcilePush}) runs for synced files on writeback. Nextcloud is
 *    authoritative; a genuine both-sides-drifted conflict is resolved in NC's favour.
 *
 * Two namespaces are handled here so {@see TagMerge} only ever sees plain content:
 *
 *  - **Reserved** — anything under {@see RESERVED_PREFIX} (`n8n:sync`, `n8n:link`,
 *    `n8n:ignore`, …). These are the app's own pills and the user's n8n-side
 *    override markers; they are NEVER mirrored as content and NEVER pushed to n8n.
 *    Reserved tags already on the n8n workflow are preserved verbatim on push.
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
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Pull: reconcile n8n's tags onto the Nextcloud file and re-stamp the baseline.
	 * n8n wins, but NC-local additions survive (they push out next time). Safe to
	 * call for both sync and link files.
	 *
	 * @param array<string,mixed> $workflow the n8n workflow row (carries `tags`)
	 * @param list<string> $protected mapping tags that must never be dropped
	 */
	public function reconcilePull(int $fileId, array $workflow, ManagedFile $managed, array $protected): void {
		$source = $this->contentTags($this->tagNames($workflow));
		$nc = $this->readNcContentTags($fileId);
		$baseline = $managed->syncedTagList();

		// Pull semantic: source, plus any tag NC gained since the last sync.
		$localAdds = array_values(array_diff($nc, $baseline));
		$desired = $this->withProtected(array_merge($source, $localAdds), $protected);

		$this->writeNcContentTags($fileId, $desired);
		// The agreed set is only what the source actually reflects; NC-local adds are
		// not agreed until a push lands them, so they stay OUT of the baseline.
		$this->metadata->stampTags($fileId, $this->withProtected($source, $protected));
	}

	/**
	 * Push: reconcile the Nextcloud pills onto the n8n workflow and re-stamp the
	 * baseline. NC wins conflicts. Fetches the live workflow so the merge sees n8n's
	 * current tags and any reserved markers are preserved. Sync files only.
	 *
	 * @param list<string> $protected mapping tags that must never be dropped
	 */
	public function reconcilePush(int $fileId, ManagedFile $managed, array $protected): void {
		$workflow = $this->n8n->getWorkflow($managed->workflowId);
		$sourceNames = $this->tagNames($workflow);
		$source = $this->contentTags($sourceNames);
		$nc = $this->readNcContentTags($fileId);
		$baseline = $managed->syncedTagList();

		$merged = TagMerge::merge($baseline, $nc, $source, sourceWins: false);
		$merged = $this->withProtected($merged, $protected);

		// Converge both sides on the merged set, preserving n8n's reserved markers.
		$this->writeNcContentTags($fileId, $merged);
		$this->pushSourceTags($managed->workflowId, $sourceNames, $merged);
		$this->metadata->stampTags($fileId, $merged);
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
	 */
	public function writeNcContentTags(int $fileId, array $desired): void {
		$objId = (string)$fileId;
		$desired = array_values(array_unique(array_filter($desired, static fn (string $n): bool => $n !== '')));
		$current = $this->readNcContentTags($fileId);

		$toAssign = array_diff($desired, $current);
		if ($toAssign !== []) {
			$ids = array_map(fn (string $name): string => $this->ensureTag($name)->getId(), $toAssign);
			$this->tagMapper->assignTags($objId, self::OBJECT_TYPE, $ids);
		}

		$toRemove = array_diff($current, $desired);
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
	 * is a full replace, so the reserved ones must be re-sent or they vanish.
	 *
	 * @param list<string> $currentNames the workflow's current tag names (from the row)
	 * @param list<string> $content reserved-free content tags to set
	 */
	private function pushSourceTags(string $workflowId, array $currentNames, array $content): void {
		$reserved = array_filter($currentNames, fn (string $n): bool => $this->isReserved($n));
		$finalNames = array_values(array_unique(array_merge($content, $reserved)));

		$ids = [];
		foreach ($finalNames as $name) {
			$ids[] = $this->n8n->ensureTag($name);
		}
		$this->n8n->setWorkflowTags($workflowId, $ids);
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
