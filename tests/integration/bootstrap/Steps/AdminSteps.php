<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\PyStringNode;
use PHPUnit\Framework\Assert;

/**
 * Admin setup + connection steps: the "admin configures the app and makes the
 * n8n connection" use case (app config get/set, base URL, API key, REST API,
 * test-connection). Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait AdminSteps {
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
	public function iSetAppConfigMultiline(string $key, PyStringNode $value): void {
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

	/**
	 * One-line connection setup for feature Backgrounds: app enabled + base URL +
	 * REST API on + the CI-provided API key. This is the canonical "ready to talk
	 * to n8n" precondition — Backgrounds say this single line instead of repeating
	 * the four admin steps (which {@see admin-connection.feature} still spells out
	 * because *that* feature is what tests the connection flow itself).
	 *
	 * @Given the app is connected to n8n
	 */
	public function theAppIsConnectedToN8n(): void {
		$this->theAppIsInstalledAndEnabled();
		$this->theAdminHasSetUrlAndEnabledApi();
		$this->theAdminProvidesTheN8nApiKey();
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
}
