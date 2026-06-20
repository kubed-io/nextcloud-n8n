<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\SyncStatusService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * "Sync Actions" panel — all of the app's action buttons in one place
 * (declarative forms can't host buttons, so this classic panel collects them):
 *
 *   • Manual bulk sync — "Sync to n8n" / "Sync from n8n" (async jobs, last-run line).
 *   • Connection tests — "Test API" / "Test webhook" (folded in from the old
 *     standalone "Test Connection" panel; handlers in admin-test.js, endpoints
 *     gated by {@see AdminTest}).
 *
 * Rendered last in the section: channels → Sync Settings → Folder mappings →
 * Sync Actions. The automatic-sync strategy lives in {@see WritebackSettings}.
 */
final class SyncSettings implements IDelegatedSettings {
	public function __construct(
		private SyncStatusService $status,
		private IAppConfig $appConfig,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'sync-settings');
		Util::addStyle(Application::APP_ID, 'sync-settings');
		// Connection-test buttons folded into this panel — load their handlers/styles.
		Util::addScript(Application::APP_ID, 'admin-test');
		Util::addStyle(Application::APP_ID, 'admin-test');

		return new TemplateResponse(
			Application::APP_ID,
			'sync_settings',
			[
				'status' => $this->status->all(),
				// Test-webhook button is disabled unless the webhook channel is on.
				'webhook_enabled' => $this->appConfig->getValueBool(Application::APP_ID, 'webhook_enabled', false),
			],
			'blank',
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		// Last panel: Sync Settings (33) → Folder mappings (36) → Sync Actions (45).
		return 45;
	}

	#[\Override]
	public function getName(): ?string {
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Buttons hit dedicated controllers gated by their own
		// #[AuthorizedAdminSetting]; no generic appconfig writes here.
		return [];
	}
}
