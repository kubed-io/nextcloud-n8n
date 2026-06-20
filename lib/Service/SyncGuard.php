<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

/**
 * Re-entrancy guard so the plugin's own file writes (the pull reconciler writing
 * workflow JSON into NC) don't trip the {@see \OCA\N8nSync\Listener\NodeWrittenListener}
 * into pushing them straight back to n8n — an infinite loop.
 *
 * It's a request-scoped singleton (Nextcloud shares app services within a
 * request), so SyncService can wrap its writes and the listener can bail while
 * the guard is active. Counter-based so nested enters are safe.
 */
final class SyncGuard {
	private int $depth = 0;

	public function enter(): void {
		$this->depth++;
	}

	public function leave(): void {
		if ($this->depth > 0) {
			$this->depth--;
		}
	}

	public function active(): bool {
		return $this->depth > 0;
	}

	/** Run $fn with the guard active; always leaves, even on exception. */
	public function run(callable $fn): mixed {
		$this->enter();
		try {
			return $fn();
		} finally {
			$this->leave();
		}
	}
}
