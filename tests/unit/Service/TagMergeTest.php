<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\TagMerge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see TagMerge} — the pure three-way tag merge. Pinning the set
 * algebra here means every wiring path ({@see \OCA\N8nSync\Service\TagSyncService})
 * inherits a proven core: added-vs-removed disambiguation via the baseline, and the
 * `$sourceWins` conflict tiebreak that separates a pull from a push.
 */
#[CoversClass(TagMerge::class)]
final class TagMergeTest extends TestCase {
	/**
	 * @param list<string> $baseline
	 * @param list<string> $nc
	 * @param list<string> $source
	 * @param list<string> $expected
	 */
	#[DataProvider('mergeCases')]
	public function testMerge(array $baseline, array $nc, array $source, bool $sourceWins, array $expected): void {
		self::assertSame($expected, TagMerge::merge($baseline, $nc, $source, $sourceWins));
	}

	/** @return iterable<string, array{list<string>, list<string>, list<string>, bool, list<string>}> */
	public static function mergeCases(): iterable {
		// No drift: all three already agree, result is the same set (sorted).
		yield 'stable' => [['a', 'b'], ['a', 'b'], ['a', 'b'], true, ['a', 'b']];

		// First sync (empty baseline): both sides' tags are additions, so union.
		yield 'first sync union' => [[], ['x'], ['y'], true, ['x', 'y']];

		// Single-sided add on NC, source unchanged → tag is kept.
		yield 'nc adds' => [['a'], ['a', 'b'], ['a'], false, ['a', 'b']];

		// Single-sided add on source, NC unchanged → tag is kept.
		yield 'source adds' => [['a'], ['a'], ['a', 'b'], true, ['a', 'b']];

		// Single-sided remove on NC, source unchanged → tag is dropped.
		yield 'nc removes' => [['a', 'b'], ['a'], ['a', 'b'], false, ['a']];

		// Single-sided remove on source, NC unchanged → tag is dropped.
		yield 'source removes' => [['a', 'b'], ['a', 'b'], ['a'], true, ['a']];

		// Both removed the same tag → dropped, no conflict.
		yield 'both remove' => [['a', 'b'], ['a'], ['a'], true, ['a']];

		// Both added the same tag → kept, no conflict.
		yield 'both add same' => [['a'], ['a', 'b'], ['a', 'b'], true, ['a', 'b']];

		// Conflict: source added `b`, NC removed `b`. Pull (source wins) keeps it.
		yield 'conflict pull keeps' => [['a'], ['a'], ['a', 'b'], true, ['a', 'b']];

		// Same conflict under push (NC wins) → `b` stays removed.
		yield 'conflict push drops' => [['a'], ['a'], ['a', 'b'], false, ['a']];

		// Conflict the other way: NC added `b`, source removed `b`.
		// Pull (source wins) drops it.
		yield 'conflict pull drops other' => [['a', 'b'], ['a', 'b'], ['a'], true, ['a']];

		// …and push (NC wins) keeps it.
		yield 'conflict push keeps other' => [['a', 'b'], ['a', 'b'], ['a'], false, ['a', 'b']];
	}

	public function testResultIsSortedAndDeduplicated(): void {
		$out = TagMerge::merge([], ['zebra', 'zebra', 'apple'], ['mango', 'apple'], true);
		self::assertSame(['apple', 'mango', 'zebra'], $out);
	}

	public function testBlankNamesAreDropped(): void {
		$out = TagMerge::merge([''], ['', 'a'], ['a', ''], false);
		self::assertSame(['a'], $out);
	}

	public function testEmptyInputsYieldEmpty(): void {
		self::assertSame([], TagMerge::merge([], [], [], true));
		self::assertSame([], TagMerge::merge([], [], [], false));
	}
}
