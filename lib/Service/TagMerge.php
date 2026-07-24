<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * The pure, side-effect-free heart of tag sync: a **three-way merge** over sets of
 * content-tag names (saga Ch5 §5.6). No Nextcloud, no n8n, no IO — just set algebra,
 * so it is exhaustively unit-testable and portable to the future shared tag module.
 *
 * A workflow's tags live in three places and all three drift independently:
 *
 *   - the **source** (n8n) workflow's `tags`,
 *   - the **Nextcloud** system-tag pills on the mirror file,
 *   - the **baseline** — the set the two agreed on at the last sync
 *     ({@see WorkflowMetadata::KEY_SYNCED_TAGS}).
 *
 * Comparing only the two *current* sets cannot tell an **add** from a **remove**: if
 * n8n has `{a}` and NC has `{a,b}`, did NC add `b` or did n8n remove it? The baseline
 * answers that. Against it each side yields an unambiguous delta:
 *
 *   added_x   = x − baseline        removed_x = baseline − x
 *
 * The merge starts from the baseline, applies both sides' adds and removes, and only
 * a genuine **conflict** — a tag one side added while the other removed it — needs a
 * tiebreak. `$sourceWins` picks the winner by direction of truth: a **pull** trusts
 * the source (`true`), a **push** trusts Nextcloud (`false`).
 *
 * The reserved namespace ({@see TagSyncService::RESERVED_PREFIX}) and any protected
 * tags (n8n's folder-binding mapping tag) are handled by {@see TagSyncService} before
 * and after this merge — this class only ever sees plain content tags.
 */
final class TagMerge {
	/**
	 * Resolve the agreed content-tag set from the three inputs. Both the Nextcloud
	 * pills and the source workflow should be reconciled *to this result*, and it
	 * becomes the new baseline.
	 *
	 * @param list<string> $baseline last-agreed set (empty on first sync)
	 * @param list<string> $nc       current Nextcloud content tags
	 * @param list<string> $source   current source (n8n) content tags
	 * @param bool $sourceWins       conflict tiebreak: true = source (pull), false = NC (push)
	 * @return list<string> the merged set, unique + sorted (canonical)
	 */
	public static function merge(array $baseline, array $nc, array $source, bool $sourceWins): array {
		$base = self::set($baseline);
		$ncSet = self::set($nc);
		$srcSet = self::set($source);

		// Deltas of each side against the shared baseline.
		$ncAdded = array_diff_key($ncSet, $base);
		$ncRemoved = array_diff_key($base, $ncSet);
		$srcAdded = array_diff_key($srcSet, $base);
		$srcRemoved = array_diff_key($base, $srcSet);

		// Start from the baseline, then apply every unambiguous change.
		$merged = $base;
		foreach ($ncAdded + $srcAdded as $tag => $_) {
			$merged[$tag] = true;
		}
		foreach ($ncRemoved + $srcRemoved as $tag => $_) {
			unset($merged[$tag]);
		}

		// Conflicts: one side added a tag the other removed. The removes above may
		// have dropped a tag the winning side wants, or kept one it doesn't — so
		// re-decide every conflicting tag by the direction of truth.
		$conflicts = (array_intersect_key($ncAdded, $srcRemoved))
			+ (array_intersect_key($srcAdded, $ncRemoved));
		foreach ($conflicts as $tag => $_) {
			$winnerHas = $sourceWins ? isset($srcSet[$tag]) : isset($ncSet[$tag]);
			if ($winnerHas) {
				$merged[$tag] = true;
			} else {
				unset($merged[$tag]);
			}
		}

		return self::sortedKeys($merged);
	}

	/**
	 * Normalise a name list to a set keyed by name (dedup + drop blanks), used as
	 * the internal representation so membership tests are O(1).
	 *
	 * @param list<string> $names
	 * @return array<string,true>
	 */
	private static function set(array $names): array {
		$set = [];
		foreach ($names as $name) {
			if ($name !== '') {
				$set[$name] = true;
			}
		}
		return $set;
	}

	/**
	 * @param array<string,true> $set
	 * @return list<string>
	 */
	private static function sortedKeys(array $set): array {
		$keys = array_keys($set);
		sort($keys);
		return $keys;
	}
}
