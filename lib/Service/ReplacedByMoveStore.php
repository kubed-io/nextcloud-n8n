<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Request-scoped note that a file is about to be REPLACED by a move rather than
 * deleted — and of the identity the replaced file held, so the arrival can inherit
 * it instead of imposing its own.
 *
 * ## AN OVERWRITE REPLACES CONTENTS, NOT IDENTITY
 *
 * That is the whole rule, and it is easiest to see when the two files are NOT the
 * same workflow. Say the mapped folder holds `foo.n8n` bound to workflow **A**, and
 * an unmapped `foo.n8n` bound to **B** is moved in over it. Let the arrival keep B
 * and the folder now mirrors B — while A is still live in n8n, still carrying the
 * mapping's tag, and no longer has a file. The next pull finds a tagged workflow
 * with no mirror and writes one, so `foo (1).n8n` reappears beside the file that
 * replaced it. One overwrite, and the mapping has quietly forked.
 *
 * So the destination's workflow id wins. The arrival contributes its BODY — which
 * is what the person answering "keep the new version" was choosing between — and
 * inherits the binding that was already there, exactly as if its bytes had been
 * pasted into the existing file. B is left alone: not deleted, not archived, not
 * re-minted. It is simply a workflow whose file is gone, which is the same state
 * any unmapped file's workflow is in.
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
	/** @var array<int,true> file ids about to be replaced — their workflow is not deleted */
	private array $replaced = [];

	/** @var array<int,string> moving file id ⇒ the workflow id it should ADOPT on landing */
	private array $adopt = [];

	/**
	 * Record one overwrite: $replacedFileId is being destroyed to make room for
	 * $movingFileId, and $workflowId is the identity the destination held.
	 *
	 * An empty $workflowId records the suppression without an adoption — the file
	 * being replaced was not one of ours, so there is nothing to inherit.
	 */
	public function mark(int $replacedFileId, int $movingFileId, string $workflowId): void {
		$this->replaced[$replacedFileId] = true;
		if ($workflowId !== '') {
			$this->adopt[$movingFileId] = $workflowId;
		}
	}

	/** Is this file being replaced by a move rather than deleted? */
	public function isReplaced(int $fileId): bool {
		return isset($this->replaced[$fileId]);
	}

	/**
	 * The workflow the file landing at this id should bind to, or null when it is
	 * not arriving through an overwrite and keeps whatever it carried.
	 */
	public function adoptedWorkflowId(int $movingFileId): ?string {
		return $this->adopt[$movingFileId] ?? null;
	}
}
