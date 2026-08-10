<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Delete steps (UC-7: delete/trash/restore mirrors into n8n). DeleteToN8nListener
 * runs synchronously on BeforeNodeDeletedEvent (it must, to abort the NC delete
 * when n8n is down). Soft step = trash-move; hard = purge from trash; restore =
 * move back out of the trashbin. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait DeleteSteps {
	/** @Given a trashed :mode workflow file */
	public function aTrashedWorkflowFile(string $mode): void {
		$this->aManagedWorkflowFile($mode);
		$this->davDelete($this->currentFilePath); // → trashbin (soft step)
	}

	/**
	 * A trashed *unmapped* file: arrange the unmapped move-out (MoveSteps, composed
	 * here) so the workflow is archived + the file carries its id, then trash it.
	 *
	 * @Given a trashed unmapped workflow file that still carries its :key
	 */
	public function aTrashedUnmappedWorkflowFile(string $key): void {
		$this->anUnmappedWorkflowFileCarryingItsId($key);
		$this->davDelete($this->currentFilePath); // → trashbin (soft step)
	}

	/**
	 * A plain .n8n.json with no n8n metadata — "untracked", distinct from the
	 * "unmapped" mode (saga Chapter 3 §14) which keeps its id + an archived workflow.
	 *
	 * @Given an untracked :ext file
	 */
	public function anUntrackedFile(string $ext): void {
		$folder = 'untracked-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$path = $folder . '/plain-' . bin2hex(random_bytes(3)) . $ext;
		$this->davPut($path, json_encode(['name' => 'Plain', 'nodes' => [], 'connections' => new \stdClass()], JSON_THROW_ON_ERROR));
		$this->currentFilePath = $path;
		$this->lastWorkflowId = null;
	}

	/**
	 * @When I move it to the trash
	 * @When I delete it
	 */
	public function iMoveItToTheTrash(): void {
		$this->lastDeleteStatus = $this->davDeleteStatus($this->currentFilePath);
	}

	/**
	 * WHERE THE FILE IS, stated as a fact — for a scenario whose action is the
	 * restore or the purge that follows.
	 *
	 * A Given says what is already TRUE; it does not perform a gesture. `I have
	 * moved it to the trash` read as an action in the past tense, which is still an
	 * action — and the file being in the trash is a state, so it says that instead.
	 * Getting there requires a trash move, but that is the step's problem, not the
	 * scenario's.
	 *
	 * @Given the file is in the trash
	 */
	public function theFileIsInTheTrash(): void {
		$this->iMoveItToTheTrash();
		Assert::assertNotNull(
			$this->trashbinPathFor($this->currentFilePath),
			'setup: the file is not in the trash',
		);
	}

	/**
	 * THE WORKFLOW'S STATE IN n8n, NAMED — the post-state half of every gesture in
	 * the trash lifecycle, and the reason those three files read as mirror images of
	 * each other rather than as unrelated assertions.
	 *
	 * The four states a workflow can be in from Nextcloud's point of view, and what
	 * each one means:
	 *
	 *   archived, hidden but preserved   n8n has it, marked archived — reversible
	 *   live, unarchived                 n8n has it, not archived
	 *   live and untouched               n8n has it and this app never wrote to it
	 *   gone, permanently deleted        n8n does not have it at all
	 *
	 * `live, unarchived` and `live and untouched` describe the same n8n row and are
	 * deliberately two phrases: the first is the RESULT of a restore, the second is
	 * the claim that a gesture did not reach n8n at all. A scenario should say which
	 * one it means.
	 *
	 * @Then the workflow in n8n is :state
	 */
	public function theWorkflowInN8nIs(string $state): void {
		$id = (string)$this->lastWorkflowId;
		Assert::assertNotSame('', $id, 'no workflow under test');
		$wf = $this->n8nGetWorkflow($id);

		if ($state === 'gone, permanently deleted') {
			Assert::assertNull($wf, "workflow $id is still in n8n, but the purge should have deleted it");
			return;
		}

		Assert::assertIsArray($wf, "workflow $id is gone from n8n, but this gesture should have preserved it");
		$archived = (bool)($wf['isArchived'] ?? false);
		$want = match ($state) {
			'archived, hidden but preserved' => true,
			'live, unarchived', 'live and untouched' => false,
			default => throw new \RuntimeException("unknown workflow state '$state' — see the step's docblock for the four"),
		};
		Assert::assertSame($want, $archived, "workflow $id is " . ($archived ? 'archived' : 'live') . ", expected: $state");
	}

	/** @When I purge it from the trash */
	public function iPurgeItFromTheTrash(): void {
		$trashPath = $this->trashbinPathFor($this->currentFilePath);
		Assert::assertNotNull($trashPath, 'could not find the file in the trashbin to purge');
		$res = $this->davClient()->request('DELETE', $this->trashHref($trashPath));
		$this->assertStatus($res, [204, 200], 'purge from trash');
	}

	/** @When I restore it from the trash */
	public function iRestoreItFromTheTrash(): void {
		$trashPath = $this->trashbinPathFor($this->currentFilePath);
		Assert::assertNotNull($trashPath, 'could not find the file in the trashbin to restore');
		$dest = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/restore/' . rawurlencode(basename($trashPath));
		$res = $this->davClient()->request('MOVE', $this->trashHref($trashPath), [
			'headers' => ['Destination' => $dest],
		]);
		$this->assertStatus($res, [201, 204], 'restore from trash');
	}

	/** @Then /^the workflow is archived \(hidden, preserved\) in n8n$/ */
	public function theWorkflowIsArchivedInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone — it should be archived, not deleted");
		Assert::assertTrue((bool)($wf['isArchived'] ?? false), 'workflow is not archived in n8n');
	}

	/** @Then the workflow is permanently deleted in n8n */
	public function theWorkflowIsDeletedInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertNull($wf, "workflow {$this->lastWorkflowId} still exists in n8n");
	}

	/** @Then the workflow is unarchived in n8n */
	public function theWorkflowIsUnarchivedInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone");
		Assert::assertFalse((bool)($wf['isArchived'] ?? false), 'workflow is still archived in n8n');
	}

	/** @Then the trash move succeeds */
	public function theTrashMoveSucceeds(): void {
		Assert::assertContains($this->lastDeleteStatus, [204, 200], 'the trash move did not succeed');
	}

	/**
	 * An unmapped op (trash / restore of a moved-out file) must not touch n8n:
	 * the workflow stays present and stays archived. Reuses lastWorkflowId set by
	 * the unmapped arrange.
	 *
	 * @Then the archived workflow in n8n is left as-is
	 */
	public function theArchivedWorkflowIsLeftAsIs(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone — an unmapped no-op must leave it present");
		Assert::assertTrue((bool)($wf['isArchived'] ?? false), 'the archived workflow changed — an unmapped op must leave it as-is');
	}

	/** @Then the mapping tag is stripped from the workflow in n8n */
	public function theMappingTagIsStripped(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone");
		$names = array_map(
			static fn (array $t): string => (string)($t['name'] ?? ''),
			array_values(array_filter((array)($wf['tags'] ?? []), 'is_array')),
		);
		Assert::assertNotContains($this->currentTag, $names, "tag '{$this->currentTag}' was not stripped (has: " . implode(',', $names) . ')');
	}

	/** @Then the workflow itself is not archived or deleted */
	public function theWorkflowIsNotArchivedOrDeleted(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} was deleted — link must leave it alone");
		Assert::assertFalse((bool)($wf['isArchived'] ?? false), 'workflow was archived — link must leave it alone');
	}

	/** @Then n8n is not contacted */
	public function n8nIsNotContacted(): void {
		// Operative meaning: the unmapped file had no n8n id, so nothing could be
		// contacted, and the NC delete succeeded normally.
		Assert::assertNull($this->lastWorkflowId, 'an unmapped file unexpectedly had an n8n id');
		Assert::assertContains($this->lastDeleteStatus, [204, 200], 'the unmapped delete did not succeed');
	}
}
