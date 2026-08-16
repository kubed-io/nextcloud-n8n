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
 * Create-on-land steps (UC-6: author in NC, live in n8n). A managed .n8n
 * written into a mapped folder over WebDAV fires NodeWrittenEvent →
 * CreateInN8nListener → the workflow appears in n8n. We assert the n8n side over
 * its REST API and the NC stamp over DAV PROPFIND. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait CreateSteps {
	/**
	 * Every metadata key this app stamps — the set a managed file carries and an
	 * untouched one carries none of. Named here so "no n8n metadata at all" is a
	 * claim about the whole surface rather than about whichever key someone
	 * remembered.
	 */
	private const MANAGED_KEYS = ['n8n_id', 'n8n_mode', 'n8n_mapping', 'n8n_versionId', 'n8n_syncedHash'];

	/**
	 * Set up an admin-owned mapping + the backing folder so a WebDAV PUT into it
	 * resolves to a mapping. `resolveForPath` only cares about the folder name, so
	 * the storage kind is invisible to every scenario that uses this.
	 *
	 * ADMIN-OWNED MATCHES THE APP'S OWN DEFAULT, so this arrange builds the mapping
	 * an admin gets by filling in the required fields and nothing else. It is also
	 * cheaper than a Team Folder mount. A scenario that wants the other backend
	 * says so in its own table.
	 *
	 * This note used to read "keeps CI free of the groupfolders app", which stopped
	 * being true once integration.yml installed it on every leg — and a stale note
	 * is worse than none, because it gets believed: it is why a later scenario put
	 * `| storage | admin folder |` in a table where storage is irrelevant.
	 *
	 * @Given a folder mapped as :mode to the n8n tag :tag
	 */
	public function aFolderMappedAsModeToTag(string $mode, string $tag): void {
		$folder = $this->folderNameForTag($tag);
		$data = [
			'n8n_tag' => $tag,
			'team_folder' => $folder,
			'nc_groups' => ['admin'],
			'mode' => $this->modeToModel($mode),
			'use_team_folder' => false,
		];
		$res = $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
		Assert::assertSame(0, $res['exit'], "adding mapping for $tag failed:\n{$res['output']}");
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		// NAMES THE MAPPING, so a later step can say "the mapped folder" without
		// repeating the tag. Every other mapping arrange does this
		// (SetupTrait::setupSyncMappingAndFolder, TagSyncSteps::tagArrangeManagedFile);
		// this one did not, so `currentTag` stayed '' and folderNameForTag('') fell
		// through to its 'mapped' default — a folder no scenario ever creates. That
		// is the 404 sync-now.feature hit on "each file carries its n8n dates".
		$this->currentTag = $tag;
	}

	/**
	 * A plain folder, NAMED — so a later step can say where it is creating a file
	 * instead of relying on "that folder" meaning whatever the last Given touched.
	 *
	 * @Given a folder :folder that is not mapped
	 */
	public function aFolderThatIsNotMapped(string $folder): void {
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
	}

	/**
	 * Create a workflow file over WebDAV. Both phrasings ("via the Files New
	 * menu" and a plain create) land the same way server-side — a PUT that fires
	 * NodeWrittenEvent — so one step backs both.
	 *
	 * NAMES ITS FOLDER, because a Background that declares several mappings has no
	 * "that folder" — the scenario has to say which. The older phrasings leaned on
	 * whatever the previous Given happened to leave in `currentFolder`, which reads
	 * fine in a one-mapping file and silently picks the wrong folder in this one.
	 *
	 * @When I create a new :ext file in :folder via the Files "New" menu
	 * @When I create a :ext file in :folder
	 */
	public function iCreateAWorkflowFile(string $ext, string $folder): void {
		$this->currentFolder = $folder;
		$name = 'demo-' . bin2hex(random_bytes(3)) . $ext;
		$path = $folder . '/' . $name;
		// A minimal but valid starter workflow body, like the New-menu template.
		$body = json_encode([
			'name' => 'Demo ' . substr($name, 0, 12),
			'nodes' => [],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		], JSON_THROW_ON_ERROR);
		$this->davPut($path, $body);
		$this->currentFilePath = $path;
		// Remember any workflow the app just created so teardown can delete it.
		$id = $this->davReadMetadataId($path);
		if ($id !== null && $id !== '') {
			$this->lastWorkflowId = $id;
			$this->createdWorkflowIds[] = $id;
		} else {
			$this->lastWorkflowId = null;
		}
	}

	/**
	 * THE OTHER ORIGIN. Everything above this line starts in Nextcloud; a workflow
	 * can equally start in n8n, and until this step existed nothing in the suite ever
	 * made one that way outside the admin's first sync of a brand-new mapping
	 * (`sync-now.feature`). That left the app's central promise — put the mapping's
	 * tag on a workflow and it shows up in Nextcloud — tested only as a bootstrap.
	 *
	 * UNTAGGED ON PURPOSE, so the tag is a gesture of its own rather than something
	 * the arrange smuggled in. The workflow exists and belongs to no mapping, which
	 * is the honest pre-state of every workflow anyone makes in the n8n UI.
	 *
	 * @When someone creates a workflow in n8n
	 */
	public function someoneCreatesAWorkflowInN8n(): void {
		$this->lastWorkflowId = $this->createN8nWorkflow('Made in n8n ' . bin2hex(random_bytes(3)), []);
		// NOTHING IN NEXTCLOUD YET, and said out loud: a leftover path from an earlier
		// scenario would let the metadata Then read some other file and pass.
		$this->currentFilePath = '';
	}

	/**
	 * The second half of the n8n-side create, and the step the whole feature turns
	 * on: in n8n, the mapping's tag is the entire membership gesture.
	 *
	 * THE SYNC IS FOLDED IN, exactly as the n8n-origin steps in `delete.feature` and
	 * `purge.feature` fold it in. n8n has no way to tell Nextcloud a tag was added,
	 * so the pull IS the delivery mechanism; a scenario that spelled it out as its
	 * own `When` would be describing our plumbing instead of the user's gesture, and
	 * would read as though tagging alone were not enough. It is enough — the schedule
	 * runs the same pull on its own.
	 *
	 * @When someone tags it :tag in n8n
	 */
	public function someoneTagsItInN8n(string $tag): void {
		$id = (string)($this->lastWorkflowId ?? '');
		Assert::assertNotSame('', $id, 'no workflow under test to tag — a When must create one in n8n first');
		$this->setN8nWorkflowTags($id, [$this->ensureN8nTag($tag)]);
		$this->currentTag = $tag;
		$this->runMappingSync('pull', $tag);
	}

	/**
	 * The mirror of `a matching workflow is created in n8n`, and it FINDS the file by
	 * the workflow's id rather than by a name it guessed. Nextcloud names the mirror
	 * from the workflow, and collisions get a ` (1)` suffix, so a name-based lookup
	 * would be a second implementation of the naming rules — one that agrees with the
	 * app right up until the day it does not.
	 *
	 * Records the file it found as the one under test, which is what lets the DAV
	 * metadata table beneath it read exactly this file.
	 *
	 * @Then a matching file is created in :folder
	 */
	public function aMatchingFileIsCreatedIn(string $folder): void {
		$id = (string)($this->lastWorkflowId ?? '');
		Assert::assertNotSame('', $id, 'no workflow under test — a When must create one in n8n');

		foreach ($this->propfindWorkflowIds($folder) as $href => $found) {
			if ($found !== $id) {
				continue;
			}
			$this->currentFolder = $folder;
			$this->currentFilePath = $folder . '/' . rawurldecode(basename(rtrim($href, '/')));
			return;
		}

		// THROWN, NOT ASSERTED — a failing PHPUnit assertion inside Behat dies in
		// `Registry::get()` and the reader gets a TypeError instead of this sentence.
		throw new \RuntimeException(
			"no file in \"$folder\" carries n8n_id $id — the tagged workflow never mirrored into Nextcloud",
		);
	}

	/** @Then a matching workflow is created in n8n */
	public function aMatchingWorkflowIsCreatedInN8n(): void {
		Assert::assertNotNull($this->lastWorkflowId, 'the file was not stamped with an n8n_id — no workflow was created');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "n8n has no workflow with id {$this->lastWorkflowId}");
		Assert::assertSame($this->lastWorkflowId, (string)($wf['id'] ?? ''), 'n8n returned a different workflow id');
	}

	/**
	 * The workflow wears the tag that binds it to the folder it was created in —
	 * inferred from the folder rather than restated, for the same reason the mode is.
	 *
	 * @Then the workflow carries the mapping's tag
	 */
	public function theWorkflowCarriesTheMappingsTag(): void {
		$this->theWorkflowCarriesTheTag($this->mappingTagForFolder($this->currentFolder));
	}

	/** The n8n tag of the mapping owning $folder. */
	private function mappingTagForFolder(string $folder): string {
		$id = $this->mappingIdForFolder($folder);
		foreach ($this->listMappings() as $m) {
			if ((string)($m['id'] ?? '') === $id) {
				return (string)($m['n8n_tag'] ?? '');
			}
		}
		$this->fail("mapping '$id' vanished between lookups");
	}

	/** @Then the workflow carries the :tag tag */
	public function theWorkflowCarriesTheTag(string $tag): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id captured');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		$names = array_map(
			static fn (array $t): string => (string)($t['name'] ?? ''),
			array_values(array_filter((array)($wf['tags'] ?? []), 'is_array')),
		);
		Assert::assertContains($tag, $names, "workflow {$this->lastWorkflowId} is not tagged '$tag' (has: " . implode(',', $names) . ')');
	}

	/**
	 * THE FILE'S WHOLE MANAGED STATE, IN ONE TABLE — the post-state half of the
	 * pre/post pair, and the reason the old one-key-at-a-time steps are gone. Says
	 * DAV METADATA out loud, because a file has several kinds of state a scenario
	 * might mean (its tags, its body, its mtime) and naming the surface is what lets
	 * them all be stated the same way without ambiguity.
	 *
	 * `the file is stamped with the workflow's "n8n_id"` and `the file carries its
	 * n8n metadata` said only that SOMETHING was there. The first named one key and
	 * checked it was non-empty; the second checked nothing a reader could name. Both
	 * pass on a file whose mode is wrong, whose mapping is another mapping's, or
	 * whose hash was never stamped — which is most of what can actually go wrong.
	 *
	 * Values are read the same way {@see ViewWorkflowSteps} reads them, so a scenario
	 * uses one vocabulary for state wherever it appears:
	 *
	 *   the workflow's id                 the id the app stamped when the file landed
	 *   its own, not the one it arrived with   a MINT: different from the id carried in
	 *   n8n's current one                 matches the workflow's versionId right now
	 *   the file's hash                   sha1 of the file's current bytes
	 *   the mapping's id                  the mapping owning the folder the file is in
	 *   the mapping's mode                that mapping's mode, in its stored form
	 *   the "<tag>" mapping's id          a NAMED mapping, for a Background with several
	 *   set                               present and non-empty, value unimportant
	 *   anything else                     an exact literal
	 *
	 * THE MAPPING IS INFERRED FROM THE FOLDER, which is the point of spelling the
	 * mappings out in the Background: a scenario says where it put the file and the
	 * expected mode follows from that, rather than being restated in an Examples
	 * column where it could disagree with the mapping it claims to describe.
	 *
	 * @Then the file holds this DAV metadata:
	 */
	public function theFileHolds(TableNode $table): void {
		Assert::assertNotSame('', $this->currentFilePath, 'no file to inspect — a When must create one');
		$this->assertManagedMetadata($this->currentFilePath, $table);
	}

	/**
	 * One of the three name rows. Always a quoted literal — a name is the one thing in
	 * this vocabulary a scenario really can spell out, and spelling it is the point.
	 *
	 * Every failure reports all three, because the interesting question is never "is
	 * this one wrong" but "which of the three disagreed with the other two", and that is
	 * unanswerable from a single value.
	 */
	private function assertNameRow(string $path, string $key, string $expected): void {
		if (!str_starts_with($expected, '"') || !str_ends_with($expected, '"')) {
			throw new \RuntimeException("the table says $key is $expected; a name row takes a quoted literal.");
		}
		$want = trim($expected, '"');
		// EVERY ARM NAMED, AND NO `default`. The caller decides which rows reach here, so
		// a `default` arm makes two lists that have to agree and silently reads the wrong
		// value the day they stop — a new row would be checked against n8n's name whatever
		// it was supposed to mean, and the failure would blame the value rather than the
		// vocabulary. An unhandled row is a bug in this trait, so it says so.
		$actual = match ($key) {
			'filename' => basename($path),
			'name in the file' => $this->nameInTheFile($path),
			'name in n8n' => $this->nameInN8n($path),
			default => throw new \RuntimeException(
				"'{$key}' is routed to the name rows but has no reader — add one here.",
			),
		};
		if ($actual !== $want) {
			throw new \RuntimeException(
				"$key: expected '$want', found '$actual' — the file is called " . basename($path)
				. ', its JSON says ' . $this->nameInTheFile($path)
				. ', n8n says ' . $this->nameInN8n($path),
			);
		}
	}

	/** The `name` key of the file's own JSON — a sync body and a link pointer both have one. */
	private function nameInTheFile(string $path): string {
		try {
			$wf = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return '<unreadable JSON: ' . $e->getMessage() . '>';
		}
		return ($wf instanceof \stdClass && isset($wf->name) && is_string($wf->name))
			? $wf->name
			: '<no name key>';
	}

	/** What n8n calls the workflow this file claims — found by id, never by name. */
	private function nameInN8n(string $path): string {
		$id = (string)$this->davReadMetadataId($path);
		if ($id === '') {
			return '<the file carries no id, so it names no workflow>';
		}
		$wf = $this->n8nGetWorkflow($id);
		return is_array($wf) ? (string)($wf['name'] ?? '<no name>') : "<workflow '$id' is gone from n8n>";
	}

	/**
	 * The engine behind every "holds this DAV metadata" step, shared so `the file
	 * holds:` and `the copy holds:` cannot drift into two vocabularies.
	 *
	 * @param array<string,callable(string,?string):void> $extra per-file value keywords
	 */
	private function assertManagedMetadata(string $path, TableNode $table, array $extra = []): void {
		foreach ($table->getRowsHash() as $key => $expected) {
			$key = trim($key);
			$expected = trim($expected);

			// THE THREE PLACES A NAME LIVES, readable side by side in one table on
			// purpose. They are supposed to be one value, and the only way to catch them
			// disagreeing is to read all three in the same glance — a copy shipped saying
			// three different things at once, and each of the three looked fine alone.
			//
			// FIRST IN THE LOOP, BEFORE THE METADATA READ, because these rows are not
			// metadata keys and must never be handed to one. `davReadMetadata()` builds
			// `<nc:metadata-{$key}/>`, so `name in the file` becomes
			// `<nc:metadata-name in the file/>` — not well-formed XML. The PROPFIND then
			// never comes back 207, and the assertion that says so is itself eaten by the
			// Registry TypeError, so three scenarios failed reporting nothing but that.
			if (in_array($key, ['filename', 'name in the file', 'name in n8n'], true)) {
				$this->assertNameRow($path, $key, $expected);
				continue;
			}

			$actual = $this->davReadMetadata($path, $key);

			if (isset($extra[$expected])) {
				$extra[$expected]($key, $actual);
				continue;
			}

			// A COPY'S OWN IDENTITY: present, and DIFFERENT from what the ORIGINAL still
			// carries. The anti-hijack claim in one row, and stronger than either half.
			//
			// LIVES HERE RATHER THAN IN `the copy holds:`, where it used to be passed in
			// as a one-off. The same sentence is now needed by a copy made in Nextcloud
			// and by a duplicate made in n8n, and this trait's own docblock says why that
			// matters: one engine, so the two steps cannot drift into two vocabularies.
			// `copyOriginalBefore` is captured by whichever arrange named the original.
			if ($expected === "its own, not the original's") {
				if (($actual ?? '') === '') {
					throw new \RuntimeException("$path carries no $key — nothing registered it as its own workflow");
				}
				$before = (string)($this->copyOriginalBefore[$key] ?? '');
				if ($actual === $before) {
					throw new \RuntimeException(
						"$path inherited the original's $key ('$actual') — a copy must never hijack identity",
					);
				}
				continue;
			}

			// A MINT, NOT A RESTORE. The file carried an id in and the app decided it was
			// not usable here — the workflow was hard-deleted in n8n, or a sibling in the
			// landing folder already tracks it. Both end with a fresh id, and asserting
			// "different from what it arrived with" is what tells the two apart.
			if ($expected === 'its own, not the one it arrived with') {
				// THROWN, NOT ASSERTED. A failing PHPUnit assertion inside Behat dies in
				// `PHPUnit\TextUI\Configuration\Registry::get()` — there is no PHPUnit run
				// to configure — so the message is replaced by a TypeError and the reader
				// learns nothing at all. `MappingSteps::fail` documents the same trap.
				if (($actual ?? '') === '') {
					throw new \RuntimeException("the file carries no $key — the move-in never registered it");
				}
				$arrived = $this->idArrivedWith !== '' ? $this->idArrivedWith : (string)($this->lastWorkflowId ?? '');
				if ($arrived === $actual) {
					throw new \RuntimeException(
						"$key is still '$actual' — the id the file arrived with. This gesture should have minted a new one",
					);
				}
				continue;
			}

			// `Modified` IS NOT A METADATA KEY, and it is in this table anyway. It is
			// state the file carries, read by the same person in the same glance as the
			// rest, and splitting it onto a line of its own said the same thing twice.
			if ($key === 'Modified') {
				$id = (string)$this->davReadMetadataId($path);
				$wf = $this->n8nGetWorkflow($id);
				Assert::assertIsArray($wf, "workflow $id is gone from n8n");
				$updatedAt = strtotime((string)($wf['updatedAt'] ?? ''));
				Assert::assertIsInt($updatedAt, 'n8n did not report an updatedAt to compare against');
				Assert::assertSame(
					$updatedAt,
					$this->davReadTime($path, 'getlastmodified'),
					"the mirror's Modified is not the workflow's updatedAt",
				);
				continue;
			}

			// THE TWO STAMPS AN EDIT MOVES, and the reason edit.feature spells them out:
			// they are the app's memory of "what did we last agree on", and an edit is
			// exactly when they must move. A version that lags means the next pull thinks
			// n8n is ahead; a hash that lags means the next save is read as a fresh edit
			// and pushed again.
			if ($expected === "n8n's current one") {
				$id = (string)$this->davReadMetadataId($path);
				$wf = $this->n8nGetWorkflow($id);
				Assert::assertIsArray($wf, "workflow $id is gone from n8n");
				Assert::assertSame((string)($wf['versionId'] ?? ''), $actual, "$key is not n8n's current versionId");
				continue;
			}
			if ($expected === "the file's hash") {
				Assert::assertSame(sha1($this->davGet($path)), $actual, "$key is not the hash of the file's current bytes");
				continue;
			}

			// `cleared` is the one expectation that is ABSENCE, so it is answered before
			// the presence check every other value shares. A file that left its mapping
			// still has an id and a mode — what it no longer has is an owner.
			if ($expected === 'cleared') {
				Assert::assertTrue(
					$actual === null || $actual === '',
					"$key is still '$actual' on $path, but nothing should own it now",
				);
				continue;
			}

			Assert::assertNotNull($actual, "$key is not on $path at all");
			Assert::assertNotSame('', $actual, "$key is empty on $path");

			if ($expected === 'set') {
				continue;
			}
			$want = match (true) {
				$expected === "the workflow's id" => (string)$this->lastWorkflowId,
				$expected === "the mapping's id" => $this->mappingIdForFolder($this->currentFolder),
				$expected === "the mapping's mode" => $this->mappingModeForFolder($this->currentFolder),
				(bool)preg_match('/^the "([^"]+)" mapping\'s id$/', $expected, $m) => $this->mappingIdForTag($m[1]),
				// A MODE SPELLED OUT IN AN EXAMPLES COLUMN still gets the storage
				// translation, so a scenario may write `link` — the word the admin
				// picked and the word the Background uses — without knowing the app
				// stores it as `reference` (the literal `link` is is_callable() and
				// crashes core's PROPFIND). Without this the only way to state a mode
				// per row would be to leak that workaround into the Gherkin.
				$key === self::META_MODE => $this->storedMode($expected),
				default => $expected,
			};
			Assert::assertNotSame('', $want, "the scenario asked for '$expected' but the arrange never recorded one");
			Assert::assertSame($want, $actual, "$key carried the wrong value");
		}
	}

	/**
	 * Every managed key currently on a file, keyed by name — the shape both halves of
	 * a pre/post comparison are read into.
	 *
	 * @return array<string,string>
	 */
	private function readManagedMetadata(string $path): array {
		$out = [];
		foreach (self::MANAGED_KEYS as $key) {
			$value = $this->davReadMetadata($path, $key);
			if ($value !== null && $value !== '') {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	/**
	 * The negative twin, and it names the whole set rather than one key. A file
	 * outside every mapping is a plain document: not one of ours in any respect,
	 * which is a stronger claim than "it has no n8n_id".
	 *
	 * @Then the file holds no n8n DAV metadata at all
	 */
	public function theFileHoldsNoMetadataAtAll(): void {
		Assert::assertNotSame('', $this->currentFilePath, 'no file to inspect — a When must create one');

		foreach (self::MANAGED_KEYS as $key) {
			$actual = $this->davReadMetadata($this->currentFilePath, $key);
			Assert::assertTrue(
				$actual === null || $actual === '',
				"{$this->currentFilePath} carries $key ('$actual') but nothing should have managed it",
			);
		}
	}

	/** The mapping id for an n8n tag, read back from the LIVE store. */
	private function mappingIdForTag(string $tag): string {
		foreach ($this->listMappings() as $m) {
			if (($m['n8n_tag'] ?? '') === $tag) {
				return (string)($m['id'] ?? '');
			}
		}
		$this->fail("no mapping declares the tag '$tag' — check the Background");
	}

	/**
	 * The mapping's mode for $folder, in the form the metadata STORES — `link` is
	 * written as `reference`, because the literal string `link` is is_callable() and
	 * crashes core's PROPFIND. A scenario should not have to know that; it says
	 * "the mapping's mode" and this does the translation.
	 */
	private function mappingModeForFolder(string $folder): string {
		$id = $this->mappingIdForFolder($folder);
		foreach ($this->listMappings() as $m) {
			if ((string)($m['id'] ?? '') === $id) {
				return $this->storedMode((string)($m['mode'] ?? ''));
			}
		}
		$this->fail("mapping '$id' vanished between lookups");
	}

	/**
	 * A mode as the METADATA STORES it. One place, because both ways a scenario can
	 * name a mode — "the mapping's mode" and a literal in an Examples column — have
	 * to land on the same string, and two copies of a translation is how they stop.
	 */
	private function storedMode(string $mode): string {
		return $mode === 'link' ? 'reference' : $mode;
	}

	/**
	 * The mapping id owning $folder — the nearest one, so a nested mapping wins over
	 * the parent it sits inside. Read from the live store rather than remembered at
	 * arrange time, because a Background may declare several and the file decides
	 * which by where it landed.
	 */
	private function mappingIdForFolder(string $folder): string {
		$best = ['id' => '', 'len' => -1];
		foreach ($this->listMappings() as $m) {
			$mf = (string)($m['team_folder'] ?? '');
			if ($mf === '') {
				continue;
			}
			if (($folder === $mf || str_starts_with($folder, $mf . '/')) && strlen($mf) > $best['len']) {
				$best = ['id' => (string)($m['id'] ?? ''), 'len' => strlen($mf)];
			}
		}
		if ($best['id'] === '') {
			$this->fail("no mapping owns the folder '$folder' — check the Background");
		}
		return $best['id'];
	}

	/** @Then the file is stamped with the workflow's :key */
	public function theFileIsStampedWith(string $key): void {
		$value = $this->davReadMetadata($this->currentFilePath, $key);
		Assert::assertNotNull($value, "file has no metadata-$key");
		Assert::assertNotSame('', $value, "metadata-$key is empty");
		if ($key === self::META_ID) {
			Assert::assertSame($this->lastWorkflowId, $value, 'stamped id disagrees with the n8n workflow id');
		}
	}

	/** @Then no workflow is created in n8n */
	public function noWorkflowIsCreatedInN8n(): void {
		Assert::assertNull($this->lastWorkflowId, "a workflow ({$this->lastWorkflowId}) was unexpectedly created in n8n");
	}

	/** @Then the file has no :key metadata */
	public function theFileHasNoMetadata(string $key): void {
		$value = $this->davReadMetadata($this->currentFilePath, $key);
		Assert::assertTrue($value === null || $value === '', "file unexpectedly has metadata-$key='$value'");
	}

	/** @Then /^the file is treated as a plain document \(unmapped state\)$/ */
	public function theFileIsTreatedAsPlain(): void {
		// "Plain" = no n8n metadata id; the create listener bailed (outside any
		// mapping). The id check above is the operative assertion; this step is a
		// readable restatement so the scenario reads as a sentence.
		$this->theFileHasNoMetadata(self::META_ID);
	}
}
