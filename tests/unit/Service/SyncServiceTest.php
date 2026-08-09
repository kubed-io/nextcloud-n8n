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
use OCA\N8nSync\Service\OwnershipTags;
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
			$this->createStub(OwnershipTags::class),
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
