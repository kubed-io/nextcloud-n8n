<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\BackgroundJob;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\Mapping;
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
			if (!$node instanceof File || !str_ends_with($node->getName(), FilenameCodec::EXT)) {
				return;
			}
			$meta = $this->metadata->read($node->getId());
			$id = is_array($meta) ? ($meta[WorkflowMetadata::KEY_ID] ?? null) : null;
			if (!is_string($id) || $id === '') {
				return;
			}
			if (($meta[WorkflowMetadata::KEY_MODE] ?? '') !== Mapping::MODE_SYNC) {
				return;
			}

			$stem = FilenameCodec::parse($node->getName())['name'] ?? '';
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
				$parent = $node->getParent();
				$current = $node->getName();
				$collision = 0;
				while (true) {
					$candidate = FilenameCodec::format($jsonName, $id, false, $collision);
					if ($candidate === $current) {
						return;
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
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync name reconcile failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'action' => $action,
				'exception' => $e,
			]);
		}
	}
}
