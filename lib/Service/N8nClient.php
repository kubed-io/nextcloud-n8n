<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Exception\N8nApiException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the n8n public REST API.
 *
 * One source of truth for:
 *  - reading + decrypting the stored API key,
 *  - resolving the configured base URL (trim trailing slash so callers don't have to),
 *  - issuing requests through Nextcloud's IClientService (so HTTP proxying,
 *    `allow_local_address`, and TLS settings stay consistent with the rest
 *    of the platform), and
 *  - normalising error shapes so callers get a small set of typed exceptions.
 *
 * Method surface intentionally small \u2014 only what Phase 3/4 will need:
 *
 *   ping()                       \u2014 cheapest call to verify URL+key (used by the
 *                                  Test connection button and as a self-check).
 *   listWorkflows($limit, $cursor) \u2014 paginated workflow listing for the bulk
 *                                  pull reconciler.
 *   getWorkflow($id)             \u2014 single workflow read for fetching the
 *                                  current JSON before write or for diffing.
 *   updateWorkflow($id, $body)   \u2014 PUT used by writeback (Fork B-1 / Phase 4).
 *
 * Anything richer (executions, credentials, tags) belongs in a separate
 * service or a later expansion of this one \u2014 we explicitly want this class
 * to stay small while the contract solidifies.
 */
final class N8nClient {
	/** Hard page cap so one paginated read is bounded (n8n maxes at 250/page; 250×20 ≫ realistic). */
	private const MAX_PAGES = 20;

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Cheapest reachable call \u2014 lists at most one workflow.
	 *
	 * Returns a friendly summary the Test connection button can render
	 * verbatim. Throws on auth/network failure so the caller decides how to
	 * present it.
	 *
	 * @return array{httpStatus:int, message:string}
	 */
	public function ping(): array {
		$res = $this->request('GET', '/api/v1/workflows', ['limit' => 1]);
		$code = $res->getStatusCode();
		return [
			'httpStatus' => $code,
			'message' => "Connected to n8n (HTTP $code).",
		];
	}

	/**
	 * Turn any failure from {@see ping()} into one friendly, user-facing line —
	 * shared by the Test connection button ({@see \OCA\N8nSync\Controller\ConfigController})
	 * and the `n8n_sync:test-connection` occ command, so both surfaces say the exact
	 * same thing. Crucially it tells the two failure classes apart:
	 *
	 *  - **Not set / misconfigured** — our own pre-formatted guards (missing URL or
	 *    key, decrypt failure, local-address refused) are plain `\RuntimeException`s;
	 *    their message is already user-ready, so pass it through. The "setup isn't
	 *    finished" case.
	 *  - **Rejected / unreachable** — a real HTTP failure arrives as an
	 *    {@see N8nApiException} carrying the status in its `httpStatus` property.
	 *    401/403 means the key was *set but rejected* — a different problem from
	 *    "not set", and the whole reason this method exists.
	 *
	 * Two load-bearing subtleties: N8nApiException is a `\RuntimeException` subclass,
	 * so catch order/`instanceof` must exclude it from the passthrough (the older
	 * `catch (RuntimeException)` in ConfigController swallowed the 401 mapping); and
	 * the status lives in `httpStatus`, not the Exception code (always 0).
	 */
	public static function describeConnectionError(\Throwable $e): string {
		if (!($e instanceof N8nApiException)) {
			return $e instanceof \RuntimeException
				? $e->getMessage()
				: 'Could not reach n8n: ' . $e->getMessage();
		}
		$code = $e->httpStatus;
		if ($code === 401 || $code === 403) {
			return "Authentication failed (HTTP $code) — n8n rejected the API key. Check it is valid, not expired, and has access.";
		}
		if ($code === 404) {
			return 'Reached the host but /api/v1 was not found — check the base URL.';
		}
		// httpStatus 0 is a genuine transport failure (no response). Any other code
		// means we DID reach n8n and it returned an error (e.g. 500) — say so with
		// the code rather than the misleading "could not reach".
		if ($code === 0) {
			return 'Could not reach n8n: ' . $e->getMessage();
		}
		return "n8n returned HTTP $code: " . $e->getMessage();
	}

	/**
	 * @param int<1,250>|null $limit n8n caps at 250; null = server default.
	 * @param string|null $cursor pagination cursor returned by previous call.
	 * @param list<string>|null $tags AND-filter on these n8n tag names. The n8n
	 *                                API joins them with commas and matches
	 *                                workflows that carry **all** of them
	 *                                (verified live against `?tags=a,b`).
	 *                                Tag names are passed verbatim, so they
	 *                                must not themselves contain commas \u2014
	 *                                Mapping::fromArray enforces that.
	 * @return array<string,mixed> raw decoded body \u2014 typically `{data: [\u2026], nextCursor: \u2026}`
	 */
	public function listWorkflows(?int $limit = null, ?string $cursor = null, ?array $tags = null): array {
		$query = [];
		if ($limit !== null) {
			$query['limit'] = $limit;
		}
		if ($cursor !== null && $cursor !== '') {
			$query['cursor'] = $cursor;
		}
		if ($tags !== null && $tags !== []) {
			$query['tags'] = implode(',', $tags);
		}
		$res = $this->request('GET', '/api/v1/workflows', $query);
		return $this->decode($res);
	}

	/** @return array<string,mixed> raw decoded workflow */
	public function getWorkflow(string $id): array {
		$res = $this->request('GET', '/api/v1/workflows/' . rawurlencode($id));
		return $this->decode($res);
	}

	/**
	 * Full-replace update of a workflow. n8n's API expects the same shape it
	 * returns from GET, so callers should round-trip rather than handcraft.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed> server response
	 */
	public function updateWorkflow(string $id, array $body): array {
		$res = $this->request(
			'PUT',
			'/api/v1/workflows/' . rawurlencode($id),
			[],
			$body,
		);
		return $this->decode($res);
	}

	/**
	 * Test the Webhook channel by POSTing a tiny payload to n8n's **test-event**
	 * path (`/webhook-test/...`), the URL n8n activates while "Listen for test
	 * event" is open in the editor. Mirrors {@see ping()} for the API.
	 *
	 * If the receiving flow isn't currently listening, n8n replies with its own
	 * "not registered" message — which {@see callWebhook} surfaces verbatim, so
	 * the admin still gets actionable feedback.
	 *
	 * @return array{httpStatus:int, message:string}
	 */
	public function pingWebhook(): array {
		$path = trim($this->config->getValueString(Application::APP_ID, 'webhook_path', ''));
		if ($path === '') {
			throw new \RuntimeException('Set the webhook path first.');
		}
		$this->callWebhook($this->toTestWebhookPath($path), [
			'n8n_sync' => 'test',
			'ts' => date('c'),
		]);
		return [
			'httpStatus' => 200,
			'message' => 'Webhook test event delivered. Check that your n8n flow received it.',
		];
	}

	/**
	 * Map a production webhook path to n8n's test-event variant:
	 * `/webhook/foo` → `/webhook-test/foo`. If the path doesn't follow n8n's
	 * `/webhook/` convention we can't infer the test URL, so we hit it as-is.
	 */
	private function toTestWebhookPath(string $path): string {
		$p = '/' . ltrim($path, '/');
		if (str_starts_with($p, '/webhook/')) {
			return '/webhook-test/' . substr($p, strlen('/webhook/'));
		}
		return $p;
	}

	/**
	 * Create a workflow. Used by the new-file flow (UC-6) and tests.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed> created workflow (incl. id)
	 */
	public function createWorkflow(array $body): array {
		return $this->decode($this->request('POST', '/api/v1/workflows', [], $body));
	}

	/** Delete a workflow (prune / tests). @return array<string,mixed> */
	public function deleteWorkflow(string $id): array {
		return $this->decode($this->request('DELETE', '/api/v1/workflows/' . rawurlencode($id)));
	}

	/**
	 * Soft-delete: archive a workflow. Mirrors NC's "move to trash" for the
	 * `sync + two-way` path (§17.7). Preserves nodes, connections, settings,
	 * tags, and versionId — the workflow is just marked `isArchived: true` and
	 * disappears from the default editor view. Reverse with {@see unarchiveWorkflow}.
	 *
	 * Verified live on the production instance: `POST /api/v1/workflows/{id}/archive`
	 * → 200, full body echoed back with `isArchived:true`.
	 *
	 * @return array<string,mixed>
	 */
	public function archiveWorkflow(string $id): array {
		return $this->decode($this->request('POST', '/api/v1/workflows/' . rawurlencode($id) . '/archive'));
	}

	/**
	 * Restore an archived workflow (mirror of NC trash-restore). Same id, same
	 * content, **tags preserved** — verified live: `POST /api/v1/workflows/{id}/unarchive`
	 * → 200 with `isArchived:false`.
	 *
	 * Note: only POST is accepted. `DELETE /archive` and `POST /restore` both 405.
	 *
	 * @return array<string,mixed>
	 */
	public function unarchiveWorkflow(string $id): array {
		return $this->decode($this->request('POST', '/api/v1/workflows/' . rawurlencode($id) . '/unarchive'));
	}

	/**
	 * List every tag in n8n. n8n paginates `/tags` like `/workflows`; we read all
	 * pages because the create-on-land flow needs an exact name → id lookup and
	 * the typical homelab tag set is tiny (tens, not thousands).
	 *
	 * @return list<array{id:string,name:string}> normalised, ignoring extra fields
	 */
	public function listTags(): array {
		$out = [];
		foreach ($this->paginate('/api/v1/tags') as $row) {
			if (isset($row['id'], $row['name'])) {
				$out[] = ['id' => (string)$row['id'], 'name' => (string)$row['name']];
			}
		}
		return $out;
	}

	/**
	 * Every workflow carrying **all** of $tags, across every page (the pull
	 * reconciler's source). Yields the raw decoded workflow rows that carry an `id`.
	 *
	 * @param list<string> $tags
	 * @return iterable<int, array<string,mixed>>
	 */
	public function eachWorkflow(array $tags): iterable {
		$query = $tags === [] ? [] : ['tags' => implode(',', $tags)];
		foreach ($this->paginate('/api/v1/workflows', $query) as $row) {
			if (isset($row['id'])) {
				yield $row;
			}
		}
	}

	/**
	 * Walk every page of a cursor-paginated n8n list endpoint (`/workflows`,
	 * `/tags`, …), yielding each element of `data` that decodes to an array.
	 * Manages `limit`/`cursor`; bounded by {@see MAX_PAGES} against a buggy
	 * self-referential cursor.
	 *
	 * @param array<string,mixed> $query extra query params (limit/cursor are added here)
	 * @return iterable<int, array<string,mixed>>
	 */
	private function paginate(string $path, array $query = []): iterable {
		$cursor = null;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$pageQuery = $query + ['limit' => 250];
			if ($cursor !== null) {
				$pageQuery['cursor'] = $cursor;
			}
			$batch = $this->decode($this->request('GET', $path, $pageQuery));
			foreach (($batch['data'] ?? []) as $row) {
				if (is_array($row)) {
					yield $row;
				}
			}
			$cursor = $batch['nextCursor'] ?? null;
			if (!is_string($cursor) || $cursor === '') {
				return;
			}
		}
		$this->logger->warning('n8n pagination hit MAX_PAGES guard', [
			'app' => Application::APP_ID,
			'path' => $path,
		]);
	}

	/**
	 * Idempotently look up the n8n tag id for $name; create the tag if it
	 * doesn't exist yet. Tag names are stored verbatim (case-sensitive) — the
	 * caller is expected to pass the same name a {@see Mapping} carries.
	 *
	 * Returns the n8n tag id (opaque string) — what {@see setWorkflowTags}
	 * accepts.
	 */
	public function ensureTag(string $name): string {
		foreach ($this->listTags() as $tag) {
			if ($tag['name'] === $name) {
				return $tag['id'];
			}
		}
		$created = $this->decode($this->request('POST', '/api/v1/tags', [], ['name' => $name]));
		$id = $created['id'] ?? null;
		if (!is_string($id) || $id === '') {
			throw new \RuntimeException('n8n create-tag did not return an id');
		}
		return $id;
	}

	/**
	 * Replace the tag set on a workflow with $tagIds (n8n's PUT semantics on
	 * `/workflows/{id}/tags` — additive callers must merge first via
	 * {@see getWorkflow}'s `tags` array). The body shape is `[{id: …}, …]`.
	 *
	 * @param list<string> $tagIds
	 * @return array<string,mixed> n8n's response (the new tag list)
	 */
	public function setWorkflowTags(string $workflowId, array $tagIds): array {
		$body = array_map(static fn (string $id) => ['id' => $id], $tagIds);
		return $this->decode($this->request(
			'PUT',
			'/api/v1/workflows/' . rawurlencode($workflowId) . '/tags',
			[],
			$body,
		));
	}

	/**
	 * POST a JSON body to the n8n webhook channel under the base URL. The webhook
	 * has its **own** Bearer secret (`webhook_token`), independent of the REST
	 * API key — set it and we send `Authorization: Bearer <token>`; leave it
	 * empty for an unauthenticated webhook. The receiving n8n workflow owns the
	 * routing/branching logic. `$path` is the webhook path, e.g. `/webhook/n8n-sync`.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed> decoded response (empty array if none)
	 */
	public function callWebhook(string $path, array $body): array {
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'n8n_url', ''), '/');
		if ($base === '') {
			throw new \RuntimeException('Set the n8n base URL first.');
		}

		$headers = [
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		];
		$enc = $this->config->getValueString(Application::APP_ID, 'webhook_token', '');
		if ($enc !== '') {
			try {
				$headers['Authorization'] = 'Bearer ' . $this->crypto->decrypt($enc);
			} catch (\Throwable) {
				throw new \RuntimeException('Stored webhook token could not be decrypted — re-enter it.');
			}
		}

		$client = $this->clientService->newClient();
		try {
			$res = $client->post($base . '/' . ltrim($path, '/'), [
				'headers' => $headers,
				'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
				'timeout' => 15,
				'nextcloud' => ['allow_local_address' => true],
			]);
		} catch (LocalServerException $e) {
			throw new \RuntimeException('Refused to connect to a local address.', 0, $e);
		} catch (\Throwable $e) {
			throw $this->toApiException($e);
		}
		return $this->decode($res);
	}

	/**
	 * Single chokepoint for every HTTP call. Reads + decrypts the API key,
	 * applies the standard headers, and sets `allow_local_address` so the
	 * homelab's in-cluster URLs work the same way as public ones. This opts out
	 * of NC's default SSRF guard on purpose — see SECURITY.md "Network egress and
	 * local addresses" for the trade-off (admin-trust boundary, single n8n target).
	 *
	 * Throws \RuntimeException with a friendly message for the cases we know
	 * how to label (no URL, no key, decrypt fail, local-address blocked) and
	 * lets transport errors bubble up otherwise so the caller can format them.
	 *
	 * @param array<string,mixed> $query
	 * @param array<string,mixed>|list<array<string,mixed>>|null $jsonBody
	 */
	private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): IResponse {
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'n8n_url', ''), '/');
		$enc = $this->config->getValueString(Application::APP_ID, 'api_key', '');
		if ($base === '') {
			throw new \RuntimeException('Set the n8n base URL first.');
		}
		if ($enc === '') {
			throw new \RuntimeException('No n8n API key is set — add one first.');
		}
		try {
			$key = $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			throw new \RuntimeException('Stored key could not be decrypted \u2014 re-enter it.');
		}

		$url = $base . $path;
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		$opts = [
			'headers' => [
				'X-N8N-API-KEY' => $key,
				'Accept' => 'application/json',
			],
			'timeout' => 10,
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($jsonBody !== null) {
			$opts['headers']['Content-Type'] = 'application/json';
			$opts['body'] = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}

		$client = $this->clientService->newClient();
		try {
			return $this->dispatch($client, $method, $url, $opts);
		} catch (LocalServerException $e) {
			throw new \RuntimeException('Refused to connect to a local address.', 0, $e);
		} catch (\Throwable $e) {
			throw $this->toApiException($e);
		}
	}

	/**
	 * Turn a transport/HTTP exception into an {@see N8nApiException} carrying
	 * n8n's own error text. n8n responds to bad workflow JSON with
	 * `{"message": "request/body/connections must be object", "hint"?: "…"}`;
	 * we surface exactly that so it can be shown to the user. We duck-type the
	 * Guzzle exception (`getResponse()` → PSR-7 response) rather than import it,
	 * so we don't couple to a specific HTTP-client bundle.
	 */
	private function toApiException(\Throwable $e): N8nApiException {
		if (is_callable([$e, 'getResponse'])) {
			$res = $e->getResponse();
			if ($res instanceof \Psr\Http\Message\ResponseInterface) {
				$status = $res->getStatusCode();
				$raw = (string)$res->getBody();
				$msg = '';
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
					if (is_string($decoded['hint'] ?? null) && $decoded['hint'] !== '') {
						$msg = trim($msg . ' — ' . $decoded['hint']);
					}
				}
				if ($msg === '') {
					$msg = $raw !== '' ? mb_substr($raw, 0, 500) : ('HTTP ' . $status);
				}
				return new N8nApiException($msg, $status, $e);
			}
		}
		return new N8nApiException($e->getMessage(), 0, $e);
	}

	private function dispatch(IClient $client, string $method, string $url, array $opts): IResponse {
		switch (strtoupper($method)) {
			case 'GET':    return $client->get($url, $opts);
			case 'PUT':    return $client->put($url, $opts);
			case 'POST':   return $client->post($url, $opts);
			case 'DELETE': return $client->delete($url, $opts);
			default:
				throw new \LogicException('Unsupported HTTP method: ' . $method);
		}
	}

	/** @return array<string,mixed> */
	private function decode(IResponse $res): array {
		$body = (string)$res->getBody();
		if ($body === '') {
			return [];
		}
		try {
			$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('n8n response was not valid JSON', ['exception' => $e]);
			throw new \RuntimeException('n8n response was not valid JSON.', 0, $e);
		}
		if (!is_array($data)) {
			throw new \RuntimeException('n8n response was not a JSON object/array.');
		}
		return $data;
	}
}
