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
	private const OBJECT_FIELDS = ['connections', 'settings', 'staticData', 'pinData'];

	/**
	 * The same coercion, one level down, inside every entry of `nodes`.
	 *
	 * THIS IS THE ONE THAT WAS MISSING, and it cost the whole copy feature. n8n
	 * answered `request/body/nodes/0/parameters must be object` for any workflow with a
	 * node whose parameters are empty — which is most first drafts — so copying a REAL
	 * workflow into a mapped folder left an untracked `.n8n` and a warning in the log.
	 * The top-level list above was written from a trivial workflow and stops at the
	 * fields such a workflow has.
	 */
	private const NODE_OBJECT_FIELDS = ['parameters', 'credentials'];

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
	 * Full workflow JSON for `sync` mode — so a later writeback is a simple PUT of the
	 * file contents.
	 *
	 * ## "VERBATIM" WAS THE CLAIM AND IT WAS NOT TRUE
	 *
	 * This used to hand the decoded workflow straight to `json_encode`, which wrote
	 * `"parameters": []` for every empty object n8n had sent as `{}` — the loss happens
	 * in {@see N8nClient::decode}, long before here, and nothing put it back. The mirror
	 * was therefore NOT a faithful copy, and the moment anything sent it back to n8n
	 * (a copy, a save) the validator rejected it.
	 *
	 * So the same coercion the outbound bodies get is applied here. A file written by
	 * an older version differs from its workflow by exactly these empty objects, so the
	 * first pull after this ships rewrites it once and is stable after that.
	 *
	 * @param array<string,mixed> $workflow
	 */
	public static function encodeSync(array $workflow): string {
		self::coerceEmptyObjects($workflow);
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

	/**
	 * Coerce empty `[]` to `{}` everywhere n8n's schema says object — `nodes` itself
	 * stays a list, and so does everything under `connections`' `main` arrays.
	 *
	 * ## WHY THIS EXISTS AT ALL, AND WHY IT HAS TO BE A LIST OF POSITIONS
	 *
	 * PHP cannot tell `{}` from `[]` once JSON has been decoded to an associative
	 * array: both are the empty array. {@see N8nClient::decode} decodes that way and
	 * everything downstream reads arrays, so by the time a workflow reaches disk every
	 * empty object in it has already become an empty list. n8n's validator is strict
	 * about the difference, so the shape has to be put back by NAMING the positions
	 * that are objects. A blanket "every empty array becomes an object" would be
	 * wrong in the other direction and would corrupt genuinely empty lists.
	 *
	 * Applied on the way OUT to n8n and on the way IN to the file, so a mirror is a
	 * faithful copy rather than something that only happens to work until it is sent
	 * back.
	 *
	 * @param array<string,mixed>|\stdClass $body
	 */
	private static function coerceEmptyObjects(array|\stdClass &$body): void {
		foreach (self::OBJECT_FIELDS as $k) {
			if (self::get($body, $k) === []) {
				self::set($body, $k, new \stdClass());
			}
		}

		// One level into `connections`: the map is keyed by node name and each value is
		// itself an object of output-name → connection lists. A node wired to nothing
		// has an empty one.
		$connections = self::get($body, 'connections');
		if (is_array($connections)) {
			foreach ($connections as $node => $outputs) {
				if ($outputs === []) {
					$connections[$node] = new \stdClass();
				}
			}
			self::set($body, 'connections', $connections);
		}

		$nodes = self::get($body, 'nodes');
		if (!is_array($nodes)) {
			return;
		}
		foreach ($nodes as $i => $node) {
			if (!is_array($node) && !$node instanceof \stdClass) {
				continue;
			}
			foreach (self::NODE_OBJECT_FIELDS as $k) {
				if (self::get($node, $k) === []) {
					self::set($node, $k, new \stdClass());
				}
			}
			$nodes[$i] = $node;
		}
		self::set($body, 'nodes', $nodes);
	}

	/**
	 * Read one key off either JSON shape.
	 *
	 * BOTH SHAPES REACH HERE, which is the whole reason these two helpers exist. A
	 * create/update body is built from `json_decode($file, false)` — objects, so nodes
	 * are `stdClass`. A file body is built from {@see N8nClient::decode}'s associative
	 * arrays. One coercion has to serve both or the two paths drift, and drifting is
	 * exactly what let the send side be repaired while the file kept its `[]`.
	 *
	 * @param array<string,mixed>|\stdClass $subject
	 */
	private static function get(array|\stdClass $subject, string $key): mixed {
		return is_array($subject) ? ($subject[$key] ?? null) : ($subject->$key ?? null);
	}

	/** @param array<string,mixed>|\stdClass $subject */
	private static function set(array|\stdClass &$subject, string $key, mixed $value): void {
		if (is_array($subject)) {
			$subject[$key] = $value;
		} else {
			$subject->$key = $value;
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
