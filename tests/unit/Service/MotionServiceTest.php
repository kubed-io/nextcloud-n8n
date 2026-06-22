<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
use OCA\N8nSync\Service\CreateService;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MotionService;
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\OwnershipTags;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\Files\File;
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
	private OwnershipTags $tags;
	private MotionService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->createService = $this->createMock(CreateService::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		$this->tags = $this->createMock(OwnershipTags::class);

		// SyncGuard just brackets the callback in enter/leave — a stub that runs it inline.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$this->service = new MotionService(
			$this->n8n,
			$this->createService,
			$this->metadata,
			$this->tags,
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

	// ── moveOut ──────────────────────────────────────────────────────────────────

	public function testMoveOutArchivesAndStampsUnmapped(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')->with('wf1');
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write')->with(7, [
			WorkflowMetadata::KEY_MODE => WorkflowMetadata::MODE_UNMAPPED,
			WorkflowMetadata::KEY_MAPPING => '',
		]);
		$this->tags->expects(self::once())->method('apply')->with(7, WorkflowMetadata::MODE_UNMAPPED);

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
		$this->tags->expects(self::once())->method('apply')->with(7, WorkflowMetadata::MODE_UNMAPPED);

		$this->service->moveOut($this->file(7), 'wf1');
	}

	public function testMoveOutRethrows500AndDoesNotStamp(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')
			->willThrowException(new N8nApiException('boom', 500));
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::never())->method('apply');

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
		$this->tags->expects(self::once())->method('apply')->with(9, Mapping::MODE_SYNC);

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
		$this->tags->expects(self::never())->method('apply');

		$this->service->moveIn($node, 'wf1', $map);
	}

	public function testMoveInRethrows500AndDoesNotCreateOrStamp(): void {
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')
			->willThrowException(new N8nApiException('boom', 500));
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::never())->method('apply');

		$this->expectException(N8nApiException::class);
		$this->service->moveIn($this->file(9), 'wf1', $this->mapping());
	}
}
