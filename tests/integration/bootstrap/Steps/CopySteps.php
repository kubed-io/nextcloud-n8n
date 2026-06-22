<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Copy lifecycle (copy.feature). A COPY is the opposite of a MOVE: always a
 * brand-new instance, never the original's identity. NC fires NodeCopiedEvent,
 * which CopyListener handles by stripping any inherited metadata and (if the
 * copy landed in a mapping) registering it as a fresh workflow. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait CopySteps {
	/** @When I copy the file within the :tag folder */
	public function iCopyTheFileWithinTheFolder(string $tag): void {
		$dest = $this->folderNameForTag($tag) . '/' . $this->copyBasename();
		$this->davCopy($this->currentFilePath, $dest);
		$this->captureCopy($dest);
	}

	/** @When I copy the file to a folder that is not mapped */
	public function iCopyTheFileToAnUnmappedFolder(): void {
		$folder = 'unmapped-copy-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$dest = $folder . '/' . $this->copyBasename();
		$this->davCopy($this->currentFilePath, $dest);
		$this->captureCopy($dest);
	}

	/** @When I copy the file into the :tag folder */
	public function iCopyTheFileIntoTheFolder(string $tag): void {
		$dest = $this->folderNameForTag($tag) . '/' . $this->copyBasename();
		$this->davCopy($this->currentFilePath, $dest);
		$this->captureCopy($dest);
	}

	/** @Then the copy carries no inherited :key */
	public function theCopyCarriesNoInherited(string $key): void {
		$copyId = $this->davReadMetadata($this->copyFilePath, $key);
		Assert::assertNotSame($this->lastWorkflowId, $copyId, "the copy inherited the original's $key — a copy must never hijack identity");
	}

	/** @Then the copy is registered as a NEW workflow in n8n with its own id */
	public function theCopyIsRegisteredAsANewWorkflow(): void {
		Assert::assertNotNull($this->copyWorkflowId, 'the copy was not stamped with an n8n_id — create-on-copy did not run');
		Assert::assertNotSame('', $this->copyWorkflowId, 'the copy has an empty n8n_id');
		Assert::assertNotSame($this->lastWorkflowId, $this->copyWorkflowId, 'the copy reused the original workflow id');
		Assert::assertIsArray($this->n8nGetWorkflow($this->copyWorkflowId), "the copy's workflow {$this->copyWorkflowId} does not exist in n8n");
	}

	/** @Then the original file and workflow are unchanged */
	public function theOriginalFileAndWorkflowAreUnchanged(): void {
		Assert::assertSame($this->lastWorkflowId, $this->davReadMetadataId($this->currentFilePath), 'the original file lost or changed its id');
		Assert::assertIsArray($this->n8nGetWorkflow($this->lastWorkflowId), 'the original workflow disappeared from n8n');
	}

	/** @Then there are now two distinct workflows in n8n */
	public function thereAreNowTwoDistinctWorkflows(): void {
		Assert::assertNotNull($this->copyWorkflowId, 'no copy workflow was created');
		Assert::assertNotSame($this->lastWorkflowId, $this->copyWorkflowId, 'the two workflows share an id');
		Assert::assertIsArray($this->n8nGetWorkflow($this->lastWorkflowId), 'the original workflow is gone');
		Assert::assertIsArray($this->n8nGetWorkflow($this->copyWorkflowId), 'the copy workflow is gone');
	}

	/** @Then the copy has no n8n metadata */
	public function theCopyHasNoN8nMetadata(): void {
		$id = $this->davReadMetadata($this->copyFilePath, self::META_ID);
		Assert::assertTrue($id === null || $id === '', "the copy carries an n8n_id ('$id') but should have none");
	}

	/** @Then no workflow is created in n8n for the copy */
	public function noWorkflowIsCreatedForTheCopy(): void {
		Assert::assertTrue($this->copyWorkflowId === null || $this->copyWorkflowId === '', 'a workflow was created in n8n for an unmapped copy');
	}

	/** @Then the copy is treated as a plain document */
	public function theCopyIsTreatedAsAPlainDocument(): void {
		Assert::assertTrue($this->davExists($this->copyFilePath), 'the copy vanished');
		$this->theCopyHasNoN8nMetadata();
	}

	/** @Then the original unmapped file keeps its :key */
	public function theOriginalUnmappedFileKeepsItsId(string $key): void {
		Assert::assertSame($this->lastWorkflowId, $this->davReadMetadata($this->currentFilePath, $key), "the original unmapped file lost its $key");
	}

	/** @Then the original unmapped file's workflow is not restored or duplicated */
	public function theOriginalUnmappedWorkflowIsNotRestoredOrDuplicated(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, 'the original workflow disappeared from n8n');
		Assert::assertTrue((bool)($wf['isArchived'] ?? false), 'the original workflow was unexpectedly restored (unarchived) by a copy');
		if ($this->copyWorkflowId !== null && $this->copyWorkflowId !== '') {
			Assert::assertNotSame($this->lastWorkflowId, $this->copyWorkflowId, 'the copy duplicated the original workflow id');
		}
	}

	/** A unique copy basename so a COPY never collides with its source (Overwrite: F). */
	private function copyBasename(): string {
		return 'Copy-' . bin2hex(random_bytes(3)) . '.n8n.json';
	}

	/** Record the just-made copy and the workflow id (if any) create-on-copy stamped. */
	private function captureCopy(string $dest): void {
		$this->copyFilePath = $dest;
		$this->copyWorkflowId = $this->davReadMetadataId($dest);
		if (is_string($this->copyWorkflowId) && $this->copyWorkflowId !== '') {
			$this->createdWorkflowIds[] = $this->copyWorkflowId;
		}
	}
}
