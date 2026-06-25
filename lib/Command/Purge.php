<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:purge` — the CLI surface for the "Purge Nextcloud files" admin
 * button (purge.feature). Deletes the **restorable** managed files (sync/link)
 * this app created across every mapping; n8n is never contacted (the delete runs
 * under SyncGuard) and unmapped/ignored/untracked files are kept. Reversible by a
 * subsequent `n8n_sync:sync pull`. No logic of its own — it just calls
 * {@see SyncService::purge()} and prints the `{deleted, kept}` counts as JSON,
 * which is also the headless surface the integration suite drives.
 */
final class Purge extends Command {
	public function __construct(
		private SyncService $sync,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:purge')
			->setDescription('Delete the sync/link workflow files this app created from Nextcloud (n8n untouched; unmapped/ignored/standalone files kept).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$res = $this->sync->purge();
		$output->writeln(json_encode($res, JSON_THROW_ON_ERROR));
		return 0;
	}
}
