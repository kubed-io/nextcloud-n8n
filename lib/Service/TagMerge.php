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
 * For a **set** element against a single baseline the merge is fully **deterministic**
 * — there is no tiebreak to make. A tag is in the result iff it was *added* by either
 * side (it wasn't in the baseline; at least one side now has it) or it *survived* in
 * the baseline (both sides still carry it). The only way the two current sets can
 * disagree on a baseline tag is that exactly one side *removed* it — and a removal
 * always wins over the unchanged side, because that side is the one that changed. A
 * tag can never be simultaneously "added on one side and removed on the other":
 * *added* means not-in-baseline, *removed* means in-baseline, and those are disjoint.
 *
 * Direction-of-truth (pull trusts n8n, push trusts Nextcloud) is therefore **not** a
 * property of this merge — it is expressed by *which* reconcile runs it and what that
 * reconcile does with the result ({@see TagSyncService}). This class stays a pure,
 * symmetric function of its three inputs.
 *
 * The reserved namespace ({@see TagSyncService::RESERVED_PREFIX}) and any protected
 * tags (n8n's folder-binding mapping tag) are handled by {@see TagSyncService} before
 * and after this merge — this class only ever sees plain content tags.
 */
final class TagMerge {
	/**
	 * Resolve the agreed content-tag set from the three inputs (deterministic; see
	 * the class docblock for why no tiebreak is needed). Both the Nextcloud pills and
	 * the source workflow should be reconciled *to this result*, and it becomes the
	 * new baseline.
	 *
	 * @param list<string> $baseline last-agreed set (empty on first sync)
	 * @param list<string> $nc       current Nextcloud content tags
	 * @param list<string> $source   current source (n8n) content tags
	 * @return list<string> the merged set, unique + sorted (canonical)
	 */
	public static function merge(array $baseline, array $nc, array $source): array {
		$base = self::set($baseline);
		$ncSet = self::set($nc);
		$srcSet = self::set($source);

		// Deltas of each side against the shared baseline. Adds are disjoint from
		// removes (an add is not-in-baseline, a remove is in-baseline), so applying
		// every add and every remove is unambiguous — a removal by either side wins
		// over the side that left the baseline tag untouched.
		$ncAdded = array_diff_key($ncSet, $base);
		$srcAdded = array_diff_key($srcSet, $base);
		$ncRemoved = array_diff_key($base, $ncSet);
		$srcRemoved = array_diff_key($base, $srcSet);

		$merged = $base;
		foreach ($ncAdded + $srcAdded as $tag => $_) {
			$merged[$tag] = true;
		}
		foreach ($ncRemoved + $srcRemoved as $tag => $_) {
			unset($merged[$tag]);
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
