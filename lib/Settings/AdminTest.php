<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Classic (non-declarative) "Test" panel — one button, testing the one
 * connection. Declarative settings cannot include buttons, which is why this
 * exists at all; it used to host a second button for the Webhook channel, which
 * is gone (saga Ch5 — deferred, not disowned).
 *
 * **This panel is not registered.** `info.xml` lists only MappingSettings,
 * SyncSettings and AdminSection, and {@see SyncSettings} folded the button into
 * its own template — so {@see getForm} and `templates/admin_test.php` are not
 * reached. The class stays because it is the authorization target the controller
 * names in `#[AuthorizedAdminSetting(settings: AdminTest::class)]`, which is a
 * real job even when nothing renders it.
 */
final class AdminTest implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		// JS + CSS must be added via Util so they pick up the CSP nonce —
		// inline <script>/<style> in templates is blocked by NC's strict CSP.
		Util::addScript(Application::APP_ID, 'admin-test');
		Util::addStyle(Application::APP_ID, 'admin-test');
		// 'blank' render mode: NC wraps the template in the section container
		// but does not inject a full page shell. The panel needs no state now that
		// there is one channel to test — it used to pass `webhook_enabled` so the
		// second button could render disabled.
		return new TemplateResponse(Application::APP_ID, 'admin_test', [], 'blank');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 22 — rendered below the Instance card (5), so the button sits after
	 * the URL and key it tests. Sync Settings (33), Folder mappings (36) and Sync
	 * Actions (45) follow.
	 */
	#[\Override]
	public function getPriority(): int {
		return 22;
	}

	#[\Override]
	public function getName(): ?string {
		// The heading is rendered inside the template (see admin_test.php).
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Read-only test endpoint — no appconfig keys are modified.
		return [];
	}
}
