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
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\OwnershipTags;
use OCA\N8nSync\Service\PushService;
use OCA\N8nSync\Service\ReservedTagResolver;
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
		$this->service = new SyncService(
			$this->mappings,
			$this->n8n,
			$this->metadata,
			$this->createStub(OwnershipTags::class),
			$this->storage,
			$guard,
			$this->push,
			$mimeLoader,
			$this->createStub(IJobList::class),
			$this->createStub(SyncStatusService::class),
			$this->createStub(IAppConfig::class),
			new ReservedTagResolver(),
			$this->tagSync,
			new NullLogger(),
		);
	}

	private function mapping(string $mode = Mapping::MODE_SYNC, string $id = 'map-alpha'): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'n8n_tag' => 'nextcloud:alpha',
			'team_folder' => 'alpha',
			'nc_groups' => ['admin'],
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

	public function testPushOneDelegatesTagsToPushNotItself(): void {
		// Tag reconcile now lives inside PushService::push (Slice B, body-canonical),
		// so pushOne must NOT reconcile tags itself — a pushed file just counts.
		$managed = $this->file(1, 'Flow.n8n.json');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$managed]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-1', Mapping::MODE_SYNC));
		$this->push->method('push')->willReturn(true);

		$this->tagSync->expects(self::never())->method('reconcilePush');

		$res = $this->service->pushOne($this->mapping());

		self::assertSame(1, $res['succeeded']);
	}

	public function testPushOneReportsPushFailure(): void {
		// push() surfaces n8n's message; the file is reported failed (tag reconcile
		// is push()'s own concern and can't be reached when the body push throws).
		$managed = $this->file(1, 'Flow.n8n.json');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$managed]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-1', Mapping::MODE_SYNC));
		$this->push->method('push')->willThrowException(new \RuntimeException('n8n 400'));

		$res = $this->service->pushOne($this->mapping());

		self::assertSame(0, $res['succeeded']);
		self::assertSame(1, $res['failed']);
		self::assertStringContainsString('n8n 400', (string)$res['message']);
	}

	public function testPushOneSkipDoesNotPushLinkFile(): void {
		// A link file in a sync mapping is skipped before push — so no push at all.
		$linkFile = $this->file(2, 'B.n8n.json');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$linkFile]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('wf-2', Mapping::MODE_LINK));

		$this->push->expects(self::never())->method('push');

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
			->with(10, self::isType('array'), self::isInstanceOf(ManagedFile::class), ['nextcloud:alpha']);

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

	// ── reserved-tag ignore + ignored mode (saga §14.8) ─────────────────────────

	public function testPullResolvesIgnoreTagSoWorkflowIsNotPulled(): void {
		// An n8n:ignore workflow is never written — no file is created for it.
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->expects(self::never())->method('newFile');

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$this->n8n->method('eachWorkflow')->willReturn([
			['id' => 'wf-x', 'name' => 'X', 'tags' => [['id' => 'i', 'name' => OwnershipTags::TAG_IGNORE]]],
		]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC));

		self::assertSame(1, $res['processed']);
		self::assertSame(0, $res['succeeded']);
		self::assertSame(0, $res['pruned']);
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

	public function testPruneSkipsAnIgnoredFile(): void {
		// An ignored file stays in the folder; even though its (archived) workflow is
		// not returned under the tag, prune must NOT delete it.
		$ignored = $this->createMock(File::class);
		$ignored->method('getId')->willReturn(20);
		$ignored->method('getName')->willReturn('Ign.n8n.json');
		$ignored->expects(self::never())->method('delete');

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$ignored]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$this->metadata->method('read')->willReturnCallback(function (int $fileId): ?ManagedFile {
			return match ($fileId) {
				20 => $this->managed('wf-ign', WorkflowMetadata::MODE_IGNORED, 'map-alpha'),
				default => null,
			};
		});
		$this->n8n->method('eachWorkflow')->willReturn([]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(0, $res['pruned']);
	}

	// ── purge (admin "Purge Nextcloud files") ────────────────────────────────────

	public function testPurgeDeletesSyncAndLinkButKeepsUnmappedIgnoredUntracked(): void {
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
				4 => $this->managed('wf-4', WorkflowMetadata::MODE_IGNORED),
				default => null, // untracked (no record)
			};
		});

		$res = $this->service->purge();

		self::assertSame(2, $res['deleted']); // sync + link
		self::assertSame(2, $res['kept']);    // unmapped + ignored (untracked/non-n8n not counted)
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
