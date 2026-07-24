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
 * Unit tests for {@see TagMerge} — the pure three-way tag merge. The merge is
 * **deterministic**: against a single shared baseline a set element can never be
 * "added on one side and removed on the other" (added ⇒ not-in-baseline, removed ⇒
 * in-baseline — disjoint), so there is no tiebreak. These cases pin the two things
 * that DO matter: an add on either side is kept, and a **remove on either side wins**
 * over the side that left the baseline tag untouched — i.e. removing a tag on one
 * side removes it on the other.
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
	public function testMerge(array $baseline, array $nc, array $source, array $expected): void {
		self::assertSame($expected, TagMerge::merge($baseline, $nc, $source));
	}

	/** @return iterable<string, array{list<string>, list<string>, list<string>, list<string>}> */
	public static function mergeCases(): iterable {
		// No drift: all three already agree, result is the same set (sorted).
		yield 'stable' => [['a', 'b'], ['a', 'b'], ['a', 'b'], ['a', 'b']];

		// First sync (empty baseline): both sides' tags are additions, so union.
		yield 'first sync union' => [[], ['x'], ['y'], ['x', 'y']];

		// Add on NC only, source unchanged → tag is kept.
		yield 'nc adds' => [['a'], ['a', 'b'], ['a'], ['a', 'b']];

		// Add on source only, NC unchanged → tag is kept.
		yield 'source adds' => [['a'], ['a'], ['a', 'b'], ['a', 'b']];

		// Remove on NC only (b was in the baseline), source still carries it →
		// the removal wins: b is dropped. THIS is "remove here removes there".
		yield 'nc removes wins' => [['a', 'b'], ['a'], ['a', 'b'], ['a']];

		// Remove on source only, NC still carries it → the removal wins.
		yield 'source removes wins' => [['a', 'b'], ['a', 'b'], ['a'], ['a']];

		// Both removed the same tag → dropped.
		yield 'both remove' => [['a', 'b'], ['a'], ['a'], ['a']];

		// Both added the same tag → kept.
		yield 'both add same' => [['a'], ['a', 'b'], ['a', 'b'], ['a', 'b']];

		// One side adds a NEW tag while the other removes a DIFFERENT baseline tag —
		// both changes are independent and both apply (not a conflict).
		yield 'independent add and remove' => [['a', 'b'], ['a', 'b', 'c'], ['a'], ['a', 'c']];

		// Symmetric: NC removes, source adds a different tag.
		yield 'independent remove and add' => [['a', 'b'], ['a'], ['a', 'b', 'd'], ['a', 'd']];
	}

	public function testRemoveOnEitherSidePropagates(): void {
		// Whichever side drops a baseline tag, the merged set drops it — the caller's
		// direction (pull vs push) does not change this; it only changes which side
		// the merged set is then written back to.
		self::assertSame(['a'], TagMerge::merge(['a', 'b'], ['a'], ['a', 'b']));
		self::assertSame(['a'], TagMerge::merge(['a', 'b'], ['a', 'b'], ['a']));
	}

	public function testResultIsSortedAndDeduplicated(): void {
		$out = TagMerge::merge([], ['zebra', 'zebra', 'apple'], ['mango', 'apple']);
		self::assertSame(['apple', 'mango', 'zebra'], $out);
	}

	public function testBlankNamesAreDropped(): void {
		$out = TagMerge::merge([''], ['', 'a'], ['a', '']);
		self::assertSame(['a'], $out);
	}

	public function testEmptyInputsYieldEmpty(): void {
		self::assertSame([], TagMerge::merge([], [], []));
	}
}
