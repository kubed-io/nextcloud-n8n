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
 * Three keys are tracked:
 *
 *   n8n_id          \u2014 the workflow id from n8n. Stable across renames.
 *   n8n_mode        \u2014 'reference' or 'sync' (per-file effective mode).
 *   n8n_versionId   \u2014 the n8n versionId we last reconciled, used for
 *                     conflict detection on writeback (Phase 4).
 *
 * Why this is the cleanest layer:
 *  - **Server-side reads** (writeback listener, occ commands) call
 *    ::read() directly \u2014 zero DAV plumbing, zero round-trips.
 *  - **DAV/PROPFIND exposure is automatic.** Once registered with
 *    `initMetadata()`, every key is advertised at
 *    `{http://nextcloud.org/ns}metadata-<key>` by core's FilesPlugin. The
 *    Phase 5 frontend file action reads the deep-link id from there
 *    without us writing a byte of DAV code (verified by reading
 *    `apps/dav/lib/Connector/Sabre/FilesPlugin.php` lines 463-465).
 *
 * Mode value note: 'link' is *forbidden* here even though it was the
 * historical name for the read-only mode. NC core's FilesPlugin feeds
 * metadata values straight into PropFind::handle(), which calls them as
 * callbacks if `is_callable($value)` returns true. The string 'link'
 * matches PHP's builtin `link()` function, so storing it explodes every
 * PROPFIND with `ArgumentCountError: link() expects exactly 2 arguments`.
 * We renamed the read-only mode to 'reference' (not a callable symbol)
 * project-wide; see Mapping docblock for the migration. Any future enum
 * additions here MUST clear `is_callable()`.
 *
 * All keys are EDIT_FORBIDDEN: clients cannot mutate them via PROPPATCH.
 * Only the plugin itself writes them, from the pull/push reconcilers.
 */
class WorkflowMetadata {
	public const KEY_ID          = 'n8n_id';
	public const KEY_MODE        = 'n8n_mode';       // reference | sync
	public const KEY_WRITEBACK   = 'n8n_writeback';  // two-way | readonly | '' (reference)
	public const KEY_VERSION_ID  = 'n8n_versionId';
	/** sha1 of the file body at the last successful pull/push — the writeback loop guard. */
	public const KEY_SYNCED_HASH = 'n8n_syncedHash';
	/** Id of the originating mapping — INDEXED so files can be targeted by mapping. */
	public const KEY_MAPPING     = 'n8n_mapping';

	/** All managed keys, in a stable order suitable for diagnostics. */
	public const KEYS = [
		self::KEY_ID,
		self::KEY_MODE,
		self::KEY_WRITEBACK,
		self::KEY_VERSION_ID,
		self::KEY_SYNCED_HASH,
		self::KEY_MAPPING,
	];

	/** Keys stored as searchable indexes (the rest are plain, read-only props). */
	private const INDEXED_KEYS = [self::KEY_MAPPING];

	public function __construct(
		private IFilesMetadataManager $manager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Idempotently register every key with the Files Metadata system.
	 *
	 * Called once from {@see Application::boot()}. After this runs, the keys
	 * are queryable, indexable, and \u2014 most importantly \u2014 surfaced over DAV
	 * as `{nc:}metadata-<key>` automatically.
	 *
	 * String type for all three: id is opaque, mode is a small enum,
	 * versionId is a UUID. None benefits from numeric/list typing.
	 *
	 * `indexed=false` because we do not need SEARCH-by-metadata yet \u2014 the
	 * pull reconciler walks NC folders and reads each file's metadata
	 * directly. Flipping these to indexed later is a reversible, non-breaking
	 * change.
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
	 * Upsert the managed keys for a file. Any key omitted from `$values` is
	 * left as-is; pass an explicit empty string to overwrite.
	 *
	 * @param array{n8n_id?:string, n8n_mode?:string, n8n_versionId?:string} $values
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
			// Indexed keys must be written with the index flag so they're searchable.
			$metadata->setString($key, (string)$values[$key], in_array($key, self::INDEXED_KEYS, true));
		}
		$this->manager->saveMetadata($metadata);
	}

	/**
	 * Read the managed keys for a file.
	 *
	 * Returns null if the file has no metadata record at all (i.e. nothing
	 * has ever been written for it). Returns an array with `null` entries
	 * for individual keys that simply aren't set yet.
	 *
	 * @return array{n8n_id:?string, n8n_mode:?string, n8n_versionId:?string}|null
	 */
	public function read(int $fileId): ?array {
		try {
			$metadata = $this->manager->getMetadata($fileId, false);
		} catch (FilesMetadataNotFoundException) {
			return null;
		}
		$out = [];
		foreach (self::KEYS as $key) {
			$out[$key] = $metadata->hasKey($key) ? $metadata->getString($key) : null;
		}
		return $out;
	}
}
