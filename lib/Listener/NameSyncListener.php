<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\BackgroundJob\ReconcileNameJob;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IUserSession;

/**
 * Keeps three things equal for a two-way managed workflow file: the **filename
 * stem**, the JSON **`name`** key, and (via the writeback / a direct push) the
 * **n8n workflow name**. Renaming the file, or editing the `name` inside the
 * JSON and saving, both end up reflected everywhere.
 *
 * This listener only **decides + enqueues** — the actual file write/rename runs
 * in {@see ReconcileNameJob}. That deferral is required: during a rename the
 * file is locked, so a synchronous `putContent` here throws `LockedException`.
 * Reads (to compare names) use a shared lock and are safe even mid-rename.
 *
 * Authority follows what the user changed (so the two paths never fight, and the
 * follow-up event the job triggers finds things in sync and no-ops):
 *   - {@see NodeRenamedEvent}  → filename changed → `name_from_filename`.
 *   - {@see NodeWrittenEvent}  → content changed  → `filename_from_name`.
 *
 * Gate mirrors the writeback listener (metadata-only, survives moves): `n8n_id`
 * + `sync` + `two-way`. Backup/reference stay n8n-driven (pull renames them).
 * Bails under {@see SyncGuard::active()} so pull/create writes don't reshuffle.
 *
 * @implements IEventListener<NodeWrittenEvent|NodeRenamedEvent>
 */
final class NameSyncListener implements IEventListener {
	public function __construct(
		private WorkflowMetadata $metadata,
		private SyncGuard $guard,
		private IJobList $jobList,
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($this->guard->active()) {
			return;
		}
		$node = $this->resolveNode($event);
		if (!$node instanceof File || !str_ends_with($node->getName(), FilenameCodec::EXT)) {
			return;
		}

		$meta = $this->metadata->read($node->getId());
		if ($meta === null) {
			return;
		}
		$id = $meta[WorkflowMetadata::KEY_ID] ?? null;
		if (!is_string($id) || $id === '') {
			return; // not managed yet — create-on-land owns the first write
		}
		if (($meta[WorkflowMetadata::KEY_MODE] ?? '') !== Mapping::MODE_SYNC
			|| ($meta[WorkflowMetadata::KEY_WRITEBACK] ?? '') !== Mapping::WRITEBACK_TWO_WAY) {
			return; // reference / backup are n8n-driven
		}

		$stem = FilenameCodec::parse($node->getName())['name'] ?? '';
		if ($stem === '') {
			return;
		}

		// Read (shared lock — safe even during a rename) to compare names and
		// only enqueue on a real mismatch.
		try {
			$wf = json_decode($node->getContent(), false);
		} catch (\Throwable) {
			return;
		}
		$jsonName = ($wf instanceof \stdClass && isset($wf->name) && is_string($wf->name)) ? trim($wf->name) : '';

		// The acting user resolves the file in the job (team-folder files are
		// mounted per-user) — same approach as the writeback's PushWorkflowJob.
		$uid = $this->userSession->getUser()?->getUID() ?? $node->getOwner()?->getUID() ?? '';
		if ($uid === '') {
			return;
		}

		if ($event instanceof NodeRenamedEvent) {
			if ($jsonName !== $stem) {
				$this->enqueue($node->getId(), $uid, 'name_from_filename');
			}
		} elseif ($jsonName !== '' && $jsonName !== $stem) {
			$this->enqueue($node->getId(), $uid, 'filename_from_name');
		}
	}

	private function enqueue(int $fileId, string $uid, string $action): void {
		$this->jobList->add(ReconcileNameJob::class, [
			'fileId' => $fileId,
			'userId' => $uid,
			'action' => $action,
		]);
	}

	private function resolveNode(Event $event): ?Node {
		if ($event instanceof NodeWrittenEvent) {
			return $event->getNode();
		}
		if ($event instanceof NodeRenamedEvent) {
			return $event->getTarget();
		}
		return null;
	}
}
