<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * The `.n8n` files already sitting under a folder a mapping is about to claim
 * (`mapping/create.feature`).
 *
 * ## WHAT THIS PREVENTS IS A STATE THE APP HAS NO ANSWER FOR
 *
 * A `link` mirror is a pointer at a workflow n8n owns. A workflow file that is
 * nobody's mirror sitting inside a link mapping is a contradiction, and every rule
 * that reads one has to guess which it is. `mapping/delete.feature` says a link
 * mapping's files all go; the motion rules say an unmapped file keeps its identity
 * and is nobody's business. Both are right about a tree that should not exist.
 *
 * It is not hypothetical, and the Grafana sibling reached it on a live instance in
 * three steps: a folder mapped `sync`, the mapping removed (leaving real workflow
 * files behind, unmapped), then re-mapped `link` over them. CI could not have
 * caught it, because every scenario builds a clean tree.
 *
 * So the contradiction is designed out at the only moment it can be created.
 *
 * ## PURGED, NOT TRASHED — THE ONE PLACE THIS APP DESTROYS SOMETHING
 *
 * A trashed file offers a restore, and restoring INTO a link mapping is already
 * ruled out — a link folder refuses authoring ({@see \OCA\N8nSync\Listener\CreateGuardListener}),
 * so there is nowhere for the bytes to go. Rather than invent an answer for a
 * restore that cannot work, the files never reach the trash.
 *
 * Which is why {@see under()} exists as its own call. Nothing here purges without
 * the admin having been told HOW MANY and that they are not recoverable, and the
 * count has to come from the same walk that does the deleting, or the number in the
 * warning is a different question's answer.
 *
 * ## IT RUNS UNDER THE GUARD, OR IT ARCHIVES THE WORKFLOWS IN n8n
 *
 * The files this destroys are `unmapped`, and an unmapped file KEEPS its `n8n_id` —
 * that is the whole point of the state. So each `delete()` here fires the same
 * `BeforeNodeDeletedEvent` a person's delete does, and
 * {@see \OCA\N8nSync\Listener\DeleteToN8nListener} answers that by archiving the
 * workflow in n8n. Without {@see SyncGuard} raised, clearing a folder so it can
 * mirror a tag would archive workflows that have nothing to do with the gesture.
 *
 * ## ONLY `link`, AND ONLY WHAT IS THERE NOW
 *
 * A `sync` mapping adopts the files it finds — that is create-on-land, and it is
 * the feature. This walk is asked for only when the mode is `link`, by the one
 * caller that knows the mode.
 */
final class ExistingWorkflows {
	/**
	 * How deep the walk goes before it refuses to answer.
	 *
	 * A CEILING RATHER THAN A RECURSION LIMIT: the number is not tuned to anything,
	 * it exists so a pathological tree cannot hang a request that an admin is
	 * waiting on. Past it the walk THROWS rather than answering "nothing found" —
	 * see {@see workflowsBelow()}.
	 */
	private const MAX_DEPTH = 32;

	public function __construct(
		private StorageService $storage,
		private TrashControl $trash,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every `.n8n` file under the mapping's folder, at any depth.
	 *
	 * Answers `[]` when the folder does not exist yet, which is the ordinary case:
	 * most mappings are made against a folder the app is about to create.
	 *
	 * @return list<File>
	 */
	public function under(Mapping $mapping): array {
		$root = $this->storage->findFolder($mapping);

		return $root === null ? [] : $this->workflowsBelow($root, 0);
	}

	/**
	 * Destroy them, permanently, and answer how many went.
	 *
	 * NEVER THROWS. A file that will not delete is logged and stepped over: the
	 * mapping this clears the way for has already been created, and failing here
	 * would leave the admin with a mapping they cannot see and an error they cannot
	 * act on. The survivor is visible in the folder and in the log.
	 *
	 * @param list<File> $workflows from {@see under()}, so the count the admin
	 *                              acknowledged is the set that is destroyed
	 */
	public function purge(array $workflows): int {
		if ($workflows === []) {
			return 0;
		}

		$purged = 0;

		// ONE GUARD FOR THE WHOLE SWEEP. See the class docblock: without it every
		// delete below reaches n8n and archives the workflow the file names.
		$this->guard->run(function () use ($workflows, &$purged): void {
			foreach ($workflows as $workflow) {
				$path = $workflow->getPath();

				try {
					$this->trash->withoutTrash(static function () use ($workflow): void {
						$workflow->delete();
					});
				} catch (\Throwable $e) {
					$this->logger->warning('n8n_sync: could not purge a workflow file to make way for a link mapping', [
						'app' => Application::APP_ID,
						'file' => $path,
						'exception' => $e,
					]);

					continue;
				}

				$purged++;
				$this->logger->info('n8n_sync: purged a workflow file to make way for a link mapping', [
					'app' => Application::APP_ID,
					'file' => $path,
				]);
			}
		});

		return $purged;
	}

	/**
	 * @return list<File>
	 */
	private function workflowsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			// A FOLDER TOO DEEP TO SCAN IS NOT AN EMPTY FOLDER, which is the identical
			// reasoning to the unreadable case below. Answering "nothing found" here
			// would let a link mapping be created over workflow files that really are
			// there, just deeper than the ceiling — the exact state this class exists to
			// prevent, reached through the one door left unlocked.
			//
			// The Grafana sibling shipped this branch answering `[]` while the other one
			// threw, so it failed closed on one way of not knowing and open on the
			// other. Copilot caught it there; it is written correctly here from the
			// start.
			$this->logger->error('n8n_sync: a folder tree was too deep to scan for existing workflow files', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'depth' => $depth,
			]);

			throw new \InvalidArgumentException(sprintf(
				'"%s" is nested more than %d levels deep, so it is not possible to tell whether it '
				. 'already holds workflow files. Nothing was changed — map a folder nearer the top, '
				. 'or flatten the tree.',
				$folder->getName(),
				self::MAX_DEPTH,
			));
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			// AN UNREADABLE FOLDER IS NOT AN EMPTY ONE. Answering "nothing found" here
			// would let the mapping be created over workflow files nobody could see.
			//
			// SO IT FAILS CLOSED, as an `InvalidArgumentException` — the type both front
			// doors already turn into a refusal the admin can read, rather than a 500
			// from the panel and a stack trace from `occ`.
			$this->logger->error('n8n_sync: could not read a folder while scanning for existing workflow files', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			throw new \InvalidArgumentException(sprintf(
				'"%s" could not be read, so it is not possible to tell whether it already holds '
				. 'workflow files. Nothing was changed.',
				$folder->getName(),
			), 0, $e);
		}

		$found = [];
		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->workflowsBelow($child, $depth + 1) as $nested) {
					$found[] = $nested;
				}

				continue;
			}
			if ($child instanceof File && FilenameCodec::isWorkflowName($child->getName())) {
				$found[] = $child;
			}
		}

		return $found;
	}
}
