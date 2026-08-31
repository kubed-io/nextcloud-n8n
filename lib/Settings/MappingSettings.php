<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\TeamFolderService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Folder-mapping admin panel — the most involved bit of the section because
 * it's an editable list of objects, not a flat form. Declarative settings
 * have no array-of-objects type, so this is a classic IDelegatedSettings
 * panel rendered server-side: PHP foreach builds the initial table, and
 * vanilla JS does add/update/delete through MappingController.
 *
 * Implements IDelegatedSettings so the controller can use
 * #[AuthorizedAdminSetting(settings: MappingSettings::class)] to gate the
 * REST endpoints — same canonical pattern as the Test connection button.
 */
final class MappingSettings implements IDelegatedSettings {
	public function __construct(
		private MappingService $service,
		private IGroupManager $groupManager,
		private TeamFolderService $teamFolders,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		// BEFORE the panel script, which calls into it. `dialogs` defines
		// window.N8nSync.confirmDestructive; addScript order is load order.
		Util::addScript(Application::APP_ID, 'dialogs');
		Util::addScript(Application::APP_ID, 'mapping-settings');
		Util::addStyle(Application::APP_ID, 'mapping-settings');

		// All group ids, for the per-mapping group multiselect. search('') returns
		// every group; fine for a homelab — paginate later if it ever gets large.
		$groups = array_map(
			static fn ($g) => $g->getGID(),
			$this->groupManager->search(''),
		);
		sort($groups);

		return new TemplateResponse(
			Application::APP_ID,
			'mapping_settings',
			[
				// describe(), not toArray(): each card's Groups picker is checked
				// against what the FOLDER is shared with, read as this page renders.
				// So a share added in the Files app or with occ shows up here.
				'mappings' => array_map(
					fn ($m) => $this->service->describe($m),
					$this->service->list(),
				),
				'groups' => $groups,
				'team_folders_available' => $this->teamFolders->isAvailable(),
			],
			'blank',
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Below Sync Settings (33) and above Sync Actions (45). Mappings are a
	 * repeating list (longest section), so they sit last before the buttons:
	 * the instance → how to sync → the mappings → the action buttons.
	 */
	#[\Override]
	public function getPriority(): int {
		return 36;
	}

	#[\Override]
	public function getName(): ?string {
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Mappings are edited via the dedicated REST controller (which carries
		// its own #[AuthorizedAdminSetting]), not via the generic appconfig
		// write endpoint, so no entries here.
		return [];
	}
}
