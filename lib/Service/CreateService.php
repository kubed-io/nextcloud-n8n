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
 * Create-on-land (§17.2): turn a `*.n8n` file in a mapped folder that
 * carries no `n8n_id` yet into a real n8n workflow.
 *
 * Triggered by {@see \OCA\N8nSync\Listener\CreateInN8nListener} when:
 *  - a new file is dropped via the Files "New" menu (§15.11) into a mapped
 *    folder, or saved there from the Text editor;
 *  - a hand-made `.n8n` is moved/dropped into a mapped folder from
 *    elsewhere (re-attach side of the §17.1 eject flow);
 *  - an external WebDAV PUT lands content in a mapped folder.
 *
 * The request body is shaped by {@see N8nWorkflowBody::toCreateBody} — the one
 * place that owns n8n's writable-field whitelist, settings allowlist, and
 * `[]→{}` coercion (shared with {@see PushService}'s update path), so a workflow
 * that round-trips through "create then later push" is byte-stable. Defaults match
 * the frontend's `STARTER_WORKFLOW`: empty `nodes` / `connections` / `settings`.
 *
 * Tag handling: assignment is **additive only**. We `ensureTag` the mapping's
 * tag in n8n, then PUT the workflow's tag list **as merge** (existing tags +
 * ours). We never strip tags we don't own — n8n's tag namespace is the user's.
 *
 * The post-create stamp (the five Files-Metadata sync keys) is wrapped in
 * {@see SyncGuard} so the implicit re-write doesn't echo into
 * {@see \OCA\N8nSync\Listener\NodeWrittenListener} as a writeback.
 */
final class CreateService {
	public function __construct(
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private TagSyncService $tagSync,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create $node's contents as a workflow in n8n, tag it with $mapping->n8nTag
	 * (additively), and stamp the file with full sync metadata.
	 *
	 * Returns the new n8n workflow id on success. Throws on any unrecoverable
	 * failure; the listener turns that into a notification.
	 *
	 * @param bool $namedByNextcloud the caller KNOWS Nextcloud just named this file, so
	 *                               the filename — counter and all — is what the workflow
	 *                               is called. Only a copy sets this; see below.
	 */
	public function createForFile(File $node, Mapping $mapping, bool $namedByNextcloud = false): string {
		$content = $node->getContent();
		$wf = $this->parseFileBody($content);

		// A COPY IS A NAMING, and the bytes cannot know it. The body is the original's,
		// so its `name` still says the original's name; the file it landed in is called
		// whatever Nextcloud picked to dodge the collision. Left alone, a copy made
		// beside its source reached n8n as a second workflow named exactly like the
		// first, while its file said `(1)` — three places, two answers.
		//
		// The filename is the authority here for the same reason it is on a rename: it
		// is the thing that just changed. Note `toCreateBody()` only falls back to the
		// basename when the body carries NO name at all, which a copy never is.
		if ($namedByNextcloud) {
			$display = FilenameCodec::displayName($node->getName());
			if ($display !== '') {
				$wf->name = $display;
			}
		}

		$body = N8nWorkflowBody::toCreateBody($wf, $node->getName());
		$created = $this->n8n->createWorkflow($body);

		$id = (string)($created['id'] ?? '');
		if ($id === '') {
			throw new \RuntimeException('n8n create did not return a workflow id');
		}
		$versionId = (string)($created['versionId'] ?? '');

		// Tag (additive). Failure here is non-fatal — the workflow exists,
		// we'd rather stamp the file and let the user re-tag manually than
		// orphan an n8n workflow with no NC link. Logged, never thrown.
		// THE ADOPTION RULE (saga §5.10): at this instant the body is the ONLY record of
		// this thing's tags — there are no pills yet on a freshly-landed file, no baseline,
		// and the workflow did not exist a moment ago. So the body seeds n8n. This is also
		// where tags added while the file sat OUTSIDE any mapping finally reach n8n.
		$this->applyMappingTagAdditive($id, $created, $mapping->n8nTag, $this->tagSync->contentTagsFromBody($content));

		// THE BODY DOES NOT LEARN ITS TAGS HERE, AND IT CANNOT. n8n's schema forbids
		// `tags` on create, so they go up in a SECOND call — and that call knows exactly
		// what the workflow ends up carrying, so writing them straight into the file
		// looks obvious. It is not possible from here: this runs INSIDE the handler for
		// the very write that created the file, so `putContent()` on the same node hits
		// Nextcloud's lock and the whole create fails. Tried; it took out every arrange
		// in the suite that lands a file in a mapped folder.
		//
		// The body catches up on the first pull, which rewrites it from n8n's canonical
		// row. Until then `pills ⇄ body` holds trivially — both are empty.
		//
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
	 * Ensure $tagName exists in n8n and PUT it onto the workflow, **merging** with
	 * any tags the create response returned.
	 *
	 * The body's own tag names are ensured in n8n and unioned in alongside the
	 * mapping tag, so a file that arrives already tagged keeps its tags — the
	 * adoption moment is the one time the body is the sole record of them (saga
	 * §5.6.3; `features/tag-sync.feature`'s ADOPTION section is the spec). They
	 * cannot ride the create itself: {@see N8nWorkflowBody::toCreateBody}'s
	 * writable whitelist omits `tags`, and n8n's own schema (`workflowCreate.yml`,
	 * `additionalProperties: false`) marks `tags` **readOnly** — `PUT
	 * /workflows/{id}/tags` is the only writer that exists.
	 *
	 * `$created['tags']` is read and merged even though n8n's schema means it is
	 * always empty today — it costs nothing and stops this from silently discarding
	 * tags if n8n ever does start echoing them back on create.
	 *
	 * Failure here is logged and swallowed — see createForFile() rationale.
	 *
	 * @param array<string,mixed> $created the workflow as returned by POST
	 * @param list<string> $bodyTags content tag names the arriving file already carried
	 */
	private function applyMappingTagAdditive(string $workflowId, array $created, string $tagName, array $bodyTags): void {
		try {
			$existing = [];
			foreach (($created['tags'] ?? []) as $t) {
				if (is_array($t) && isset($t['id']) && is_string($t['id']) && $t['id'] !== '') {
					$existing[] = $t['id'];
				}
			}
			// The mapping tag plus whatever the file arrived carrying. `ensureTags` is the
			// batch form — one tag-list GET for the whole set instead of one per name.
			$names = $tagName === '' ? $bodyTags : array_merge([$tagName], $bodyTags);
			$merged = array_values(array_unique(array_merge($existing, $this->n8n->ensureTags($names))));
			$this->n8n->setWorkflowTags($workflowId, $merged);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: failed to assign tags to created workflow', [
				'app' => Application::APP_ID,
				'workflowId' => $workflowId,
				'tag' => $tagName,
				'bodyTags' => $bodyTags,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Stamp the five Files-Metadata sync keys (id + mode + versionId +
	 * syncedHash + mapping), wrapped in the SyncGuard so the implicit re-write
	 * doesn't echo into the writeback listener.
	 */
	private function stampFile(File $node, Mapping $mapping, string $id, string $versionId, string $content): void {
		$this->guard->run(function () use ($node, $mapping, $id, $versionId, $content): void {
			$this->metadata->stampSynced($node->getId(), $id, $mapping->mode, $versionId, $content, $mapping->id);
		});
	}
}
