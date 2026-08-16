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
	 * Create an admin-owned mapping for $tag in mode $mode + its backing folder,
	 * wiring the connection so create/push/delete can reach n8n. Records the tag
	 * for tag-strip assertions and sets currentFolder.
	 */
	private function setupSyncMappingAndFolder(string $mode, string $tag): void {
		// Connection (idempotent): URL + key + REST API on.
		$this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($this->n8nUrl));
		$this->occ('config:app:set ' . self::APP_ID . ' api_enabled --value=1');
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
				'name' => 'When clicking Test workflow',
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

	/** A stable, filesystem-safe folder name derived from an n8n tag. */
	private function folderNameForTag(string $tag): string {
		$slug = preg_replace('/[^a-z0-9]+/i', '-', $tag) ?? 'mapped';
		return trim(strtolower($slug), '-') ?: 'mapped';
	}
}
