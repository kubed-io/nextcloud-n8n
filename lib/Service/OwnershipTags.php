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
 * Owns the NC system tags this app puts on the files it manages — one per
 * effective state (a coloured pill the user sees in Drive):
 *
 *   n8n:sync    — full workflow JSON; two-way (NC edits push back to n8n).
 *   n8n:backup  — full workflow JSON; read-only backup (edits only from n8n).
 *   n8n:link    — a small pointer / deep link to a workflow that lives in n8n.
 *
 * Why both these tags AND Files-Metadata? Tags are human-visible + survive a
 * metadata wipe, and let a user opt a hand-made file in. Metadata is the
 * authoritative machine store.
 *
 * NOTE on "link"/"reference" and "backup"/"read-only": these are synonyms. The
 * user-facing tags + UI say "link"/"backup"; the metadata value + Mapping
 * constants say "reference"/"readonly". Only the *metadata value* `link` is
 * forbidden (it is `is_callable()` and crashes PROPFIND — see WorkflowMetadata);
 * tag names have no such constraint.
 */
class OwnershipTags {
	public const TAG_SYNC = 'n8n:sync';
	public const TAG_BACKUP = 'n8n:backup';
	public const TAG_LINK = 'n8n:link';

	/** All tags this app manages — used to scrub competing assignments. */
	public const ALL = [self::TAG_SYNC, self::TAG_BACKUP, self::TAG_LINK];

	/** Old tag names stripped on re-tag (n8n:reference -> n8n:link). */
	private const LEGACY = ['n8n:reference'];

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Pick the tag for an effective state. Throws on an unknown combination so a
	 * caller bug surfaces rather than mis-tagging.
	 */
	public static function tagFor(string $mode, ?string $writeback): string {
		if ($mode === Mapping::MODE_REFERENCE) {
			return self::TAG_LINK;
		}
		if ($mode === Mapping::MODE_SYNC) {
			return $writeback === Mapping::WRITEBACK_READONLY ? self::TAG_BACKUP : self::TAG_SYNC;
		}
		throw new \InvalidArgumentException('Unknown mode for ownership tag: ' . $mode);
	}

	/**
	 * Stamp the right ownership tag on a file id and strip any of our other tags
	 * (incl. legacy names). Idempotent — safe to call on every sync run.
	 */
	public function apply(int $fileId, string $mode, ?string $writeback): void {
		$desiredName = self::tagFor($mode, $writeback);
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
