<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Support;

use GuzzleHttp\Client;
use PHPUnit\Framework\Assert;

/**
 * n8n REST transport (Guzzle, X-N8N-API-KEY): the assertion side — did the app
 * actually create / tag / archive / delete the workflow in n8n? Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}; reads the shared `$n8n`
 * client + `$n8nUrl` / `$n8nApiKey`.
 */
trait N8nApiTrait {
	private function n8nClient(): Client {
		if ($this->n8n === null) {
			Assert::assertNotSame('', $this->n8nApiKey, 'N8N_API_KEY is not set — n8n assertions need it');
			$this->n8n = new Client([
				'base_uri' => $this->n8nUrl . '/api/v1/',
				'headers' => ['X-N8N-API-KEY' => $this->n8nApiKey, 'Accept' => 'application/json'],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->n8n;
	}

	/** GET an n8n workflow by id; returns the decoded body or null on 404. */
	private function n8nGetWorkflow(string $id): ?array {
		$res = $this->n8nClient()->request('GET', 'workflows/' . rawurlencode($id));
		if ($res->getStatusCode() === 404) {
			return null;
		}
		Assert::assertSame(200, $res->getStatusCode(), "GET n8n workflow $id failed: " . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * PUT an n8n workflow body — the n8n-side edit an `@in-n8n` scenario arranges.
	 * Only the writable fields are accepted (the schema is `additionalProperties:
	 * false` and `tags` is `readOnly`, saga §5.6.3), so callers pass exactly those:
	 * `name`, `nodes`, `connections`, `settings`, `staticData`.
	 *
	 * @param array<string,mixed> $body
	 */
	private function n8nUpdateWorkflow(string $id, array $body): void {
		$res = $this->n8nClient()->request('PUT', 'workflows/' . rawurlencode($id), [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode($body, JSON_THROW_ON_ERROR),
		]);
		Assert::assertContains(
			$res->getStatusCode(),
			[200, 201],
			"PUT n8n workflow $id failed: " . (string)$res->getBody(),
		);
	}

	/** Hard-delete an n8n workflow by id. 204/200 = gone; 404 = already gone. */
	private function n8nDeleteWorkflow(string $id): void {
		$res = $this->n8nClient()->request('DELETE', 'workflows/' . rawurlencode($id));
		Assert::assertContains(
			$res->getStatusCode(),
			[200, 204, 404],
			"DELETE n8n workflow $id failed: " . (string)$res->getBody(),
		);
	}

	/** Bring an archived workflow back to life (POST /workflows/{id}/unarchive). */
	private function n8nUnarchiveWorkflow(string $id): void {
		$res = $this->n8nClient()->request('POST', 'workflows/' . rawurlencode($id) . '/unarchive');
		Assert::assertContains(
			$res->getStatusCode(),
			[200, 204],
			"unarchive n8n workflow $id failed: " . (string)$res->getBody(),
		);
	}
}
