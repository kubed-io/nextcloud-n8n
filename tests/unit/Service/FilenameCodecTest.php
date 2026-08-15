<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\FilenameCodec;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see FilenameCodec}.
 *
 * This is the scaffold "hello world but real" test (Chapter 2 §5): FilenameCodec
 * is pure string logic with zero Nextcloud dependencies, so it runs in the
 * standalone unit suite with nothing but PHP. It still exercises behaviour that
 * actually matters — the id-suffix round-trip and collision handling are what
 * keep repeated pulls stable instead of oscillating.
 */
#[CoversClass(FilenameCodec::class)]
final class FilenameCodecTest extends TestCase {
	public function testFormatCleanShapeOmitsId(): void {
		$name = FilenameCodec::format('My Workflow', 'w0TtomB3I8dCHSXW', false);
		self::assertSame('My Workflow.n8n', $name);
	}

	public function testFormatIdSuffixedShapeEmbedsId(): void {
		$name = FilenameCodec::format('My Workflow', 'w0TtomB3I8dCHSXW', true);
		self::assertSame('My Workflow.w0TtomB3I8dCHSXW.n8n', $name);
	}

	public function testFormatAddsCollisionSuffix(): void {
		$name = FilenameCodec::format('My Workflow', 'w0TtomB3I8dCHSXW', false, 2);
		self::assertSame('My Workflow (2).n8n', $name);
	}

	public function testFormatFallsBackToIdWhenNameSanitisesToEmpty(): void {
		// A name made entirely of control characters sanitises to "" (they are
		// stripped, not substituted) — we must never produce a bare
		// ".n8n", so the id is used as the stem. (Banned path characters
		// like "/" become "_" and would NOT trigger this fallback.)
		$name = FilenameCodec::format("\x00\x01\x1f", 'w0TtomB3I8dCHSXW', false);
		self::assertSame('w0TtomB3I8dCHSXW.n8n', $name);
	}

	public function testFormatSanitisesUnsafeCharacters(): void {
		$name = FilenameCodec::format('a/b:c?d', 'w0TtomB3I8dCHSXW', false);
		self::assertSame('a_b_c_d.n8n', $name);
	}

	/**
	 * The headline property: an id-suffixed filename round-trips back to the
	 * exact name + id it was built from. This is what makes repeated pulls
	 * idempotent — the codec can recognise the file it wrote last time.
	 */
	#[DataProvider('roundTripNames')]
	public function testIdSuffixedRoundTrip(string $workflowName, string $id): void {
		$filename = FilenameCodec::format($workflowName, $id, true);
		$parsed = FilenameCodec::parse($filename);

		self::assertNotNull($parsed);
		self::assertSame($workflowName, $parsed['name']);
		self::assertSame($id, $parsed['id']);
		self::assertSame(0, $parsed['suffix']);
	}

	/** @return iterable<string, array{string, string}> */
	public static function roundTripNames(): iterable {
		yield 'simple' => ['Daily Report', '0oOA4iz0T0GRmICc'];
		yield 'name with dots' => ['v1.2 pipeline', 'PQfdkurMHf6SdR4w'];
		yield 'id with dashes' => ['Backup', '-7AgWuz-iwnhC4dkt'];
	}

	public function testParseCleanShapeHasNullId(): void {
		$parsed = FilenameCodec::parse('My Workflow.n8n');

		self::assertNotNull($parsed);
		self::assertSame('My Workflow', $parsed['name']);
		self::assertNull($parsed['id']);
		self::assertSame(0, $parsed['suffix']);
	}

	public function testParseExtractsCollisionSuffix(): void {
		$parsed = FilenameCodec::parse('My Workflow (3).n8n');

		self::assertNotNull($parsed);
		self::assertSame('My Workflow', $parsed['name']);
		self::assertSame(3, $parsed['suffix']);
	}

	public function testParseIgnoresLeadingPath(): void {
		$parsed = FilenameCodec::parse('/team/folder/Daily Report.0oOA4iz0T0GRmICc.n8n');

		self::assertNotNull($parsed);
		self::assertSame('Daily Report', $parsed['name']);
		self::assertSame('0oOA4iz0T0GRmICc', $parsed['id']);
	}

	#[DataProvider('nonMatchingBasenames')]
	public function testParseReturnsNullForNonN8nFiles(string $basename): void {
		self::assertNull(FilenameCodec::parse($basename));
	}

	/** @return iterable<string, array{string}> */
	public static function nonMatchingBasenames(): iterable {
		yield 'plain json' => ['notes.json'];
		yield 'wrong extension' => ['workflow.n8n.txt'];
		yield 'bare extension only' => ['.n8n'];
		yield 'the retired compound extension' => ['workflow.n8n.json'];
		yield 'empty' => [''];
	}

	#[DataProvider('workflowNameCases')]
	public function testIsWorkflowName(string $name, bool $expected): void {
		self::assertSame($expected, FilenameCodec::isWorkflowName($name));
	}

	/** @return iterable<string, array{string, bool}> */
	public static function workflowNameCases(): iterable {
		yield 'clean shape' => ['My Workflow.n8n', true];
		yield 'id-suffixed shape' => ['My Workflow.w0TtomB3I8dCHSXW.n8n', true];
		yield 'collision suffix' => ['My Workflow (2).n8n', true];
		// AGREES WITH parse() NOW, which rejects an empty stem. It used to answer true
		// here and null there — a disagreement about "is this ours?" that the compound
		// extension hid, because a bare `.n8n.json` is not a name anyone would type.
		yield 'bare extension (empty stem)' => ['.n8n', false];
		yield 'plain json' => ['notes.json', false];
		yield 'wrong extension' => ['workflow.n8n.txt', false];
		yield 'the retired compound extension' => ['workflow.n8n.json', false];
		yield 'no extension' => ['workflow', false];
		yield 'empty' => ['', false];
	}

	/**
	 * The trash renames what it takes. Half the reason the purge step never ran was
	 * that {@see FilenameCodec::isWorkflowName} is FALSE for every trashed workflow
	 * file — the deletion timestamp lands after the extension, so `str_ends_with`
	 * misses. These pin the predicate that fixed it.
	 */
	#[DataProvider('trashedNameCases')]
	public function testIsTrashedWorkflowName(string $name, bool $expected): void {
		self::assertSame($expected, FilenameCodec::isTrashedWorkflowName($name));
	}

	/** @return iterable<string, array{string, bool}> */
	public static function trashedNameCases(): iterable {
		yield 'trashed clean shape' => ['My Workflow.n8n.d1712345678', true];
		yield 'trashed id-suffixed shape' => ['My Workflow.w0TtomB3I8dCHSXW.n8n.d1712345678', true];
		yield 'trashed collision suffix' => ['My Workflow (2).n8n.d1', true];
		// A LIVE file is isWorkflowName()'s job. Matching it here too would let a
		// trash-only predicate fire on a file that was never deleted.
		yield 'live file is not trashed' => ['My Workflow.n8n', false];
		yield 'timestamp must be digits' => ['My Workflow.n8n.dABC', false];
		yield 'timestamp must be present' => ['My Workflow.n8n.d', false];
		yield 'suffix must follow the extension' => ['My Workflow.d1712345678.n8n', false];
		yield 'wrong extension' => ['notes.json.d1712345678', false];
		yield 'empty' => ['', false];
	}

	public function testIsWorkflowFileTrueForManagedFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Daily Report.n8n');
		self::assertTrue(FilenameCodec::isWorkflowFile($file));
	}

	public function testIsWorkflowFileFalseForWrongExtension(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('notes.json');
		self::assertFalse(FilenameCodec::isWorkflowFile($file));
	}

	public function testIsWorkflowFileFalseForNonFileNode(): void {
		// A Folder is a Node but not a File — the predicate rejects it even if the
		// name happens to end in the extension (a folder named like a workflow).
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn('weird.n8n');
		self::assertFalse(FilenameCodec::isWorkflowFile($folder));
	}

	public function testIsWorkflowFileFalseForNull(): void {
		self::assertFalse(FilenameCodec::isWorkflowFile(null));
	}

	// ── one collision spelling, shared with Nextcloud ──────────────────────────

	/**
	 * How Nextcloud names a file whose name is taken: `getUniqueName()` inserts the
	 * counter immediately before the LAST extension, counting from one. Modelled here
	 * rather than asserted against literals so the claim below is about the RULE — the
	 * tests are worthless if they only agree with names somebody typed out by hand.
	 */
	private static function asNextcloudWouldName(string $taken, int $n): string {
		$dot = strrpos($taken, '.');
		self::assertNotFalse($dot, "'$taken' has no extension for a counter to go before");
		return substr($taken, 0, $dot) . ' (' . $n . ')' . substr($taken, $dot);
	}

	/**
	 * THE WHOLE POINT OF THE SINGLE-SEGMENT EXTENSION: a copy is born with the name this
	 * codec would have chosen, so there is nothing to read around and nothing to rename.
	 *
	 * Under the compound `.n8n.json` the two disagreed — Nextcloud produced
	 * `FooBoblicious.n8n (1).json`, confirmed on the live instance — which does not end
	 * in the extension, so every predicate here answered "not ours": no metadata, no
	 * workflow in n8n, and a file that looks managed and is not.
	 */
	#[DataProvider('collidingNames')]
	public function testNextcloudNamesACopyExactlyAsThisCodecWould(string $name, string $id, bool $idInFilename): void {
		$first = FilenameCodec::format($name, $id, $idInFilename);

		self::assertSame(
			FilenameCodec::format($name, $id, $idInFilename, 1),
			self::asNextcloudWouldName($first, 1),
			'the client and the codec must spell a collision the same way',
		);
	}

	/** @return iterable<string, array{string, string, bool}> */
	public static function collidingNames(): iterable {
		yield 'clean shape' => ['Fleet Health', '0oOA4iz0T0GRmICc', false];
		yield 'id-suffixed shape' => ['Board', '0oOA4iz0T0GRmICc', true];
		yield 'a name containing a dot' => ['v1.2 thing', 'PQfdkurMHf6SdR4w', false];
	}

	/** And the name the client picked is one of ours, with the counter read off it. */
	public function testACopyTheClientNamedParsesStraightBack(): void {
		$copy = self::asNextcloudWouldName(FilenameCodec::format('Fleet Health', 'PQfdkurMHf6SdR4w', false), 1);

		self::assertTrue(FilenameCodec::isWorkflowName($copy));
		$parsed = FilenameCodec::parse($copy);
		self::assertNotNull($parsed);
		self::assertSame('Fleet Health', $parsed['name'], 'the logical name a pull matches on');
		self::assertSame('Fleet Health (1)', $parsed['display'], 'the name the user reads');
		self::assertSame(1, $parsed['suffix']);
	}

	/**
	 * THE ID-SUFFIXED SHAPE SURVIVES IT, and only because the counter is read off
	 * FIRST. The client appends it after the id — there is nowhere else for it to go —
	 * so a parse that looked for the id first would see `0oOA4iz0T0GRmICc (1)`, match
	 * nothing, and drop the identity on the very gesture most likely to need it.
	 */
	public function testACopiedIdSuffixedNameKeepsItsId(): void {
		$copy = self::asNextcloudWouldName(FilenameCodec::format('Board', 'aBcDeF123456', true), 1);

		$parsed = FilenameCodec::parse($copy);
		self::assertNotNull($parsed);
		self::assertSame('aBcDeF123456', $parsed['id']);
		self::assertSame('Board', $parsed['name']);
		self::assertSame('Board (1)', $parsed['display']);
	}

	/**
	 * THE RETIRED COMPOUND EXTENSION IS NOT OURS ANY MORE, and that is deliberate: it is
	 * exactly why {@see \OCA\N8nSync\Migration\MigrateFileExtension} has to run — and
	 * why, unlike the Grafana sibling's, it stays for a version or two. A published app's
	 * population is not ours to count, and an upgraded instance whose files were never
	 * renamed would keep every workflow on disk and never act on one again.
	 */
	public function testTheRetiredCompoundExtensionIsNoLongerRecognised(): void {
		self::assertFalse(FilenameCodec::isWorkflowName('Fleet Health.n8n.json'));
		self::assertNull(FilenameCodec::parse('Fleet Health.n8n.json'));
		self::assertFalse(FilenameCodec::isWorkflowName('Fleet Health.n8n (1).json'));
	}

	/** Not ours at all — the counter is there but the type is not. */
	public function testAnUnrelatedFileWithACounterIsNotOurs(): void {
		self::assertFalse(FilenameCodec::isWorkflowName('Budget (1).xlsx'));
		self::assertFalse(FilenameCodec::isWorkflowName('notes (1).json'));
	}

	// ── the two names a suffixed file has ──────────────────────────────────────

	/**
	 * `name` and `display` differ by exactly the counter, and callers want opposite
	 * ones: a pull matches on the LOGICAL name so a mirror already wearing `(1)` is
	 * recognised next time, while anything showing the user a name wants it as written.
	 */
	public function testASuffixedFileHasBothALogicalNameAndADisplayedOne(): void {
		$parsed = FilenameCodec::parse('Fleet Health (1).n8n');

		self::assertNotNull($parsed);
		self::assertSame('Fleet Health', $parsed['name'], 'the logical name a pull matches on');
		self::assertSame('Fleet Health (1)', $parsed['display'], 'the name the user reads');
		self::assertSame(1, $parsed['suffix']);
	}

	/** With no counter the two are the same string, so callers can use either safely. */
	public function testAnUnsuffixedFilesTwoNamesAreTheSame(): void {
		$parsed = FilenameCodec::parse('Fleet Health.n8n');

		self::assertNotNull($parsed);
		self::assertSame($parsed['name'], $parsed['display']);
	}

	/**
	 * THE ONE-LINER THAT STOPS THE MISTAKE. Reaching into `parse()` and taking `name` is
	 * how a workflow copied in Nextcloud reached n8n still wearing the ORIGINAL's name —
	 * the counter, which was the entire difference between the two names, had been
	 * stripped on the way past.
	 */
	public function testDisplayNameKeepsTheCounter(): void {
		self::assertSame('Fleet Health (1)', FilenameCodec::displayName('Fleet Health (1).n8n'));
		self::assertSame('Fleet Health', FilenameCodec::displayName('Fleet Health.n8n'));
		self::assertSame('', FilenameCodec::displayName('Budget.xlsx'));
	}
}
