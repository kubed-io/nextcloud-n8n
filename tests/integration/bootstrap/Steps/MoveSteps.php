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

	/** Shared body of the two arranges above. */
	private function arrangeManagedFileIn(string $folder, string $mode): void {
		$this->currentFolder = $folder;
		$this->putManagedFile($this->currentFolder . '/Mover.n8n.json', 'Mover');
		$this->lastVersionId = $this->davReadMetadata($this->currentFilePath, self::META_VERSION_ID);
		$this->expectedArchived = false; // sync/link create leaves the workflow live

		// $mode USED TO BE IGNORED ENTIRELY — every caller got a `sync` file whatever
		// they asked for. Harmless while only sync/link called it (a create leaves the
		// workflow live either way), and a silent lie the moment anything asked for
		// `ignored`: the scenario would arrange the opposite of its own Given and then
		// assert against it. A step must honour the parameter it accepts.
		if ($mode === 'ignored') {
			$this->assignSystemTag($this->currentFilePath, 'n8n:ignore');
			Assert::assertSame(
				'ignored',
				$this->davReadMetadata($this->currentFilePath, self::META_MODE),
				'arrange precondition failed: tagging n8n:ignore did not set mode=ignored',
			);
			$this->expectedArchived = true;
		}
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
		$dest = $folder . '/Mover-renamed.n8n.json';
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
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		$this->expectedArchived = false; // move-in restores (unarchives) the workflow
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
	 * A plain `.n8n.json` that was never tracked (no n8n id) sitting outside any
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
	 */
	public function anUnmappedCopyOfThatSameWorkflow(string $key): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id from the managed sync file');
		$this->collisionWorkflowId = $this->lastWorkflowId;

		// THE MAPPING IS WHICHEVER ONE THE PRECEDING GIVEN USED. Hardcoded
		// `nextcloud:alpha` here tied this arrange to one feature's tag naming, in a
		// step move.feature and copy.feature both call.
		$sourceFolder = $this->currentFolder;
		$sourceTag = $this->currentTag;

		// Move the synced file OUT → it becomes the unmapped copy (id preserved, workflow archived).
		$this->iMoveTheFileToAnUnmappedFolder();
		Assert::assertSame(
			'unmapped',
			$this->davReadMetadata($this->currentFilePath, self::META_MODE),
			'setup: the moved-out copy is not unmapped',
		);
		$this->collisionIncomingPath = $this->currentFilePath;

		// Bring the workflow back to life and pull the mapping so a fresh SYNCED file is
		// written into it from n8n — the "existing synced copy" the move-in must defer to.
		$this->n8nUnarchiveWorkflow($this->collisionWorkflowId);
		$this->runMappingSync('pull', $sourceTag);

		$this->collisionSyncedPath = $sourceFolder . '/Mover.n8n.json';
		Assert::assertSame(
			$this->collisionWorkflowId,
			$this->davReadMetadataId($this->collisionSyncedPath),
			'setup: the pulled synced file does not carry the shared workflow id',
		);
		Assert::assertSame(
			'sync',
			$this->davReadMetadata($this->collisionSyncedPath, self::META_MODE),
			'setup: the pulled file is not in sync mode',
		);

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
		$dest = $folder . '/Mover-incoming.n8n.json';
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
