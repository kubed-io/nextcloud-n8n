<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use Psr\Log\LoggerInterface;

/**
 * Wraps Nextcloud's Files Metadata API for n8n workflow files.
 *
 * Keys tracked (saga Ch3 §14):
 *
 *   n8n_id          — the workflow id from n8n. Stable across renames/moves.
 *   n8n_mode        — the file's mode: sync | link | unmapped | ignored. INDEXED.
 *   n8n_versionId   — the n8n versionId we last reconciled (conflict detection).
 *   n8n_syncedHash  — sha1 of the file body at the last pull/push (loop guard).
 *   n8n_mapping     — id of the originating mapping. INDEXED.
 *   n8n_syncedTags  — JSON list of the content tags agreed at the last sync
 *                     (the three-way tag merge baseline).
 *
 * `n8n_writeback` was removed — mode is now the single source of truth (the old
 * `sync + two-way` is just `sync`).
 *
 * Why this is the cleanest layer:
 *  - **Server-side reads** (listeners, occ commands) call ::read() directly — zero
 *    DAV plumbing, zero round-trips.
 *  - **DAV/PROPFIND exposure is automatic.** Once registered with `initMetadata()`,
 *    every key is advertised at `{http://nextcloud.org/ns}metadata-<key>` by core's
 *    FilesPlugin, and the indexed keys are SEARCH/REPORT-queryable.
 *
 * The `link` ⇄ `reference` wire translation (THE one place it lives): NC core's
 * FilesPlugin feeds metadata values straight into PropFind::handle(), which calls
 * them as callbacks if `is_callable($value)` is true. The string `link` matches
 * PHP's builtin `link()`, so storing it explodes every PROPFIND. So **link mode is
 * stored as the value `reference`** and translated back on read — everywhere else
 * in the codebase the mode is `link`. `sync` / `unmapped` / `ignored` are not
 * callable, so they store as-is. Any future mode value MUST clear `is_callable()`.
 *
 * All keys are EDIT_FORBIDDEN: clients cannot mutate them via PROPPATCH. Only the
 * plugin itself writes them, from the pull/push reconcilers.
 */
final class WorkflowMetadata {
	public const KEY_ID = 'n8n_id';
	public const KEY_MODE = 'n8n_mode';       // sync | reference(=link) | unmapped | ignored — INDEXED
	public const KEY_VERSION_ID = 'n8n_versionId';
	/** sha1 of the file body at the last successful pull/push — the writeback loop guard. */
	public const KEY_SYNCED_HASH = 'n8n_syncedHash';
	/** Id of the originating mapping — INDEXED so files can be targeted by mapping. */
	public const KEY_MAPPING = 'n8n_mapping';
	/**
	 * JSON array of the reserved-stripped content-tag names agreed at the last
	 * pull/push — the baseline for the three-way tag merge ({@see TagSyncService}).
	 * Lets the reconciler tell an added tag from a removed one when both NC and n8n
	 * have drifted since the last sync. Not indexed (never queried by value).
	 */
	public const KEY_SYNCED_TAGS = 'n8n_syncedTags';

	/** File-mode values not covered by {@see Mapping} (which only configures sync/link). */
	public const MODE_UNMAPPED = 'unmapped';
	public const MODE_IGNORED = 'ignored';

	/**
	 * The on-the-wire (stored) value for {@see Mapping::MODE_LINK}. `link` itself is
	 * is_callable() and crashes core PROPFIND, so it is stored as `reference` and
	 * translated back by {@see read()}. This is the ONLY place `reference` appears.
	 */
	private const WIRE_LINK = 'reference';

	/** All managed keys, in a stable order suitable for diagnostics. */
	public const KEYS = [
		self::KEY_ID,
		self::KEY_MODE,
		self::KEY_VERSION_ID,
		self::KEY_SYNCED_HASH,
		self::KEY_MAPPING,
		self::KEY_SYNCED_TAGS,
	];

	/** Keys stored as searchable indexes (the rest are plain, read-only props). */
	private const INDEXED_KEYS = [self::KEY_MODE, self::KEY_MAPPING];

	public function __construct(
		private IFilesMetadataManager $manager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Idempotently register every key with the Files Metadata system.
	 *
	 * Called once from {@see Application::boot()}. After this runs, the keys are
	 * surfaced over DAV as `{nc:}metadata-<key>`, and the INDEXED_KEYS (mode +
	 * mapping) are SEARCH/REPORT-queryable — so "find every sync / unmapped /
	 * ignored file" is a fast indexed query, not a folder walk.
	 */
	public function register(): void {
		foreach (self::KEYS as $key) {
			$this->manager->initMetadata(
				$key,
				IMetadataValueWrapper::TYPE_STRING,
				in_array($key, self::INDEXED_KEYS, true), // indexed → searchable
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}
	}

	/**
	 * Upsert the managed keys for a file. Any key omitted from `$values` is left
	 * as-is; pass an explicit empty string to overwrite. The mode is given in the
	 * canonical vocabulary (`sync`/`link`/`unmapped`/`ignored`); `link` is stored
	 * as `reference` on the wire (see class docblock).
	 *
	 * @param array{
	 *     n8n_id?:string,
	 *     n8n_mode?:string,
	 *     n8n_versionId?:string,
	 *     n8n_syncedHash?:string,
	 *     n8n_mapping?:string,
	 *     n8n_syncedTags?:string
	 * } $values
	 */
	public function write(int $fileId, array $values): void {
		if ($values === []) {
			return;
		}
		$metadata = $this->manager->getMetadata($fileId, true);
		foreach (self::KEYS as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}
			$stored = $this->toWire($key, $values[$key]);
			// Indexed keys must be written with the index flag so they're searchable.
			$metadata->setString($key, $stored, in_array($key, self::INDEXED_KEYS, true));
		}
		$this->manager->saveMetadata($metadata);
	}

	/**
	 * Stamp the full sync-metadata set for a managed file in one call: id, mode,
	 * versionId, the body hash (the push loop-guard, computed here from $body), and
	 * the originating mapping. The single home for the five-key shape that the pull
	 * reconciler and create-on-land both write — callers apply the ownership tag
	 * separately ({@see OwnershipTags::apply}).
	 */
	public function stampSynced(int $fileId, string $id, string $mode, string $versionId, string $body, string $mappingId): void {
		$this->write($fileId, [
			self::KEY_ID => $id,
			self::KEY_MODE => $mode,
			self::KEY_VERSION_ID => $versionId,
			self::KEY_SYNCED_HASH => sha1($body),
			self::KEY_MAPPING => $mappingId,
		]);
	}

	/**
	 * Stamp the tag-sync baseline: the reserved-stripped content-tag set that NC and
	 * n8n agreed on at this pull/push. Written separately from {@see stampSynced}
	 * because tags are reconciled after the body is written and, on n8n, travel on a
	 * different API call than the workflow body ({@see N8nClient::setWorkflowTags}).
	 * The set is de-duplicated and sorted so the stored baseline is canonical.
	 *
	 * @param list<string> $tags
	 */
	public function stampTags(int $fileId, array $tags): void {
		$this->write($fileId, [self::KEY_SYNCED_TAGS => self::encodeTags($tags)]);
	}

	/**
	 * Canonical JSON for a tag baseline: unique + sorted so equal sets always encode
	 * to the same string (stable diffs, cheap equality). JSON — not a comma join —
	 * because n8n tag names may contain commas, spaces, any character.
	 *
	 * @param list<string> $tags
	 */
	public static function encodeTags(array $tags): string {
		$tags = array_values(array_unique(array_filter($tags, static fn (string $t): bool => $t !== '')));
		sort($tags);
		return json_encode($tags, JSON_THROW_ON_ERROR);
	}

	/**
	 * Read the managed keys for a file as a typed {@see ManagedFile}.
	 *
	 * Returns null if the file has no metadata record at all. Otherwise a
	 * ManagedFile whose unset keys read back as `''`. The mode is returned in the
	 * canonical vocabulary (the stored `reference` becomes `link`).
	 */
	public function read(int $fileId): ?ManagedFile {
		try {
			$metadata = $this->manager->getMetadata($fileId, false);
		} catch (FilesMetadataNotFoundException) {
			return null;
		}
		$value = fn (string $key): string => $metadata->hasKey($key)
			? $this->fromWire($key, $metadata->getString($key))
			: '';
		return new ManagedFile(
			$value(self::KEY_ID),
			$value(self::KEY_MODE),
			$value(self::KEY_VERSION_ID),
			$value(self::KEY_SYNCED_HASH),
			$value(self::KEY_MAPPING),
			$value(self::KEY_SYNCED_TAGS),
		);
	}

	/**
	 * Drop the entire managed-metadata record for a file. Used when a COPY lands
	 * (saga Ch3 §14 `copy.feature`): a copy is ALWAYS a brand-new instance and must
	 * never inherit the original's `n8n_id` / mode / mapping, so its metadata is
	 * wiped to a clean slate. Idempotent — safe on a file that has no record.
	 */
	public function clear(int $fileId): void {
		$this->manager->deleteMetadata($fileId);
	}

	/** Canonical → stored: `link` mode is persisted as `reference`. */
	private function toWire(string $key, string $value): string {
		return ($key === self::KEY_MODE && $value === Mapping::MODE_LINK) ? self::WIRE_LINK : $value;
	}

	/** Stored → canonical: the stored `reference` mode reads back as `link`. */
	private function fromWire(string $key, string $value): string {
		return ($key === self::KEY_MODE && $value === self::WIRE_LINK) ? Mapping::MODE_LINK : $value;
	}
}
