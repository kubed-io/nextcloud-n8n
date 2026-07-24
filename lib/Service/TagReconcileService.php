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
 * Reactive NC → n8n tag reconcile for a **single** file (saga Ch5 §5.6.2, Slice A).
 * The bulk sync reconciles tags inside its own pull/push loop; this is the small
 * orchestrator the *event-driven* path shares — a content-tag pill edit caught by
 * {@see \OCA\N8nSync\Listener\ContentTagListener} (inline) or drained by
 * {@see \OCA\N8nSync\BackgroundJob\ReconcileTagsJob} (async).
 *
 * It does three things the raw {@see TagSyncService::reconcilePush} can't do on its
 * own, and nothing else:
 *
 *  1. **Gates.** Only a managed **sync** file reconciles — a `link` file's pills are a
 *     read-only projection of n8n (pull-only), and an `unmapped`/`ignored` file is a
 *     plain Nextcloud file the tag machinery must not touch (§5.6 scope).
 *  2. **Resolves the protected set.** n8n binds a workflow to its folder BY TAG, so the
 *     mapping's own tag is force-kept on both sides; we look it up from the file's
 *     recorded `n8n_mapping`. A file whose mapping has vanished protects nothing.
 *  3. **Guards + swallows.** The reconcile writes pills (which re-fire tag events) and
 *     talks to n8n, so it runs inside {@see SyncGuard} (no re-entrancy — the pills it
 *     writes, including a force-kept mapping-tag pop-back, don't re-trigger the
 *     listener) and best-effort logs a failure instead of surfacing it (a tag hiccup
 *     must never break the user's Files action).
 *
 * Slice A deliberately reconciles the **pills** to n8n and does NOT rewrite the file
 * body's `tags` array — the body ⇆ pills projection is Slice B. The body catches up on
 * the next pull; nothing reads the body `tags` as canonical yet, so it can't revert.
 */
final class TagReconcileService {
	public function __construct(
		private MappingService $mappings,
		private WorkflowMetadata $metadata,
		private TagSyncService $tagSync,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile one file's Nextcloud content pills to n8n. No-op (returns false) for a
	 * non-managed, non-sync file; true when a reconcile was attempted (success or a
	 * swallowed failure). Safe to call from an event handler or a background job.
	 */
	public function reconcileFile(File $node): bool {
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged() || !$managed->isSync()) {
			return false; // link/unmapped/ignored/foreign → not a reactive push surface
		}
		$protected = $this->protectedTagsFor($managed);
		$fileId = $node->getId();
		$this->guard->run(function () use ($fileId, $managed, $protected): void {
			try {
				$this->tagSync->reconcilePush($fileId, $managed, $protected);
			} catch (\Throwable $e) {
				// The user's pill click already landed in Nextcloud; a failure to carry
				// it to n8n is logged and retried by the next sync, never surfaced as a
				// broken Files action.
				$this->logger->warning('n8n_sync reactive tag reconcile failed', [
					'app' => Application::APP_ID,
					'fileId' => $fileId,
					'exception' => $e,
				]);
			}
		});
		return true;
	}

	/**
	 * The mapping tags to force-keep for this file: its own mapping's binding tag, or
	 * none if the mapping id is unset or no longer resolves.
	 *
	 * @return list<string>
	 */
	private function protectedTagsFor(ManagedFile $managed): array {
		if ($managed->mappingId === '') {
			return [];
		}
		$mapping = $this->mappings->getById($managed->mappingId);
		return $mapping !== null ? [$mapping->n8nTag] : [];
	}
}
