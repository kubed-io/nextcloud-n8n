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
 *
 * **A pull writes only what actually changed** (saga Ch5 §5.11): a mirror whose
 * bytes already match n8n is not rewritten, so a pull over a quiet folder leaves
 * every mtime alone. Without that, a 5-minutely scheduled pull reported every
 * mirrored file as modified every 5 minutes — see {@see writeWorkflow}.
 */
final class SyncService {
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
		private TagSyncService $tagSync,
		private MirrorTimes $times,
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
	 * @return array{processed:int, succeeded:int, failed:int, unchanged:int, status:string, message:?string}
	 */
	public function pullAll(): array {
		// Backend availability is now per-mapping (Team Folder vs admin-owned),
		// checked in pullOne.
		$total = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'unchanged' => 0];
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			try {
				$res = $this->pullOne($mapping);
				$total['processed'] += $res['processed'];
				$total['succeeded'] += $res['succeeded'];
				$total['failed'] += $res['failed'];
				$total['unchanged'] += $res['unchanged'];
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
			'unchanged' => $total['unchanged'],
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
	 * `unchanged` counts the succeeded files whose body already matched n8n and so
	 * were NOT rewritten — a subset of `succeeded`, not a separate outcome. On a
	 * quiet folder it equals `succeeded`, which is what "nothing to do" looks like.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, unchanged:int}
	 */
	public function pullOne(Mapping $mapping): array {
		// NO "SKIP A MAPPING WITH NO GROUPS" GUARD ANY MORE. It read
		// $mapping->ncGroups, which no longer exists — the groups are the folder's
		// (see Mapping's class docblock). It was also the wrong call: an unshared
		// folder is the admin's business, visible to them in the mapping card and in
		// Files, and refusing to sync into it turned a sharing question into a
		// mysteriously empty folder.
		if (!$this->storage->isAvailable($mapping)) {
			$this->logger->warning('skipping mapping: storage backend unavailable (Team Folder selected but groupfolders disabled?)', [
				'app' => Application::APP_ID,
				'teamFolder' => $mapping->teamFolder,
			]);
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0, 'unchanged' => 0];
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
			$unchanged = 0;

			$existingById = $this->indexByN8nId($targetFolder, $mapping);
			$nameCounts = [];
			$seenIds = [];

			foreach ($this->n8n->eachWorkflow([$mapping->n8nTag]) as $workflow) {
				$processed++;
				$seenIds[(string)$workflow['id']] = true;
				try {
					if (!$this->writeWorkflow($targetFolder, $mapping, $workflow, $mapping->mode, $existingById, $nameCounts)) {
						$unchanged++;
					}
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
			return [
				'processed' => $processed,
				'succeeded' => $succeeded,
				'failed' => $failed,
				'pruned' => $pruned,
				'unchanged' => $unchanged,
			];
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
			if (!FilenameCodec::isWorkflowFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged()) {
				continue;
			}
			// Push only files that are themselves `sync`. A `link` must not be pushed
			// even though the mapping might be sync. A legacy file with no recorded
			// mode (empty) is treated as sync for backward compatibility.
			if ($managed->mode !== '' && !$managed->isSync()) {
				continue;
			}
			$processed++;
			try {
				if ($this->push->push($node)) {
					// The body already pushed + stamped; a tag hiccup must not report
					// the file as failed (it would mislead the admin and can't retry
					// the body anyway — its hash now matches). Logged, retried next run.
					$this->reconcileTagsOnPush($node->getId(), $managed, $mapping);
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

	/**
	 * @param array<string,\OCP\Files\Node> $index
	 */
	private function collectManaged(Folder $folder, Mapping $mapping, array &$index): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->collectManaged($node, $mapping, $index);
				continue;
			}
			if (!FilenameCodec::isWorkflowFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged()) {
				continue;
			}
			$id = $managed->workflowId;
			$owner = $managed->mappingId;
			if ($owner !== '' && $owner !== $mapping->id) {
				continue; // owned by a different mapping sharing/nesting this subtree
			}
			$index[$id] = $node;
		}
	}

	/**
	 * Reconcile a single workflow into $folder (update-in-place by id, else fresh
	 * write with collision suffix). Metadata + ownership tag follow the body. The
	 * mode written is the mapping's mode (or `null`/skip for an n8n:ignore'd
	 * workflow — saga §14.8), resolved by the caller into $effectiveMode.
	 *
	 * **Change-detected** (saga Ch5 §5.11): an existing mirror is rewritten only when
	 * its bytes actually differ from what n8n would write. This used to be an
	 * unconditional `putContent`, which bumped the mtime of every mirrored file on
	 * every scheduled tick — a folder-wide "Modified a few seconds ago" every 5
	 * minutes that buried the files a human had really touched.
	 *
	 * Returns **true when the body was written** (created or updated), false when the
	 * mirror already matched n8n — the caller's `unchanged` counter.
	 *
	 * @param array<string,mixed> $workflow
	 * @param string $effectiveMode Mapping::MODE_SYNC|MODE_LINK for this workflow
	 * @param array<string,\OCP\Files\Node> $existingById
	 * @param array<string,int> $nameCounts
	 */
	private function writeWorkflow(
		Folder $folder,
		Mapping $mapping,
		array $workflow,
		string $effectiveMode,
		array $existingById,
		array &$nameCounts,
	): bool {
		$id = (string)$workflow['id'];
		$displayName = (string)($workflow['name'] ?? $id);
		$versionId = (string)($workflow['versionId'] ?? '');

		$body = $effectiveMode === Mapping::MODE_LINK
			? N8nWorkflowBody::encodeReference($workflow, $this->config->getValueString(Application::APP_ID, 'n8n_url', ''))
			: N8nWorkflowBody::encodeSync($workflow);

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
			$fileId = $existing->getId();
			// THE fix: the body is the only write here that is not already
			// self-suppressing. Core's metadata layer no-ops an unchanged value
			// (`FilesMetadata::setString` returns early, `saveMetadata` skips when
			// nothing was updated) and the tag writes are diff-based, so stamping and
			// re-tagging an untouched mirror costs nothing and stays unconditional —
			// they also self-heal a mirror whose stamp drifted. `putContent` has no
			// such guard: it rewrote the file, and the mtime, every single tick.
			$wrote = $this->bodyDiffers($existing, $body);
			if ($wrote) {
				$existing->putContent($body);
			}
			$this->metadata->stampSynced($fileId, $id, $effectiveMode, $versionId, $body, $mapping->id);
			$this->tags->apply($fileId, $effectiveMode);
			$this->reconcileTagsOnPull($fileId, $workflow, $mapping);
			$this->applySourceTimes($existing, $workflow, $wrote);
			return $wrote;
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
		$this->metadata->stampSynced($file->getId(), $id, $effectiveMode, $versionId, $body, $mapping->id);
		$this->tags->apply($file->getId(), $effectiveMode);
		$this->reconcileTagsOnPull($file->getId(), $workflow, $mapping);
		$this->applySourceTimes($file, $workflow, true);
		return true;
	}

	/**
	 * Hand the mirror n8n's own clocks — `updatedAt` → modification time, `createdAt` →
	 * creation time — so "Modified" answers *when the workflow changed* rather than
	 * *when the reconciler last wrote this node*. {@see MirrorTimes} owns the framework
	 * plumbing and the write-only-what-differs rule; this is just the field mapping.
	 *
	 * @param array<string,mixed> $workflow the n8n row (carries `updatedAt` / `createdAt`)
	 * @param bool $justWrote true when the body was (re)written in this pass, so the
	 *                        file's mtime is unavoidably `now` and must be restamped
	 */
	private function applySourceTimes(\OCP\Files\File $file, array $workflow, bool $justWrote): void {
		$this->times->apply(
			$file,
			MirrorTimes::parse($workflow['updatedAt'] ?? null),
			MirrorTimes::parse($workflow['createdAt'] ?? null),
			$justWrote,
		);
	}

	/**
	 * Does the mirror on disk differ from the body n8n would write?
	 *
	 * The size check is a free, EXACT "differs" signal — it reads the filecache, not
	 * the storage, so a genuinely changed workflow never costs a download. Only when
	 * the sizes agree do we read the bytes; that read is the price of not writing,
	 * and it is strictly cheaper than the unconditional write it replaces (on object
	 * storage a GET beats a PUT, and a skipped write is also a skipped etag/mtime
	 * bump and a skipped `NodeWrittenEvent`).
	 *
	 * A read we cannot perform answers **true** — writing is the old behaviour, so an
	 * unreadable mirror degrades to "always rewrite" rather than to "never repair".
	 */
	private function bodyDiffers(\OCP\Files\File $file, string $body): bool {
		if ((int)$file->getSize() !== strlen($body)) {
			return true;
		}
		try {
			return $file->getContent() !== $body;
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: could not read mirror for change detection; rewriting it', [
				'app' => Application::APP_ID,
				'file' => $file->getName(),
				'exception' => $e,
			]);
			return true;
		}
	}

	/**
	 * Mirror the n8n workflow's content tags onto the just-written file (saga Ch5
	 * §5.6). Runs for sync AND link — tag searchability is mode-independent. A tag
	 * failure must never sink the pull that already wrote the body, so it is logged
	 * and swallowed; the next pull retries.
	 *
	 * @param array<string,mixed> $workflow
	 */
	private function reconcileTagsOnPull(int $fileId, array $workflow, Mapping $mapping): void {
		$managed = $this->metadata->read($fileId);
		if ($managed === null) {
			return;
		}
		try {
			$this->tagSync->reconcilePull($fileId, $workflow, $managed);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync tag pull failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Push the just-synced file's Nextcloud content tags back to n8n (saga Ch5
	 * §5.6). The body already pushed and stamped, so a tag failure is logged and
	 * swallowed — never promoted to a failed push (that would mislead the admin and
	 * the body can't re-push anyway, its hash now matches). Sync files only.
	 */
	private function reconcileTagsOnPush(int $fileId, ManagedFile $managed, Mapping $mapping): void {
		try {
			$this->tagSync->reconcilePush($fileId, $managed);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync tag push failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
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
			$managed = $this->metadata->read($node->getId());
			if ($managed?->isManaged()) {
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

	/**
	 * The admin "Purge Nextcloud files" action (purge.feature): remove every
	 * **restorable** managed file — `sync` and `link`, whose workflow is still live
	 * and tagged in n8n — from every mapping's folder, so a later "Sync from n8n"
	 * brings them all back. n8n is never contacted (the delete runs under SyncGuard,
	 * which suppresses {@see \OCA\N8nSync\Listener\DeleteToN8nListener}).
	 *
	 * Deliberately KEEPS anything a pull could not restore, so purge can never cost
	 * data: `unmapped` files (their workflow is archived in n8n — they are the
	 * user's standalone copies / templates) and untracked `.n8n.json`
	 * (a plain document the app never created). Recurses subfolders.
	 *
	 * @return array{deleted:int, kept:int}
	 */
	public function purge(): array {
		$deleted = 0;
		$kept = 0;
		foreach ($this->mappings->list() as $mapping) {
			$folder = $this->storage->findFolder($mapping);
			if ($folder === null) {
				continue;
			}
			$this->purgeFolder($folder, $deleted, $kept);
		}
		return ['deleted' => $deleted, 'kept' => $kept];
	}

	/** Recursive worker for {@see purge()}. */
	private function purgeFolder(Folder $folder, int &$deleted, int &$kept): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->purgeFolder($node, $deleted, $kept);
				continue;
			}
			if (!FilenameCodec::isWorkflowFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if ($managed === null || !$managed->isManaged()) {
				continue; // untracked .n8n.json — the user's own, leave it
			}
			// Only sync/link can be restored by a pull; an unmapped file's workflow is
			// archived in n8n (a pull won't bring it back), so it is kept standalone.
			if (!$managed->isSync() && !$managed->isLink()) {
				$kept++;
				continue;
			}
			$this->guard->run(function () use ($node): void {
				$node->delete();
			});
			$deleted++;
		}
	}
}
