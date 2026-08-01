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
		self::assertSame('My Workflow.n8n.json', $name);
	}

	public function testFormatIdSuffixedShapeEmbedsId(): void {
		$name = FilenameCodec::format('My Workflow', 'w0TtomB3I8dCHSXW', true);
		self::assertSame('My Workflow.w0TtomB3I8dCHSXW.n8n.json', $name);
	}

	public function testFormatAddsCollisionSuffix(): void {
		$name = FilenameCodec::format('My Workflow', 'w0TtomB3I8dCHSXW', false, 2);
		self::assertSame('My Workflow (2).n8n.json', $name);
	}

	public function testFormatFallsBackToIdWhenNameSanitisesToEmpty(): void {
		// A name made entirely of control characters sanitises to "" (they are
		// stripped, not substituted) — we must never produce a bare
		// ".n8n.json", so the id is used as the stem. (Banned path characters
		// like "/" become "_" and would NOT trigger this fallback.)
		$name = FilenameCodec::format("\x00\x01\x1f", 'w0TtomB3I8dCHSXW', false);
		self::assertSame('w0TtomB3I8dCHSXW.n8n.json', $name);
	}

	public function testFormatSanitisesUnsafeCharacters(): void {
		$name = FilenameCodec::format('a/b:c?d', 'w0TtomB3I8dCHSXW', false);
		self::assertSame('a_b_c_d.n8n.json', $name);
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
		$parsed = FilenameCodec::parse('My Workflow.n8n.json');

		self::assertNotNull($parsed);
		self::assertSame('My Workflow', $parsed['name']);
		self::assertNull($parsed['id']);
		self::assertSame(0, $parsed['suffix']);
	}

	public function testParseExtractsCollisionSuffix(): void {
		$parsed = FilenameCodec::parse('My Workflow (3).n8n.json');

		self::assertNotNull($parsed);
		self::assertSame('My Workflow', $parsed['name']);
		self::assertSame(3, $parsed['suffix']);
	}

	public function testParseIgnoresLeadingPath(): void {
		$parsed = FilenameCodec::parse('/team/folder/Daily Report.0oOA4iz0T0GRmICc.n8n.json');

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
		yield 'bare extension only' => ['.n8n.json'];
		yield 'empty' => [''];
	}

	#[DataProvider('workflowNameCases')]
	public function testIsWorkflowName(string $name, bool $expected): void {
		self::assertSame($expected, FilenameCodec::isWorkflowName($name));
	}

	/** @return iterable<string, array{string, bool}> */
	public static function workflowNameCases(): iterable {
		yield 'clean shape' => ['My Workflow.n8n.json', true];
		yield 'id-suffixed shape' => ['My Workflow.w0TtomB3I8dCHSXW.n8n.json', true];
		yield 'collision suffix' => ['My Workflow (2).n8n.json', true];
		yield 'bare extension' => ['.n8n.json', true]; // a name test, not a parse — the suffix alone matches
		yield 'plain json' => ['notes.json', false];
		yield 'wrong extension' => ['workflow.n8n.txt', false];
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
		yield 'trashed clean shape' => ['My Workflow.n8n.json.d1712345678', true];
		yield 'trashed id-suffixed shape' => ['My Workflow.w0TtomB3I8dCHSXW.n8n.json.d1712345678', true];
		yield 'trashed collision suffix' => ['My Workflow (2).n8n.json.d1', true];
		// A LIVE file is isWorkflowName()'s job. Matching it here too would let a
		// trash-only predicate fire on a file that was never deleted.
		yield 'live file is not trashed' => ['My Workflow.n8n.json', false];
		yield 'timestamp must be digits' => ['My Workflow.n8n.json.dABC', false];
		yield 'timestamp must be present' => ['My Workflow.n8n.json.d', false];
		yield 'suffix must follow the extension' => ['My Workflow.d1712345678.n8n.json', false];
		yield 'wrong extension' => ['notes.json.d1712345678', false];
		yield 'empty' => ['', false];
	}

	public function testIsWorkflowFileTrueForManagedFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Daily Report.n8n.json');
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
		$folder->method('getName')->willReturn('weird.n8n.json');
		self::assertFalse(FilenameCodec::isWorkflowFile($folder));
	}

	public function testIsWorkflowFileFalseForNull(): void {
		self::assertFalse(FilenameCodec::isWorkflowFile(null));
	}
}
