<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\BackgroundJob;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\AppConfigReader;
use OCA\N8nSync\Service\SyncService;
use OCA\N8nSync\Service\SyncStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Scheduled n8n → Nextcloud pull (§17.3). A TimedJob — NC schedules by interval,
 * not cron expressions — registered once in {@see Application::boot} and run by
 * the cron worker. Both knobs come from the "Sync Settings" declarative form:
 *   - `schedule_enabled`  : master on/off (run() no-ops when off)
 *   - `schedule_interval` : seconds between runs (read in the constructor)
 *
 * The interval is re-read each time the job is instantiated, so changing it in
 * settings takes effect on the next tick. Status is recorded via
 * SyncStatusService so the Manual sync panel's "last:" line reflects scheduled
 * runs too.
 */
final class ScheduledPullJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AppConfigReader $config,
		private SyncService $sync,
		private SyncStatusService $status,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(max(60, $this->intervalSeconds()));
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!$this->isEnabled()) {
			return; // disabled — interval still gates how often we re-check
		}
		$this->status->markStarted(SyncStatusService::DIR_PULL);
		try {
			$result = $this->sync->runInline(SyncStatusService::DIR_PULL, null);
			$this->status->markFinished(SyncStatusService::DIR_PULL, $result);
		} catch (\Throwable $e) {
			$this->logger->error('n8n_sync scheduled pull failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			$this->status->markFinished(SyncStatusService::DIR_PULL, [
				'status' => 'error',
				'message' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Both reads go through {@see AppConfigReader}: a value written before the
	 * form moved to EXTERNAL storage may carry the wrong stored type, and the
	 * reader's string-parse rescue keeps the schedule running through that.
	 */
	private function isEnabled(): bool {
		return $this->config->bool('schedule_enabled');
	}

	/**
	 * Parse the free-text schedule into seconds: a number with an optional unit
	 * (s/m/h/d), or a plain number = seconds. Anything unparseable → hourly.
	 * Clamped to a 60s floor (the cron tick granularity).
	 */
	private function intervalSeconds(): int {
		$raw = strtolower(trim($this->config->string('schedule_interval', '1h')));
		if ($raw === '') {
			return 3600;
		}
		if (preg_match('/^(\d+)\s*([smhd]?)$/', $raw, $m)) {
			$mult = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
			return max(60, (int)$m[1] * $mult);
		}
		return 3600;
	}
}
