<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Tag-change steps (`workflows/tags.feature`, saga Ch5 §5.6): a workflow's tags
 * changed on one surface, and what that change does to the others.
 *
 * ## THE VOCABULARY IS A SET, NOT A POKE
 *
 * Every gesture here is "the tags are now THIS", never "add x" / "remove y". That
 * is the behaviour as a person experiences it — you edit a list and save it — and
 * it is what lets one Scenario Outline cover adding, subtracting and doing both at
 * once as rows of a table rather than as three scenarios each. The steps take a
 * comma list and diff it against what is there; which individual tags moved is an
 * implementation detail of the step, not of the spec.
 *
 * NORMAL tags only. The MAPPING TAG is excluded from every set in this file: it is
 * the binding between workflow and folder, not a label, and a scenario that put it
 * in its `Examples` column would be asserting the binding survived rather than
 * asserting anything about tags. It is added back automatically by the arrange and
 * stripped from every assertion; the two scenarios that are ABOUT it name it
 * explicitly. Reserved `n8n:*` tags are stripped the same way, for the same reason.
 *
 * ## THE SYNC IS FOLDED INTO THE GESTURE, DELIBERATELY
 *
 * `When the "flows" mapping is pulled` is a MECHANISM, not a behaviour — nobody
 * changes a tag in order to run a reconcile, and a spec written that way has to be
 * rewritten every time the plumbing moves. n8n emits no outbound event, so a pull
 * is simply HOW the news of an n8n-side change arrives; it belongs to the gesture.
 * So `the workflow's tags are changed to … in n8n` changes them AND settles the
 * mirror, and the scenario says only what a person did and what came of it.
 *
 * TIMING IS NOT IN THE SPEC EITHER, for the same reason. Whether the writeback
 * runs during the request or on the worker's next tick is our plumbing; the
 * behaviour is that the change arrives. The arrange pins it so one scenario does
 * not inherit whatever the previous one left behind.
 *
 * Leans on the composed helpers: SyncSteps (`ensureN8nTag`, `createN8nWorkflow`,
 * `setN8nWorkflowTags`, `propfindWorkflowIds`, `runMappingSync`), ReservedTagsSteps
 * (`n8nWorkflowTagNames`, `hrefToFilesPath`), ModeChangeSteps (`assignSystemTag`,
 * `ensureSystemTag`, `fileId`, `fileSystemTags`, `tagDavClient`), SetupTrait
 * (`putManagedFile`, `folderNameForTag`) and WebDavTrait. Composed into {@see
 * \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait TagSyncSteps {
	/** The n8n workflow id under test in a tag scenario. */
	private string $tagWfId = '';
	/** The managed file's files-root-relative path (resolved lazily after a pull). */
	private string $tagFilePath = '';
	/** The mapping tag binding the file under test — excluded from every "normal" set. */
	private string $tagMappingTag = '';
	/** A non-workflow file pinned with a shared tag, for the edge-vs-catalog prune check. */
	private string $tagUnrelatedFile = '';
	/** Snapshot of the NC system-tag catalog names, for the "no new definition" check. */
	private array $tagCatalogBefore = [];
	/** Snapshot of the n8n tag catalog names, for the "no new definition" check. */
	private array $tagN8nBefore = [];
	/** The mirror's body before an n8n-side change — "what else did the pull touch?". */
	private string $tagBodyBefore = '';

	// ── Given: the mirror, and the tags it starts with ─────────────────────────

	/**
	 * A mirrored workflow whose NORMAL tags are exactly $tags, converged on every
	 * surface — which is what a managed file plus one pull produces.
	 *
	 * Both modes are arranged here rather than in two steps, because "which mode"
	 * is a value and the difference is entirely in how the mirror comes to exist: a
	 * `sync` file is written into the folder and pulled, a `link` has no bytes of
	 * its own so its workflow is seeded in n8n and the pull mints the pointer.
	 *
	 * @Given a managed :mode workflow file in :mapping whose normal tags are :tags
	 */
	public function aManagedFileWhoseNormalTagsAre(string $mode, string $mapping, string $tags): void {
		// TIMING IS NOT IN THE SPEC, AND IS PINNED HERE INSTEAD. Whether the change
		// reaches n8n during the request or on the worker's next tick is an
		// implementation detail of this app — the behaviour is that it arrives, and
		// a scenario that said "async" would be describing our plumbing rather than
		// anything a person does. The harness still has to pin it, or every scenario
		// inherits whatever the one before it left behind.
		$this->setPushTiming('sync');
		$normal = self::tagList($tags);
		$this->tagMappingTag = $mapping;
		$this->currentFolder = $this->folderNameForTag($mapping);
		$this->currentTag = $mapping;
		$this->tagFilePath = '';

		if ($mode === 'sync') {
			$this->putManagedFile($this->currentFolder . '/Tagged-' . bin2hex(random_bytes(3)) . '.n8n.json', 'Tagged');
			$this->tagWfId = $this->lastWorkflowId;
		} else {
			// A link holds no body, so there is nothing to PUT: the workflow is the
			// only thing that exists until the pull mints a pointer for it.
			$this->tagWfId = $this->createN8nWorkflow('Tagged-' . bin2hex(random_bytes(3)), []);
		}

		$this->tagSetN8n(array_merge([$mapping], $normal));
		$this->runMappingSync('pull', $mapping);

		// Snapshotted AFTER the arrange so "no new definition" means "none minted by
		// the gesture under test", not "none minted by the fixture".
		$this->tagCatalogBefore = $this->allSystemTagNames();
		$this->tagN8nBefore = $this->allN8nTagNames();
	}

	/**
	 * A managed sync file moved OUT of its mapping: still carries its n8n id, but the
	 * workflow is archived and no mapping owns it.
	 *
	 * @Given a workflow file that has become "unmapped"
	 */
	public function aWorkflowFileThatHasBecomeUnmapped(): void {
		$this->aManagedFileWhoseNormalTagsAre('sync', 'flows', 'foo');
		$from = $this->tagLocateFile();
		$dest = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($dest);
		$to = $dest . '/' . basename($from);
		$this->davMove($from, $to);
		$this->tagFilePath = $to;
		$this->currentFilePath = $to;
		// Snapshot AFTER the move-out: the unmap archives and untags the workflow in
		// n8n, so a snapshot taken before it would attribute the unmap's own side
		// effects to the tag change under test.
		$this->tagN8nBefore = $this->tagN8nContent($this->tagWfId);
	}

	/** @Given the Nextcloud system tag :tag is also pinned on an unrelated non-workflow file */
	public function aSharedTagPinnedOnAnUnrelatedFile(string $tag): void {
		$path = 'unrelated-' . bin2hex(random_bytes(3)) . '.txt';
		$this->davPut($path, 'not a workflow');
		$this->assignSystemTag($path, $tag);
		$this->tagUnrelatedFile = $path;
	}

	/** Pin how the writeback runs. A harness concern; see the arrange above. */
	private function setPushTiming(string $timing): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' timing --value=' . escapeshellarg($timing));
		Assert::assertSame(0, $res['exit'], "setting timing=$timing failed:\n{$res['output']}");
	}

	// ── When: the tags are changed, on one surface ─────────────────────────────

	/**
	 * Change the Nextcloud tags to exactly $tags — the pill gesture, as a SET.
	 *
	 * The mapping tag is preserved: it is not a label the user is editing, and a
	 * step that silently dropped it would unbind the workflow as a side effect of
	 * every scenario in this file.
	 *
	 * TWO PHRASINGS, ONE FUNCTION. `I have changed …` is the same gesture read as a
	 * pre-state — a scenario needs both when the Nextcloud change is the ARRANGE and
	 * something else is the action. Behat ignores the keyword when matching, so the
	 * two sentences must genuinely differ or they register as one duplicate step and
	 * fail every scenario in the suite.
	 *
	 * @When I change the Nextcloud tags to :tags
	 * @Given I have changed the Nextcloud tags to :tags
	 */
	public function iChangeTheNextcloudTagsTo(string $tags): void {
		$this->applyNextcloudTags(self::tagList($tags));
	}

	/**
	 * Remove the MAPPING tag itself — the one Nextcloud-side tag change that is not
	 * about labelling, and the only place this file names the binding.
	 *
	 * Settles the mapping afterwards for the same reason the n8n steps do: the
	 * question is what the mirror looks like once the change has been dealt with,
	 * not whether a particular listener fired inside the request.
	 *
	 * @When I remove the :tag mapping tag in Nextcloud
	 */
	public function iRemoveTheMappingTag(string $tag): void {
		$this->tagRemoveSystemTag($this->tagLocateFile(), $tag);
		$this->runMappingSync('push', $tag);
	}

	/**
	 * Change the tags by editing the FILE — each written as a bare `{name}` with no
	 * id, exactly what a human typing into the JSON produces. Resolving those names
	 * to n8n ids is the app's job, and one of the things this proves.
	 *
	 * @When I change the tags in the file to :tags
	 */
	public function iChangeTheTagsInTheFileTo(string $tags): void {
		$names = self::tagList($tags);
		if ($this->tagMappingTag !== '') {
			array_unshift($names, $this->tagMappingTag);
		}
		$path = $this->tagLocateFile();
		// Object decode: an assoc round-trip flattens the empty `connections` and
		// `settings` objects to `[]`, which n8n rejects on the next push — the same
		// object-vs-array pitfall `PushService::pushViaApi` is documented against.
		$wf = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		Assert::assertInstanceOf(\stdClass::class, $wf, "managed file at $path is not a JSON object");
		$wf->tags = array_map(static fn (string $n): object => (object)['name' => $n], array_values(array_unique($names)));
		$this->davPut($path, json_encode($wf, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		// The save fires NodeWrittenEvent → PushWorkflowJob (async default). Under
		// sync timing the push already ran inline, so this is a harmless no-op.
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob');
	}

	/**
	 * Change the tags in n8n to exactly $tags, and let the news reach Nextcloud.
	 *
	 * The pull is part of the gesture, not a step of its own — see the trait
	 * docblock. The body is noted first so `nothing else in the file changed` can
	 * say what the arriving change did NOT touch.
	 *
	 * @When the workflow's tags are changed to :tags in n8n
	 */
	public function theWorkflowsTagsAreChangedToInN8n(string $tags): void {
		$names = self::tagList($tags);
		if ($this->tagMappingTag !== '') {
			array_unshift($names, $this->tagMappingTag);
		}
		$this->tagNoteBody();
		$this->tagSetN8n($names);
		$this->tagSettle();
	}

	/**
	 * Add ONE tag in n8n, leaving the rest alone — the delta form, for scenarios
	 * where the point is that a change made in n8n meets a change already made in
	 * Nextcloud. Restating the whole set there would quietly overwrite the other
	 * side's edit inside the arrange and prove nothing.
	 *
	 * @When the tag :tag is added to the workflow in n8n
	 */
	public function theTagIsAddedInN8n(string $tag): void {
		$names = $this->n8nWorkflowTagNames($this->tagWfId);
		$names[] = $tag;
		$this->tagNoteBody();
		$this->tagSetN8n($names);
		$this->tagSettle();
	}

	/**
	 * Remove ONE tag in n8n, leaving the rest alone. Also the gesture that strips a
	 * MAPPING tag, which is why it must not filter reserved or mapping names out.
	 *
	 * @When the tag :tag is removed from the workflow in n8n
	 */
	public function theTagIsRemovedInN8n(string $tag): void {
		$names = array_values(array_filter(
			$this->n8nWorkflowTagNames($this->tagWfId),
			static fn (string $n): bool => $n !== $tag,
		));
		$this->tagNoteBody();
		$this->tagSetN8n($names);
		$this->tagSettle();
	}

	// ── Then: the tags, everywhere ─────────────────────────────────────────────

	/**
	 * THE PAYOFF STEP: the normal tags are $tags on every surface at once — n8n, the
	 * Nextcloud pills, and the file's own `tags` array.
	 *
	 * Asserted as ONE comparison over all three rather than three assertions in a
	 * row, because which surface drifted is the first thing you want to know and
	 * asserting them one at a time hides the other two behind the first failure.
	 *
	 * THE IDS ARE PART OF THE END STATE, NOT A SCENARIO. A tag n8n has never seen
	 * has no id, and a human editing the JSON writes `{"name": "prod"}` with none —
	 * so "the file's rows are canonical `{id,name}` again" is simply what a settled
	 * tag change looks like, whichever surface it started on. It used to be a `@todo`
	 * scenario of its own, which said no more than this line does and cost a whole
	 * live run to say it.
	 *
	 * @Then the workflow's normal tags are :tags in n8n and in Nextcloud
	 */
	public function theNormalTagsAreEverywhere(string $tags): void {
		$want = self::tagList($tags);
		$got = $this->readNormalTags();
		Assert::assertSame(
			['n8n' => $want, 'pills' => $want, 'body' => $want],
			$got,
			'the tag surfaces disagree (want vs got shown above)',
		);
		$this->assertBodyTagsCarryIds();
	}

	/**
	 * Every row in the file's `tags` array carries a non-empty n8n id.
	 *
	 * Tolerates an empty array on purpose: emptying the tags is one of the gestures
	 * under test, and "no rows" is a correct end state rather than a missing id.
	 */
	private function assertBodyTagsCarryIds(): void {
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		foreach ((array)($wf['tags'] ?? []) as $tag) {
			Assert::assertIsArray($tag, 'a body tag entry is not an object');
			$name = (string)($tag['name'] ?? '?');
			Assert::assertNotSame('', (string)($tag['id'] ?? ''), "the body tag '$name' carries no n8n id");
		}
	}

	/**
	 * n8n did NOT move. Used where the change is not supposed to travel — a link, or
	 * a queued push that has not run yet — and asserted against n8n's own API rather
	 * than against anything this app reports, so it cannot pass by the app agreeing
	 * with itself.
	 *
	 * @Then the workflow's normal tags are still :tags in n8n
	 */
	public function theNormalTagsAreStillInN8n(string $tags): void {
		Assert::assertSame(
			self::tagList($tags),
			$this->tagNormal($this->tagN8nContent($this->tagWfId)),
			"n8n's tags changed when they should not have",
		);
	}

	/**
	 * A link's tags are n8n's, so a local change to them does not merely fail to
	 * push — it does not survive at all. n8n is the only writer, and the next sync
	 * restores what it says.
	 *
	 * @Then the file's tags settle back to :tags
	 */
	public function theFilesTagsSettleBackTo(string $tags): void {
		$this->tagSettle();
		Assert::assertSame(
			self::tagList($tags),
			$this->tagNormal($this->tagContentPills($this->tagLocateFile())),
			'a locally-added tag survived on a link',
		);
	}

	/**
	 * Everything except the tags array is byte-identical to what was there before the
	 * change arrived. A tags-only change in n8n must not smuggle in other edits — and
	 * comparing the tag-stripped bodies says that without caring how the tags
	 * themselves are shaped (n8n fills ids in, the file may have carried bare names).
	 *
	 * @Then nothing else in the file changed
	 */
	public function nothingElseInTheFileChanged(): void {
		Assert::assertNotSame('', $this->tagBodyBefore, 'no mirror body was noted before the change');
		Assert::assertSame(
			self::bodyWithoutTags($this->tagBodyBefore),
			self::bodyWithoutTags($this->davGet($this->tagLocateFile())),
			'the change moved more than the tags array',
		);
	}

	/**
	 * THE INVARIANT the file-edit direction rests on: the file body's `tags` array
	 * never disagrees with the pills. Asserted on its own where there is no n8n side
	 * to compare against.
	 *
	 * @Then the body agrees with the pills
	 */
	public function theBodyAgreesWithThePills(): void {
		$got = $this->readNormalTags();
		Assert::assertSame($got['pills'], $got['body'], "the file's tags array and its pills disagree");
	}

	/** @Then the file has no content tag :tag */
	public function theFileHasNoContentTag(string $tag): void {
		$pills = $this->tagContentPills($this->tagLocateFile());
		Assert::assertNotContains($tag, $pills, "the file unexpectedly carries the '$tag' content pill");
	}

	/** @Then the file still carries the :tag mapping tag */
	public function theFileStillCarriesTheMappingTag(string $tag): void {
		Assert::assertContains(
			$tag,
			$this->tagContentPills($this->tagLocateFile()),
			"the mapping tag '$tag' was removed from the file",
		);
	}

	/** @Then the workflow in n8n still carries the :tag tag */
	public function theWorkflowStillCarries(string $tag): void {
		Assert::assertContains($tag, $this->n8nWorkflowTagNames($this->tagWfId), "the workflow in n8n lost the '$tag' tag");
	}

	/**
	 * The mirror is gone. Resolved by workflow id across the folder rather than by
	 * the cached path, because "pruned" is exactly the case where the cached path
	 * would still resolve to a name nothing owns.
	 *
	 * @Then the file is gone from the mapped folder
	 */
	public function theFileIsGoneFromTheMappedFolder(): void {
		foreach ($this->propfindWorkflowIds($this->currentFolder) as $href => $wid) {
			if ($wid === $this->tagWfId) {
				throw new \RuntimeException(
					"workflow {$this->tagWfId} is still mirrored at " . $this->hrefToFilesPath((string)$href),
				);
			}
		}
		$this->addToAssertionCount(1);
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

	// ── Then: nothing reached n8n ──────────────────────────────────────────────

	/**
	 * Nothing reached n8n. Compares the workflow's tag set against the snapshot taken
	 * when the file became unmapped — asserting on the SET rather than on "no request
	 * was made", because the observable that matters is n8n being unchanged, and a
	 * request-counting assertion would pass just as happily if we sent a no-op write.
	 *
	 * @Then no tag push to n8n is triggered
	 */
	public function noTagPushToN8nIsTriggered(): void {
		$before = $this->tagN8nBefore;
		$after = $this->tagN8nContent($this->tagWfId);
		sort($before);
		sort($after);
		Assert::assertSame($before, $after, "n8n's tags changed for an unmapped file");
	}

	// ── Then: pruning is an edge sweep, not a catalog GC ────────────────────────

	/** @Then the :tag system-tag definition still exists */
	public function theSystemTagDefinitionStillExists(string $tag): void {
		Assert::assertContains($tag, $this->allSystemTagNames(), "the '$tag' system-tag definition was deleted");
	}

	/** @Then the unrelated file still carries the :tag pill */
	public function theUnrelatedFileStillCarries(string $tag): void {
		Assert::assertNotSame('', $this->tagUnrelatedFile, 'no unrelated file was seeded');
		Assert::assertContains($tag, $this->fileSystemTags($this->tagUnrelatedFile), "the unrelated file lost its '$tag' pill");
	}

	/** @Then no new tag definition is created on either side */
	public function noNewTagDefinitionIsCreated(): void {
		$ncNew = array_values(array_diff($this->allSystemTagNames(), $this->tagCatalogBefore));
		$n8nNew = array_values(array_diff($this->allN8nTagNames(), $this->tagN8nBefore));
		Assert::assertSame([], $ncNew, 'a new Nextcloud system-tag definition was minted: ' . implode(',', $ncNew));
		Assert::assertSame([], $n8nNew, 'a new n8n tag definition was minted: ' . implode(',', $n8nNew));
	}

	// ── helpers: reading the three surfaces ────────────────────────────────────

	/**
	 * The normal tags on all three surfaces, each sorted — reserved `n8n:*` and the
	 * mapping tag stripped, since neither is a label anyone applied.
	 *
	 * @return array{n8n: list<string>, pills: list<string>, body: list<string>}
	 */
	private function readNormalTags(): array {
		$path = $this->tagLocateFile();

		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");
		$body = [];
		foreach ((array)($wf['tags'] ?? []) as $tag) {
			$name = is_array($tag) ? (string)($tag['name'] ?? '') : '';
			if ($name !== '') {
				$body[] = $name;
			}
		}

		return [
			'n8n' => $this->tagNormal($this->tagN8nContent($this->tagWfId)),
			'pills' => $this->tagNormal($this->tagContentPills($path)),
			'body' => $this->tagNormal($body),
		];
	}

	/**
	 * Drop the mapping tag (and anything reserved that slipped through), sort, dedupe.
	 *
	 * @param list<string> $names
	 * @return list<string>
	 */
	private function tagNormal(array $names): array {
		$out = array_values(array_unique(array_filter(
			$names,
			fn (string $n): bool => $n !== '' && $n !== $this->tagMappingTag && !str_starts_with($n, 'n8n:'),
		)));
		sort($out);
		return $out;
	}

	/** Split a comma list into a sorted, de-duplicated name list. "" → []. */
	private static function tagList(string $csv): array {
		$names = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $s): bool => $s !== ''));
		$names = array_values(array_unique($names));
		sort($names);
		return $names;
	}

	/** A decoded workflow body with its `tags` array dropped, re-encoded canonically. */
	private static function bodyWithoutTags(string $json): string {
		$wf = json_decode($json, true);
		Assert::assertIsArray($wf, 'workflow body is not JSON');
		unset($wf['tags']);
		return json_encode($wf, JSON_THROW_ON_ERROR);
	}

	// ── helpers: making a change ───────────────────────────────────────────────

	/**
	 * Assign and unassign pills until the file's normal tags are exactly $normal.
	 * The mapping tag is left alone — removing it is a different gesture with its own
	 * step, and doing it here as a side effect would unbind the workflow.
	 *
	 * @param list<string> $normal
	 */
	private function applyNextcloudTags(array $normal): void {
		$path = $this->tagLocateFile();
		$have = $this->tagNormal($this->tagContentPills($path));
		foreach (array_diff($normal, $have) as $add) {
			$this->assignSystemTag($path, $add);
		}
		foreach (array_diff($have, $normal) as $drop) {
			$this->tagRemoveSystemTag($path, $drop);
		}
	}

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

	/** Let a change made in n8n reach Nextcloud — the pull, folded into the gesture. */
	private function tagSettle(): void {
		Assert::assertNotSame('', $this->tagMappingTag, 'no mapping under test to settle');
		$this->runMappingSync('pull', $this->tagMappingTag);
	}

	/** Pin the mirror's body, so a later step can say what a change did NOT touch. */
	private function tagNoteBody(): void {
		$this->tagBodyBefore = $this->davGet($this->tagLocateFile());
	}

	// ── helpers: n8n / Nextcloud reads ─────────────────────────────────────────

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
}
