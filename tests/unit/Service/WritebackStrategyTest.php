<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\WritebackStrategy;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The one decision that replaced the `timing` admin radio (saga Ch5).
 *
 * These are the cases the removed toggle could not express, which is the point of the
 * change: an admin picking "background" on an instance where nothing drains the queue
 * was choosing for the writeback never to happen, and neither they nor the form could
 * have known that.
 */
#[CoversClass(WritebackStrategy::class)]
final class WritebackStrategyTest extends TestCase {
	private function strategy(string $mode): WritebackStrategy {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($mode);
		return new WritebackStrategy($config, new NullLogger());
	}

	/**
	 * THE ORDINARY CASE, and the preference. A queued push returns the save
	 * immediately and does not serialise an n8n round trip into a desktop client's
	 * upload of a whole folder.
	 */
	public function testQueuesWhenThereIsAUserAndSomethingDrainsTheQueue(): void {
		self::assertTrue($this->strategy('cron')->canQueue('alice'));
	}

	/**
	 * WEBCRON COUNTS AS DRAINED, deliberately. One job per call and ~288 a day is
	 * slow, but somebody is calling it, so queued work still happens. Only `ajax` has
	 * the property that it may never run.
	 */
	public function testWebcronCountsAsDrained(): void {
		self::assertTrue($this->strategy('webcron')->canQueue('alice'));
	}

	/**
	 * `ajax` IS NEXTCLOUD'S DEFAULT, which is what makes this the important case
	 * rather than an exotic one. It runs a single job per page visit — no visitors, no
	 * execution — so a queued push on a fresh instance can sit indefinitely. Enqueueing
	 * would still SUCCEED (`IJobList::add()` is a row insert), which is exactly why the
	 * failure is silent and has to be pre-empted here.
	 */
	public function testRunsInlineWhenNothingReliablyDrainsTheQueue(): void {
		self::assertFalse($this->strategy('ajax')->canQueue('alice'));
	}

	/** An unreadable/absent mode is treated as the default, which is `ajax`. */
	public function testAnUnknownModeIsTreatedAsUndrained(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		self::assertFalse((new WritebackStrategy($config, new NullLogger()))->canQueue('alice'));
	}

	/**
	 * NO ACTING USER IS A CAPABILITY GAP, NOT A PREFERENCE. `PushWorkflowJob` re-opens
	 * a Files view to find the node again; with no uid it logs and gives up, so the
	 * work would simply never happen however well the queue is drained.
	 *
	 * Checked against a HEALTHY cron instance on purpose — otherwise the mode alone
	 * could be producing the answer and the uid arm would be untested.
	 */
	public function testRunsInlineWhenThereIsNobodyToActAs(): void {
		self::assertFalse($this->strategy('cron')->canQueue(''));
	}
}
