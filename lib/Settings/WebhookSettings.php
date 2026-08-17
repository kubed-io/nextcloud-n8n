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
 * The **Webhook channel** card — independent of, and composable with, the REST
 * API channel ({@see AdminSettings}). When enabled, saving a two-way file POSTs
 * the workflow to a single n8n webhook (the receiving flow owns the routing).
 *
 * This can run *in addition to* the API (belt-and-suspenders) or *instead of*
 * it (turn the API channel off and only fire the webhook). It uses the global
 * base URL from {@see InstanceSettings} plus its **own** Bearer token — a
 * separate secret from the REST API key, since the webhook is often a different
 * trust boundary.
 *
 * A "Test webhook" button is intentionally deferred (low priority); it would
 * POST to n8n's test-event path pattern.
 */
final class WebhookSettings implements IDeclarativeSettingsForm {
	#[\Override]
	public function getSchema(): array {
		return [
			// See AdminSettings for the "do NOT prefix the id" gotcha.
			'id' => 'webhook',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'n8n_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Webhook',
			'description' => 'Optional second push channel: POST saved workflows to an n8n webhook, alongside or instead of the REST API.',
			'fields' => [
				[
					'id' => 'webhook_enabled',
					'title' => 'Write back via a webhook',
					'description' => 'Saving a sync file POSTs it to the path below — on its own, or alongside the REST API.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => '0',
				],
				[
					'id' => 'webhook_path',
					'title' => 'Webhook path',
					'description' => 'Path under the base URL. Test webhook posts to the matching /webhook-test/ path.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/webhook/n8n-sync',
					'default' => '',
				],
				[
					'id' => 'webhook_token',
					'title' => 'Webhook Bearer token',
					'description' => 'Optional, stored encrypted. Sent as Authorization: Bearer. Leave empty for an unauthenticated webhook.',
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => 'Paste the webhook token',
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
