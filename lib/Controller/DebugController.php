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
 *  2. The `occ n8n_sync:list-workflows` command ({@see \OCA\N8nSync\Command\ListWorkflows})
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
		// Same 1..50 clamp the occ command uses — a debug surface never pages.
		$limit = max(1, min(50, (int)$this->request->getParam('limit', 5)));
		$cursor = (string)$this->request->getParam('cursor', '');
		try {
			return new JSONResponse($this->client->listWorkflows($limit, $cursor !== '' ? $cursor : null));
		} catch (\Throwable $e) {
			return $this->badGateway($e);
		}
	}

	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function getWorkflow(string $id): JSONResponse {
		try {
			return new JSONResponse($this->client->getWorkflow($id));
		} catch (\Throwable $e) {
			return $this->badGateway($e);
		}
	}

	/** Any client failure surfaces as 502 — the upstream n8n is the broken half. */
	private function badGateway(\Throwable $e): JSONResponse {
		return new JSONResponse(
			['status' => 'error', 'message' => $e->getMessage()],
			Http::STATUS_BAD_GATEWAY,
		);
	}
}
