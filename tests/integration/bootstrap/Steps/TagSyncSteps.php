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
 * (Slice B: pill edits lockstep the body's `tags` array, and a hand-edit of that
 * array — even a bare `{name}` — reconciles to n8n and back onto the pills with
 * its id filled) is exercised live here too; only the optional catalog sweep
 * stays `@todo`.
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
	/** The queued-job class the reactive pill-edit path enqueues under async timing. */
	private const RECONCILE_TAGS_JOB = 'OCA\N8nSync\BackgroundJob\ReconcileTagsJob';

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
	/** All four surfaces as of the last "I note the current tag state", for "unchanged". */
	private ?array $tagStateSnapshot = null;

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
		$this->tagArrangeManagedFile($mode, $tag, [$a, $b], false);
	}

	/**
	 * A managed sync file already tag-synced to :a and :b (baseline stamped via a
	 * pull) — the starting point for reconcile / mapping-tag-protection cases.
	 *
	 * @Given a managed :mode workflow file in :tag tagged :a and :b
	 */
	public function aManagedFileTaggedTwo(string $mode, string $tag, string $a, string $b): void {
		$this->tagArrangeManagedFile($mode, $tag, [$a, $b], true);
	}

	/**
	 * A managed sync file already tag-synced to three tags (baseline stamped via a
	 * pull) — the starting point for the body-edit remove cases.
	 *
	 * @Given a managed :mode workflow file in :tag tagged :a, :b, and :c
	 */
	public function aManagedFileTaggedThree(string $mode, string $tag, string $a, string $b, string $c): void {
		$this->tagArrangeManagedFile($mode, $tag, [$a, $b, $c], true);
	}

	/**
	 * A managed sync file already tag-synced to :a and :b, whose JSON body already
	 * carries those tags (a pull writes the whole workflow, tags included) — the
	 * starting point for the body↔pills (Slice B) cases.
	 *
	 * @Given a managed :mode workflow file in :tag with body tags :a and :b
	 */
	public function aManagedFileWithBodyTagsTwo(string $mode, string $tag, string $a, string $b): void {
		$this->tagArrangeManagedFile($mode, $tag, [$a, $b], true);
	}

	/**
	 * A managed sync file already tag-synced to a single tag (the mapping tag) —
	 * the starting point for the move-out and eject cases.
	 *
	 * @Given a managed :mode workflow file in :tag tagged :only
	 */
	public function aManagedFileTaggedOne(string $mode, string $tag, string $only): void {
		$this->tagArrangeManagedFile($mode, $tag, [$only], true);
	}

	/** @Given a managed :mode file last synced with tags :a and :b */
	public function aManagedFileLastSyncedTwo(string $mode, string $a, string $b): void {
		$this->tagArrangeManagedFile($mode, $a, [$a, $b], true);
	}

	/** @Given a managed :mode file last synced with tags :a, :b, and :c */
	public function aManagedFileLastSyncedThree(string $mode, string $a, string $b, string $c): void {
		$this->tagArrangeManagedFile($mode, $a, [$a, $b, $c], true);
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

	/**
	 * Add ONE tag to the workflow in n8n, leaving the rest alone.
	 *
	 * Shares its body with the `now also has` phrasing above — Behat ignores the
	 * keyword when matching, so one function can honestly answer to both: `Given the
	 * workflow in n8n now also has "x"` sets a precondition, `When the tag "x" is added
	 * to the workflow in n8n` is the action under test. Same operation, two readings.
	 *
	 * @When the tag :tag is added to the workflow in n8n
	 */
	public function theTagIsAddedInN8n(string $tag): void {
		$this->theWorkflowNowAlsoHas($tag);
	}

	/**
	 * Remove ONE tag from the workflow in n8n, leaving the rest alone. The existing
	 * n8n-side steps all restate the WHOLE set ("now has only x and y"), which is fine
	 * for arranging but wrong for an action: a scenario about removing one tag should
	 * say that, not list what survives.
	 *
	 * @When the tag :tag is removed from the workflow in n8n
	 */
	public function theTagIsRemovedInN8n(string $tag): void {
		$names = array_values(array_filter(
			$this->tagN8nContent($this->tagWfId),
			static fn (string $n): bool => $n !== $tag,
		));
		$this->tagSetN8n($names);
	}

	/** @Given the Nextcloud system tag :tag is also pinned on an unrelated non-workflow file */
	public function aSharedTagPinnedOnAnUnrelatedFile(string $tag): void {
		$path = 'unrelated-' . bin2hex(random_bytes(3)) . '.txt';
		$this->davPut($path, 'not a workflow');
		$this->assignSystemTag($path, $tag);
		$this->tagUnrelatedFile = $path;
	}

	/**
	 * Edit the workflow body WITHOUT touching its `tags` array, then save — the
	 * ordinary case, and the one the acceptance test turns on. Changes a node name so
	 * the content genuinely differs (a byte-identical PUT would not exercise the
	 * writeback at all), leaves `tags` exactly as it found them, and drains the push.
	 *
	 * @When the admin edits the workflow's nodes and saves, leaving the tags array alone
	 */
	public function theAdminEditsTheNodesLeavingTagsAlone(): void {
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		$before = $wf['tags'] ?? null;

		$wf['nodes'] = [[
			'name' => 'Touched-' . bin2hex(random_bytes(3)),
			'type' => 'n8n-nodes-base.noOp',
			'typeVersion' => 1,
			'position' => [0, 0],
			'parameters' => new \stdClass(),
		]];
		Assert::assertSame($before, $wf['tags'] ?? null, 'this step must not alter the tags array');

		$this->davPut($path, json_encode($wf, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		// drainJobs() takes the class to drain — calling it bare was a fatal, not a
		// no-op. Both jobs a save can enqueue are drained so the step behaves the same
		// under either `timing`, rather than only under the one the caller happens to set.
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob');
		$this->drainJobs(self::RECONCILE_TAGS_JOB);
	}

	// ── the four-surface tag state: one step for the whole picture ──────────────

	/**
	 * Arrange the WHOLE tag state at once. Every `Given` in the direction scenarios
	 * starts from a converged state (all four columns equal), which is exactly what a
	 * managed file plus a pull produces — so this asserts the four columns match rather
	 * than trying to force them apart. If they diverge, the arrange is wrong and the
	 * scenario would be testing a fiction.
	 *
	 * The FIRST name is the mapping tag; it binds the workflow to the folder.
	 *
	 * WORDED DIFFERENTLY FROM THE `Then` ON PURPOSE — "starts as" vs "is". Behat matches
	 * a step by its TEXT and ignores the keyword, so an identical phrase under `@Given`
	 * and `@Then` is one duplicated definition, not two steps: Behat refuses to register
	 * the second and every scenario in the suite fails. That rule is written down in
	 * `.github/instructions/gherkin.instructions.md`, and it still caught me — arranging
	 * and asserting genuinely need different sentences.
	 *
	 * @Given /^the tag state starts as n8n "([^"]*)" \/ pills "([^"]*)" \/ body "([^"]*)" \/ agreed "([^"]*)"$/
	 */
	public function theTagStateIs(string $n8n, string $pills, string $body, string $agreed): void {
		$names = self::tagList($n8n);
		Assert::assertSame(
			[$names, $names, $names],
			[self::tagList($pills), self::tagList($body), self::tagList($agreed)],
			'a Given tag state must be converged — all four columns equal. Use a When to diverge them.',
		);
		Assert::assertNotEmpty($names, 'the first tag binds the folder, so the set cannot be empty');
		$this->tagArrangeManagedFile('sync', $names[0], $names, true);
		$this->assertTagState($n8n, $pills, $body, $agreed);
	}

	/**
	 * Assert the WHOLE tag state — the payoff step. Naming all four surfaces in one
	 * line is what makes a scenario readable as pre/post state instead of a list of
	 * pokes, and it means a regression in ANY surface fails the scenario that cares.
	 *
	 * @Then /^the tag state is n8n "([^"]*)" \/ pills "([^"]*)" \/ body "([^"]*)" \/ agreed "([^"]*)"$/
	 */
	public function theTagStateBecomes(string $n8n, string $pills, string $body, string $agreed): void {
		$this->assertTagState($n8n, $pills, $body, $agreed);
	}

	/**
	 * THE INVARIANT the whole third direction rests on: the file body's `tags` array
	 * never disagrees with the pills. Asserted on its own so a scenario can check it
	 * after each of several triggers without restating the full set every time.
	 *
	 * @Then the body agrees with the pills
	 */
	public function theBodyAgreesWithThePills(): void {
		$path = $this->tagLocateFile();
		$pills = $this->tagContentPills($path);
		sort($pills);
		$this->assertBodyTagArray($pills);
	}

	/** @Then the tag state is unchanged */
	public function theTagStateIsUnchanged(): void {
		Assert::assertNotNull($this->tagStateSnapshot, 'nothing was snapshotted to compare against');
		Assert::assertSame($this->tagStateSnapshot, $this->readTagState(), 'the tag state changed when it should not have');
	}

	/** @Given I note the current tag state */
	public function iNoteTheCurrentTagState(): void {
		$this->tagStateSnapshot = $this->readTagState();
	}

	/**
	 * Read all four surfaces. `agreed` comes off the DAV metadata property rather than
	 * the database, so the assertion goes through the same surface a client sees.
	 *
	 * @return array{n8n: list<string>, pills: list<string>, body: list<string>, agreed: list<string>}
	 */
	private function readTagState(): array {
		$path = $this->tagLocateFile();

		$n8n = $this->tagN8nContent($this->tagWfId);
		sort($n8n);
		$pills = $this->tagContentPills($path);
		sort($pills);

		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		$body = [];
		foreach ((array)($wf['tags'] ?? []) as $tag) {
			$name = is_array($tag) ? (string)($tag['name'] ?? '') : '';
			if ($name !== '' && !str_starts_with($name, 'n8n:')) {
				$body[] = $name;
			}
		}
		$body = array_values(array_unique($body));
		sort($body);

		$raw = $this->davReadMetadata($path, 'n8n_syncedTags') ?? '';
		$decoded = $raw === '' ? [] : json_decode($raw, true);
		$agreed = [];
		foreach (is_array($decoded) ? $decoded : [] as $name) {
			if (is_string($name) && $name !== '' && !str_starts_with($name, 'n8n:')) {
				$agreed[] = $name;
			}
		}
		sort($agreed);

		return ['n8n' => $n8n, 'pills' => $pills, 'body' => $body, 'agreed' => $agreed];
	}

	private function assertTagState(string $n8n, string $pills, string $body, string $agreed): void {
		$want = [
			'n8n' => self::tagList($n8n),
			'pills' => self::tagList($pills),
			'body' => self::tagList($body),
			'agreed' => self::tagList($agreed),
		];
		$got = $this->readTagState();
		// One assertion over all four so a failure reports the whole picture — which
		// column drifted is the first thing you want to know, and asserting them one by
		// one hides the other three behind the first failure.
		Assert::assertSame($want, $got, 'tag state mismatch (want vs got shown above)');
	}

	/** Split a comma list into a sorted, de-duplicated name list. "" → []. */
	private static function tagList(string $csv): array {
		$names = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $s): bool => $s !== ''));
		$names = array_values(array_unique($names));
		sort($names);
		return $names;
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

	/** @When the admin edits the file body's :field array to :a and :b */
	public function theAdminEditsBodyArrayTwo(string $field, string $a, string $b): void {
		$this->editBodyTagArray([$a, $b]);
	}

	/** @When the admin edits the file body's :field array to :a, :b, and :c */
	public function theAdminEditsBodyArrayThree(string $field, string $a, string $b, string $c): void {
		$this->editBodyTagArray([$a, $b, $c]);
	}

	/** @When the admin edits the file body's :field array to only :a */
	public function theAdminEditsBodyArrayOne(string $field, string $a): void {
		$this->editBodyTagArray([$a]);
	}

	/** @Given the push timing is :timing */
	public function thePushTimingIs(string $timing): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' timing --value=' . escapeshellarg($timing));
		Assert::assertSame(0, $res['exit'], "setting timing=$timing failed:\n{$res['output']}");
	}

	/** @Then a tag-reconcile job is queued for the file */
	public function aReconcileTagsJobIsQueued(): void {
		$res = $this->occ('background-job:list --class=' . escapeshellarg(self::RECONCILE_TAGS_JOB) . ' --output=json');
		$jobs = json_decode($res['output'], true);
		Assert::assertIsArray($jobs, "could not list background jobs:\n{$res['output']}");
		Assert::assertNotEmpty($jobs, 'no ReconcileTagsJob was queued by the pill edit');
	}

	/** @When the background queue runs */
	public function theBackgroundQueueRuns(): void {
		$this->drainJobs(self::RECONCILE_TAGS_JOB);
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
	 * @Then the file's Nextcloud system tags still include :tag
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

	/** @Then the file's Nextcloud system tags become :a and :b */
	public function theFileTagsBecomeTwo(string $a, string $b): void {
		$expected = [$a, $b];
		sort($expected);
		Assert::assertSame($expected, $this->tagContentPills($this->tagLocateFile()), 'the pill set did not become exactly the expected two');
	}

	/** @Then the file's Nextcloud system tags become :a, :b, and :c */
	public function theFileTagsBecomeThree(string $a, string $b, string $c): void {
		$expected = [$a, $b, $c];
		sort($expected);
		Assert::assertSame($expected, $this->tagContentPills($this->tagLocateFile()), 'the pill set did not become exactly the expected three');
	}

	/** @Then the file body's :field array becomes :a and :b */
	public function theBodyArrayBecomesTwo(string $field, string $a, string $b): void {
		$this->assertBodyTagArray([$a, $b]);
	}

	/** @Then the file body's :field array becomes :a, :b, and :c */
	public function theBodyArrayBecomesThree(string $field, string $a, string $b, string $c): void {
		$this->assertBodyTagArray([$a, $b, $c]);
	}

	/** @Then every tag in the file body carries an n8n id */
	public function everyBodyTagCarriesAnId(): void {
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		$tags = (array)($wf['tags'] ?? []);
		Assert::assertNotEmpty($tags, 'the body has no tags array to check for ids');
		foreach ($tags as $tag) {
			Assert::assertIsArray($tag, 'a body tag entry is not an object');
			$name = (string)($tag['name'] ?? '?');
			Assert::assertArrayHasKey('id', $tag, "the body tag '$name' has no n8n id");
			Assert::assertNotSame('', (string)$tag['id'], "the body tag '$name' has an empty n8n id");
		}
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

	/**
	 * @Then the workflow in n8n is tagged :a and :b
	 * @Then the workflow in n8n is tagged :a and :b without a manual push
	 * @Then the workflow in n8n is still tagged only :a and :b
	 */
	public function theWorkflowIsTaggedTwo(string $a, string $b): void {
		$expected = [$a, $b];
		sort($expected);
		Assert::assertSame($expected, $this->tagN8nContent($this->tagWfId), 'the n8n content tags are not exactly the expected two');
	}

	/**
	 * @Then the workflow in n8n is tagged :a, :b, and :c
	 * @Then the workflow in n8n is tagged :a, :b, and :c without a manual push
	 */
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

	/**
	 * @Then the file is still bound to the :tag mapping
	 * @Then the file stays mapped to :tag
	 */
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
	private function tagArrangeManagedFile(string $mode, string $tag, array $names, bool $synced): void {
		// The tag/body reconcile surface is sync-only; a non-sync mode here is an
		// authoring mistake, so fail loudly instead of silently arranging a sync file.
		Assert::assertSame('sync', $mode, "tag arrange only supports 'sync' files, got '$mode'");
		$folder = $this->folderNameForTag($tag);
		$this->currentFolder = $folder;
		$this->currentTag = $tag;
		$this->putManagedFile($folder . '/Tagged-' . bin2hex(random_bytes(3)) . '.n8n.json', 'Tagged');
		$this->tagWfId = $this->lastWorkflowId;
		// Do NOT trust the PUT path: a pull mirrors the workflow name onto the file,
		// renaming it. Resolve lazily by workflow id (see tagLocateFile) instead.
		$this->tagFilePath = '';
		$this->tagSetN8n($names);
		if ($synced) {
			$this->runMappingSync('pull', $tag);
		}
		$this->tagCatalogBefore = $this->allSystemTagNames();
		$this->tagN8nBefore = $this->allN8nTagNames();
	}

	/**
	 * Hand-edit the managed file's JSON body `tags` array to exactly $names, each
	 * a bare `{name}` object with NO id — exactly what a human editing the file
	 * would type — then save it over WebDAV and drain the writeback push so Slice B
	 * reconciles the body tags to n8n and back onto the pills (and fills the ids).
	 *
	 * @param list<string> $names
	 */
	private function editBodyTagArray(array $names): void {
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		$wf['tags'] = array_map(static fn (string $n): array => ['name' => $n], array_values($names));
		$this->davPut($path, json_encode($wf, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		// The save fires NodeWrittenEvent → PushWorkflowJob (async default). Draining
		// runs PushService::push → reconcileFromBody: body tags become the truth,
		// get pushed to n8n, and the canonical rows land back on the pills + body.
		// Under sync timing the push already ran inline, so this is a harmless no-op.
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob');
	}

	/**
	 * Assert the managed file's JSON body `tags` array carries exactly $names
	 * (reserved namespace excluded), sorted.
	 *
	 * @param list<string> $names
	 */
	private function assertBodyTagArray(array $names): void {
		sort($names);
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		$got = [];
		foreach ((array)($wf['tags'] ?? []) as $tag) {
			$name = is_array($tag) ? (string)($tag['name'] ?? '') : '';
			if ($name !== '' && !str_starts_with($name, 'n8n:')) {
				$got[] = $name;
			}
		}
		sort($got);
		Assert::assertSame($names, array_values(array_unique($got)), 'the body tags array is not the expected set (has: ' . implode(',', $got) . ')');
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
