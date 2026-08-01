<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Typed view of a managed workflow file's Files-Metadata — the `n8n_*` keys
 * {@see WorkflowMetadata} stores, read back as a value object instead of an
 * `array<string,mixed>` the caller has to poke at with `?? null` + `is_string()`.
 *
 * Every field is normalised to a plain string: a key that was never stamped reads
 * back as `''` (not null), so callers compare against `''` or use the `is*()`
 * helpers and never juggle null. A file with no metadata record at all is
 * represented by {@see WorkflowMetadata::read()} returning `null`, not by a
 * ManagedFile with empty fields.
 *
 * The mode is in the **canonical** vocabulary (`sync` / `link` / `unmapped` /
 * `ignored`) — the stored `reference` wire value is already translated back to
 * `link` by {@see WorkflowMetadata::read()} before it reaches here.
 */
final class ManagedFile {
	public function __construct(
		public readonly string $workflowId,
		public readonly string $mode,
		public readonly string $versionId,
		public readonly string $syncedHash,
		public readonly string $mappingId,
		/**
		 * JSON array of the reserved-stripped content tag names agreed at the last
		 * pull/push — the three-way tag merge baseline ({@see TagSyncService}).
		 * Empty string when never stamped; decode with {@see syncedTagList()}.
		 */
		public readonly string $syncedTags = '',
	) {
	}

	/**
	 * The tag-sync baseline as a plain `list<string>`. A malformed or empty stamp
	 * reads back as `[]`, so callers never juggle JSON errors or null.
	 *
	 * @return list<string>
	 */
	public function syncedTagList(): array {
		if ($this->syncedTags === '') {
			return [];
		}
		$decoded = json_decode($this->syncedTags, true);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			return [];
		}
		return array_values(array_filter($decoded, 'is_string'));
	}

	/** True when the file carries an n8n workflow id — i.e. it is one of ours. */
	public function isManaged(): bool {
		return $this->workflowId !== '';
	}

	public function isSync(): bool {
		return $this->mode === Mapping::MODE_SYNC;
	}

	public function isLink(): bool {
		return $this->mode === Mapping::MODE_LINK;
	}

	public function isUnmapped(): bool {
		return $this->mode === WorkflowMetadata::MODE_UNMAPPED;
	}

	public function isIgnored(): bool {
		return $this->mode === WorkflowMetadata::MODE_IGNORED;
	}
}
