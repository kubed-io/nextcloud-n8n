<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * A request-scoped hand-off for the identity of a file that is being moved, held
 * between the before- and after-rename events of the SAME request (saga Ch3 §14.2).
 *
 * ## WHY A FILE CAN ARRIVE SOMEWHERE WITHOUT ITS OWN NAME ON IT
 *
 * Everything this app knows about a workflow file — its `n8n_id`, its mode, which
 * mapping owns it, the version it last agreed with n8n, the tag baseline — lives in
 * core's `files_metadata` table, keyed by file id. That table does not survive a move
 * between two *storages*. Nextcloud implements a cross-storage move as a copy followed
 * by an unlink of the source, and the unlink takes the metadata row with it — measured
 * on a live instance, where the file id was preserved and the metadata came back `[]`.
 *
 * Two mapped Team Folders are two storages whenever groupfolders is configured with
 * `separate-storage` (which is the default on current versions), and a Team Folder and
 * a home folder always are. So the ordinary gesture "drag a workflow from one mapped
 * folder to another" delivers a file that Nextcloud regards as never having been ours.
 *
 * ## WHAT THAT COST, AND WHY THE STASH IS THE FIX
 *
 * An arriving `*.n8n` with no `n8n_id` is exactly what create-on-land exists to adopt,
 * so {@see \OCA\N8nSync\Listener\CreateInN8nListener} minted a BRAND-NEW workflow for a
 * file that already had one. The original kept the source mapping's tag, the next pull
 * saw it and wrote it back into the folder it had just left, and the user was left with
 * the same workflow in two places under two ids. Every downstream listener was reasoning
 * correctly from a premise the storage layer had already destroyed.
 *
 * The `Before` event still sees the source node with its metadata intact, so the values
 * are read there and put back on the target in the `After` event before any other
 * listener looks. The key is the source **path**, not the file id: the id happens to be
 * preserved by the cache move today, but that is an implementation detail of one storage
 * pair, whereas both events name the same source path by construction.
 *
 * Scoped to the request (the service is a per-request singleton) and consumed on read,
 * so a stash can never leak into an unrelated move.
 *
 * @psalm-import-type ManagedValues from WorkflowMetadata
 */
final class MoveIdentityStore {
	/**
	 * Stashed metadata values, keyed by the source path of the move.
	 *
	 * @var array<string, ManagedValues>
	 */
	private array $stash = [];

	/**
	 * Remember what the file at `$sourcePath` was, for the length of this request.
	 *
	 * @param ManagedValues $values the metadata key/value set to restore
	 */
	public function keep(string $sourcePath, array $values): void {
		if ($values === []) {
			return;
		}
		$this->stash[$sourcePath] = $values;
	}

	/**
	 * Take back what was stashed for `$sourcePath`, or null when nothing was.
	 * Consuming: a stash is good for exactly one move.
	 *
	 * @return ManagedValues|null
	 */
	public function take(string $sourcePath): ?array {
		$values = $this->stash[$sourcePath] ?? null;
		unset($this->stash[$sourcePath]);
		return $values;
	}
}
