<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Command;

use OCA\N8nSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ n8n_sync:set-api-key [<key>]`
 *
 * Store the n8n API key the way the app reads it — **`ICrypto`-encrypted** under
 * the `api_key` AppConfig entry, exactly as the admin UI's `sensitive` field does
 * on save. {@see \OCA\N8nSync\Service\N8nClient} calls `ICrypto::decrypt()` on
 * this value, so a plain `occ config:app:set … api_key` (even with `--sensitive`,
 * which only hides it from reports) stores plaintext and fails to decrypt — this
 * command is the correct headless path.
 *
 * The headless equivalent of pasting the key into Settings: useful for occ/helm
 * config injection and for the integration tests. (Mirrors how this repo's
 * `apps/nextcloud` sets the Keycloak OIDC secret via that app's own occ command.)
 *
 * Pass the key as an argument, or pipe it on stdin to keep it out of the process
 * list / shell history:
 *   echo "$RAW_KEY" | occ n8n_sync:set-api-key
 */
final class SetApiKey extends Command {
	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('n8n_sync:set-api-key')
			->setDescription('Store the n8n API key (encrypted), as the admin Settings panel would.')
			->addArgument('key', InputArgument::OPTIONAL, 'The raw n8n API key. If omitted, read from stdin.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$key = (string)($input->getArgument('key') ?? '');
		if ($key === '') {
			$stdin = file_get_contents('php://stdin');
			$key = $stdin === false ? '' : trim($stdin);
		}
		if ($key === '') {
			$output->writeln('<error>No API key provided (pass as an argument or pipe on stdin).</error>');
			return 1;
		}

		$this->config->setValueString(Application::APP_ID, 'api_key', $this->crypto->encrypt($key), sensitive: true);
		$output->writeln('<info>n8n API key stored (encrypted).</info>');
		return 0;
	}
}
