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

/**
 * The "Test connection" authorization target — and only that.
 *
 * **This panel is not registered.** `info.xml` lists only MappingSettings,
 * SyncSettings and AdminSection, and {@see SyncSettings} folded the test button
 * into its own template — so {@see getForm} is never reached, and the template
 * it used to render (`templates/admin_test.php`) has been removed. The class
 * stays because it is the authorization target the controllers name in
 * `#[AuthorizedAdminSetting(settings: AdminTest::class)]`, which is a real job
 * even when nothing renders it. Re-registering the panel means re-creating the
 * template, not just adding the info.xml entry.
 */
final class AdminTest implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		// Unreachable — the panel is not registered (see the class docblock).
		// The interface demands a TemplateResponse; the named template no
		// longer exists, which is fine for a method nothing calls.
		return new TemplateResponse(Application::APP_ID, 'admin_test', [], 'blank');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 22 — where the panel would sit if it were registered: below the
	 * Instance card (5) whose URL and key the button tests, above Sync Settings
	 * (33), Folder mappings (36) and Sync Actions (45).
	 */
	#[\Override]
	public function getPriority(): int {
		return 22;
	}

	#[\Override]
	public function getName(): ?string {
		// Nothing renders this panel, so there is no name to show.
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Read-only test endpoint — no appconfig keys are modified.
		return [];
	}
}
