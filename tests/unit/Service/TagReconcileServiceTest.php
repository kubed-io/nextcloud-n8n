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
use OCA\N8nSync\Service\N8nWorkflowBody;
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
 * tag triggers. Both directions are LIVE (saga §5.9):
 *
 *  - the **pill** path ({@see reconcileFile}) gates on managed+sync, resolves the
 *    mapping's protected tag, runs {@see TagSyncService::reconcilePush} inside the
 *    {@see SyncGuard}, and then WRITES the file's `tags` array so the body cannot lag;
 *  - the **body** path ({@see reconcileFromBody}) treats the file's JSON `tags` as the
 *    Nextcloud-side truth for that save, and leaves the file exactly as typed.
 *
 * The two tests worth reading first are `testPillEditWritesTheCanonicalRowsIntoTheBody`
 * and `testReconcileFromBodyIsFreeWhenTheBodyAgreesWithThePills`. Together they are the
 * whole design: the lockstep removes the only cause of a stale body, which is what lets
 * the body path compare against the PILLS instead of the baseline — and comparing
 * against the baseline is what made this feature undecidable twice.
 *
 * The merge algebra itself lives (and is tested) in {@see TagSyncServiceTest}; here we
 * pin the orchestration.
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

	// ── the lockstep: a pill edit keeps the body in step ────────────────────────

	/**
	 * THE CHANGE THAT MAKES THE THIRD DIRECTION POSSIBLE. A pill edit used to leave
	 * the file body alone, which made the body the only surface that could go stale —
	 * and the only cause of it. That staleness is what made a body-tag edit
	 * undecidable, so the fix removes the cause rather than tracking it (saga §5.9).
	 */
	public function testPillEditWritesTheCanonicalRowsIntoTheBody(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('reconcilePush')->willReturn([
			['id' => 't1', 'name' => 'prod'],
			['id' => 't9', 'name' => 'flows'],
		]);

		$written = null;
		$node = $this->fileWith(5, '{"name":"WF","tags":[]}', $written);
		self::assertTrue($this->service->reconcileFile($node));

		self::assertNotNull($written, 'a pill edit must keep the body in step');
		$decoded = json_decode($written, true);
		self::assertSame(
			[['id' => 't9', 'name' => 'flows'], ['id' => 't1', 'name' => 'prod']],
			$decoded['tags'],
			'the body should carry n8n rows with ids, sorted by name for a stable diff',
		);
	}

	/**
	 * RESERVED MARKERS MUST NOT REACH THE BODY. `reconcilePush()` returns the final n8n
	 * set, which deliberately re-sends any `n8n:*` marker the workflow already had —
	 * correct for n8n (setWorkflowTags is a full replace) and wrong for the file. The
	 * body is CONTENT, and it is the one portable surface: a reserved marker written
	 * here would travel with the file and seed itself as a content tag on adoption.
	 */
	public function testPillEditNeverWritesReservedTagsIntoTheBody(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('reconcilePush')->willReturn([
			['id' => 't1', 'name' => 'prod'],
			['id' => 't7', 'name' => 'n8n:ignore'],
			['id' => 't9', 'name' => 'flows'],
		]);

		$written = null;
		$node = $this->fileWith(5, '{"name":"WF","tags":[]}', $written);
		$this->service->reconcileFile($node);

		self::assertNotNull($written);
		$names = array_column(json_decode($written, true)['tags'], 'name');
		self::assertSame(['flows', 'prod'], $names, 'a reserved marker leaked into the file body');
	}

	/** A pill toggle resolving to the same set must not churn the file. */
	public function testPillEditDoesNotRewriteAnUnchangedBody(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('reconcilePush')->willReturn([['id' => 't9', 'name' => 'flows']]);

		$body = json_encode(['name' => 'WF', 'tags' => [['id' => 't9', 'name' => 'flows']]], N8nWorkflowBody::JSON_PRETTY);
		$written = null;
		$node = $this->fileWith(5, $body, $written);
		$this->service->reconcileFile($node);

		self::assertNull($written, 'an unchanged tag set must not rewrite the file');
	}

	/** A link pointer is not a workflow body; there is nothing to keep in step. */
	public function testPillEditToleratesANonObjectBody(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('reconcilePush')->willReturn([['id' => 't9', 'name' => 'flows']]);

		$written = null;
		$node = $this->fileWith(5, 'not json at all', $written);
		self::assertTrue($this->service->reconcileFile($node));
		self::assertNull($written);
	}

	// ── body path: the third direction ──────────────────────────────────────────

	public function testReconcileFromBodySkipsUnmanaged(): void {
		$this->metadata->method('read')->willReturn(null);
		$this->tagSync->expects(self::never())->method('reconcilePushFromBody');

		self::assertFalse($this->service->reconcileFromBody($this->node(), '{"name":"WF","tags":[{"name":"foo"}]}'));
	}

	/**
	 * THE ACCEPTANCE TEST FOR THE WHOLE DIRECTION. A save that did not touch the tags
	 * must cost nothing and must not push anything — and the comparison is against
	 * the PILLS, not the `n8n_syncedTags` baseline. Comparing to the baseline is what
	 * made this feature undecidable twice: the baseline moves on a pill edit while
	 * the body does not, so an ordinary nodes-only save looked exactly like a
	 * deliberate removal (saga §5.9).
	 */
	public function testReconcileFromBodyIsFreeWhenTheBodyAgreesWithThePills(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '["foo"]');
		$this->metadata->method('read')->willReturn($managed);
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo']);
		$this->tagSync->method('readNcContentTags')->willReturn(['foo']);
		$this->tagSync->expects(self::never())->method('reconcilePushFromBody');

		self::assertFalse($this->service->reconcileFromBody($this->node(), '{"name":"WF","tags":[{"id":"t1","name":"foo"}]}'));
	}

	/**
	 * The stale-body regression, pinned from the other side: the baseline can disagree
	 * with the body while the PILLS agree with it, and that combination must still be
	 * free. Under the old baseline comparison this pushed a bogus tag set.
	 */
	public function testReconcileFromBodyIgnoresTheBaselineEntirely(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '["foo","added-by-a-pill"]');
		$this->metadata->method('read')->willReturn($managed);
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo']);
		$this->tagSync->method('readNcContentTags')->willReturn(['foo']);
		$this->tagSync->expects(self::never())->method('reconcilePushFromBody');

		self::assertFalse($this->service->reconcileFromBody($this->node(), '{"name":"WF","tags":[{"name":"foo"}]}'));
	}

	public function testReconcileFromBodyPushesWhenTheBodyDisagreesWithThePills(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '["foo"]');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		// The user typed a bare {"name":"prod"} into the array; the pills lack it.
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo', 'prod']);
		$this->tagSync->method('readNcContentTags')->willReturn(['foo']);

		$this->tagSync->expects(self::once())
			->method('reconcilePushFromBody')
			->with(5, $managed, ['foo', 'prod'], ['flows'])
			->willReturn([['id' => 't1', 'name' => 'foo'], ['id' => 't2', 'name' => 'prod']]);

		self::assertTrue($this->service->reconcileFromBody($this->node(5), '{"name":"WF","tags":[{"id":"t1","name":"foo"},{"name":"prod"}]}'));
	}

	/**
	 * The body is left EXACTLY as typed — a bare `{"name":"prod"}` is not "corrected"
	 * into n8n's `{id,name}` row on save. The ids arrive on the next pull. Rewriting a
	 * file the user is actively editing is hostile, and it would re-introduce the
	 * re-entrant write this path is built without.
	 */
	public function testReconcileFromBodyNeverRewritesTheFile(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '["foo"]');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo', 'prod']);
		$this->tagSync->method('readNcContentTags')->willReturn(['foo']);
		$this->tagSync->method('reconcilePushFromBody')->willReturn([['id' => 't2', 'name' => 'prod']]);

		$written = null;
		$node = $this->fileWith(5, '{"name":"WF","tags":[{"name":"prod"}]}', $written);
		$this->service->reconcileFromBody($node, '{"name":"WF","tags":[{"name":"prod"}]}');

		self::assertNull($written, 'the body path must not write the file');
	}

	public function testReconcileFromBodySwallowsAnN8nFailure(): void {
		$managed = $this->managed(Mapping::MODE_SYNC, 'map-a', '["foo"]');
		$this->metadata->method('read')->willReturn($managed);
		$this->mappings->method('getById')->willReturn($this->mapping('flows'));
		$this->tagSync->method('contentTagsFromWorkflow')->willReturn(['foo', 'prod']);
		$this->tagSync->method('readNcContentTags')->willReturn(['foo']);
		$this->tagSync->method('reconcilePushFromBody')->willThrowException(new \RuntimeException('n8n 500'));

		self::assertTrue($this->service->reconcileFromBody($this->node(5), '{"name":"WF","tags":[{"name":"prod"}]}'));
		self::assertFalse($this->guard->active(), 'the guard leaked after a failing body reconcile');
	}
}
