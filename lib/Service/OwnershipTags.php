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
 * Owns the NC system tags this app puts on the files it manages — one per mode
 * (a coloured pill the user sees in the Files app):
 *
 *   n8n:sync      — full workflow JSON; edits push back to n8n.
 *   n8n:link      — a small pointer / deep link to a workflow that lives in n8n.
 *   n8n:unmapped  — a sync file ejected from its mapping (moved out); the JSON is
 *                   kept, the workflow archived in n8n, restorable on move-back-in.
 *
 * (saga §14: `n8n:backup` was dropped along with backup mode — it migrates to
 * `n8n:sync`. The old `n8n:reference` tag was renamed to `n8n:link`. Both are
 * stripped as legacy on re-tag. `ignored` mode carries no auto-managed pill — the
 * file keeps the user's hand-set `n8n:ignore` marker instead, which is why that tag
 * is NOT in {@see ALL} and is never stripped by {@see apply()}/{@see clear()}.)
 *
 * On the Nextcloud side these tags are **authoritative**: the app keeps exactly one
 * on each managed file, matching the file's mode metadata. (The same `n8n:sync` /
 * `n8n:link` vocabulary may also be set by hand on a *workflow in n8n* as an
 * optional override — but the app only reads those; it never writes them in n8n.)
 *
 * Why both these tags AND Files-Metadata? Tags are human-visible, survive a metadata
 * wipe, and let a user opt a hand-made file in. Metadata is the authoritative store.
 */
final class OwnershipTags {
	public const TAG_SYNC = 'n8n:sync';
	public const TAG_LINK = 'n8n:link';
	public const TAG_UNMAPPED = 'n8n:unmapped';

	/**
	 * The user-set, n8n-side override/exclude markers (saga §14.8 `reserved-tags`).
	 * Unlike the pills above, the app NEVER writes these onto its files automatically —
	 * a user adds them by hand to override a mapping default per workflow. `n8n:ignore`
	 * is the one that also has a Nextcloud-side effect (it drives `ignored` mode), so it
	 * is recognised by {@see \OCA\N8nSync\Listener\ModeTagListener}; it is deliberately
	 * kept OUT of {@see ALL} so it is never stripped as a competing assignment.
	 */
	public const TAG_IGNORE = 'n8n:ignore';

	/** All tags this app currently manages — used to scrub competing assignments. */
	public const ALL = [self::TAG_SYNC, self::TAG_LINK, self::TAG_UNMAPPED];

	/** Old tag names stripped on re-tag (n8n:reference → n8n:link; n8n:backup → n8n:sync). */
	private const LEGACY = ['n8n:reference', 'n8n:backup'];

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Pick the tag for a mode. Throws on a mode that has no file tag (`ignored` is
	 * saga Ch2 §14 Phase 2, not yet produced; an unknown mode is a programming error).
	 */
	public static function tagFor(string $mode): string {
		return match ($mode) {
			Mapping::MODE_SYNC => self::TAG_SYNC,
			Mapping::MODE_LINK => self::TAG_LINK,
			WorkflowMetadata::MODE_UNMAPPED => self::TAG_UNMAPPED,
			default => throw new \InvalidArgumentException('Unknown mode for ownership tag: ' . $mode),
		};
	}

	/**
	 * Stamp the right ownership tag on a file id and strip any of our other tags
	 * (incl. legacy names). Idempotent — safe to call on every sync run.
	 */
	public function apply(int $fileId, string $mode): void {
		$desiredName = self::tagFor($mode);
		$desiredTag = $this->ensureTag($desiredName);
		$objId = (string)$fileId;

		$this->tagMapper->assignTags($objId, 'files', [$desiredTag->getId()]);

		foreach (array_merge(self::ALL, self::LEGACY) as $other) {
			if ($other === $desiredName) {
				continue;
			}
			try {
				$otherTag = $this->tagManager->getTag($other, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], 'files', $otherTag->getId())) {
				$this->tagMapper->unassignTags($objId, 'files', [$otherTag->getId()]);
			}
		}
	}

	/**
	 * Strip every ownership tag this app manages (current + legacy) from a file.
	 * Used when a COPY lands (saga Ch2 §14 `copy.feature`): the copy must start with
	 * no n8n identity, so it carries none of our pills. Idempotent.
	 */
	public function clear(int $fileId): void {
		$objId = (string)$fileId;
		foreach (array_merge(self::ALL, self::LEGACY) as $name) {
			try {
				$tag = $this->tagManager->getTag($name, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], 'files', $tag->getId())) {
				$this->tagMapper->unassignTags($objId, 'files', [$tag->getId()]);
			}
		}
	}

	/** True if the file carries any of our ownership tags (cheap second signal). */
	public function isOwned(int $fileId): bool {
		$objId = (string)$fileId;
		foreach (self::ALL as $name) {
			try {
				$tag = $this->tagManager->getTag($name, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], 'files', $tag->getId())) {
				return true;
			}
		}
		return false;
	}

	/** Look up (or first-time create) the system tag. */
	private function ensureTag(string $name): ISystemTag {
		try {
			return $this->tagManager->createTag($name, true, true);
		} catch (TagAlreadyExistsException) {
			return $this->tagManager->getTag($name, true, true);
		}
	}
}
