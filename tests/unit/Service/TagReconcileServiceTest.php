<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TagReconcileService;
use OCA\N8nSync\Service\TagSyncService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see TagReconcileService} — the small orchestrator behind the
 * reactive pill-edit trigger (saga Ch5 §5.6.2, Slice A). It gates on managed+sync,
 * resolves the mapping's protected tag, and runs {@see TagSyncService::reconcilePush}
 * inside the {@see SyncGuard}, best-effort. The merge algebra itself lives (and is
 * tested) in {@see TagSyncServiceTest}; here we pin the orchestration seams.
 *
 * `final` collaborators are doubled via the unit bootstrap's `dg/bypass-finals`.
 */
#[CoversClass(TagReconcileService::class)]
final class TagReconcileServiceTest extends TestCase {
	private MappingService $mappings;
	private WorkflowMetadata $metadata;
	private TagSyncService $tagSync;
	private SyncGuard $guard;
	private TagReconcileService $service;

	protected function setUp(): void {
		$this->mappings = $this->createStub(MappingService::class);
		$this->metadata = $this->createStub(WorkflowMetadata::class);
		$this->tagSync = $this->createMock(TagSyncService::class);
		// Real guard so "runs inside the guard" is verifiable from the reconcile call.
		$this->guard = new SyncGuard();
		$this->service = new TagReconcileService(
			$this->mappings,
			$this->metadata,
			$this->tagSync,
			$this->guard,
			new NullLogger(),
		);
	}

	private function node(int $id = 1): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	private function managed(string $mode, string $mappingId = 'map-a'): ManagedFile {
		return new ManagedFile('wf-1', $mode, '', '', $mappingId, '');
	}

	private function mapping(string $tag): Mapping {
		return new Mapping('map-a', $tag, 'folder', ['admin'], Mapping::MODE_SYNC, false);
	}

	// ── gating ───────────────────────────────────────────────────────────────

	public function testSkipsWhenNoMetadataRecord(): void {
		$this->metadata->method('read')->willReturn(null);
		$this->tagSync->expects(self::never())->method('reconcilePush');

		self::assertFalse($this->service->reconcileFile($this->node()));
	}

	public function testSkipsUnmanagedFile(): void {
		// workflowId '' → not managed.
		$this->metadata->method('read')->willReturn(new ManagedFile('', Mapping::MODE_SYNC, '', '', '', ''));
		$this->tagSync->expects(self::never())->method('reconcilePush');

		self::assertFalse($this->service->reconcileFile($this->node()));
	}

	public function testSkipsLinkFile(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_LINK));
		$this->tagSync->expects(self::never())->method('reconcilePush');

		self::assertFalse($this->service->reconcileFile($this->node()));
	}

	// ── protected-tag resolution ───────────────────────────────────────────────

	public function testReconcilesSyncFileWithMappingTagProtected(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));

		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(7, $managed, ['flows']);

		self::assertTrue($this->service->reconcileFile($this->node(7)));
	}

	public function testProtectedIsEmptyWhenMappingMissing(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'gone');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn(null);

		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(1, $managed, []);

		self::assertTrue($this->service->reconcileFile($this->node()));
	}

	public function testProtectedIsEmptyWhenMappingIdBlank(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, '');
		$this->metadata->method('read')->willReturn($managed);
		// A blank mapping id must not even hit the mapping lookup.
		$this->mappings->expects(self::never())->method('getById');

		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(1, $managed, []);

		self::assertTrue($this->service->reconcileFile($this->node()));
	}

	// ── guard + error handling ─────────────────────────────────────────────────

	public function testReconcileRunsInsideTheGuard(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));

		$seen = false;
		$this->tagSync->method('reconcilePush')->willReturnCallback(function () use (&$seen): void {
			// The pills this reconcile writes re-fire tag events; they must land with
			// the guard active so the listener bails. Prove the bracket is on here.
			$seen = $this->guard->active();
		});

		$this->service->reconcileFile($this->node());

		self::assertTrue($seen, 'reconcilePush did not run inside an active SyncGuard');
		self::assertFalse($this->guard->active(), 'the guard was not released after the reconcile');
	}

	public function testSwallowsReconcileFailure(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('reconcilePush')->willThrowException(new \RuntimeException('n8n 500'));

		// A tag hiccup is logged, not thrown — the user's pill click already landed.
		self::assertTrue($this->service->reconcileFile($this->node()));
		self::assertFalse($this->guard->active(), 'the guard leaked after a failing reconcile');
	}
}
