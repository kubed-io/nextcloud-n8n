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
	/**
	 * UNREACHABLE, AND IT SAYS SO RATHER THAN PRETENDING.
	 *
	 * Verified against core (`stable34`) both ways it could be called:
	 *   - the authorization path compares CLASS NAMES only — `SecurityMiddleware`
	 *     does `in_array($settingClass, $authorizedClasses, true)` and never
	 *     instantiates the class, so the attribute cannot reach this method;
	 *   - the renderer (`CommonSettingsTrait`) only iterates classes that
	 *     `Settings\Manager::registerSetting()` was given, which come from
	 *     `info.xml`'s `<settings>` block — and this class is not in it.
	 *
	 * So there is no template to return, and returning a `TemplateResponse` for
	 * a file that does not exist would leave a "template not found" fatal waiting
	 * for whoever re-registers the panel. This throws instead, naming both halves
	 * of the job they would have to do.
	 */
	#[\Override]
	public function getForm(): TemplateResponse {
		throw new \LogicException(
			'AdminTest is an authorization target only and renders nothing. To bring the panel back, '
			. 'create templates/admin_test.php and register this class in appinfo/info.xml.',
		);
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
