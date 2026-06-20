<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * Tracks the last-run state for the manual sync buttons.
 *
 * Two independent records keyed by direction:
 *   - "pull" : n8n  →  Nextcloud   ("Sync now ← n8n")
 *   - "push" : Nextcloud  →  n8n   ("Sync now → n8n")
 *
 * Persisted as one JSON blob per direction in AppConfig so a fresh install
 * starts with no state and `occ config:app:delete n8n_sync sync_status_*`
 * is enough to reset.
 *
 * Shape of each record (all optional):
 *   started_at   : ISO-8601 string (UTC)
 *   finished_at  : ISO-8601 string (UTC)
 *   status       : "running" | "ok" | "error"
 *   processed    : int   (files seen / candidates)
 *   succeeded    : int   (files actually synced)
 *   failed       : int
 *   message      : string (one-line summary, useful when status=error)
 */
final class SyncStatusService {
	public const DIR_PULL = 'pull';
	public const DIR_PUSH = 'push';

	public function __construct(
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/** @return array{pull: array<string,mixed>, push: array<string,mixed>} */
	public function all(): array {
		return [
			self::DIR_PULL => $this->get(self::DIR_PULL),
			self::DIR_PUSH => $this->get(self::DIR_PUSH),
		];
	}

	/** @return array<string,mixed> */
	public function get(string $direction): array {
		$this->assertDirection($direction);
		$raw = (string)$this->config->getAppValue(
			Application::APP_ID,
			$this->key($direction),
			'{}',
		);
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Async dispatch sets this the moment a job is enqueued (before it runs).
	 * Preserves the previous run's `finished_at`/counts so the UI keeps showing
	 * "last: <when>" while the new run is queued (only `status` flips).
	 */
	public function markQueued(string $direction): void {
		$this->save($direction, array_merge($this->get($direction), [
			'status' => 'queued',
			'queued_at' => $this->nowIso(),
		]));
	}

	/** Job picked up by the worker. Keeps the last finished result visible. */
	public function markStarted(string $direction): void {
		$this->save($direction, array_merge($this->get($direction), [
			'status' => 'running',
			'started_at' => $this->nowIso(),
		]));
	}

	/**
	 * Merge a completion record on top of whatever was set when the run started.
	 * Always sets `finished_at`; caller supplies `status` plus any counters.
	 *
	 * @param array<string,mixed> $patch
	 */
	public function markFinished(string $direction, array $patch): void {
		$current = $this->get($direction);
		$merged = array_merge($current, $patch, [
			'finished_at' => $this->nowIso(),
		]);
		if (!isset($merged['status'])) {
			$merged['status'] = 'ok';
		}
		$this->save($direction, $merged);
	}

	private function save(string $direction, array $record): void {
		$this->assertDirection($direction);
		$this->config->setAppValue(
			Application::APP_ID,
			$this->key($direction),
			json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
		);
	}

	private function key(string $direction): string {
		return 'sync_status_' . $direction;
	}

	private function assertDirection(string $direction): void {
		if ($direction !== self::DIR_PULL && $direction !== self::DIR_PUSH) {
			throw new \InvalidArgumentException('direction must be "pull" or "push"');
		}
	}

	private function nowIso(): string {
		// gmdate with the time factory keeps us testable and timezone-stable.
		return gmdate('Y-m-d\\TH:i:s\\Z', $this->time->getTime());
	}
}
