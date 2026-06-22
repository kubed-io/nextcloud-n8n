<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Storage + CRUD for the folder-mapping list.
 *
 * Backed by a single AppConfig key (`mappings`) holding a JSON array — keeps all
 * mappings in one round-trip read and makes occ/helm parity trivial
 * (`occ config:app:set n8n_sync mappings '[...json...]'`).
 *
 * Each mapping binds an n8n tag to a Team Folder shared with NC groups; see
 * {@see Mapping}. Legacy rows (old `n8n_path`/`nc_path` keys, `mode: 'link'`)
 * are migrated transparently on read and re-persisted once.
 */
final class MappingService {
	public function __construct(
		private IAppConfig $config,
	) {
	}

	/** @return list<Mapping> */
	public function list(): array {
		$raw = $this->config->getValueString(Application::APP_ID, 'mappings', '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$result = [];
		$legacySeen = false;
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			// One-shot migration markers (saga Ch2 §14): the old folder-mapping
			// shape used `n8n_path` + `nc_path`; the old link mode was `reference`;
			// and every old row carried a `writeback` field that no longer exists.
			// Mapping::fromArray rewrites all of these — we just remember whether we
			// saw any so we can re-persist the cleaned list once.
			if (array_key_exists('n8n_path', $entry) && !array_key_exists('n8n_tag', $entry)) {
				$legacySeen = true;
			}
			if (array_key_exists('nc_path', $entry) && !array_key_exists('team_folder', $entry)) {
				$legacySeen = true;
			}
			if (($entry['mode'] ?? null) === 'reference' || array_key_exists('writeback', $entry)) {
				$legacySeen = true;
			}
			try {
				$result[] = Mapping::fromArray($entry);
			} catch (\InvalidArgumentException) {
				// Skip malformed rows rather than break the admin page — they
				// just disappear on next save.
				continue;
			}
		}
		if ($legacySeen && $result !== []) {
			$this->persist($result);
		}
		return $result;
	}

	/** Look up a single mapping by its stable id (used to resolve a file's `n8n_mapping`). */
	public function getById(string $id): ?Mapping {
		foreach ($this->list() as $m) {
			if ($m->id === $id) {
				return $m;
			}
		}
		return null;
	}

	public function add(Mapping $mapping): Mapping {
		$all = $this->list();
		$this->assertTagUnique($all, $mapping->n8nTag, null);
		$all[] = $mapping;
		$this->persist($all);
		return $mapping;
	}

	public function update(string $id, Mapping $mapping): Mapping {
		$all = $this->list();
		$this->assertTagUnique($all, $mapping->n8nTag, $id);
		$updated = null;
		foreach ($all as $i => $existing) {
			if ($existing->id === $id) {
				// The storage backend is immutable (spec §14.1): switching moves
				// bytes between stores. Reject a change; the user must remove +
				// re-add (the purge option exists for exactly this).
				if ($existing->useTeamFolder !== $mapping->useTeamFolder) {
					throw new \InvalidArgumentException(
						'The storage backend (Team Folder vs admin-owned) cannot be changed after a mapping '
						. 'is created. Remove the mapping (optionally purging its files) and add it again.',
					);
				}
				// Preserve the original id + backend even if the caller sent different ones.
				$updated = new Mapping(
					$id,
					$mapping->n8nTag,
					$mapping->teamFolder,
					$mapping->ncGroups,
					$mapping->mode,
					$existing->useTeamFolder,
				);
				$all[$i] = $updated;
				break;
			}
		}
		if ($updated === null) {
			throw new \OutOfBoundsException('mapping not found');
		}
		$this->persist($all);
		return $updated;
	}

	public function delete(string $id): void {
		$all = $this->list();
		$filtered = array_values(array_filter($all, fn (Mapping $m) => $m->id !== $id));
		if (count($filtered) === count($all)) {
			throw new \OutOfBoundsException('mapping not found');
		}
		$this->persist($filtered);
	}

	/**
	 * Given a Nextcloud node path, return the mapping whose Team Folder is the
	 * mounted top-level folder, or null. NC node paths look like
	 * `/<uid>/files/<teamFolder>/...`; we match on the segment after `files/`.
	 *
	 * Used by the writeback listener (Phase 4) to decide if a saved file belongs
	 * to a mapping. Kept in the service so the bulk-sync reconciler can reuse it.
	 */
	public function resolveForPath(string $ncPath): ?Mapping {
		if (!preg_match('#/files/([^/]+)#', $ncPath, $m)) {
			return null;
		}
		$folder = $m[1];
		foreach ($this->list() as $mapping) {
			if ($mapping->teamFolder === $folder) {
				return $mapping;
			}
		}
		return null;
	}

	/**
	 * Enforce one-folder-per-tag (spec UC-1 / §14.3): reject a mapping whose
	 * n8n tag is already used by a different mapping. Comparison is exact /
	 * case-sensitive (tags are stored verbatim). This removes the whole "one
	 * workflow lands in two folders" edge case by construction.
	 *
	 * @param list<Mapping> $all
	 */
	private function assertTagUnique(array $all, string $tag, ?string $exceptId): void {
		foreach ($all as $m) {
			if ($m->id !== $exceptId && $m->n8nTag === $tag) {
				throw new \InvalidArgumentException(
					'Another mapping already uses the n8n tag "' . $tag . '". Each tag may map to only one folder.',
				);
			}
		}
	}

	/** @param list<Mapping> $mappings */
	private function persist(array $mappings): void {
		$json = json_encode(
			array_map(fn (Mapping $m) => $m->toArray(), $mappings),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
		);
		$this->config->setValueString(Application::APP_ID, 'mappings', $json);
	}
}
