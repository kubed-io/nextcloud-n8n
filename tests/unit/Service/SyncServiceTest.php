<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\MirrorTimes;
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\N8nWorkflowBody;
use OCA\N8nSync\Service\PushService;
use OCA\N8nSync\Service\StorageService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\SyncService;
use OCA\N8nSync\Service\SyncStatusService;
use OCA\N8nSync\Service\TagSyncService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the {@see SyncService} manual, mapping-scoped sync (saga §14.6).
 *
 * Two new behaviours under test:
 *  - **pushOne** pushes only a mapping's own `sync` `.n8n.json` files (skipping a
 *    `link` mapping and any file without an `n8n_id`); files outside the mapping
 *    folder — every `unmapped` file — are never even listed, so never pushed.
 *  - **pull prune**: a managed file whose workflow no longer carries the tag (it
 *    wasn't returned by n8n this pull) is deleted from the folder, while a file
 *    whose workflow still carries the tag is updated in place, not removed.
 *
 * Doubles follow the repo convention (saga §14.2d): collaborators that only hand
 * back canned values are `createStub` (no PHPUnit "mock without expectations"
 * notice); only the file nodes whose `delete()` we verify are `createMock`. The
 * `final` collaborators rely on the unit bootstrap's `dg/bypass-finals`.
 */
#[CoversClass(SyncService::class)]
final class SyncServiceTest extends TestCase {
	private StorageService $storage;
	private N8nClient $n8n;
	private WorkflowMetadata $metadata;
	private PushService $push;
	private MappingService $mappings;
	private TagSyncService $tagSync;
	private MirrorTimes $times;
	private SyncService $service;

	protected function setUp(): void {
		$this->storage = $this->createStub(StorageService::class);
		$this->n8n = $this->createStub(N8nClient::class);
		$this->metadata = $this->createStub(WorkflowMetadata::class);
		$this->push = $this->createStub(PushService::class);

		// SyncGuard just brackets work in enter/leave (and run()); inert stub.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		// fixupFilecacheMimetype: a no-op pair, never asserted.
		$mimeLoader = $this->createStub(IMimeTypeLoader::class);
		$mimeLoader->method('getId')->willReturn(1);

		$this->mappings = $this->createStub(MappingService::class);
		$this->tagSync = $this->createMock(TagSyncService::class);
		// MirrorTimes reaches into the storage/cache stack, so it is mocked here and
		// covered on its own in MirrorTimesTest — the reconciler only owes the mapping.
		$this->times = $this->createMock(MirrorTimes::class);
		$this->service = new SyncService(
			$this->mappings,
			$this->n8n,
			$this->metadata,
			$this->storage,
			$guard,
			$this->push,
			$mimeLoader,
			$this->createStub(IJobList::class),
			$this->createStub(SyncStatusService::class),
			$this->createStub(IAppConfig::class),
			$this->tagSync,
			$this->times,
			new NullLogger(),
		);
	}

	private function mapping(string $mode = Mapping::MODE_SYNC, string $id = 'map-alpha'): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'n8n_tag' => 'nextcloud:alpha',
			'team_folder' => 'alpha',
			'mode' => $mode,
			'use_team_folder' => false,
		]);
	}

	/** A managed `.n8n.json` File stub with a fixed id + name (no verified interactions). */
	private function file(int $id, string $name): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		return $node;
	}

	/**
	 * The body {@see SyncService} writes for a `sync` workflow — the pull encodes the
	 * n8n row verbatim, so a test that wants "the mirror already matches n8n" has to
	 * hand back these exact bytes from getContent()/getSize().
	 *
	 * @param array<string,mixed> $workflow
	 */
	private function syncBody(array $workflow): string {
		return N8nWorkflowBody::encodeSync($workflow);
	}

	/** A {@see ManagedFile} read() stub value (the typed metadata WorkflowMetadata::read returns). */
	private function managed(string $id = '', string $mode = '', string $mappingId = ''): ManagedFile {
		return new ManagedFile($id, $mode, '', '', $mappingId);
	}

	// ── pushOne ────────────────────────────────────────────────────────────────

	public function testPushOneSkipsLinkMapping(): void {
		// A link mapping never reaches storage; the returned zeros prove the bail.
		$res = $this->service->pushOne($this->mapping(Mapping::MODE_LINK));

		self::assertSame(['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null], $res);
	}

	public function testPushOneSkipsWhenStorageUnavailable(): void {
		$this->storage->method('isAvailable')->willReturn(false);

		$res = $this->service->pushOne($this->mapping());

		self::assertSame(0, $res['processed']);
	}

	public function testPushOnePushesManagedSyncFilesAndSkipsTheRest(): void {
		$managed = $this->file(1, 'Flow.n8n.json');
		$plainTxt = $this->file(2, 'notes.txt');           // wrong extension → skipped
		$unstamped = $this->file(3, 'Draft.n8n.json');     // no n8n_id → skipped

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$managed, $plainTxt, $unstamped]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);

		$this->metadata->method('read')->willReturnCallback(function (int $fileId): ?ManagedFile {
			return match ($fileId) {
				1 => $this->managed('wf-1'),
				3 => $this->managed(),  // managed-looking name but never stamped
				default => null,
			};
		});

		// Only the stamped sync file is a push candidate; a true result counts as succeeded.
		$this->push->method('push')->willReturn(true);

		$res = $this->service->pushOne($this->mapping());

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['failed']);
		self::assertNull($res['message']);
	}

	public function testPushOneReconcilesTagsForEachPushedFile(): void {
		$managed = $this->file(1, 'Flow.n8n.json');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$managed]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-1', Mapping::MODE_SYNC));
		$this->push->method('push')->willReturn(true);

		// Parity: a forced push reconciles the file's tags back to n8n, passing the
		// mapping's own tag as the protected set (it must never be pushed as removed).
		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(1, self::isInstanceOf(ManagedFile::class));

		$res = $this->service->pushOne($this->mapping());

		self::assertSame(1, $res['succeeded']);
	}

	public function testPushOneTagFailureDoesNotFailTheFile(): void {
		// The body already pushed + stamped; a tag reconcile error is logged and
		// swallowed, so the file still counts as succeeded, not failed.
		$managed = $this->file(1, 'Flow.n8n.json');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$managed]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-1', Mapping::MODE_SYNC));
		$this->push->method('push')->willReturn(true);
		$this->tagSync->method('reconcilePush')
			->willThrowException(new \RuntimeException('n8n 500 on setWorkflowTags'));

		$res = $this->service->pushOne($this->mapping());

		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['failed']);
		self::assertNull($res['message']);
	}

	public function testPushOneSkipDoesNotReconcileTags(): void {
		// A link file in a sync mapping is skipped before push — so no tag write.
		$linkFile = $this->file(2, 'B.n8n.json');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$linkFile]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-2', Mapping::MODE_LINK));

		$this->tagSync->expects(self::never())->method('reconcilePush');

		$this->service->pushOne($this->mapping(Mapping::MODE_SYNC));
	}

	// ── pull prune ───────────────────────────────────────────────────────────────

	public function testPullOneReconcilesTagsForWrittenWorkflow(): void {
		// Parity: a forced pull reconciles each written workflow's tags onto the
		// file, passing the workflow row (its `tags`) and the mapping's protected tag.
		$keepName = FilenameCodec::format('Keep', 'wf-keep', false, 0);
		$keep = $this->file(10, $keepName);

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$keep]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-keep', Mapping::MODE_SYNC, 'map-alpha'));
		$this->n8n->method('eachWorkflow')->willReturn([
			['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1', 'tags' => [['id' => 't', 'name' => 'prod']]],
		]);

		$this->tagSync->expects(self::once())
			->method('reconcilePull')
			->with(10, self::isType('array'), self::isInstanceOf(ManagedFile::class));

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(1, $res['succeeded']);
	}

	public function testPullOnePrunesFilesWhoseWorkflowLostTheTag(): void {
		// The kept file's name already matches the canonical form, so writeWorkflow
		// updates it in place without a rename; the stale file is pruned.
		$keepName = FilenameCodec::format('Keep', 'wf-keep', false, 0);
		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn($keepName);
		$keep->expects(self::never())->method('delete');

		$stale = $this->createMock(File::class);
		$stale->method('getId')->willReturn(11);
		$stale->method('getName')->willReturn('Stale.n8n.json');
		$stale->expects(self::once())->method('delete');

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$keep, $stale]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$mapId = 'map-alpha';
		$this->metadata->method('read')->willReturnCallback(function (int $fileId) use ($mapId): ?ManagedFile {
			return match ($fileId) {
				10 => $this->managed('wf-keep', mappingId: $mapId),
				11 => $this->managed('wf-stale', mappingId: $mapId),
				default => null,
			};
		});

		// n8n still returns only the "keep" workflow under the tag.
		$this->n8n->method('eachWorkflow')->willReturn([
			['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1'],
		]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, $mapId));

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(1, $res['pruned']);
	}

	// ── pull change-detection (saga Ch5 §5.11) ──────────────────────────────────
	//
	// The defect: writeWorkflow called putContent() unconditionally, so a scheduled
	// pull rewrote every mirrored file every tick and every file read as "Modified a
	// few seconds ago" forever. A mirror whose bytes already match n8n must not be
	// written; one that differs still must be.

	public function testPullDoesNotRewriteAMirrorThatAlreadyMatchesN8n(): void {
		$workflow = ['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1'];
		$body = $this->syncBody($workflow);

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(strlen($body));
		$keep->method('getContent')->willReturn($body);
		$keep->expects(self::never())->method('putContent');

		self::assertSame(1, $this->pullWith($keep, $workflow)['unchanged']);
	}

	public function testPullRewritesAMirrorWhoseBodyChangedInN8n(): void {
		// Same length as the stale mirror, so only the CONTENT comparison can catch
		// it — proves the cheap size check is a shortcut, never the whole test.
		$workflow = ['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v2'];
		$body = $this->syncBody($workflow);
		$stale = str_replace('v2', 'v1', $body);
		self::assertSame(strlen($body), strlen($stale), 'fixture must be same-length to exercise the content compare');

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(strlen($stale));
		$keep->method('getContent')->willReturn($stale);
		$keep->expects(self::once())->method('putContent')->with($body);

		$res = $this->pullWith($keep, $workflow);

		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['unchanged']);
	}

	public function testPullRewritesWithoutReadingWhenTheSizeAlreadyDiffers(): void {
		// A differing size is an exact "changed" answer straight from the filecache,
		// so a changed workflow never pays for a storage read.
		$workflow = ['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1'];

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(1);
		$keep->expects(self::never())->method('getContent');
		$keep->expects(self::once())->method('putContent');

		self::assertSame(0, $this->pullWith($keep, $workflow)['unchanged']);
	}

	public function testPullRewritesWhenTheMirrorCannotBeRead(): void {
		// An unreadable mirror must degrade to the old always-write behaviour, never
		// to "leave it alone" — a pull still has to be able to repair a broken file.
		$workflow = ['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1'];
		$body = $this->syncBody($workflow);

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(strlen($body));
		$keep->method('getContent')->willThrowException(new \RuntimeException('storage unreachable'));
		$keep->expects(self::once())->method('putContent')->with($body);

		self::assertSame(0, $this->pullWith($keep, $workflow)['unchanged']);
	}

	public function testFreshWriteCountsAsChanged(): void {
		// A workflow with no mirror yet is always a write — never "unchanged".
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->file(10, 'Keep.n8n.json'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-keep', Mapping::MODE_SYNC, 'map-alpha'));
		$this->n8n->method('eachWorkflow')->willReturn([['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1']]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['unchanged']);
	}

	/**
	 * Pull one mapping holding exactly $mirror, with n8n returning exactly $workflow.
	 * The mirror is already canonically named and owned by the mapping, so the only
	 * decision left in writeWorkflow is whether to write the body.
	 *
	 * @param array<string,mixed> $workflow
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, unchanged:int}
	 */
	private function pullWith(File $mirror, array $workflow): array {
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$mirror]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed((string)$workflow['id'], Mapping::MODE_SYNC, 'map-alpha'));
		$this->n8n->method('eachWorkflow')->willReturn([$workflow]);

		return $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));
	}

	// ── source timestamps on the mirror ─────────────────────────────────────────
	//
	// The reconciler owes exactly one thing here: map n8n's field names onto the two
	// clocks and say whether the body was just rewritten. The write-only-what-differs
	// rule, and the framework plumbing, are MirrorTimes' — see MirrorTimesTest.

	public function testPullHandsN8nsOwnTimestampsToTheClockStamper(): void {
		$workflow = [
			'id' => 'wf-keep',
			'name' => 'Keep',
			'versionId' => 'v1',
			'updatedAt' => '2026-07-24T16:25:42.986Z',
			'createdAt' => '2026-06-17T21:53:20.580Z',
		];
		$body = $this->syncBody($workflow);

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(strlen($body));
		$keep->method('getContent')->willReturn($body);

		// Unchanged body → $force is false, so MirrorTimes gets to decide by comparison.
		$this->times->expects(self::once())
			->method('apply')
			->with($keep, strtotime('2026-07-24T16:25:42Z'), strtotime('2026-06-17T21:53:20Z'), false);

		self::assertSame(1, $this->pullWith($keep, $workflow)['unchanged']);
	}

	public function testAJustWrittenMirrorForcesTheClockRestamp(): void {
		// The body was rewritten, so the file's mtime is `now` — comparing would read a
		// value we already know is wrong. The reconciler says so with $force = true.
		$workflow = ['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v2', 'updatedAt' => '2026-07-24T16:25:42Z'];

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(1); // differs → rewritten

		$this->times->expects(self::once())
			->method('apply')
			->with($keep, strtotime('2026-07-24T16:25:42Z'), null, true);

		self::assertSame(0, $this->pullWith($keep, $workflow)['unchanged']);
	}

	public function testAWorkflowWithNoTimestampsLeavesBothClocksAlone(): void {
		// n8n renaming or dropping the fields must degrade to Nextcloud's own clock,
		// not to a mirror dated 1970 — the reconciler passes null and MirrorTimes bails.
		$workflow = ['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1'];
		$body = $this->syncBody($workflow);

		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn(FilenameCodec::format('Keep', 'wf-keep', false, 0));
		$keep->method('getSize')->willReturn(strlen($body));
		$keep->method('getContent')->willReturn($body);

		$this->times->expects(self::once())->method('apply')->with($keep, null, null, false);

		$this->pullWith($keep, $workflow);
	}

	public function testPushOneSkipsALinkFileInASyncMapping(): void {
		$syncFile = $this->file(1, 'A.n8n.json');
		$linkFile = $this->file(2, 'B.n8n.json'); // a link-mode file is never pushed

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$syncFile, $linkFile]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);

		$this->metadata->method('read')->willReturnCallback(function (int $fileId): ?ManagedFile {
			return match ($fileId) {
				1 => $this->managed('wf-1', Mapping::MODE_SYNC),
				2 => $this->managed('wf-2', Mapping::MODE_LINK),
				default => null,
			};
		});
		$this->push->method('push')->willReturn(true);

		$res = $this->service->pushOne($this->mapping(Mapping::MODE_SYNC));

		// Only the sync file is a push candidate; the link file is skipped.
		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
	}

	// ── purge (admin "Purge Nextcloud files") ────────────────────────────────────

	public function testPurgeDeletesSyncAndLinkButKeepsUnmappedAndUntracked(): void {
		// The data-safety contract: purge removes only what a pull can restore
		// (sync/link); unmapped + ignored (archived in n8n) and untracked files stay.
		$sync = $this->fileExpectDelete(1, 'Sync.n8n.json', true);
		$link = $this->fileExpectDelete(2, 'Link.n8n.json', true);
		$unmapped = $this->fileExpectDelete(3, 'Template.n8n.json', false);
		$ignored = $this->fileExpectDelete(4, 'Parked.n8n.json', false);
		$untracked = $this->fileExpectDelete(5, 'Draft.n8n.json', false);
		$notOurs = $this->fileExpectDelete(6, 'notes.txt', false);

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$sync, $link, $unmapped, $ignored, $untracked, $notOurs]);

		$this->mappings->method('list')->willReturn([$this->mapping(Mapping::MODE_SYNC)]);
		$this->storage->method('findFolder')->willReturn($folder);

		$this->metadata->method('read')->willReturnCallback(function (int $fileId): ?ManagedFile {
			return match ($fileId) {
				1 => $this->managed('wf-1', Mapping::MODE_SYNC),
				2 => $this->managed('wf-2', Mapping::MODE_LINK),
				3 => $this->managed('wf-3', WorkflowMetadata::MODE_UNMAPPED),
				default => null, // untracked (no record)
			};
		});

		$res = $this->service->purge();

		self::assertSame(2, $res['deleted']); // sync + link
		self::assertSame(1, $res['kept']);    // unmapped (untracked/non-n8n not counted)
	}

	/** A managed File mock that asserts whether ::delete() is (or isn't) called. */
	private function fileExpectDelete(int $id, string $name, bool $shouldDelete): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		$node->expects($shouldDelete ? self::once() : self::never())->method('delete');
		return $node;
	}
}
