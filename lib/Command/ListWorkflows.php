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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:list-workflows [--limit=5] [--cursor=...]`
 *
 * Server-side smoke test for the n8n REST client \u2014 same code path as the
 * "Test connection" button and the eventual Phase 3 reconciler, but reachable
 * without a browser surface and therefore without any CSRF/auth concerns
 * beyond shell access (which is, by definition, already privileged).
 *
 * Output is the raw JSON returned by n8n, pretty-printed. Suitable for
 * piping into `jq` or comparing against the live n8n UI.
 */
class ListWorkflows extends Command {
	public function __construct(
		private N8nClient $client,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('n8n_sync:list-workflows')
			->setDescription('List workflows from the configured n8n instance (smoke test).')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max workflows to return (1-50).', '5')
			->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Pagination cursor returned by a previous call.', '')
			->addOption('tag', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Filter by tag name (repeatable). Multiple values are AND-joined by n8n.', []);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, min(50, (int)$input->getOption('limit')));
		$cursor = (string)$input->getOption('cursor');
		/** @var list<string> $tags */
		$tags = (array)$input->getOption('tag');
		$tags = array_values(array_filter(array_map('strval', $tags), fn ($t) => $t !== ''));
		try {
			$data = $this->client->listWorkflows(
				$limit,
				$cursor !== '' ? $cursor : null,
				$tags === [] ? null : $tags,
			);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return 0;
	}
}
