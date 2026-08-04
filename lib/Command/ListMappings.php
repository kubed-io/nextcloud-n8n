<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:list-mappings`
 *
 * CLI binding over `MappingService::list()` — prints the configured mappings as
 * JSON (the same list the admin Settings panel renders). No logic of its own.
 */
final class ListMappings extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:list-mappings')
			->setDescription('List the configured folder mappings as JSON.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// describe(), not toArray(): the stored shape no longer carries groups, and
		// "what is this mapping shared with" is the question anyone reading this
		// list is asking. They are read from the folder as this runs.
		$mappings = array_map(
			fn ($m) => $this->service->describe($m),
			$this->service->list(),
		);
		$output->writeln(json_encode($mappings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return 0;
	}
}
