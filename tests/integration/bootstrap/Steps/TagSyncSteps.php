<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Three-way tag-sync steps (`tag-sync.feature`, saga Ch5 §5.6): a workflow's n8n
 * tags and the file's Nextcloud system-tag pills kept as ONE set, minus the
 * reserved `n8n:` namespace. Exercises the shipped surfaces — pull mirror (sync +
 * link), push writeback, the `n8n_syncedTags` baseline that keeps adds/removes
 * straight, the deterministic three-way merge, the force-kept mapping tag, the
 * `n8n:ignore` eject, and edge-vs-catalog pruning. The body↔pills projection
 * (surfaces 2 & 3) and the optional catalog sweep stay `@todo`.
 *
 * Leans on the composed helpers: ReconcileSteps (`ensureN8nTag`,
 * `createN8nWorkflow`, `setN8nWorkflowTags`, `propfindWorkflowIds`,
 * `runMappingSync`), ReservedTagsSteps (`n8nWorkflowTagNames`, `hrefToFilesPath`),
 * ModeChangeSteps (`assignSystemTag`, `ensureSystemTag`, `fileId`,
 * `fileSystemTags`, `tagDavClient`), SetupTrait (`putManagedFile`,
 * `folderNameForTag`) and WebDavTrait. Composed into {@see
 * \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait TagSyncSteps {
	/** The n8n workflow id under test in a tag scenario. */
	private string $tagWfId = '';
	/** The managed file's files-root-relative path (resolved lazily after a pull). */
	private string $tagFilePath = '';
	/** A non-workflow file pinned with a shared tag, for the edge-vs-catalog prune check. */
	private string $tagUnrelatedFile = '';
	/** Snapshot of the NC system-tag catalog names, for the "no new definition" check. */
	private array $tagCatalogBefore = [];
	/** Snapshot of the n8n tag catalog names, for the "no new definition" check. */
	private array $tagN8nBefore = [];

	// ── Given: seed an n8n-only workflow (pull will create the file) ───────────

	/** @Given n8n has a workflow tagged :a, :b, and :c */
	public function n8nHasAWorkflowTaggedThree(string $a, string $b, string $c): void {
		$ids = array_map(fn (string $n): string => $this->ensureN8nTag($n), [$a, $b, $c]);
		$this->tagWfId = $this->createN8nWorkflow('TagWf', $ids);
		$this->tagFilePath = '';
	}

	// ── Given: managed files (a body already exists in the mapped folder) ──────

	/**
	 * A managed sync file whose n8n workflow carries :a and :b but which has NOT
	 * been tag-synced yet (empty baseline) — used by the push-writes-tags case.
	 *
	 * @Given a managed :mode workflow file in :tag with n8n tags :a and :b
	 */
	public function aManagedFileWithN8nTags(string $mode, string $tag, string $a, string $b): void {
		$this->tagArrangeManagedFile($tag, [$a, $b], false);
	}

	/**
	 * A managed sync file already tag-synced to :a and :b (baseline stamped via a
	 * pull) — the starting point for reconcile / mapping-tag-protection cases.
	 *
	 * @Given a managed :mode workflow file in :tag tagged :a and :b
	 */
	public function aManagedFileTaggedTwo(string $mode, string $tag, string $a, string $b): void {
		$this->tagArrangeManagedFile($tag, [$a, $b], true);
	}

	/**
	 * A managed sync file already tag-synced to a single tag (the mapping tag) —
	 * the starting point for the move-out and eject cases.
	 *
	 * @Given a managed :mode workflow file in :tag tagged :only
	 */
	public function aManagedFileTaggedOne(string $mode, string $tag, string $only): void {
		$this->tagArrangeManagedFile($tag, [$only], true);
	}

	/** @Given a managed :mode file last synced with tags :a and :b */
	public function aManagedFileLastSyncedTwo(string $mode, string $a, string $b): void {
		$this->tagArrangeManagedFile($a, [$a, $b], true);
	}

	/** @Given a managed :mode file last synced with tags :a, :b, and :c */
	public function aManagedFileLastSyncedThree(string $mode, string $a, string $b, string $c): void {
		$this->tagArrangeManagedFile($a, [$a, $b, $c], true);
	}

	// ── Given: mutate the two sides between the baseline and the reconcile ─────

	/** @Given the file now also has the Nextcloud system tag :tag */
	public function theFileNowAlsoHasTag(string $tag): void {
		$this->assignSystemTag($this->tagLocateFile(), $tag);
	}

	/** @Given the workflow in n8n still has only :a and :b */
	public function theWorkflowStillHasOnlyTwo(string $a, string $b): void {
		$this->tagSetN8n([$a, $b]);
	}

	/** @Given the workflow in n8n now has only :a and :b */
	public function theWorkflowNowHasOnlyTwo(string $a, string $b): void {
		$this->tagSetN8n([$a, $b]);
	}

	/** @Given the workflow in n8n still has :a, :b, and :c */
	public function theWorkflowStillHasThree(string $a, string $b, string $c): void {
		$this->tagSetN8n([$a, $b, $c]);
	}

	/** @Given the workflow in n8n now also has :tag */
	public function theWorkflowNowAlsoHas(string $tag): void {
		$names = $this->tagN8nContent($this->tagWfId);
		$names[] = $tag;
		$this->tagSetN8n($names);
	}

	/** @Given the Nextcloud system tag :tag is also pinned on an unrelated non-workflow file */
	public function aSharedTagPinnedOnAnUnrelatedFile(string $tag): void {
		$path = 'unrelated-' . bin2hex(random_bytes(3)) . '.txt';
		$this->davPut($path, 'not a workflow');
		$this->assignSystemTag($path, $tag);
		$this->tagUnrelatedFile = $path;
	}

	// ── When ───────────────────────────────────────────────────────────────────

	/** @When the :tag mapping is pushed */
	public function theMappingIsPushed(string $tag): void {
		$this->currentFolder = $this->folderNameForTag($tag);
		$this->runMappingSync('push', $tag);
	}

	/** @When the :tag mapping is reconciled */
	public function theMappingIsReconciled(string $tag): void {
		// A push runs the symmetric three-way merge and converges BOTH sides, so
		// it is the single-shot "reconcile" the spec means.
		$this->currentFolder = $this->folderNameForTag($tag);
		$this->runMappingSync('push', $tag);
	}

	/** @When the admin adds the Nextcloud system tag :tag to the file */
	public function theAdminAddsSystemTag(string $tag): void {
		$this->assignSystemTag($this->tagLocateFile(), $tag);
	}

	/**
	 * @When the admin removes the Nextcloud system tag :tag from the file
	 * @When the admin removes the :tag pill from the workflow file
	 */
	public function theAdminRemovesSystemTag(string $tag): void {
		$this->tagRemoveSystemTag($this->tagLocateFile(), $tag);
	}

	/** @When the admin tags the file :reserved */
	public function theAdminTagsTheFile(string $reserved): void {
		$this->assignSystemTag($this->tagLocateFile(), $reserved);
	}

	/** @When the file is moved out of the :tag mapped folder */
	public function theFileIsMovedOut(string $tag): void {
		$from = $this->tagLocateFile();
		$dest = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($dest);
		$to = $dest . '/' . basename($from);
		$this->davMove($from, $to);
		$this->tagFilePath = $to;
		$this->currentFilePath = $to;
	}

	// ── Then: Nextcloud pills ───────────────────────────────────────────────────

	/** @Then the workflow's file has the Nextcloud system tags :a and :b */
	public function theFileHasSystemTagsTwo(string $a, string $b): void {
		$pills = $this->tagContentPills($this->tagLocateFile());
		Assert::assertContains($a, $pills, "the file is missing the '$a' pill (has: " . implode(',', $pills) . ')');
		Assert::assertContains($b, $pills, "the file is missing the '$b' pill (has: " . implode(',', $pills) . ')');
	}

	/**
	 * @Then the workflow's file has the Nextcloud system tag :tag
	 * @Then the file still carries the :tag system tag
	 */
	public function theFileHasSystemTag(string $tag): void {
		$pills = $this->tagContentPills($this->tagLocateFile());
		Assert::assertContains($tag, $pills, "the file is missing the '$tag' pill (has: " . implode(',', $pills) . ')');
	}

	/** @Then the file has no content tag :tag */
	public function theFileHasNoContentTag(string $tag): void {
		$pills = $this->tagContentPills($this->tagLocateFile());
		Assert::assertNotContains($tag, $pills, "the file unexpectedly carries the '$tag' content pill");
	}

	/** @Then the file's Nextcloud system tags are exactly :a and :b */
	public function theFileTagsAreExactlyTwo(string $a, string $b): void {
		$expected = [$a, $b];
		sort($expected);
		Assert::assertSame($expected, $this->tagContentPills($this->tagLocateFile()), 'the pill set is not exactly the expected two');
	}

	/** @Then the unrelated file still carries the :tag pill */
	public function theUnrelatedFileStillCarries(string $tag): void {
		Assert::assertNotSame('', $this->tagUnrelatedFile, 'no unrelated file was seeded');
		Assert::assertContains($tag, $this->fileSystemTags($this->tagUnrelatedFile), "the unrelated file lost its '$tag' pill");
	}

	/** @Then the file can be found by a Nextcloud tag search for :tag */
	public function theFileIsFoundByTagSearch(string $tag): void {
		$path = $this->tagLocateFile();
		$want = $this->fileId($path);
		$tagId = $this->ensureSystemTag($tag);
		$body = '<?xml version="1.0"?>'
			. '<oc:filter-files xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:prop><oc:fileid/></d:prop>'
			. '<oc:filter-rules><oc:systemtag>' . $tagId . '</oc:systemtag></oc:filter-rules>'
			. '</oc:filter-files>';
		$res = $this->davClient()->request('REPORT', '', [
			'headers' => ['Depth' => 'infinity', 'Content-Type' => 'application/xml'],
			'body' => $body,
		]);
		Assert::assertSame(207, $res->getStatusCode(), 'tag-search REPORT failed: ' . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		$found = [];
		foreach ($doc->xpath('//oc:fileid') ?: [] as $n) {
			$found[] = (int)trim((string)$n);
		}
		Assert::assertContains($want, $found, "the file (id $want) was not returned by a tag search for '$tag'");
	}

	// ── Then: n8n side ──────────────────────────────────────────────────────────

	/** @Then the workflow in n8n is tagged :a and :b */
	public function theWorkflowIsTaggedTwo(string $a, string $b): void {
		$expected = [$a, $b];
		sort($expected);
		Assert::assertSame($expected, $this->tagN8nContent($this->tagWfId), 'the n8n content tags are not exactly the expected two');
	}

	/** @Then the workflow in n8n is tagged :a, :b, and :c */
	public function theWorkflowIsTaggedThree(string $a, string $b, string $c): void {
		$expected = [$a, $b, $c];
		sort($expected);
		Assert::assertSame($expected, $this->tagN8nContent($this->tagWfId), 'the n8n content tags are not exactly the expected three');
	}

	/** @Then the workflow in n8n still carries the :tag tag */
	public function theWorkflowStillCarries(string $tag): void {
		Assert::assertContains($tag, $this->n8nWorkflowTagNames($this->tagWfId), "the workflow in n8n lost the '$tag' tag");
	}

	/** @Then the :tag tag is gone from n8n */
	public function theTagIsGoneFromN8n(string $tag): void {
		Assert::assertNotContains($tag, $this->n8nWorkflowTagNames($this->tagWfId), "the '$tag' tag is still on the workflow in n8n");
	}

	/** @Then the reserved :pattern tags are not written to n8n */
	public function theReservedTagsAreNotWritten(string $pattern): void {
		foreach ($this->n8nWorkflowTagNames($this->tagWfId) as $name) {
			Assert::assertStringStartsNotWith('n8n:', $name, "a reserved tag '$name' was written to n8n");
		}
	}

	/** @Then :reserved is never written to n8n as a content tag */
	public function theReservedIsNeverWritten(string $reserved): void {
		Assert::assertNotContains($reserved, $this->n8nWorkflowTagNames($this->tagWfId), "the reserved tag '$reserved' was written to n8n");
	}

	// ── Then: both sides / merge outcomes ───────────────────────────────────────

	/** @Then the resulting tag set on both sides is :a, :b, and :c */
	public function theResultingSetIsThree(string $a, string $b, string $c): void {
		$this->assertBothSides([$a, $b, $c]);
	}

	/** @Then the resulting tag set on both sides is :a, :b, :c, and :d */
	public function theResultingSetIsFour(string $a, string $b, string $c, string $d): void {
		$this->assertBothSides([$a, $b, $c, $d]);
	}

	/** @Then the :tag tag is gone from both sides */
	public function theTagIsGoneFromBothSides(string $tag): void {
		Assert::assertNotContains($tag, $this->tagContentPills($this->tagLocateFile()), "the '$tag' pill survived on the file");
		Assert::assertNotContains($tag, $this->n8nWorkflowTagNames($this->tagWfId), "the '$tag' tag survived in n8n");
	}

	// ── Then: mapping-tag protection + eject ────────────────────────────────────

	/** @Then the file is still bound to the :tag mapping */
	public function theFileIsStillBound(string $tag): void {
		$path = $this->tagLocateFile();
		Assert::assertTrue($this->davExists($path), 'the file was pruned');
		Assert::assertSame('sync', $this->davReadMetadata($path, self::META_MODE), 'the file is no longer a sync mapping member');
		Assert::assertContains($tag, $this->tagContentPills($path), "the mapping tag '$tag' pill is gone");
	}

	/** @Then the file becomes :mode */
	public function theFileBecomesMode(string $mode): void {
		$path = $this->tagLocateFile();
		Assert::assertSame($mode, $this->davReadMetadata($path, self::META_MODE), "the file did not become '$mode'");
	}

	/** @Then the file is kept as a standalone copy, not pruned */
	public function theFileIsKeptStandalone(): void {
		Assert::assertTrue($this->davExists($this->tagLocateFile()), 'the file was pruned instead of kept');
	}

	/** @Then the workflow's :tag tag is handled by the unmap path, not the tag sync */
	public function theTagIsHandledByTheUnmapPath(string $tag): void {
		// The unmap path (motion) archives the workflow and stamps `unmapped`; the
		// tag sync must not have touched it. Assert the file is unmapped.
		Assert::assertSame('unmapped', $this->davReadMetadata($this->tagLocateFile(), self::META_MODE), 'the moved-out file is not unmapped');
	}

	// ── Then: pruning is an edge sweep, not a catalog GC ────────────────────────

	/** @Then the :tag system-tag definition still exists */
	public function theSystemTagDefinitionStillExists(string $tag): void {
		Assert::assertContains($tag, $this->allSystemTagNames(), "the '$tag' system-tag definition was deleted");
	}

	/** @Then no new tag definition is created on either side */
	public function noNewTagDefinitionIsCreated(): void {
		$this->assertNoNewDefinitions();
	}

	// ── helpers: arrange ────────────────────────────────────────────────────────

	/**
	 * Put a managed sync file into the mapping's folder, set the n8n workflow's
	 * tags to $names, and (when $synced) run a pull so `n8n_syncedTags` is stamped
	 * as the baseline. The first name is the mapping tag (it binds the folder).
	 *
	 * @param list<string> $names
	 */
	private function tagArrangeManagedFile(string $tag, array $names, bool $synced): void {
		$folder = $this->folderNameForTag($tag);
		$this->putManagedFile($folder . '/Tagged-' . bin2hex(random_bytes(3)) . '.n8n.json', 'Tagged');
		$this->tagWfId = $this->lastWorkflowId;
		$this->tagFilePath = $this->currentFilePath;
		$this->tagSetN8n($names);
		if ($synced) {
			$this->runMappingSync('pull', $tag);
		}
		$this->tagCatalogBefore = $this->allSystemTagNames();
		$this->tagN8nBefore = $this->allN8nTagNames();
	}

	// ── helpers: n8n tag mutation / reads ───────────────────────────────────────

	/**
	 * Set the workflow-under-test's n8n tags to exactly $names (ensuring each).
	 *
	 * @param list<string> $names
	 */
	private function tagSetN8n(array $names): void {
		Assert::assertNotSame('', $this->tagWfId, 'no workflow under test to tag');
		$ids = array_map(fn (string $n): string => $this->ensureN8nTag($n), array_values(array_unique($names)));
		$this->setN8nWorkflowTags($this->tagWfId, $ids);
	}

	/**
	 * The workflow's n8n tag names minus the reserved namespace, sorted.
	 *
	 * @return list<string>
	 */
	private function tagN8nContent(string $id): array {
		$names = array_filter($this->n8nWorkflowTagNames($id), static fn (string $n): bool => !str_starts_with($n, 'n8n:'));
		sort($names);
		return array_values($names);
	}

	// ── helpers: Nextcloud pill reads / writes ──────────────────────────────────

	/**
	 * The file's content pills (system tags minus the reserved namespace), sorted.
	 *
	 * @return list<string>
	 */
	private function tagContentPills(string $path): array {
		$names = array_filter($this->fileSystemTags($path), static fn (string $n): bool => !str_starts_with($n, 'n8n:'));
		sort($names);
		return array_values($names);
	}

	/** Unassign a system tag (by name) from the file at $path. 404 = already gone. */
	private function tagRemoveSystemTag(string $path, string $name): void {
		$fileId = $this->fileId($path);
		$tagId = $this->ensureSystemTag($name);
		$res = $this->tagDavClient()->request('DELETE', 'systemtags-relations/files/' . $fileId . '/' . $tagId);
		Assert::assertContains($res->getStatusCode(), [201, 204, 404], 'remove tag failed: ' . (string)$res->getBody());
	}

	/** Resolve (and cache) the managed file's path — after a pull, locate by workflow id. */
	private function tagLocateFile(): string {
		if ($this->tagFilePath !== '') {
			return $this->tagFilePath;
		}
		Assert::assertNotSame('', $this->tagWfId, 'no workflow under test recorded');
		foreach ($this->propfindWorkflowIds($this->currentFolder) as $href => $wid) {
			if ($wid === $this->tagWfId) {
				$this->tagFilePath = $this->hrefToFilesPath((string)$href);
				return $this->tagFilePath;
			}
		}
		throw new \RuntimeException("no pulled file for workflow {$this->tagWfId} in {$this->currentFolder}");
	}

	// ── helpers: both-sides + catalog snapshots ─────────────────────────────────

	/**
	 * Assert both the file's pills and the workflow's n8n content tags equal $set.
	 *
	 * @param list<string> $set
	 */
	private function assertBothSides(array $set): void {
		sort($set);
		Assert::assertSame($set, $this->tagContentPills($this->tagLocateFile()), 'the Nextcloud pills are not the expected merged set');
		Assert::assertSame($set, $this->tagN8nContent($this->tagWfId), 'the n8n tags are not the expected merged set');
	}

	/** Every Nextcloud system-tag display name (the whole catalog). @return list<string> */
	private function allSystemTagNames(): array {
		$res = $this->tagDavClient()->request('PROPFIND', 'systemtags', [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
				. '<d:prop><oc:display-name/></d:prop></d:propfind>',
		]);
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		$names = [];
		foreach ($doc->xpath('//oc:display-name') ?: [] as $n) {
			$v = trim((string)$n);
			if ($v !== '') {
				$names[] = $v;
			}
		}
		sort($names);
		return array_values(array_unique($names));
	}

	/** Every n8n tag name (the whole catalog). @return list<string> */
	private function allN8nTagNames(): array {
		$res = $this->n8nClient()->request('GET', 'tags?limit=250');
		$decoded = json_decode((string)$res->getBody(), true);
		$names = [];
		foreach ((array)($decoded['data'] ?? []) as $tag) {
			if (is_array($tag) && isset($tag['name']) && is_string($tag['name'])) {
				$names[] = $tag['name'];
			}
		}
		sort($names);
		return array_values(array_unique($names));
	}

	/** Assert neither catalog grew since the baseline snapshot. */
	private function assertNoNewDefinitions(): void {
		$ncNew = array_values(array_diff($this->allSystemTagNames(), $this->tagCatalogBefore));
		$n8nNew = array_values(array_diff($this->allN8nTagNames(), $this->tagN8nBefore));
		Assert::assertSame([], $ncNew, 'a new Nextcloud system-tag definition was minted: ' . implode(',', $ncNew));
		Assert::assertSame([], $n8nNew, 'a new n8n tag definition was minted: ' . implode(',', $n8nNew));
	}
}
