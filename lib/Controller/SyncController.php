<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Controller;

use OCA\N8nSync\Service\SyncService;
use OCA\N8nSync\Service\SyncStatusService;
use OCA\N8nSync\Settings\SyncSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Manual bulk-sync endpoints.
 *
 * Pull runs through {@see SyncService::pullAll}: each mapping's tag is
 * queried against n8n, files are reconciled into the configured owner user's
 * NC home, and Files-Metadata stamps follow.
 *
 * Push runs through {@see SyncService::pushAll}: every synced mapping's
 * files are written back wholesale — the admin declaring Nextcloud the
 * source of truth and n8n made to match.
 *
 * Routes:
 *   POST /apps/n8n_sync/sync/pull    → n8n → NC (bulk populate)
 *   POST /apps/n8n_sync/sync/push    → NC → n8n (bulk export)
 *   GET  /apps/n8n_sync/sync/status  → both records
 */
final class SyncController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $sync,
		private SyncStatusService $status,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function status(): JSONResponse {
		return new JSONResponse($this->status->all());
	}

	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function pull(): JSONResponse {
		return $this->enqueue(SyncStatusService::DIR_PULL);
	}

	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function push(): JSONResponse {
		return $this->enqueue(SyncStatusService::DIR_PUSH);
	}

	/**
	 * Bulk manual sync is always asynchronous (§14.2): enqueue a background job
	 * and return immediately with 'queued' so navigating away can't kill it. The
	 * UI polls /sync/status to watch queued → running → ok|error.
	 */
	private function enqueue(string $direction): JSONResponse {
		try {
			$this->sync->dispatch($direction, null, true);
		} catch (\Throwable $e) {
			$this->logger->error('n8n_sync enqueue failed', ['exception' => $e]);
			return new JSONResponse([
				'status' => 'error',
				'message' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse([
			'status' => 'queued',
			'direction' => $direction,
			'state' => $this->status->get($direction),
		], Http::STATUS_OK);
	}
}
