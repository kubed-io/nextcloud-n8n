<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a piece of writeback runs INLINE in the request or is QUEUED for the
 * background worker — one decision, in one place, derived rather than configured.
 *
 * ## THIS USED TO BE AN ADMIN RADIO, AND IT SHOULD NEVER HAVE BEEN ONE
 *
 * `Sync Settings` offered "push in the background" vs "push immediately during the save".
 * It read as an instance-wide mode and was nothing of the sort: `timing` was consulted in
 * exactly two places and governed two of the six sites that queue a job. A copy's rename,
 * every bulk sync and the scheduled pull stayed asynchronous whichever way it was set.
 * Saga Ch5 (*The toggle that governs two of fifteen things*) has the full audit.
 *
 * Worse, it asked the admin a question the app can answer better, and could not answer at
 * all in the case that matters — see below.
 *
 * ## QUEUED IS THE PREFERENCE; INLINE IS THE FALLBACK
 *
 * They are not two equally good modes, which is what a radio listing them side by side
 * implied. Queueing is what we want: a save returns immediately, and a desktop client
 * uploading a folder does not serialise an n8n round trip into every one of its PUTs.
 * Inline is what we do when queueing would not actually work.
 *
 * Two conditions make it not work, and neither is a preference:
 *
 *   1. **NO ACTING USER.** {@see \OCA\N8nSync\BackgroundJob\PushWorkflowJob} re-opens a
 *      Files view to find the node again, so it needs a uid. Without one the job logs
 *      and gives up — the work would simply never happen. `NodeWrittenListener` already
 *      fell back to inline here; `ContentTagListener` returned and did nothing at all.
 *      Same condition, opposite behaviour, and that inconsistency is the bug this class
 *      removes by construction.
 *
 *   2. **NOBODY DRAINS THE QUEUE.** `backgroundjobs_mode` defaults to `ajax`, which
 *      Nextcloud's own admin manual calls "the least reliable": one job per page visit,
 *      and only when somebody visits. On such an instance a queued push may not run for
 *      hours, or ever. Enqueueing always SUCCEEDS there — `IJobList::add()` is a row
 *      insert and cannot fail for want of infrastructure — which is exactly why the
 *      failure is silent and why this has to be checked rather than assumed.
 *
 * `webcron` is deliberately treated as drained. It is slow (one job per call, ~288/day)
 * but somebody is actually calling it, so work does not vanish. Only `ajax` has the
 * property that nothing may ever run.
 *
 * ## WHAT THIS DELIBERATELY DOES NOT DECIDE
 *
 * Only the sites that genuinely have a choice ask. Nine of the app's fifteen writeback
 * operations are settled by physics and never come here: anything that writes a file's
 * own bytes during that file's own event MUST be queued (Nextcloud's lock — see
 * {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob}), and anything that must answer
 * before the request continues MUST be inline (a guard's refusal, a delete abort, a
 * request-scoped store). Those are not configurable and are not routed through here.
 */
final class WritebackStrategy {
	/**
	 * The one background-jobs mode where queued work may never run at all.
	 * `ajax` executes a single job per page visit — no visitors, no execution.
	 */
	private const MODE_UNDRAINED = 'ajax';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Can this work be handed to the background worker, or must it run now?
	 *
	 * @param string $uid the acting user the job would re-resolve the node through;
	 *                    an empty string means there is nobody for it to act as
	 */
	public function canQueue(string $uid): bool {
		if ($uid === '') {
			$this->logger->debug('n8n_sync writeback: no acting user, so a job could not find the file; running inline', [
				'app' => Application::APP_ID,
			]);
			return false;
		}
		$mode = $this->config->getValueString('core', 'backgroundjobs_mode', self::MODE_UNDRAINED);
		if ($mode === self::MODE_UNDRAINED) {
			$this->logger->debug('n8n_sync writeback: background jobs run on page visits only; running inline', [
				'app' => Application::APP_ID,
				'backgroundjobs_mode' => $mode,
			]);
			return false;
		}
		return true;
	}
}
