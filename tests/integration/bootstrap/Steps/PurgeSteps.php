<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Purge steps (`purge.feature`): the admin "Purge Nextcloud files" action, driven
 * headlessly through `occ n8n_sync:purge` ({@see \OCA\N8nSync\Command\Purge}) which
 * runs {@see \OCA\N8nSync\Service\SyncService::purge()}. Purge deletes only the
 * restorable (sync/link) files; the assertions prove n8n is untouched, the mapping
 * survives, a deliberately-kept standalone (unmapped) file is left alone, and a
 * later "Sync from n8n" brings the files back. Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext};
 * reuses the file/mapping arrange from the move/setup traits and the
 * PROPFIND-by-id + n8n-REST helpers.
 */
trait PurgeSteps {
	/** A file we deliberately keep across a purge (a standalone / unmapped copy). */
	private string $purgeKeepPath = '';
	private string $purgeKeepBody = '';

	/** @Given I remember the unmapped file */
	public function iRememberTheUnmappedFile(): void {
		Assert::assertNotSame('', $this->currentFilePath, 'no current file to remember');
		$this->purgeKeepPath = $this->currentFilePath;
		$this->purgeKeepBody = $this->davGet($this->currentFilePath);
	}

	/** @When the admin purges the Nextcloud files */
	public function theAdminPurgesTheNextcloudFiles(): void {
		$res = $this->occ('n8n_sync:purge');
		// Plain throw (not a PHPUnit assert) so a failure shows the real exit+output
		// — see the WebDavTrait/Reconcile note about the masked PHPUnit-under-Behat error.
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("purge failed (exit {$res['exit']}):\n{$res['output']}");
		}
	}

	/** @Then no managed workflow files remain in the :tag folder */
	public function noManagedFilesRemainInFolder(string $tag): void {
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($tag));
		Assert::assertSame([], $byId, 'purge left managed files in the folder: ' . implode(', ', array_values($byId)));
	}

	/** @Then the workflow still exists in n8n */
	public function theWorkflowStillExistsInN8n(): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id captured for the purge assertion');
		Assert::assertNotNull(
			$this->n8nGetWorkflow($this->lastWorkflowId),
			'purge removed the workflow from n8n — it must never contact n8n',
		);
	}

	/** @Then the :tag mapping is still configured */
	public function theMappingIsStillConfigured(string $tag): void {
		$res = $this->occ('n8n_sync:list-mappings');
		Assert::assertSame(0, $res['exit'], "list-mappings failed:\n{$res['output']}");
		Assert::assertStringContainsString($tag, $res['output'], "the $tag mapping is gone after a purge");
	}

	/**
	 * The purge deletes files this app CREATED, and an `ignored` file is deliberately
	 * not one of them: it kept its id and its place, and the user excluded it on
	 * purpose. Asserted by the file still being readable at its path with its metadata
	 * intact — a purge that removed it would 404 here.
	 *
	 * @Then that ignored file is left in place
	 */
	public function thatIgnoredFileIsLeftInPlace(): void {
		Assert::assertNotSame('', $this->currentFilePath, 'no current file to check');
		Assert::assertTrue(
			$this->davExists($this->currentFilePath),
			"the purge deleted the ignored file at {$this->currentFilePath}",
		);
		Assert::assertSame(
			'ignored',
			$this->davReadMetadata($this->currentFilePath, 'n8n_mode'),
			'the file survived but is no longer `ignored`',
		);
	}

	/** @Then the remembered file is left in place */
	public function theRememberedFileIsLeftInPlace(): void {
		Assert::assertNotSame('', $this->purgeKeepPath, 'no file was remembered');
		Assert::assertTrue($this->davExists($this->purgeKeepPath), 'purge deleted a standalone file it should have kept');
		Assert::assertSame($this->purgeKeepBody, $this->davGet($this->purgeKeepPath), 'purge modified a file it should have left alone');
	}

	/** @Then the workflow appears again as a file in the :tag folder */
	public function theWorkflowAppearsAgainInFolder(string $tag): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id captured');
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($tag));
		Assert::assertArrayHasKey(
			$this->lastWorkflowId,
			$byId,
			'the workflow did not come back as a file after Sync from n8n',
		);
	}
}
