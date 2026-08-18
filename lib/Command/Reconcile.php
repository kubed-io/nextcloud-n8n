<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncService;
use OCA\N8nSync\Service\SyncStatusService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:sync <pull|push> [--mapping=<tag|id>] [--all]`
 *
 * CLI surface for the manual, mapping-scoped sync controls (the admin's
 * "Sync from n8n" / "Sync to n8n" buttons, saga §14.6) — and the headless way
 * the integration suite drives them. No logic of its own: it resolves the named
 * mapping (by n8n tag, falling back to mapping id) and runs
 * {@see SyncService::dispatch()} **inline** (async=false) so the exit code and
 * the printed JSON reflect the actual run.
 *
 *   pull : n8n → Nextcloud — reconcile the mapping's folder against its tag,
 *          updating files in place by `n8n_id` and pruning files whose workflow
 *          lost the tag. Ignores `unmapped` files (they are outside the mapping).
 *   push : Nextcloud → n8n — push the mapping's `sync` files up.
 *
 * `--mapping` targets one mapping; `--all` (or omitting `--mapping`) runs every
 * mapping. Mapping-scoped sync is the documented per-mapping contract; the
 * all-mappings form mirrors the bulk buttons.
 */
final class Reconcile extends Command {
	public function __construct(
		private SyncService $sync,
		private MappingService $mappings,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:sync')
			->setDescription('Manually sync a mapping (or all) between n8n and Nextcloud.')
			->addArgument('direction', InputArgument::REQUIRED, 'Sync direction: "pull" (n8n → NC) or "push" (NC → n8n).')
			->addOption('mapping', 'm', InputOption::VALUE_REQUIRED, 'Target one mapping by n8n tag (or mapping id).')
			->addOption('all', null, InputOption::VALUE_NONE, 'Sync every mapping (the default when --mapping is omitted).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$direction = (string)$input->getArgument('direction');
		if (!SyncStatusService::isDirection($direction)) {
			$output->writeln('<error>direction must be "pull" or "push"</error>');
			return 1;
		}

		$selector = $input->getOption('mapping');
		$mappingId = null;
		if (is_string($selector) && $selector !== '') {
			$mappingId = $this->resolveMappingId($selector);
			if ($mappingId === null) {
				$output->writeln('<error>no mapping found for "' . $selector . '" (by tag or id)</error>');
				return 1;
			}
		}

		try {
			$result = $this->sync->dispatch($direction, $mappingId, false);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
	}

	/** Resolve a mapping selector to its id: match the n8n tag first, then treat it as an id. */
	private function resolveMappingId(string $selector): ?string {
		foreach ($this->mappings->list() as $mapping) {
			if ($mapping->n8nTag === $selector) {
				return $mapping->id;
			}
		}
		return $this->mappings->getById($selector)?->id;
	}
}
