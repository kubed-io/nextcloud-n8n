<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Resolves the **effective mode** for a single workflow at pull time, given the
 * mapping's default mode and any reserved tags the workflow carries on the n8n side
 * (saga §14.8 `reserved-tags.feature`).
 *
 * A mapping binds one n8n tag to a folder + a default mode (`sync` / `link`). On top
 * of that default, a small, OPTIONAL set of reserved tags lets a user override or
 * exclude a single workflow without touching the mapping:
 *
 *   n8n:sync    → pull this one as sync,  whatever the mapping default is
 *   n8n:link    → pull this one as link,  whatever the mapping default is
 *   n8n:ignore  → exclude this one        (resolve() returns null → don't pull)
 *
 * Authority is one-directional: the app only ever **reads** these off a workflow —
 * it never writes them onto workflows in n8n. They are the escape hatch; the mapping
 * default does everything on its own when they are absent.
 *
 * Out of scope (documented, not built): if a workflow carries BOTH `n8n:sync` and
 * `n8n:link`, the pull resolves to `sync` and ignores the stray link.
 */
final class ReservedTagResolver {
	/**
	 * The effective mode for $workflow under a mapping whose default is $defaultMode.
	 *
	 * @param array<string,mixed> $workflow an n8n workflow row (its `tags` are read)
	 * @param string $defaultMode the mapping's mode (Mapping::MODE_SYNC|MODE_LINK)
	 * @return string|null Mapping::MODE_SYNC | Mapping::MODE_LINK, or null = ignore
	 *                     (n8n:ignore present → the workflow must not be pulled)
	 */
	public function resolve(array $workflow, string $defaultMode): ?string {
		$names = $this->tagNames($workflow);

		if (in_array(OwnershipTags::TAG_IGNORE, $names, true)) {
			return null;
		}
		// n8n:sync wins over n8n:link if a workflow somehow carries both.
		if (in_array(OwnershipTags::TAG_SYNC, $names, true)) {
			return Mapping::MODE_SYNC;
		}
		if (in_array(OwnershipTags::TAG_LINK, $names, true)) {
			return Mapping::MODE_LINK;
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
