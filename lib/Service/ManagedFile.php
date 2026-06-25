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
	) {
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
