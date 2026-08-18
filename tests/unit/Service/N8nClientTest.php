<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
use OCA\N8nSync\Service\N8nClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see N8nClient::describeConnectionError} — the one formatter behind both the
 * Test connection button and the occ command. Its whole job is to keep the two
 * failure classes distinct: a key that isn't set vs. one that was set and
 * rejected. The 401 case doubly guards a regression: N8nApiException is a
 * RuntimeException subclass, so a naive `catch (RuntimeException)` would show n8n's
 * raw text instead of a clear "rejected".
 */
#[CoversClass(N8nClient::class)]
final class N8nClientTest extends TestCase {
	public function testDescribesAMissingKeyAsSetupNotRejection(): void {
		$msg = N8nClient::describeConnectionError(
			new \RuntimeException('No n8n API key is set — add one first.'),
		);
		self::assertStringContainsString('add one first', $msg);
		self::assertStringNotContainsStringIgnoringCase('rejected', $msg);
	}

	public function testDescribesA401AsARejectedKey(): void {
		$msg = N8nClient::describeConnectionError(new N8nApiException('unauthorized', 401));
		self::assertStringContainsString('401', $msg);
		self::assertStringContainsStringIgnoringCase('rejected', $msg);
		self::assertStringNotContainsString('unauthorized', $msg);
	}

	public function testDescribesA403AsARejectedKey(): void {
		$msg = N8nClient::describeConnectionError(new N8nApiException('forbidden', 403));
		self::assertStringContainsStringIgnoringCase('rejected', $msg);
	}

	public function testDescribesA404AsABaseUrlProblem(): void {
		$msg = N8nClient::describeConnectionError(new N8nApiException('not found', 404));
		self::assertStringContainsStringIgnoringCase('base url', $msg);
	}

	public function testDescribesATransportErrorAsUnreachable(): void {
		// httpStatus 0 = no response at all — genuinely "could not reach".
		$msg = N8nClient::describeConnectionError(new N8nApiException('connection refused', 0));
		self::assertStringContainsStringIgnoringCase('could not reach', $msg);
	}

	public function testDescribesA500AsAReachedHttpErrorNotUnreachable(): void {
		// n8n WAS reached and returned 500 — must not claim "could not reach".
		$msg = N8nClient::describeConnectionError(new N8nApiException('internal error', 500));
		self::assertStringContainsString('500', $msg);
		self::assertStringNotContainsStringIgnoringCase('could not reach', $msg);
	}
}
