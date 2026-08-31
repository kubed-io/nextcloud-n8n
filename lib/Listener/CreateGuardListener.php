<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * The authoring guard: a link mapping is filled from its tag in n8n, so nothing may be
 * written into one from Nextcloud.
 *
 * The third of the family — {@see MoveGuardListener} refuses a move, {@see CopyGuardListener}
 * a copy, and this one a write. Same shape, same reason: refuse BEFORE the gesture rather
 * than tidy up after it.
 *
 * ## THE DOOR THAT WAS LEFT OPEN, AND NOT BY ANYONE'S DECISION
 *
 * `workflows/create.feature` ran `New file in a mapped folder becomes a real workflow` as
 * an Outline over `Demo`, `Pointers` and `Shared` — and `Pointers` is a **link** mapping.
 * So the spec asserted, and the suite proved green, that authoring a file into a link
 * folder mints a workflow whose mapping tag does not select it, leaving the next pull with
 * an opinion about a file the user had just made.
 *
 * The app already refused to COPY into a link mapping and to MOVE into one, and refuses
 * DAV writes to the link files inside one. Authoring was never argued for — it was simply
 * never asked about. The spec was corrected first and left `@unbuilt` on purpose, so the
 * app and the spec disagreed in writing rather than the spec blessing whatever the code
 * happened to do. This is the code half of that PR.
 *
 * ## WHY THIS EXISTS ALONGSIDE THE SABRE PLUGIN
 *
 * {@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin} refuses a write to a link FILE over WebDAV
 * and answers 403 with a reason. It cannot be the whole rule here, for two reasons:
 *
 *   - It classifies from the file's OWN metadata (`isLinkFile()`), and a brand-new file
 *     has none. A file being authored into a link folder is not yet a link, so the plugin
 *     correctly waves it through — the constraint belongs to the FOLDER, not the file.
 *   - It only sees WebDAV. An `occ` command, another app, or anything using the Files API
 *     never touches Sabre.
 *
 * ## THE SYNC GUARD IS LOAD-BEARING HERE, NOT DEFENSIVE
 *
 * The PULL writes mirrors into link folders — that is the entire point of a link mapping —
 * and those writes fire this event too. Refusing them would not merely be over-strict, it
 * would break link mappings completely: no mirror could ever be written. The pull runs
 * inside {@see SyncGuard}, so the guard check below is what separates "n8n filled this
 * folder" from "a person tried to".
 *
 * ## EVERY WRITE, NOT ONLY THE FIRST
 *
 * The event does not distinguish creating a file from editing one, and it does not need
 * to: a link's body is n8n's either way, so both answers are the same refusal. Files that
 * are not workflows are waved through — a link mapping's one concession is that other file
 * types may live alongside the mirrored workflows.
 *
 * @implements IEventListener<BeforeNodeWrittenEvent>
 */
final class CreateGuardListener implements IEventListener {
	public function __construct(
		private MappingService $mappings,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeWrittenEvent) {
			return;
		}
		// The pull's own writes — see the class docblock. This is not defence in depth;
		// without it a link mapping could never be filled.
		if ($this->guard->active()) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isWorkflowName($node->getName())) {
			return; // a spreadsheet in a link folder is entirely welcome
		}

		try {
			$mapping = $this->mappings->resolveForPath($node->getPath());
		} catch (\Throwable $e) {
			// CANNOT CLASSIFY → NEVER BLOCK. A guard that refuses writes whenever the
			// mapping lookup is unhappy would take the whole instance's workflow files
			// down with it.
			$this->logger->debug('n8n_sync: could not classify a written workflow file; allowing', [
				'app' => Application::APP_ID,
				'path' => $node->getPath(),
				'exception' => $e,
			]);
			return;
		}
		if ($mapping === null || $mapping->mode !== Mapping::MODE_LINK) {
			return;
		}

		$this->logger->warning('n8n_sync: refused a write to a workflow file in a link-mapped folder', [
			'app' => Application::APP_ID,
			'path' => $node->getPath(),
			'mapping' => $mapping->id,
		]);

		throw new AbortedEventException(
			'“' . $node->getName() . '” cannot be written here: “' . $mapping->teamFolder . '” mirrors an n8n '
			. 'tag in link mode, so its workflows are n8n\'s to create. Make the workflow in n8n and give it '
			. 'the mapping\'s tag and it will appear here, or switch the folder mapping to sync mode to author '
			. 'workflows from Nextcloud.',
		);
	}
}
