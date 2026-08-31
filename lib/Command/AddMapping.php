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
 *    "mode":"sync","use_team_folder":true}
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
			// nc_groups travels ALONGSIDE the mapping, not inside it: they are
			// applied to the provisioned folder and read back from it, never stored.
			//
			// `purge_workflows` likewise — it is the admin's answer to a question the
			// panel asks, not a field a mapping stores. A CLI has nowhere to ask, so it
			// is spelled in the JSON, and it defaults to false: the destructive path
			// cannot be reached by a caller that does not know about it.
			$purge = filter_var($data['purge_workflows'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$saved = $this->service->add(Mapping::fromArray($data), $data['nc_groups'] ?? [], $purge);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		} catch (\RuntimeException $e) {
			// The mapping was valid; its FOLDER could not be provisioned — most
			// likely a Team Folder on an instance without groupfolders. Nothing was
			// stored, which is the point of provisioning before persisting.
			$output->writeln('<error>Could not provision the mapped folder: ' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('<info>Added mapping ' . $saved->id . ' (' . $saved->n8nTag . ' → ' . $saved->teamFolder . ').</info>');
		return 0;
	}
}
