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
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Push dispatcher (Nextcloud → n8n). On save, a managed sync-mode file is written
 * back with `PUT /workflows/{id}`, carrying the writable fields and returning the
 * new `versionId`.
 *
 * ## THERE USED TO BE TWO CHANNELS, AND THE SECOND ONE NEVER GREW UP
 *
 * A save could also POST the file to a configured n8n webhook, and the two were
 * described as independent and composable: both on for belt-and-suspenders, API
 * off and webhook on to make a flow the only writer, neither on for a valid no-op.
 * That story cost this class a channel switch, a per-channel error prefix, and a
 * state where saving a file did nothing at all and called it success.
 *
 * The webhook channel is gone (saga Ch5 — deferred, not disowned), so a push is
 * one call with one outcome. There is no `api_enabled` to consult, because "does
 * this app write back?" is not a question an admin should have to answer.
 *
 * Errors are **not** swallowed: a failure is re-thrown as an
 * {@see N8nApiException} carrying n8n's own message so the caller (save listener,
 * async job, or bulk push) can surface it as a user notification or an HTTP error.
 * On success we stamp the file's `n8n_syncedHash` (loop guard) and the `versionId`.
 *
 * Scope: **updates** of workflows we already track (have an `n8n_id`). A file
 * with no id is not one of ours to update — creating a workflow from a
 * fresh file is {@see CreateService}'s job, fired by its own listener — so it
 * is skipped here with a log line.
 */
final class PushService {
	public function __construct(
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Push $node's contents to n8n. Returns true when the file was written back,
	 * false when it isn't a pushable managed file. Throws {@see N8nApiException}
	 * when the write fails.
	 */
	public function push(Node $node): bool {
		if (!$node instanceof File) {
			return false;
		}
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			// No n8n id yet → not ours to update. Creating it in n8n is
			// CreateService's job, on its own event.
			$this->logger->info('n8n_sync writeback: file has no n8n_id; skipping (create is not the writeback\'s job)', [
				'app' => Application::APP_ID,
				'path' => $node->getPath(),
			]);
			return false;
		}
		$id = $managed->workflowId;

		$content = $node->getContent();

		try {
			$versionId = $this->pushViaApi($id, $content);
		} catch (N8nApiException $e) {
			// ALREADY THE RIGHT TYPE, SO RETHROW IT WHOLE. Re-wrapping it in a new
			// N8nApiException would keep the message and lose everything that makes
			// the message actionable: `httpStatus` would flatten to 0 (so a caller
			// can no longer tell a rejected key from a malformed body) and the
			// original would drop out of the chain along with its stack.
			throw $e;
		} catch (\Throwable $e) {
			// A LOCAL failure instead — an unparseable file, a JSON encode error.
			// Callers catch one type, so normalise to it, but keep the original as
			// `previous` rather than reducing it to a string.
			// Either way the synced hash is not stamped, so the next save retries.
			throw new N8nApiException($e->getMessage(), 0, $e);
		}

		// Stamp the synced hash so this exact content won't re-trigger a push,
		// plus the new versionId.
		$update = [WorkflowMetadata::KEY_SYNCED_HASH => sha1($content)];
		if ($versionId !== null && $versionId !== '') {
			$update[WorkflowMetadata::KEY_VERSION_ID] = $versionId;
		}
		$this->metadata->write($node->getId(), $update);
		return true;
	}

	/**
	 * REST `PUT /workflows/{id}`. n8n rejects unknown/read-only fields, so we send
	 * only the writable ones. Returns the new versionId if the response carries one.
	 */
	private function pushViaApi(string $id, string $content): ?string {
		// Decode as objects (not assoc): n8n's schema is strict about JSON
		// *types* — `connections`/`settings`/`staticData` must be objects, and an
		// empty `{}` round-tripped through an assoc array would re-encode as `[]`
		// and earn a `400 … must be object`. Keeping stdClass preserves the
		// original file's object-vs-array shape exactly.
		$wf = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
		if (!$wf instanceof \stdClass) {
			throw new \RuntimeException('file is not a JSON object');
		}
		// {@see N8nWorkflowBody::toUpdateBody} owns the writable-field whitelist,
		// the settings allowlist, and the `[]→{}` coercion (shared with create).
		$body = N8nWorkflowBody::toUpdateBody($wf);
		$resp = $this->n8n->updateWorkflow($id, $body);
		$v = $resp['versionId'] ?? null;
		return is_string($v) ? $v : null;
	}

}
