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
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin "Test connection" endpoint. The actual HTTP work lives in N8nClient
 * so the same code path exercised here is the same one Phase 3/4 uses for
 * the bulk reconciler and the writeback push — there's only ever one place
 * we read+decrypt the API key and hit the n8n REST API.
 *
 * The 401/403/404 friendly mapping stays here because those codes are
 * HTTP-transport noise that only the connection test cares about; deeper
 * callers (Phase 3/4) want raw exceptions to drive retry/backoff.
 */
final class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private N8nClient $client,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function testConnection(): JSONResponse {
		try {
			$result = $this->client->ping();
			return new JSONResponse([
				'status' => 'ok',
				'message' => $result['message'],
				'httpStatus' => $result['httpStatus'],
			]);
		} catch (\Throwable $e) {
			// One shared formatter (also used by the occ command) so the button and
			// the CLI say the same thing — and so a *rejected* key (401/403) reads
			// differently from a *missing* one. A single `catch \Throwable` is
			// deliberate: N8nApiException is a RuntimeException subclass, so a
			// narrower `catch \RuntimeException` here would hide the 401 mapping.
			return new JSONResponse([
				'status' => 'error',
				'message' => N8nClient::describeConnectionError($e),
			]);
		}
	}

	#[NoCSRFRequired]
	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function testWebhook(): JSONResponse {
		try {
			$result = $this->client->pingWebhook();
			return new JSONResponse([
				'status' => 'ok',
				'message' => $result['message'],
				'httpStatus' => $result['httpStatus'],
			]);
		} catch (\RuntimeException $e) {
			// Covers N8nApiException (n8n's own message) and the friendly
			// pre-formatted errors (missing path/URL, decrypt failure, …).
			return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'Webhook test failed: ' . $e->getMessage(),
			]);
		}
	}
}
