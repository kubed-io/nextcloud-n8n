<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\BackgroundJob\ReconcileNameJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The copy half of the motion lifecycle (saga Ch3 §14.2 `copy.feature`). Where a
 * MOVE is "the SAME workflow relocating" (see {@see MotionService}), a COPY is
 * ALWAYS a brand-new instance — it never inherits the original's n8n identity.
 *
 * Copy is therefore the single safest point to strip metadata: whatever the source
 * was (sync, link, unmapped), the copy starts clean. Four things happen here,
 * driven by {@see \OCA\N8nSync\Listener\CopyListener} on {@see
 * \OCP\Files\Events\Node\NodeCopiedEvent}:
 *
 *   1. **Strip identity.** Wipe any `n8n_id` / mode / mapping metadata and any
 *      ownership tag from the copy. Nextcloud does not propagate Files-Metadata or
 *      system tags across a copy today, so this is normally a no-op — but doing it
 *      explicitly makes "a copy starts clean" a guarantee, not an accident of core
 *      internals.
 *   2. **Adopt the body's tags** as the copy's own pills ({@see adoptBodyTags}) —
 *      the source's pills are bound to its file id and do not travel.
 *   3. **Register if mapped.** If the copy landed inside a mapped folder, create it
 *      as a NEW workflow in n8n ({@see CreateService::createForFile}, which mints a
 *      fresh id — it never reads any id out of the JSON body). A copy that landed
 *      outside any mapping is left as a plain, untracked document.
 *   4. **Settle its name**, which is the part that was missing.
 *
 * ## THE NAME NEXTCLOUD PICKED IS THE COPY'S REAL NAME
 *
 * A copy landing beside its source collides, so Nextcloud names it — `Board (1).n8n`.
 * That name is the copy's name **in all three places**: `createForFile` puts the
 * filename's display name on the body so n8n is right from the first write, and step 3
 * writes it into the file's JSON `name` to match. Without it a copy reached n8n under
 * the ORIGINAL's name — two workflows, one name, and a file claiming a third thing.
 *
 * The name-in-the-JSON is a file write, so it is deferred to {@see ReconcileNameJob} —
 * the copy's own hook holds locks on the file it just made, and `putContent()` there
 * throws. n8n is correct within the request; the file catches up a tick later.
 *
 * **THE FILE ITSELF NEEDS NO CORRECTING, and that is the single-segment extension's
 * doing.** Nextcloud's counter goes before the LAST extension, so the retired
 * `.n8n.json` made a copy `Board.n8n (1).json` — a name ending in `.json` that matched
 * none of this app's predicates, leaving a file that looks managed and is not (confirmed
 * live as `FooBoblicious.n8n (1).json`). Reading it took a `canonicalise()` pass in front
 * of every predicate, un-writing it took a second deferred rename, and pulling that
 * rename forward into the request (a Sabre plugin rewriting the COPY `Destination`) broke
 * the Files app outright: it stats the path IT chose the instant the copy returns, and
 * only for a copy landing in the folder it came from — precisely and only the case that
 * collides. Measured live in the grafana sibling: intercepting gives COPY 201 then STAT
 * 404; deferring gives 201 then 207. With one segment the counter lands where
 * {@see FilenameCodec::format()} puts it and none of that machinery has anything to do.
 *
 * Failures are logged and swallowed: the NC copy already happened, and a copy that
 * failed to register is just an untracked `.n8n` the user can re-save to retry.
 */
final class CopyService {
	use ResolvesActingUser;

	public function __construct(
		private CreateService $createService,
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private TagSyncService $tagSync,
		private SyncGuard $guard,
		private IJobList $jobList,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Handle a freshly-copied `*.n8n` file: strip any inherited identity, then
	 * register it as a new workflow if it landed in a mapping.
	 */
	public function onCopy(File $node): void {
		$this->stripIdentity($node);
		$this->adoptBodyTags($node);

		$mapping = $this->mappings->resolveForPath($node->getPath());
		if ($mapping === null) {
			return; // landed outside any mapping — a plain, untracked file
		}
		// A LINK MAPPING IS FILLED FROM n8n AND FROM NOWHERE ELSE. The user-facing
		// refusal is {@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin}, which stops the copy
		// before a file exists at all; this is the backstop for every other route (occ,
		// another app, a script), where the file is already on disk and the most this can
		// do is decline to mint a workflow for it. Registering one would put a workflow in
		// n8n that the mapping's own tag does not select, and the next pull would delete
		// the file it came from.
		if ($mapping->mode === Mapping::MODE_LINK) {
			$this->logger->warning('n8n_sync copy: a link mapping is filled from n8n; the copy was left untracked', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
			]);
			return;
		}

		// Inside a mapping → a brand-new workflow with its own fresh id, carrying the
		// name Nextcloud just gave the file rather than the one it was copied from.
		$this->createService->createForFile($node, $mapping, true);

		$this->settleName($node);
	}

	/**
	 * Hand the copy's name to {@see ReconcileNameJob}, which runs once the locks this
	 * hook holds are gone: rename the file out of Nextcloud's spelling into ours if it
	 * somehow arrived wearing it, and write the resulting display name into the JSON.
	 *
	 * `name_from_filename` is the right action because a copy IS a naming — the file was
	 * just given a name by Nextcloud, exactly as a rename gives it one by hand, and both
	 * make the filename the authority. The job re-checks everything and no-ops when a
	 * copy needed neither, which is the ordinary case.
	 */
	private function settleName(File $node): void {
		// The job resolves the file per-user, because team-folder files are mounted that
		// way — same reason the async push job takes one.
		$uid = $this->actingUserUid($node);
		if ($uid === '') {
			return;
		}
		$this->jobList->add(ReconcileNameJob::class, [
			'fileId' => $node->getId(),
			'userId' => $uid,
			'action' => 'name_from_filename',
		]);
	}

	/**
	 * Wipe the copy's managed metadata + ownership tags so it carries none of the
	 * original's n8n identity. Wrapped in the {@see SyncGuard} so the implicit writes
	 * don't echo into the writeback listener.
	 */
	private function stripIdentity(File $node): void {
		$this->guard->run(function () use ($node): void {
			$this->metadata->clear($node->getId());
		});
	}

	/**
	 * Give the copy the pills its own body asks for.
	 *
	 * A COPY IS THE ONE MOMENT THE TWO NEXTCLOUD SURFACES PROVABLY DIVERGE, and the
	 * app has already promised they do not: a file's pills and its `tags` array are
	 * kept as one set, mapped or not, because that pair needs no remote system
	 * (saga §5.10). Nextcloud copies BYTES — so the copy inherits the body's tags —
	 * but it does not copy system tags, so the copy lands with none. Left alone,
	 * every copy is a file breaking our own rule the instant it exists.
	 *
	 * THE BODY WINS, WHICH IS THE SAME DIRECTION AS ADOPTION. The body is the only
	 * surface that survives being copied, moved, or carried out of Nextcloud, which
	 * is exactly why adoption seeds n8n from it. Deriving the pills from it here
	 * makes copy and adoption two uses of one rule rather than two special cases —
	 * and it is why the alternative (stripping the body's tags to match the empty
	 * pills) would be wrong: it would destroy the seed a copy landing in a mapping
	 * is about to need.
	 *
	 * Purely local: no n8n call, nothing to fail over. If the copy then lands in a
	 * mapping, {@see CreateService::createForFile} reads the same body and carries
	 * the same tags up.
	 */
	private function adoptBodyTags(File $node): void {
		try {
			$tags = $this->tagSync->contentTagsFromBody($node->getContent());
			if ($tags === []) {
				return;
			}
			$this->guard->run(fn () => $this->tagSync->writeNcContentTags($node->getId(), $tags));
		} catch (\Throwable $e) {
			// The copy exists and its body is intact; pills that failed to land are
			// cosmetic and the next reconcile settles them. Never fail a copy for this.
			$this->logger->warning('n8n_sync copy: could not apply the body tags as pills', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'exception' => $e,
			]);
		}
	}
}
