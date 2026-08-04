<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:set-groups <id> <groups>`
 *
 * Change which Nextcloud groups a mapping's folder is shared with — the ONE
 * editable thing about a mapping, and previously reachable only from the admin
 * panel. Everything else is fixed at create: the tag, the folder, the storage
 * backend and the mode.
 *
 * IT WRITES TO THE FOLDER, NOT TO THE MAPPING. Groups are a property of the
 * folder and Nextcloud already stores them — as groupfolders assignments or as
 * group shares — so this app keeps no second copy to disagree with. A share
 * added from the Files app shows up here too.
 *
 * The set is applied EXACTLY: groups not named are unshared. That is what makes
 * the list narrowable at all; without it, "set" could only ever add.
 *
 * Prints what the FOLDER reports afterwards rather than what was asked for. The
 * two differ when a named group does not exist — Nextcloud cannot share with a
 * group that is not there, and saying so is more useful than echoing the request
 * back.
 */
final class SetGroups extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:set-groups')
			->setDescription('Set the Nextcloud groups a mapping\'s folder is shared with.')
			->addArgument('id', InputArgument::REQUIRED, 'The mapping id (from list-mappings).')
			->addArgument(
				'groups',
				InputArgument::OPTIONAL,
				'Comma-separated group ids. Omit, or pass "", to share with nobody.',
				'',
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');

		try {
			$groups = $this->service->updateGroups($id, (string)$input->getArgument('groups'));
		} catch (\OutOfBoundsException) {
			$output->writeln('<error>No mapping with id "' . $id . '".</error>');
			return 1;
		} catch (\RuntimeException $e) {
			$output->writeln('<error>Could not re-share the mapped folder: ' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln($groups === []
			? '<comment>Shared with no groups.</comment>'
			: 'Shared with: <info>' . implode(', ', $groups) . '</info>');

		return 0;
	}
}
