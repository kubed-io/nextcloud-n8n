<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\BackgroundJob;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\PushService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Deferred half of the three-way name sync (§17.6). The reconciliation has to
 * happen **out of the triggering request** because the file is locked during a
 * rename — writing its content inside the NodeRenamedEvent handler throws
 * `OCP\Lock\LockedException` ("existing lock on file"). So {@see \OCA\N8nSync\Listener\NameSyncListener}
 * just enqueues this job; by the time it runs the lock is released.
 *
 * Argument: `{ fileId:int, userId:string, action:'name_from_filename'|'filename_from_name' }`.
 *
 * - `name_from_filename` (a rename happened): write the filename stem into the
 *   JSON `name` (guarded so the writeback doesn't echo), then push to n8n
 *   directly so the workflow name updates in one tick.
 * - `filename_from_name` (the JSON `name` was edited + saved): rename the file
 *   to match (the original save already pushed the name to n8n via writeback).
 *
 * Both actions first put the file into OUR spelling of a collision — see
 * {@see canonicaliseSpelling()}. That has to happen here rather than at the gesture for
 * the same reason the rest of this job does: a copy's own hook holds locks on the file
 * it made.
 *
 * The stem this job reads is the filename's `display` name — the counter INCLUDED. A
 * file called `Board (1).n8n.json` is a workflow called `Board (1)` when Nextcloud named
 * it, and taking the counter-stripped `name` instead is what let a copy reach n8n
 * wearing the original's name.
 *
 * Idempotent: re-checks the gate + current values and no-ops if already in sync,
 * so a stale/duplicate enqueue is harmless.
 */
final class ReconcileNameJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private WorkflowMetadata $metadata,
		private PushService $pushService,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$uid = (string)($argument['userId'] ?? '');
		$action = (string)($argument['action'] ?? '');
		if ($fileId === 0 || $uid === '') {
			return;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->getById($fileId)[0] ?? null;
			if (!FilenameCodec::isWorkflowFile($node)) {
				return;
			}
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged()) {
				return;
			}
			if (!$managed->isSync()) {
				return;
			}
			$id = $managed->workflowId;

			$this->canonicaliseSpelling($node);

			$stem = FilenameCodec::displayName($node->getName());
			$wf = json_decode($node->getContent(), false);
			if (!$wf instanceof \stdClass) {
				return;
			}
			$jsonName = isset($wf->name) && is_string($wf->name) ? trim($wf->name) : '';

			if ($action === 'name_from_filename') {
				if ($stem === '' || $jsonName === $stem) {
					return; // already in sync
				}
				$wf->name = $stem;
				$encoded = json_encode($wf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if ($encoded === false) {
					return;
				}
				// Write the JSON name guarded (so writeback/name-sync don't echo),
				// then push to n8n ourselves — one tick, one push.
				$this->guard->run(function () use ($node, $encoded): void {
					$node->putContent($encoded);
				});
				$this->pushService->push($node);
			} elseif ($action === 'filename_from_name') {
				if ($jsonName === '' || $jsonName === $stem) {
					return; // already in sync (n8n was pushed by the save's writeback)
				}
				$this->renameTo($node, $jsonName, $id);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync name reconcile failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'action' => $action,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Put the file into OUR spelling of a collision counter, if it is wearing
	 * Nextcloud's.
	 *
	 * Nextcloud names a colliding copy `Board.n8n (1).json`, counting before the last
	 * extension because to Nextcloud our file is a `.json` called `Board.n8n`. Ours is
	 * `Board (1).n8n.json`, with the counter on the workflow's name.
	 * {@see \OCA\N8nSync\DAV\CopyNamePlugin} already sees to that for copies made over
	 * WebDAV, which is all of them from the Files app; this is the backstop for the rest.
	 *
	 * **EXACTLY THE CANONICAL NAME, OR NOTHING.** This must not go through
	 * {@see renameTo()}, which steps the counter until it finds a free name: with
	 * `Board (1).n8n.json` already taken, a copy landing at `Board.n8n (1).json` would be
	 * renamed to `Board (1) (1).n8n.json` — a name nobody chose, in place of one that was
	 * working. The plugin's rule is that the client's name wins when ours is occupied,
	 * and the backstop has to agree with it.
	 */
	private function canonicaliseSpelling(File $node): void {
		$current = $node->getName();
		if (!FilenameCodec::isNextcloudSpelling($current)) {
			return;
		}
		$wanted = FilenameCodec::canonicalise($current);
		$parent = $node->getParent();
		if ($parent->nodeExists($wanted)) {
			return; // ours is taken; the client's name is the one that works
		}
		$node->move($parent->getPath() . '/' . $wanted);
	}

	/**
	 * Rename $node so its stem reads $display, stepping the collision counter until the
	 * name is free. No-ops when the file already has the name it wants — including when
	 * the free name IS the current one, which is how a file that legitimately carries a
	 * counter keeps it instead of fighting the file that took the plain name.
	 *
	 * The 1000 bound is a runaway guard, not a policy: a thousand files sharing one
	 * workflow name is a broken mapping, and looping forever would be worse than leaving
	 * the name alone.
	 */
	private function renameTo(File $node, string $display, string $id): void {
		if ($display === '') {
			return;
		}
		$parent = $node->getParent();
		$current = $node->getName();
		$collision = 0;
		while (true) {
			$candidate = FilenameCodec::format($display, $id, false, $collision);
			if ($candidate === $current) {
				return; // already the name it wants
			}
			if (!$parent->nodeExists($candidate)) {
				break;
			}
			if (++$collision > 1000) {
				return;
			}
		}
		$node->move($parent->getPath() . '/' . $candidate);
	}
}
