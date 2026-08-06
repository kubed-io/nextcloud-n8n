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

	/**
	 * An omitted mode is the DEFAULT, not a refusal — this test used to assert the
	 * opposite, and it was the only thing depending on the old behaviour.
	 *
	 * `link` is the conservative choice: it downloads nothing and pushes nothing
	 * back, so a mapping made without an opinion about mode cannot cost anything.
	 * It also makes the shortest useful call expressible — a tag and a folder —
	 * which is what `occ n8n_sync:add-mapping` overwhelmingly gets asked to do.
	 *
	 * An UNKNOWN mode is still a refusal ({@see testUnknownModeRejected()}): the
	 * admin said something, and the app cannot honour it. Saying nothing and
	 * saying nonsense are different inputs and get different answers.
	 */
	public function testMissingModeDefaultsToLink(): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f']);
		$this->assertSame(Mapping::MODE_LINK, $m->mode);
	}

	public function testCommaInTagRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['n8n_tag' => 'a,b', 'team_folder' => 'f', 'mode' => 'sync']);
	}

	public function testEmptyFolderRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => '', 'mode' => 'sync']);
	}

	public function testNestedFolderPathIsPreserved(): void {
		// Mappings nest, so a team_folder may be a multi-segment path — the
		// resolver prefix-matches it. Surrounding/duplicate slashes are cleaned.
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => '/outer//inner/', 'mode' => 'sync']);
		self::assertSame('outer/inner', $m->teamFolder);
	}

	/**
	 * THE DEFAULT MUST BE THE BACKEND THAT ALWAYS EXISTS.
	 *
	 * A Team Folder needs groupfolders, an optional app that is absent on a stock
	 * Nextcloud, so defaulting to it made the default mapping the one that could
	 * not be provisioned. This asserts the omitted flag, not a passed `false` —
	 * the bug was entirely in what happens when nobody says anything.
	 */
	public function testStorageDefaultsToAdminOwned(): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f']);
		self::assertFalse($m->useTeamFolder, 'an unset storage flag must mean an admin-owned folder');
	}

	public function testStorageIsOptedInto(): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f', 'use_team_folder' => true]);
		self::assertTrue($m->useTeamFolder, 'a Team Folder must still be selectable');
	}

	public function testRoundTripThroughToArray(): void {
		$m = Mapping::fromArray(['n8n_tag' => 't', 'team_folder' => 'f', 'mode' => 'link', 'use_team_folder' => false]);
		$again = Mapping::fromArray($m->toArray());
		self::assertSame('link', $again->mode);
		self::assertFalse($again->useTeamFolder);
	}
}
