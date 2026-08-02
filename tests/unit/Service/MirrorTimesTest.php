<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\MirrorTimes;
use OCP\Files\Cache\ICache;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see MirrorTimes} — giving a mirror the timestamps of the thing it
 * mirrors, without giving back the churn that saga §5.11 removed.
 *
 * The rule under test is the same one the body write follows: **write only what
 * differs**. Measured live, `touch()` leaves the file's own etag alone but propagates a
 * fresh etag to its PARENT FOLDER — which is exactly what sync clients poll to decide
 * "something in here changed, re-scan it". So a class that stamped the clock every pull
 * would not churn the files, it would churn the folder, every tick, forever. The
 * no-op-when-already-correct tests below are therefore the point of this class, not an
 * edge case.
 */
#[CoversClass(MirrorTimes::class)]
final class MirrorTimesTest extends TestCase {
	private MirrorTimes $times;

	protected function setUp(): void {
		$this->times = new MirrorTimes(new NullLogger());
	}

	// ── parse ──────────────────────────────────────────────────────────────────

	public function testParseReadsAnIso8601Timestamp(): void {
		self::assertSame(
			strtotime('2026-07-24T16:25:42Z'),
			MirrorTimes::parse('2026-07-24T16:25:42.986Z'),
			'n8n sends milliseconds; they round down to the second rather than failing',
		);
	}

	/**
	 * Null means "leave the clock alone", never "stamp the epoch" — so a field n8n
	 * stops sending, or renames, degrades to Nextcloud's own clock (the old behaviour)
	 * instead of dating every mirror 1970.
	 */
	public function testParseReturnsNullForAnythingUnusable(): void {
		self::assertNull(MirrorTimes::parse(null));
		self::assertNull(MirrorTimes::parse(''));
		self::assertNull(MirrorTimes::parse('not a date'));
		self::assertNull(MirrorTimes::parse(1234567890));
		self::assertNull(MirrorTimes::parse(['2026-07-24T16:25:42Z']));
	}

	// ── mtime ──────────────────────────────────────────────────────────────────

	public function testStampsTheModificationTimeWhenItDiffers(): void {
		$file = $this->createMock(File::class);
		$file->method('getMTime')->willReturn(1000);
		$file->expects(self::once())->method('touch')->with(2000);

		$this->times->apply($file, 2000, null);
	}

	public function testLeavesTheModificationTimeAloneWhenItAlreadyMatches(): void {
		// THE anti-churn test: a settled mirror is not touched, so its etag holds and
		// desktop clients have nothing to re-download.
		$file = $this->createMock(File::class);
		$file->method('getMTime')->willReturn(2000);
		$file->expects(self::never())->method('touch');

		$this->times->apply($file, 2000, null);
	}

	public function testForceStampsEvenWhenTheClockLooksRight(): void {
		// The caller just rewrote the body, so the file's mtime is `now` whatever its
		// cached FileInfo says — comparing would be reading a stale value.
		$file = $this->createMock(File::class);
		$file->method('getMTime')->willReturn(2000);
		$file->expects(self::once())->method('touch')->with(2000);

		$this->times->apply($file, 2000, null, true);
	}

	public function testANullModificationTimeTouchesNothing(): void {
		$file = $this->createMock(File::class);
		$file->expects(self::never())->method('touch');

		// Even with $force: null is "we do not know", and a forced write of an unknown
		// value is how you date every mirror 1970.
		$this->times->apply($file, null, null, true);
	}

	// ── creation time ──────────────────────────────────────────────────────────

	public function testStampsTheCreationTimeThroughTheCacheWhenItDiffers(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('update')->with(42, ['creation_time' => 900]);

		$file = $this->fileWithCache($cache, creationTime: 100);
		$this->times->apply($file, null, 900);
	}

	public function testLeavesTheCreationTimeAloneWhenItAlreadyMatches(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::never())->method('update');

		$file = $this->fileWithCache($cache, creationTime: 900);
		$this->times->apply($file, null, 900);
	}

	public function testForceDoesNotApplyToTheCreationTime(): void {
		// Unlike mtime, writing the body does not disturb the creation time, so the
		// comparison is always meaningful and $force has nothing to override.
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::never())->method('update');

		$file = $this->fileWithCache($cache, creationTime: 900);
		$this->times->apply($file, null, 900, true);
	}

	// ── failure is never fatal ─────────────────────────────────────────────────

	public function testAClockThatWillNotSetIsSwallowed(): void {
		// The body, the metadata and the tags are already committed by the time a
		// caller reaches this, so a storage that refuses must not turn a good pull
		// into a failed one. The next pull retries.
		$file = $this->createMock(File::class);
		$file->method('getMTime')->willReturn(1000);
		$file->method('getName')->willReturn('Flow.n8n.json');
		$file->method('touch')->willThrowException(new \RuntimeException('read-only storage'));

		$this->times->apply($file, 2000, null);

		$this->expectNotToPerformAssertions();
	}

	/** A File whose storage hands back $cache, and whose creation time is $creationTime. */
	private function fileWithCache(ICache $cache, int $creationTime): File {
		$storage = $this->createStub(IStorage::class);
		$storage->method('getCache')->willReturn($cache);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getCreationTime')->willReturn($creationTime);
		$file->method('getStorage')->willReturn($storage);
		return $file;
	}
}
