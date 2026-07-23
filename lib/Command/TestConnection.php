<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\N8nClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:test-connection`
 *
 * Headless equivalent of the admin "Test connection" button — runs the exact
 * same {@see N8nClient::ping()} (GET /api/v1/workflows with the stored, decrypted
 * API key) so an operator can verify the base URL + API key + api-enabled are
 * all valid without a browser. Same code path the Settings panel exercises;
 * complements the existing `list-workflows` / `get-workflow` smoke commands.
 *
 * Exit 0 on a reachable, authenticated instance; 1 otherwise (with the same
 * friendly message the button shows).
 */
final class TestConnection extends Command {
	public function __construct(
		private N8nClient $client,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:test-connection')
			->setDescription('Verify the configured n8n base URL + API key (same as the admin Test connection button).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$result = $this->client->ping();
		} catch (\Throwable $e) {
			// Same friendly formatter the admin button uses — so an unset key and a
			// rejected key report differently, and the CLI matches the UI.
			$output->writeln('<error>' . N8nClient::describeConnectionError($e) . '</error>');
			return 1;
		}
		$output->writeln('<info>' . $result['message'] . '</info>');
		return 0;
	}
}
