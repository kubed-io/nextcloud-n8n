<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Create-on-land steps (UC-6: author in NC, live in n8n). A managed .n8n.json
 * written into a mapped folder over WebDAV fires NodeWrittenEvent →
 * CreateInN8nListener → the workflow appears in n8n. We assert the n8n side over
 * its REST API and the NC stamp over DAV PROPFIND. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait CreateSteps {
	/**
	 * Set up an admin-owned mapping + the backing folder so a WebDAV PUT into it
	 * resolves to a mapping. `resolveForPath` only cares about the folder name, so
	 * the storage kind is invisible to every scenario that uses this.
	 *
	 * ADMIN-OWNED IS A SPEED CHOICE, NOT A DEPENDENCY ONE. It used to be the latter
	 * — "keeps CI free of the groupfolders app" — but groupfolders is installed on
	 * every leg now (integration.yml), and that stale note is why a later scenario
	 * put `| storage | admin folder |` in a table where storage is irrelevant.
	 * A plain folder is simply cheaper to make than a Team Folder mount.
	 *
	 * A scenario that cares which backend it runs on must say so in its own table,
	 * and `use_team_folder` defaults to TRUE — so this is the exception, not the
	 * shape a mapping normally has.
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
	}

	/** @Given a folder that is not mapped */
	public function aFolderThatIsNotMapped(): void {
		$folder = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
	}

	/**
	 * Create a workflow file over WebDAV. Both phrasings ("via the Files New
	 * menu" and a plain create) land the same way server-side — a PUT that fires
	 * NodeWrittenEvent — so one step backs both.
	 *
	 * @When I create a new :ext file in that folder via the Files "New" menu
	 * @When I create a :ext file in that folder
	 */
	public function iCreateAWorkflowFile(string $ext): void {
		Assert::assertNotSame('', $this->currentFolder, 'no current folder — a Given must set one');
		$name = 'demo-' . bin2hex(random_bytes(3)) . $ext;
		$path = $this->currentFolder . '/' . $name;
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

	/** @Then a matching workflow is created in n8n */
	public function aMatchingWorkflowIsCreatedInN8n(): void {
		Assert::assertNotNull($this->lastWorkflowId, 'the file was not stamped with an n8n_id — no workflow was created');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "n8n has no workflow with id {$this->lastWorkflowId}");
		Assert::assertSame($this->lastWorkflowId, (string)($wf['id'] ?? ''), 'n8n returned a different workflow id');
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
