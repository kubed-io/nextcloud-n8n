<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Classic (non-declarative) "Test" panel: one button to test the REST API and
 * one to test the Webhook channel. Declarative settings cannot include buttons,
 * so the testing for *both* channels is consolidated here in a single section
 * (rather than repeating a whole card per channel just for a button).
 *
 * Implements IDelegatedSettings so the controller can gate the test endpoints
 * with the canonical #[AuthorizedAdminSetting] attribute.
 */
class AdminTest implements IDelegatedSettings {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		// JS + CSS must be added via Util so they pick up the CSP nonce —
		// inline <script>/<style> in templates is blocked by NC's strict CSP.
		Util::addScript(Application::APP_ID, 'admin-test');
		Util::addStyle(Application::APP_ID, 'admin-test');
		// 'blank' render mode: NC wraps the template in the section container
		// but does not inject a full page shell. The webhook test button is
		// disabled unless the webhook channel is enabled (state at page load).
		return new TemplateResponse(Application::APP_ID, 'admin_test', [
			'webhook_enabled' => $this->appConfig->getValueBool(Application::APP_ID, 'webhook_enabled', false),
		], 'blank');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 22 — rendered below both channel cards (REST API 10, Webhook 20)
	 * so the two test buttons sit together after everything they test is
	 * configured. Writeback timing (25), Mappings (30), Manual sync (35) follow.
	 */
	public function getPriority(): int {
		return 22;
	}

	public function getName(): ?string {
		// The heading is rendered inside the template (see admin_test.php).
		return null;
	}

	public function getAuthorizedAppConfig(): array {
		// Read-only test endpoint — no appconfig keys are modified.
		return [];
	}
}
