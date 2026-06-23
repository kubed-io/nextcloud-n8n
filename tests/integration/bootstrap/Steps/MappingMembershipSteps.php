<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Folder-membership steps (saga §14.9 `mapping-membership.feature`): a mapping is
 * metadata on a folder, so a file's mapping is "where it lives", and folders
 * **nest** — a mapping inside an already-mapped folder wins for files beneath it
 * (the nearest enclosing mapping). This is the real, server-observable proof of
 * {@see \OCA\N8nSync\Service\MappingService::resolveForPath}: we set up admin-owned
 * mappings (no groupfolders needed in CI), PUT a workflow file over WebDAV so the
 * create listener resolves its mapping, and read the resulting `n8n_mapping` stamp
 * back over DAV PROPFIND. Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 *
 * Reuses {@see CreateSteps::aFolderThatIsNotMapped} (the "Given a folder that is
 * not mapped" step) and the shared WebDAV / occ / n8n transport helpers.
 */
trait MappingMembershipSteps {
	/** Mapping id keyed by the n8n tag the scenario created it under. */
	private array $membershipMappingIds = [];
	/** The outer mapped folder, so a nested "subfolder of it" can be built under it. */
	private string $membershipOuterFolder = '';
	/** The mapping id the last membership assertion expects (for the "nearest wins" restatement). */
	private string $membershipExpectedId = '';

	// ── Given ─────────────────────────────────────────────────────────────────

	/** @Given a folder mapped to the n8n tag :tag */
	public function aFolderMappedToTheN8nTag(string $tag): void {
		$folder = $this->folderNameForTag($tag);
		$this->davMkdir($folder);
		$this->addMembershipMapping($tag, $folder);
		$this->membershipOuterFolder = $folder;
		$this->currentFolder = $folder;
	}

	/** @Given a subfolder of it mapped to the n8n tag :tag */
	public function aSubfolderOfItMappedToTheN8nTag(string $tag): void {
		Assert::assertNotSame('', $this->membershipOuterFolder, 'no outer folder — a "folder mapped to" Given must run first');
		$subfolder = $this->membershipOuterFolder . '/' . $this->folderNameForTag($tag);
		// The outer folder is already registered for teardown; deleting it removes
		// this nested child too, so we only MKCOL here (and skip createdFolders, as
		// the teardown DELETE rawurlencodes and can't address a nested path anyway).
		$this->davMkcolNested($subfolder);
		$this->addMembershipMapping($tag, $subfolder);
		$this->currentFolder = $subfolder;
	}

	// ── When ──────────────────────────────────────────────────────────────────

	/**
	 * Land a workflow file in the current folder over WebDAV. In a mapped folder
	 * the create listener registers it (stamping n8n_id + n8n_mapping); in an
	 * unmapped folder it stays a plain, untracked file. All three phrasings drive
	 * the same PUT — the folder set by the preceding Given is what differs.
	 *
	 * @When a managed workflow file lives in that folder
	 * @When a workflow file lives in that folder
	 * @When a workflow file lives in the subfolder
	 */
	public function aWorkflowFileLivesInTheFolder(): void {
		Assert::assertNotSame('', $this->currentFolder, 'no current folder — a Given must set one');
		$name = 'member-' . bin2hex(random_bytes(3)) . '.n8n.json';
		$path = $this->currentFolder . '/' . $name;
		$body = json_encode([
			'name' => 'Member ' . substr($name, 0, 12),
			'nodes' => [],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		], JSON_THROW_ON_ERROR);
		$this->davPut($path, $body);
		$this->currentFilePath = $path;
		$id = $this->davReadMetadataId($path);
		if ($id !== null && $id !== '') {
			$this->lastWorkflowId = $id;
			$this->createdWorkflowIds[] = $id;
		} else {
			$this->lastWorkflowId = null;
		}
	}

	// ── Then ──────────────────────────────────────────────────────────────────

	/** @Then the file belongs to the :tag mapping */
	public function theFileBelongsToTheMapping(string $tag): void {
		$expected = $this->membershipMappingIds[$tag] ?? null;
		Assert::assertNotNull($expected, "no mapping was created for tag '$tag'");
		$actual = $this->davReadMetadata($this->currentFilePath, self::META_MAPPING);
		Assert::assertSame($expected, $actual, "file's n8n_mapping is not the '$tag' mapping");
		$this->membershipExpectedId = $expected;
	}

	/** @Then the file belongs to no mapping */
	public function theFileBelongsToNoMapping(): void {
		$mapping = $this->davReadMetadata($this->currentFilePath, self::META_MAPPING);
		Assert::assertTrue($mapping === null || $mapping === '', "file unexpectedly carries an n8n_mapping='$mapping'");
	}

	/** @Then it is :untracked if it has no n8n id, or :unmapped if it carries one */
	public function itIsUntrackedOrUnmapped(string $untracked, string $unmapped): void {
		$id = $this->davReadMetadata($this->currentFilePath, self::META_ID);
		if ($id === null || $id === '') {
			// No id → the file was never registered: it is "untracked".
			Assert::assertSame('untracked', $untracked, 'scenario mislabels the no-id state');
		} else {
			// It carries an id but lives outside every mapping: it is "unmapped".
			Assert::assertSame('unmapped', $unmapped, 'scenario mislabels the carries-id state');
			Assert::assertSame('unmapped', $this->davReadMetadata($this->currentFilePath, self::META_MODE), 'a file with an id outside a mapping should read as unmapped');
		}
	}

	/** @Then it belongs to the :tag mapping, not :other */
	public function itBelongsToTheMappingNot(string $tag, string $other): void {
		$expected = $this->membershipMappingIds[$tag] ?? null;
		$otherId = $this->membershipMappingIds[$other] ?? null;
		Assert::assertNotNull($expected, "no mapping was created for tag '$tag'");
		$actual = $this->davReadMetadata($this->currentFilePath, self::META_MAPPING);
		Assert::assertSame($expected, $actual, "file resolved to the wrong mapping (wanted '$tag')");
		if ($otherId !== null) {
			Assert::assertNotSame($otherId, $actual, "file resolved to the outer '$other' mapping instead of the nearer '$tag'");
		}
		$this->membershipExpectedId = $expected;
	}

	/** @Then the nearest enclosing mapping wins */
	public function theNearestEnclosingMappingWins(): void {
		// A readable restatement of the previous assertion: the file's stamped
		// mapping is the nearer (deeper) one we just checked for.
		Assert::assertNotSame('', $this->membershipExpectedId, 'no membership expectation was set');
		Assert::assertSame($this->membershipExpectedId, $this->davReadMetadata($this->currentFilePath, self::META_MAPPING), 'the nearest enclosing mapping did not win');
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/** Add an admin-owned mapping (no groupfolders) at $folder for $tag, remembering its id. */
	private function addMembershipMapping(string $tag, string $folder): void {
		$id = 'mm-' . bin2hex(random_bytes(4));
		$data = [
			'id' => $id,
			'n8n_tag' => $tag,
			'team_folder' => $folder,
			'nc_groups' => ['admin'],
			'mode' => 'sync',
			'use_team_folder' => false,
		];
		$res = $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
		// RuntimeException, not Assert: a failing PHPUnit assertion under Behat +
		// PHPUnit 12 throws the opaque Registry::get() TypeError that masks the real
		// message (see WebDavTrait::assertStatus). A plain throw shows exit + output.
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("add-mapping for $tag failed (exit {$res['exit']}):\n{$res['output']}");
		}
		$this->membershipMappingIds[$tag] = $id;
	}

	/** MKCOL a possibly-nested folder path (davMkdir only handles a single segment). */
	private function davMkcolNested(string $path): void {
		$this->assertStatus(
			$this->davClient()->request('MKCOL', $this->davEncode($path)),
			[201, 405],
			"MKCOL $path",
		);
	}
}
