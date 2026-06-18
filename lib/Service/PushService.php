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
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Writeback dispatcher (Nextcloud → n8n). On save, a managed two-way file is
 * pushed to **every enabled channel** — the two are independent and composable,
 * not an either/or:
 *
 *  - REST API (`api_enabled`)     → `PUT /workflows/{id}` with the writable
 *                                   fields; returns the new `versionId`.
 *  - Webhook  (`webhook_enabled`) → POST the file to the configured webhook,
 *                                   authenticated with the webhook's own Bearer
 *                                   token. The receiving flow owns the routing.
 *
 * Both on = belt-and-suspenders (PUT then notify a flow). API off + webhook on =
 * the flow is the only writer. Neither on = the file is stored locally only
 * (a valid no-op, not an error).
 *
 * Errors are **not** swallowed: each channel's failure is collected and, if any
 * channel failed, re-thrown as an {@see N8nApiException} carrying n8n's own
 * message so the caller (save listener, async job, or bulk push) can surface it
 * as a user notification or an HTTP error. On full success we stamp the file's
 * `n8n_syncedHash` (loop guard) and the n8n `versionId`.
 *
 * Scope: **updates** of workflows we already track (have an `n8n_id`). Creating
 * a brand-new workflow from a hand-made file (UC-6) is a follow-up — such files
 * are skipped here with a log line.
 */
class PushService {
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private N8nClient $n8n,
		private WorkflowMetadata $metadata,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Push $node's contents to every enabled channel. Returns true when the file
	 * was handled (incl. the "no channels enabled" no-op), false when it isn't a
	 * pushable managed file. Throws {@see N8nApiException} if any enabled channel
	 * failed.
	 */
	public function push(Node $node): bool {
		if (!$node instanceof File) {
			return false;
		}
		$meta = $this->metadata->read($node->getId());
		$id = $meta[WorkflowMetadata::KEY_ID] ?? null;
		if (!is_string($id) || $id === '') {
			// No n8n id yet → a brand-new hand-made file. Creating it in n8n is
			// a future step (UC-6); skip for now.
			$this->logger->info('n8n_sync writeback: file has no n8n_id; new-workflow create not implemented', [
				'app' => Application::APP_ID,
				'path' => $node->getPath(),
			]);
			return false;
		}

		$apiOn = $this->appConfig->getValueBool(Application::APP_ID, 'api_enabled', true);
		$webhookOn = $this->appConfig->getValueBool(Application::APP_ID, 'webhook_enabled', false);

		$content = $node->getContent();
		$versionId = null;
		$errors = [];
		// Prefix channel names only when both are active, so a single-channel
		// failure keeps n8n's bare message (nicest in a toast).
		$both = $apiOn && $webhookOn;

		if ($apiOn) {
			try {
				$versionId = $this->pushViaApi($id, $content);
			} catch (\Throwable $e) {
				$errors[] = ($both ? 'API: ' : '') . $e->getMessage();
			}
		}
		if ($webhookOn) {
			try {
				$this->pushViaWebhook($node, $id, $content);
			} catch (\Throwable $e) {
				$errors[] = ($both ? 'Webhook: ' : '') . $e->getMessage();
			}
		}

		if ($errors !== []) {
			// Don't stamp the synced hash — so the next save retries.
			throw new N8nApiException(implode(' · ', $errors));
		}

		// Full success (incl. the no-channels no-op): stamp the synced hash so
		// this exact content won't re-trigger a push, plus the new versionId.
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
		$body = [];
		foreach (['name', 'nodes', 'connections', 'settings', 'staticData'] as $k) {
			if (isset($wf->$k)) {
				$body[$k] = $wf->$k;
			}
		}
		if ($body === []) {
			throw new \RuntimeException('no writable workflow fields (name/nodes/connections/settings) in file');
		}
		// n8n's `settings` schema is `additionalProperties: false` with a fixed
		// allowlist (see openapi/workflow.yml: WorkflowSettings). GETs sometimes
		// return extras (e.g. `callerPolicy`, `callerIds`, persisted internal
		// flags) that PUT then 400s with `must not have additional properties`.
		// Strip to the documented allowlist before sending.
		if (isset($body['settings']) && $body['settings'] instanceof \stdClass) {
			$allowed = [
				'saveExecutionProgress',
				'saveManualExecutions',
				'saveDataErrorExecution',
				'saveDataSuccessExecution',
				'executionTimeout',
				'errorWorkflow',
				'timezone',
				'executionOrder',
			];
			$filtered = new \stdClass();
			foreach ($allowed as $k) {
				if (isset($body['settings']->$k)) {
					$filtered->$k = $body['settings']->$k;
				}
			}
			$body['settings'] = $filtered;
		}
		// n8n's own GET serialises an empty `connections`/`settings`/`staticData`
		// as `[]`, but its PUT validator demands an object — so even a faithful
		// round-trip of a trivial workflow would 400. Coerce empty arrays back to
		// `{}` for these object-typed fields (nodes stays a list).
		foreach (['connections', 'settings', 'staticData'] as $k) {
			if (isset($body[$k]) && $body[$k] === []) {
				$body[$k] = new \stdClass();
			}
		}
		$resp = $this->n8n->updateWorkflow($id, $body);
		$v = $resp['versionId'] ?? null;
		return is_string($v) ? $v : null;
	}

	/**
	 * POST the file to the configured n8n webhook (Bearer). The receiving n8n
	 * workflow decides what to do; we don't get a versionId back.
	 */
	private function pushViaWebhook(Node $node, string $id, string $content): ?string {
		$path = (string)$this->config->getAppValue(Application::APP_ID, 'webhook_path', '');
		if ($path === '') {
			throw new \RuntimeException('Webhook writeback is enabled but no webhook path is configured.');
		}
		$wf = json_decode($content, true);
		$this->n8n->callWebhook($path, [
			'n8n_id' => $id,
			'path' => $node->getPath(),
			'content' => $wf,
		]);
		return null;
	}
}
