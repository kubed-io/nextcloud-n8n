<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\BackgroundJob;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\PushService;
use OCA\N8nSync\Service\SyncNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Async writeback path. Enqueued by NodeWrittenListener when
 * {@see \OCA\N8nSync\Service\WritebackStrategy} says the queue will actually be
 * drained; otherwise that listener pushes inline and this job is never created.
 *
 * Phase 1 skeleton: looks up the node by file id (passed in $argument) and
 * delegates to PushService. The job is intentionally tiny — no retry policy,
 * no batching — those land with the real transport in Phase 4.
 *
 * Argument shape (set by `IJobList::add(self::class, ['fileId' => ...])`):
 *   - `fileId`  int   — the Node id to push
 *   - `userId`  string — owner uid for context (PushService may need it)
 */
final class PushWorkflowJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PushService $pushService,
		private IRootFolder $rootFolder,
		private SyncNotifier $notifier,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$userId = (string)($argument['userId'] ?? '');
		if ($fileId === 0 || $userId === '') {
			$this->logger->warning('PushWorkflowJob skipped: missing fileId or userId', [
				'app' => Application::APP_ID,
				'argument' => $argument,
			]);
			return;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		if ($node === null) {
			$this->logger->info('PushWorkflowJob: file ' . $fileId . ' no longer exists for ' . $userId, [
				'app' => Application::APP_ID,
			]);
			return;
		}

		// Same contract as the inline path: surface n8n's complaint to the user
		// as a notification rather than failing silently in the cron log.
		try {
			if ($this->pushService->push($node)) {
				$this->notifier->cleared($fileId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('PushWorkflowJob: writeback failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			$this->notifier->failed($userId, $fileId, $node->getName(), $e->getMessage());
		}
	}
}
