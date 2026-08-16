<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * Copy lifecycle (copy.feature). A COPY is the opposite of a MOVE: always a
 * brand-new instance, never the original's identity. NC fires NodeCopiedEvent,
 * which CopyListener handles by stripping any inherited metadata and (if the
 * copy landed in a mapping) registering it as a fresh workflow. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait CopySteps {
	/** The original's full DAV metadata, read before the copy — the "unchanged" baseline. */
	private array $copyOriginalBefore = [];

	/** The HTTP status of a copy the scenario expected to be refused. */
	private int $copyAttemptStatus = 0;
	/** What the destination folder held before that attempt. @var list<string> */
	private array $copyAttemptBefore = [];

	/**
	 * A workflow file in a NAMED folder, whatever that folder happens to be.
	 *
	 * ONE ARRANGE FOR EVERY SOURCE, because a copy does not care what its source
	 * was — that is the rule under test. Landing it in a mapped folder makes it
	 * managed in that mapping's mode; landing it outside every mapping makes it a
	 * plain document. The scenario says the folder and the Background says what the
	 * folder IS, so nothing has to restate "sync" or "unmapped" here.
	 *
	 * @Given a workflow file in :folder
	 * @Given a workflow file in :folder whose tags are :tags
	 */
	public function aWorkflowFileIn(string $folder, string $tags = ''): void {
		$this->davMkdir($folder);
		$name = 'Source-' . bin2hex(random_bytes(3));
		$path = $folder . '/' . $name . '.n8n';
		$this->davPut($path, json_encode(
			self::starterWorkflow($name, self::tagList($tags)),
			JSON_THROW_ON_ERROR,
		));
		$this->currentFolder = $folder;
		$this->currentFilePath = $path;
		// CREATE-ON-LAND RENAMES THE FILE to match the workflow's name, so the path we
		// PUT to may already be gone. Re-resolve before anything reads it.
		if (!$this->davExists($path)) {
			$this->currentFilePath = $folder . '/' . $name . '.n8n';
		}
		$this->lastWorkflowId = $this->davReadMetadataId($this->currentFilePath);
		if (is_string($this->lastWorkflowId) && $this->lastWorkflowId !== '') {
			$this->createdWorkflowIds[] = $this->lastWorkflowId;
		}
		$this->originalPath = $this->currentFilePath;
		$this->copyOriginalBefore = $this->readManagedMetadata($this->originalPath);
	}

	/**
	 * @When I copy the file into :folder
	 *
	 * THE COPY KEEPS ITS OWN NAME, and that is the whole gesture. This step used to
	 * invent a fresh random name for the destination, which meant the suite copied a
	 * workflow into the folder it was already in and NEVER ONCE COLLIDED — so every
	 * question about what a colliding copy is called went unasked, and the answers
	 * shipped wrong in all three places at once.
	 *
	 * Nextcloud's own collision name is computed here rather than left to the server,
	 * because the server does not compute one: WebDAV COPY onto an existing path is a
	 * 412, full stop. It is the FILES CLIENT that picks a free name and then copies to
	 * it — `getUniqueName()` from `@nextcloud/files`, which counts from 1 and inserts
	 * the counter before the LAST extension, since to it our file is a `.json` called
	 * `Fleet Health.n8n`. That is why the name it produces is `Fleet Health.n8n (1).json`
	 * and not ours. Confirmed on the live instance as `FooBoblicious.n8n (1).json`.
	 *
	 * So the suite plays the client. Emulating it is not a shortcut around the real
	 * behaviour — it IS the real behaviour, and the app's job starts the moment that
	 * name lands.
	 */
	public function iCopyTheFileInto(string $folder): void {
		$this->davMkdir($folder);
		$before = $this->davListWorkflowFiles($folder);
		$this->davCopy(
			$this->currentFilePath,
			$this->filesClientCopyName($folder, basename($this->currentFilePath)),
		);
		$this->currentFolder = $folder;
		$this->settleCopy();
		$this->captureCopy($this->theOneNewWorkflowFileIn($folder, $before));
	}

	/**
	 * The copy the app is expected to REFUSE — so this one does not assert success.
	 *
	 * `davCopy` insists on 201/204 and would fail here with "COPY returned 403" as
	 * though the block were the bug. A refusal is the behaviour under test, so the
	 * status is captured and judged by the Then, exactly as `move.feature` does for
	 * `I try to move`.
	 *
	 * @When I try to copy the file into :folder
	 */
	public function iTryToCopyTheFileInto(string $folder): void {
		$this->davMkdir($folder);
		$this->copyAttemptBefore = $this->davListWorkflowFiles($folder);
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/'
			. $this->davEncode($this->filesClientCopyName($folder, basename($this->currentFilePath)));
		$res = $this->davClient()->request('COPY', $this->davEncode($this->currentFilePath), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->copyAttemptStatus = $res->getStatusCode();
	}

	/** @Then the copy is refused with a message */
	public function theCopyIsRefusedWithAMessage(): void {
		Assert::assertNotContains(
			$this->copyAttemptStatus,
			[201, 204],
			"the copy was allowed (HTTP {$this->copyAttemptStatus}) but should have been refused",
		);
	}

	/**
	 * NOTHING ARRIVED — compared against what the folder held BEFORE the attempt, not
	 * against a name this step guessed. A refusal that still left a file behind is the
	 * failure worth catching, and it would not necessarily be called what we expected.
	 *
	 * @Then no file is added to :folder
	 */
	public function noFileIsAddedTo(string $folder): void {
		$now = $this->davListWorkflowFiles($folder);
		$added = array_values(array_diff($now, $this->copyAttemptBefore));
		Assert::assertSame([], $added, 'the refusal still left ' . implode(', ', $added) . " in '$folder'");
	}

	/**
	 * The destination the Files app would COPY to: the source's own name, with the
	 * client's ` (N)` counter before the last extension if that name is taken.
	 */
	private function filesClientCopyName(string $folder, string $basename): string {
		$ext = strrchr($basename, '.');
		$stem = $ext === false ? $basename : substr($basename, 0, -strlen($ext));
		$ext = $ext === false ? '' : $ext;

		$candidate = $basename;
		for ($n = 1; $this->davExists($folder . '/' . $candidate); $n++) {
			$candidate = $stem . ' (' . $n . ')' . $ext;
			if ($n > 100) {
				throw new \RuntimeException("no free name for '$basename' in '$folder'");
			}
		}
		return $folder . '/' . $candidate;
	}

	/**
	 * The single workflow file that appeared in $folder, given what was there before.
	 *
	 * FOUND AFTERWARDS, NOT ASSUMED. Insisting on EXACTLY ONE is the point: a copy that
	 * somehow produced two files, or none, fails here with what the folder actually
	 * holds rather than further down as a confusing 404.
	 *
	 * @param list<string> $before
	 */
	private function theOneNewWorkflowFileIn(string $folder, array $before): string {
		$now = $this->davListWorkflowFiles($folder);
		$new = array_values(array_diff($now, $before));
		if (count($new) !== 1) {
			throw new \RuntimeException(
				"expected exactly one new workflow file in '$folder', found " . count($new)
				. ".\n  before: " . implode(', ', $before)
				. "\n  after:  " . implode(', ', $now),
			);
		}
		return $folder . '/' . $new[0];
	}

	/**
	 * Run the deferred half of a copy.
	 *
	 * A copy's own hook holds locks on the file it just made, so the app cannot rewrite
	 * that file's JSON inside the request — it hands the work to
	 * {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob}, exactly as a rename does.
	 * Draining the queue here is what lets the scenario assert the END state rather than
	 * a half-finished one; the deferral itself is `rename.feature`'s subject.
	 */
	private function settleCopy(): void {
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\ReconcileNameJob');
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob');
	}

	/**
	 * The copy's whole managed state. Same vocabulary as {@see CreateSteps::theFileHolds},
	 * plus one value this file needs and no other does:
	 *
	 *   its own, not the original's   present, non-empty, and DIFFERENT from the
	 *                                 original's — which is the entire anti-hijack
	 *                                 claim, and stronger than either half alone
	 *
	 * @Then the copy holds this DAV metadata:
	 */
	public function theCopyHolds(TableNode $table): void {
		if ((string)$this->copyFilePath === '') {
			throw new \RuntimeException('no copy to inspect — a When must make one');
		}
		// `its own, not the original's` is understood by the shared engine — see
		// {@see CreateSteps::assertManagedMetadata}. It used to be handed in here as a
		// one-off closure, which meant only THIS step could say it; the n8n-side
		// duplicate needs the same sentence about the same end state.
		$this->assertManagedMetadata($this->copyFilePath, $table);
	}

	/** @Then the copy holds no n8n DAV metadata at all */
	public function theCopyHoldsNoMetadataAtAll(): void {
		Assert::assertTrue($this->davExists($this->copyFilePath), 'the copy vanished');
		foreach (self::MANAGED_KEYS as $key) {
			$actual = $this->davReadMetadata($this->copyFilePath, $key);
			Assert::assertTrue(
				$actual === null || $actual === '',
				"the copy carries $key ('$actual') but a copy outside every mapping is nobody's",
			);
		}
	}

	/**
	 * THE ANTI-HIJACK INVARIANT, stated as pre/post state rather than as a list of
	 * things that did not happen. The original's metadata is read before the copy
	 * and compared after: every key it had, it still has, with the same value.
	 *
	 * That covers what three separate steps used to claim — that the original kept
	 * its id, that its workflow was not restored, that it was not duplicated —
	 * because all three are the same sentence: the original did not move.
	 *
	 * @Then the original file and its workflow are unchanged
	 */
	public function theOriginalIsUnchanged(): void {
		if ($this->originalPath === '') {
			throw new \RuntimeException('no original was captured — a Given must establish one');
		}
		// `n8n_syncedHash` IS NOT PART OF "UNCHANGED", and excluding it is not a
		// weakening. It is the app's private record of the bytes it last agreed with n8n
		// about, so a legitimate PULL that normalises the local body to n8n's canonical
		// row moves it — which is the app keeping up, not the original changing. An
		// `@in-n8n` scenario's When contains exactly such a pull, so comparing it there
		// asserts that syncing does not happen.
		//
		// Every claim the step is actually making survives: `n8n_id` is still the
		// anti-hijack check, `n8n_mapping` and `n8n_mode` still pin where the original
		// belongs, and `n8n_versionId` still moves if anything WROTE to the original's
		// workflow — which is the drift worth catching.
		$before = $this->copyOriginalBefore;
		$now = $this->readManagedMetadata($this->originalPath);
		unset($before['n8n_syncedHash'], $now['n8n_syncedHash']);
		if ($now !== $before) {
			// SPELLED OUT, NOT ASSERTED. A PHPUnit array diff inside Behat is eaten by
			// the Registry TypeError (see MappingSteps::fail), and this step's message
			// IS the diagnosis — it names which key drifted and to what.
			$keys = array_unique([...array_keys($before), ...array_keys($now)]);
			sort($keys);
			$drift = [];
			foreach ($keys as $key) {
				$was = (string)($before[$key] ?? '');
				$is = (string)($now[$key] ?? '');
				if ($was !== $is) {
					$drift[] = "$key: '$was' became '$is'";
				}
			}
			throw new \RuntimeException(
				"the original at {$this->originalPath} changed — " . implode('; ', $drift),
			);
		}
		$id = (string)($this->copyOriginalBefore['n8n_id'] ?? '');
		if ($id !== '' && !is_array($this->n8nGetWorkflow($id))) {
			throw new \RuntimeException("the original's workflow $id disappeared from n8n");
		}
	}

	/**
	 * The copy's tags, on both surfaces, as ONE step — because they are one claim.
	 * The tags travelled in the body, so both the pills and the workflow in n8n end
	 * up with the same set; asserting them separately would be two sentences for a
	 * thing that is only true together.
	 *
	 * The mapping tag is excluded, as everywhere else: it is the binding, not a label
	 * the file brought with it.
	 *
	 * @Then the copy's normal tags are :tags in n8n and in Nextcloud
	 */
	public function theCopysNormalTagsAre(string $tags): void {
		$want = array_values(array_filter(array_map('trim', explode(',', $tags))));
		sort($want);

		$mappingTag = $this->mappingTagForFolder($this->currentFolder);
		$strip = fn (array $names): array => array_values(array_filter(
			$names,
			static fn (string $n): bool => $n !== '' && $n !== $mappingTag,
		));

		$id = (string)$this->copyWorkflowId;
		Assert::assertNotSame('', $id, 'the copy has no workflow to read tags from');
		$inN8n = $strip($this->n8nWorkflowTagNames($id));
		sort($inN8n);
		$inNextcloud = $strip($this->fileSystemTags($this->copyFilePath));
		sort($inNextcloud);

		Assert::assertSame(['n8n' => $want, 'Nextcloud' => $want], ['n8n' => $inN8n, 'Nextcloud' => $inNextcloud]);
	}

	/**
	 * THE BINDING IS POST-STATE TOO, and it is the one tag that CHANGES on a copy
	 * across mappings. A workflow belongs to a mapping by wearing its tag, so a copy
	 * that lands in `Pointers` must come out carrying `nextcloud:pointers` — and must
	 * NOT still carry the tag of the mapping it was copied from, which would leave one
	 * workflow claimed by two mappings and mirrored into both folders.
	 *
	 * Asserted as "this one, and no other mapping's" rather than as an exact set,
	 * because the normal tags are asserted separately and this step should fail for
	 * exactly one reason.
	 *
	 * @Then the copy's workflow carries the :folder mapping tag, and no other mapping's
	 */
	public function theCopysWorkflowCarriesTheMappingTag(string $folder): void {
		$id = (string)$this->copyWorkflowId;
		Assert::assertNotSame('', $id, 'the copy has no workflow to read tags from');

		$want = $this->mappingTagForFolder($folder);
		$on = $this->n8nWorkflowTagNames($id);
		Assert::assertContains($want, $on, "the copy's workflow does not carry '$want', the tag binding it to $folder");

		foreach ($this->listMappings() as $m) {
			$other = (string)($m['n8n_tag'] ?? '');
			if ($other === '' || $other === $want) {
				continue;
			}
			Assert::assertNotContains(
				$other,
				$on,
				"the copy's workflow still carries '$other' — it would be claimed by two mappings and mirrored into both",
			);
		}
	}

	/**
	 * NEXTCLOUD COPIES BYTES, so the copy's content is the original's content. The
	 * app's whole contribution to a copy landing outside every mapping is what it
	 * takes OFF — the identity — and this asserts it kept its hands off the rest.
	 *
	 * @Then the copy's body is byte-for-byte the original's
	 */
	public function theCopysBodyIsByteForByteTheOriginals(): void {
		$original = $this->davGet($this->originalPath);
		$copy = $this->davGet($this->copyFilePath);
		if ($original !== $copy) {
			throw new \RuntimeException(
				"the copy's body differs from the original's: " . strlen($original)
				. ' bytes became ' . strlen($copy),
			);
		}
	}

	/**
	 * THE INVARIANT A COPY WOULD OTHERWISE BREAK. Nextcloud copies bytes but not
	 * system tags, so a copy lands with a `tags` array and no pills — and the app
	 * promises those two agree for any `.n8n`, mapped or not. This asserts the
	 * copy path put that right.
	 *
	 * @Then the copy's pills match its body
	 */
	public function theCopysPillsMatchItsBody(): void {
		$wf = json_decode($this->davGet($this->copyFilePath), true);
		if (!is_array($wf)) {
			throw new \RuntimeException("the copy at {$this->copyFilePath} is not JSON");
		}
		$body = [];
		foreach ((array)($wf['tags'] ?? []) as $row) {
			$name = is_array($row) ? (string)($row['name'] ?? '') : '';
			if ($name !== '') {
				$body[] = $name;
			}
		}
		sort($body);
		$pills = $this->fileSystemTags($this->copyFilePath);
		sort($pills);

		if ($body !== $pills) {
			throw new \RuntimeException(
				"the copy's body says [" . implode(', ', $body) . '] but its pills say ['
				. implode(', ', $pills) . ']',
			);
		}
	}

	/** @Then no workflow is created in n8n for the copy */
	public function noWorkflowIsCreatedForTheCopy(): void {
		Assert::assertTrue($this->copyWorkflowId === null || $this->copyWorkflowId === '', 'a workflow was created in n8n for an unmapped copy');
	}

	/** A unique copy basename so a COPY never collides with its source (Overwrite: F). */
	private function copyBasename(): string {
		return 'Copy-' . bin2hex(random_bytes(3)) . '.n8n';
	}

	/** Record the just-made copy and the workflow id (if any) create-on-copy stamped. */
	private function captureCopy(string $dest): void {
		$this->copyFilePath = $dest;
		$this->copyWorkflowId = $this->davReadMetadataId($dest);
		if (is_string($this->copyWorkflowId) && $this->copyWorkflowId !== '') {
			$this->createdWorkflowIds[] = $this->copyWorkflowId;
		}
	}

	// ── the other direction: a duplicate made in n8n ───────────────────────────

	/**
	 * @When someone duplicates its workflow in n8n, keeping the name
	 *
	 * n8n's own "Duplicate": a NEW id carrying the same body — and THE SAME NAME, which
	 * is the hard case and the only one worth arranging. n8n permits it; verified on a
	 * live instance holding two workflows both called `Emby Items`, with different ids.
	 *
	 * The duplicate is tagged for the same mapping, because that is what makes it the
	 * mapping's to mirror — a workflow with no mapping tag is not in the mapping at all,
	 * and the scenario would be arranging nothing.
	 *
	 * Then a sync, because the mirror arriving is the behaviour under test and the pull
	 * that carries it is not.
	 */
	public function someoneDuplicatesItsWorkflowInN8n(): void {
		$id = (string)$this->lastWorkflowId;
		$original = $this->n8nGetWorkflow($id);
		if (!is_array($original)) {
			throw new \RuntimeException("workflow '$id' does not exist in n8n");
		}
		$tag = $this->mappingTagForFolder($this->currentFolder);
		$duplicate = $this->createN8nWorkflow(
			(string)($original['name'] ?? 'Untitled'),
			[$this->ensureN8nTag($tag)],
		);
		// THE DUPLICATE IS NOW THE WORKFLOW UNDER TEST, so the Then that looks for "a
		// matching file" looks for ITS file and not the original's. Without this the
		// scenario would find the file it started with and pass having proved nothing —
		// the same trap `the duplicate arrives as its own file` was written to dodge,
		// which is why that step could be retired rather than kept beside its twin.
		$this->lastWorkflowId = $duplicate;
		$this->runMappingSync('pull', $tag);
	}

	/**
	 * @Then :folder holds one file per workflow, named:
	 *
	 * THE WHOLE FOLDER, AS A SET. Naming the files one at a time would say nothing about
	 * how many there are, and the failure a suffix bug produces is usually a MISSING file
	 * rather than a misnamed one — two workflows collapsing onto one mirror.
	 *
	 * "One file per workflow" is the second half, and it is checked by id: three files
	 * with the right three names could still be three views of one workflow.
	 */
	public function holdsOneFilePerWorkflowNamed(string $folder, TableNode $table): void {
		$want = array_map(static fn (array $row): string => trim($row[0]), $table->getRows());
		sort($want);
		$got = $this->davListWorkflowFiles($folder);
		sort($got);
		if ($got !== $want) {
			throw new \RuntimeException(
				"'$folder' does not hold the files the scenario describes:\n  expected: "
				. implode(', ', $want) . "\n  found:    " . implode(', ', $got),
			);
		}

		$ids = [];
		foreach ($got as $name) {
			$id = (string)$this->davReadMetadataId($folder . '/' . $name);
			if ($id === '') {
				throw new \RuntimeException("'$folder/$name' carries no id, so it mirrors no workflow");
			}
			if (isset($ids[$id])) {
				throw new \RuntimeException(
					"'$name' and '{$ids[$id]}' both claim workflow '$id' — one workflow, two mirrors",
				);
			}
			$ids[$id] = $name;
		}
		$this->namedFolder = $folder;
		$this->namedFiles = $got;
	}

	/**
	 * @Then all three workflows are still named :name in n8n
	 *
	 * THE COUNTER STOPS AT THE FILENAME. Nextcloud cannot hold three files with one
	 * name, so it numbers them — but that is Nextcloud's constraint, and pushing it back
	 * into n8n would rename workflows nobody asked to rename.
	 *
	 * ## WHY "STILL" IS DOING REAL WORK IN THAT SENTENCE
	 *
	 * Landing the three names correctly is the easy half; KEEPING them is where the pull
	 * was wrong. It asked for the unsuffixed name on every mirror on every tick, so both
	 * duplicates were told, over and over, to take the name the first one was sitting on.
	 * It only "worked" because the rename threw and the catch logged
	 * `rename skipped (collision?)`.
	 *
	 * So this settles the folder before believing it: read, sync once more, and check
	 * neither the names nor the filenames moved. The sync is a MECHANISM and stays here;
	 * a scenario that said "and sync again" out loud would be describing the app's
	 * plumbing instead of what the user ends up with.
	 */
	public function allThreeWorkflowsAreStillNamedInN8n(string $name): void {
		$this->assertAllNamed($name, 'as they landed');
		$before = $this->namedFiles;

		$this->runMappingSync('pull', $this->mappingTagForFolder($this->namedFolder));

		$after = $this->davListWorkflowFiles($this->namedFolder);
		sort($after);
		if ($after !== $before) {
			throw new \RuntimeException(
				"the names did not survive another sync:\n  were: " . implode(', ', $before)
				. "\n  now:  " . implode(', ', $after),
			);
		}
		$this->assertAllNamed($name, 'after another sync');
	}

	/** Every file the scenario named claims a workflow n8n still calls $name. */
	private function assertAllNamed(string $name, string $when): void {
		$wrong = [];
		foreach ($this->namedFiles as $file) {
			$id = (string)$this->davReadMetadataId($this->namedFolder . '/' . $file);
			$wf = $this->n8nGetWorkflow($id);
			$got = is_array($wf) ? (string)($wf['name'] ?? '') : '<gone from n8n>';
			if ($got !== $name) {
				$wrong[] = "$file → '$got'";
			}
		}
		if ($wrong !== []) {
			throw new \RuntimeException(
				"$when, these are no longer named '$name' in n8n: " . implode('; ', $wrong)
				. ' — a Nextcloud filename counter reached n8n, which is the one place it must not go',
			);
		}
	}
}
