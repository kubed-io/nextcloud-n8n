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
 * The n8n **instance** — just the base URL, deliberately in its own card at the
 * very top of the section. The URL is global: it scopes *both* the REST API
 * channel and the Webhook channel to a single designated n8n instance, so it
 * doesn't belong to either one. Credentials live in their own per-channel cards
 * (API key in {@see AdminSettings}, webhook token in {@see WebhookSettings}).
 */
class InstanceSettings implements IDeclarativeSettingsForm {
	public function getSchema(): array {
		return [
			// See AdminSettings for the "do NOT prefix the id with the app id"
			// gotcha — applies to every declarative form in this section.
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'n8n_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Instance',
			'description' => 'The n8n instance everything is scoped to. Shared by both the API and Webhook channels below.',
			'fields' => [
				[
					'id' => 'n8n_url',
					'title' => 'n8n base URL',
					'description' => 'e.g. https://n8n.example.com (no trailing slash).',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://n8n.example.com',
					'default' => '',
				],
			],
		];
	}
}
