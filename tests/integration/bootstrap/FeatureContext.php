<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration;

use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;

/**
 * Behat step definitions for the n8n_sync integration suite.
 *
 * Transport: `occ` is invoked via the shell (the $OCC env var, e.g. "php occ"
 * run from the Nextcloud server root) — the same way the lifecycle/admin-setup
 * steps a human operator would run. Later stages add HTTP calls to NC's API and
 * to the n8n service (Guzzle) for behavioural assertions.
 */
final class FeatureContext implements Context {
	private const APP_ID = 'n8n_sync';

	/** The occ invocation prefix, e.g. "php occ". */
	private string $occ;

	/** Result of the most recent occ command. */
	private int $lastExit = 0;
	private string $lastOutput = '';

	public function __construct() {
		$this->occ = getenv('OCC') ?: 'php occ';
	}

	// ── occ plumbing ────────────────────────────────────────────────────────

	/**
	 * Run an occ command. $args is appended to the occ prefix verbatim.
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occ(string $args): array {
		$cmd = $this->occ . ' ' . $args . ' 2>&1';
		$output = [];
		$exit = 0;
		exec($cmd, $output, $exit);
		$this->lastExit = $exit;
		$this->lastOutput = implode("\n", $output);
		return ['exit' => $exit, 'output' => $this->lastOutput];
	}

	// ── lifecycle steps ───────────────────────────────────────────────────────
	// Steps read in plain English (medium-agnostic); occ is an implementation
	// detail of the step definitions, not the feature.

	/**
	 * Precondition: make sure the app is enabled (idempotent).
	 *
	 * @Given the app is enabled
	 */
	public function givenTheAppIsEnabled(): void {
		$this->occ('app:enable --force ' . self::APP_ID);
	}

	/** @When the admin enables the app */
	public function theAdminEnablesTheApp(): void {
		$res = $this->occ('app:enable --force ' . self::APP_ID);
		Assert::assertSame(0, $res['exit'], "enabling the app failed:\n{$res['output']}");
	}

	/** @When the admin disables the app */
	public function theAdminDisablesTheApp(): void {
		$res = $this->occ('app:disable ' . self::APP_ID);
		Assert::assertSame(0, $res['exit'], "disabling the app failed:\n{$res['output']}");
	}

	/** @Then the app should be enabled */
	public function theAppIsEnabled(): void {
		$res = $this->occ('app:list');
		Assert::assertMatchesRegularExpression(
			'/^\s+- ' . preg_quote(self::APP_ID, '/') . ':/m',
			$this->enabledBlock($res['output']),
			'the app is not in the Enabled list',
		);
	}

	/** @Then the app is not enabled */
	public function theAppIsNotEnabled(): void {
		$res = $this->occ('app:list');
		Assert::assertDoesNotMatchRegularExpression(
			'/^\s+- ' . preg_quote(self::APP_ID, '/') . ':/m',
			$this->enabledBlock($res['output']),
			'the app is still enabled',
		);
	}

	/** @Then the app is installed correctly */
	public function theAppIsInstalledCorrectly(): void {
		$res = $this->occ('app:getpath ' . self::APP_ID);
		Assert::assertSame(0, $res['exit'], 'app:getpath failed');
		Assert::assertNotSame('', trim($res['output']), 'app path did not resolve');
	}

	// ── admin-setup steps ─────────────────────────────────────────────────────

	/** @When I set app config :key to :value */
	public function iSetAppConfig(string $key, string $value): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' ' . escapeshellarg($key) . ' --value=' . escapeshellarg($value));
		Assert::assertSame(0, $res['exit'], "config:app:set $key failed:\n{$res['output']}");
	}

	/**
	 * Multi-line (PyString) form, e.g. for the mappings JSON.
	 *
	 * @When I set app config :key to:
	 */
	public function iSetAppConfigMultiline(string $key, \Behat\Gherkin\Node\PyStringNode $value): void {
		$this->iSetAppConfig($key, $value->getRaw());
	}

	/** @When I set sensitive app config :key to :value */
	public function iSetSensitiveAppConfig(string $key, string $value): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' ' . escapeshellarg($key) . ' --value=' . escapeshellarg($value) . ' --sensitive');
		Assert::assertSame(0, $res['exit'], "config:app:set $key (sensitive) failed:\n{$res['output']}");
	}

	/** @Then app config :key is :expected */
	public function appConfigIs(string $key, string $expected): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' ' . escapeshellarg($key));
		Assert::assertSame($expected, trim($res['output']), "config $key mismatch");
	}

	/** @Then app config :key contains :needle */
	public function appConfigContains(string $key, string $needle): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' ' . escapeshellarg($key));
		Assert::assertStringContainsString($needle, $res['output'], "config $key did not contain '$needle'");
	}

	// ── connection steps (the "admin makes connection" use case) ──────────────

	/** @Given the app is installed and enabled */
	public function theAppIsInstalledAndEnabled(): void {
		$this->occ('app:enable --force ' . self::APP_ID);
	}

	/** @When the admin sets the n8n base URL */
	public function theAdminSetsTheN8nBaseUrl(): void {
		$url = getenv('N8N_URL') ?: 'http://localhost:5678';
		$res = $this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($url));
		Assert::assertSame(0, $res['exit'], "setting the base URL failed:\n{$res['output']}");
	}

	/**
	 * Store the real, CI-provided key the way the admin UI does (encrypted).
	 *
	 * @When the admin provides the n8n API key
	 */
	public function theAdminProvidesTheN8nApiKey(): void {
		$key = getenv('N8N_API_KEY') ?: '';
		Assert::assertNotSame('', $key, 'N8N_API_KEY is not set — the test setup must provide it');
		$res = $this->occStdin($this->occ . ' n8n_sync:set-api-key', $key);
		Assert::assertSame(0, $res['exit'], "providing the API key failed:\n{$res['output']}");
	}

	/** @When the admin provides an invalid API key */
	public function theAdminProvidesAnInvalidApiKey(): void {
		$res = $this->occStdin($this->occ . ' n8n_sync:set-api-key', 'not-a-real-key');
		Assert::assertSame(0, $res['exit'], "storing the (invalid) key failed:\n{$res['output']}");
	}

	/** @When the admin enables the REST API */
	public function theAdminEnablesTheRestApi(): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' api_enabled --value=1');
		Assert::assertSame(0, $res['exit'], "enabling the REST API failed:\n{$res['output']}");
	}

	/** @Given the admin has set the n8n base URL and enabled the REST API */
	public function theAdminHasSetUrlAndEnabledApi(): void {
		$this->theAdminSetsTheN8nBaseUrl();
		$this->theAdminEnablesTheRestApi();
	}

	/** @When the admin tests the connection */
	public function theAdminTestsTheConnection(): void {
		$this->occ('n8n_sync:test-connection');
	}

	/** @Then the connection is verified */
	public function theConnectionIsVerified(): void {
		Assert::assertSame(0, $this->lastExit, "the connection test failed:\n{$this->lastOutput}");
	}

	/** @Then the connection test reports a failure */
	public function theConnectionTestReportsAFailure(): void {
		Assert::assertNotSame(0, $this->lastExit, "the connection test unexpectedly succeeded:\n{$this->lastOutput}");
	}

	// ── mapping steps ─────────────────────────────────────────────────────────
	// The feature describes mappings in plain English (titled table columns); the
	// step translates "storage"/"mode" words into the data model and adds them.

	/** @When the admin adds these mappings: */
	public function theAdminAddsTheseMappings(\Behat\Gherkin\Node\TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$res = $this->addMapping(
				$row['n8n tag'],
				$row['folder'],
				$row['storage'],
				$row['mode'],
			);
			Assert::assertSame(0, $res['exit'], "adding mapping {$row['n8n tag']} failed:\n{$res['output']}");
		}
	}

	/** @When the admin adds a sync mapping with no writeback for tag :tag */
	public function theAdminAddsASyncMappingWithNoWriteback(string $tag): void {
		// "sync" normally implies a writeback; build the invalid shape directly.
		$json = json_encode([
			'n8n_tag' => $tag, 'team_folder' => 'x', 'nc_groups' => ['admin'],
			'mode' => 'sync', 'use_team_folder' => true,
		], JSON_THROW_ON_ERROR);
		$this->occ('n8n_sync:add-mapping ' . escapeshellarg($json));
	}

	/** @When the admin adds a link mapping that also writes back for tag :tag */
	public function theAdminAddsALinkMappingThatWritesBack(string $tag): void {
		$json = json_encode([
			'n8n_tag' => $tag, 'team_folder' => 'x', 'nc_groups' => ['admin'],
			'mode' => 'reference', 'writeback' => 'two-way', 'use_team_folder' => true,
		], JSON_THROW_ON_ERROR);
		$this->occ('n8n_sync:add-mapping ' . escapeshellarg($json));
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was unexpectedly accepted:\n{$this->lastOutput}");
	}

	/** @Then there are :count configured mappings */
	public function thereAreNConfiguredMappings(int $count): void {
		Assert::assertCount($count, $this->listMappings(), "expected $count mappings");
	}

	/** @Then the mapping for tag :tag is a :storage folder in :mode mode */
	public function theMappingForTagIs(string $tag, string $storage, string $mode): void {
		$m = $this->findMapping($tag);
		Assert::assertNotNull($m, "no mapping for tag $tag");
		// storage: "team" → use_team_folder true; "admin" → false.
		Assert::assertSame(str_contains($storage, 'team'), (bool)($m['use_team_folder'] ?? false), "tag $tag storage");
		[$expMode, $expWriteback] = $this->modeToModel($mode);
		Assert::assertSame($expMode, $m['mode'], "tag $tag mode");
		if ($expWriteback !== null) {
			Assert::assertSame($expWriteback, $m['writeback'] ?? null, "tag $tag writeback");
		}
	}

	/** Translate a UI mode word to the stored (mode, writeback) pair. */
	private function modeToModel(string $mode): array {
		return match ($mode) {
			'sync' => ['sync', 'two-way'],
			'backup' => ['sync', 'readonly'],
			'link' => ['reference', null],
			default => throw new \InvalidArgumentException("unknown mode '$mode'"),
		};
	}

	/** Build + run an add-mapping from plain-English storage/mode words. */
	private function addMapping(string $tag, string $folder, string $storage, string $mode): array {
		[$m, $writeback] = $this->modeToModel($mode);
		$data = [
			'n8n_tag' => $tag,
			'team_folder' => $folder,
			'nc_groups' => ['admin'],
			'mode' => $m,
			'use_team_folder' => str_contains($storage, 'team'),
		];
		if ($writeback !== null) {
			$data['writeback'] = $writeback;
		}
		return $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
	}

	/** @return list<array<string,mixed>> */
	private function listMappings(): array {
		$res = $this->occ('n8n_sync:list-mappings');
		$decoded = json_decode($res['output'], true);
		Assert::assertIsArray($decoded, "list-mappings did not return JSON:\n{$res['output']}");
		return $decoded;
	}

	/** @return array<string,mixed>|null */
	private function findMapping(string $tag): ?array {
		foreach ($this->listMappings() as $m) {
			if (($m['n8n_tag'] ?? null) === $tag) {
				return $m;
			}
		}
		return null;
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * Run an occ command with data piped on stdin (for `set-api-key`, which reads
	 * the key from stdin to keep it off the process list).
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occStdin(string $cmd, string $stdin): array {
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $descriptors, $pipes);
		Assert::assertIsResource($proc, "could not start: $cmd");
		fwrite($pipes[0], $stdin);
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($proc);
		$this->lastExit = $exit;
		$this->lastOutput = $out;
		return ['exit' => $exit, 'output' => $out];
	}

	/** Slice the "Enabled:" block out of `occ app:list` output (stop at "Disabled:"). */
	private function enabledBlock(string $appList): string {
		$lines = explode("\n", $appList);
		$out = [];
		$in = false;
		foreach ($lines as $line) {
			if (str_starts_with($line, 'Enabled:')) {
				$in = true;
				continue;
			}
			if (str_starts_with($line, 'Disabled:')) {
				break;
			}
			if ($in) {
				$out[] = $line;
			}
		}
		return implode("\n", $out);
	}
}
