<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\BackgroundJob;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\TagReconcileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Async reactive tag reconcile (saga Ch5 §5.6.2, Slice A). Enqueued by
 * {@see \OCA\N8nSync\Listener\ContentTagListener} when the queue will actually be
 * drained ({@see \OCA\N8nSync\Service\WritebackStrategy}): a content-tag
 * pill was edited on a managed `sync` file and its tags are carried to n8n on the next
 * cron tick instead of inline during the request.
 *
 * The tag-side sibling of {@see PushWorkflowJob}: look the node up by file id for the
 * acting user and delegate to {@see TagReconcileService}, which owns the gating,
 * protected-tag lookup, guard, and best-effort error handling.
 *
 * Argument shape (`IJobList::add(self::class, ['fileId' => ..., 'userId' => ...])`):
 *   - `fileId` int    — the workflow file whose pills changed
 *   - `userId` string — the acting user (team-folder files mount per-user)
 */
final class ReconcileTagsJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private TagReconcileService $reconcile,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$userId = (string)($argument['userId'] ?? '');
		if ($fileId === 0 || $userId === '') {
			$this->logger->warning('ReconcileTagsJob skipped: missing fileId or userId', [
				'app' => Application::APP_ID,
				'argument' => $argument,
			]);
			return;
		}

		try {
			$node = $this->rootFolder->getUserFolder($userId)->getById($fileId)[0] ?? null;
		} catch (\Throwable $e) {
			$this->logger->warning('ReconcileTagsJob: could not resolve file ' . $fileId . ' for ' . $userId, [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if (!$node instanceof File) {
			$this->logger->info('ReconcileTagsJob: file ' . $fileId . ' no longer exists for ' . $userId, [
				'app' => Application::APP_ID,
			]);
			return;
		}

		// TagReconcileService gates (managed + sync), guards, and swallows failures.
		$this->reconcile->reconcileFile($node);
	}
}
