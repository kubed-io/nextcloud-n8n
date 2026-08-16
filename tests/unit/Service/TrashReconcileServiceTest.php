<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TeamFolderService;
use OCA\N8nSync\Service\TrashControl;
use OCA\N8nSync\Service\TrashedFile;
use OCA\N8nSync\Service\TrashReconcileService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TrashReconcileService} — the Nextcloud trash following n8n's archive in both
 * directions, and the only code in this app that destroys a file the user has not asked
 * it to destroy.
 *
 * The purge tests are weighted accordingly. There is ONE way to reach a purge and a long
 * list of ways to be spared it, and the spared cases are the ones worth the words: each
 * is a distinct reason the app might have thought a workflow was gone when it was not.
 * A false negative costs one more tick of a trash entry; a false positive destroys the
 * last copy of a workflow. The restore half is cheap to get wrong and cheap to fix — its
 * worst failure is a duplicate file — so it is tested for correctness, not for caution.
 *
 * `TrashedFile` carries both operations as closures ({@see TrashControl}), which is
 * exactly what makes this testable without `files_trashbin`: the doubles are flags the
 * closures set, so "was it purged?" is a boolean rather than a mock on an interface that
 * does not exist here.
 */
#[CoversClass(TrashReconcileService::class)]
final class TrashReconcileServiceTest extends TestCase {
	private const MAPPING_ID = 'map-alpha';

	private N8nClient $n8n;
	private WorkflowMetadata $metadata;
	private TrashControl $trash;
	private TeamFolderService $teamFolders;
	/** The node a restore resolves to, so a test can assert the caller got THAT file. */
	private File $restoredNode;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->metadata = $this->createStub(WorkflowMetadata::class);
		$this->trash = $this->createStub(TrashControl::class);
		$this->teamFolders = $this->createStub(TeamFolderService::class);
		$this->teamFolders->method('resolveActorUid')->willReturn('actor');
		$this->restoredNode = $this->createStub(File::class);
	}

	private function service(): TrashReconcileService {
		// SyncGuard only brackets the purge (so the legacy trash hook stays quiet); an
		// inert stub that runs its callback is the whole contract this class needs.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		// The restored file is found again by the id it kept through the trash, so the
		// root folder is wired to answer with one rather than stubbed into silence —
		// otherwise every restore assertion would pass on a null it never noticed.
		$home = $this->createStub(Folder::class);
		$home->method('getFirstNodeById')->willReturn($this->restoredNode);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		return new TrashReconcileService(
			$this->n8n,
			$this->metadata,
			$this->trash,
			$this->teamFolders,
			$root,
			$guard,
			new NullLogger(),
		);
	}

	private function mapping(string $id = self::MAPPING_ID): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'n8n_tag' => 'nextcloud:alpha',
			'team_folder' => 'Automations',
			'mode' => 'sync',
		]);
	}

	/**
	 * Put one file in the trash and hand back the flag its purge sets, so an assertion
	 * reads as "was this entry destroyed?" rather than as a mock expectation.
	 */
	private function trashHolds(string $name, int $fileId = 7): \stdClass {
		$did = new \stdClass();
		$did->purge = false;
		$did->restore = false;
		$this->trash->method('listTrashed')->willReturn([
			new TrashedFile(
				$fileId,
				$name,
				function () use ($did): void {
					$did->purge = true;
				},
				function () use ($did): void {
					$did->restore = true;
				},
			),
		]);
		return $did;
	}

	/** What `WorkflowMetadata::read()` answers for the trashed file. */
	private function stampedWith(?ManagedFile $managed): void {
		$this->metadata->method('read')->willReturn($managed);
	}

	private function managed(string $workflowId, string $mode = Mapping::MODE_SYNC, string $mappingId = self::MAPPING_ID): ManagedFile {
		return new ManagedFile($workflowId, $mode, '', '', $mappingId);
	}

	// ── the one way to be purged ───────────────────────────────────────────────

	/**
	 * The whole feature in one test: the workflow is not in the tag listing, and asking
	 * n8n directly says it does not exist. Both halves of the delete are now real — the
	 * trash mirrors the archive, and emptying either one empties the other.
	 */
	public function testAMirrorWhoseWorkflowNoLongerExistsIsPurged(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1'));
		$this->n8n->method('getWorkflow')->with('wf-1')
			->willThrowException(new N8nApiException('gone', 404));

		self::assertSame(1, $this->service()->reap($this->mapping(), []));
		self::assertTrue($purged->purge, 'the trashed mirror survived a workflow that does not');
	}

	// ── every way to be spared ─────────────────────────────────────────────────

	/**
	 * THE COMMON CASE, AND IT COSTS NOTHING. A mirror is in the trash precisely because
	 * its workflow was archived, and n8n returns archived workflows in the tag listing
	 * the pull has already made — so the id is in `$liveIds` and no API call happens at
	 * all. If this ever regressed, every archived workflow's mirror would be destroyed
	 * on the next tick.
	 */
	public function testAnArchivedWorkflowsMirrorStaysAndIsNeverEvenAskedAbout(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1'));
		$this->n8n->expects(self::never())->method('getWorkflow');

		self::assertSame(0, $this->service()->reap($this->mapping(), ['wf-1' => true]));
		self::assertFalse($purged->purge);
	}

	/**
	 * UNTAGGED IS NOT DELETED. Absent from the tag listing means "gone OR merely
	 * untagged", and those must not share an outcome — which is the entire reason the
	 * direct `getWorkflow` call exists instead of trusting the listing.
	 */
	public function testAWorkflowThatMerelyLostTheTagKeepsItsMirror(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1'));
		$this->n8n->method('getWorkflow')->willReturn(['id' => 'wf-1']);

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	/**
	 * n8n BEING ILL IS NOT PROOF OF ANYTHING. This is the safety property the class is
	 * built around: uncertainty resolves to "leave it", so an outage cannot empty a
	 * user's trash. The entry is still there on the next tick, when n8n can answer.
	 */
	public function testAnUnreachableN8nPurgesNothing(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1'));
		$this->n8n->method('getWorkflow')->willThrowException(new N8nApiException('boom', 500));

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	public function testATransportFailurePurgesNothing(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1'));
		$this->n8n->method('getWorkflow')->willThrowException(new \RuntimeException('connection refused'));

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	/** A file this app never stamped is simply a file the user deleted. */
	public function testAFileWithNoMetadataIsNotOursToDestroy(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith(null);
		$this->n8n->expects(self::never())->method('getWorkflow');

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	/**
	 * A file that left its mapping keeps its id but stops being the mapping's business —
	 * `purge.feature` already says the same thing about the user-driven purge, and the
	 * n8n-driven one must not disagree with it.
	 */
	public function testAnUnmappedFileIsLeftAlone(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1', WorkflowMetadata::MODE_UNMAPPED, ''));
		$this->n8n->expects(self::never())->method('getWorkflow');

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	/**
	 * ONE MAPPING DOES NOT REAP ANOTHER'S. The actor sees every mapped folder's trash in
	 * one listing, so without this filter the first mapping to run would judge every
	 * other mapping's mirrors against ITS tag listing — and find all of them missing.
	 */
	public function testAnotherMappingsMirrorIsNotThisMappingsToJudge(): void {
		$purged = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1', Mapping::MODE_SYNC, 'map-beta'));
		$this->n8n->expects(self::never())->method('getWorkflow');

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	/** Not one of ours by name — no metadata read, no API call, no purge. */
	public function testANonWorkflowFileIsIgnoredOutright(): void {
		$purged = $this->trashHolds('holiday-photos.zip');
		$this->n8n->expects(self::never())->method('getWorkflow');

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
		self::assertFalse($purged->purge);
	}

	/**
	 * A purge that fails is counted as what it is — nothing. A Team Folder can be
	 * read-only for the actor, and the entry staying put is the right outcome; what
	 * would be wrong is reporting it as removed.
	 */
	public function testAFailedPurgeIsNotCountedAndDoesNotStopTheRun(): void {
		$this->trash->method('listTrashed')->willReturn([
			new TrashedFile(
				7,
				'Fleet Health.n8n',
				static function (): void {
					throw new \RuntimeException('read-only Team Folder');
				},
				static function (): void {
				},
			),
		]);
		$this->stampedWith($this->managed('wf-1'));
		$this->n8n->method('getWorkflow')->willThrowException(new N8nApiException('gone', 404));

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
	}

	/**
	 * NO ACTOR, NO RUN — and no exception either. `resolveActorUid()` throws on an
	 * instance whose built-in admin group is empty, and a pull must survive that: the
	 * reconcile is a pass inside the pull, not the point of it.
	 */
	public function testNoSyncActorMeansNoTrashToReconcile(): void {
		$teamFolders = $this->createStub(TeamFolderService::class);
		$teamFolders->method('resolveActorUid')->willThrowException(new \RuntimeException('no actor'));
		$this->teamFolders = $teamFolders;

		self::assertSame(0, $this->service()->reap($this->mapping(), []));
	}

	// ── the other direction: a mirror coming back out ──────────────────────────

	/**
	 * The undo half. Unarchiving in n8n puts the workflow back in the tag listing with
	 * no mirror in the folder to match it, and the pull's only other move is to write a
	 * NEW file — leaving the user a fresh copy in the folder and their original in the
	 * trash, both claiming one workflow. Restoring the entry is what makes the file that
	 * comes back the same file.
	 */
	public function testTheMirrorOfAnUnarchivedWorkflowComesBackOutOfTheTrash(): void {
		$did = $this->trashHolds('Fleet Health.n8n');
		$this->stampedWith($this->managed('wf-1'));

		$restored = $this->service()->restoreMirror($this->mapping(), 'wf-1');

		self::assertTrue($did->restore, 'the trashed mirror was left in the trash and a new file written beside it');
		self::assertSame($this->restoredNode, $restored, 'the caller was not handed the file it must now update in place');
	}

	/**
	 * ASKED FOR ONE WORKFLOW, NOT FOR ANY. The trash can hold several of this mapping's
	 * mirrors at once — a folder-wide archive puts them all there — so matching on
	 * "belongs to this mapping" alone would restore whichever happened to be first and
	 * hand the pull a file for a different workflow entirely.
	 */
	public function testAnotherWorkflowsTrashedMirrorIsNotRestored(): void {
		$did = $this->trashHolds('Something Else.n8n');
		$this->stampedWith($this->managed('wf-2'));

		self::assertNull($this->service()->restoreMirror($this->mapping(), 'wf-1'));
		self::assertFalse($did->restore);
	}

	/** Nothing of ours in the trash: the pull writes a fresh mirror, as it always did. */
	public function testWithNothingInTheTrashTheCallerIsToldToWriteAFreshMirror(): void {
		$this->trash->method('listTrashed')->willReturn([]);

		self::assertNull($this->service()->restoreMirror($this->mapping(), 'wf-1'));
	}

	/**
	 * A RESTORE THAT CANNOT HAPPEN IS NOT FATAL. A read-only Team Folder refuses it, and
	 * the right answer is the old behaviour — write a fresh file — rather than a pull
	 * that dies and leaves the whole mapping unsynced over one workflow.
	 */
	public function testAFailedRestoreFallsBackToWritingAFreshMirror(): void {
		$this->trash->method('listTrashed')->willReturn([
			new TrashedFile(
				7,
				'Fleet Health.n8n',
				static function (): void {
				},
				static function (): void {
					throw new \RuntimeException('read-only Team Folder');
				},
			),
		]);
		$this->stampedWith($this->managed('wf-1'));

		self::assertNull($this->service()->restoreMirror($this->mapping(), 'wf-1'));
	}
}
