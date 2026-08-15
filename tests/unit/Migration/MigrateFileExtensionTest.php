<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Migration;

use OCA\N8nSync\Migration\MigrateFileExtension;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\StorageService;
use OCA\N8nSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see MigrateFileExtension} — the one-time rename from the retired `.n8n.json`
 * to `.n8n`.
 *
 * THIS IS THE ONLY THING IN THE CUT THAT TOUCHES A USER'S FILES, and it runs
 * unattended during an upgrade, so the failure modes worth pinning are the quiet
 * ones: a file skipped (its workflow is then unreachable forever, because the app
 * no longer recognises the old extension), a file renamed twice, or one rename
 * throwing and taking the rest of the folder down with it.
 */
#[CoversClass(MigrateFileExtension::class)]
final class MigrateFileExtensionTest extends TestCase {
	/** @var list<string> every move() the step performed, as "<old> -> <new path>" */
	private array $moves = [];

	/**
	 * Names that already exist in the folder, so a rename onto one has to step aside.
	 *
	 * @var list<string>
	 */
	private array $occupied = [];

	protected function setUp(): void {
		$this->moves = [];
		$this->occupied = [];
	}

	public function testTheRetiredExtensionIsRenamedToTheNewOne(): void {
		$this->migrate(['Fleet Health.n8n.json']);

		self::assertSame(
			['Fleet Health.n8n.json -> /alice/files/Demo/Fleet Health.n8n'],
			$this->moves,
		);
	}

	/**
	 * THE SHAPE THE OLD EXTENSION LEFT BEHIND. Nextcloud counted before the LAST
	 * extension, so a copy landed as `Fleet Health.n8n (1).json` — and it has to
	 * come out the far side as `Fleet Health (1).n8n`, the name the new extension
	 * would have produced, not as a file with a counter buried in its middle.
	 */
	public function testNextcloudsOldCollisionSpellingBecomesATrailingCounter(): void {
		$this->migrate(['Fleet Health.n8n (1).json']);

		self::assertSame(
			['Fleet Health.n8n (1).json -> /alice/files/Demo/Fleet Health (1).n8n'],
			$this->moves,
		);
	}

	/** The uid-suffixed shape keeps its uid, with the counter moving to the end. */
	public function testTheUidSuffixedShapeSurvivesWithItsCounterMoved(): void {
		$this->migrate(['Board.aBcDeF123456.n8n (2).json']);

		self::assertSame(
			['Board.aBcDeF123456.n8n (2).json -> /alice/files/Demo/Board.aBcDeF123456 (2).n8n'],
			$this->moves,
		);
	}

	/** Idempotent: run it twice and the second pass has nothing to do. */
	public function testAFileAlreadyOnTheNewExtensionIsLeftAlone(): void {
		$this->migrate(['Fleet Health.n8n', 'Fleet Health (1).n8n']);

		self::assertSame([], $this->moves);
	}

	/** Somebody else's file with the same tail is none of our business. */
	public function testAnUnrelatedFileIsNeverTouched(): void {
		$this->migrate(['Budget.xlsx', 'notes.json', 'workflow.n8n.txt']);

		self::assertSame([], $this->moves);
	}

	/**
	 * TWO OLD NAMES CAN WANT ONE NEW NAME — a copy Nextcloud named and a file somebody
	 * had already renamed by hand both land on `Fleet Health (1).n8n`. The second
	 * one steps aside instead of throwing, because a throw here would strand every
	 * remaining file in the folder on an extension the app no longer reads.
	 */
	public function testASecondFileWantingTheSameNameStepsAside(): void {
		$this->occupied = ['Fleet Health (1).n8n'];
		$this->migrate(['Fleet Health.n8n (1).json']);

		self::assertSame(
			['Fleet Health.n8n (1).json -> /alice/files/Demo/Fleet Health (1) (1).n8n'],
			$this->moves,
		);
	}

	/** Subfolders are mirrored into n8n too, so their files migrate as well. */
	public function testItRecursesIntoSubfolders(): void {
		$this->migrate(['Top.n8n.json'], ['Nested' => ['Deep.n8n.json']]);

		self::assertSame(
			[
				'Top.n8n.json -> /alice/files/Demo/Top.n8n',
				'Deep.n8n.json -> /alice/files/Demo/Nested/Deep.n8n',
			],
			$this->moves,
		);
	}

	/**
	 * One unrenamable file must not cost the others. The upgrade is unattended, so a
	 * half-migrated folder that reported success is the worst outcome available.
	 */
	public function testAFailedRenameDoesNotStopTheRest(): void {
		$this->migrate(['Broken.n8n.json', 'Fine.n8n.json'], [], 'Broken.n8n.json');

		self::assertSame(['Fine.n8n.json -> /alice/files/Demo/Fine.n8n'], $this->moves);
	}

	// ── harness ────────────────────────────────────────────────────────────────

	/**
	 * @param list<string> $files names directly in the mapped folder
	 * @param array<string, list<string>> $subfolders name → the files inside it
	 * @param string $throwsOn a filename whose move() blows up
	 */
	private function migrate(array $files, array $subfolders = [], string $throwsOn = ''): void {
		$folder = $this->folderNode('/alice/files/Demo', $files, $subfolders, $throwsOn);

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('list')->willReturn([$this->mapping()]);
		$storage = $this->createStub(StorageService::class);
		$storage->method('findFolder')->willReturn($folder);

		$step = new MigrateFileExtension($mappings, $storage, new SyncGuard(), new NullLogger());
		$step->run($this->createStub(IOutput::class));
	}

	/**
	 * @param list<string> $files
	 * @param array<string, list<string>> $subfolders
	 */
	private function folderNode(string $path, array $files, array $subfolders, string $throwsOn): Folder {
		/** @var list<Node> $children */
		$children = [];
		foreach ($files as $name) {
			$children[] = $this->fileNode($path, $name, $throwsOn);
		}
		foreach ($subfolders as $name => $contents) {
			$children[] = $this->folderNode($path . '/' . $name, $contents, [], $throwsOn);
		}

		$folder = $this->createStub(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getDirectoryListing')->willReturn($children);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => in_array($name, $this->occupied, true),
		);
		return $folder;
	}

	private function fileNode(string $parentPath, string $name, string $throwsOn): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(crc32($parentPath . '/' . $name));
		$file->method('move')->willReturnCallback(function (string $target) use ($name, $throwsOn, $file): Node {
			if ($name === $throwsOn) {
				throw new \RuntimeException('locked');
			}
			$this->moves[] = $name . ' -> ' . $target;
			return $file;
		});
		return $file;
	}

	private function mapping(): Mapping {
		return Mapping::fromArray([
			'id' => 'map-alpha',
			'n8n_tag' => 'nextcloud:alpha',
			'team_folder' => 'Demo',
			'mode' => Mapping::MODE_SYNC,
		]);
	}
}
