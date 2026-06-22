<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\Mapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Mapping} — pure validation/normalisation logic, no NC deps.
 *
 * The focus is the saga Ch2 §14 model collapse: a mapping mode is exactly
 * `sync` | `link`, `writeback` is gone, and {@see Mapping::fromArray} migrates the
 * legacy shapes (`reference` → `link`; `sync` + any `writeback`, incl. the old
 * `backup` = `sync + readonly`, → `sync`). These conversions are the load-bearing
 * back-compat for the live-data migration, so they're worth pinning.
 */
#[CoversClass(Mapping::class)]
final class MappingTest extends TestCase {
	public function testNewSyncMapping(): void {
		$m = Mapping::fromArray(['n8n_tag' => 'team:flows', 'team_folder' => 'flows', 'mode' => 'sync']);
		self::assertSame('sync', $m->mode);
		self::assertSame('team:flows', $m->n8nTag);
		self::assertSame('flows', $m->teamFolder);
	}

	public function testNewLinkMapping(): void {
		$m = Mapping::fromArray(['n8n_tag' => 'team:links', 'team_folder' => 'links', 'mode' => 'link']);
		self::assertSame('link', $m->mode);
	}

	/**
	 * Legacy {mode, writeback} shapes all collapse onto the new two-value model.
	 *
	 * @param array<string,mixed> $legacy
	 */
	#[DataProvider('legacyModeProvider')]
	public function testLegacyModeMigration(array $legacy, string $expectedMode): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f'] + $legacy);
		self::assertSame($expectedMode, $m->mode);
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function legacyModeProvider(): array {
		return [
			'sync + two-way → sync' => [['mode' => 'sync', 'writeback' => 'two-way'], 'sync'],
			'sync + readonly (backup) → sync' => [['mode' => 'sync', 'writeback' => 'readonly'], 'sync'],
			'reference → link' => [['mode' => 'reference'], 'link'],
			'reference + null writeback → link' => [['mode' => 'reference', 'writeback' => null], 'link'],
			'literal link stays link' => [['mode' => 'link'], 'link'],
		];
	}

	public function testWritebackIsDroppedFromToArray(): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f', 'mode' => 'sync', 'writeback' => 'two-way']);
		self::assertArrayNotHasKey('writeback', $m->toArray());
		self::assertSame('sync', $m->toArray()['mode']);
	}

	public function testLegacyPathKeys(): void {
		// old n8n_path (slash-prefixed) → nextcloud:-namespaced tag; nc_path → folder.
		$m = Mapping::fromArray(['n8n_path' => '/alpha', 'nc_path' => '/Alpha', 'mode' => 'sync']);
		self::assertSame('nextcloud:alpha', $m->n8nTag);
		self::assertSame('Alpha', $m->teamFolder);
	}

	public function testUnknownModeRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f', 'mode' => 'backup']);
	}

	public function testMissingModeRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f']);
	}

	public function testCommaInTagRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['n8n_tag' => 'a,b', 'team_folder' => 'f', 'mode' => 'sync']);
	}

	public function testEmptyFolderRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => '', 'mode' => 'sync']);
	}

	public function testRoundTripThroughToArray(): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f', 'mode' => 'link', 'use_team_folder' => false]);
		$again = Mapping::fromArray($m->toArray());
		self::assertSame('link', $again->mode);
		self::assertFalse($again->useTeamFolder);
	}
}
