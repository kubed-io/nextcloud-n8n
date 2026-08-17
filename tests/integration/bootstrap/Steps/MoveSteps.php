<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Move / motion steps (saga §14.2: move-out → unmapped+archive, move-in →
 * restore). MoveGuardListener vets the move on BeforeNodeRenamedEvent (sync may
 * leave, link may not); MotionListener applies the n8n-side consequence on the
 * post-move NodeRenamedEvent. Both run synchronously, so no job draining here.
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait MoveSteps {
	/**
	 * The id the file carried IN, for telling a mint from a restore.
	 *
	 * Read by the shared metadata vocabulary's `its own, not the one it arrived with`
	 * ({@see \OCA\N8nSync\Tests\Integration\Steps\CreateSteps}), so the two gestures
	 * that can mint a workflow both set it: a move-in, and a restore of a file whose
	 * workflow was deleted while it sat in the trash ({@see DeleteSteps}). It lives
	 * here because the move needed it first, not because it is the move's alone.
	 */
	private string $idArrivedWith = '';

	/**
	 * A managed sync/link file in one of the Background's mapped folders, addressed
	 * by its TAG. Kept for copy/purge/reserved-tags, which still declare their
	 * mappings tag-first and derive the folder name from it.
	 *
	 * @Given a managed :mode workflow file in the :tag folder
	 */
	public function aManagedWorkflowFileInTheFolder(string $mode, string $tag): void {
		$this->currentTag = $tag;
		$this->arrangeManagedFileIn($this->folderNameForTag($tag), $mode);
	}

	/**
	 * The same arrange, addressed by the NEXTCLOUD FOLDER — the form `move.feature`
	 * uses, where the Background names each mapping's folder explicitly.
	 *
	 * A move is a Nextcloud gesture, so a move scenario should say which folder it
	 * moves between. Saying it in tags worked only because the folder name happened
	 * to be derived from the tag, and that coincidence is what let a scenario be
	 * written asserting a folder was "no longer a tag in n8n".
	 *
	 * @Given a managed :mode workflow file in :folder
	 */
	public function aManagedWorkflowFileInFolder(string $mode, string $folder): void {
		$this->currentTag = $this->tagForFolder($folder);
		$this->arrangeManagedFileIn($folder, $mode);
	}

	/**
	 * Shared body of the two arranges above.
	 *
	 * THE NAME IS UNIQUE PER SCENARIO, and it has to be. It was a fixed
	 * `Mover.n8n`, which was fine while one scenario per run moved a file into
	 * a given folder — the moment two did, the second MOVE hit the first's leftover
	 * and Nextcloud refused it with a 412 before this app saw anything. The failure
	 * reads as a storage or permissions problem and is neither.
	 */
	private function arrangeManagedFileIn(string $folder, string $mode): void {
		$this->currentFolder = $folder;
		$name = 'Mover-' . bin2hex(random_bytes(3));
		$this->putManagedFile($this->currentFolder . '/' . $name . '.n8n', $name);
		$this->lastVersionId = $this->davReadMetadata($this->currentFilePath, self::META_VERSION_ID);
		$this->expectedArchived = false; // sync/link create leaves the workflow live
	}

	/**
	 * An *unmapped* file that still carries its id: set up a managed sync file in
	 * the first sync mapping, then move it OUT to an unmapped folder so the motion
	 * path archives it and stamps `unmapped`. Leaves it outside any mapping.
	 *
	 * IT ASKS THE STORE WHICH MAPPING TO USE rather than naming one. This Given is
	 * shared by move/copy/purge/delete, whose Backgrounds declare different
	 * mappings; it used to hardcode `nextcloud:alpha`, which silently tied all four
	 * features to one feature's naming and would have broken the moment any of them
	 * renamed a tag.
	 *
	 * @Given an unmapped workflow file that still carries its :key
	 */
	public function anUnmappedWorkflowFileCarryingItsId(string $key): void {
		$this->aManagedWorkflowFileInFolder('sync', $this->firstSyncFolder());
		$this->iMoveTheFileToAnUnmappedFolder();
		Assert::assertSame(
			'unmapped',
			$this->davReadMetadata($this->currentFilePath, self::META_MODE),
			'setup precondition failed: the file is not unmapped after the move-out',
		);
	}

	/** @When I rename the file within the :tag folder */
	public function iMoveRenameTheFileWithinTheFolder(string $tag): void {
		$this->iRenameTheFileWithin($this->folderNameForTag($tag));
	}

	/** @When I rename the file within :folder */
	public function iRenameTheFileWithin(string $folder): void {
		$dest = $folder . '/Mover-renamed.n8n';
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/** @When I move the file into a subfolder of the :tag folder */
	public function iMoveTheFileIntoASubfolder(string $tag): void {
		$this->iMoveTheFileIntoASubfolderOf($this->folderNameForTag($tag));
	}

	/** @When I move the file into a subfolder of :folder */
	public function iMoveTheFileIntoASubfolderOf(string $folder): void {
		$sub = $folder . '/sub';
		$this->davMkdir($sub);
		$dest = $sub . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/** @When I move the file to a folder that is not mapped */
	public function iMoveTheFileToAnUnmappedFolder(): void {
		$folder = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		$this->expectedArchived = true; // sync move-out archives the workflow
	}

	/** @When I move the file to another folder that is not mapped */
	public function iMoveTheFileToAnotherUnmappedFolder(): void {
		$folder = 'unmapped2-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		// relocation between unmapped locations — archived state is unchanged.
	}

	/** @When I move the file into the :tag folder */
	public function iMoveTheFileIntoTheFolder(string $tag): void {
		$this->iMoveTheFileInto($this->folderNameForTag($tag));
	}

	/** @When I move the file into :folder */
	public function iMoveTheFileInto(string $folder): void {
		// THE ID IT ARRIVED WITH, pinned before the move overwrites it. A move-in can
		// MINT a new workflow, and `its own, not the one it arrived with` has to
		// compare against what the file carried IN — re-reading afterwards compares the
		// new id with itself, which passes for a restore and fails for a mint.
		$this->idArrivedWith = (string)($this->davReadMetadataId($this->currentFilePath) ?? '');
		$from = $this->currentFolder;
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		// THE FILE IS SOMEWHERE ELSE NOW, and the metadata table resolves "the
		// mapping's id" from where the file IS. Leaving this pointing at the source
		// asked which mapping owns the folder the file just left.
		$this->currentFolder = $folder;
		$this->expectedArchived = false; // move-in restores (unarchives) the workflow

		// ADVANCE THE CLOCK, DO NOT CHANGE THE OUTCOME. A rebind settles n8n and the
		// pills inside the move, but not the file's `tags` array — the file is locked for
		// the length of a rename, and a mirror of n8n is written by the sync, not by the
		// gesture. So the sync runs here, in the `When`, exactly as it would on its own
		// schedule a moment later.
		//
		// A Gherkin `Then` states the effect, never how long it took to arrive, so the
		// waiting belongs in the step. Scoped to the one gesture that leaves a surface
		// behind — a move between two DIFFERENT mappings — so no other scenario pays for
		// a sync it did not ask for.
		if ($from !== '' && $from !== $folder && $this->isMappedFolder($from) && $this->isMappedFolder($folder)) {
			$this->runMappingSync('pull', $this->tagForFolder($folder));
		}

		// A move-in can MINT a workflow: create-on-land for an untracked file, or the
		// create-fallback when the old id was hard-deleted in n8n. Re-capture whatever
		// id the file now carries so "a matching workflow is created" + teardown see it.
		// For a plain unarchive the id is unchanged, so this is a harmless re-read.
		$id = $this->davReadMetadataId($this->currentFilePath);
		if ($id !== null && $id !== '') {
			$this->lastWorkflowId = $id;
			if (!in_array($id, $this->createdWorkflowIds, true)) {
				$this->createdWorkflowIds[] = $id;
			}
		}
	}

	/**
	 * A plain `.n8n` that was never tracked (no n8n id) sitting outside any
	 * mapping — delegates to the untracked-file arrange (DeleteSteps, composed here).
	 *
	 * @Given a :ext file that was never tracked in n8n
	 */
	public function aFileThatWasNeverTrackedInN8n(string $ext): void {
		$this->anUntrackedFile($ext);
	}

	/**
	 * Hard-delete the workflow under test in n8n so the next unarchive 404s and the
	 * move-in falls back to create. Remembers the deleted id for the "is new" check.
	 *
	 * @Given that workflow no longer exists in n8n
	 */
	public function thatWorkflowNoLongerExistsInN8n(): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id to delete');
		$this->deletedWorkflowId = $this->lastWorkflowId;
		$this->n8nDeleteWorkflow($this->lastWorkflowId);
		Assert::assertNull(
			$this->n8nGetWorkflow($this->lastWorkflowId),
			'precondition: the workflow still exists in n8n after the delete',
		);
	}

	/**
	 * After a create-fallback move-in, the file carries a FRESH id (not the deleted
	 * one) and that workflow exists live in n8n.
	 *
	 * @Then a new workflow is created in n8n from the file
	 */
	public function aNewWorkflowIsCreatedFromTheFile(): void {
		$newId = $this->davReadMetadataId($this->currentFilePath);
		Assert::assertNotNull($newId, 'the file has no n8n_id after the move-in');
		Assert::assertNotSame('', $newId, 'the file has an empty n8n_id after the move-in');
		Assert::assertNotSame(
			$this->deletedWorkflowId,
			$newId,
			'a new workflow was NOT created — the file still carries the old (deleted) id',
		);
		Assert::assertIsArray($this->n8nGetWorkflow($newId), "the new workflow $newId does not exist in n8n");
	}

	/**
	 * Genesis for the move-in duplicate case (saga §14.19): a SECOND file carrying
	 * the SAME workflow id as the managed sync file already in alpha, but sitting
	 * OUTSIDE any mapping. A MOVE never duplicates, so we move the synced file OUT
	 * (archiving the workflow), then unarchive it in n8n and PULL alpha — which
	 * re-creates the synced file in place from n8n. Net: the synced copy lives in
	 * alpha (id W) and the original, now unmapped, copy waits outside (also id W).
	 *
	 * @Given an unmapped copy of that same workflow with the same :key outside any mapping
	 * @Given an unmapped copy of that same workflow with the same :key in :folder
	 */
	public function anUnmappedCopyOfThatSameWorkflow(string $key, string $folder = ''): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id from the managed sync file');
		$this->collisionWorkflowId = $this->lastWorkflowId;

		// THE MAPPING IS WHICHEVER ONE THE PRECEDING GIVEN USED. Hardcoded
		// `nextcloud:alpha` here tied this arrange to one feature's tag naming, in a
		// step move.feature and copy.feature both call.
		$sourceFolder = $this->currentFolder;
		$sourceTag = $this->currentTag;

		// Move the synced file OUT → it becomes the unmapped copy (id preserved, workflow archived).
		$this->iMoveTheFileToAnUnmappedFolder();
		if ($this->davReadMetadata($this->currentFilePath, self::META_MODE) !== 'unmapped') {
			throw new \RuntimeException('setup: the moved-out copy is not unmapped');
		}
		$this->collisionIncomingPath = $this->currentFilePath;

		// Bring the workflow back to life and pull the mapping so a fresh SYNCED file is
		// written into it from n8n — the "existing synced copy" the move-in must defer to.
		$this->n8nUnarchiveWorkflow($this->collisionWorkflowId);
		$this->runMappingSync('pull', $sourceTag);

		// FOUND BY WORKFLOW ID, not by filename. This assumed `Mover.n8n`, the name
		// one arrange happened to use — so the moment a scenario arranged its file any
		// other way, the setup asserted against a path that was never written and the
		// failure read as "the pulled file does not carry the shared id". The pull also
		// names a mirror after its WORKFLOW, so the filename is n8n's to choose anyway.
		$this->collisionSyncedPath = '';
		foreach ($this->propfindWorkflowIds($sourceFolder) as $href => $wid) {
			if ($wid === $this->collisionWorkflowId) {
				$this->collisionSyncedPath = $this->hrefToFilesPath((string)$href);
				break;
			}
		}
		if ($this->collisionSyncedPath === '') {
			throw new \RuntimeException(
				"setup: the pull wrote no file for workflow {$this->collisionWorkflowId} into $sourceFolder",
			);
		}
		if ($this->davReadMetadata($this->collisionSyncedPath, self::META_MODE) !== 'sync') {
			throw new \RuntimeException("setup: the pulled file at {$this->collisionSyncedPath} is not in sync mode");
		}

		// THIS ARRANGE REDEFINES "the original". The file the Given made was moved out
		// to become the incoming copy, so the file now standing in the mapping — the one
		// the scenario's last line says must be untouched — is the mirror the pull just
		// wrote. Re-point the role and re-baseline it here, while it is still pristine.
		$this->originalPath = $this->collisionSyncedPath;
		$this->copyOriginalBefore = $this->readManagedMetadata($this->collisionSyncedPath);

		// The incoming copy (still unmapped, outside alpha) is what the scenario moves in next.
		$this->currentFilePath = $this->collisionIncomingPath;
		$this->lastWorkflowId = $this->collisionWorkflowId;
	}

	/**
	 * Move the unmapped copy INTO the mapping under a FRESH name. Because a sibling
	 * already tracks this workflow, MotionService::moveIn mints the incoming as a
	 * brand-new instance (copy semantics) rather than restoring it — see saga §14.19.
	 *
	 * @When I move the unmapped copy into the :tag folder under a different name
	 */
	public function iMoveTheUnmappedCopyIntoTheFolder(string $tag): void {
		$this->iMoveTheUnmappedCopyInto($this->folderNameForTag($tag));
	}

	/** @When I move the unmapped copy into :folder under a different name */
	public function iMoveTheUnmappedCopyInto(string $folder): void {
		$dest = $folder . '/Mover-incoming.n8n';
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/**
	 * Try to move the unmapped copy in under the SAME name as the existing synced
	 * file. Nextcloud's WebDAV MOVE uses Overwrite:F, so the destination-exists case
	 * is refused with a 412 before any rename event fires — exactly like any NC
	 * same-name move. Capture the status for the refusal assertion.
	 *
	 * @When I try to move the unmapped copy into the :tag folder under the same name
	 */
	public function iTryToMoveTheUnmappedCopyIntoTheFolderUnderTheSameName(string $tag): void {
		$this->iTryToMoveTheUnmappedCopyIntoUnderTheSameName($this->folderNameForTag($tag));
	}

	/** @When I try to move the unmapped copy into :folder under the same name */
	public function iTryToMoveTheUnmappedCopyIntoUnderTheSameName(string $folder): void {
		$dest = $folder . '/' . basename($this->collisionSyncedPath);
		$this->lastMoveStatus = $this->davMoveStatus($this->currentFilePath, $dest);
	}

	/**
	 * After a differently-named duplicate lands, the moved-in file carries a FRESH id
	 * (not the shared one) and that new workflow exists live in n8n.
	 *
	 * @Then the moved-in file becomes a brand-new workflow in n8n
	 */
	public function theMovedInFileBecomesABrandNewWorkflow(): void {
		$newId = $this->davReadMetadataId($this->currentFilePath);
		Assert::assertNotNull($newId, 'the moved-in file has no n8n_id');
		Assert::assertNotSame('', $newId, 'the moved-in file has an empty n8n_id');
		Assert::assertNotSame(
			$this->collisionWorkflowId,
			$newId,
			'no new instance was minted — the moved-in file still carries the shared id',
		);
		Assert::assertIsArray($this->n8nGetWorkflow($newId), "the new workflow $newId does not exist in n8n");
		// Register for teardown so the minted workflow is cleaned up.
		if (!in_array($newId, $this->createdWorkflowIds, true)) {
			$this->createdWorkflowIds[] = $newId;
		}
	}

	/** @Then the original synced file is unchanged */
	public function theOriginalSyncedFileRemainsUnchanged(): void {
		Assert::assertSame(
			'sync',
			$this->davReadMetadata($this->collisionSyncedPath, self::META_MODE),
			'the existing synced file changed mode',
		);
		Assert::assertSame(
			$this->collisionWorkflowId,
			$this->davReadMetadataId($this->collisionSyncedPath),
			'the existing synced file changed its workflow id',
		);
		Assert::assertIsArray(
			$this->n8nGetWorkflow($this->collisionWorkflowId),
			'the original workflow vanished from n8n',
		);
	}

	/** @When I try to move the file to a folder that is not mapped */
	public function iTryToMoveTheFileToAnUnmappedFolder(): void {
		$folder = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->lastMoveStatus = $this->davMoveStatus($this->currentFilePath, $dest);
	}

	/**
	 * A move into ANOTHER mapping. MoveGuardListener aborts it on the
	 * BeforeNodeRenamedEvent, so nothing on the n8n side is ever reached — which is
	 * the point of the scenario: the ambiguous case is refused before it can pick
	 * one of the two possible meanings.
	 *
	 * @When I try to move the file into :folder
	 */
	public function iTryToMoveTheFileInto(string $folder): void {
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->lastMoveStatus = $this->davMoveStatus($this->currentFilePath, $dest);
	}

	/** @Then the file stays in :mode mode in the :tag mapping */
	public function theFileStaysInModeInTheMapping(string $mode, string $tag): void {
		$this->theFileStaysInModeIn($mode, $this->folderNameForTag($tag));
	}

	/**
	 * @Then the file stays in :mode mode in :folder
	 *
	 * Compares against the WIRE mode. This used `modeToModel()`, which returns
	 * `link` — but DAV reports link as `reference`, so the assertion could only ever
	 * have passed for `sync`. Latent rather than caught, because no scenario had yet
	 * asked a link file to stay put.
	 */
	public function theFileStaysInModeIn(string $mode, string $folder): void {
		$expected = $mode === 'link' ? 'reference' : $this->modeToModel($mode);
		Assert::assertSame($expected, $this->davReadMetadata($this->currentFilePath, self::META_MODE), "file is not in $mode mode");
		Assert::assertStringStartsWith($folder . '/', $this->currentFilePath, "file is not under $folder");
		$mappingId = $this->davReadMetadata($this->currentFilePath, self::META_MAPPING);
		Assert::assertNotNull($mappingId, 'file lost its n8n_mapping');
		Assert::assertNotSame('', $mappingId, 'file lost its n8n_mapping');
	}

	/** @Then the file's mode becomes :mode */
	public function theFilesModeBecomes(string $mode): void {
		// n8n_mode is exposed over DAV in its WIRE form — link is stored as
		// "reference" (the literal "link" crashes core PROPFIND). sync/unmapped
		// are identical in both forms, so only link needs translating.
		$wire = $mode === 'link' ? 'reference' : $mode;
		Assert::assertSame($wire, $this->davReadMetadata($this->currentFilePath, self::META_MODE), "file mode did not become $mode");
	}

	/** @Then the file's mode becomes :mode in the :tag mapping */
	public function theFilesModeBecomesInTheMapping(string $mode, string $tag): void {
		$this->theFileStaysInModeInTheMapping($mode, $tag);
	}

	/** @Then the file's mode becomes :mode in :folder */
	public function theFilesModeBecomesIn(string $mode, string $folder): void {
		$this->theFileStaysInModeIn($mode, $folder);
	}

	/** @Then the file keeps its :key1 and :key2 */
	public function theFileKeepsItsIdAndVersionId(string $key1, string $key2): void {
		Assert::assertSame($this->lastWorkflowId, $this->davReadMetadata($this->currentFilePath, $key1), "$key1 was lost");
		Assert::assertSame($this->lastVersionId, $this->davReadMetadata($this->currentFilePath, $key2), "$key2 was lost");
	}

	/** @Then its :key1 and :key2 are unchanged */
	public function itsIdAndVersionIdAreUnchanged(string $key1, string $key2): void {
		$this->theFileKeepsItsIdAndVersionId($key1, $key2);
	}

	/** @Then the file's :key is cleared */
	public function theFilesMetadataIsCleared(string $key): void {
		$value = $this->davReadMetadata($this->currentFilePath, $key);
		Assert::assertTrue($value === null || $value === '', "metadata-$key was not cleared (='$value')");
	}

	/** @Then the :key is unchanged */
	public function theIdIsUnchanged(string $key): void {
		Assert::assertSame($this->lastWorkflowId, $this->davReadMetadata($this->currentFilePath, $key), "$key changed across the move");
	}

	/** @Then the full workflow JSON is still in the Nextcloud file */
	public function theFullWorkflowJsonIsStillInTheFile(): void {
		$body = (string)$this->davGet($this->currentFilePath);
		$wf = json_decode($body, true);
		Assert::assertIsArray($wf, "file is not valid JSON after the move:\n$body");
		Assert::assertArrayHasKey('name', $wf, 'the workflow JSON body is missing — only a pointer remains');
	}

	/**
	 * @Then nothing changes in n8n
	 * @Then nothing changes in n8n except the name
	 */
	public function nothingChangesInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} unexpectedly disappeared from n8n");
		Assert::assertSame($this->expectedArchived, (bool)($wf['isArchived'] ?? false), 'the workflow archived-state changed when it should not have');
	}

	/**
	 * A mapping owns a workflow by its tag, so "under a folder" is two things at once:
	 * the file is there, and n8n agrees the workflow belongs to that folder's mapping.
	 *
	 * ## THE DUPLICATE CHECK LIVES HERE, NOT IN THE GHERKIN
	 *
	 * A move can fail by leaving TWO workflows — one the file points at, one orphaned
	 * under the old mapping's tag — and every assertion phrased around "the" workflow
	 * is answered by whichever id the file is holding, so all of them pass. That is not
	 * hypothetical: it is exactly how a move between two Team Folders shipped green
	 * while minting a duplicate on every drag. Counting is the only question that
	 * cannot be answered by the wrong workflow, so it is asked here, once, as part of
	 * what "the workflow is now under X" means rather than as a line of prose.
	 *
	 * @Then the workflow is now under :folder
	 */
	public function theWorkflowIsNowUnder(string $folder): void {
		Assert::assertStringStartsWith($folder . '/', $this->currentFilePath, "the file is not in $folder");
		Assert::assertTrue($this->davExists($this->currentFilePath), "the file is not in $folder at all");

		$id = $this->movedWorkflowId();
		if ($this->idArrivedWith !== '') {
			Assert::assertSame(
				$this->idArrivedWith,
				$id,
				'the file is pointing at a DIFFERENT workflow than the one it moved with — it was re-created, not relocated',
			);
		}

		$name = preg_replace('/\.n8n$/', '', basename($this->currentFilePath)) ?? '';
		$found = $this->n8nWorkflowsNamed($name);
		Assert::assertCount(
			1,
			$found,
			sprintf('n8n holds %d workflows named "%s" — a move must relocate one, never mint another', count($found), $name),
		);

		Assert::assertContains(
			$this->mappingTagForFolder($folder),
			$this->n8nWorkflowTagNames($id),
			"the workflow does not carry the tag that binds it to $folder",
		);
	}

	/**
	 * The other half of the same claim, and the one that catches the orphan: nothing
	 * named this is still bound to the folder it left — no file there, and nothing in
	 * n8n wearing that mapping's tag. A workflow left carrying its old tag is pulled
	 * back into the old folder on the next sync, which is how one file becomes two.
	 *
	 * @Then the workflow named :name is no longer under :folder
	 */
	public function theWorkflowNamedIsNoLongerUnder(string $name, string $folder): void {
		$stem = preg_replace('/\.n8n$/', '', $name) ?? $name;
		Assert::assertFalse(
			$this->davExists($folder . '/' . $stem . '.n8n'),
			"$stem.n8n is still in $folder",
		);

		$tag = $this->mappingTagForFolder($folder);
		foreach ($this->n8nWorkflowsNamed($stem) as $row) {
			$names = [];
			foreach ((array)($row['tags'] ?? []) as $t) {
				if (is_array($t)) {
					$names[] = (string)($t['name'] ?? '');
				}
			}
			Assert::assertNotContains(
				$tag,
				$names,
				"a workflow named \"$stem\" still carries '$tag' — the next pull writes it back into $folder",
			);
		}
	}

	/**
	 * THE WHOLE TAG SET, MAPPING TAGS INCLUDED — one surface per sentence, the same
	 * shape `tags.feature` uses.
	 *
	 * Deliberately NOT the `normal tags` steps, which drop the mapping tag before
	 * comparing. That is right where the mapping is fixed and the scenario is about the
	 * user's own labels; it is exactly wrong here, where the mapping tag is the thing
	 * under test. Asserting the full set is also stricter than naming the tags that
	 * should and should not be there: a leftover tag fails it, a missing one fails it,
	 * and so does a set that is somehow both.
	 *
	 * @Then the workflow's tags are :tags in Nextcloud
	 */
	public function theMovedWorkflowsTagsAreInNextcloud(string $tags): void {
		Assert::assertSame(
			self::tagList($tags),
			self::sortedNames($this->fileSystemTags($this->currentFilePath)),
			"the file's Nextcloud pills are not the expected set",
		);
	}

	/**
	 * The file's own `tags` array — the surface that outlives Nextcloud, and the one
	 * that pushes back to n8n on the next save, so a stale entry here re-binds the
	 * workflow to the folder it left.
	 *
	 * @Then the workflow's tags are :tags in the file
	 */
	public function theMovedWorkflowsTagsAreInTheFile(string $tags): void {
		$wf = json_decode($this->davGet($this->currentFilePath), true);
		Assert::assertIsArray($wf, "the file at {$this->currentFilePath} is not JSON");

		$names = [];
		foreach ((array)($wf['tags'] ?? []) as $tag) {
			Assert::assertIsArray($tag, 'a body tag entry is not an object');
			$names[] = (string)($tag['name'] ?? '');
		}
		Assert::assertSame(
			self::tagList($tags),
			self::sortedNames($names),
			"the file's tags array is not the expected set",
		);
	}

	/**
	 * n8n's own answer, read from its API rather than from anything this app reports —
	 * a `Then` that only asks this app proves the app agrees with itself.
	 *
	 * @Then the workflow's tags are :tags in n8n
	 */
	public function theMovedWorkflowsTagsAreInN8n(string $tags): void {
		Assert::assertSame(
			self::tagList($tags),
			self::sortedNames($this->n8nWorkflowTagNames($this->movedWorkflowId())),
			"the workflow's n8n tags are not the expected set",
		);
	}

	/**
	 * The workflow the file under test currently claims. Read from the FILE rather
	 * than from `$lastWorkflowId`, because the move step re-reads that field to follow
	 * a legitimate mint — so trusting it would let a scenario about relocation grade
	 * the workflow it should have proven does not exist.
	 */
	private function movedWorkflowId(): string {
		$id = (string)($this->davReadMetadataId($this->currentFilePath) ?? '');
		Assert::assertNotSame('', $id, 'the file under test carries no workflow id');
		return $id;
	}

	/**
	 * De-duplicated, sorted, blanks dropped — so a set comparison is about membership
	 * and never about the order two APIs happened to answer in.
	 *
	 * @param list<string> $names
	 * @return list<string>
	 */
	private static function sortedNames(array $names): array {
		$out = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
		sort($out);
		return $out;
	}

	/**
	 * Become somebody the folder was SHARED WITH, rather than the person who owns it.
	 *
	 * ## THE WHOLE SCENARIO TURNS ON WHO IS ASKING
	 *
	 * Core's `SharesPlugin::beforeMove` refuses a move when the source is not shareable
	 * AND the destination is a share — and "is a share" is evaluated for the ACTING
	 * user. The folder's owner never sees their own folder as a share, so the refusal is
	 * invisible to them. The suite has always run as one user who owns every admin
	 * folder it creates, which is exactly why this was reported from live use by a group
	 * member and never once by CI.
	 *
	 * So this makes a second account, gives it the group the Team Folder is shared with
	 * (otherwise it cannot even see the file it is about to move), shares the admin
	 * folder with it, and switches the DAV client over. {@see FeatureContext::tearDown}
	 * puts the original user back and removes the account.
	 *
	 * @Given :folder is shared with me rather than owned by me
	 */
	public function folderIsSharedWithMe(string $folder): void {
		$user = 'n8n-member-' . bin2hex(random_bytes(3));
		$pass = 'Member-' . bin2hex(random_bytes(8)) . '!aA1';

		$res = $this->occEnv('user:add --password-from-env ' . escapeshellarg($user), ['OC_PASS' => $pass]);
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not create '$user':\n{$res['output']}");
		}
		$this->borrowedUser = $user;

		// The Team Folder in the Background is shared with the `admin` group, so the
		// member needs it to see the file at all. This grants group membership, not
		// ownership of anything — which is the distinction under test.
		//
		// CHECKED, because a silent failure here surfaces much later as a bare DAV 404 on
		// a file the member cannot see, which reads as a bug in the app rather than in
		// the arrange.
		$res = $this->occ('group:adduser admin ' . escapeshellarg($user));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not add '$user' to the admin group:\n{$res['output']}");
		}

		// Share the admin-owned folder TO them, as the owner. occ has no share command;
		// the OCS API is the only route, and it is the same call the Files UI makes.
		$share = $this->davClient()->request('POST', $this->ncBaseUrl . '/ocs/v2.php/apps/files_sharing/api/v1/shares', [
			'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
			'form_params' => [
				'path' => '/' . $folder,
				'shareType' => 0,          // user share
				'shareWith' => $user,
				// 15 = read+update+create+delete. NOT 31, which adds SHARE (16) — the
				// comment here used to say "all but share" while granting exactly that.
				// Resharing off is both the ordinary setting and the honest one for a
				// scenario about what a non-owner may do.
				'permissions' => 15,
			],
		]);
		$this->assertStatus($share, [200], "share '$folder' with $user");

		// Everything after this line speaks as the member.
		$this->ncUser = $user;
		$this->ncPass = $pass;
		$this->dav = null;
	}

	/**
	 * THE REBIND, IN ONE ASSERTION: the workflow now wears the tag of the mapping it
	 * landed in, and no longer wears the one it came from.
	 *
	 * A mapping owns a workflow by its tag alone, so the tag IS the membership — a move
	 * between mappings that only re-stamped the file would leave n8n believing the
	 * workflow still belongs to the old mapping, and the next pull of THAT mapping would
	 * write the file back into the folder it just left.
	 *
	 * Stated as "this one, and no other mapping's" rather than as an exact set, so it
	 * fails for exactly one reason and leaves the file's own tags to their own step.
	 * The mapping is inferred from the folder the file is in now — that is the point of
	 * the Background spelling the mappings out.
	 *
	 * @Then the workflow carries the mapping's tag, and no other mapping's
	 */
	public function theWorkflowCarriesTheMappingTagAndNoOther(): void {
		$id = (string)($this->lastWorkflowId ?? '');
		Assert::assertNotSame('', $id, 'no workflow under test');

		$want = $this->mappingTagForFolder($this->currentFolder);
		$on = $this->n8nWorkflowTagNames($id);
		Assert::assertContains($want, $on, "the workflow does not carry '$want', the tag binding it to {$this->currentFolder}");

		foreach ($this->listMappings() as $m) {
			$other = (string)($m['n8n_tag'] ?? '');
			if ($other === '' || $other === $want) {
				continue;
			}
			Assert::assertNotContains(
				$other,
				$on,
				"the workflow still carries '$other' — it would be claimed by two mappings and mirrored into both",
			);
		}
	}

	/** @Then the move is refused with a message */
	public function theMoveIsRefusedWithAMessage(): void {
		Assert::assertNotContains($this->lastMoveStatus, [201, 204], "the move was allowed (HTTP {$this->lastMoveStatus}) but should have been refused");
	}

	/** @Then the file stays in the :tag folder */
	public function theFileStaysInTheFolder(string $tag): void {
		$this->theFileStaysIn($this->folderNameForTag($tag));
	}

	/** @Then the file stays in :folder */
	public function theFileStaysIn(string $folder): void {
		Assert::assertTrue($this->davExists($this->currentFilePath), 'the file moved away — the block did not hold');
		Assert::assertStringStartsWith($folder . '/', $this->currentFilePath, "file is not under $folder");
	}

	// ── mapping → mapping (@unbuilt: MoveGuardListener refuses this today) ────────

	/**
	 * @Then the file no longer has the :tag tag in n8n nor Nextcloud
	 *
	 * Both sides, in one sentence, because a half-moved tag is the failure this is
	 * watching for: the workflow re-tagged in n8n while Nextcloud still shows the
	 * old mapping's tag would look fine from either side alone.
	 */
	public function theFileNoLongerHasTheTag(string $tag): void {
		throw new \RuntimeException(
			'mapping→mapping moves are refused by MoveGuardListener — '
			. 'saga §14.2 case (a) needs re-tag vs eject+reattach decided before this can be built'
		);
	}

	/** @Then the file now has the :tag tag in n8n and Nextcloud */
	public function theFileNowHasTheTag(string $tag): void {
		throw new \RuntimeException('mapping→mapping moves are refused by MoveGuardListener — scenario is @unbuilt');
	}

	/** @Then the file's mapping id is updated to the :folder mapping */
	public function theFilesMappingIdIsUpdatedTo(string $folder): void {
		throw new \RuntimeException('mapping→mapping moves are refused by MoveGuardListener — scenario is @unbuilt');
	}

	/** @Then the file's mode is :mode */
	public function theFilesModeIs(string $mode): void {
		$wire = $mode === 'link' ? 'reference' : $mode;
		Assert::assertSame($wire, $this->davReadMetadata($this->currentFilePath, self::META_MODE), "file mode is not $mode");
	}

	/** @Then the file stays :state */
	public function theFileStaysState(string $state): void {
		Assert::assertSame($state, $this->davReadMetadata($this->currentFilePath, self::META_MODE), "file is not in '$state' mode");
	}
}
