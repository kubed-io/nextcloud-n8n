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
use OCP\Files\File;

/**
 * Gate-keeps a managed workflow file's moves *before* they happen (saga Ch2
 * §14.2). NC fires {@see BeforeNodeRenamedEvent} for both renames and moves (a
 * move is a rename to a new path); throwing {@see AbortedEventException} aborts
 * the operation and surfaces the message to the user. The *consequences* of an
 * allowed move (archive / unarchive / re-stamp) are handled afterwards by
 * {@see MotionListener} on the post-move {@see \OCP\Files\Events\Node\NodeRenamedEvent}.
 *
 * Rules (only `*.n8n` files are constrained):
 *   - move/rename **within** the same mapping → allow (rename, subfolder).
 *   - a `link` file moving ANYWHERE → block. It is a pointer; relocating it
 *     relocates nothing, and landing it in a `sync` mapping would be a mode change
 *     made by dragging.
 *   - anything moving INTO a `link` mapping → block. That folder's contents come
 *     from its tag in n8n, so a file put there by hand is deleted by the next pull.
 *   - `sync` mapping → **different** mapping → allow, and MotionListener REBINDS it:
 *     the old mapping's tag comes off, the new one's goes on, same workflow.
 *   - `sync` mapping → unmapped location → allow; MotionListener archives it and
 *     marks it `unmapped`.
 *
 * An already-`unmapped` file lives outside every mapping, so `resolveForPath` on its
 * source returns null; only the link-mapping destination rule can still catch it.
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
		$tgtMapping = $this->mappings->resolveForPath($event->getTarget()->getPath());
		if ($srcMapping !== null && $tgtMapping !== null && $tgtMapping->id === $srcMapping->id) {
			return; // staying within the same mapping folder (rename / subfolder move)
		}

		// ── A LINK NEVER MOVES, WHEREVER IT IS GOING ──────────────────────────────
		//
		// Checked before anything about the destination, because the destination does
		// not enter into it: a link is a read-only pointer to a workflow that lives in
		// n8n, and relocating the pointer relocates nothing. This used to refuse only a
		// move OUT to an unmapped folder, which left a link free to move into another
		// mapping — where it would have had to become a `sync` file to mean anything,
		// i.e. a mode change performed by dragging. Modes are not changed by gestures in
		// Nextcloud; every other one of them is refused too (edit, delete, copy).
		if ($srcMapping !== null && $this->effectiveMode($source, $srcMapping) === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'A linked n8n workflow can’t be moved — it’s only a pointer to a workflow that lives '
				. 'in n8n. Change what the "' . $srcMapping->teamFolder . '" mapping tracks in n8n instead.'
			);
		}

		// ── AND A LINK MAPPING IS FILLED FROM n8n, WHATEVER IS ARRIVING ───────────
		//
		// The mirror of the rule above, seen from the folder, and the same one the copy
		// guard states. A link mapping's contents come from its tag; a file moved in by
		// hand is not part of that tag, so the next pull would delete it.
		if ($tgtMapping !== null && $tgtMapping->mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'“' . $tgtMapping->teamFolder . '” mirrors an n8n tag in link mode, so files can’t be '
				. 'moved into it. Tag the workflow in n8n instead.'
			);
		}

		// Everything else is allowed, and {@see MotionListener} does the n8n side on the
		// post-move event:
		//   mapping → different mapping  rebind (the tag swaps; the workflow never leaves)
		//   mapping → unmapped           move out (archive + mark unmapped)
		//   unmapped → mapping           move in (unarchive, or create if it is gone)
		//
		// The first of those used to be refused here, telling the user to move the file
		// out to an unmanaged folder and then back in — which is the same two n8n writes
		// the rebind now makes in one step, so the refusal only made them do it by hand.
		// A gesture the app can complete is not a gesture to decline.
	}

	/**
	 * The file's own mode, falling back to its mapping's. The stamp is what the file
	 * actually IS — a mapping can be re-moded after its files were written — and the
	 * mapping is the answer for a file that carries no stamp yet.
	 */
	private function effectiveMode(File $source, Mapping $srcMapping): string {
		$managed = $this->metadata->read($source->getId());
		return ($managed !== null && $managed->mode !== '') ? $managed->mode : $srcMapping->mode;
	}
}
