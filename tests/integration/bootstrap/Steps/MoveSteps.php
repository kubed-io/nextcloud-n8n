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
	 * A managed sync/link file living in one of the Background's mapped folders,
	 * addressed by its tag. Captures the id + versionId for later "unchanged" checks.
	 *
	 * @Given a managed :mode workflow file in the :tag folder
	 */
	public function aManagedWorkflowFileInTheFolder(string $mode, string $tag): void {
		$this->currentFolder = $this->folderNameForTag($tag);
		$this->currentTag = $tag;
		$this->putManagedFile($this->currentFolder . '/Mover.n8n.json', 'Mover');
		$this->lastVersionId = $this->davReadMetadata($this->currentFilePath, self::META_VERSION_ID);
		$this->expectedArchived = false; // sync/link create leaves the workflow live
	}

	/**
	 * An *unmapped* file that still carries its id: set up a managed sync file in
	 * the alpha mapping, then move it OUT to an unmapped folder so the motion path
	 * archives it and stamps `unmapped`. Leaves it sitting outside any mapping.
	 *
	 * @Given an unmapped workflow file that still carries its :key
	 */
	public function anUnmappedWorkflowFileCarryingItsId(string $key): void {
		$this->aManagedWorkflowFileInTheFolder('sync', 'nextcloud:alpha');
		$this->iMoveTheFileToAnUnmappedFolder();
		Assert::assertSame(
			'unmapped',
			$this->davReadMetadata($this->currentFilePath, self::META_MODE),
			'setup precondition failed: the file is not unmapped after the move-out',
		);
	}

	/** @When I rename the file within the :tag folder */
	public function iMoveRenameTheFileWithinTheFolder(string $tag): void {
		$dest = $this->folderNameForTag($tag) . '/Mover-renamed.n8n.json';
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/** @When I move the file into a subfolder of the :tag folder */
	public function iMoveTheFileIntoASubfolder(string $tag): void {
		$sub = $this->folderNameForTag($tag) . '/sub';
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
		$dest = $this->folderNameForTag($tag) . '/' . basename($this->currentFilePath);
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

		// Move the synced file OUT → it becomes the unmapped copy (id preserved, workflow archived).
		$this->iMoveTheFileToAnUnmappedFolder();
		Assert::assertSame(
			'unmapped',
			$this->davReadMetadata($this->currentFilePath, self::META_MODE),
			'setup: the moved-out copy is not unmapped',
		);
		$this->collisionIncomingPath = $this->currentFilePath;

		// Bring the workflow back to life and pull alpha so a fresh SYNCED file is written
		// into the mapping from n8n — the "existing synced copy" the move-in must defer to.
		$this->n8nUnarchiveWorkflow($this->collisionWorkflowId);
		$this->runMappingSync('pull', 'nextcloud:alpha');

		$this->collisionSyncedPath = $this->folderNameForTag('nextcloud:alpha') . '/Mover.n8n.json';
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
		$dest = $this->folderNameForTag($tag) . '/Mover-incoming.n8n.json';
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
		$dest = $this->folderNameForTag($tag) . '/' . basename($this->collisionSyncedPath);
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

	/** @Then the file stays in :mode mode in the :tag mapping */
	public function theFileStaysInModeInTheMapping(string $mode, string $tag): void {
		$expected = $this->modeToModel($mode);
		Assert::assertSame($expected, $this->davReadMetadata($this->currentFilePath, self::META_MODE), "file is not in $mode mode");
		Assert::assertStringStartsWith($this->folderNameForTag($tag) . '/', $this->currentFilePath, 'file is not under the mapped folder');
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
		Assert::assertTrue($this->davExists($this->currentFilePath), 'the file moved away — the block did not hold');
		Assert::assertStringStartsWith($this->folderNameForTag($tag) . '/', $this->currentFilePath, 'file is not under the expected folder');
	}

	/** @Then the file stays :state */
	public function theFileStaysState(string $state): void {
		Assert::assertSame($state, $this->davReadMetadata($this->currentFilePath, self::META_MODE), "file is not in '$state' mode");
	}
}
