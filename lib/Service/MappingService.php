<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Exception\ExistingWorkflowsException;
use OCP\IAppConfig;

/**
 * Storage + CRUD for the folder-mapping list.
 *
 * Backed by a single AppConfig key (`mappings`) holding a JSON array — keeps all
 * mappings in one round-trip read and makes occ/helm parity trivial
 * (`occ config:app:set n8n_sync mappings '[...json...]'`).
 *
 * Each mapping binds an n8n tag to a Team Folder shared with NC groups; see
 * {@see Mapping}. {@see Mapping::fromArray} still *reads* legacy rows (old
 * `n8n_path`/`nc_path` keys, `mode: 'reference'`, a stray `writeback`) so a list
 * is always parseable; the one-shot rewrite that re-persists them in the current
 * shape lives in {@see migrate()}, run from the {@see \OCA\N8nSync\Migration\MigrateMappings}
 * repair step — never on a read. The parsed list is memoised for the request, so
 * the several listeners that call {@see resolveForPath} each event don't re-decode
 * the config every time.
 */
final class MappingService {
	/**
	 * Request-scoped cache of the parsed list (the service is a per-request singleton).
	 *
	 * @var list<Mapping>|null
	 */
	private ?array $cache = null;

	public function __construct(
		private IAppConfig $config,
		private StorageService $storage,
		private ExistingWorkflows $existing,
	) {
	}

	/** @return list<Mapping> */
	public function list(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, 'mappings', '[]'), true);
		if (!is_array($decoded)) {
			return $this->cache = [];
		}
		return $this->cache = $this->parseRows($decoded);
	}

	/**
	 * Decode stored rows into Mappings. fromArray reads both current and legacy
	 * shapes; a malformed row is skipped rather than breaking the admin page.
	 *
	 * @param array<array-key, mixed> $decoded
	 * @return list<Mapping>
	 */
	private function parseRows(array $decoded): array {
		$result = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			try {
				$result[] = Mapping::fromArray($entry);
			} catch (\InvalidArgumentException) {
				continue;
			}
		}
		return $result;
	}

	/**
	 * One-shot: rewrite any legacy-shaped mapping rows into the current format and
	 * re-persist. Returns true when anything was rewritten (so the repair step can
	 * log it). Idempotent — a no-op on an already-clean store. This is the *only*
	 * place a read of the mappings config may also write it.
	 *
	 * Legacy markers (saga Ch3 §14): the old `n8n_path`/`nc_path` keys, the old
	 * `reference` link mode, and the removed `writeback` field.
	 */
	public function migrate(): bool {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, 'mappings', '[]'), true);
		if (!is_array($decoded)) {
			return false;
		}
		$legacySeen = false;
		foreach ($decoded as $entry) {
			if (is_array($entry) && self::isLegacyRow($entry)) {
				$legacySeen = true;
				break;
			}
		}
		$result = $this->parseRows($decoded);
		if (!$legacySeen || $result === []) {
			return false;
		}
		$this->persist($result);
		return true;
	}

	/** @param array<string,mixed> $entry */
	private static function isLegacyRow(array $entry): bool {
		return array_key_exists('nc_groups', $entry)
			|| (array_key_exists('n8n_path', $entry) && !array_key_exists('n8n_tag', $entry))
			|| (array_key_exists('nc_path', $entry) && !array_key_exists('team_folder', $entry))
			|| ($entry['mode'] ?? null) === 'reference'
			|| array_key_exists('writeback', $entry);
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

	/**
	 * Store a new mapping, and provision its folder.
	 *
	 * `$groups` is passed alongside the mapping rather than being part of it: they
	 * are applied to the folder and read back from it, never stored.
	 *
	 * THE FOLDER IS MADE BEFORE THE MAPPING IS PERSISTED, so a mapping that cannot
	 * be provisioned is not saved at all. A mapping asking for a Team Folder on an
	 * instance without groupfolders used to save happily and then fail on every
	 * sync afterwards, which reads as "the sync is broken" rather than "that
	 * backend is not installed".
	 *
	 * @param array<array-key, mixed>|string $groups
	 */
	public function add(Mapping $mapping, array|string $groups = [], bool $purgeWorkflows = false): Mapping {
		$all = $this->list();
		$this->assertApiKeyConfigured();
		$this->assertTagUnique($all, $mapping->n8nTag);
		$this->assertFolderUnique($all, $mapping->teamFolder);

		// READ BEFORE ANYTHING IS PROVISIONED, so a refusal costs nothing and the
		// number the admin is shown is the number that would go.
		//
		// AFTER `assertFolderUnique()`, WHICH IS WHY THIS ONLY EVER SEES UNMAPPED
		// FILES. A folder already in use is refused one line up, so a tree belonging
		// to another mapping never reaches this check.
		$existing = $mapping->mode === Mapping::MODE_LINK ? $this->existing->under($mapping) : [];
		if ($existing !== [] && !$purgeWorkflows) {
			throw new ExistingWorkflowsException(sprintf(
				'"%s" already holds %d workflow file%s. A link mapping holds pointers rather '
				. 'than workflows, so they would be permanently deleted — not moved to the '
				. 'trash, and not recoverable. Move them elsewhere first, or confirm the deletion.',
				$mapping->teamFolder,
				count($existing),
				count($existing) === 1 ? '' : 's',
			), count($existing), $mapping->teamFolder);
		}

		$this->storage->ensureFolder($mapping, $groups);
		$all[] = $mapping;
		$this->persist($all);

		// LAST, AND ONLY ONCE THE MAPPING IS REAL. The files are destroyed to make way
		// for a mapping, so destroying them before the mapping is stored would leave an
		// admin who hits a later refusal with neither the files nor the mapping.
		// `$existing` is the set the admin was shown a count for — re-walking here could
		// pick up a file that arrived in between, which nobody acknowledged.
		if ($existing !== []) {
			$this->existing->purge($existing);
		}

		return $mapping;
	}

	/**
	 * Re-share a mapping's folder with the given groups — the only edit there is.
	 *
	 * IT WRITES TO THE FOLDER AND PERSISTS NOTHING. The stored mapping is not
	 * touched, because groups are not on it; the return value is what the folder
	 * reports AFTERWARDS, which is not always what was submitted — a group that
	 * does not exist cannot be shared with.
	 *
	 * @param array<array-key, mixed>|string $ncGroups
	 * @return list<string>
	 */
	public function updateGroups(string $id, array|string $ncGroups): array {
		$mapping = $this->getById($id);
		if ($mapping === null) {
			throw new \OutOfBoundsException('mapping not found');
		}

		$this->storage->ensureFolder($mapping, $ncGroups);

		return $this->groupsOf($mapping);
	}

	/**
	 * The groups a mapping's folder is currently shared with.
	 *
	 * @return list<string>
	 */
	public function groupsOf(Mapping $mapping): array {
		return $this->storage->groupsOf($mapping);
	}

	/**
	 * The stored shape PLUS the folder's current groups — what the admin page and
	 * `list-mappings` render, as opposed to what is written to appconfig.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(Mapping $mapping): array {
		return $mapping->toArray() + ['nc_groups' => $this->groupsOf($mapping)];
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
	 * Given a Nextcloud node path, return the mapping whose folder **encloses**
	 * the node, or null. NC node paths look like `/<uid>/files/<folder…>/<file>`;
	 * we compare the part after `files/` against each mapping's `teamFolder`.
	 *
	 * Mappings are metadata on a folder, so they nest: a folder can be mapped
	 * **inside** an already-mapped folder. When more than one mapping encloses
	 * the node the **nearest enclosing** one wins — i.e. the deepest folder path.
	 * Because every enclosing folder is a path-prefix of the node, the longest
	 * matching `teamFolder` is unambiguously the deepest, so we keep the longest.
	 *
	 * Used by the create / move / copy listeners to decide which mapping (if any)
	 * a file belongs to. Kept in the service so the bulk-sync reconciler reuses it.
	 */
	public function resolveForPath(string $ncPath): ?Mapping {
		if (!preg_match('#/files/(.+)$#', $ncPath, $m)) {
			return null;
		}
		// Everything under the user's files root, e.g. "outer/inner/wf.n8n"
		// (a file) or "outer/inner" (the folder itself). Leading/trailing slashes
		// are stripped so the prefix comparison is on clean segments.
		$relative = trim($m[1], '/');
		$best = null;
		$bestLen = -1;
		foreach ($this->list() as $mapping) {
			$folder = trim($mapping->teamFolder, '/');
			if ($folder === '') {
				continue;
			}
			// The node belongs to $folder iff it IS that folder or lives anywhere
			// beneath it. The trailing slash pins the match to a segment boundary
			// so "outer" never swallows a sibling like "outerwear".
			$encloses = $relative === $folder
				|| str_starts_with($relative, $folder . '/');
			if ($encloses && strlen($folder) > $bestLen) {
				$bestLen = strlen($folder);
				$best = $mapping;
			}
		}
		return $best;
	}

	/**
	 * Enforce one-folder-per-tag (spec UC-1 / §14.3): reject a mapping whose
	 * n8n tag is already used by a different mapping. Comparison is exact /
	 * case-sensitive (tags are stored verbatim). This removes the whole "one
	 * workflow lands in two folders" edge case by construction.
	 *
	 * @param list<Mapping> $all
	 */
	private function assertTagUnique(array $all, string $tag): void {
		foreach ($all as $m) {
			if ($m->n8nTag === $tag) {
				throw new \InvalidArgumentException(
					'Another mapping already uses the n8n tag "' . $tag . '". Each tag may map to only one folder.',
				);
			}
		}
	}

	/**
	 * One folder, one mapping.
	 *
	 * THE TWIN OF {@see assertTagUnique()}, and it exists for the mirror-image reason.
	 * A tag mapped twice would make two mappings mean the same thing; a FOLDER mapped
	 * twice makes one tree answer to two tags, so every file in it belongs to both and
	 * `resolveForPath()` has to pick one — silently, and differently depending on
	 * which mapping was stored first.
	 *
	 * Compared case-insensitively and without surrounding slashes, because those are
	 * the same folder to Nextcloud and only look different in the store.
	 *
	 * @param list<Mapping> $all
	 */
	private function assertFolderUnique(array $all, string $folder): void {
		$wanted = mb_strtolower(trim($folder, '/'));
		if ($wanted === '') {
			return;
		}
		foreach ($all as $m) {
			if (mb_strtolower(trim($m->teamFolder, '/')) === $wanted) {
				throw new \InvalidArgumentException(
					'Another mapping already uses the Nextcloud folder "' . $m->teamFolder . '". Each folder may hold only one mapping.',
				);
			}
		}
	}

	/**
	 * A mapping without a key is a mapping that can never sync.
	 *
	 * REFUSED AT CREATION RATHER THAN DISCOVERED AT THE FIRST PULL. The folder is
	 * provisioned and shared the moment a mapping is stored, so a mapping made with no
	 * key leaves a real folder in people's Files that will never fill — and nothing
	 * tells the admin why until they go looking in the log.
	 *
	 * The URL is deliberately NOT checked here. It has a usable default and a bad one
	 * is a connection problem the Test button exists to report; a missing key is the
	 * one thing that makes the mapping meaningless on its own terms.
	 */
	private function assertApiKeyConfigured(): void {
		if ($this->config->getValueString(Application::APP_ID, 'api_key', '') === '') {
			throw new \InvalidArgumentException(
				'An API key is not configured yet. Add one in the n8n Sync admin settings, '
				. 'or with `occ n8n_sync:set-api-key`, before mapping a tag.',
			);
		}
	}

	/** @param list<Mapping> $mappings */
	private function persist(array $mappings): void {
		$json = json_encode(
			array_map(fn (Mapping $m) => $m->toArray(), $mappings),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
		);
		$this->config->setValueString(Application::APP_ID, 'mappings', $json);
		// Keep the request cache in step with what we just stored ($mappings is a list).
		$this->cache = $mappings;
	}
}
