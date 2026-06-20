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
 * The **REST API channel** card. This is one of two independent writeback
 * channels (the other is {@see WebhookSettings}); the base URL it uses lives in
 * {@see InstanceSettings}. The API channel is also what bulk *pull* and the
 * Test-connection button use.
 *
 * Values land in appconfig under app `n8n_sync`; `api_key` is `sensitive` so
 * core stores it encrypted and never echoes it back. `api_enabled` gates whether
 * saved files are PUT to `/workflows/{id}` — turn it off to push *only* via the
 * webhook. (Pull + Test connection still require a valid key regardless.)
 */
final class AdminSettings implements IDeclarativeSettingsForm {
	#[\Override]
	public function getSchema(): array {
		return [
			// NOTE: do NOT prefix the form id with the app id. The settings
			// frontend strips a leading "<app>_" before calling the save API,
			// so a prefixed id (e.g. n8n_sync_admin -> admin) fails the
			// backend's exact-match lookup and sensitive fields get stored
			// unencrypted. A clean id keeps both sides in agreement.
			'id' => 'api',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'n8n_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'REST API',
			'description' => 'The REST API channel: pulls workflows, and (when enabled) writes saved files back via PUT /workflows/{id}.',
			'fields' => [
				[
					'id' => 'api_enabled',
					'title' => 'Write back via the REST API',
					'description' => 'When on, saving a two-way file updates the workflow in n8n through the REST API. Turn off to push only via the webhook below. (Bulk pull and the Test API button always use the REST API regardless of this toggle.)',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => '1',
				],
				[
					'id' => 'api_key',
					'title' => 'n8n API key',
					'description' => 'Stored encrypted. Sent as X-N8N-API-KEY to the REST API.',
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => 'Paste the n8n API key',
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
