<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Request-scoped note that a file is about to be REPLACED by a move rather than
 * deleted, so the delete path can tell the two apart.
 *
 * ## WHY NEXTCLOUD CANNOT TELL YOU THIS ITSELF
 *
 * A WebDAV MOVE onto an existing name is an overwrite, and sabre performs one as
 * a delete of the destination followed by a move
 * ({@see \Sabre\DAV\CorePlugin::httpMove}). The delete half is a real delete: the
 * node goes to the trash and `BeforeNodeDeletedEvent` fires, carrying nothing that
 * says a move is halfway through. So the app archived the workflow in n8n — for a
 * gesture the user experiences as "keep the new version", where nothing was
 * deleted and nothing should be archived.
 *
 * Sabre's `beforeMove` fires from `httpMove` BEFORE that delete, and it is the only
 * moment anything knows both halves are one gesture.
 * {@see \OCA\N8nSync\DAV\ReplacedByMovePlugin} marks the destination there and
 * {@see \OCA\N8nSync\Listener\DeleteToN8nListener} reads the mark.
 *
 * FILE IDS, NOT PATHS. The two sides spell a path differently — sabre works in
 * `files/<uid>/<relative>`, the event carries `/<uid>/files/<relative>` — and a
 * normalisation that is subtly wrong fails OPEN, silently archiving the workflow
 * again. The id is the same integer on both sides.
 *
 * The mark is never cleared. Its lifetime is the request, one MOVE replaces one
 * file, and a stale mark could only matter if the same id were deleted twice in
 * one request — which is the same file being deleted after being replaced, i.e.
 * still not a delete.
 *
 * The sibling of {@see MoveIdentityStore}, and for the same reason: a move is two
 * events, and the thing that makes them one gesture lives only between them.
 */
final class ReplacedByMoveStore {
	/** @var array<int,true> file ids being replaced by a move in this request */
	private array $marked = [];

	public function mark(int $fileId): void {
		$this->marked[$fileId] = true;
	}

	public function isMarked(int $fileId): bool {
		return isset($this->marked[$fileId]);
	}
}
