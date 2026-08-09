<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\WorkflowMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ManagedFile} — the typed view of a workflow file's
 * Files-Metadata that {@see WorkflowMetadata::read()} now returns. Pure value
 * object, so it runs in the standalone unit suite. These tests pin the exact
 * predicates the ~16 lifecycle call sites rely on after the R2 refactor.
 */
#[CoversClass(ManagedFile::class)]
final class ManagedFileTest extends TestCase {
	public function testEmptyWorkflowIdIsNotManaged(): void {
		$mf = new ManagedFile('', '', '', '', '');
		self::assertFalse($mf->isManaged());
	}

	public function testNonEmptyWorkflowIdIsManaged(): void {
		$mf = new ManagedFile('wf-1', Mapping::MODE_SYNC, 'v3', 'abc123', 'map-alpha');
		self::assertTrue($mf->isManaged());
		self::assertSame('wf-1', $mf->workflowId);
		self::assertSame('v3', $mf->versionId);
		self::assertSame('abc123', $mf->syncedHash);
		self::assertSame('map-alpha', $mf->mappingId);
	}

	#[DataProvider('modeCases')]
	public function testModePredicates(string $mode, string $expectedTrue): void {
		$mf = new ManagedFile('wf-1', $mode, '', '', '');
		$flags = [
			'sync' => $mf->isSync(),
			'link' => $mf->isLink(),
			'unmapped' => $mf->isUnmapped(),
		];
		foreach ($flags as $name => $value) {
			self::assertSame($name === $expectedTrue, $value, "isMode($name) for mode=$mode");
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function modeCases(): iterable {
		yield 'sync' => [Mapping::MODE_SYNC, 'sync'];
		yield 'link' => [Mapping::MODE_LINK, 'link'];
		yield 'unmapped' => [WorkflowMetadata::MODE_UNMAPPED, 'unmapped'];
	}

	public function testEmptyModeMatchesNoPredicate(): void {
		// A managed file whose mode was never stamped (legacy) reads back as ''
		// and matches none of the mode predicates — callers treat that as "sync"
		// only where they explicitly check `mode !== ''` (e.g. SyncService::pushOne).
		$mf = new ManagedFile('wf-1', '', '', '', '');
		self::assertFalse($mf->isSync());
		self::assertFalse($mf->isLink());
		self::assertFalse($mf->isUnmapped());
	}

	public function testSyncedTagsDefaultsToEmptyList(): void {
		// Every existing 5-arg call site keeps working: the tag baseline defaults
		// to '' and decodes to an empty list.
		$mf = new ManagedFile('wf-1', Mapping::MODE_SYNC, '', '', '');
		self::assertSame('', $mf->syncedTags);
		self::assertSame([], $mf->syncedTagList());
	}

	public function testSyncedTagListDecodesJson(): void {
		$mf = new ManagedFile('wf-1', Mapping::MODE_SYNC, '', '', '', '["linux","prod"]');
		self::assertSame(['linux', 'prod'], $mf->syncedTagList());
	}

	#[DataProvider('malformedTagCases')]
	public function testSyncedTagListToleratesGarbage(string $raw): void {
		$mf = new ManagedFile('wf-1', Mapping::MODE_SYNC, '', '', '', $raw);
		self::assertSame([], $mf->syncedTagList());
	}

	/** @return iterable<string, array{string}> */
	public static function malformedTagCases(): iterable {
		yield 'not json' => ['nonsense'];
		yield 'json object' => ['{"a":1}'];
		yield 'json object with string values' => ['{"a":"prod"}'];
		yield 'json scalar' => ['"linux"'];
	}

	public function testSyncedTagListDropsNonStringEntries(): void {
		$mf = new ManagedFile('wf-1', Mapping::MODE_SYNC, '', '', '', '["ok",1,true,"fine"]');
		self::assertSame(['ok', 'fine'], $mf->syncedTagList());
	}
}
