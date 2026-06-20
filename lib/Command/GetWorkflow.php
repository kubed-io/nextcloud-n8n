<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\N8nClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:get-workflow <id>`
 *
 * Server-side fetch of a single workflow. Useful to confirm that an id
 * picked from `n8n_sync:list-workflows` round-trips through the same client
 * Phase 4 will use to PUT updates back.
 */
final class GetWorkflow extends Command {
	public function __construct(
		private N8nClient $client,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:get-workflow')
			->setDescription('Fetch a single workflow JSON by id from the configured n8n instance.')
			->addArgument('id', InputArgument::REQUIRED, 'The n8n workflow id (from list-workflows).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');
		try {
			$data = $this->client->getWorkflow($id);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return 0;
	}
}
