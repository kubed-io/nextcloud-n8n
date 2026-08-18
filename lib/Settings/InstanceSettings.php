<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\Service\AppConfigReader;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * The n8n **instance**: where it is, and the key to talk to it. One card, because
 * there is one connection.
 *
 * ## IT USED TO BE THREE CARDS, AND TWO OF THEM WERE A FEATURE THAT NEVER LANDED
 *
 * The section was Instance (URL) → REST API (key + "write back via the REST API")
 * → Webhook (enabled + path + token). That shape existed because a saved file was
 * meant to reach n8n through *either* of two channels, so the URL was the one thing
 * they shared and each channel owned its own credential card.
 *
 * The webhook channel is gone (saga Ch5 — deferred, not disowned), and with it the
 * reason for the split. One channel needs no switch to turn it off, no card of its
 * own to distinguish it from the other, and no "which of these is on?" for an admin
 * to reason about. The REST API is how a workflow is written back; that is a fact
 * about the app, not a setting.
 *
 * So the URL and the key sit together, which is also where an admin looks for them:
 * they are two halves of one credential and were only ever apart to serve a
 * distinction that no longer exists.
 *
 * ## WHY THE KEY'S OWN COPY IS COMPUTED
 *
 * A sensitive field renders **blank** even when a value is stored — core never
 * echoes it — so the admin cannot otherwise tell "no key yet" from "a key is
 * saved". The description and placeholder are therefore rendered from whether a key
 * is currently stored: a plain "is it set?" signal that does not depend on the
 * framework showing a masked value. Whether the key is *valid* is what the Test
 * connection button answers, and that is a different question.
 */
final class InstanceSettings implements IDeclarativeSettingsForm {
	public function __construct(
		private readonly AppConfigReader $config,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		$hasKey = $this->config->string('api_key', '') !== '';

		$keyDescription = $hasKey
			? '✓ A key is stored (encrypted). Paste a new one to replace it, or use Test connection to check it still works.'
			: 'No API key stored yet. Sent as X-N8N-API-KEY once saved.';
		$keyPlaceholder = $hasKey
			? '•••••••••••••• — a key is stored (paste to replace)'
			: 'Paste the n8n API key';

		return [
			// NOTE: do NOT prefix the form id with the app id. The settings
			// frontend strips a leading "<app>_" before calling the save API,
			// so a prefixed id (e.g. n8n_sync_instance -> instance) fails the
			// backend's exact-match lookup and sensitive fields get stored
			// unencrypted. A clean id keeps both sides in agreement.
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'n8n_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Instance',
			'description' => 'The n8n instance this app talks to, and the key it authenticates with.',
			'fields' => [
				[
					'id' => 'n8n_url',
					'title' => 'n8n base URL',
					'description' => 'No trailing slash.',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://n8n.example.com',
					'default' => '',
				],
				[
					'id' => 'api_key',
					'title' => 'n8n API key',
					'description' => $keyDescription,
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $keyPlaceholder,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
