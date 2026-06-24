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
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Re-mode a managed workflow file between **sync** and **link** (saga Ch2 §14.2b
 * `mode-change.feature`). The workflow identity (`n8n_id`) is preserved — only how
 * Nextcloud holds it changes:
 *
 *   sync → link : the file content collapses to the small pointer (id/name/URL);
 *                 it stops pushing. No data loss — n8n still holds the full workflow.
 *   link → sync : the full workflow JSON is pulled down into the file; two-way resumes.
 *
 * **Mutual exclusivity** is the other half: a managed file carries exactly one of
 * `n8n:sync` / `n8n:link`. {@see OwnershipTags::apply()} stamps the target tag and
 * strips the other, so "the just-added tag wins" falls out for free — adding
 * `n8n:link` to a sync file routes here with target=link, which strips `n8n:sync`.
 *
 * Triggered by {@see \OCA\N8nSync\Listener\ModeTagListener} (a system-tag change, the
 * Files context-menu toggle, or — Phase 2 — an n8n-side override tag on pull). All
 * the writes (content + metadata + tags) run inside the {@see SyncGuard} so the
 * implicit re-writes don't echo back into the tag listener or the writeback push.
 */
final class ModeChangeService {
	public function __construct(
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private OwnershipTags $ownershipTags,
		private SyncGuard $guard,
		private MappingService $mappings,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Transition $node to $target ∈ {sync, link, ignored}. No-op (safe to call
	 * repeatedly) when the file isn't managed, $target isn't a known mode, or the
	 * file is already in $target — in the already-there case the ownership tag is
	 * still re-asserted (for sync/link) so a stray duplicate mode tag resolves to one.
	 *
	 * `ignored` is the exclude case (saga §14.8): the workflow is **archived** in n8n,
	 * the file's mode flips to `ignored` but its body, id, and folder location are all
	 * kept, and the sync/link ownership pills are stripped (an ignored file carries the
	 * user's hand-set `n8n:ignore` marker, not an auto-managed pill). Subsequent
	 * pulls/pushes skip it ({@see SyncService}).
	 */
	public function changeTo(File $node, string $target): void {
		if (
			$target !== Mapping::MODE_SYNC
			&& $target !== Mapping::MODE_LINK
			&& $target !== WorkflowMetadata::MODE_IGNORED
		) {
			return;
		}

		$meta = $this->metadata->read($node->getId());
		$id = is_array($meta) ? ($meta[WorkflowMetadata::KEY_ID] ?? null) : null;
		if (!is_string($id) || $id === '') {
			return; // not a managed workflow file — nothing to re-mode
		}

		// An UNMAPPED file (ejected from its mapping: full JSON kept on disk, workflow
		// archived in n8n) must never be re-moded to sync/link by a stray tag. There is
		// no link outside a mapping — flipping it to `link` would collapse the full body
		// to a pointer aimed at an archived workflow, a silent data loss into a broken-link
		// limbo. The `n8n:unmapped` pill is authoritative: re-assert it (which strips the
		// manually-added n8n:sync/n8n:link) and leave the body untouched. Moving the file
		// back INTO a mapping is the only supported way to revive it (MotionService::moveIn).
		if (
			($meta[WorkflowMetadata::KEY_MODE] ?? '') === WorkflowMetadata::MODE_UNMAPPED
			&& ($target === Mapping::MODE_SYNC || $target === Mapping::MODE_LINK)
		) {
			$this->logger->info('n8n_sync mode-change: refused a sync/link re-mode of an unmapped file; kept it unmapped', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $id,
				'requested' => $target,
			]);
			$this->guard->run(fn () => $this->ownershipTags->apply($node->getId(), WorkflowMetadata::MODE_UNMAPPED));
			return;
		}

		if (($meta[WorkflowMetadata::KEY_MODE] ?? '') === $target) {
			// Already in the target mode. For sync/link re-assert the single correct
			// tag (resolves a manually-added duplicate); `ignored` has no pill to
			// re-assert, so just stop.
			if ($target !== WorkflowMetadata::MODE_IGNORED) {
				$this->guard->run(fn () => $this->ownershipTags->apply($node->getId(), $target));
			}
			return;
		}

		if ($target === WorkflowMetadata::MODE_IGNORED) {
			$this->changeToIgnored($node, $id);
			return;
		}

		// We need the live workflow to rebuild the body in the new shape.
		try {
			$workflow = $this->n8n->getWorkflow($id);
		} catch (N8nApiException $e) {
			$this->logger->warning('n8n_sync mode-change: could not fetch workflow; leaving file as-is', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $id,
				'exception' => $e,
			]);
			return;
		}

		$body = $target === Mapping::MODE_LINK
			? $this->encodeReference($workflow)
			: $this->encodeSync($workflow);

		$this->guard->run(function () use ($node, $target, $workflow, $body): void {
			$node->putContent($body);
			$this->metadata->write($node->getId(), [
				WorkflowMetadata::KEY_MODE => $target,
				WorkflowMetadata::KEY_VERSION_ID => (string)($workflow['versionId'] ?? ''),
				WorkflowMetadata::KEY_SYNCED_HASH => sha1($body),
			]);
			// Stamp the target tag + strip the other (mutual exclusivity).
			$this->ownershipTags->apply($node->getId(), $target);
		});
	}

	/**
	 * Un-ignore a managed file: the user removed the `n8n:ignore` marker, so the file
	 * returns to its mapping's default mode (saga §14.8). The mirror of
	 * {@see changeToIgnored} — the workflow is **unarchived** in n8n, then the file is
	 * re-moded to the mapping default (sync or link) by {@see changeTo()}, which rebuilds
	 * the body and re-stamps the ownership pill.
	 *
	 * No-op when the file isn't managed or isn't currently `ignored` (so a stray unassign
	 * of another file, or a double-remove, does nothing). A file in no resolvable mapping
	 * falls back to `sync` — its full JSON is already on disk, so two-way simply resumes.
	 */
	public function unignore(File $node): void {
		$meta = $this->metadata->read($node->getId());
		$id = is_array($meta) ? ($meta[WorkflowMetadata::KEY_ID] ?? null) : null;
		if (!is_string($id) || $id === '') {
			return; // not a managed workflow file — nothing to un-ignore
		}
		if (($meta[WorkflowMetadata::KEY_MODE] ?? '') !== WorkflowMetadata::MODE_IGNORED) {
			return; // only an actually-ignored file un-ignores
		}

		// Unarchive the workflow (mirror of the ignore archive). A 404 means it was
		// hard-deleted while ignored — there is nothing to unarchive; changeTo() then
		// finds it gone and leaves the file as-is, which is the best we can do.
		try {
			$this->n8n->unarchiveWorkflow($id);
		} catch (N8nApiException $e) {
			if ($e->httpStatus !== 404) {
				$this->logger->warning('n8n_sync un-ignore: could not unarchive workflow; leaving file as-is', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'workflowId' => $id,
					'exception' => $e,
				]);
				return;
			}
		}

		$mapping = $this->mappings->resolveForPath($node->getPath());
		$default = $mapping?->mode ?? Mapping::MODE_SYNC;
		$this->changeTo($node, $default);
	}

	/**
	 * Flip a managed file to `ignored`: archive the workflow in n8n, stamp
	 * `n8n_mode = ignored`, and strip the sync/link pills — keeping the file's body,
	 * id, and location untouched. The full JSON is left in place so the file is still
	 * readable/editable and a later un-ignore can restore it. The archive call is the
	 * only side effect that can fail; if it does, the file is left as-is.
	 */
	private function changeToIgnored(File $node, string $id): void {
		try {
			$this->n8n->archiveWorkflow($id);
		} catch (N8nApiException $e) {
			$this->logger->warning('n8n_sync ignore: could not archive workflow; leaving file as-is', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'workflowId' => $id,
				'exception' => $e,
			]);
			return;
		}

		$this->guard->run(function () use ($node): void {
			$this->metadata->write($node->getId(), [
				WorkflowMetadata::KEY_MODE => WorkflowMetadata::MODE_IGNORED,
			]);
			// `ignored` has no auto-managed pill — drop sync/link/unmapped, keep the
			// user's n8n:ignore marker (it is not in OwnershipTags::ALL).
			$this->ownershipTags->clear($node->getId());
		});
	}

	/**
	 * Pointer body for link mode (id, name, url, tags). Mirrors
	 * {@see SyncService::encodeReference} — replicated here so the re-mode engine
	 * owns no dependency on the bulk reconciler.
	 *
	 * @param array<string,mixed> $workflow
	 */
	private function encodeReference(array $workflow): string {
		$id = (string)($workflow['id'] ?? '');
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'n8n_url', ''), '/');
		$tags = [];
		foreach ($workflow['tags'] ?? [] as $t) {
			if (is_array($t) && isset($t['name'])) {
				$tags[] = (string)$t['name'];
			}
		}
		$payload = [
			'$schema' => 'n8n.reference/v1',
			'id' => $id,
			'name' => (string)($workflow['name'] ?? $id),
			'url' => $base === '' ? null : $base . '/workflow/' . $id,
			'tags' => $tags,
		];
		return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Full workflow JSON for sync mode, verbatim (so a later writeback is a simple PUT
	 * of the file contents). Mirrors {@see SyncService::encodeSync}.
	 *
	 * @param array<string,mixed> $workflow
	 */
	private function encodeSync(array $workflow): string {
		return json_encode($workflow, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
