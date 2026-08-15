<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * One file sitting in a Nextcloud trash, reduced to the three things this app has any
 * business knowing about it: which file it is, what it was called, and how to destroy
 * it.
 *
 * ## WHY THIS EXISTS AT ALL INSTEAD OF PASSING `ITrashItem` AROUND
 *
 * `OCA\Files_Trashbin\Trash\ITrashItem` lives in the trashbin APP's namespace, not
 * OCP, and `files_trashbin` is removable — the same fact that makes
 * {@see TrashControl} resolve its manager lazily. Every signature that names the
 * interface is a file that cannot be analysed without a psalm suppression and a class
 * that cannot be unit-tested on a machine where the app is absent, which in this repo
 * is every machine (the unit suite runs against `nextcloud/ocp` alone).
 *
 * So the interface stops at {@see TrashControl}'s boundary and callers get this. The
 * decision of WHICH trashed mirrors to destroy — the part with the rules in it, and
 * the part worth testing — then lives in ordinary code with no trash types in sight
 * ({@see TrashReconcileService}).
 *
 * ## THE TWO OPERATIONS ARE CLOSURES, NOT IDS TO LOOK UP AGAIN
 *
 * `ITrashManager::removeItem()` and `restoreItem()` both need the item OBJECT, because
 * they dispatch on the item's own backend — a Team Folder's trash and a home trash
 * restore and delete through entirely different code. There is no stable "purge by file
 * id" call to hand back instead, and re-finding the item by name would race the listing
 * it came from. Holding the bound calls keeps the dispatch where it belongs, inside the
 * trash app's own types.
 *
 * Two closures rather than one because the two directions are both real: a workflow
 * deleted in n8n purges its mirror, and a workflow unarchived in n8n brings its mirror
 * back out. Restoring the entry is what makes the second one the SAME file rather than
 * a fresh copy written beside a trashed original.
 */
final class TrashedFile {
	/**
	 * @param int $fileId the filecache id, unchanged by the trip through the trash —
	 *                    which is what makes the file's `n8n_*` metadata readable here,
	 *                    and what identifies the node again after a restore
	 * @param string $name the ORIGINAL basename, not the `.n8n.d<timestamp>` spelling
	 *                     the trash stores it under
	 * @param \Closure():void $purge permanently delete this item, through whichever
	 *                               trash backend is holding it
	 * @param \Closure():void $restore put it back where it came from, same backend
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $name,
		private readonly \Closure $purge,
		private readonly \Closure $restore,
	) {
	}

	/** Destroy this trash entry. There is no undo past here — that is the point of it. */
	public function purge(): void {
		($this->purge)();
	}

	/** Put the file back at the path it was trashed from. */
	public function restore(): void {
		($this->restore)();
	}
}
