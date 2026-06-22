<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\OwnershipTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see OwnershipTags::tagFor} — the pure mode → tag mapping.
 *
 * The saga Ch2 §14 model collapse means exactly two modes carry a file tag:
 * `sync` → `n8n:sync`, `link` → `n8n:link`. `backup` is gone and any other mode
 * (incl. the Phase-2 `unmapped`/`ignored` states, which have no file tag) must
 * throw rather than silently mis-tag.
 */
#[CoversClass(OwnershipTags::class)]
final class OwnershipTagsTest extends TestCase {
	public function testSyncMode(): void {
		self::assertSame('n8n:sync', OwnershipTags::tagFor(Mapping::MODE_SYNC));
	}

	public function testLinkMode(): void {
		self::assertSame('n8n:link', OwnershipTags::tagFor(Mapping::MODE_LINK));
	}

	#[DataProvider('unknownModeProvider')]
	public function testUnknownModeThrows(string $mode): void {
		$this->expectException(\InvalidArgumentException::class);
		OwnershipTags::tagFor($mode);
	}

	/** @return array<string,array{0:string}> */
	public static function unknownModeProvider(): array {
		return [
			'dropped backup' => ['backup'],
			'wire-only reference' => ['reference'],
			'phase-2 unmapped' => ['unmapped'],
			'phase-2 ignored' => ['ignored'],
			'empty' => [''],
		];
	}
}
