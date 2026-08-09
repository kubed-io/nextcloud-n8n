<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Migration;

use OCA\N8nSync\AppInfo\Application;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Deletes the `n8n:sync` / `n8n:link` / `n8n:unmapped` / `n8n:ignore` system tags
 * once, on upgrade. They were the app's own mode pills; nothing writes them any
 * more.
 *
 * ## WHY THIS IS A REPAIR STEP AND NOT A FILTER
 *
 * The alternative was to leave the tags on disk and keep filtering the `n8n:`
 * namespace out of every tag path — which is most of what made tag sync fiddly,
 * and the reason for removing the pills in the first place. But simply dropping
 * the filter would be a data event, not a cleanup: a leftover `n8n:sync` pill
 * would become an ordinary content tag on the next reconcile and get PUSHED TO
 * n8n, so an upgrade would quietly seed every mirrored workflow with a tag the
 * user never chose.
 *
 * Deleting the DEFINITION removes it from every file at once — Nextcloud drops the
 * assignments with it — so the namespace is genuinely empty afterwards and the
 * filter has nothing left to do.
 *
 * ## DELETING A DEFINITION IS NORMALLY A THING THIS APP REFUSES TO DO
 *
 * Tag catalogs are shared, so the tag-sync engine never prunes a definition: one
 * may be pinned on files this app knows nothing about. These four are the
 * exception because the app MINTED them and no human chose them — they are our
 * litter, not someone's label. Nothing else in the `n8n:` namespace is touched.
 *
 * Idempotent: a tag that is already gone is not an error, and after the first run
 * every subsequent upgrade finds nothing.
 */
final class RemoveModePills implements IRepairStep {
	/** The pills this app used to write, and the one reserved marker it read. */
	private const RETIRED = ['n8n:sync', 'n8n:link', 'n8n:unmapped', 'n8n:ignore'];

	public function __construct(
		private ISystemTagManager $tagManager,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the retired n8n_sync mode pills';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$removed = [];
		foreach (self::RETIRED as $name) {
			try {
				$tag = $this->tagManager->getTag($name, true, true);
			} catch (TagNotFoundException) {
				continue; // never existed here, or already swept
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync: could not look up a retired mode pill', [
					'app' => Application::APP_ID,
					'tag' => $name,
					'exception' => $e,
				]);
				continue;
			}

			try {
				$this->tagManager->deleteTags($tag->getId());
				$removed[] = $name;
			} catch (\Throwable $e) {
				// A pill left behind is cosmetic — a dead entry in the tag picker. Never
				// fail an upgrade over it.
				$this->logger->warning('n8n_sync: could not delete a retired mode pill', [
					'app' => Application::APP_ID,
					'tag' => $name,
					'exception' => $e,
				]);
			}
		}

		if ($removed !== []) {
			$output->info('n8n_sync: removed the retired mode pills (' . implode(', ', $removed) . ')');
		}
	}
}
