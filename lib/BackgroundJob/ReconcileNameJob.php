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
 * NEITHER ACTION RENAMES A COPY ANY MORE. Under the old `.n8n.json` extension a copy was
 * born `Board.n8n (1).json` — Nextcloud counts before the LAST extension — so this job
 * also had to move the file into a spelling the app could read. With `.n8n` the counter
 * already lands where {@see FilenameCodec::format()} puts it, so the copy arrives
 * correctly named and only its name-in-the-JSON needs settling.
 *
 * The stem this job reads is the filename's `display` name — the counter INCLUDED. A
 * file called `Board (1).n8n` is a workflow called `Board (1)` when Nextcloud named
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
