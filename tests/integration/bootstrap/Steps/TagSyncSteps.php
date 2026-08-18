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
 * explicitly. There is no reserved namespace any more — the app writes no tags of
 * its own — so nothing else is hidden from an assertion.
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
	 * NAMED BY ITS FOLDER, and the mapping tag is read back from the live store.
	 * A mapping IS its n8n tag, so naming the tag here would have read fine too —
	 * but `move.feature` and `delete.feature` already say "in Automations", the
	 * Background declares the folder in its table, and one vocabulary across the
	 * suite is worth more than the shortest sentence in one file. Tags are named
	 * where a tag is what the gesture is about.
	 *
	 * @Given a managed :mode workflow file in :folder whose normal tags are :tags
	 */
	public function aManagedFileWhoseNormalTagsAre(string $mode, string $folder, string $tags): void {
		// TIMING IS NOT IN THE SPEC, AND IT IS NOT PINNED ANY MORE EITHER. This used to
		// force `timing=sync` so the reconcile happened inline and the assertions could
		// read it straight back. That knob is gone (saga Ch5) — inline-vs-queued is now
		// derived from the environment — so the arrange stops asserting HOW the change
		// travels and the Then-steps drain the job instead. Same behaviour graded, one
		// less thing the harness has to hold still.
		$normal = self::tagList($tags);
		$mapping = $this->tagForFolder($folder);
		$this->tagMappingTag = $mapping;
		$this->currentFolder = $folder;
		$this->currentTag = $mapping;
		$this->tagFilePath = '';

		if ($mode === 'sync') {
			$this->putManagedFile($this->currentFolder . '/Tagged-' . bin2hex(random_bytes(3)) . '.n8n', 'Tagged');
			$this->tagWfId = $this->lastWorkflowId;
		} else {
			// A link holds no body, so there is nothing to PUT: the workflow is the
			// only thing that exists until the pull mints a pointer for it.
			$this->tagWfId = $this->createN8nWorkflow('Tagged-' . bin2hex(random_bytes(3)), []);
		}

		$this->tagSetN8n(array_merge([$mapping], $normal));
		$this->runMappingSync('pull', $mapping);
	}

	/**
	 * A managed sync file moved OUT of its mapping: still carries its n8n id, but the
	 * workflow is archived and no mapping owns it.
	 *
	 * @Given a workflow file that has become "unmapped"
	 */
	public function aWorkflowFileThatHasBecomeUnmapped(): void {
		$this->aManagedFileWhoseNormalTagsAre('sync', 'Flows', 'foo');
		$from = $this->tagLocateFile();
		$dest = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($dest);
		$to = $dest . '/' . basename($from);
		$this->davMove($from, $to);
		$this->tagFilePath = $to;
		$this->currentFilePath = $to;
	}

	// ── When: the tags are changed, on one surface ─────────────────────────────

	/**
	 * Change the Nextcloud tags to exactly $tags — the pill gesture, as a SET.
	 *
	 * The mapping tag is preserved: it is not a label the user is editing, and a
	 * step that silently dropped it would unbind the workflow as a side effect of
	 * every scenario in this file.
	 *
	 * ONE PHRASING, AND IT IS A `When`. There used to be a past-tense twin here for
	 * scenarios that needed the tag change as an arrange — but a Given states what is
	 * TRUE, and "I have changed the tags" is an action wearing the past tense. Any
	 * scenario needing that pre-state should say what the tags ARE instead, which the
	 * arrange already takes as an argument.
	 *
	 * @When I change the Nextcloud tags to :tags
	 */
	public function iChangeTheNextcloudTagsTo(string $tags): void {
		$this->applyNextcloudTags(self::tagList($tags));
		$this->settlePillChange();
	}

	/**
	 * Remove the MAPPING tag itself — the one Nextcloud-side tag change that is not
	 * about labelling, and the only place this file names the binding.
	 *
	 * Deliberately does NOT run a sync afterwards. The unbind is REACTIVE: dropping
	 * the tag takes the workflow out of the mapping there and then, and a sync here
	 * would hide whether that happened by doing the same work a second time.
	 *
	 * @When I remove the :tag mapping tag in Nextcloud
	 */
	public function iRemoveTheMappingTag(string $tag): void {
		$path = $this->tagLocateFile();
		$this->tagRemoveSystemTag($path, $tag);
		$this->settlePillChange();
		// The mirror is expected to be gone now, so the cached path must not be reused
		// as though it still resolved.
		$this->tagFilePath = '';
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
	 * THE PAYOFF, ONE SURFACE PER SENTENCE.
	 *
	 * These were one step asserting all three at once, which read as a single
	 * sentence containing an "and" — and it was three checks wearing one name. A
	 * settled tag change means the tags are on the pills, in the file, and in n8n,
	 * so a scenario says that in three lines and a failure names the surface that
	 * drifted in its own right.
	 *
	 * @Then the workflow's normal tags are :tags in Nextcloud
	 */
	public function theNormalTagsAreInNextcloud(string $tags): void {
		Assert::assertSame(
			self::tagList($tags),
			$this->tagNormal($this->tagContentPills($this->tagLocateFile())),
			"the file's Nextcloud tags are not the expected set",
		);
	}

	/**
	 * The file's own `tags` array — the third surface, and the only one that
	 * survives the file being copied or carried out of Nextcloud.
	 *
	 * THE IDS ARE DELIBERATELY NOT ASSERTED HERE, and CI is why. Asserting them read
	 * well — canonical `{id,name}` rows as the mark of a settled change — and it
	 * failed every row of the file-edit outline: a hand-edited file keeps the bare
	 * `{"name": …}` rows the person typed, and only a later sync from n8n rewrites
	 * them. That is the documented design (the file is briefly "incomplete" in a way
	 * that self-corrects), so the assertion was wrong, not the app. What this step
	 * owns is the SET; the id shape is asserted where it is the point, on an unmapped
	 * file that has no n8n to mint one.
	 *
	 * @Then the workflow's normal tags are :tags in the file
	 */
	public function theNormalTagsAreInTheFile(string $tags): void {
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");

		$names = [];
		foreach ((array)($wf['tags'] ?? []) as $tag) {
			Assert::assertIsArray($tag, 'a body tag entry is not an object');
			$names[] = (string)($tag['name'] ?? '');
		}
		Assert::assertSame(
			self::tagList($tags),
			$this->tagNormal($names),
			"the file's tags array is not the expected set",
		);
	}

	/**
	 * n8n's own answer, read from its API rather than from anything this app
	 * reports — a `Then` that only asks this app proves the app agrees with itself.
	 *
	 * @Then the workflow's normal tags are :tags in n8n
	 */
	public function theNormalTagsAreInN8n(string $tags): void {
		Assert::assertSame(
			self::tagList($tags),
			$this->tagNormal($this->tagN8nContent($this->tagWfId)),
			"the workflow's n8n tags are not the expected set",
		);
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
	}

	/**
	 * The MIRROR went, the WORKFLOW did not. Losing a mapping tag says the workflow
	 * no longer belongs to that folder — it says nothing about the workflow, which
	 * is still in n8n wearing whatever tags it has left. Asserted because "the file
	 * is gone" reads identically whether the app unmirrored it or deleted it.
	 *
	 * NAMED FOR WHAT IT ASSERTS, not for its sentence: `theWorkflowStillExistsInN8n`
	 * was already another trait's, and two traits composed into one context may not both
	 * define a method name — PHP fatals on the collision before Behat sees a single
	 * step, so every leg dies at once with a message about traits rather than tests.
	 *
	 * @Then the workflow still exists in n8n, with its other tags
	 */
	public function theWorkflowSurvivesUnmirrored(): void {
		$wf = $this->n8nGetWorkflow($this->tagWfId);
		Assert::assertIsArray($wf, "workflow {$this->tagWfId} was deleted in n8n, not merely unmirrored");
		Assert::assertNotEmpty(
			$this->tagNormal($this->n8nWorkflowTagNames($this->tagWfId)),
			'the workflow lost its other tags along with the mapping tag',
		);
	}

	/**
	 * A tag n8n has never seen has NO ID, and the file records it honestly: a row
	 * whose ONLY key is `name`. This is the one place a settled file legitimately
	 * holds an id-less row — the file sits outside every mapping, so there is no n8n
	 * to mint one, and inventing a placeholder would be a lie the next sync has to
	 * undo. Asserted on the KEYS, not merely on a missing id, because a row carrying
	 * `{"name": "urgent", "id": null}` or a stray `createdAt` would be this app
	 * writing shapes n8n never agreed to.
	 *
	 * @Then the file records the tag :tag by name alone, with no id
	 */
	public function theFileRecordsTheTagByNameAlone(string $tag): void {
		$path = $this->tagLocateFile();
		$wf = json_decode($this->davGet($path), true);
		Assert::assertIsArray($wf, "managed file at $path is not JSON");

		foreach ((array)($wf['tags'] ?? []) as $row) {
			if (is_array($row) && ($row['name'] ?? null) === $tag) {
				Assert::assertSame(['name'], array_keys($row), "the '$tag' row carries more than its name");
				return;
			}
		}
		throw new \RuntimeException("the file's tags array has no row for '$tag'");
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

	// ── helpers: reading the three surfaces ────────────────────────────────────

	/**
	 * Drop the mapping tag (and anything reserved that slipped through), sort, dedupe.
	 *
	 * @param list<string> $names
	 * @return list<string>
	 */
	private function tagNormal(array $names): array {
		$out = array_values(array_unique(array_filter(
			$names,
			fn (string $n): bool => $n !== '' && $n !== $this->tagMappingTag,
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

	/**
	 * Let a PILL change reach n8n — the reconcile, folded into the gesture.
	 *
	 * EVERY `When` THAT TOUCHES A PILL NEEDS THIS, which is why it is a helper rather
	 * than a line in one step. This file's arrange used to pin `timing=sync` so
	 * `ContentTagListener` ran inline and the assertions could read the result back
	 * immediately; that knob is gone (saga Ch5) and the listener now enqueues
	 * {@see ReconcileTagsJob} wherever a worker will drain it. Draining here keeps each
	 * step meaning the same thing under either outcome — and is a no-op when the
	 * reconcile already ran inline.
	 *
	 * It was added to the tag-SET gesture first and not to the mapping-tag removal,
	 * which is a different `When` on the same surface. CI found it: the file was still
	 * mirrored because the unbind was sitting in a queue nobody had emptied.
	 */
	private function settlePillChange(): void {
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\ReconcileTagsJob');
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
		$names = $this->n8nWorkflowTagNames($id);
		sort($names);
		return array_values($names);
	}

	/**
	 * The file's content pills (system tags minus the reserved namespace), sorted.
	 *
	 * @return list<string>
	 */
	private function tagContentPills(string $path): array {
		$names = $this->fileSystemTags($path);
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
}
