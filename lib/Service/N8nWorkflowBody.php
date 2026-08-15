<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Single source of truth for every shape an n8n workflow takes on the wire and
 * on disk. Pure logic (no DI), so it is trivially unit-testable.
 *
 * Before this class the same n8n-schema quirks lived in four places: the create
 * body in {@see CreateService}, the update body in {@see PushService}, and a
 * verbatim-duplicated `encodeReference`/`encodeSync` pair in both
 * {@see SyncService} and {@see PushService}. n8n's request schema is an
 * external, moving target; keeping it here means it changes in exactly one file.
 *
 * The schema gotchas, learned live:
 *  - **Writable-field whitelist.** n8n rejects unknown/read-only fields, so only
 *    `name`/`nodes`/`connections`/`settings`/`staticData` are ever sent.
 *  - **Settings allowlist.** n8n's `WorkflowSettings` is `additionalProperties:
 *    false`; a GET may return extras (`callerPolicy`, …) that a PUT then 400s on.
 *  - **`[]→{}` coercion.** n8n serialises empty `connections`/`settings`/
 *    `staticData` as `[]`, but its validator demands an object — so a faithful
 *    round-trip of a trivial workflow would 400 without this.
 */
final class N8nWorkflowBody {
	/** Fields n8n accepts on create/update; everything else is read-only/rejected. */
	private const WRITABLE = ['name', 'nodes', 'connections', 'settings', 'staticData'];

	/** Object-typed fields whose empty `[]` must be coerced to `{}` for n8n's validator. */
	private const OBJECT_FIELDS = ['connections', 'settings', 'staticData'];

	/**
	 * n8n's `WorkflowSettings` schema is `additionalProperties: false` with this
	 * fixed allowlist; any extra key 400s the request.
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

	/** Encode flags for a human-readable file body (pretty, UTF-8 + slashes verbatim). */
	public const JSON_PRETTY = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Build the POST `/workflows` body from a file's decoded JSON. Defaults match
	 * the frontend's `STARTER_WORKFLOW`: empty `nodes`/`connections`/`settings`.
	 * Name authority: the JSON `name` if non-empty, else the file stem, else
	 * "Untitled".
	 *
	 * @return array<string,mixed>
	 */
	public static function toCreateBody(\stdClass $wf, string $basename): array {
		$body = self::pickWritable($wf);

		$jsonName = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
		if ($jsonName === '') {
			$body['name'] = self::stemFromBasename($basename);
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

		self::filterSettings($body);
		self::coerceEmptyObjects($body);
		return $body;
	}

	/**
	 * Build the PUT `/workflows/{id}` body from a file's decoded JSON. Throws when
	 * the file carries none of the writable fields (nothing to update).
	 *
	 * @return array<string,mixed>
	 */
	public static function toUpdateBody(\stdClass $wf): array {
		$body = self::pickWritable($wf);
		if ($body === []) {
			throw new \RuntimeException('no writable workflow fields (name/nodes/connections/settings) in file');
		}
		self::filterSettings($body);
		self::coerceEmptyObjects($body);
		return $body;
	}

	/**
	 * Tiny pointer body for `link` mode — id, name, deep-link URL, tag names.
	 * `$baseUrl` is the configured n8n base URL (trailing slash tolerated); an
	 * empty base yields a null `url`.
	 *
	 * @param array<string,mixed> $workflow
	 */
	public static function encodeReference(array $workflow, string $baseUrl): string {
		$id = (string)($workflow['id'] ?? '');
		$base = rtrim($baseUrl, '/');
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
		return json_encode($payload, self::JSON_PRETTY);
	}

	/**
	 * Full workflow JSON for `sync` mode, verbatim — so a later writeback is a
	 * simple PUT of the file contents.
	 *
	 * @param array<string,mixed> $workflow
	 */
	public static function encodeSync(array $workflow): string {
		return json_encode($workflow, self::JSON_PRETTY);
	}

	/**
	 * Pull the writable fields out of a decoded workflow object.
	 *
	 * @return array<string,mixed>
	 */
	private static function pickWritable(\stdClass $wf): array {
		$body = [];
		foreach (self::WRITABLE as $k) {
			if (isset($wf->$k)) {
				$body[$k] = $wf->$k;
			}
		}
		return $body;
	}

	/** Strip `settings` down to the documented allowlist (drops what n8n would 400 on). */
	private static function filterSettings(array &$body): void {
		if (!isset($body['settings']) || !$body['settings'] instanceof \stdClass) {
			return;
		}
		$filtered = new \stdClass();
		foreach (self::SETTINGS_ALLOWED as $k) {
			if (isset($body['settings']->$k)) {
				$filtered->$k = $body['settings']->$k;
			}
		}
		$body['settings'] = $filtered;
	}

	/** Coerce empty `[]` to `{}` for the object-typed fields (nodes stays a list). */
	private static function coerceEmptyObjects(array &$body): void {
		foreach (self::OBJECT_FIELDS as $k) {
			if (isset($body[$k]) && $body[$k] === []) {
				$body[$k] = new \stdClass();
			}
		}
	}

	/**
	 * Derive a clean workflow name from the on-disk filename: strip `.n8n`
	 * and any trailing " (N)" collision suffix; fall back to "Untitled".
	 */
	private static function stemFromBasename(string $basename): string {
		$parsed = FilenameCodec::parse($basename);
		$name = $parsed !== null ? trim($parsed['name']) : '';
		return $name !== '' ? $name : 'Untitled';
	}
}
