<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\BackgroundJob\ReconcileNameJob;
use OCA\N8nSync\Service\CopyService;
use OCA\N8nSync\Service\CreateService;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TagSyncService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see CopyService} (saga Ch2 §14.2 `copy.feature`).
 *
 * The load-bearing rules: a copy ALWAYS strips its inherited identity (metadata +
 * ownership tags wiped) wherever it lands; a copy that landed inside a mapping is
 * then registered as a NEW workflow via {@see CreateService::createForFile}; a copy
 * outside any mapping is left a plain, untracked file (no create). All collaborators
 * are `final`, so the unit bootstrap's `dg/bypass-finals` is what lets the mock
 * builder double them. `File` + `SyncGuard` are pure stubs; the rest are mocks with
 * explicit expectations (incl. `never()`) so no PHPUnit notice fires for an
 * un-exercised double.
 */
#[CoversClass(CopyService::class)]
final class CopyServiceTest extends TestCase {
	private CreateService $createService;
	private MappingService $mappings;
	private WorkflowMetadata $metadata;
	private IJobList $jobList;
	private CopyService $service;

	protected function setUp(): void {
		$this->createService = $this->createMock(CreateService::class);
		$this->mappings = $this->createMock(MappingService::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);

		// SyncGuard just brackets the callback in enter/leave — a stub that runs it inline.
		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$this->jobList = $this->createMock(IJobList::class);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new CopyService(
			$this->createService,
			$this->mappings,
			$this->metadata,
			$this->createStub(TagSyncService::class),
			$guard,
			$this->jobList,
			$session,
			new NullLogger(),
		);
	}

	private function file(int $id = 42, string $path = '/admin/files/alpha/wf.n8n.json'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn($path);
		return $node;
	}

	private function mapping(string $id = 'map-alpha'): Mapping {
		return Mapping::fromArray(['id' => $id, 'n8n_tag' => 'nextcloud:alpha', 'team_folder' => 'alpha', 'mode' => 'sync']);
	}

	public function testCopyIntoMappingStripsIdentityAndCreatesNewWorkflow(): void {
		$node = $this->file(7);
		$mapping = $this->mapping();

		$this->metadata->expects(self::once())->method('clear')->with(7);
		$this->mappings->expects(self::once())->method('resolveForPath')->willReturn($mapping);
		// TRUE, NOT DEFAULTED. The flag is what tells CreateService that Nextcloud just
		// named this file, so the workflow it makes in n8n wears the copy's name rather
		// than the one it was copied from.
		$this->createService->expects(self::once())->method('createForFile')->with($node, $mapping, true);

		$this->service->onCopy($node);
	}

	public function testCopyOutsideMappingStripsIdentityAndDoesNotCreate(): void {
		$node = $this->file(7);

		$this->metadata->expects(self::once())->method('clear')->with(7);
		$this->mappings->expects(self::once())->method('resolveForPath')->willReturn(null);
		$this->createService->expects(self::never())->method('createForFile');
		$this->jobList->expects(self::never())->method('add');

		$this->service->onCopy($node);
	}

	/**
	 * THE NAME NEXTCLOUD PICKED HAS TO REACH THE FILE, and the copy's own hook cannot
	 * put it there: Nextcloud still holds locks on the target while the copy events run,
	 * so `putContent()` here throws. So the work is handed to {@see ReconcileNameJob},
	 * which runs once they are gone.
	 *
	 * `name_from_filename` because a copy IS a naming: Nextcloud just gave this file a
	 * name, exactly as a rename does, and in both cases the filename is the authority.
	 */
	public function testACopyHandsItsNameToTheReconcileJob(): void {
		$this->mappings->method('resolveForPath')->willReturn($this->mapping());
		$this->jobList->expects(self::once())->method('add')->with(
			ReconcileNameJob::class,
			['fileId' => 7, 'userId' => 'alice', 'action' => 'name_from_filename'],
		);

		$this->service->onCopy($this->file(7));
	}
}
