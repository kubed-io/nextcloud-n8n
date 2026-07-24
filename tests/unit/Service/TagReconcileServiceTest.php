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
 * Unit tests for {@see TagReconcileService} — the orchestrator behind the reactive
 * tag triggers (saga Ch5 §5.6.2). The **pill** path ({@see reconcileFile}) is LIVE: it
 * gates on managed+sync, resolves the mapping's protected tag, and runs
 * {@see TagSyncService::reconcilePush} inside the {@see SyncGuard} — carrying the pill
 * to n8n and leaving the file body untouched (the body mirror self-heals on pull). The
 * **body** path ({@see reconcileFromBody}) is the DORMANT Slice B engine (saga
 * §5.6.2.3): unwired in production but unit-tested here — it treats the file's JSON
 * `tags` as truth, fast-path-skips an unchanged set, and writes n8n's `{id,name}` back
 * so a bare `{"name":…}` gains its id. The merge algebra itself lives (and is tested)
 * in {@see TagSyncServiceTest}; here we pin the orchestration.
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
		// A mock (not a stub) so the "blank mapping id never hits the lookup" test can
		// assert getById is never called.
		$this->mappings = $this->createMock(MappingService::class);
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

	/**
	 * A File whose getContent returns `$content` and whose putContent records the last
	 * written body into `$written` (by reference) so a body rewrite is assertable.
	 */
	private function fileWith(int $id, string $content, ?string &$written): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getContent')->willReturn($content);
		$node->method('putContent')->willReturnCallback(function (string $c) use (&$written): void {
			$written = $c;
		});
		return $node;
	}

	private function managed(string $mode, string $mappingId = 'map-a', string $syncedTags = ''): ManagedFile {
		return new ManagedFile('wf-1', $mode, '', '', $mappingId, $syncedTags);
	}

	private function mapping(string $tag): Mapping {
		return new Mapping('map-a', $tag, 'folder', ['admin'], Mapping::MODE_SYNC, false);
	}

	// ── gating (pill path) ─────────────────────────────────────────────────────

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

	// ── protected-tag resolution (pill path) ────────────────────────────────────

	public function testReconcilesSyncFileWithMappingTagProtected(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));

		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(7, $managed, ['flows'])
			->willReturn([]);

		self::assertTrue($this->service->reconcileFile($this->node(7)));
	}

	public function testProtectedIsEmptyWhenMappingMissing(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'gone');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn(null);

		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(1, $managed, [])
			->willReturn([]);

		self::assertTrue($this->service->reconcileFile($this->node()));
	}

	public function testProtectedIsEmptyWhenMappingIdBlank(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, '');
		$this->metadata->method('read')->willReturn($managed);
		// A blank mapping id must not even hit the mapping lookup.
		$this->mappings->expects(self::never())->method('getById');

		$this->tagSync->expects(self::once())
			->method('reconcilePush')
			->with(1, $managed, [])
			->willReturn([]);

		self::assertTrue($this->service->reconcileFile($this->node()));
	}

	// ── guard + error handling (pill path) ──────────────────────────────────────

	public function testReconcileRunsInsideTheGuard(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));

		$seen = false;
		$this->tagSync->method('reconcilePush')->willReturnCallback(function () use (&$seen): array {
			// The pills this reconcile writes re-fire tag events; they must land with
			// the guard active so the listener bails. Prove the bracket is on here.
			$seen = $this->guard->active();
			return [];
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

	// ── body path (Slice B) ─────────────────────────────────────────────────────

	public function testReconcileFromBodySkipsUnmanaged(): void {
		$this->metadata->method('read')->willReturn(null);
		$this->tagSync->expects(self::never())->method('reconcilePushFromBody');

		$content = '{"name":"WF","tags":[{"name":"foo"}]}';
		self::assertSame($content, $this->service->reconcileFromBody($this->node(), $content));
	}

	public function testReconcileFromBodyFastPathWhenTagsMatchBaseline(): void {
		// Body carries exactly the baseline set → nothing NC-side changed; no n8n hit.
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '["foo"]');
		$this->metadata->method('read')->willReturn($managed);
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo']);
		$this->tagSync->expects(self::never())->method('reconcilePushFromBody');

		$content = '{"name":"WF","tags":[{"id":"t1","name":"foo"}]}';
		self::assertSame($content, $this->service->reconcileFromBody($this->node(), $content));
	}

	public function testReconcileFromBodyPushesAndFillsTagIds(): void {
		// User added a bare {"name":"foo"} that isn't in the (empty) baseline.
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo']);

		$this->tagSync->expects(self::once())
			->method('reconcilePushFromBody')
			->with(5, $managed, ['foo'], ['flows'])
			->willReturn([['id' => 't1', 'name' => 'foo'], ['id' => 't9', 'name' => 'flows']]);

		$written = null;
		$node = $this->fileWith(5, '{"name":"WF","tags":[{"name":"foo"}]}', $written);
		$final = $this->service->reconcileFromBody($node, '{"name":"WF","tags":[{"name":"foo"}]}');

		self::assertNotNull($written, 'the body was not rewritten with the canonical rows');
		$decoded = json_decode($final, true);
		self::assertSame(
			[['id' => 't9', 'name' => 'flows'], ['id' => 't1', 'name' => 'foo']],
			$decoded['tags'],
			'body should carry n8n rows (with ids), sorted by name',
		);
	}
}
