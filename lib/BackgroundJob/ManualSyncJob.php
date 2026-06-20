<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\BackgroundJob;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\SyncService;
use OCA\N8nSync\Service\SyncStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Async manual sync (§14). Enqueued by SyncService::dispatch() when `async` is
 * true so a full sync survives the admin navigating away. Records run state in
 * SyncStatusService (queued → running → ok|error) for the UI to poll.
 *
 * Argument shape (IJobList::add): `{ direction: 'pull'|'push', mappingId?: string }`.
 */
final class ManualSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private SyncService $sync,
		private SyncStatusService $status,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$direction = (string)($argument['direction'] ?? SyncStatusService::DIR_PULL);
		$mappingId = $argument['mappingId'] ?? null;
		$mappingId = is_string($mappingId) && $mappingId !== '' ? $mappingId : null;

		$this->status->markStarted($direction);
		try {
			$result = $this->sync->runInline($direction, $mappingId);
			$this->status->markFinished($direction, $result);
		} catch (\Throwable $e) {
			$this->logger->error('n8n_sync manual sync job failed', [
				'app' => Application::APP_ID,
				'direction' => $direction,
				'exception' => $e,
			]);
			$this->status->markFinished($direction, [
				'status' => 'error',
				'message' => $e->getMessage(),
			]);
		}
	}
}
