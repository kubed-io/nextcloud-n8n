<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Migration;

use OCA\N8nSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Deletes the settings the retired webhook writeback channel left behind
 * (saga Ch5 — deferred, not disowned).
 *
 * ## ONE OF THESE IS A SECRET, WHICH IS WHY THIS STEP EXISTS
 *
 * Removing a feature's code does not remove what its form already stored. An
 * instance that ever saved a webhook token still holds it, encrypted, in app
 * config — and `SECURITY.md` now says this app keeps one secret, the API key. A
 * claim about which secrets are held is only true if the others are actually
 * gone, so this is a security cleanup wearing an upgrade step's clothes, not
 * tidiness.
 *
 * `api_enabled` goes with them. It was the "write back via the REST API" switch,
 * which only meant anything while there were two channels to choose between;
 * leaving it behind would leave a stored `false` that nothing reads and that
 * would silently come back to life if the key were ever consulted again.
 *
 * Idempotent: deleting an absent key is a no-op, so a re-run costs four lookups.
 */
final class RemoveWebhookSettings implements IRepairStep {
	/** The retired keys, in the order an admin met them in the old form. */
	private const RETIRED_KEYS = [
		'webhook_enabled',
		'webhook_path',
		'webhook_token',
		'api_enabled',
	];

	public function __construct(
		private IAppConfig $config,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the retired n8n_sync webhook settings';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$removed = [];
		foreach (self::RETIRED_KEYS as $key) {
			if ($this->config->hasKey(Application::APP_ID, $key)) {
				$this->config->deleteKey(Application::APP_ID, $key);
				$removed[] = $key;
			}
		}
		if ($removed !== []) {
			$output->info('n8n_sync: removed retired settings (' . implode(', ', $removed) . ')');
		}
	}
}
