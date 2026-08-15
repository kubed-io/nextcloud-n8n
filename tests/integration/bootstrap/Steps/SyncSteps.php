<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
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
	/** The node name written by the last file edit, for the writeback assertions. */
	private string $editedNodeName = '';

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

	/**
	 * Seed the workflows a sync will find, optionally carrying an ORDINARY tag
	 * alongside the mapping tag.
	 *
	 * The extra tag is what makes "a mirror wears its workflow's tags" assertable
	 * here at all: with only the mapping tag on them, a mirror that imported no tags
	 * whatsoever would still pass. It is one phrasing more, not a second step —
	 * Behat fills the omitted argument from the default, so the plain sentence keeps
	 * working for the scenarios that do not care.
	 *
	 * @Given n8n has workflows tagged :tag
	 * @Given n8n has workflows tagged :tag, each also carrying :extra
	 */
	public function n8nHasWorkflowsTagged(string $tag, string $extra = ''): void {
		$tagIds = [$this->ensureN8nTag($tag)];
		if ($extra !== '') {
			$tagIds[] = $this->ensureN8nTag($extra);
		}
		foreach (['Reconcile-Alpha', 'Reconcile-Beta'] as $name) {
			$unique = $name . '-' . bin2hex(random_bytes(3));
			$id = $this->createN8nWorkflow($unique, $tagIds);
			$this->seededWorkflows[$unique] = $id;
		}
		Assert::assertCount(2, $this->seededWorkflows, 'failed to seed tagged workflows in n8n');
	}

	/**
	 * Every mirror wears its workflow's tags as Nextcloud system tags — the END STATE
	 * of a sync, and the thing that makes the mirror as searchable as n8n.
	 *
	 * It lives here rather than in tags.feature because nobody CHANGED a tag: this is
	 * what a first sync leaves behind. The reserved `n8n:*` namespace is the app's
	 * control plane and is excluded, so a mirror never wears one as a content tag.
	 *
	 * @Then each file carries its workflow's tags as Nextcloud tags
	 */
	public function eachFileCarriesItsWorkflowTags(): void {
		Assert::assertNotEmpty($this->seededWorkflows, 'no seeded workflows to check');
		$byId = $this->mappedFilesByWorkflowId($this->folderNameForTag($this->currentTag));
		foreach ($this->seededWorkflows as $name => $id) {
			Assert::assertArrayHasKey($id, $byId, "workflow '$name' ($id) has no mirror to check");
			$want = array_values(array_filter(
				$this->n8nWorkflowTagNames($id),
				static fn (string $n): bool => !str_starts_with($n, 'n8n:'),
			));
			sort($want);
			// hrefToFilesPath: mappedFilesByWorkflowId hands back DAV hrefs, and the
			// system-tag lookup wants a files-root-relative path — handed the href it
			// reports "could not resolve fileid", which reads like a missing file.
			$got = array_values(array_filter(
				$this->fileSystemTags($this->hrefToFilesPath($byId[$id])),
				static fn (string $n): bool => !str_starts_with($n, 'n8n:'),
			));
			sort($got);
			Assert::assertSame($want, $got, "the mirror of '$name' does not wear its workflow's tags");
		}
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
		$this->reconcileUnmappedPath = $folder . '/Bystander.n8n';
		$this->davPut($this->reconcileUnmappedPath, $this->reconcileUnmappedBody);
	}

	// ── When ──────────────────────────────────────────────────────────────────

	/**
	 * Edit the file's nodes and save it — the gesture a person actually performs.
	 *
	 * The push is folded in, as everywhere else: nobody edits a workflow in order to
	 * run a push, and whether the writeback happens inline or on the worker's next
	 * tick is our plumbing. The job is drained so the step behaves the same under
	 * either `timing`.
	 *
	 * @When I edit the file's nodes and save
	 */
	public function iEditTheFilesNodesAndSave(): void {
		$path = $this->currentFilePath;
		// Object decode: an assoc round-trip flattens the empty `connections` and
		// `settings` objects to `[]`, which n8n rejects on the next push.
		$wf = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		Assert::assertInstanceOf(\stdClass::class, $wf, "managed file at $path is not a JSON object");
		$this->editedNodeName = 'Edited-' . bin2hex(random_bytes(3));
		$wf->nodes = [(object)[
			'name' => $this->editedNodeName,
			'type' => 'n8n-nodes-base.noOp',
			'typeVersion' => 1,
			'position' => [0, 0],
			'parameters' => new \stdClass(),
		]];
		$this->davPut($path, json_encode($wf, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob');
	}

	/**
	 * Someone edits the workflow in n8n and the news reaches the mirror — the pull
	 * folded into the gesture, as everywhere else.
	 *
	 * @When someone edits the workflow's nodes in n8n
	 */
	public function someoneEditsTheWorkflowInN8n(): void {
		$id = (string)$this->davReadMetadataId($this->currentFilePath);
		Assert::assertNotSame('', $id, 'no workflow behind the file under test');
		$wf = $this->n8nGetWorkflow($id);
		Assert::assertIsArray($wf, "workflow $id is gone from n8n");

		$this->editedNodeName = 'EditedInN8n-' . bin2hex(random_bytes(3));
		$this->n8nUpdateWorkflow($id, [
			'name' => (string)($wf['name'] ?? 'Edited'),
			'nodes' => [(object)[
				'name' => $this->editedNodeName,
				'type' => 'n8n-nodes-base.noOp',
				'typeVersion' => 1,
				'position' => [0, 0],
				'parameters' => new \stdClass(),
			]],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		]);
		$this->runMappingSync('pull', $this->currentTag);
	}

	/**
	 * THE MIRROR WEARS ITS WORKFLOW'S CLOCK, not the sync's. A mirrored folder whose
	 * files all say "modified a few seconds ago" after every scheduled run is a
	 * folder where a real edit is invisible, which is the bug this pins.
	 *
	 * @Then the file's "Modified" is when the workflow last changed in n8n
	 */
	public function theFilesModifiedIsTheWorkflowsUpdatedAt(): void {
		$id = (string)$this->davReadMetadataId($this->currentFilePath);
		$wf = $this->n8nGetWorkflow($id);
		Assert::assertIsArray($wf, "workflow $id is gone from n8n");
		$updatedAt = strtotime((string)($wf['updatedAt'] ?? ''));
		Assert::assertIsInt($updatedAt, 'n8n did not report an updatedAt to compare against');
		Assert::assertSame(
			$updatedAt,
			$this->davReadTime($this->currentFilePath, 'getlastmodified'),
			"the mirror's Modified is not the workflow's updatedAt",
		);
	}

	/** @Then the file holds the workflow's nodes as n8n has them */
	public function theFileHoldsTheWorkflowsNodes(): void {
		$wf = json_decode($this->davGet($this->currentFilePath), true);
		Assert::assertIsArray($wf, 'the mirror is not JSON');
		$names = [];
		foreach ((array)($wf['nodes'] ?? []) as $node) {
			if (is_array($node) && is_string($node['name'] ?? null)) {
				$names[] = $node['name'];
			}
		}
		Assert::assertSame([$this->editedNodeName], $names, 'the mirror does not carry the nodes n8n has');
	}

	/**
	 * A LINK'S BODY, SAID OUT LOUD. "does not hold the workflow" was a negative that
	 * never named what IS there — and what is there is a specific, documented shape:
	 * an `n8n.reference/v1` pointer carrying the id, the name and a deep link.
	 *
	 * @Then the file holds a pointer:
	 */
	public function theFileHoldsAPointer(TableNode $table): void {
		$body = json_decode($this->davGet($this->currentFilePath), true);
		Assert::assertIsArray($body, 'the link is not JSON');
		Assert::assertArrayNotHasKey('nodes', $body, 'a link carries the workflow body; it should hold only a pointer');

		$id = (string)$this->davReadMetadataId($this->currentFilePath);
		$wf = $this->n8nGetWorkflow($id);
		Assert::assertIsArray($wf, "workflow $id is gone from n8n");

		foreach ($table->getRowsHash() as $key => $expected) {
			$actual = (string)($body[trim($key)] ?? '');
			$want = match (trim($expected)) {
				"the workflow's id" => $id,
				"the workflow's name" => (string)($wf['name'] ?? ''),
				'a deep link to it in n8n' => null,
				default => trim($expected),
			};
			if ($want === null) {
				Assert::assertStringContainsString($id, $actual, "$key is not a deep link to the workflow");
				continue;
			}
			Assert::assertSame($want, $actual, "the pointer's $key is wrong");
		}
	}

	/**
	 * THE EDIT ARRIVED: n8n's workflow carries the node the file now carries.
	 *
	 * Compares NODE NAMES rather than whole bodies, because n8n rewrites parts of
	 * what it is given (ids, versionId, its own timestamps) and a byte comparison
	 * would fail for reasons that have nothing to do with the edit.
	 *
	 * @Then the workflow in n8n holds the file's nodes
	 */
	public function theWorkflowHoldsWhatTheFileHolds(): void {
		Assert::assertContains(
			$this->editedNodeName,
			$this->n8nWorkflowNodeNames((string)$this->davReadMetadataId($this->currentFilePath)),
			'the edit never reached n8n',
		);
	}

	/** @Then the workflow in n8n still holds the nodes it had */
	public function theWorkflowDoesNotHoldWhatTheFileHolds(): void {
		Assert::assertNotContains(
			$this->editedNodeName,
			$this->n8nWorkflowNodeNames((string)$this->davReadMetadataId($this->currentFilePath)),
			'an edit outside every mapping reached n8n anyway',
		);
	}

	/** The node names on a workflow in n8n. @return list<string> */
	private function n8nWorkflowNodeNames(string $id): array {
		$wf = $this->n8nGetWorkflow($id);
		Assert::assertIsArray($wf, "workflow $id is gone from n8n");
		$names = [];
		foreach ((array)($wf['nodes'] ?? []) as $node) {
			if (is_array($node) && is_string($node['name'] ?? null)) {
				$names[] = $node['name'];
			}
		}
		return $names;
	}

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

	/**
	 * @When the :tag tag is removed from the workflow in n8n
	 *
	 * TAKES THE WORKFLOW FROM WHICHEVER ARRANGE RAN. It only read
	 * `$seededWorkflows`, which is filled by `n8n has workflows tagged` — the Given
	 * this step was written beside, in the file that became sync-now.feature. Its
	 * scenario now lives in tag-sync.feature and opens with a managed-file arrange
	 * instead, which records `lastWorkflowId` and seeds nothing; the step then
	 * untagged `''` and failed with "no seeded workflow to untag".
	 *
	 * Moving a scenario moves its assumptions with it, and this one's were in the
	 * step rather than the feature.
	 */
	public function theTagIsRemovedFromTheWorkflowInN8n(string $tag): void {
		$id = (string)($this->lastWorkflowId ?? '');
		if ($id === '') {
			$id = (string)reset($this->seededWorkflows);
		}
		Assert::assertNotSame('', $id, 'no workflow to untag — the arrange recorded neither a managed file nor a seeded workflow');
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

	// ── the metadata is the app's record: a client may read it, never write it ──

	/** @var array<string,array{status:int,body:string,before:?string}> one entry per property tried */
	private array $proppatchAttempts = [];

	/**
	 * EVERY PROPERTY THE APP STAMPS, not a representative one. They are immutable as
	 * a set — a single writable key would let a client rewrite the app's record of
	 * what it last agreed with n8n, and the next sync would believe it.
	 *
	 * @When a client tries to change every property the app stamps via PROPPATCH
	 */
	public function aClientTriesToChangeEveryManagedProperty(): void {
		if ($this->currentFilePath === '') {
			throw new \RuntimeException('no file to tamper with — a Given must arrange one');
		}
		$this->proppatchAttempts = [];
		foreach (self::MANAGED_KEYS as $key) {
			$before = $this->davReadMetadata($this->currentFilePath, $key);
			$res = $this->davProppatch($this->currentFilePath, $key, 'tampered-by-client');
			$this->proppatchAttempts[$key] = [
				'status' => $res->getStatusCode(),
				'body' => (string)$res->getBody(),
				'before' => $before,
			];
		}
	}

	/**
	 * Nextcloud reports a forbidden prop as a 403 INSIDE the 207 multistatus, so the
	 * outer status alone proves nothing — the body has to be read for the refusal.
	 * The values themselves are the caller's next assertion, stated in the feature as
	 * the metadata table, so this step only claims the refusal.
	 *
	 * @Then every change is refused
	 */
	public function everyChangeIsRefused(): void {
		if ($this->proppatchAttempts === []) {
			throw new \RuntimeException('nothing was tried — the When did not run');
		}
		$accepted = [];
		foreach ($this->proppatchAttempts as $key => $attempt) {
			$refused = $attempt['status'] >= 400
				|| str_contains($attempt['body'], '403')
				|| stripos($attempt['body'], 'forbidden') !== false;
			if (!$refused) {
				$accepted[] = "$key (HTTP {$attempt['status']})";
			}
		}
		if ($accepted !== []) {
			throw new \RuntimeException(
				'PROPPATCH was not refused for: ' . implode(', ', $accepted)
				. ' — these properties must be read-only to every client',
			);
		}
	}

	/** Attempt to set one nc:metadata-* prop via PROPPATCH; returns the raw response. */
	private function davProppatch(string $path, string $key, string $value): \Psr\Http\Message\ResponseInterface {
		$prop = 'metadata-' . $key;
		$body = '<?xml version="1.0"?>'
			. '<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:set><d:prop><nc:' . $prop . '>' . htmlspecialchars($value, ENT_XML1) . '</nc:' . $prop . '></d:prop></d:set>'
			. '</d:propertyupdate>';
		return $this->davClient()->request('PROPPATCH', $this->davEncode($path), [
			'headers' => ['Content-Type' => 'application/xml'],
			'body' => $body,
		]);
	}
}
