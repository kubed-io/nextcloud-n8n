<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Manual per-mapping sync steps (saga Ch2 §14.6 `reconcile.feature`): the admin's
 * two buttons, each scoped to ONE mapping —
 *   - "Sync from n8n" (pull): bring the mapping's tagged workflows into its folder,
 *     update files in place by `n8n_id`, and prune files whose workflow lost the tag.
 *   - "Sync to n8n"   (push): send the mapping's `sync` files up to their workflows.
 * Both FULLY IGNORE `unmapped` files — those live outside any mapping, so a
 * mapping-scoped sync never sees them.
 *
 * The buttons are driven headlessly through our occ surface
 * `n8n_sync:sync <pull|push> --mapping=<tag>` ({@see \OCA\N8nSync\Command\Reconcile}),
 * which runs {@see \OCA\N8nSync\Service\SyncService::dispatch()} inline. The n8n REST
 * helpers below seed/inspect the workflow+tag state the pull reconciles against.
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait ReconcileSteps {
	/**
	 * Workflow ids this scenario seeded directly in n8n (tagged for the mapping),
	 * keyed by name so a Then can find "the workflow that lost its tag".
	 *
	 * @var array<string,string>
	 */
	private array $seededWorkflows = [];

	/** The unmapped file we planted outside every mapping, and its on-disk body. */
	private string $reconcileUnmappedPath = '';
	private string $reconcileUnmappedBody = '';

	/** The mapping folder + sync files (path ⇒ n8n id) a push scenario set up. */
	private array $reconcileSyncFiles = [];

	// ── Given ─────────────────────────────────────────────────────────────────

	/** @Given n8n has workflows tagged :tag */
	public function n8nHasWorkflowsTagged(string $tag): void {
		$tagId = $this->ensureN8nTag($tag);
		foreach (['Reconcile-Alpha', 'Reconcile-Beta'] as $name) {
			$unique = $name . '-' . bin2hex(random_bytes(3));
			$id = $this->createN8nWorkflow($unique, [$tagId]);
			$this->seededWorkflows[$unique] = $id;
		}
		Assert::assertCount(2, $this->seededWorkflows, 'failed to seed tagged workflows in n8n');
	}

	/** @Given an unmapped workflow file exists outside every mapping */
	public function anUnmappedWorkflowFileExists(): void {
		$folder = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$this->reconcileUnmappedBody = json_encode([
			'name' => 'Unmapped Bystander',
			'nodes' => [],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		], JSON_THROW_ON_ERROR);
		$this->reconcileUnmappedPath = $folder . '/Bystander.n8n.json';
		$this->davPut($this->reconcileUnmappedPath, $this->reconcileUnmappedBody);
	}

	/** @Given the :tag folder has sync workflow files with local changes */
	public function theFolderHasSyncFilesWithLocalChanges(string $tag): void {
		$folder = $this->folderNameForTag($tag);
		// Two files authored in NC → create-on-land registers each as a workflow.
		foreach (['Pushable-One', 'Pushable-Two'] as $base) {
			$path = $folder . '/' . $base . '-' . bin2hex(random_bytes(3)) . '.n8n.json';
			$this->putManagedFile($path, $base);
			$id = $this->lastWorkflowId;
			Assert::assertNotNull($id, "managed file $path was not stamped with an n8n_id");
			// Make a LOCAL change the push must carry up: rename the workflow body.
			$changed = json_encode([
				'name' => 'Locally Changed ' . $base,
				'nodes' => [],
				'connections' => new \stdClass(),
				'settings' => new \stdClass(),
			], JSON_THROW_ON_ERROR);
			$this->davPut($path, $changed);
			$this->reconcileSyncFiles[$path] = $id;
		}
		$this->currentFolder = $folder;
		$this->currentTag = $tag;
	}

	// ── When ──────────────────────────────────────────────────────────────────

	/** @When the admin clicks :button for the :tag mapping */
	public function theAdminClicksSyncForMapping(string $button, string $tag): void {
		$direction = match ($button) {
			'Sync from n8n' => 'pull',
			'Sync to n8n' => 'push',
			default => throw new \InvalidArgumentException("unknown sync button '$button'"),
		};
		// Remember the tag so argless Thens (update-in-place, prune) can find the folder.
		$this->currentTag = $tag;
		$this->runMappingSync($direction, $tag);
	}

	// ── Then (pull) ───────────────────────────────────────────────────────────

	/** @Then each :tag workflow appears as a file in the mapped folder */
	public function eachWorkflowAppearsAsAFile(string $tag): void {
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($tag));
		foreach ($this->seededWorkflows as $name => $id) {
			Assert::assertArrayHasKey($id, $byId, "workflow '$name' ($id) did not pull into the mapped folder");
		}
	}

	/** @Then existing files are updated in place — matched by workflow id, never duplicated */
	public function existingFilesAreUpdatedInPlaceNeverDuplicated(): void {
		$folder = $this->folderNameForTag($this->currentTag);
		// A second pull must be idempotent: same workflow id → still exactly one file.
		$this->runMappingSync('pull', $this->currentTag);
		$counts = $this->mappedFileCountsByWorkflowId($folder);
		foreach ($this->seededWorkflows as $name => $id) {
			Assert::assertSame(1, $counts[$id] ?? 0, "workflow '$name' ($id) is duplicated or missing — expected exactly one file");
		}
	}

	/** @Then a mapped file whose workflow no longer carries the tag is pruned from the folder */
	public function aMappedFileWhoseWorkflowLostTheTagIsPruned(): void {
		$folder = $this->folderNameForTag($this->currentTag);
		// Strip the mapping tag from one seeded workflow, then re-pull: its file goes.
		$victimId = (string)reset($this->seededWorkflows);
		$this->setN8nWorkflowTags($victimId, []);
		$this->runMappingSync('pull', $this->currentTag);
		$byId = $this->mappedFilesByWorkflowId($folder);
		Assert::assertArrayNotHasKey($victimId, $byId, "workflow $victimId lost its tag but its file was not pruned");
	}

	/** @Then /^the unmapped file is left untouched \(it is outside the mapping's scope\)$/ */
	public function theUnmappedFileIsLeftUntouched(): void {
		Assert::assertNotSame('', $this->reconcileUnmappedPath, 'no unmapped file was planted');
		Assert::assertTrue($this->davExists($this->reconcileUnmappedPath), 'the unmapped file was removed by a mapping-scoped sync');
		Assert::assertSame($this->reconcileUnmappedBody, $this->davGet($this->reconcileUnmappedPath), 'the unmapped file was rewritten by a mapping-scoped sync');
	}

	// ── Then (push) ───────────────────────────────────────────────────────────

	/** @Then each sync file in the folder is pushed to its workflow in n8n */
	public function eachSyncFileIsPushedToItsWorkflow(): void {
		Assert::assertNotEmpty($this->reconcileSyncFiles, 'no sync files were set up to push');
		foreach ($this->reconcileSyncFiles as $path => $id) {
			$wf = $this->n8nGetWorkflow($id);
			Assert::assertIsArray($wf, "workflow $id (from $path) is gone from n8n");
			Assert::assertStringContainsString('Locally Changed', (string)($wf['name'] ?? ''), "the local change to $path was not pushed up to workflow $id");
		}
	}

	/** @Then /^the unmapped file is not pushed \(it is outside the mapping's scope\)$/ */
	public function theUnmappedFileIsNotPushed(): void {
		// It carries no n8n_id, so there is nothing in n8n to mirror; it must also
		// be left exactly as planted (a push never reaches outside the mapping).
		Assert::assertTrue($this->davExists($this->reconcileUnmappedPath), 'the unmapped file vanished during a push');
		Assert::assertSame($this->reconcileUnmappedBody, $this->davGet($this->reconcileUnmappedPath), 'the unmapped file was modified by a push');
	}

	// ── helpers: drive the occ sync surface ───────────────────────────────────

	/** Run `occ n8n_sync:sync <direction> --mapping=<tag>` and assert it succeeded. */
	private function runMappingSync(string $direction, string $tag): void {
		$res = $this->occ('n8n_sync:sync ' . escapeshellarg($direction) . ' --mapping=' . escapeshellarg($tag));
		// RuntimeException, not Assert: a failing PHPUnit assertion under Behat +
		// PHPUnit 12 throws the opaque Registry::get() TypeError that masks the real
		// message (see WebDavTrait::assertStatus). A plain throw shows exit + output.
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("sync $direction for $tag failed (exit {$res['exit']}):\n{$res['output']}");
		}
	}

	// ── helpers: n8n REST seeding/inspection ──────────────────────────────────

	/** Find an n8n tag id by name; create it if missing. */
	private function ensureN8nTag(string $name): string {
		$res = $this->n8nClient()->request('GET', 'tags?limit=250');
		$decoded = json_decode((string)$res->getBody(), true);
		foreach ((array)($decoded['data'] ?? []) as $tag) {
			if (is_array($tag) && ($tag['name'] ?? null) === $name) {
				return (string)$tag['id'];
			}
		}
		$create = $this->n8nClient()->request('POST', 'tags', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode(['name' => $name], JSON_THROW_ON_ERROR),
		]);
		Assert::assertContains($create->getStatusCode(), [200, 201], 'create n8n tag failed: ' . (string)$create->getBody());
		$body = json_decode((string)$create->getBody(), true);
		Assert::assertIsArray($body, 'create n8n tag returned no JSON');
		return (string)$body['id'];
	}

	/**
	 * Create a workflow in n8n and assign $tagIds to it; returns the new id and
	 * records it for teardown.
	 *
	 * @param list<string> $tagIds
	 */
	private function createN8nWorkflow(string $name, array $tagIds): string {
		$create = $this->n8nClient()->request('POST', 'workflows', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode([
				'name' => $name,
				'nodes' => [],
				'connections' => new \stdClass(),
				'settings' => new \stdClass(),
			], JSON_THROW_ON_ERROR),
		]);
		Assert::assertContains($create->getStatusCode(), [200, 201], 'create n8n workflow failed: ' . (string)$create->getBody());
		$body = json_decode((string)$create->getBody(), true);
		Assert::assertIsArray($body, 'create n8n workflow returned no JSON');
		$id = (string)$body['id'];
		$this->createdWorkflowIds[] = $id;
		if ($tagIds !== []) {
			$this->setN8nWorkflowTags($id, $tagIds);
		}
		return $id;
	}

	/**
	 * Replace a workflow's tags (PUT /workflows/{id}/tags). An empty list clears
	 * them — how we make a workflow "lose the tag" for the prune assertion.
	 *
	 * @param list<string> $tagIds
	 */
	private function setN8nWorkflowTags(string $id, array $tagIds): void {
		$payload = array_map(static fn (string $t): array => ['id' => $t], $tagIds);
		$res = $this->n8nClient()->request('PUT', 'workflows/' . rawurlencode($id) . '/tags', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode($payload, JSON_THROW_ON_ERROR),
		]);
		Assert::assertContains($res->getStatusCode(), [200, 201], "set tags on workflow $id failed: " . (string)$res->getBody());
	}

	/**
	 * PROPFIND the mapped folder and return a map of `n8n_id ⇒ href` for every
	 * managed file under it (the last one wins if — wrongly — duplicated).
	 *
	 * @return array<string,string>
	 */
	private function mappedFilesByWorkflowId(string $folder): array {
		$map = [];
		foreach ($this->propfindWorkflowIds($folder) as $href => $id) {
			$map[$id] = $href;
		}
		return $map;
	}

	/**
	 * Like {@see mappedFilesByWorkflowId} but counts files per id, so a duplicate
	 * (same id, two files) is visible.
	 *
	 * @return array<string,int>
	 */
	private function mappedFileCountsByWorkflowId(string $folder): array {
		$counts = [];
		foreach ($this->propfindWorkflowIds($folder) as $id) {
			$counts[$id] = ($counts[$id] ?? 0) + 1;
		}
		return $counts;
	}

	/**
	 * Depth-1 PROPFIND of $folder for `nc:metadata-n8n_id`; yields href ⇒ id for
	 * each child file that carries one (folders + unstamped files are skipped).
	 *
	 * @return iterable<string,string>
	 */
	private function propfindWorkflowIds(string $folder): iterable {
		$ns = 'http://nextcloud.org/ns';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($folder), [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="' . $ns . '">'
				. '<d:prop><nc:metadata-' . self::META_ID . '/></d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], "PROPFIND $folder");
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $ns);
		foreach ($doc->xpath('//d:response') ?: [] as $resp) {
			$resp->registerXPathNamespace('d', 'DAV:');
			$resp->registerXPathNamespace('nc', $ns);
			$href = trim((string)($resp->xpath('d:href')[0] ?? ''));
			$id = '';
			foreach ($resp->xpath('.//d:propstat') ?: [] as $propstat) {
				$propstat->registerXPathNamespace('d', 'DAV:');
				$propstat->registerXPathNamespace('nc', $ns);
				if (!str_contains((string)($propstat->xpath('d:status')[0] ?? ''), '200')) {
					continue;
				}
				$node = $propstat->xpath('d:prop/nc:metadata-' . self::META_ID);
				if ($node) {
					$id = trim((string)$node[0]);
				}
			}
			if ($id !== '' && $href !== '') {
				yield $href => $id;
			}
		}
	}
}
