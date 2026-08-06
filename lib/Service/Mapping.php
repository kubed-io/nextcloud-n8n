<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use JsonSerializable;

/**
 * Folder-mapping value object.
 *
 * Each Mapping binds an **n8n tag** (the only stable subdivision n8n's public
 * REST API exposes — there is no folder API; see plan §12.2) to a **Team Folder**
 * (groupfolders mount point), shared with a set of **Nextcloud groups**, plus the
 * default mode for files under it.
 *
 * Ownership model (plan §12.4, decided H-B): Team Folders are owned by no user,
 * so there is no owner field. The plugin grants itself write access via a
 * dedicated actor group ({@see TeamFolderService::ACTOR_GROUP}).
 *
 * ## WHAT IS NOT ON THIS OBJECT: GROUPS
 *
 * Which groups the mapped folder is shared with is a property OF THE FOLDER, and
 * Nextcloud already stores it — as groupfolders assignments, or as group shares.
 * Copying it here would create a second answer to the same question, and the two
 * disagree the moment an admin re-shares the folder from the Files app or `occ`,
 * which they are entitled to do.
 *
 * That is not a hypothetical tidy-up. Three apps in this family can map to the
 * SAME folder, and while each stored its own list every sync stamped its list
 * over the others' — so n8n, Grafana and Penpot fought for control of one folder
 * forever, and none of them was wrong. Sourcing the groups from the folder makes
 * the folder the single answer, so all three (and the Files UI, and `occ`) can
 * edit the same sharing without contending.
 *
 * Groups are therefore read on demand ({@see StorageService::groupsOf()}) and
 * written straight through ({@see MappingService::updateGroups()}).
 *
 * A mapping's mode is authoritative for every workflow it pulls — there is **no**
 * per-workflow or per-file `sync`↔`link` override (that toggle was removed in saga
 * §15.3). The only per-workflow exception is the `n8n:ignore` exclude tag, read at
 * pull time (saga §14.8); this object carries the folder-level mode.
 *
 * Mode model (saga Ch3 §14): a mapping's mode is exactly **`sync`** or **`link`**.
 * `writeback` is gone (the old `sync + two-way` is now just `sync`); `backup`
 * (old `sync + readonly`) is dropped and migrates to `sync`; the old `reference`
 * is renamed to `link`. {@see fromArray()} reads all of those legacy shapes and
 * MappingService re-persists the cleaned list on first read.
 *
 * (The on-the-wire DAV metadata value for `link` is `reference` — the literal
 * string `link` is `is_callable()` and crashes core PROPFIND — but that
 * translation lives in {@see WorkflowMetadata}, not here; a Mapping says `link`.)
 *
 * Invariants:
 *  - `mode` MUST be `sync` or `link`.
 *  - `n8nTag` MUST NOT contain commas (n8n uses comma as the multi-tag delimiter).
 *  - `teamFolder` MUST be non-empty.
 */
final class Mapping implements JsonSerializable {
	public const MODE_SYNC = 'sync';
	public const MODE_LINK = 'link';

	public function __construct(
		public readonly string $id,
		public readonly string $n8nTag,
		public readonly string $teamFolder,
		public readonly string $mode,
		public readonly bool $useTeamFolder,
	) {
	}

	/**
	 * Validate + normalise a raw input array (from REST or stored JSON) into a
	 * Mapping. Throws InvalidArgumentException on any invariant violation so the
	 * controller returns a clean 400 rather than persisting nonsense.
	 *
	 * Reads legacy shapes (saga Ch3 §14 migration):
	 *  - keys `n8n_path` (tag) / `nc_path` (folder) → `n8n_tag` / `team_folder`;
	 *  - `mode: 'reference'` → `link`;
	 *  - `mode: 'sync'` with any (now-ignored) `writeback`, incl. the old `backup`
	 *    = `sync + readonly` → `sync`.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self {
		$id = isset($data['id']) && is_string($data['id']) && $data['id'] !== ''
			? $data['id']
			: self::newId();

		$rawTag = $data['n8n_tag'] ?? $data['n8n_path'] ?? '';
		$n8nTag = self::normaliseTag((string)$rawTag);

		$rawFolder = $data['team_folder'] ?? $data['nc_path'] ?? '';
		$teamFolder = self::normaliseFolder((string)$rawFolder);

		// Mode, with legacy normalisation. `writeback` is read only to be ignored
		// (the old sync+readonly "backup" collapses into sync); `reference` → link.
		//
		// DEFAULTS TO `link`, WHICH IT DID NOT USED TO. An omitted mode was a hard
		// refusal, so the shortest useful add-mapping — a tag and a folder — could
		// not be written at all, and every caller had to name a mode it had no
		// opinion about. `link` is the conservative choice: it downloads nothing
		// and mirrors nothing back, so a mapping made without thinking about mode
		// cannot cost anything. Individual files are promoted afterwards.
		//
		// Matches the Penpot sibling, which has always defaulted this way. The gap
		// here and in nextcloud-grafana was found by writing the admin-mapping
		// spec's defaults table and having no value to put in the `mode` row.
		$mode = (string)($data['mode'] ?? self::MODE_LINK);
		if ($mode === 'reference') {
			$mode = self::MODE_LINK;
		}

		// Storage backend. DEFAULT FALSE — an omitted flag means an admin-owned
		// folder, because that is the only backend guaranteed to exist.
		//
		// A Team Folder needs the groupfolders app, which is OPTIONAL and absent on
		// a stock Nextcloud. Defaulting to it meant the default mapping was the one
		// that could not be provisioned: `StorageService::isAvailable()` returns
		// `teamFolders->isAvailable()` for a Team Folder, so an admin who filled in
		// a tag and a folder and touched nothing else got a refusal on a plain
		// install. A default must be the safe choice, not the preferred one.
		//
		// This matches the sibling penpot app, which has always defaulted to false.
		//
		// NOTE FOR OLD DATA: `toArray()` always writes the key, so every mapping
		// this app has ever saved carries it explicitly and is unaffected. Only a
		// row persisted before the flag existed at all would read differently — and
		// those were Team Folders. At 0.1.x that is a re-map, not a migration.
		$useTeamFolder = array_key_exists('use_team_folder', $data)
			&& filter_var($data['use_team_folder'], FILTER_VALIDATE_BOOLEAN);

		if ($n8nTag === '') {
			throw new \InvalidArgumentException('n8n_tag is required');
		}
		if (str_contains($n8nTag, ',')) {
			throw new \InvalidArgumentException('n8n_tag must not contain commas');
		}
		if ($teamFolder === '') {
			throw new \InvalidArgumentException('team_folder is required');
		}
		if (!in_array($mode, [self::MODE_SYNC, self::MODE_LINK], true)) {
			throw new \InvalidArgumentException('mode must be "sync" or "link"');
		}

		return new self($id, $n8nTag, $teamFolder, $mode, $useTeamFolder);
	}

	/**
	 * The STORED shape — what goes into appconfig, and nothing else.
	 *
	 * Deliberately NOT the shape the admin page or `list-mappings` renders: those
	 * add the folder's current groups, which are read live rather than stored
	 * ({@see MappingService::describe()}).
	 *
	 * @return array{id: string, n8n_tag: string, team_folder: string, mode: string, use_team_folder: bool}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'n8n_tag' => $this->n8nTag,
			'team_folder' => $this->teamFolder,
			'mode' => $this->mode,
			'use_team_folder' => $this->useTeamFolder,
		];
	}

	#[\Override]
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/** Tiny opaque id, unique within the mappings list. */
	public static function newId(): string {
		return bin2hex(random_bytes(8));
	}

	/**
	 * Normalise an n8n tag name. Stored verbatim (case-sensitive). Legacy: a
	 * slash-prefixed value from the old `n8n_path` field is stripped and adopts
	 * the recommended `nextcloud:` namespace.
	 */
	private static function normaliseTag(string $value): string {
		$v = trim($value);
		if ($v === '' || $v === '/') {
			return '';
		}
		if ($v[0] === '/') {
			$stripped = ltrim($v, '/');
			return $stripped === '' ? '' : 'nextcloud:' . $stripped;
		}
		return $v;
	}

	/**
	 * Team Folder mount point — usually a plain name (`flows`), but a mapping may
	 * also sit on a **nested** folder (`flows/archived`) since mappings are
	 * per-folder metadata and the resolver picks the nearest enclosing one. Strip
	 * surrounding slashes (legacy `nc_path` values looked like "/n8n"), collapse
	 * any duplicate separators, and drop whitespace so the stored value is a clean
	 * relative path the resolver can prefix-match.
	 */
	private static function normaliseFolder(string $value): string {
		$v = trim($value);
		$v = preg_replace('#/+#', '/', $v) ?? $v;
		return trim($v, '/');
	}
}
