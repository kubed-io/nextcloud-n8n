<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * "Sync Settings" — the automatic-sync strategy for both directions. (Class name
 * kept as WritebackSettings to preserve its declarative registration; the user-
 * facing title is "Sync Settings".) The always-available bulk buttons live in
 * their own dedicated panel ({@see SyncSettings}); this form is config only.
 *
 * Declarative + STORAGE_TYPE_INTERNAL → values auto-persist to appconfig under
 * each field id, read elsewhere by:
 *   - `timing`            → {@see \OCA\N8nSync\Listener\NodeWrittenListener} (NC→n8n)
 *   - `schedule_enabled`  → {@see \OCA\N8nSync\BackgroundJob\ScheduledPullJob} (n8n→NC)
 *   - `schedule_interval` → same job's TimedJob interval (seconds)
 *
 * NC schedules by **interval** (TimedJob), not cron expressions — hence presets.
 *
 * Same id-prefix gotcha as AdminSettings — the form id must NOT be prefixed with
 * the app id.
 */
final class WritebackSettings implements IDeclarativeSettingsForm {
	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'data_sync',
			'priority' => 33, // just below Folder mappings (30); the Manual sync buttons (40) follow
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'n8n_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Sync Settings',
			'description' => 'How Nextcloud and n8n stay in sync automatically. The Manual sync buttons below run a one-off sync in either direction at any time.',
			'fields' => [
				[
					'id' => 'timing',
					'title' => 'Nextcloud → n8n: when you save a workflow file',
					'description' => 'Async (recommended): the push runs in the background after the save. Sync: pushes during the save for instant feedback, but can briefly lock the file. Only two-way mappings push back.',
					'type' => DeclarativeSettingsTypes::RADIO,
					'default' => 'async',
					'options' => [
						['name' => 'Push in the background (asynchronous — recommended)', 'value' => 'async'],
						['name' => 'Push immediately during the save (synchronous)', 'value' => 'sync'],
					],
				],
				[
					'id' => 'schedule_enabled',
					'title' => 'n8n → Nextcloud: scheduled sync',
					'description' => 'Nextcloud periodically pulls workflows from n8n (read-only — nothing changes in n8n). Optional; when off, use the manual “Sync from n8n” button. For near-real-time instead, build an n8n workflow that pushes changes to Nextcloud.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => '0',
				],
				[
					'id' => 'schedule_interval',
					'title' => 'Schedule — how often',
					'description' => 'How often to pull, as a number + unit (s/m/h/d). Examples: 15m, 1h, 6h, 1d. A plain number = seconds. Minimum 1m.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '1h',
					'default' => '1h',
				],
			],
		];
	}
}
