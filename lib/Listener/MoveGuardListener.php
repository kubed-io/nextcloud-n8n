<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\MappingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\File;

/**
 * Enforces the §14.4 invariant — a managed workflow file may not leave its
 * mapping's folder. NC fires {@see BeforeNodeRenamedEvent} for both renames and
 * moves (a move is a rename to a new path); throwing {@see AbortedEventException}
 * aborts the operation and surfaces the message to the user.
 *
 * Allowed: rename in place, and moves *within* the mapping's folder (into
 * subfolders). Blocked: moving a `*.n8n.json` out of its mapping folder, or into
 * a different mapping's folder. This deliberately avoids the exploding
 * delete/unlink/convert edge cases (see §14.4); the simple rule is "you can't
 * move it out".
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
class MoveGuardListener implements IEventListener {
	public function __construct(
		private MappingService $mappings,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		$source = $event->getSource();
		if (!$source instanceof File || !str_ends_with($source->getName(), FilenameCodec::EXT)) {
			return; // only managed workflow files are constrained
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return; // not under any mapping folder — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($event->getTarget()->getPath());
		if ($tgtMapping !== null && $tgtMapping->id === $srcMapping->id) {
			return; // staying within the same mapping folder (rename / subfolder move)
		}

		throw new AbortedEventException(
			'n8n workflows can’t be moved out of their synced folder ("'
			. $srcMapping->teamFolder . '"). Move it within that folder instead.'
		);
	}
}
