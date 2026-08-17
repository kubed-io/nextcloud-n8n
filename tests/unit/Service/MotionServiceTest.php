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
use OCA\N8nSync\Service\PushService;
use OCA\N8nSync\Service\ReplacedByMoveStore;
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
	private PushService $push;
	private TagSyncService $tagSync;
	private WorkflowMetadata $metadata;
	private ReplacedByMoveStore $replaced;
	private MotionService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->createService = $this->createMock(CreateService::class);
		$this->push = $this->createMock(PushService::class);
		$this->tagSync = $this->createMock(TagSyncService::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		// A REAL STORE, not a double: it is a request-scoped array with no
		// collaborators, so stubbing it would only restate its two lines.
		$this->replaced = new ReplacedByMoveStore();

		// SyncGuard just brackets the callback in enter/leave — a stub that runs it inline.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$this->service = new MotionService(
			$this->n8n,
			$this->createService,
			$this->push,
			$this->tagSync,
			$this->metadata,
			$guard,
			$this->replaced,
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

		// KEYED BY ID, NOT `->with($siblingFileId)`. A parameter constraint on a shared
		// `read` makes every OTHER read a failed expectation, and `moveIn` now reads the
		// INCOMING file's metadata too (to decide whether its body is ahead of n8n). The
		// incoming file answers null here, which is the "nothing to push" arm.
		$this->metadata->method('read')->willReturnCallback(
			static fn (int $fileId): ?ManagedFile => $fileId === $siblingFileId
				? new ManagedFile($siblingWorkflowId, '', '', '', '')
				: null,
		);
		return $node;
	}

	/** An arriving file whose bytes are $content and whose stamped state is $managed. */
	private function arrivingFile(int $id, string $content, ManagedFile $managed): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getContent')->willReturn($content);
		$this->metadata->method('read')->willReturn($managed);
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
			WorkflowMetadata::KEY_ID => 'wf1',
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
			WorkflowMetadata::KEY_ID => 'wf1',
			WorkflowMetadata::KEY_MODE => Mapping::MODE_SYNC,
			WorkflowMetadata::KEY_MAPPING => 'map-beta',
		]);

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}

	// ── moveIn: the body that arrived ────────────────────────────────────────────

	/**
	 * A FILE THAT COMES BACK CARRYING CHANGES MUST SEND THEM, and this is the test
	 * that says why the push exists at all.
	 *
	 * Unarchiving settles identity and says nothing about content. A file sitting
	 * outside every mapping is editable and is never pushed, so it can return holding
	 * a body n8n has never seen. Stamp it `sync` and stop, and the next pull finds n8n
	 * authoritative and overwrites the file with the older body — the user's edit
	 * survives the move and is destroyed by a scheduled job minutes later.
	 *
	 * It is also what makes "keep the new version" mean anything: that answer is a
	 * statement about WHICH BODY WINS, and a win that never reaches n8n is not one.
	 */
	public function testMoveInPushesABodyN8nHasNotSeen(): void {
		$node = $this->arrivingFile(9, '{"nodes":[]}', new ManagedFile('wf1', 'sync', '', 'a-stale-hash', 'map-beta'));
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf1');
		$this->createService->expects(self::never())->method('createForFile');
		$this->push->expects(self::once())->method('push')->with(self::identicalTo($node));

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}

	/**
	 * THE ORDINARY MOVE-OUT-AND-BACK STAYS A PURE IDENTITY OPERATION. The file is
	 * byte-for-byte the mirror the app last wrote, so there is nothing to tell n8n and
	 * a write here would be churn — a new versionId, a new `updatedAt`, and every
	 * mirror's clock moved for a gesture that changed nothing.
	 *
	 * `n8n_syncedHash` is the existing memory of what the two sides last agreed on, so
	 * the gate is the one already there rather than a new one.
	 */
	public function testMoveInDoesNotPushWhenTheFileIsStillTheMirror(): void {
		$content = '{"nodes":[]}';
		$node = $this->arrivingFile(9, $content, new ManagedFile('wf1', 'sync', '', sha1($content), 'map-beta'));
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf1');
		$this->push->expects(self::never())->method('push');

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}

	/**
	 * AN OVERWRITE REPLACES CONTENTS, NOT IDENTITY — the rule stated where it is
	 * easiest to get wrong, with two DIFFERENT workflows.
	 *
	 * The mapped folder held a file bound to `wf-dest`; a file bound to `wf-src` is
	 * moved in over it. Let the arrival keep `wf-src` and the folder now mirrors it
	 * while `wf-dest` is still live, still carrying the mapping's tag, and no longer
	 * has a file — so the next pull writes it back beside the file that replaced it
	 * and the mapping has quietly forked.
	 *
	 * `wf-src` is deliberately NOT touched here: not deleted, not archived, not
	 * re-minted. It is simply a workflow whose file is gone.
	 */
	public function testMoveInAdoptsTheWorkflowItOverwrote(): void {
		$node = $this->arrivingFile(9, '{"nodes":[]}', new ManagedFile('wf-src', 'sync', '', 'stale', 'map-beta'));
		$this->replaced->mark(77, 9, 'wf-dest');

		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf-dest');
		$this->n8n->expects(self::never())->method('archiveWorkflow');
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write')->with(9, [
			WorkflowMetadata::KEY_ID => 'wf-dest',
			WorkflowMetadata::KEY_MODE => Mapping::MODE_SYNC,
			WorkflowMetadata::KEY_MAPPING => 'map-beta',
		]);

		$this->service->moveIn($node, 'wf-src', $this->mapping('map-beta'));
	}

	/**
	 * AN OVERWRITE ARRIVES WITH THE WORKFLOW ALREADY LIVE, because suppressing the
	 * archive is the whole point — and n8n answers an unarchive of a live workflow
	 * with an error. Treating that as a failure aborted the move-in before it could
	 * stamp or push, which is exactly how the first CI run of this behaviour failed.
	 * The mirror of `moveOut`, where a 404 on archive is likewise idempotent success.
	 */
	public function testMoveInTreatsAnAlreadyLiveWorkflowAsUnarchived(): void {
		$node = $this->arrivingFile(9, '{"nodes":[]}', new ManagedFile('wf1', 'sync', '', 'stale', 'map-beta'));
		$this->n8n->method('unarchiveWorkflow')
			->willThrowException(new N8nApiException('Workflow is not archived.', 400));
		$this->n8n->method('getWorkflow')->willReturn(['id' => 'wf1', 'isArchived' => false]);
		$this->createService->expects(self::never())->method('createForFile');
		$this->metadata->expects(self::once())->method('write');
		$this->push->expects(self::once())->method('push');

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
	}

	/**
	 * A FAILED PUSH DOES NOT UNDO A MOVE THAT ALREADY HAPPENED. The file has moved and
	 * its identity is settled; throwing would answer the client with a 500 for a
	 * gesture that succeeded, and would not put the body back. `syncedHash` is left
	 * stale, so the next save or bulk push retries on its own.
	 */
	public function testMoveInSurvivesAFailedPush(): void {
		$node = $this->arrivingFile(9, '{"nodes":[]}', new ManagedFile('wf1', 'sync', '', 'a-stale-hash', 'map-beta'));
		$this->n8n->method('unarchiveWorkflow');
		$this->push->method('push')->willThrowException(new N8nApiException('boom', 500));

		$this->service->moveIn($node, 'wf1', $this->mapping('map-beta'));
		self::assertTrue(true, 'moveIn swallowed the push failure');
	}
}
