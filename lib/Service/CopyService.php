<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * The copy half of the motion lifecycle (saga Ch3 §14.2 `copy.feature`). Where a
 * MOVE is "the SAME workflow relocating" (see {@see MotionService}), a COPY is
 * ALWAYS a brand-new instance — it never inherits the original's n8n identity.
 *
 * Copy is therefore the single safest point to strip metadata: whatever the source
 * was (sync, link, unmapped), the copy starts clean. Two things happen here,
 * driven by {@see \OCA\N8nSync\Listener\CopyListener} on {@see
 * \OCP\Files\Events\Node\NodeCopiedEvent}:
 *
 *   1. **Strip identity.** Wipe any `n8n_id` / mode / mapping metadata and any
 *      ownership tag from the copy. Nextcloud does not propagate Files-Metadata or
 *      system tags across a copy today, so this is normally a no-op — but doing it
 *      explicitly makes "a copy starts clean" a guarantee, not an accident of core
 *      internals.
 *   2. **Register if mapped.** If the copy landed inside a mapped folder, create it
 *      as a NEW workflow in n8n ({@see CreateService::createForFile}, which mints a
 *      fresh id — it never reads any id out of the JSON body). A copy that landed
 *      outside any mapping is left as a plain, untracked document.
 *
 * Failures are logged and swallowed: the NC copy already happened, and a copy that
 * failed to register is just an untracked `.n8n.json` the user can re-save to retry.
 */
final class CopyService {
	public function __construct(
		private CreateService $createService,
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private TagSyncService $tagSync,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Handle a freshly-copied `*.n8n.json` file: strip any inherited identity, then
	 * register it as a new workflow if it landed in a mapping.
	 */
	public function onCopy(File $node): void {
		$this->stripIdentity($node);
		$this->adoptBodyTags($node);

		$mapping = $this->mappings->resolveForPath($node->getPath());
		if ($mapping === null) {
			return; // landed outside any mapping — a plain, untracked file
		}

		// Inside a mapping → a brand-new workflow with its own fresh id.
		$this->createService->createForFile($node, $mapping);
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
			$decoded = json_decode($node->getContent(), true);
			$tags = $this->tagSync->contentTagsFromWorkflow(is_array($decoded) ? $decoded : []);
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
