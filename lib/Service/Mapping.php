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
 * so there is no owner field. `nc_groups` are the user-facing groups the Team
 * Folder is shared with; the plugin additionally grants itself write access via
 * a dedicated actor group ({@see TeamFolderService::ACTOR_GROUP}).
 *
 * Per-workflow tags in n8n can override the mode at the file level (saga Ch2 §14
 * reserved tags); this object only carries the folder-level default.
 *
 * Mode model (saga Ch2 §14): a mapping's mode is exactly **`sync`** or **`link`**.
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
 *  - `ncGroups` MAY be empty here, but a mapping with no groups produces a Team
 *    Folder nobody can see — the pull reconciler warns + skips those.
 */
final class Mapping implements JsonSerializable {
	public const MODE_SYNC = 'sync';
	public const MODE_LINK = 'link';

	/**
	 * @param list<string> $ncGroups
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $n8nTag,
		public readonly string $teamFolder,
		public readonly array $ncGroups,
		public readonly string $mode,
		public readonly bool $useTeamFolder,
	) {
	}

	/**
	 * Validate + normalise a raw input array (from REST or stored JSON) into a
	 * Mapping. Throws InvalidArgumentException on any invariant violation so the
	 * controller returns a clean 400 rather than persisting nonsense.
	 *
	 * Reads legacy shapes (saga Ch2 §14 migration):
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

		$ncGroups = self::normaliseGroups($data['nc_groups'] ?? []);

		// Mode, with legacy normalisation. `writeback` is read only to be ignored
		// (the old sync+readonly "backup" collapses into sync); `reference` → link.
		$mode = (string)($data['mode'] ?? '');
		if ($mode === 'reference') {
			$mode = self::MODE_LINK;
		}

		// Storage backend (immutability enforced in MappingService::update).
		// Default true: groupfolders is the preferred path; legacy rows created
		// before this flag existed were all Team Folders.
		$useTeamFolder = !array_key_exists('use_team_folder', $data)
			|| filter_var($data['use_team_folder'], FILTER_VALIDATE_BOOLEAN);

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

		return new self($id, $n8nTag, $teamFolder, $ncGroups, $mode, $useTeamFolder);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'n8n_tag' => $this->n8nTag,
			'team_folder' => $this->teamFolder,
			'nc_groups' => $this->ncGroups,
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
	 * Team Folder mount point — a plain name, not a path. Strip surrounding
	 * slashes (legacy `nc_path` values looked like "/n8n") and whitespace.
	 */
	private static function normaliseFolder(string $value): string {
		return trim(trim($value), '/');
	}

	/**
	 * Group ids: a list of non-empty trimmed strings, de-duplicated, re-indexed.
	 *
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function normaliseGroups(mixed $value): array {
		if (is_string($value)) {
			// tolerate a comma-separated string from a form field
			$value = $value === '' ? [] : explode(',', $value);
		}
		if (!is_array($value)) {
			return [];
		}
		$out = [];
		foreach ($value as $g) {
			$g = trim((string)$g);
			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}
		return $out;
	}
}
