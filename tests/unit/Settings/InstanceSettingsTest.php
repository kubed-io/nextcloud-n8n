<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Settings;

use OCA\N8nSync\Service\AppConfigReader;
use OCA\N8nSync\Settings\InstanceSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Instance card renders its key-field copy from whether a key is stored — the
 * only reliable "is it set?" signal, since a sensitive field itself always shows
 * blank. These lock that the two states read differently without weakening the
 * field (still a sensitive PASSWORD either way).
 *
 * **Carried over from `AdminSettingsTest`, deliberately.** The dynamic copy used to
 * live on the REST API card; that card is gone with the webhook channel and the URL
 * and the key now share one form. Deleting the card is not a reason to stop testing
 * the behaviour it happened to host — the invariant is about a sensitive field, not
 * about which card it sits on.
 */
#[CoversClass(InstanceSettings::class)]
final class InstanceSettingsTest extends TestCase {
	/** @return array<string,mixed> the api_key field's schema */
	private function keyField(bool $hasKey): array {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($hasKey ? 'encrypted-blob' : '');
		$schema = (new InstanceSettings(new AppConfigReader($config)))->getSchema();
		foreach ($schema['fields'] as $field) {
			if (($field['id'] ?? null) === 'api_key') {
				return $field;
			}
		}
		self::fail('api_key field not found in schema');
	}

	public function testStaysASensitivePasswordFieldEitherWay(): void {
		foreach ([true, false] as $hasKey) {
			$field = $this->keyField($hasKey);
			self::assertTrue($field['sensitive']);
		}
	}

	public function testSignalsWhenNoKeyIsStored(): void {
		$field = $this->keyField(false);
		self::assertStringContainsStringIgnoringCase('no api key', $field['description']);
		self::assertStringContainsStringIgnoringCase('paste the n8n', $field['placeholder']);
	}

	public function testSignalsWhenAKeyIsStored(): void {
		$field = $this->keyField(true);
		self::assertStringContainsStringIgnoringCase('stored', $field['description']);
		self::assertStringContainsString('•', $field['placeholder']);
	}

	/**
	 * THE URL AND THE KEY ARE ONE CARD NOW, and that is the point of the change —
	 * they were only ever apart to distinguish two writeback channels. A form that
	 * lost one of them would still pass every assertion above.
	 */
	public function testHoldsBothHalvesOfTheConnection(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$schema = (new InstanceSettings(new AppConfigReader($config)))->getSchema();

		$ids = array_map(static fn (array $f): string => (string)($f['id'] ?? ''), $schema['fields']);
		self::assertSame(['n8n_url', 'api_key'], $ids);
	}
}
