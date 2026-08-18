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
use OCP\Files\Node;
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
	/** The pull counters, zeroed — what a skipped mapping reports. */
	private const ZERO_PULL = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0, 'purged' => 0, 'unchanged' => 0];
	/** The push counters, zeroed (message is appended where it applies). */
	private const ZERO_PUSH = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

	public function __construct(
		private MappingService $mappings,
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private StorageService $storage,
		private SyncGuard $guard,
		private PushService $push,
		private IJobList $jobList,
		private SyncStatusService $status,
		private IAppConfig $config,
		private TagSyncService $tagSync,
		private TrashControl $trash,
		private TrashReconcileService $trashReconcile,
		private MirrorTimes $times,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Single parameterized entry point for manual sync (§14).
	 *
	 * @param string $direction SyncStatusService::DIR_PULL|DIR_PUSH
	 * @param string|null $mappingId a specific mapping, or null = all mappings
	 * @param bool $async true = enqueue a background job and return
	 *                    'queued' immediately; false = run inline
	 * @return array<string,mixed>
	 */
	public function dispatch(string $direction, ?string $mappingId, bool $async): array {
		if (!SyncStatusService::isDirection($direction)) {
			throw new \InvalidArgumentException('direction must be "pull" or "push"');
		}
		if ($async) {
			$this->status->markQueued($direction);
			$this->jobList->add(ManualSyncJob::class, ['direction' => $direction, 'mappingId' => $mappingId]);
			// `async` is read by nothing in-repo, but it is part of the endpoint's
			// live JSON payload — payload shape is behaviour, so it stays.
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
		$push = $direction === SyncStatusService::DIR_PUSH;
		if ($mappingId === null || $mappingId === '') {
			return $push ? $this->pushAll() : $this->pullAll();
		}
		$mapping = $this->mappings->getById($mappingId);
		if ($mapping === null) {
			throw new \OutOfBoundsException('Mapping not found');
		}
		$res = $push ? $this->pushOne($mapping) : $this->pullOne($mapping);
		$res['status'] = ($res['failed'] ?? 0) === 0 ? 'ok' : 'error';
		if (!$push) {
			// A pull's per-file errors are counters, not a message.
			$res['message'] = null;
		}
		return $res;
	}

	/**
	 * Pull every mapping in order. Used by the bulk "Sync from n8n" button.
	 *
	 * EVERY COUNTER `pullOne` RETURNS IS ADDED UP HERE. This used to aggregate four of
	 * the five and drop `pruned` on the floor, so a bulk sync that moved three mirrors
	 * to the trash reported `unchanged: 11` and mentioned the removals nowhere — which
	 * is how a working archive-to-trash fix came to look like a broken one for an
	 * afternoon. A counter that is not summed is worse than a counter that does not
	 * exist: it reads as a zero.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, purged:int, unchanged:int, status:string, message:?string}
	 */
	public function pullAll(): array {
		// Backend availability is now per-mapping (Team Folder vs admin-owned),
		// checked in pullOne.
		$total = self::ZERO_PULL;
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			try {
				$res = $this->pullOne($mapping);
				foreach (array_keys($total) as $key) {
					$total[$key] += $res[$key];
				}
			} catch (\Throwable $e) {
				$errors[] = $mapping->teamFolder . ': ' . $e->getMessage();
				$total['failed']++;
				$this->logger->error('pullOne failed for ' . $mapping->teamFolder, [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
		return $total + [
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
	 * It reconciles the TRASH too, and that is a separate pass with a separate rule:
	 * a mirror already in the Nextcloud trash is destroyed once its workflow stops
	 * existing in n8n at all. {@see TrashReconcileService} owns that decision and the
	 * reasoning for reversing the rule that used to say otherwise.
	 *
	 * `unchanged` counts the succeeded files whose body already matched n8n and so
	 * were NOT rewritten — a subset of `succeeded`, not a separate outcome. On a
	 * quiet folder it equals `succeeded`, which is what "nothing to do" looks like.
	 *
	 * `pruned` and `purged` are the two removals, and they are different events worth
	 * different words: `pruned` is a live mirror moved to the trash because its workflow
	 * left the mapping, `purged` is a TRASHED mirror destroyed because its workflow
	 * stopped existing ({@see TrashReconcileService}). Neither is a subset of anything.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, purged:int, unchanged:int}
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
			return self::ZERO_PULL;
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
			// EVERY id the tag listing returned, archived ones included — which is what
			// makes it a proof of EXISTENCE rather than of liveness, and therefore what
			// {@see TrashReconcileService} needs. `$seenIds` cannot serve: it is
			// deliberately missing the archived ids, because its job is to decide what to
			// prune, and an archived workflow's mirror should be pruned.
			$knownIds = [];

			foreach ($this->n8n->eachWorkflow([$mapping->n8nTag]) as $workflow) {
				// AN ARCHIVED WORKFLOW IS NOT A LIVE ONE, and n8n keeps returning it: the
				// tag survives archiving, so `GET /workflows?tags=…` hands it back exactly
				// like a live workflow and the pull used to mirror it as one. Measured on a
				// live instance — 13 workflows on one mapping's tag, 4 of them archived,
				// every one still sitting in Nextcloud as an ordinary file.
				//
				// Leaving it OUT of `$seenIds` is the whole fix: it is not written, and
				// {@see pruneStale} then moves its mirror to the Nextcloud trash, which is
				// the same path a workflow that lost the tag already takes. Archiving in
				// n8n and trashing in Nextcloud become the same gesture seen from two
				// sides, which is what `delete.feature` says they are.
				$knownIds[(string)$workflow['id']] = true;
				if (!empty($workflow['isArchived'])) {
					continue;
				}
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
			// AFTER the prune, not before. The prune is what puts an archived workflow's
			// mirror into the trash, and the reconcile then has to see it there and leave
			// it — its id is in $knownIds, so it does. Running the two in this order is
			// what proves that on every single pull rather than only when a test says so.
			$purged = $this->trashReconcile->reap($mapping, $knownIds);

			return [
				'processed' => $processed,
				'succeeded' => $succeeded,
				'failed' => $failed,
				'pruned' => $pruned,
				'purged' => $purged,
				'unchanged' => $unchanged,
			];
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * Delete managed files that belong to $mapping but whose workflow was not seen in
	 * this pull — because it lost the mapping's tag, or because it was ARCHIVED in n8n
	 * and the pull deliberately skipped it. The workflow is left alone in n8n — only the
	 * local mirror is removed — and the caller already holds the SyncGuard so the delete
	 * does not mirror back. Returns the number of files pruned.
	 *
	 * `Node::delete()` is a move to the Nextcloud trash, not a destruction, which is what
	 * makes this the right mechanism for an archive: n8n hid the workflow without losing
	 * it, and Nextcloud does the same to the file. Unarchiving in n8n brings the workflow
	 * back into the tag listing and the next pull writes a fresh mirror.
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
				$this->removeMirror($node, $mapping);
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
	 * Remove a mirror whose workflow is no longer live in the mapping — and decide, from
	 * the mapping's MODE, whether the user gets it back.
	 *
	 *   sync  → the Nextcloud trash. The file IS the workflow's content, and the thing
	 *           that happened in n8n (an archive) is itself reversible, so the local
	 *           gesture must be too. Restoring the file unarchives the workflow.
	 *   link  → gone, with no trash entry. A link is a read-only projection; once the
	 *           workflow is out of the tag there is nothing for a restore to reconnect
	 *           to, and a trashed pointer would offer the user exactly that. The
	 *           workflow itself is untouched in n8n, which is the whole point of a link.
	 *
	 * {@see TrashControl} explains why pausing the trash is the only supported way to
	 * make a delete permanent, and why it is the right one for a Team Folder.
	 */
	private function removeMirror(Node $node, Mapping $mapping): void {
		if ($mapping->mode !== Mapping::MODE_LINK) {
			$node->delete();
			return;
		}
		// A STATEMENT BODY, not an arrow function. `Node::delete()` is `void`, and while
		// PHP evaluates a void call in expression position to null quite happily, writing
		// it as `fn () => $node->delete()` implies a result that does not exist — it read
		// as a bug to a reviewer, which is reason enough.
		$this->trash->withoutTrash(static function () use ($node): void {
			$node->delete();
		});
	}

	/**
	 * Bulk push: send every `sync` file under each mapping back to n8n (NC treated
	 * as source of truth). Used by the "Sync now → n8n" button. Delegates per
	 * mapping to {@see pushOne}; `link` mappings never push.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, status:string, message:?string}
	 */
	public function pushAll(): array {
		// Summed by key, same as pullAll — a counter that is not summed is worse
		// than a counter that does not exist.
		$total = self::ZERO_PUSH;
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			$res = $this->pushOne($mapping);
			foreach (array_keys($total) as $key) {
				$total[$key] += $res[$key];
			}
			if (is_string($res['message'] ?? null) && $res['message'] !== '') {
				$errors[] = $res['message'];
			}
		}
		return $total + [
			'status' => $total['failed'] === 0 ? 'ok' : 'error',
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
		// Nothing to push: a link mapping is a pointer, an unavailable backend has
		// no folder, and a missing folder has no files.
		if ($mapping->mode !== Mapping::MODE_SYNC
			|| !$this->storage->isAvailable($mapping)
			|| ($folder = $this->storage->findFolder($mapping)) === null) {
			return self::ZERO_PUSH + ['message' => null];
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
					$this->reconcileTagsOnPush($node->getId(), $managed);
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
	 * write.
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
	 * write with collision suffix). Metadata follows the body, and the mode written
	 * is the mapping's — there is no per-workflow override and no pill to keep in
	 * step with it.
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

		// NO MIRROR IN THE FOLDER IS NOT THE SAME AS NO MIRROR. A workflow that was
		// archived had its mirror moved to the trash, so the moment it is unarchived it
		// reappears in the tag listing with nothing here to match — and writing a fresh
		// file then leaves the user with a new one in the folder and their original in
		// the trash, both claiming the same workflow. Bringing the trashed one back makes
		// the file that returns the SAME file, which is what makes unarchiving the undo
		// of archiving rather than something that merely looks like it.
		$existing = $existingById[$id] ?? $this->trashReconcile->restoreMirror($mapping, $id);
		if ($existing instanceof \OCP\Files\File) {
			// THE SUFFIX IS PART OF THE NAME THIS FILE IS ENTITLED TO KEEP. n8n permits
			// two workflows to share a name and Nextcloud does not permit two files to
			// share a name, so the second mirror wears a counter — and asking for index
			// 0 unconditionally told it, on every single pull, to go and take a name the
			// first mirror is sitting on.
			//
			// It "worked" by throwing: the move failed, the catch below logged
			// `rename skipped (collision?)`, and the file kept its suffix by accident.
			// Every tick, for every duplicate. An exception is not a naming policy, and
			// the log line's own question mark says nobody was sure it was one.
			$desired = $this->desiredMirrorName($existing, $displayName, $id);
			if ($desired !== null && $existing->getName() !== $desired) {
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
			$this->reconcileTagsOnPull($fileId, $workflow);
			$this->applySourceTimes($existing, $workflow, $wrote);
			return $wrote;
		}

		$basename = $displayName === '' ? $id : $displayName;
		$collision = $this->firstFreeCollision($folder, $displayName, $id, $nameCounts[$basename] ?? 0, null);
		if ($collision === null) {
			throw new \RuntimeException('Could not find a unique filename for ' . $basename);
		}
		$candidate = FilenameCodec::format($displayName, $id, false, $collision);
		$nameCounts[$basename] = $collision + 1;

		$file = $folder->newFile($candidate, $body);
		$this->metadata->stampSynced($file->getId(), $id, $effectiveMode, $versionId, $body, $mapping->id);
		$this->reconcileTagsOnPull($file->getId(), $workflow);
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
	 * What an EXISTING mirror should be called, given the name its workflow now carries —
	 * collision counter included when another file holds the plain name.
	 *
	 * The plain name is always preferred: a workflow whose duplicate was deleted in n8n
	 * should get its unsuffixed name back rather than wear a counter forever. Failing
	 * that, the first free counter is taken, and reaching the file's OWN current name
	 * counts as free — that is how a legitimate duplicate keeps the suffix it has instead
	 * of being renamed on every tick.
	 *
	 * Returns null when no name is available at all, which the caller reads as "leave it
	 * alone". A wrong-but-unique name is strictly better than an exception here: the
	 * workflow's identity lives in its metadata, so a mirror is never lost by being
	 * misnamed, and a pull that aborts over cosmetics would strand the whole folder.
	 */
	private function desiredMirrorName(\OCP\Files\File $existing, string $displayName, string $id): ?string {
		$collision = $this->firstFreeCollision($existing->getParent(), $displayName, $id, 0, $existing->getName());
		return $collision === null ? null : FilenameCodec::format($displayName, $id, false, $collision);
	}

	/**
	 * The first collision counter (from $from, capped at 1000) whose formatted
	 * name is free in $parent. A candidate equal to $treatAsFree counts as free —
	 * that is how a rename scan lets a file keep its own current name. Null when
	 * every counter is taken; the two callers disagree on what that means (throw
	 * vs leave-alone), so the decision stays with them.
	 */
	private function firstFreeCollision(Folder $parent, string $displayName, string $id, int $from, ?string $treatAsFree): ?int {
		// The cap never cuts off the starting counter itself: the pre-refactor loop
		// would still try a start value above 1000 and only give up moving PAST it.
		$cap = max($from, 1000);
		for ($collision = $from; $collision <= $cap; $collision++) {
			$candidate = FilenameCodec::format($displayName, $id, false, $collision);
			if ($candidate === $treatAsFree || !$parent->nodeExists($candidate)) {
				return $collision;
			}
		}
		return null;
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
	private function reconcileTagsOnPull(int $fileId, array $workflow): void {
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
	private function reconcileTagsOnPush(int $fileId, ManagedFile $managed): void {
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
	 * `*.n8n` that were never synced (no `n8n_id`) are all left intact.
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
				// SyncGuard suppresses DeleteToN8nListener (§17.7). This cleans
				// up the local mirror because the mapping is gone — n8n itself
				// is untouched by definition, regardless of mode.
				$this->guard->run(function () use ($node): void {
					$node->delete();
				});
				$count++;
			}
		}
		return $count;
	}
}
