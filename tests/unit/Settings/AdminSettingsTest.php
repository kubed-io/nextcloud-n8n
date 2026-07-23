<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Settings;

use OCA\N8nSync\Settings\AdminSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The REST API card renders its key-field copy from whether a key is stored — the
 * only reliable "is it set?" signal, since a sensitive field itself always shows
 * blank. These lock that the two states read differently without weakening the
 * field (still a sensitive PASSWORD either way).
 */
final class AdminSettingsTest extends TestCase {
	/** @return array<string,mixed> the api_key field's schema */
	private function keyField(bool $hasKey): array {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($hasKey ? 'encrypted-blob' : '');
		$schema = (new AdminSettings($config))->getSchema();
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
}
