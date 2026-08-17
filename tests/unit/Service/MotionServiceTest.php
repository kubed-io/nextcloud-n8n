<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
use OCA\N8nSync\Service\CreateService;
use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MotionService;
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TagSyncService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the {@see MotionService} move lifecycle (saga Ch2 §14.2).
 *
 * The load-bearing rules: a `sync` move-OUT archives the workflow and re-stamps the
 * file `unmapped` (id preserved); a move-IN unarchives the SAME workflow and re-stamps
 * `sync`; a hard-deleted workflow (404 on unarchive) falls back to a fresh create. n8n
 * 404s on archive are idempotent success; other errors bubble. n8n/metadata/tag/create
 * collaborators are `final`, so the unit bootstrap's `dg/bypass-finals` is what lets the
 * mock builder double them. `File` + `SyncGuard` are pure stubs (no behaviour asserted);
 * the rest are mocks with explicit expectations (incl. `never()`) so no PHPUnit notice
 * fires for an un-exercised double.
 */
#[CoversClass(MotionService::class)]
final class MotionServiceTest extends TestCase {
	private N8nClient $n8n;
	private CreateService $createService;
	private TagSyncService $tagSync;
	private WorkflowMetadata $metadata;
	private MotionService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->createService = $this->createMock(CreateService::class);
		$this->tagSync = $this->createMock(TagSyncService::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);

		// SyncGuard just brackets the callback in enter/leave — a stub that runs it inline.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$this->service = new MotionService(
			$this->n8n,
			$this->createService,
			$this->tagSync,
			$this->metadata,
			$guard,
			new NullLogger(),
		);
	}

	private function file(int $id = 42): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	private function mapping(string $id = 'map-beta', string $tag = 'team:beta'): Mapping {
		return Mapping::fromArray(['id' => $id, 'n8n_tag' => $tag, 'team_folder' => 'beta', 'mode' => 'sync']);
	}

	/**
	 * An incoming move-in file (fileId $incomingId) whose landing folder also holds a
	 * single managed sibling (fileId $siblingFileId) carrying workflow $siblingWorkflowId.
	 * Wires `getParent()->getDirectoryListing()` and the sibling's metadata read.
	 */
	private function fileWithSibling(int $incomingId, int $siblingFileId, string $siblingWorkflowId): File {
		$sibling = $this->createStub(File::class);
		$sibling->method('getId')->willReturn($siblingFileId);
		$sibling->method('getName')->willReturn('Mover.n8n');

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$sibling]);

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($incomingId);
		$node->method('getName')->willReturn('Mover-incoming.n8n');
		$node->method('getParent')->willReturn($folder);

		$this->metadata->method('read')->with($siblingFileId)->willReturn(
			new ManagedFile($siblingWorkflowId, '', '', '', ''),
		);
		return $node;
	}

	// ── moveOut ──────────────────────────────────────────────────────────────────

	public function testMoveOutArchivesAndStampsUnmapped(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')->with('wf1');
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write')->with(7, [
			WorkflowMetadata::KEY_MODE => WorkflowMetadata::MODE_UNMAPPED,
			WorkflowMetadata::KEY_MAPPING => '',
		]);

		$this->service->moveOut($this->file(7), 'wf1');
	}

	public function testMoveOutSwallows404OnArchiveButStillStampsUnmapped(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')
			->willThrowException(new N8nApiException('gone', 404));
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write')->with(7, [
			WorkflowMetadata::KEY_MODE => WorkflowMetadata::MODE_UNMAPPED,
			WorkflowMetadata::KEY_MAPPING => '',
		]);

		$this->service->moveOut($this->file(7), 'wf1');
	}

	public function testMoveOutRethrows500AndDoesNotStamp(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')
			->willThrowException(new N8nApiException('boom', 500));
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::never())->method('write');

		$this->expectException(N8nApiException::class);
		$this->service->moveOut($this->file(7), 'wf1');
	}

	// ── rebind ───────────────────────────────────────────────────────────────────

	/**
	 * THE ORDER IS THE POINT. The new mapping's tag goes on before the old one comes
	 * off, so the workflow is never momentarily a member of no mapping — a pull landing
	 * in that window would decide its file was stale and trash it.
	 */
	public function testRebindAddsTheNewTagBeforeDroppingTheOld(): void {
		$seen = [];
		$this->tagSync->expects(self::once())->method('addMappingTag')
			->willReturnCallback(function (string $id, string $tag) use (&$seen): void {
				$seen[] = 'add:' . $tag;
			});
		$this->tagSync->expects(self::once())->method('dropSourceTag')
			->willReturnCallback(function (string $id, string $tag) use (&$seen): void {
				$seen[] = 'drop:' . $tag;
			});

		$this->service->rebind($this->file(11), 'wf1', $this->mapping('map-src', 'team:src'), $this->mapping('map-dst', 'team:dst'));

		self::assertSame(['add:team:dst', 'drop:team:src'], $seen);
	}

	public function testRebindStampsTheMappingItLandedIn(): void {
		$this->metadata->expects(self::once())->method('write')->with(11, [
			WorkflowMetadata::KEY_MODE => 'sync',
			WorkflowMetadata::KEY_MAPPING => 'map-dst',
		]);
		$this->createService->expects(self::never())->method('createForFile');

		$this->service->rebind($this->file(11), 'wf1', $this->mapping('map-src', 'team:src'), $this->mapping('map-dst', 'team:dst'));
	}

	/** The workflow is gone from n8n, so there is no membership to move — make one. */
	public function testRebindCreatesFreshWhenTheWorkflowIsGone(): void {
		$this->tagSync->method('addMappingTag')
			->willThrowException(new N8nApiException('not found', 404));
		$this->createService->expects(self::once())->method('createForFile');
		$this->metadata->expects(self::never())->method('write');

		$this->service->rebind($this->file(11), 'wf1', $this->mapping('map-src', 'team:src'), $this->mapping('map-dst', 'team:dst'));
	}

	/**
	 * A REAL n8n FAILURE MUST NOT LEAVE THE FILE CLAIMING THE NEW MAPPING. Stamping
	 * after a failed retag would tell Nextcloud the move landed while n8n still has the
	 * workflow in the old mapping — the two sides disagreeing, silently.
	 */
	public function testRebindRethrows500AndDoesNotStamp(): void {
		$this->tagSync->method('addMappingTag')
			->willThrowException(new N8nApiException('boom', 500));
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::never())->method('write');

		$this->expectException(N8nApiException::class);
		$this->service->rebind($this->file(11), 'wf1', $this->mapping('map-src', 'team:src'), $this->mapping('map-dst', 'team:dst'));
	}

	/**
	 * THE TAG LIVES ON THREE SURFACES. n8n is settled by the two calls above; the
	 * Nextcloud pill is settled here, inline, because writing a pill takes no file lock.
	 * Leaving it would show the user the folder the file came from.
	 */
	public function testRebindSwapsTheMappingPill(): void {
		$this->tagSync->expects(self::once())->method('swapMappingPill')
			->with(11, 'team:src', 'team:dst')->willReturn(['team:dst']);

		$this->service->rebind($this->file(11), 'wf1', $this->mapping('map-src', 'team:src'), $this->mapping('map-dst', 'team:dst'));
	}

	/**
	 * n8n HAS ALREADY BEEN WRITTEN by the time the pill is touched, so a pill failure is
	 * not a failed rebind — it is one lagging surface that the next pull corrects. Throwing
	 * would tell MotionListener the move failed when it succeeded, and the stamp is
	 * already down.
	 */
	public function testRebindSurvivesAPillFailure(): void {
		$this->tagSync->method('swapMappingPill')->willThrowException(new \RuntimeException('tag backend down'));
		$this->metadata->expects(self::once())->method('write');
		$this->createService->expects(self::never())->method('createForFile');

		$this->service->rebind($this->file(11), 'wf1', $this->mapping('map-src', 'team:src'), $this->mapping('map-dst', 'team:dst'));
	}

	// ── moveIn ───────────────────────────────────────────────────────────────────

	public function testMoveInUnarchivesAndStampsSync(): void {
		$node = $this->file(9);
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf1');
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write')->with(9, [
			WorkflowMetadata::KEY_MODE => Mapping::MODE_SYNC,
			WorkflowMetadata::KEY_MAPPING => 'map-beta',
		]);

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}

	public function testMoveInFallsBackToCreateWhenWorkflowHardDeleted(): void {
		$node = $this->file(9);
		$map = $this->mapping('map-beta');
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')
			->willThrowException(new N8nApiException('not found', 404));
		// createForFile owns its own id/mode/mapping stamp — we don't double-write here.
		$this->createService->expects(self::once())->method('createForFile')->with($node, $map);
		$this->metadata->expects(self::never())->method('write');

		$this->service->moveIn($node, 'wf1', $map);
	}

	public function testMoveInRethrows500AndDoesNotCreateOrStamp(): void {
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')
			->willThrowException(new N8nApiException('boom', 500));
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::never())->method('write');

		$this->expectException(N8nApiException::class);
		$this->service->moveIn($this->file(9), 'wf1', $this->mapping());
	}

	// ── moveIn: duplicate-on-collision (saga §14.19) ──────────────────────────────

	public function testMoveInMintsNewInstanceWhenADuplicateIsAlreadySyncedHere(): void {
		// A sibling in the landing folder already tracks wf1 → the incoming is a
		// duplicate. Mint it as a brand-new instance (createForFile strips the carried
		// id) and leave the existing file + its live workflow untouched: no unarchive,
		// no re-stamp here (createForFile owns its own stamp), no delete.
		$node = $this->fileWithSibling(9, 99, 'wf1');
		$node->expects(self::never())->method('delete');
		$this->createService->expects(self::once())->method('createForFile')
			->with(self::identicalTo($node), self::isInstanceOf(Mapping::class));
		$this->n8n->expects(self::never())->method('unarchiveWorkflow');
		$this->metadata->expects(self::never())->method('write');

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}

	public function testMoveInIgnoresSiblingsWithADifferentIdAndUnarchives(): void {
		// The only sibling carries a DIFFERENT workflow → not a duplicate; the normal
		// unarchive + stamp path runs and no new instance is minted.
		$node = $this->fileWithSibling(9, 99, 'other-wf');
		$node->expects(self::never())->method('delete');
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf1');
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write')->with(9, [
			WorkflowMetadata::KEY_MODE => Mapping::MODE_SYNC,
			WorkflowMetadata::KEY_MAPPING => 'map-beta',
		]);

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}
}
