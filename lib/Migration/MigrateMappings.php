<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Migration;

use OCA\N8nSync\Service\MappingService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Rewrites any legacy-shaped folder mappings (old `n8n_path`/`nc_path` keys, the
 * old `reference` link mode, the removed `writeback` field) into the current
 * format, once, on upgrade. The actual rewrite lives in
 * {@see MappingService::migrate()} — this is just the lifecycle hook so the
 * cleanup happens here instead of opportunistically on every read.
 *
 * Idempotent: a no-op on an already-clean store.
 */
final class MigrateMappings implements IRepairStep {
	public function __construct(
		private MappingService $mappings,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Normalise legacy n8n_sync folder mappings';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->mappings->migrate()) {
			$output->info('n8n_sync: migrated legacy folder mappings to the current shape');
		}
	}
}
