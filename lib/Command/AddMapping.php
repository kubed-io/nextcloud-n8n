<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:add-mapping '<json>'`
 *
 * CLI binding for adding a folder mapping — the same `Mapping::fromArray()` +
 * `MappingService::add()` the admin Settings panel's create endpoint runs, just
 * over the CLI for occ/helm/k8s automation. No new logic: validation and
 * persistence live in the service; this only parses the argument and maps an
 * invalid mapping to a non-zero exit.
 *
 * The JSON is the mapping shape, e.g.:
 *   {"n8n_tag":"nextcloud:alpha","team_folder":"alpha","nc_groups":["admin"],
 *    "mode":"sync","writeback":"two-way","use_team_folder":true}
 */
final class AddMapping extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:add-mapping')
			->setDescription('Add a folder mapping (same as the admin Settings panel, via CLI).')
			->addArgument('json', InputArgument::REQUIRED, 'The mapping as a JSON object.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$data = json_decode((string)$input->getArgument('json'), true);
		if (!is_array($data)) {
			$output->writeln('<error>argument is not a valid JSON object</error>');
			return 1;
		}
		try {
			$saved = $this->service->add(Mapping::fromArray($data));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('<info>Added mapping ' . $saved->id . ' (' . $saved->n8nTag . ' → ' . $saved->teamFolder . ').</info>');
		return 0;
	}
}
