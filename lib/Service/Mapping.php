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
 * default sync semantics for files under it.
 *
 * Ownership model (plan §12.4, decided H-B): Team Folders are owned by no user,
 * so there is no owner field. `nc_groups` are the user-facing groups the Team
 * Folder is shared with; the plugin additionally grants itself write access via
 * a dedicated actor group ({@see TeamFolderService::ACTOR_GROUP}).
 *
 * Per-workflow tags in n8n can still override mode at the file level (Phase 2
 * metadata layer); this object only carries the folder-level default.
 *
 * Invariants:
 *  - `mode === 'reference'` → `writeback` MUST be null (reference is read-only by nature).
 *  - `mode === 'sync'`      → `writeback` MUST be 'two-way' or 'readonly'.
 *  - `n8nTag` MUST NOT contain commas (n8n uses comma as the multi-tag delimiter).
 *  - `teamFolder` MUST be non-empty.
 *  - `ncGroups` MAY be empty here, but a mapping with no groups produces a Team
 *    Folder nobody can see — the pull reconciler warns + skips those.
 *
 * Mode value note: 'link' is forbidden as a metadata value (it is `is_callable()`
 * and detonates PROPFIND through core's FilesPlugin); the read-only mode is
 * 'reference'. Legacy rows with `mode: 'link'`, or with the old `n8n_path` /
 * `nc_path` keys, are auto-upgraded by {@see fromArray()} and re-persisted once
 * by MappingService on first read.
 */
final class Mapping implements JsonSerializable {
	public const MODE_REFERENCE = 'reference';
	public const MODE_SYNC = 'sync';

	public const WRITEBACK_TWO_WAY = 'two-way';
	public const WRITEBACK_READONLY = 'readonly';

	/**
	 * @param list<string> $ncGroups
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $n8nTag,
		public readonly string $teamFolder,
		public readonly array $ncGroups,
		public readonly string $mode,
		public readonly ?string $writeback,
		public readonly bool $useTeamFolder,
	) {
	}

	/**
	 * Validate + normalise a raw input array (from REST or stored JSON) into a
	 * Mapping. Throws InvalidArgumentException on any invariant violation so the
	 * controller returns a clean 400 rather than persisting nonsense.
	 *
	 * Accepts legacy keys (`n8n_path` for the tag, `nc_path` for the folder) so
	 * the one-shot migration in MappingService::list() needn't know field shapes.
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

		$mode = (string)($data['mode'] ?? '');
		if ($mode === 'link') {
			$mode = self::MODE_REFERENCE; // legacy rename
		}
		$writeback = $data['writeback'] ?? null;
		if ($writeback === '') {
			$writeback = null;
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
		if (!in_array($mode, [self::MODE_REFERENCE, self::MODE_SYNC], true)) {
			throw new \InvalidArgumentException('mode must be "reference" or "sync"');
		}
		if ($mode === self::MODE_REFERENCE && $writeback !== null) {
			throw new \InvalidArgumentException('writeback is not valid when mode=reference');
		}
		if ($mode === self::MODE_SYNC
			&& !in_array($writeback, [self::WRITEBACK_TWO_WAY, self::WRITEBACK_READONLY], true)) {
			throw new \InvalidArgumentException('writeback must be "two-way" or "readonly" when mode=sync');
		}

		return new self($id, $n8nTag, $teamFolder, $ncGroups, $mode, $writeback === null ? null : (string)$writeback, $useTeamFolder);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'n8n_tag' => $this->n8nTag,
			'team_folder' => $this->teamFolder,
			'nc_groups' => $this->ncGroups,
			'mode' => $this->mode,
			'writeback' => $this->writeback,
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
