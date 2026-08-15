<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Migration;

use OCA\N8nSync\Migration\MigrateFileExtension;
use OCA\N8nSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
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

	/**
	 * The users `callForSeenUsers` yields. More than one is the interesting case: a Team
	 * Folder is mounted once per member, so the same file comes back from every one of
	 * their searches and must be renamed exactly once.
	 *
	 * @var list<string>
	 */
	private array $users = ['alice'];

	/**
	 * Users whose home cannot be opened at all — a real state (never logged in, broken
	 * mount) and one an unattended upgrade must survive rather than abort on.
	 *
	 * @var list<string>
	 */
	private array $brokenUsers = [];

	/**
	 * Users for whom every `move()` fails — a Team Folder mounted read-only for their
	 * group, which groupfolders genuinely supports.
	 *
	 * @var list<string>
	 */
	private array $readOnlyUsers = [];

	/** The user `callForSeenUsers` is currently yielding, so the harness can vary by mount. */
	private string $currentUser = '';

	protected function setUp(): void {
		$this->moves = [];
		$this->occupied = [];
		$this->users = ['alice'];
		$this->brokenUsers = [];
		$this->readOnlyUsers = [];
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

	/**
	 * EVERY FILE, WHEREVER IT LIVES — the regression this class was rewritten for.
	 *
	 * It used to walk the mapped folders only, and the live instance had six workflow
	 * files sitting in a plain home folder outside every mapping. An unmapped file is a
	 * SUPPORTED state (eject a file by moving it out, move it back in to re-register),
	 * and one left on the old extension can never complete that round trip: the move-in
	 * listener asks `isWorkflowName()` first, so it would land in a mapped folder and be
	 * silently ignored.
	 */
	public function testEveryFileIsMigratedWhicheverFolderItIsIn(): void {
		$this->migrate(['Top.n8n.json'], ['Unmapped Scratch' => ['Deep.n8n.json']]);

		self::assertSame(
			[
				'Top.n8n.json -> /alice/files/Demo/Top.n8n',
				'Deep.n8n.json -> /alice/files/Demo/Unmapped Scratch/Deep.n8n',
			],
			$this->moves,
		);
	}

	/**
	 * A Team Folder is mounted once per member, so the same file comes back from every
	 * member's search. Renaming it twice would move an already-migrated `Fleet Health.n8n`
	 * to `Fleet Health.n8n (1).n8n` — a name nobody chose, on a file that was already
	 * right. Deduplicated by file id.
	 */
	public function testAFileSharedBetweenUsersIsRenamedExactlyOnce(): void {
		$this->users = ['alice', 'bob', 'carol'];
		$this->migrate(['Shared.n8n.json']);

		self::assertSame(['Shared.n8n.json -> /alice/files/Demo/Shared.n8n'], $this->moves);
	}

	/**
	 * A RENAME THAT FAILED IS NOT A RENAME THAT IS SETTLED. A Team Folder is mounted once
	 * per member and groupfolders supports per-group permissions, so the same file can be
	 * read-only under one member's mount and writable under another's — and
	 * `callForSeenUsers` yields in no particular order, so which one goes first is luck.
	 *
	 * Marking the id as handled on the failed attempt would let that luck decide whether
	 * the file is migrated at all, and an unmigrated file is invisible to the app forever,
	 * which is the whole thing this class exists to prevent.
	 */
	public function testAFileTheFirstMemberCannotRenameIsRetriedByTheNext(): void {
		$this->users = ['readonly', 'alice'];
		$this->readOnlyUsers = ['readonly'];
		$this->migrate(['Shared.n8n.json']);

		self::assertSame(['Shared.n8n.json -> /alice/files/Demo/Shared.n8n'], $this->moves);
	}

	/**
	 * A user whose home cannot be opened does not abandon everyone else's files. The
	 * broken user goes FIRST, because the failure mode being pinned is an exception
	 * escaping the callback and ending the whole `callForSeenUsers` walk.
	 */
	public function testABrokenUserHomeDoesNotStopTheRest(): void {
		$this->users = ['broken', 'alice'];
		$this->brokenUsers = ['broken'];
		$this->migrate(['Fine.n8n.json']);

		self::assertSame(['Fine.n8n.json -> /alice/files/Demo/Fine.n8n'], $this->moves);
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
	 * @param list<string> $files names directly in the folder
	 * @param array<string, list<string>> $subfolders name → the files inside it
	 * @param string $throwsOn a filename whose move() blows up
	 */
	private function migrate(array $files, array $subfolders = [], string $throwsOn = ''): void {
		// A FRESH TREE PER CALL, because each user gets their own mount of the same file
		// and the ids are what tie them together. Returning one shared array would let a
		// stub that failed for the first user fail identically for the second, hiding the
		// retry this class now does.
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('search')->willReturnCallback(
			fn (): array => $this->tree('/alice/files/Demo', $files, $subfolders, $throwsOn),
		);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $uid) use ($userFolder): Folder {
			if (in_array($uid, $this->brokenUsers, true)) {
				throw new \RuntimeException("no home for $uid");
			}
			return $userFolder;
		});

		$users = $this->createStub(IUserManager::class);
		$users->method('callForSeenUsers')->willReturnCallback(function (callable $fn): void {
			foreach ($this->users as $uid) {
				$this->currentUser = $uid;
				$u = $this->createStub(IUser::class);
				$u->method('getUID')->willReturn($uid);
				$fn($u);
			}
		});

		$step = new MigrateFileExtension($root, $users, new SyncGuard(), new NullLogger());
		$step->run($this->createStub(IOutput::class));
	}

	/**
	 * The flat result a `Folder::search()` returns — files from any depth, each carrying
	 * the parent it really lives in. Nested entries are given a deeper parent path so a
	 * rename target can be checked against the right folder.
	 *
	 * @param list<string> $files
	 * @param array<string, list<string>> $subfolders
	 * @return list<Node>
	 */
	private function tree(string $path, array $files, array $subfolders, string $throwsOn): array {
		$out = [];
		foreach ($files as $name) {
			$out[] = $this->fileNode($path, $name, $throwsOn);
		}
		foreach ($subfolders as $sub => $names) {
			foreach ($names as $name) {
				$out[] = $this->fileNode($path . '/' . $sub, $name, $throwsOn);
			}
		}
		return $out;
	}

	private function fileNode(string $parentPath, string $name, string $throwsOn): File {
		$parent = $this->createStub(Folder::class);
		$parent->method('getPath')->willReturn($parentPath);
		$parent->method('nodeExists')->willReturnCallback(
			fn (string $n): bool => in_array($n, $this->occupied, true),
		);

		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(crc32($parentPath . '/' . $name));
		$file->method('getParent')->willReturn($parent);
		$file->method('move')->willReturnCallback(function (string $target) use ($name, $throwsOn, $file): Node {
			if ($name === $throwsOn || in_array($this->currentUser, $this->readOnlyUsers, true)) {
				throw new \RuntimeException('locked');
			}
			$this->moves[] = $name . ' -> ' . $target;
			return $file;
		});
		return $file;
	}
}
