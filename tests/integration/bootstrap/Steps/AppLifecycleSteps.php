<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * App lifecycle steps (enable / disable / installed-correctly). Steps read in
 * plain English (medium-agnostic); occ is an implementation detail of the step
 * definitions, not the feature. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait AppLifecycleSteps {
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
