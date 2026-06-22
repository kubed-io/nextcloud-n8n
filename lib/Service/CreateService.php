<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use Psr\Log\LoggerInterface;

/**
 * Create-on-land (§17.2): turn a `*.n8n.json` file in a mapped folder that
 * carries no `n8n_id` yet into a real n8n workflow.
 *
 * Triggered by {@see \OCA\N8nSync\Listener\CreateInN8nListener} when:
 *  - a new file is dropped via the Files "New" menu (§15.11) into a mapped
 *    folder, or saved there from the Text editor;
 *  - a hand-made `.n8n.json` is moved/dropped into a mapped folder from
 *    elsewhere (re-attach side of the §17.1 eject flow);
 *  - an external WebDAV PUT lands content in a mapped folder.
 *
 * Body shape mirrors {@see PushService::pushViaApi} exactly (writable-field
 * whitelist, settings allowlist, `[]→{}` coercion) so a workflow that round-
 * trips through "create then later push" is byte-stable. Defaults match the
 * frontend's `STARTER_WORKFLOW`: empty `nodes` / `connections` / `settings`,
 * `active: false` (n8n's POST `/workflows` ignores `active` regardless).
 *
 * Tag handling: assignment is **additive only**. We `ensureTag` the mapping's
 * tag in n8n, then PUT the workflow's tag list **as merge** (existing tags +
 * ours). We never strip tags we don't own — n8n's tag namespace is the user's.
 *
 * The post-create stamp (Files-Metadata + ownership system tag + mimetype
 * re-stamp) is wrapped in {@see SyncGuard} so the implicit re-write doesn't
 * echo into {@see \OCA\N8nSync\Listener\NodeWrittenListener} as a writeback.
 */
final class CreateService {
	/**
	 * Same allowlist as {@see PushService::pushViaApi}. n8n's `WorkflowSettings`
	 * schema is `additionalProperties: false`, so any extras 400 the request.
	 */
	private const SETTINGS_ALLOWED = [
		'saveExecutionProgress',
		'saveManualExecutions',
		'saveDataErrorExecution',
		'saveDataSuccessExecution',
		'executionTimeout',
		'errorWorkflow',
		'timezone',
		'executionOrder',
	];

	public function __construct(
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private OwnershipTags $ownershipTags,
		private SyncGuard $guard,
		private IMimeTypeLoader $mimeLoader,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create $node's contents as a workflow in n8n, tag it with $mapping->n8nTag
	 * (additively), and stamp the file with full sync metadata.
	 *
	 * Returns the new n8n workflow id on success. Throws on any unrecoverable
	 * failure; the listener turns that into a notification.
	 */
	public function createForFile(File $node, Mapping $mapping): string {
		$content = $node->getContent();
		$wf = $this->parseFileBody($content);

		$body = $this->buildCreateBody($wf, $node->getName());
		$created = $this->n8n->createWorkflow($body);

		$id = (string)($created['id'] ?? '');
		if ($id === '') {
			throw new \RuntimeException('n8n create did not return a workflow id');
		}
		$versionId = (string)($created['versionId'] ?? '');

		// Tag (additive). Failure here is non-fatal — the workflow exists,
		// we'd rather stamp the file and let the user re-tag manually than
		// orphan an n8n workflow with no NC link. Logged, never thrown.
		$this->applyMappingTagAdditive($id, $created, $mapping->n8nTag);

		// Re-fetch ourselves the new content if n8n re-shaped anything?
		// No — POST /workflows echoes the body back as-stored, so the file the
		// user wrote is what's now in n8n. Use the original $content for the
		// loop-guard hash (sha1 must match what NodeWrittenListener computes
		// from $node->getContent() on the next save).
		$this->stampFile($node, $mapping, $id, $versionId, $content);

		return $id;
	}

	/**
	 * Decode the file as an stdClass (objects, not assoc) so empty `{}` round-
	 * trips through `[]→{}` coercion correctly. An empty/non-object body is
	 * tolerated — we'll fall through to the starter defaults.
	 */
	private function parseFileBody(string $content): \stdClass {
		if (trim($content) === '') {
			return new \stdClass();
		}
		try {
			$decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException('file is not valid JSON: ' . $e->getMessage(), 0, $e);
		}
		return $decoded instanceof \stdClass ? $decoded : new \stdClass();
	}

	/**
	 * Build the POST `/workflows` body. Defaults match the frontend's
	 * `STARTER_WORKFLOW`. Name authority: prefer the file's JSON `name` if
	 * non-empty; else the parsed file stem; else "Untitled".
	 *
	 * @return array<string,mixed>
	 */
	private function buildCreateBody(\stdClass $wf, string $basename): array {
		$body = [];
		foreach (['name', 'nodes', 'connections', 'settings', 'staticData'] as $k) {
			if (isset($wf->$k)) {
				$body[$k] = $wf->$k;
			}
		}

		// Name: the file stem usually matches user intent; the JSON name takes
		// precedence only when explicitly set.
		$jsonName = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
		if ($jsonName === '') {
			$body['name'] = $this->stemFromBasename($basename);
		}

		// Required-field defaults (starter shape).
		if (!isset($body['nodes']) || !is_array($body['nodes'])) {
			$body['nodes'] = [];
		}
		if (!isset($body['connections'])) {
			$body['connections'] = new \stdClass();
		}
		if (!isset($body['settings'])) {
			$body['settings'] = new \stdClass();
		}

		// Settings allowlist — drop anything n8n's strict schema would reject.
		if ($body['settings'] instanceof \stdClass) {
			$filtered = new \stdClass();
			foreach (self::SETTINGS_ALLOWED as $k) {
				if (isset($body['settings']->$k)) {
					$filtered->$k = $body['settings']->$k;
				}
			}
			$body['settings'] = $filtered;
		}

		// `[]→{}` coercion for object-typed fields. (n8n's GET sometimes returns
		// `[]` for empty objects, which would re-encode wrongly through assoc
		// arrays — this matches PushService::pushViaApi's safety net.)
		foreach (['connections', 'settings', 'staticData'] as $k) {
			if (isset($body[$k]) && $body[$k] === []) {
				$body[$k] = new \stdClass();
			}
		}

		return $body;
	}

	/**
	 * Strip the `.n8n.json` extension and any trailing " (N)" collision suffix
	 * to derive a clean name from the on-disk filename.
	 */
	private function stemFromBasename(string $basename): string {
		$parsed = FilenameCodec::parse($basename);
		$name = is_array($parsed) ? trim((string)($parsed['name'] ?? '')) : '';
		return $name !== '' ? $name : 'Untitled';
	}

	/**
	 * Ensure $tagName exists in n8n and PUT it onto the workflow, **merging**
	 * with any tags the create response already returned (POST `/workflows`
	 * preserves tags the body declared; merging keeps any pre-existing tags
	 * the user explicitly carried over from another instance). Failure here is
	 * logged and swallowed — see createForFile() rationale.
	 *
	 * @param array<string,mixed> $created the workflow as returned by POST
	 */
	private function applyMappingTagAdditive(string $workflowId, array $created, string $tagName): void {
		try {
			$tagId = $this->n8n->ensureTag($tagName);
			$existing = [];
			foreach (($created['tags'] ?? []) as $t) {
				if (is_array($t) && isset($t['id']) && is_string($t['id']) && $t['id'] !== '') {
					$existing[] = $t['id'];
				}
			}
			$merged = $existing;
			if (!in_array($tagId, $merged, true)) {
				$merged[] = $tagId;
			}
			$this->n8n->setWorkflowTags($workflowId, $merged);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: failed to assign mapping tag to created workflow', [
				'app' => Application::APP_ID,
				'workflowId' => $workflowId,
				'tag' => $tagName,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Stamp Files-Metadata (id + mode + writeback + versionId + syncedHash +
	 * mapping), apply the ownership system tag, and re-stamp the custom
	 * mimetype on the row so the icon shows immediately. All wrapped in the
	 * SyncGuard so the implicit re-writes don't echo into the writeback
	 * listener.
	 */
	private function stampFile(File $node, Mapping $mapping, string $id, string $versionId, string $content): void {
		$this->guard->run(function () use ($node, $mapping, $id, $versionId, $content): void {
			$this->metadata->write($node->getId(), [
				WorkflowMetadata::KEY_ID => $id,
				WorkflowMetadata::KEY_MODE => $mapping->mode,
				WorkflowMetadata::KEY_VERSION_ID => $versionId,
				WorkflowMetadata::KEY_SYNCED_HASH => sha1($content),
				WorkflowMetadata::KEY_MAPPING => $mapping->id,
			]);
			$this->ownershipTags->apply($node->getId(), $mapping->mode);
			try {
				$this->mimeLoader->updateFilecache('n8n.json', $this->mimeLoader->getId('application/n8n+json'));
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync: post-create mimetype re-stamp failed', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'exception' => $e,
				]);
			}
		});
	}
}
