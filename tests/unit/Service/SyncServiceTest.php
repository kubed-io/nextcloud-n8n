<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\FilenameCodec;
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

		$this->service = new SyncService(
			$this->createStub(MappingService::class),
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

		$this->metadata->method('read')->willReturnCallback(static function (int $fileId): ?array {
			return match ($fileId) {
				1 => [WorkflowMetadata::KEY_ID => 'wf-1'],
				3 => [],            // managed-looking name but never stamped
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

	// ── pull prune ───────────────────────────────────────────────────────────────

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
		$this->metadata->method('read')->willReturnCallback(static function (int $fileId) use ($mapId): ?array {
			return match ($fileId) {
				10 => [WorkflowMetadata::KEY_ID => 'wf-keep', WorkflowMetadata::KEY_MAPPING => $mapId],
				11 => [WorkflowMetadata::KEY_ID => 'wf-stale', WorkflowMetadata::KEY_MAPPING => $mapId],
				default => null,
			};
		});

		// n8n still returns only the "keep" workflow under the tag.
		$this->n8n->method('listWorkflows')->willReturn([
			'data' => [['id' => 'wf-keep', 'name' => 'Keep', 'versionId' => 'v1']],
			'nextCursor' => null,
		]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, $mapId));

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(1, $res['pruned']);
	}

	// ── reserved-tag overrides + ignored mode (saga §14.8) ───────────────────────

	public function testPullAppliesLinkOverrideOnASyncMapping(): void {
		// A sync mapping, but the one workflow carries n8n:link → it is written as a
		// link pointer (reference schema), not the full sync JSON.
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);

		$newFile = $this->createStub(File::class);
		$newFile->method('getId')->willReturn(99);

		$captured = null;
		$folder->expects(self::once())->method('newFile')
			->willReturnCallback(function (string $name, string $body) use (&$captured, $newFile): File {
				$captured = $body;
				return $newFile;
			});

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$this->n8n->method('listWorkflows')->willReturn([
			'data' => [['id' => 'wf-1', 'name' => 'Flow', 'versionId' => 'v1', 'tags' => [['id' => 't', 'name' => OwnershipTags::TAG_LINK]]]],
			'nextCursor' => null,
		]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC));

		self::assertSame(1, $res['succeeded']);
		self::assertNotNull($captured);
		self::assertStringContainsString('n8n.reference/v1', (string)$captured);
	}

	public function testPullResolvesIgnoreTagSoWorkflowIsNotPulled(): void {
		// An n8n:ignore workflow is never written — no file is created for it.
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->expects(self::never())->method('newFile');

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$this->n8n->method('listWorkflows')->willReturn([
			'data' => [['id' => 'wf-x', 'name' => 'X', 'tags' => [['id' => 'i', 'name' => OwnershipTags::TAG_IGNORE]]]],
			'nextCursor' => null,
		]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC));

		self::assertSame(1, $res['processed']);
		self::assertSame(0, $res['succeeded']);
		self::assertSame(0, $res['pruned']);
	}

	public function testPushOneSkipsAPerFileLinkOverrideInASyncMapping(): void {
		$syncFile = $this->file(1, 'A.n8n.json');
		$linkOverride = $this->file(2, 'B.n8n.json'); // a link file inside a sync mapping

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$syncFile, $linkOverride]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);

		$this->metadata->method('read')->willReturnCallback(static function (int $fileId): ?array {
			return match ($fileId) {
				1 => [WorkflowMetadata::KEY_ID => 'wf-1', WorkflowMetadata::KEY_MODE => Mapping::MODE_SYNC],
				2 => [WorkflowMetadata::KEY_ID => 'wf-2', WorkflowMetadata::KEY_MODE => Mapping::MODE_LINK],
				default => null,
			};
		});
		$this->push->method('push')->willReturn(true);

		$res = $this->service->pushOne($this->mapping(Mapping::MODE_SYNC));

		// Only the sync file is a push candidate; the link override is skipped.
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

		$this->metadata->method('read')->willReturnCallback(static function (int $fileId): ?array {
			return match ($fileId) {
				20 => [
					WorkflowMetadata::KEY_ID => 'wf-ign',
					WorkflowMetadata::KEY_MODE => WorkflowMetadata::MODE_IGNORED,
					WorkflowMetadata::KEY_MAPPING => 'map-alpha',
				],
				default => null,
			};
		});
		$this->n8n->method('listWorkflows')->willReturn(['data' => [], 'nextCursor' => null]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(0, $res['pruned']);
	}
}
