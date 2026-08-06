<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Sync steps — the first sync (sync-now.feature), the push behind an edit
 * (edit-workflow.feature), and the mechanism by which an n8n-side change reaches
 * Nextcloud for the behaviours that own it. There is no reconcile.feature: the
 * reconciler is a MECHANISM, and a mechanism does not get a feature file. The admin's
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
trait SyncSteps {
	/** The workflow whose mapping tag was stripped, for the prune assertion. */
	private string $untaggedWorkflowId = '';

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

	/** The decoded JSON the last `occ n8n_sync:sync` run printed — what the run reports. */
	private array $lastSyncResult = [];

	/** Mirror etags (files-root path ⇒ etag) as of the last "has already been pulled". */
	private array $reconcileEtagsBefore = [];

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

	/**
	 * THE TRIGGER IS DATA, NOT A BEHAVIOUR. Three ways to start one sync — the
	 * card's button, the section's button, and the clock — so the outline treats
	 * them as columns and this turns a column into an action.
	 *
	 * A REGEX WITH THE VOCABULARY SPELLED OUT, not `:actor syncs :scope`. Behat's
	 * `:name` placeholder matches a quoted string or a single non-space token, so
	 * `the admin` never matches it and every row comes back undefined. The
	 * alternation also makes a typo in an Examples cell a hard failure rather than
	 * a silently different actor.
	 *
	 * @When /^(the admin|the schedule) syncs (one mapping|every mapping)$/
	 */
	public function actorSyncsScope(string $actor, string $scope): void {
		if ($actor === 'the schedule') {
			$this->theScheduleFires();

			return;
		}

		$this->runMappingSync('pull', $scope === 'one mapping' ? $this->currentTag : null);
	}

	/** @When the admin pushes to n8n */
	public function theAdminPushesToN8n(): void {
		$this->runMappingSync('push', $this->currentTag);
	}

	/**
	 * @When the :tag mapping is synced
	 * @Given the :tag mapping has been synced
	 *
	 * The same run, in the two tenses a spec needs it. A feature ABOUT syncing puts
	 * it in the When; a feature about looking at what a sync left behind needs the
	 * mirror to exist as pre-state, and past tense is what keeps the reader's eye on
	 * that feature's own behaviour instead of on this one.
	 */
	public function theMappingIsSynced(string $tag): void {
		$this->currentTag = $tag;
		$this->runMappingSync('pull', $tag);
	}

	/**
	 * Make the scheduled pull actually run, rather than asserting that it would.
	 *
	 * TWO SAFETY FLOORS STAND BETWEEN A TEST AND A TIMED JOB, and neither can be
	 * waited out in CI: the job's own interval and the worker's last-run gate. So
	 * this enables the schedule, finds the registered job by class, and executes it
	 * by id with `--force-execute`, which bypasses both.
	 *
	 * That is the real job, reading the real setting, calling the real sync.
	 * Asserting that a row exists in oc_jobs would prove the job is registered and
	 * nothing about whether it works.
	 */
	private function theScheduleFires(): void {
		$res = $this->occ('config:app:set n8n_sync schedule_enabled --value=1 --type=boolean');
		Assert::assertSame(0, $res['exit'], "could not enable the schedule:\n{$res['output']}");

		$res = $this->occ('background-job:list --class=' . escapeshellarg('OCA\\N8nSync\\BackgroundJob\\ScheduledPullJob') . ' --output=json');
		$jobs = json_decode($res['output'], true);
		Assert::assertIsArray($jobs, "background-job:list did not return JSON:\n{$res['output']}");
		Assert::assertNotSame([], $jobs, 'the scheduled pull job is not registered');

		$id = (string)($jobs[0]['id'] ?? '');
		Assert::assertNotSame('', $id, 'the scheduled pull job has no id');

		$res = $this->occ('background-job:execute ' . escapeshellarg($id) . ' --force-execute');
		Assert::assertSame(0, $res['exit'], "running the scheduled pull failed:\n{$res['output']}");
	}

	/** @When the :tag tag is removed from the workflow in n8n */
	public function theTagIsRemovedFromTheWorkflowInN8n(string $tag): void {
		$id = (string)reset($this->seededWorkflows);
		Assert::assertNotSame('', $id, 'no seeded workflow to untag');
		$this->untaggedWorkflowId = $id;
		$this->setN8nWorkflowTags($id, []);
	}

	// ── Then (pull) ───────────────────────────────────────────────────────────

	/** @Then each :tag workflow appears as a file in the mapped folder */
	public function eachWorkflowAppearsAsAFile(string $tag): void {
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($tag));
		foreach ($this->seededWorkflows as $name => $id) {
			Assert::assertArrayHasKey($id, $byId, "workflow '$name' ($id) did not pull into the mapped folder");
		}
	}

	/**
	 * Each mirror's creation time is its workflow's `createdAt` in n8n, not the moment
	 * the pull that created the file ran. The one clock a later sync could never
	 * reconstruct — once the file exists there is no "before" left to read it from.
	 *
	 * ONE REUSABLE SENTENCE for both clocks, because they are one end state: a
	 * mirror wears the workflow's times rather than the sync's. Any later
	 * behaviour that produces a mirror can assert it in a line.
	 *
	 * @Then each file carries its n8n dates
	 * @Then each file's creation time is when its workflow was created in n8n
	 */
	public function eachFileCreationTimeIsTheWorkflowCreatedAt(): void {
		Assert::assertNotEmpty($this->seededWorkflows, 'no seeded workflows to check');
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($this->currentTag));
		foreach ($this->seededWorkflows as $name => $id) {
			$href = $byId[$id] ?? null;
			Assert::assertNotNull($href, "workflow '$name' ($id) has no mirror to check");

			$wf = $this->n8nGetWorkflow($id);
			Assert::assertIsArray($wf, "workflow $id is gone from n8n");
			$createdAt = strtotime((string)($wf['createdAt'] ?? ''));
			Assert::assertIsInt($createdAt, "n8n reported no createdAt for $id");

			$actual = $this->davReadTime($this->hrefToFilesPath((string)$href), 'creation_time');
			Assert::assertSame($createdAt, $actual, "the mirror for '$name' does not carry the workflow's creation time");
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

	/**
	 * @Then the file is pruned from the folder
	 *
	 * ASSERTS ONLY. Its predecessor stripped the tag AND re-ran the sync inside the
	 * Then, so the scenario's only visible step was "the button was pressed" and
	 * the actual behaviour — a workflow losing its mapping tag — happened invisibly
	 * inside an assertion. The untagging is a `When` now, and this looks.
	 */
	public function theFileIsPrunedFromTheFolder(): void {
		$id = $this->untaggedWorkflowId;
		Assert::assertNotSame('', $id, 'no workflow was untagged');
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($this->currentTag));
		Assert::assertArrayNotHasKey($id, $byId, "workflow $id lost its tag but its file was not pruned");
	}

	/** @Then /^the unmapped file is left untouched \(it is outside the mapping's scope\)$/ */
	public function theUnmappedFileIsLeftUntouched(): void {
		Assert::assertNotSame('', $this->reconcileUnmappedPath, 'no unmapped file was planted');
		Assert::assertTrue($this->davExists($this->reconcileUnmappedPath), 'the unmapped file was removed by a mapping-scoped sync');
		Assert::assertSame($this->reconcileUnmappedBody, $this->davGet($this->reconcileUnmappedPath), 'the unmapped file was rewritten by a mapping-scoped sync');
	}

	// ── Given/Then: the run writes (and reports) only what changed ────────────

	/**
	 * Bring the folder fully in step with n8n, then pin every mirror's etag. The pull
	 * under test therefore starts from a folder where there is genuinely nothing to do.
	 *
	 * @Given the :tag mapping has already been pulled
	 */
	public function theMappingHasAlreadyBeenPulled(string $tag): void {
		$this->currentTag = $tag;
		$this->currentFolder = $this->folderNameForTag($tag);
		$this->runMappingSync('pull', $tag);
		$this->reconcileEtagsBefore = $this->mirrorEtags($this->currentFolder);
		Assert::assertNotEmpty($this->reconcileEtagsBefore, 'the first pull mirrored no files, so a second one proves nothing');
	}

	/**
	 * Every file the run succeeded on was one it did NOT have to rewrite. `unchanged`
	 * is a subset of `succeeded`, so equality is the strongest available statement of
	 * "this run wrote nothing" — and it is a number, which is what an admin reads.
	 *
	 * @Then the run reports every file as unchanged
	 */
	public function theRunReportsEveryFileAsUnchanged(): void {
		Assert::assertArrayHasKey('unchanged', $this->lastSyncResult, 'the run reported no `unchanged` count: ' . json_encode($this->lastSyncResult));
		Assert::assertSame(
			(int)($this->lastSyncResult['succeeded'] ?? -1),
			(int)$this->lastSyncResult['unchanged'],
			'the run rewrote files even though nothing changed in n8n',
		);
	}

	/** @Then no file in the mapped folder was rewritten */
	public function noFileInTheMappedFolderWasRewritten(): void {
		Assert::assertNotEmpty($this->reconcileEtagsBefore, 'no mirror etags were pinned before the run');
		Assert::assertSame(
			$this->reconcileEtagsBefore,
			$this->mirrorEtags($this->folderNameForTag($this->currentTag)),
			'a pull rewrote mirrors whose bodies already matched n8n',
		);
	}

	/**
	 * Every managed mirror under $folder as `path ⇒ etag`, sorted by path so two
	 * snapshots compare as whole maps. Nextcloud mints a fresh etag on every write, so
	 * an identical map means nothing under the folder was written.
	 *
	 * @return array<string,string>
	 */
	private function mirrorEtags(string $folder): array {
		$etags = [];
		foreach ($this->propfindWorkflowIds($folder) as $href => $_id) {
			$path = $this->hrefToFilesPath((string)$href);
			$etags[$path] = $this->davReadEtag($path);
		}
		ksort($etags);
		return $etags;
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

	/**
	 * Run `occ n8n_sync:sync <direction>` and assert it succeeded.
	 *
	 * $tag null means EVERY MAPPING — the CLI's own `--all` (Reconcile.php:36),
	 * which is also what an omitted `--mapping` means. `actorSyncsScope` (the
	 * "every mapping" leg of sync-now.feature) has passed null here since that
	 * scenario was written; nothing caught the mismatch with this method's
	 * previously-required `string $tag` until CI actually ran it, because Actions
	 * was down for the whole cycle this was written in.
	 */
	private function runMappingSync(string $direction, ?string $tag): void {
		$cmd = 'n8n_sync:sync ' . escapeshellarg($direction);
		$cmd .= $tag !== null ? ' --mapping=' . escapeshellarg($tag) : ' --all';
		$res = $this->occ($cmd);
		// RuntimeException, not Assert: a failing PHPUnit assertion under Behat +
		// PHPUnit 12 throws the opaque Registry::get() TypeError that masks the real
		// message (see WebDavTrait::assertStatus). A plain throw shows exit + output.
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("sync $direction for $tag failed (exit {$res['exit']}):\n{$res['output']}");
		}
		$this->lastSyncResult = self::decodeSyncReport((string)$res['output']);
	}

	/**
	 * Pull the run's JSON report out of the command's stdout. `occ` may prefix its own
	 * lines (deprecations, warnings), so we decode from the first `{` rather than
	 * assuming the whole stream is JSON. An undecodable stream yields `[]` — the
	 * counters are then absent, and the Then that wanted them says so.
	 *
	 * @return array<string,mixed>
	 */
	private static function decodeSyncReport(string $output): array {
		$start = strpos($output, '{');
		if ($start === false) {
			return [];
		}
		$decoded = json_decode(substr($output, $start), true);
		return is_array($decoded) ? $decoded : [];
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
