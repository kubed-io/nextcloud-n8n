<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Support;

use PHPUnit\Framework\Assert;

/**
 * Shared scenario setup: stand up an admin mapping + its backing folder, PUT a
 * managed workflow file, and drain background jobs deterministically. These are
 * the "arrange" primitives the create/rename/delete/move/copy step traits lean
 * on. Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 *
 * Cross-trait calls: `setupSyncMappingAndFolder` uses `occ`/`occStdin`
 * (OccTrait), `davMkdir` (WebDavTrait) and `modeToModel` (MappingSteps) — all
 * legal because every trait is composed into the one FeatureContext object.
 */
trait SetupTrait {
	/**
	 * The single node {@see starterWorkflow} writes.
	 *
	 * NAMED, because a scenario now has to say "the body that was already there"
	 * and compare against it. Spelled out in a `Then` it would be a literal that
	 * agrees with this fixture by luck and stops agreeing the day the fixture
	 * changes — silently, since a node that is not there fails the same way as a
	 * node that was renamed.
	 */
	private const STARTER_NODE_NAME = 'When clicking Test workflow';

	/**
	 * Create an admin-owned mapping for $tag in mode $mode + its backing folder,
	 * wiring the connection so create/push/delete can reach n8n. Records the tag
	 * for tag-strip assertions and sets currentFolder.
	 */
	private function setupSyncMappingAndFolder(string $mode, string $tag): void {
		// Connection (idempotent): URL + key.
		$this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($this->n8nUrl));
		if ($this->n8nApiKey !== '') {
			$this->occStdin($this->occ . ' n8n_sync:set-api-key', $this->n8nApiKey);
		}
		$folder = $this->folderNameForTag($tag);
		$data = ['n8n_tag' => $tag, 'team_folder' => $folder, 'nc_groups' => ['admin'], 'mode' => $this->modeToModel($mode), 'use_team_folder' => false];
		$res = $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
		Assert::assertSame(0, $res['exit'], "adding mapping for $tag failed:\n{$res['output']}");
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$this->currentTag = $tag;
	}

	/**
	 * Put a managed workflow file in $folder, whichever side the mapping allows.
	 *
	 * ## SEEDED IN n8n FOR A LINK MAPPING, BECAUSE A LINK MAPPING REFUSES AUTHORING
	 *
	 * Every arrange in this suite used to write its file locally with a DAV PUT, which
	 * worked in a sync mapping and worked in a link one only because **nothing stopped
	 * it**. Once `CreateGuardListener` and the `method:PUT` hook landed, those arranges
	 * started failing across four scenarios in three suites — correctly. The app now
	 * refuses a gesture the harness was relying on.
	 *
	 * The fix is not to exempt the harness. A link folder is filled from its tag in n8n
	 * and from nowhere else, so **that is how a link file has to be arranged**: create
	 * the workflow in n8n, give it the mapping's tag, and pull. The file that appears is
	 * the one a user would really have.
	 *
	 * This also makes the arranges honest in a way they were not before. A scenario like
	 * `Deleting a link is refused` was setting up its link by doing the very thing the
	 * app forbids, so the state it tested against was one no user could reach.
	 *
	 * The sync path is unchanged: authoring into a sync mapping IS the gesture, so a
	 * local PUT is the right arrange there.
	 *
	 * @return string the file's path, re-resolved because create-on-land and the pull
	 *                both name the file after the workflow
	 */
	private function seedManagedFileIn(string $folder, string $name): string {
		$this->davMkdir($folder);

		if ($this->modeForFolder($folder) !== 'link') {
			$path = $folder . '/' . $name . '.n8n';
			$this->putManagedFile($path, $name);
			return $this->currentFilePath;
		}

		// THE FAR SIDE. The tag is the whole membership gesture in n8n, and the pull is
		// the delivery mechanism — n8n has no way to tell Nextcloud a tag was added.
		$tag = $this->tagForFolder($folder);
		$id = $this->createN8nWorkflow($name, [$this->ensureN8nTag($tag)]);
		$pull = $this->occ('n8n_sync:sync pull');
		Assert::assertSame(0, $pull['exit'], "the pull seeding a link file failed:\n{$pull['output']}");

		// RESOLVED BY THE ID, NOT BY THE NAME AND NOT BY WHAT APPEARED.
		//
		// Guessing `$folder/$name.n8n` is wrong because the pull names the file after the
		// WORKFLOW and a second workflow sharing a name gets a numbered suffix. Diffing
		// the folder listing is better and still wrong: one pull can bring down more than
		// one file — a tag that already had workflows, or a mirror the previous pull
		// never finished — and `array_diff` has no meaningful order, so the arrange would
		// point `currentFilePath` at one file and `lastWorkflowId` at another. Green, and
		// testing two different things.
		//
		// The id is the only deterministic handle, and asking for it by id also asserts
		// the thing worth asserting: that the mirror was really stamped. Raised by
		// Copilot on #88.
		// EVERY MATCH, THEN EXACTLY ONE — which is why this counts from
		// `propfindWorkflowIds()` rather than using `mappedFilesByWorkflowId()`. That
		// helper builds a map KEYED BY ID, so two files sharing one id silently collapse
		// and the last one wins: the arrange would pick a file and go green over the
		// precise failure worth catching, a gesture that re-creates rather than updates
		// and leaves the old mirror behind.
		//
		// Raised by Copilot against the Grafana sibling's twin of this arrange (#79), in
		// the shape it takes there — a `break` on the first match. Carried across rather
		// than left, because the map form hides it better.
		$matches = [];
		foreach ($this->propfindWorkflowIds($folder) as $href => $found) {
			if ($found === $id) {
				$matches[] = $this->hrefToFilesPath($href);
			}
		}
		Assert::assertCount(
			1,
			$matches,
			$matches === []
				? "the pull did not bring workflow $id into '$folder' — a link file cannot be arranged any other way"
				: 'the pull wrote ' . count($matches) . " mirrors all claiming workflow $id: " . implode(', ', $matches),
		);
		$this->currentFolder = $folder;
		$this->currentFilePath = $matches[0];
		$this->lastWorkflowId = $id;
		return $this->currentFilePath;
	}

	/** PUT a starter workflow body and capture the n8n id the app stamped. */
	/** @param list<string> $tagNames */
	private function putManagedFile(string $path, string $name, array $tagNames = []): void {
		$this->davPut($path, json_encode(self::starterWorkflow($name, $tagNames), JSON_THROW_ON_ERROR));
		$this->currentFilePath = $path;
		$id = $this->davReadMetadataId($path);
		Assert::assertNotNull($id, "the file at $path was not stamped with an n8n_id — create-on-land did not run");
		$this->lastWorkflowId = $id;
		$this->createdWorkflowIds[] = $id;
	}

	/**
	 * One no-op node, named — what every "replace the nodes" gesture writes.
	 * ONE definition for the same reason as {@see starterWorkflow}: a fixture
	 * node cannot be realistic in one feature and a stub in another.
	 */
	private static function noOpNode(string $name): object {
		return (object)[
			'name' => $name,
			'type' => 'n8n-nodes-base.noOp',
			'typeVersion' => 1,
			'position' => [0, 0],
			'parameters' => new \stdClass(),
		];
	}

	/**
	 * The body every arrange writes for a workflow file — ONE definition, so a
	 * fixture cannot be realistic in one feature and a stub in another.
	 *
	 * ## IT HAS A NODE NOW, AND THAT IS THE WHOLE POINT
	 *
	 * This used to be `'nodes' => []`. A workflow with no nodes has no
	 * `nodes/0/parameters`, and `nodes/0/parameters` is the field n8n rejected when a
	 * real workflow was copied into a mapped folder — so the entire copy feature was
	 * green while its base case could not work on anything a user would actually make.
	 * A fixture stripped down far enough stops standing in for the thing it represents,
	 * and nothing in a green suite says which of those two it is.
	 *
	 * `parameters` is an empty OBJECT because that is what n8n stores for a node whose
	 * parameters have not been filled in — the ordinary state of a first draft, and the
	 * exact shape that used to be flattened to `[]` on the way through Nextcloud.
	 *
	 * @param list<string> $tagNames
	 * @return array<string,mixed>
	 */
	private static function starterWorkflow(string $name, array $tagNames = []): array {
		$body = [
			'name' => $name,
			'nodes' => [[
				'id' => 'a1b2c3d4-0000-4000-8000-000000000001',
				'name' => self::STARTER_NODE_NAME,
				'type' => 'n8n-nodes-base.manualTrigger',
				'typeVersion' => 1,
				'position' => [0, 0],
				'parameters' => new \stdClass(),
			]],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		];
		if ($tagNames !== []) {
			$body['tags'] = array_map(static fn (string $n): object => (object)['name' => $n], $tagNames);
		}
		return $body;
	}

	/**
	 * The job classes the suite drains, as constants: a typo'd FQCN string makes
	 * `background-job:list --class=…` return `[]` and drainJobs a silent no-op,
	 * which reads as "the job ran" — exactly the failure mode this suite avoids.
	 */
	private const JOB_PUSH_WORKFLOW = 'OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob';
	private const JOB_RECONCILE_NAME = 'OCA\\N8nSync\\BackgroundJob\\ReconcileNameJob';
	private const JOB_RECONCILE_TAGS = 'OCA\\N8nSync\\BackgroundJob\\ReconcileTagsJob';

	/**
	 * Execute every queued job of $jobClass now, deterministically.
	 *
	 * `background-job:worker --once` honours the worker's last-run / reservation
	 * timing, so a job queued microseconds ago is often skipped on an immediate
	 * pass — which made rename reconciles flaky. Instead we list the jobs of the
	 * class (JSON) and run each by id with `--force-execute`, which bypasses the
	 * last-run + reservation gates. Idempotent: the reconcile job no-ops if the
	 * names are already in sync, so running a stale id is harmless.
	 */
	private function drainJobs(string $jobClass): void {
		// NOTHING DRAINS THE QUEUE ON AN `ajax` INSTANCE, and pretending otherwise
		// would grade a worker that instance does not have. A scenario that puts the
		// instance in that mode is asserting the app copes WITHOUT a worker, so the
		// harness must not quietly supply one — this is the difference between the
		// inline fallback working and the test faking it.
		if ($this->backgroundJobsMode() === 'ajax') {
			return;
		}
		$res = $this->occ('background-job:list --class=' . escapeshellarg($jobClass) . ' --output=json');
		$jobs = json_decode($res['output'], true);
		if (!is_array($jobs)) {
			return;
		}
		foreach ($jobs as $job) {
			$id = $job['id'] ?? null;
			if (is_int($id) || (is_string($id) && $id !== '')) {
				$this->occ('background-job:execute ' . escapeshellarg((string)$id) . ' --force-execute');
			}
		}
	}

	/**
	 * How this instance runs background jobs, read once per scenario.
	 *
	 * Cached because {@see drainJobs} consults it on every call and an `occ` round trip
	 * is not free; reset in the teardown alongside the mode itself, so a scenario that
	 * changes it does not leak the answer into the next one.
	 */
	private function backgroundJobsMode(): string {
		if ($this->backgroundJobsMode === null) {
			$res = $this->occ('config:app:get core backgroundjobs_mode');
			$this->backgroundJobsMode = trim((string)$res['output']) ?: 'ajax';
		}
		return $this->backgroundJobsMode;
	}

	/** A stable, filesystem-safe folder name derived from an n8n tag. */
	private function folderNameForTag(string $tag): string {
		$slug = preg_replace('/[^a-z0-9]+/i', '-', $tag) ?? 'mapped';
		return trim(strtolower($slug), '-') ?: 'mapped';
	}
}
