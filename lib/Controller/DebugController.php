<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Controller;

use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Settings\AdminTest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only debug endpoints — thin wrappers around N8nClient for smoke
 * testing the live n8n connection.
 *
 * Same admin gate as the Test connection button
 * (#[AuthorizedAdminSetting(settings: AdminTest::class)]) **and** Nextcloud's
 * default CSRF protection (no #[NoCSRFRequired]) — so these can't be reached
 * from a third-party site that tricked an admin into loading it.
 *
 * Two ways to call them while logged in as admin:
 *  1. Browser console (same trick the Test button uses internally):
 *       fetch(OC.generateUrl('/apps/n8n_sync/debug/workflows?limit=5'),
 *             {headers:{requesttoken:OC.requestToken}})
 *         .then(r => r.json()).then(console.log);
 *  2. The `occ n8n_sync:list-workflows` command (Service\Command\ListWorkflows)
 *     — server-side only, bypasses the browser surface entirely.
 *
 * Routes (see appinfo/routes.php):
 *   GET /apps/n8n_sync/debug/workflows         → listWorkflows(limit=5)
 *   GET /apps/n8n_sync/debug/workflows/{id}    → getWorkflow($id)
 */
final class DebugController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private N8nClient $client,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function listWorkflows(): JSONResponse {
		$limit = (int)$this->request->getParam('limit', 5);
		if ($limit < 1) {
			$limit = 1;
		}
		if ($limit > 50) {
			$limit = 50;
		}
		$cursor = (string)$this->request->getParam('cursor', '');
		try {
			$data = $this->client->listWorkflows($limit, $cursor !== '' ? $cursor : null);
			return new JSONResponse($data);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['status' => 'error', 'message' => $e->getMessage()],
				Http::STATUS_BAD_GATEWAY,
			);
		}
	}

	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function getWorkflow(string $id): JSONResponse {
		try {
			$data = $this->client->getWorkflow($id);
			return new JSONResponse($data);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['status' => 'error', 'message' => $e->getMessage()],
				Http::STATUS_BAD_GATEWAY,
			);
		}
	}
}
