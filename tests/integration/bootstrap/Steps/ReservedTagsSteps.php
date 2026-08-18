<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * SHARED READ HELPERS, AND NO STEPS OF ITS OWN ANY MORE.
 *
 * This trait was the home of the `n8n:ignore` scenarios — the reserved tag that
 * excluded one workflow from its mapping. That feature was removed whole (see
 * `features/AGENTS.md#workflowsignore`), and its steps went with it.
 *
 * What is left is load-bearing for half the suite: resolving a DAV href to a
 * files-root path, finding a pulled file by workflow id, and reading a workflow's
 * tag names off n8n. They live here rather than moving because several traits
 * already compose them by name, and a rename would be churn for its own sake.
 *
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait ReservedTagsSteps {
	// ── helpers ────────────────────────────────────────────────────────────────

	/** Strip the `…/dav/files/<user>/` prefix off a DAV href → a files-root-relative path. */
	private function hrefToFilesPath(string $href): string {
		$href = rawurldecode($href);
		$user = getenv('NC_ADMIN_USER') ?: 'admin';
		$needle = '/files/' . $user . '/';
		$pos = strpos($href, $needle);
		return $pos === false ? ltrim($href, '/') : substr($href, $pos + strlen($needle));
	}

	/**
	 * The tag names currently on a workflow in n8n.
	 *
	 * @return list<string>
	 */
	private function n8nWorkflowTagNames(string $id): array {
		$wf = $this->n8nGetWorkflow($id);
		Assert::assertIsArray($wf, "workflow $id is gone from n8n");
		$names = [];
		foreach ((array)($wf['tags'] ?? []) as $t) {
			if (is_array($t) && isset($t['name']) && is_string($t['name'])) {
				$names[] = $t['name'];
			}
		}
		return $names;
	}
}
