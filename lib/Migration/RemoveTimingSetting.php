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
 * Deletes the retired `timing` setting (saga Ch5 — *the toggle that governs two of
 * fifteen things*).
 *
 * ## A STORED VALUE NOTHING READS IS WORSE THAN NO VALUE
 *
 * Removing the radio does not remove what it already wrote. An instance that ever saved
 * `sync` still holds it, and a key nothing consults is a trap for the next person who
 * greps for it: it looks like configuration, it reads like an answer, and it changes
 * nothing. The same reasoning retired `api_enabled` with the webhook channel
 * ({@see RemoveWebhookSettings}).
 *
 * Whether a writeback runs inline or is queued is now derived per request by
 * {@see \OCA\N8nSync\Service\WritebackStrategy} from two things an admin could not have
 * known: whether there is an acting user for the job to resolve the file through, and
 * whether anything actually drains the queue.
 *
 * Idempotent: deleting an absent key is a no-op, so a re-run costs one lookup.
 */
final class RemoveTimingSetting implements IRepairStep {
	private const RETIRED_KEY = 'timing';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the retired n8n_sync writeback timing setting';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if (!$this->config->hasKey(Application::APP_ID, self::RETIRED_KEY)) {
			return;
		}
		$this->config->deleteKey(Application::APP_ID, self::RETIRED_KEY);
		$output->info('n8n_sync: removed the retired "timing" setting; inline-vs-queued is now derived');
	}
}
