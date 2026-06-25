<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;

/**
 * Gate-keeps a managed workflow file's moves *before* they happen (saga Ch2
 * §14.2). NC fires {@see BeforeNodeRenamedEvent} for both renames and moves (a
 * move is a rename to a new path); throwing {@see AbortedEventException} aborts
 * the operation and surfaces the message to the user. The *consequences* of an
 * allowed move (archive / unarchive / re-stamp) are handled afterwards by
 * {@see MotionListener} on the post-move {@see \OCP\Files\Events\Node\NodeRenamedEvent}.
 *
 * Rules (only `*.n8n.json` files under a mapping are constrained):
 *   - move/rename **within** the same mapping → allow (rename, subfolder).
 *   - move into a **different** mapping → block (decision-cases §14.2 a/b are not
 *     yet designed — re-tag vs eject+reattach — so disallow the ambiguous case).
 *   - move **out** to an unmapped location:
 *       · `sync`  → **allow** — MotionListener archives it and marks it `unmapped`.
 *       · `link`  → **block** — a link has no NC-side JSON to keep; moving it out
 *                   would orphan the pointer.
 *
 * An already-`unmapped` file lives outside every mapping, so `resolveForPath`
 * on its source returns null and it is never constrained here (pure relocation).
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveGuardListener implements IEventListener {
	public function __construct(
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		$source = $event->getSource();
		if (!FilenameCodec::isWorkflowFile($source)) {
			return; // only managed workflow files are constrained
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return; // not under any mapping folder (e.g. unmapped) — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($event->getTarget()->getPath());
		if ($tgtMapping !== null && $tgtMapping->id === $srcMapping->id) {
			return; // staying within the same mapping folder (rename / subfolder move)
		}

		if ($tgtMapping !== null) {
			// mapping → different mapping: §14.2 decision-cases a/b, not yet designed.
			throw new AbortedEventException(
				'n8n workflows can’t be moved directly between synced folders yet. '
				. 'Move it out to an unmanaged folder first, then into "'
				. $tgtMapping->teamFolder . '".'
			);
		}

		// Leaving its mapping for an unmapped location. Sync is allowed (it becomes
		// unmapped + archived); link is refused (no JSON to keep on the NC side).
		$meta = $this->metadata->read($source->getId());
		$mode = $meta[WorkflowMetadata::KEY_MODE] ?? $srcMapping->mode;
		if ($mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'A linked n8n workflow can’t be moved out of its synced folder ("'
				. $srcMapping->teamFolder . '") — it’s only a pointer. Move it within that folder instead.'
			);
		}
		// sync → allow; MotionListener archives + marks it unmapped on the post-move event.
	}
}
