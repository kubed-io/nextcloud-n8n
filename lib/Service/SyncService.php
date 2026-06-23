<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\BackgroundJob\ManualSyncJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Bulk pull/push reconciler (the Phase 3/4 "actual sync").
 *
 * Ownership model (plan §12.4, decided H-B = Team Folders): files are written
 * into a **Team Folder** (groupfolders mount), which is owned by no user and
 * shared with the mapping's `nc_groups`. {@see TeamFolderService} handles
 * create/assign-groups/permissions and hands back a writable folder node (we
 * write through a local "actor" member — see that class). There is no owner
 * setting; the owner/transfer problem is gone.
 *
 * Body shapes:
 *  - `reference` mode: `{$schema:"n8n.reference/v1", id, name, url, tags}` — a
 *    tiny human-readable pointer, not a runtime artifact.
 *  - `sync` mode: the full workflow JSON from `GET /workflows/{id}`. n8n's PUT
 *    round-trips the same shape, so this is also the Phase-4 writeback body.
 *
 * Filename collisions: n8n permits two workflows in one tag to share a `name`;
 * we disambiguate with NC-style "(2)", "(3)", … via {@see FilenameCodec}. We
 * update in place by `n8n_id` (rename-stable) and fall back to the canonical
 * filename for fresh writes.
 */
final class SyncService {
	/** Hard page cap so one click is bounded (n8n maxes at 250/page). */
	private const MAX_PAGES = 20;

	public function __construct(
		private MappingService $mappings,
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private OwnershipTags $tags,
		private StorageService $storage,
		private SyncGuard $guard,
		private PushService $push,
		private IMimeTypeLoader $mimeLoader,
		private IJobList $jobList,
		private SyncStatusService $status,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Single parameterized entry point for manual sync (§14).
	 *
	 * @param string $direction SyncStatusService::DIR_PULL|DIR_PUSH
	 * @param string|null $mappingId a specific mapping, or null = all mappings
	 *                               (push is all-only for now)
	 * @param bool $async true = enqueue a background job and return
	 *                    'queued' immediately; false = run inline
	 * @return array<string,mixed>
	 */
	public function dispatch(string $direction, ?string $mappingId, bool $async): array {
		if ($direction !== SyncStatusService::DIR_PULL && $direction !== SyncStatusService::DIR_PUSH) {
			throw new \InvalidArgumentException('direction must be "pull" or "push"');
		}
		if ($async) {
			$this->status->markQueued($direction);
			$this->jobList->add(ManualSyncJob::class, ['direction' => $direction, 'mappingId' => $mappingId]);
			return ['status' => 'queued', 'direction' => $direction, 'async' => true];
		}
		return $this->runInline($direction, $mappingId);
	}

	/**
	 * Synchronous execution of one dispatch — also called by {@see ManualSyncJob}
	 * for the async path. Normalises the return to always carry `status`.
	 *
	 * @return array<string,mixed>
	 */
	public function runInline(string $direction, ?string $mappingId): array {
		if ($direction === SyncStatusService::DIR_PUSH) {
			if ($mappingId !== null && $mappingId !== '') {
				$mapping = $this->mappings->getById($mappingId);
				if ($mapping === null) {
					throw new \OutOfBoundsException('Mapping not found');
				}
				$res = $this->pushOne($mapping);
				$res['status'] = ($res['failed'] ?? 0) === 0 ? 'ok' : 'error';
				$res['message'] = $res['message'] ?? null;
				return $res;
			}
			return $this->pushAll();
		}
		if ($mappingId !== null && $mappingId !== '') {
			$mapping = $this->mappings->getById($mappingId);
			if ($mapping === null) {
				throw new \OutOfBoundsException('Mapping not found');
			}
			$res = $this->pullOne($mapping);
			$res['status'] = ($res['failed'] ?? 0) === 0 ? 'ok' : 'error';
			$res['message'] = null;
			return $res;
		}
		return $this->pullAll();
	}

	/**
	 * One SQL UPDATE that rewrites every `*.n8n.json` filecache row to the
	 * application/n8n+json mimetype. NC's Detection layer only consults the
	 * last extension segment ('.json' → application/json), so newly-written
	 * files are stamped with the wrong mimetype. Calling this once at the
	 * end of a pull is O(rows) and idempotent (the WHERE clause skips rows
	 * already on the right id). Identical to what RegisterMimetype runs on
	 * install/upgrade.
	 */
	private function fixupFilecacheMimetype(): void {
		try {
			$id = $this->mimeLoader->getId('application/n8n+json');
			$this->mimeLoader->updateFilecache('n8n.json', $id);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: filecache mimetype fixup skipped', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Pull every mapping in order. Used by the bulk "Sync from n8n" button.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, status:string, message:?string}
	 */
	public function pullAll(): array {
		// Backend availability is now per-mapping (Team Folder vs admin-owned),
		// checked in pullOne.
		$total = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			try {
				$res = $this->pullOne($mapping);
				$total['processed'] += $res['processed'];
				$total['succeeded'] += $res['succeeded'];
				$total['failed'] += $res['failed'];
			} catch (\Throwable $e) {
				$errors[] = $mapping->teamFolder . ': ' . $e->getMessage();
				$total['failed']++;
				$this->logger->error('pullOne failed for ' . $mapping->teamFolder, [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
		return [
			'processed' => $total['processed'],
			'succeeded' => $total['succeeded'],
			'failed' => $total['failed'],
			'status' => $errors === [] ? 'ok' : 'error',
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * Pull a single mapping into its Team Folder.
	 *
	 * Reconciling, not merely additive: workflows still carrying the tag are
	 * written/updated in place (matched by `n8n_id`), and any managed file that
	 * belongs to this mapping but whose workflow no longer carries the tag is
	 * **pruned** (the manual "Sync from n8n" contract, saga §14.6). The prune
	 * deletes only the local mirror — the workflow in n8n merely lost this tag —
	 * so it runs inside the SyncGuard this method already holds.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int}
	 */
	public function pullOne(Mapping $mapping): array {
		if ($mapping->ncGroups === []) {
			// A Team Folder with no groups is invisible to everyone — skip with
			// a clear warning rather than create dead storage.
			$this->logger->warning('skipping mapping with no groups; Team Folder would be invisible', [
				'app' => Application::APP_ID,
				'teamFolder' => $mapping->teamFolder,
			]);
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
		}
		if (!$this->storage->isAvailable($mapping)) {
			$this->logger->warning('skipping mapping: storage backend unavailable (Team Folder selected but groupfolders disabled?)', [
				'app' => Application::APP_ID,
				'teamFolder' => $mapping->teamFolder,
			]);
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
		}

		// Guard our own writes: writeWorkflow's putContent fires NodeWrittenEvent,
		// and without this the writeback listener would push every pulled file
		// straight back to n8n (loop).
		$this->guard->enter();
		try {
			$targetFolder = $this->storage->ensureFolder($mapping);

			$processed = 0;
			$succeeded = 0;
			$failed = 0;

			$existingById = $this->indexByN8nId($targetFolder, $mapping);
			$nameCounts = [];
			$seenIds = [];

			foreach ($this->iterateWorkflows($mapping->n8nTag) as $workflow) {
				$processed++;
				$seenIds[(string)$workflow['id']] = true;
				try {
					$this->writeWorkflow($targetFolder, $mapping, $workflow, $existingById, $nameCounts);
					$succeeded++;
				} catch (\Throwable $e) {
					$failed++;
					$this->logger->warning('pull workflow failed', [
						'app' => Application::APP_ID,
						'workflowId' => $workflow['id'] ?? '?',
						'teamFolder' => $mapping->teamFolder,
						'exception' => $e,
					]);
				}
			}

			$pruned = $this->pruneStale($existingById, $seenIds, $mapping);

			$this->fixupFilecacheMimetype();
			return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed, 'pruned' => $pruned];
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * Delete managed files that belong to $mapping but whose workflow was not seen
	 * in this pull (it lost the mapping's tag). The workflow is left alone in n8n —
	 * only the local mirror is removed — and the caller already holds the SyncGuard
	 * so the delete does not mirror back. Returns the number of files pruned.
	 *
	 * @param array<string,\OCP\Files\Node> $existingById managed files for this mapping, keyed by n8n id
	 * @param array<string,bool> $seenIds ids that still carry the tag (written this pull)
	 */
	private function pruneStale(array $existingById, array $seenIds, Mapping $mapping): int {
		$pruned = 0;
		foreach ($existingById as $id => $node) {
			if (isset($seenIds[$id])) {
				continue;
			}
			try {
				$node->delete();
				$pruned++;
			} catch (\Throwable $e) {
				$this->logger->warning('prune stale file failed', [
					'app' => Application::APP_ID,
					'workflowId' => $id,
					'teamFolder' => $mapping->teamFolder,
					'exception' => $e,
				]);
			}
		}
		return $pruned;
	}

	/**
	 * Bulk push: send every `sync` file under each mapping back to n8n (NC treated
	 * as source of truth). Used by the "Sync now → n8n" button. Delegates per
	 * mapping to {@see pushOne}; `link` mappings never push.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, status:string, message:?string}
	 */
	public function pushAll(): array {
		$processed = 0;
		$succeeded = 0;
		$failed = 0;
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			$res = $this->pushOne($mapping);
			$processed += $res['processed'];
			$succeeded += $res['succeeded'];
			$failed += $res['failed'];
			if (is_string($res['message'] ?? null) && $res['message'] !== '') {
				$errors[] = $res['message'];
			}
		}
		return [
			'processed' => $processed,
			'succeeded' => $succeeded,
			'failed' => $failed,
			'status' => $failed === 0 ? 'ok' : 'error',
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * Push a single mapping's `sync` files up to n8n (the per-mapping "Sync to
	 * n8n" control, saga §14.6). Files outside this mapping's folder — including
	 * every `unmapped` file — are never seen, so they are never pushed. A `link`
	 * mapping is a no-op (a pointer has nothing to push).
	 *
	 * @return array{processed:int, succeeded:int, failed:int, message:?string}
	 */
	public function pushOne(Mapping $mapping): array {
		if ($mapping->mode !== Mapping::MODE_SYNC) {
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null];
		}
		if (!$this->storage->isAvailable($mapping)) {
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null];
		}
		$folder = $this->storage->findFolder($mapping);
		if ($folder === null) {
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null];
		}
		$processed = 0;
		$succeeded = 0;
		$failed = 0;
		$errors = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof \OCP\Files\File || !str_ends_with($node->getName(), FilenameCodec::EXT)) {
				continue;
			}
			$meta = $this->metadata->read($node->getId());
			$id = $meta[WorkflowMetadata::KEY_ID] ?? null;
			if (!is_string($id) || $id === '') {
				continue;
			}
			$processed++;
			try {
				if ($this->push->push($node)) {
					$succeeded++;
				}
			} catch (\Throwable $e) {
				// Carry n8n's own message through to the admin button so a
				// bad workflow is fixable without digging in the logs.
				$failed++;
				$errors[] = $node->getName() . ': ' . $e->getMessage();
				$this->logger->warning('n8n_sync push failed', [
					'app' => Application::APP_ID,
					'file' => $node->getName(),
					'teamFolder' => $mapping->teamFolder,
					'exception' => $e,
				]);
			}
		}
		return [
			'processed' => $processed,
			'succeeded' => $succeeded,
			'failed' => $failed,
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * Generator over every workflow carrying $tag, paginating until n8n stops
	 * returning a `nextCursor`. MAX_PAGES guards a buggy self-referential cursor.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	private function iterateWorkflows(string $tag): iterable {
		$cursor = null;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$batch = $this->n8n->listWorkflows(250, $cursor, [$tag]);
			$rows = $batch['data'] ?? [];
			if (!is_array($rows)) {
				return;
			}
			foreach ($rows as $row) {
				if (is_array($row) && isset($row['id'])) {
					yield $row;
				}
			}
			$cursor = $batch['nextCursor'] ?? null;
			if (!is_string($cursor) || $cursor === '') {
				return;
			}
		}
		$this->logger->warning('iterateWorkflows hit MAX_PAGES guard', [
			'app' => Application::APP_ID,
			'tag' => $tag,
		]);
	}

	/**
	 * Build {n8n_id => File} for managed files anywhere under $root that belong to
	 * $mapping. Recurses subfolders (folder-scoped — the binding from §14.4) and
	 * filters by each file's own `n8n_mapping`: a file explicitly owned by a
	 * *different* mapping (overlapping/nested subtree) is skipped; a legacy file
	 * with no `n8n_mapping` yet is treated as belonging here and backfilled on
	 * write. Stale files survive — pull never deletes.
	 *
	 * @return array<string,\OCP\Files\Node>
	 */
	private function indexByN8nId(Folder $root, Mapping $mapping): array {
		$index = [];
		$this->collectManaged($root, $mapping, $index);
		return $index;
	}

	/** @param array<string,\OCP\Files\Node> $index */
	private function collectManaged(Folder $folder, Mapping $mapping, array &$index): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->collectManaged($node, $mapping, $index);
				continue;
			}
			if (!$node instanceof \OCP\Files\File || !str_ends_with($node->getName(), FilenameCodec::EXT)) {
				continue;
			}
			$meta = $this->metadata->read($node->getId());
			if ($meta === null) {
				continue;
			}
			$id = $meta[WorkflowMetadata::KEY_ID] ?? null;
			if (!is_string($id) || $id === '') {
				continue;
			}
			$owner = $meta[WorkflowMetadata::KEY_MAPPING] ?? null;
			if (is_string($owner) && $owner !== '' && $owner !== $mapping->id) {
				continue; // owned by a different mapping sharing/nesting this subtree
			}
			$index[$id] = $node;
		}
	}

	/**
	 * Reconcile a single workflow into $folder (update-in-place by id, else fresh
	 * write with collision suffix). Metadata + ownership tag follow the body.
	 *
	 * @param array<string,mixed> $workflow
	 * @param array<string,\OCP\Files\Node> $existingById
	 * @param array<string,int> $nameCounts
	 */
	private function writeWorkflow(
		Folder $folder,
		Mapping $mapping,
		array $workflow,
		array $existingById,
		array &$nameCounts,
	): void {
		$id = (string)$workflow['id'];
		$displayName = (string)($workflow['name'] ?? $id);
		$versionId = (string)($workflow['versionId'] ?? '');

		$body = $mapping->mode === Mapping::MODE_LINK
			? $this->encodeReference($workflow)
			: $this->encodeSync($workflow);

		$existing = $existingById[$id] ?? null;
		if ($existing instanceof \OCP\Files\File) {
			$desired = FilenameCodec::format($displayName, $id, false, 0);
			if ($existing->getName() !== $desired) {
				try {
					// Rename within the file's OWN folder — never yank a file the
					// user put in a subfolder back to the mapping root.
					$existing->move($existing->getParent()->getPath() . '/' . $desired);
				} catch (\Throwable $e) {
					$this->logger->info('rename skipped (collision?)', [
						'app' => Application::APP_ID,
						'from' => $existing->getName(),
						'to' => $desired,
						'exception' => $e,
					]);
				}
			}
			$existing->putContent($body);
			$this->metadata->write($existing->getId(), [
				WorkflowMetadata::KEY_ID => $id,
				WorkflowMetadata::KEY_MODE => $mapping->mode,
				WorkflowMetadata::KEY_VERSION_ID => $versionId,
				WorkflowMetadata::KEY_SYNCED_HASH => sha1($body),
				WorkflowMetadata::KEY_MAPPING => $mapping->id,
			]);
			$this->tags->apply($existing->getId(), $mapping->mode);
			return;
		}

		$basename = $displayName === '' ? $id : $displayName;
		$collision = $nameCounts[$basename] ?? 0;
		while (true) {
			$candidate = FilenameCodec::format($displayName, $id, false, $collision);
			if (!$folder->nodeExists($candidate)) {
				break;
			}
			$collision++;
			if ($collision > 1000) {
				throw new \RuntimeException('Could not find a unique filename for ' . $basename);
			}
		}
		$nameCounts[$basename] = $collision + 1;

		$file = $folder->newFile($candidate, $body);
		$this->metadata->write($file->getId(), [
			WorkflowMetadata::KEY_ID => $id,
			WorkflowMetadata::KEY_MODE => $mapping->mode,
			WorkflowMetadata::KEY_VERSION_ID => $versionId,
			WorkflowMetadata::KEY_SYNCED_HASH => sha1($body),
			WorkflowMetadata::KEY_MAPPING => $mapping->id,
		]);
		$this->tags->apply($file->getId(), $mapping->mode);
	}

	/**
	 * Tiny pointer body for `reference` mode, incl. the deep-link URL.
	 *
	 * @param array<string,mixed> $workflow
	 */
	private function encodeReference(array $workflow): string {
		$id = (string)$workflow['id'];
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'n8n_url', ''), '/');
		$tags = [];
		foreach ($workflow['tags'] ?? [] as $t) {
			if (is_array($t) && isset($t['name'])) {
				$tags[] = (string)$t['name'];
			}
		}
		$payload = [
			'$schema' => 'n8n.reference/v1',
			'id' => $id,
			'name' => (string)($workflow['name'] ?? $id),
			'url' => $base === '' ? null : $base . '/workflow/' . $id,
			'tags' => $tags,
		];
		return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Full workflow JSON for `sync` mode, verbatim so Phase 4 writeback is a
	 * simple PUT of the file contents.
	 *
	 * @param array<string,mixed> $workflow
	 */
	private function encodeSync(array $workflow): string {
		return json_encode($workflow, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Optional purge when a mapping is removed (spec UC-4): delete only the files
	 * this integration created — those carrying `n8n_id` metadata — in the
	 * mapping's Team Folder. The Team Folder itself, foreign files, and hand-made
	 * `*.n8n.json` that were never synced (no `n8n_id`) are all left intact.
	 *
	 * @return int number of files deleted
	 */
	public function purgeManagedFiles(Mapping $mapping): int {
		$folder = $this->storage->findFolder($mapping);
		if ($folder === null) {
			return 0;
		}
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof \OCP\Files\File) {
				continue;
			}
			$meta = $this->metadata->read($node->getId());
			$id = $meta[WorkflowMetadata::KEY_ID] ?? null;
			if (is_string($id) && $id !== '') {
				// SyncGuard suppresses DeleteToN8nListener (§17.7). Purge cleans
				// up the local mirror because the mapping is gone — n8n itself
				// is untouched by definition, regardless of mode/writeback.
				$this->guard->run(function () use ($node): void {
					$node->delete();
				});
				$count++;
			}
		}
		return $count;
	}
}
