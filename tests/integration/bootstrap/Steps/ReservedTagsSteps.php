<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Reserved-tag steps (saga §14.8 `reserved-tags.feature`): the optional, n8n-side
 * per-workflow overrides read at pull time (`n8n:sync` / `n8n:link` override the
 * mapping default; `n8n:ignore` excludes), plus the in-folder `ignored` mode when a
 * managed file is hand-tagged `n8n:ignore` in Nextcloud.
 *
 * Leans on the shared Support traits + ReconcileSteps' n8n REST seeding helpers
 * (`ensureN8nTag` / `createN8nWorkflow` / `setN8nWorkflowTags` / `propfindWorkflowIds`)
 * and MoveSteps' `a managed :mode workflow file in the :tag folder` / `the file's mode
 * becomes :mode`. Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait ReservedTagsSteps {
	/** The workflow whose pulled file the scenario asserts on. */
	private string $reservedTargetId = '';
	/** A plain (no-override) sibling under the same mapping tag, for the "siblings stay" check. */
	private string $reservedSiblingId = '';
	/** The tag names the seeded workflow started with (for the "never-written" check). */
	private array $reservedOriginalTags = [];

	// ── Given (seed workflows in n8n) ─────────────────────────────────────────

	/**
	 * Bare form: a workflow carrying only the mapping tag (no reserved override).
	 * Same seeding as the explicit "with no reserved tag" phrasing — the shorter
	 * wording is used where the scenario is about the mapping default itself
	 * (e.g. the no-`nextcloud:`-prefix case).
	 *
	 * @Given n8n has a workflow tagged :tag
	 */
	public function n8nHasAWorkflowTagged(string $tag): void {
		$this->n8nHasAWorkflowTaggedWithNoReservedTag($tag);
	}

	/** @Given n8n has a workflow tagged :tag with no reserved tag */
	public function n8nHasAWorkflowTaggedWithNoReservedTag(string $tag): void {
		$tagId = $this->ensureN8nTag($tag);
		$this->reservedTargetId = $this->createN8nWorkflow('Plain', [$tagId]);
		$this->reservedSiblingId = '';
		$this->reservedOriginalTags = [$tag];
	}

	/** @Given n8n has a workflow tagged :tag and :reserved */
	public function n8nHasAWorkflowTaggedAndReserved(string $tag, string $reserved): void {
		$tagId = $this->ensureN8nTag($tag);
		$reservedId = $this->ensureN8nTag($reserved);
		$this->reservedTargetId = $this->createN8nWorkflow('Override', [$tagId, $reservedId]);
		$this->reservedOriginalTags = [$tag, $reserved];
		// For an override (sync/link) seed a plain sibling so "siblings stay <default>"
		// has something to assert; n8n:ignore needs no sibling.
		$this->reservedSiblingId = $reserved === 'n8n:ignore'
			? ''
			: $this->createN8nWorkflow('Sibling', [$tagId]);
	}

	// ── When ──────────────────────────────────────────────────────────────────

	/** @When the :tag mapping is pulled */
	public function theMappingIsPulled(string $tag): void {
		$this->currentTag = $tag;
		$this->currentFolder = $this->folderNameForTag($tag);
		$this->runMappingSync('pull', $tag);
	}

	/** @When I tag it :reserved */
	public function iTagIt(string $reserved): void {
		$this->assignSystemTag($this->currentFilePath, $reserved);
	}

	/**
	 * Remove a system tag from the current file. Removing `n8n:ignore` un-ignores the
	 * file (TagUnassignedEvent → ModeChangeService::unignore, saga §14.8/§14.18).
	 *
	 * @When I remove the :reserved tag
	 */
	public function iRemoveTheTag(string $reserved): void {
		$fileId = $this->fileId($this->currentFilePath);
		$tagId = $this->ensureSystemTag($reserved);
		$res = $this->tagDavClient()->request('DELETE', 'systemtags-relations/files/' . $fileId . '/' . $tagId);
		Assert::assertContains($res->getStatusCode(), [201, 204, 404], 'remove tag failed: ' . (string)$res->getBody());
	}

	// ── Then (pull-time resolution) ───────────────────────────────────────────

	/**
	 * @Then that workflow's file is in :mode mode
	 * @Then /^that workflow's file is in "(?P<mode>[^"]+)" mode \(the mapping mode\)$/
	 * @Then that workflow's file is created in :mode mode
	 */
	public function thatWorkflowsFileIsInMode(string $mode): void {
		$path = $this->pulledFilePathForId($this->currentFolder, $this->reservedTargetId);
		Assert::assertNotNull($path, "no file was pulled for workflow {$this->reservedTargetId}");
		Assert::assertSame(
			$this->wireMode($mode),
			$this->davReadMetadata($path, self::META_MODE),
			"the pulled file is not in $mode mode",
		);
	}

	/** @Then sibling :tag workflows without an override stay :mode */
	public function siblingWorkflowsStay(string $tag, string $mode): void {
		Assert::assertNotSame('', $this->reservedSiblingId, 'no sibling workflow was seeded');
		$path = $this->pulledFilePathForId($this->folderNameForTag($tag), $this->reservedSiblingId);
		Assert::assertNotNull($path, "the sibling workflow {$this->reservedSiblingId} did not pull");
		Assert::assertSame($this->wireMode($mode), $this->davReadMetadata($path, self::META_MODE), "the sibling is not in $mode mode");
	}

	/**
	 * @Then that workflow is not pulled into Nextcloud
	 * @Then no file is created for it
	 */
	public function thatWorkflowIsNotPulled(): void {
		$path = $this->pulledFilePathForId($this->currentFolder, $this->reservedTargetId);
		Assert::assertNull($path, "an n8n:ignore workflow was pulled into the folder anyway ({$this->reservedTargetId})");
	}

	/** @Then the workflow in n8n still carries only its original tags */
	public function theWorkflowStillCarriesOnlyItsOriginalTags(): void {
		$names = $this->n8nWorkflowTagNames($this->reservedTargetId);
		sort($names);
		$expected = $this->reservedOriginalTags;
		sort($expected);
		Assert::assertSame($expected, $names, 'the workflow tags in n8n changed after a pull');
	}

	/** @Then the app has not added any :a, :b, or :c tag to it */
	public function theAppHasNotAddedAnyReservedTag(string $a, string $b, string $c): void {
		$names = $this->n8nWorkflowTagNames($this->reservedTargetId);
		foreach ([$a, $b, $c] as $reserved) {
			if (in_array($reserved, $this->reservedOriginalTags, true)) {
				continue; // a tag the workflow legitimately started with
			}
			Assert::assertNotContains($reserved, $names, "the app wrote the reserved tag '$reserved' onto the workflow");
		}
	}

	// ── Then (in-folder ignored mode) ─────────────────────────────────────────

	/** @Then the file stays in the mapped folder and keeps its :key */
	public function theFileStaysAndKeepsItsKey(string $key): void {
		Assert::assertTrue($this->davExists($this->currentFilePath), 'the ignored file was removed from the mapped folder');
		Assert::assertSame($this->lastWorkflowId, $this->davReadMetadata($this->currentFilePath, $key), "the ignored file lost its $key");
	}

	/** @Then the workflow is archived in n8n */
	public function theIgnoredWorkflowIsArchivedInN8n(): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id recorded for the ignored file');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone from n8n");
		Assert::assertTrue((bool)($wf['isArchived'] ?? false), 'the workflow was not archived in n8n');
	}

	/**
	 * Regex form (not turnip): a literal "/" in a turnip step is read as
	 * word-alternation (pulls|pushes), so it never matches the literal
	 * "pulls/pushes" in the Gherkin. The regex annotation matches it verbatim.
	 *
	 * @Then /^subsequent pulls\/pushes for "([^"]+)" skip it$/
	 */
	public function subsequentSyncsSkipIt(string $tag): void {
		$this->runMappingSync('pull', $tag);
		$this->runMappingSync('push', $tag);
		// Plain throw, not PHPUnit Assert: under Behat + PHPUnit 12 a failing assert
		// throws the opaque Registry::get() TypeError that masks the real message
		// (see WebDavTrait::assertStatus). Gather the observed state and report it.
		$exists = $this->davExists($this->currentFilePath);
		$mode = $exists ? $this->davReadMetadata($this->currentFilePath, self::META_MODE) : '(file gone)';
		$id = $exists ? $this->davReadMetadata($this->currentFilePath, self::META_ID) : '(file gone)';
		if (!$exists || $mode !== 'ignored' || $id !== $this->lastWorkflowId) {
			throw new \RuntimeException(sprintf(
				'a later sync did not leave the ignored file alone: exists=%s mode=%s (want ignored) id=%s (want %s) path=%s',
				$exists ? 'yes' : 'NO',
				(string)$mode,
				(string)$id,
				(string)$this->lastWorkflowId,
				$this->currentFilePath,
			));
		}
	}

	// ── helpers ────────────────────────────────────────────────────────────────

	/** n8n_mode in its DAV WIRE form (link is stored as `reference`; others are identity). */
	private function wireMode(string $mode): string {
		return $mode === 'link' ? 'reference' : $mode;
	}

	/** The files-root-relative path of the pulled file carrying $id, or null if absent. */
	private function pulledFilePathForId(string $folder, string $id): ?string {
		foreach ($this->propfindWorkflowIds($folder) as $href => $wid) {
			if ($wid === $id) {
				return $this->hrefToFilesPath((string)$href);
			}
		}
		return null;
	}

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
