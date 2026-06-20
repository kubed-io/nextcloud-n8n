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

	/** @When I run occ :args */
	public function iRunOcc(string $args): void {
		$this->occ($args);
	}

	/** @Then the occ command succeeds */
	public function theOccCommandSucceeds(): void {
		Assert::assertSame(0, $this->lastExit, "occ failed (exit {$this->lastExit}):\n{$this->lastOutput}");
	}

	/**
	 * Precondition: make sure the app is enabled (idempotent).
	 *
	 * @Given the app :app is enabled
	 */
	public function givenTheAppIsEnabled(string $app): void {
		$this->occ('app:enable --force ' . escapeshellarg($app));
	}

	/** @Then the app :app should be enabled */
	public function theAppShouldBeEnabled(string $app): void {
		$res = $this->occ('app:list');
		Assert::assertMatchesRegularExpression(
			'/^\s+- ' . preg_quote($app, '/') . ':/m',
			$this->enabledBlock($res['output']),
			"$app is not in the Enabled list",
		);
	}

	/** @Then the app :app should not be enabled */
	public function theAppShouldNotBeEnabled(string $app): void {
		$res = $this->occ('app:list');
		Assert::assertDoesNotMatchRegularExpression(
			'/^\s+- ' . preg_quote($app, '/') . ':/m',
			$this->enabledBlock($res['output']),
			"$app is still enabled",
		);
	}

	/** @Then the app :app path resolves */
	public function theAppPathResolves(string $app): void {
		$res = $this->occ('app:getpath ' . escapeshellarg($app));
		Assert::assertSame(0, $res['exit'], "app:getpath failed for $app");
		Assert::assertNotSame('', trim($res['output']), "app:getpath returned empty for $app");
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

	/** @Given the n8n base URL is set */
	public function theN8nBaseUrlIsSet(): void {
		$url = getenv('N8N_URL') ?: 'http://localhost:5678';
		$res = $this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($url));
		Assert::assertSame(0, $res['exit'], "setting n8n_url failed:\n{$res['output']}");
	}

	/**
	 * Store the real, CI-minted key the way the admin UI would (encrypted), via
	 * the app's own `n8n_sync:set-api-key` command (piped on stdin).
	 *
	 * @Given the n8n API key is set
	 */
	public function theN8nApiKeyIsSet(): void {
		$key = getenv('N8N_API_KEY') ?: '';
		Assert::assertNotSame('', $key, 'N8N_API_KEY is not set — the CI prerequisite must mint it first');
		$res = $this->occStdin($this->occ . ' n8n_sync:set-api-key', $key);
		Assert::assertSame(0, $res['exit'], "set-api-key failed:\n{$res['output']}");
	}

	/** @Given the n8n API key is set to :key */
	public function theN8nApiKeyIsSetTo(string $key): void {
		$res = $this->occStdin($this->occ . ' n8n_sync:set-api-key', $key);
		Assert::assertSame(0, $res['exit'], "set-api-key failed:\n{$res['output']}");
	}

	/** @Given the REST API is enabled */
	public function theRestApiIsEnabled(): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' api_enabled --value=1');
		Assert::assertSame(0, $res['exit'], "enabling api failed:\n{$res['output']}");
	}

	/** @When I run the connection test */
	public function iRunTheConnectionTest(): void {
		$this->occ('n8n_sync:test-connection');
	}

	/** @Then the connection test succeeds */
	public function theConnectionTestSucceeds(): void {
		Assert::assertSame(0, $this->lastExit, "test-connection failed (exit {$this->lastExit}):\n{$this->lastOutput}");
	}

	/** @Then the connection test fails */
	public function theConnectionTestFails(): void {
		Assert::assertNotSame(0, $this->lastExit, "test-connection unexpectedly succeeded:\n{$this->lastOutput}");
	}

	// ── mapping steps (occ n8n_sync:add/list/remove-mapping) ───────────────────

	/** @When I add a mapping: */
	public function iAddAMapping(\Behat\Gherkin\Node\PyStringNode $json): void {
		$res = $this->occ('n8n_sync:add-mapping ' . escapeshellarg($json->getRaw()));
		Assert::assertSame(0, $res['exit'], "add-mapping failed:\n{$res['output']}");
	}

	/** @When I try to add a mapping: */
	public function iTryToAddAMapping(\Behat\Gherkin\Node\PyStringNode $json): void {
		// Don't assert here — the scenario asserts the outcome (accepted/rejected).
		$this->occ('n8n_sync:add-mapping ' . escapeshellarg($json->getRaw()));
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "add-mapping unexpectedly succeeded:\n{$this->lastOutput}");
	}

	/** @Then the configured mappings include the tag :tag */
	public function theConfiguredMappingsIncludeTag(string $tag): void {
		$res = $this->occ('n8n_sync:list-mappings');
		Assert::assertStringContainsString($tag, $res['output'], "no mapping for tag $tag");
	}

	/** @Then there are :count configured mappings */
	public function thereAreNConfiguredMappings(int $count): void {
		$res = $this->occ('n8n_sync:list-mappings');
		$decoded = json_decode($res['output'], true);
		Assert::assertIsArray($decoded, "list-mappings did not return JSON:\n{$res['output']}");
		Assert::assertCount($count, $decoded, "expected $count mappings, got " . count($decoded));
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
