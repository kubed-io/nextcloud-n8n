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
	private WorkflowMetadata $metadata;
	private MotionService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->createService = $this->createMock(CreateService::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);

		// SyncGuard just brackets the callback in enter/leave — a stub that runs it inline.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$this->service = new MotionService(
			$this->n8n,
			$this->createService,
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

	private function mapping(string $id = 'map-beta'): Mapping {
		return Mapping::fromArray(['id' => $id, 'n8n_tag' => 'team:beta', 'team_folder' => 'beta', 'mode' => 'sync']);
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
