<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Resolves the **effective mode** for a single workflow at pull time, given the
 * mapping's default mode and whether the workflow is excluded on the n8n side
 * (saga §14.8 `reserved-tags.feature`).
 *
 * A mapping binds one n8n tag to a folder + a mode (`sync` / `link`). That mode is
 * **authoritative** for every workflow in the mapping — there is no per-workflow
 * sync/link override. The only reserved tag the app honours is the exclude:
 *
 *   n8n:ignore  → exclude this one (resolve() returns null → don't pull)
 *
 * Authority is one-directional: the app only ever **reads** `n8n:ignore` off a
 * workflow — it never writes it onto workflows in n8n. Absent it, the workflow takes
 * the mapping's mode, full stop.
 */
final class ReservedTagResolver {
	/**
	 * The effective mode for $workflow under a mapping whose mode is $defaultMode.
	 *
	 * @param array<string,mixed> $workflow an n8n workflow row (its `tags` are read)
	 * @param string $defaultMode the mapping's mode (Mapping::MODE_SYNC|MODE_LINK)
	 * @return string|null $defaultMode, or null = ignore (n8n:ignore present → the
	 *                     workflow must not be pulled)
	 */
	public function resolve(array $workflow, string $defaultMode): ?string {
		$names = $this->tagNames($workflow);

		if (in_array(OwnershipTags::TAG_IGNORE, $names, true)) {
			return null;
		}
		return $defaultMode;
	}

	/**
	 * Extract the workflow's tag names from the n8n row shape
	 * (`tags: [{id, name}, …]`). Tolerant of missing/odd entries.
	 *
	 * @param array<string,mixed> $workflow
	 * @return list<string>
	 */
	private function tagNames(array $workflow): array {
		$names = [];
		foreach ($workflow['tags'] ?? [] as $t) {
			if (is_array($t) && isset($t['name']) && is_string($t['name'])) {
				$names[] = $t['name'];
			}
		}
		return $names;
	}
}
