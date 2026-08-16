<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Exception\N8nApiException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * The last third of the delete story: the Nextcloud trash follows n8n's archive in BOTH
 * directions.
 *
 *   workflow deleted out of the archive  → its trashed mirror is purged ({@see reap})
 *   workflow unarchived                  → its trashed mirror comes BACK
 *                                          ({@see restoreMirror})
 *
 * A mirror stays in the trash only for as long as its workflow still exists in n8n.
 *
 * ## THE RULE, AND WHY IT IS THE MIRROR OF EVERY OTHER ONE
 *
 * Trashing and archiving are already the same gesture seen from two sides
 * (`delete.feature`), and restoring and unarchiving are already each other's undo
 * (`restore.feature`). The trash itself was the piece that only worked one way: emptying
 * the Nextcloud trash destroyed the workflow, and destroying the workflow left the
 * Nextcloud trash holding a mirror of something that no longer existed.
 *
 * So: **a workflow deleted out of n8n's archive purges its trashed mirror too.** Once a
 * workflow is neither live nor archived, a trash entry offering to restore it is offering
 * something that cannot happen — the restore would find nothing to unarchive and would
 * mint a NEW workflow ({@see DeleteService::restore}), which is a create dressed up as an
 * undo. The trash is now mirrored in both directions, which is the whole claim the
 * feature files make.
 *
 * ## THIS REVERSES A DECISION, AND THE OLD ONE WAS NOT SILLY
 *
 * The previous rule was *a workflow deleted in n8n leaves the trashed file alone*, on the
 * grounds that once n8n has destroyed the workflow the trashed file is the LAST COPY OF
 * IT IN EXISTENCE, and reaching in to delete that on a schedule is the most destructive
 * thing this app could do. That fear is correct about the stakes and wrong about the
 * gesture: removing a workflow from n8n's archive is not an accident anyone has on a
 * schedule. It is the second, deliberate step of a two-step delete — the user already
 * archived it once — and it is exactly the gesture Nextcloud spells "empty the trash".
 * Mirroring a purge with a purge is the same symmetry the rest of the lifecycle runs on.
 *
 * What the app must not do is guess. Every branch below refuses to purge unless it can
 * PROVE the workflow is gone, and n8n being unreachable is not proof.
 *
 * ## WHAT IT WILL NOT TOUCH
 *
 *   - a trash entry with no `n8n_id` — never ours, never was
 *   - a file belonging to a DIFFERENT mapping — that mapping's pull will judge it
 *   - a file whose mode is not `sync` — an `unmapped` file left its mapping and its
 *     workflow is not this app's business any more (`purge.feature` says the same
 *     thing about the user-driven purge), and a `link` is never trashed at all
 *   - anything at all while the answer from n8n is uncertain
 *
 * ## THE EXISTENCE CHECK IS USUALLY FREE
 *
 * The pull has just listed every workflow carrying the mapping's tag, and n8n returns
 * ARCHIVED workflows in that listing exactly like live ones (the tag survives archiving —
 * the fact that {@see SyncService::pullOne} was built on). So the ids it saw are a
 * ready-made "still exists" set, and the common case — a mirror trashed because its
 * workflow was archived — is answered without a single extra API call.
 *
 * Only an id the listing did NOT contain needs asking about, and it needs asking rather
 * than assuming: absent from the tag listing means "deleted OR merely untagged", and
 * those two must not share an outcome. One `GET /workflows/{id}` tells them apart.
 */
final class TrashReconcileService {
	/**
	 * This mapping's trashed mirrors for this run, so a pull that has to ask about
	 * several workflows pays for one trash query rather than one per workflow.
	 *
	 * DROPPED THE MOMENT THE TRASH CHANGES. A restore or a purge makes the cached list
	 * a description of a trash that no longer exists, and acting on a stale entry means
	 * restoring something twice or purging something already gone — both of which throw
	 * from deep inside a backend and get logged as mysteries.
	 *
	 * Indexed BY WORKFLOW ID, not left as a flat list, because the restore side asks
	 * about one workflow at a time and a pull asks about all of them. A linear scan per
	 * question would be a metadata read per trash entry per workflow written — a first
	 * sync of 200 workflows against a trash holding 20 old mirrors is 4,000 queries to
	 * answer 200 questions. Built once, asked 200 times.
	 *
	 * @var array<string,TrashedFile>|null
	 */
	private ?array $mirrors = null;
	private string $mirrorsKey = '';

	public function __construct(
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private TrashControl $trash,
		private TeamFolderService $teamFolders,
		private IRootFolder $rootFolder,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The trashed mirror of $workflowId, brought back to the folder it was trashed
	 * from — or null when there isn't one.
	 *
	 * ## WHY THE PULL ASKS BEFORE IT WRITES
	 *
	 * Unarchiving a workflow in n8n puts it back in the tag listing, and the pull then
	 * finds no mirror for it in the mapped folder — because the mirror is in the trash,
	 * where the archive put it. Left to itself the pull does the only other thing it
	 * knows: it writes a NEW file. The user unarchives one workflow and ends up with a
	 * fresh file in the folder and their original still in the trash, carrying the same
	 * id, waiting to confuse the next thing that looks.
	 *
	 * Restoring the trash entry instead makes the file that comes back the SAME file:
	 * same id, same metadata, same tags, same version history. That is what makes
	 * unarchiving the exact undo of archiving rather than merely a similar-looking
	 * outcome, and it is the difference `restore.feature` pins.
	 *
	 * Costs nothing in the steady state: a mapped folder normally holds a mirror for
	 * every live workflow, so this is only reached when one is genuinely missing.
	 *
	 * Never throws — a restore that cannot happen leaves the pull to write a fresh file,
	 * which is the old behaviour and merely untidy, not broken.
	 */
	public function restoreMirror(Mapping $mapping, string $workflowId): ?File {
		if ($workflowId === '') {
			return null;
		}
		$uid = $this->actorUid($mapping);
		if ($uid === null) {
			return null;
		}

		$trashed = $this->mirrors($uid, $mapping)[$workflowId] ?? null;
		if ($trashed === null) {
			return null;
		}

		try {
			// The pull already holds the SyncGuard, but this does not rely on that: a
			// restore emits `post_restore`, and {@see \OCA\N8nSync\Listener\TrashRestoreHook}
			// answers it by unarchiving in n8n — which is where the workflow already is,
			// and which is the news this whole pass is downstream of.
			$this->guard->run(static function () use ($trashed): void {
				$trashed->restore();
			});
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync trash: could not restore the mirror of an unarchived workflow', [
				'app' => Application::APP_ID,
				'fileId' => $trashed->fileId,
				'name' => $trashed->name,
				'workflowId' => $workflowId,
				'exception' => $e,
			]);
			return null;
		}
		$this->mirrors = null;

		$node = $this->resolve($uid, $trashed->fileId);
		$this->logger->info('n8n_sync trash: brought a mirror back out of the trash for an unarchived workflow', [
			'app' => Application::APP_ID,
			'fileId' => $trashed->fileId,
			'name' => $trashed->name,
			'workflowId' => $workflowId,
			'resolved' => $node !== null,
		]);
		return $node;
	}

	/**
	 * Purge $mapping's trashed mirrors whose workflow no longer exists in n8n, and
	 * return how many were destroyed.
	 *
	 * WHOSE TRASH IT LOOKS IN is the sync actor's — the same user the pull writes
	 * through ({@see StorageService}). That is the right and the only available answer:
	 * a pull has no session, and the actor is by construction a member of every Team
	 * Folder this app manages and the owner of every admin-folder mapping, so their
	 * `listTrashRoot` covers exactly the folders a mapping can write into. Who did the
	 * deleting does not matter — a Team Folder's trash belongs to the folder.
	 *
	 * Never throws. A trash reconcile that fails must not take down the pull that
	 * carried it; the mirrors are still there and the next tick tries again.
	 *
	 * @param array<string,bool> $liveIds ids n8n returned for this mapping's tag, live
	 *                                    and archived alike — see the class docblock
	 */
	public function reap(Mapping $mapping, array $liveIds): int {
		$uid = $this->actorUid($mapping);
		if ($uid === null) {
			return 0;
		}
		// The write loop and the prune have both run by now and both change the trash, so
		// the index built during the restore pass describes a trash that has moved on.
		$this->mirrors = null;

		$purged = 0;
		foreach ($this->mirrors($uid, $mapping) as $workflowId => $trashed) {
			if (!$this->isGone($workflowId, $liveIds)) {
				continue;
			}

			try {
				// UNDER THE GUARD, because the home trash's purge fires the legacy
				// `preDelete` hook and {@see \OCA\N8nSync\Listener\TrashPurgeHook} would
				// answer it by deleting the workflow in n8n. Harmless in itself — the
				// workflow is the thing that is already gone, so the call would 404 into
				// an idempotent no-op — but it would put a "deleting the workflow" line
				// in the log for a purge that is doing the exact opposite, and this app
				// has lost days to trash diagnostics that said the wrong thing.
				$this->guard->run(static function () use ($trashed): void {
					$trashed->purge();
				});
				$this->mirrors = null;
				$purged++;
				$this->logger->info('n8n_sync trash: purged a mirror whose workflow no longer exists in n8n', [
					'app' => Application::APP_ID,
					'fileId' => $trashed->fileId,
					'name' => $trashed->name,
					'workflowId' => $workflowId,
					'mapping' => $mapping->id,
				]);
			} catch (\Throwable $e) {
				// A member without delete permission on the Team Folder, a backend that
				// refused: leave the entry alone and say so. It is still recoverable,
				// which is the failure direction to prefer.
				$this->logger->warning('n8n_sync trash: could not purge a trashed mirror', [
					'app' => Application::APP_ID,
					'fileId' => $trashed->fileId,
					'name' => $trashed->name,
					'workflowId' => $workflowId,
					'exception' => $e,
				]);
			}
		}
		return $purged;
	}

	/**
	 * Whose trash to look in: the sync actor's, or null when there isn't one.
	 *
	 * `resolveActorUid()` throws on an instance whose built-in admin group has no
	 * members. A pull must survive that — the reconcile is a pass inside the pull, not
	 * the point of it — so it is caught here rather than at every call site.
	 */
	private function actorUid(Mapping $mapping): ?string {
		try {
			return $this->teamFolders->resolveActorUid();
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync trash: no sync actor, so no trash to reconcile', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * $mapping's trashed `sync` mirrors in $uid's trash, keyed by the workflow each one
	 * mirrors — the single gate both directions pass through, so a restore and a purge
	 * can never disagree about what this app owns.
	 *
	 * The name is tested before the metadata because it costs nothing and answers almost
	 * everything: this is a whole user's trash, and the overwhelming majority of what is
	 * in it has never had anything to do with this app. Only the entries that look like
	 * ours cost a query.
	 *
	 * TWO MIRRORS OF ONE WORKFLOW CANNOT BOTH BE KEYED, and the later one wins. That is
	 * not a case worth ceremony: it needs the same workflow mirrored twice in one mapping
	 * AND both copies trashed, and either survivor is a correct answer — the loser is
	 * left in the trash for the next tick, which will key it once the winner is out.
	 *
	 * @return array<string,TrashedFile>
	 */
	private function mirrors(string $uid, Mapping $mapping): array {
		$key = $uid . "\0" . $mapping->id;
		if ($this->mirrors !== null && $this->mirrorsKey === $key) {
			return $this->mirrors;
		}

		$index = [];
		foreach ($this->trash->listTrashed($uid) as $trashed) {
			if (!FilenameCodec::isWorkflowName($trashed->name)) {
				continue;
			}
			$managed = $this->metadata->read($trashed->fileId);
			if (!$managed?->isManaged() || !$managed->isSync() || $managed->mappingId !== $mapping->id) {
				continue;
			}
			$index[$managed->workflowId] = $trashed;
		}

		$this->mirrors = $index;
		$this->mirrorsKey = $key;
		return $index;
	}

	/**
	 * The restored file, found by the id it kept through the trash.
	 *
	 * Null is survivable and is not treated as a failure: the file IS back either way —
	 * that is what the restore did — and the caller falls back to writing a fresh mirror
	 * only if it cannot get a node to update. Looked up by id rather than by path
	 * because a restore can land the file under a `(1)` name when something has since
	 * taken its original one, and the id is the identity anyway.
	 */
	private function resolve(string $uid, int $fileId): ?File {
		try {
			$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync trash: restored the mirror but could not find it afterwards', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * Is $id really gone from n8n — not archived, not merely untagged, GONE?
	 *
	 * Answers **false whenever it cannot tell**, and that asymmetry is the safety
	 * property of this whole class. A wrong "no" leaves a trash entry the next tick will
	 * look at again; a wrong "yes" destroys the last copy of a workflow. So an
	 * unreachable n8n, a 500, a transport error — every one of them means "leave it".
	 * Only an explicit 404 counts as proof.
	 *
	 * @param array<string,bool> $liveIds the tag listing, which already proves existence
	 *                                    for everything in it
	 */
	private function isGone(string $id, array $liveIds): bool {
		if ($id === '' || isset($liveIds[$id])) {
			return false;
		}
		try {
			$this->n8n->getWorkflow($id);
			return false; // exists, just not under this tag any more
		} catch (N8nApiException $e) {
			if ($e->httpStatus === 404) {
				return true;
			}
			$this->logger->warning('n8n_sync trash: could not confirm a workflow is gone; leaving its mirror in the trash', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
				'status' => $e->httpStatus,
				'exception' => $e,
			]);
			return false;
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync trash: could not reach n8n to confirm a workflow is gone', [
				'app' => Application::APP_ID,
				'workflowId' => $id,
				'exception' => $e,
			]);
			return false;
		}
	}
}
