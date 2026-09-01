<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\MappingTeardownService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:remove-mapping <id>`
 *
 * CLI binding over `MappingService::delete()` — removes a mapping by its id (as
 * the admin Settings panel's delete does). Exits non-zero if the id is unknown.
 * Removing a mapping only drops the binding; it does not touch files or n8n.
 */
final class RemoveMapping extends Command {
	public function __construct(
		private MappingTeardownService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:remove-mapping')
			->setDescription('Remove a folder mapping by id.')
			->addArgument('id', InputArgument::REQUIRED, 'The mapping id (from list-mappings).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');
		try {
			// THROUGH THE TEARDOWN, NOT `MappingService::delete()`. That drops the binding
			// and stops, leaving every connected file still stamped with a mapping id that
			// no longer resolves — links included, which are then undeletable from every
			// route. The CLI and the panel have to answer a removal the same way.
			$this->service->remove($id);
		} catch (\OutOfBoundsException) {
			$output->writeln('<error>No mapping with id "' . $id . '".</error>');
			return 1;
		}
		$output->writeln('<info>Removed mapping ' . $id . '.</info>');
		return 0;
	}
}
