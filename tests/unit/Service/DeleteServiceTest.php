<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
use OCA\N8nSync\Service\CreateService;
use OCA\N8nSync\Service\DeleteService;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\N8nClient;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the {@see DeleteService} rule table (saga Ch2 §14, mode = sync|link).
 *
 * The mode-based branching is the load-bearing part: `sync` archives/deletes/
 * unarchives the n8n workflow; `link` only adds/removes the mapping tag and never
 * touches the workflow itself. {@see N8nClient} is `final` — the unit bootstrap's
 * `dg/bypass-finals` is what lets the mock builder double it.
 */
#[CoversClass(DeleteService::class)]
final class DeleteServiceTest extends TestCase {
	private N8nClient $n8n;
	private CreateService $create;
	private DeleteService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->create = $this->createMock(CreateService::class);
		$this->service = new DeleteService($this->n8n, $this->create, new NullLogger());
	}

	private function linkMapping(string $tag = 'team:links'): Mapping {
		return Mapping::fromArray(['n8n_tag' => $tag, 'team_folder' => 'f', 'mode' => 'link']);
	}

	private function syncMapping(string $tag = 'team:alpha'): Mapping {
		return Mapping::fromArray(['n8n_tag' => $tag, 'team_folder' => 'f', 'mode' => 'sync']);
	}

	/** The file coming back out of the trash — only its id is ever read here. */
	private function restoredFile(int $id = 42): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	// ── softDelete ─────────────────────────────────────────────────────────────

	public function testSoftDeleteSyncArchives(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')->with('wf1');
		$this->n8n->expects(self::never())->method('deleteWorkflow');
		$this->service->softDelete('wf1', Mapping::MODE_SYNC, null);
	}

	public function testSoftDeleteLinkUntagsAndNeverArchives(): void {
		$this->n8n->expects(self::never())->method('archiveWorkflow');
		$this->n8n->method('getWorkflow')->with('wf1')->willReturn([
			'tags' => [
				['id' => 't-link', 'name' => 'team:links'],
				['id' => 't-keep', 'name' => 'other'],
			],
		]);
		// The mapping tag is dropped; the unrelated tag is preserved.
		$this->n8n->expects(self::once())->method('setWorkflowTags')->with('wf1', ['t-keep']);
		$this->service->softDelete('wf1', Mapping::MODE_LINK, $this->linkMapping());
	}

	public function testSoftDeleteLinkWithNoMappingIsNoop(): void {
		$this->n8n->expects(self::never())->method('archiveWorkflow');
		$this->n8n->expects(self::never())->method('getWorkflow');
		$this->n8n->expects(self::never())->method('setWorkflowTags');
		$this->service->softDelete('wf1', Mapping::MODE_LINK, null);
	}

	// ── hardDelete ─────────────────────────────────────────────────────────────

	public function testHardDeleteSyncDeletes(): void {
		$this->n8n->expects(self::once())->method('deleteWorkflow')->with('wf1');
		$this->service->hardDelete('wf1', Mapping::MODE_SYNC);
	}

	public function testHardDeleteLinkIsNoop(): void {
		$this->n8n->expects(self::never())->method('deleteWorkflow');
		$this->service->hardDelete('wf1', Mapping::MODE_LINK);
	}

	// ── restore ────────────────────────────────────────────────────────────────

	public function testRestoreSyncUnarchives(): void {
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf1');
		$this->create->expects(self::never())->method('createForFile');
		$this->service->restore($this->restoredFile(), 'wf1', Mapping::MODE_SYNC, null);
	}

	public function testRestoreLinkRetags(): void {
		$this->n8n->expects(self::never())->method('unarchiveWorkflow');
		$this->n8n->method('getWorkflow')->with('wf1')->willReturn(['tags' => []]);
		$this->n8n->method('ensureTag')->with('team:links')->willReturn('t-link');
		$this->n8n->expects(self::once())->method('setWorkflowTags')->with('wf1', ['t-link']);
		$this->service->restore($this->restoredFile(), 'wf1', Mapping::MODE_LINK, $this->linkMapping());
	}

	// ── restore, when the world moved while the file sat in the trash ──────────

	/**
	 * THE ONE PLACE A 404 IS NOT SUCCESS. Every other step in this class is removing
	 * something, so "it is already gone" finishes the job. A restore is bringing
	 * something back, and swallowing the 404 handed the user a file in a mapped folder
	 * whose workflow does not exist — the exact contradiction the app then acts on.
	 */
	public function testRestoreCreatesTheWorkflowAgainWhenN8nNoLongerHasIt(): void {
		$node = $this->restoredFile();
		$mapping = $this->syncMapping();
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('wf1')
			->willThrowException(new N8nApiException('gone', 404));
		$this->create->expects(self::once())->method('createForFile')->with($node, $mapping);

		$this->service->restore($node, 'wf1', Mapping::MODE_SYNC, $mapping);
	}

	/**
	 * NO MAPPING, NO CREATE. There is no tag to create the workflow under and no folder
	 * binding to stamp on the file, so the file comes back as a plain document holding
	 * its old id — the same state as any file outside every mapping. Minting a workflow
	 * with no home would be worse than leaving it detached.
	 */
	public function testRestoreWithNoMappingCannotRecreateAndDoesNotTry(): void {
		$this->n8n->method('unarchiveWorkflow')->willThrowException(new N8nApiException('gone', 404));
		$this->create->expects(self::never())->method('createForFile');

		$this->service->restore($this->restoredFile(), 'wf1', Mapping::MODE_SYNC, null);
	}

	/**
	 * A 500 IS NOT A MISSING WORKFLOW. n8n being ill says nothing about whether the
	 * workflow is there, and creating on that evidence would duplicate a workflow that
	 * still exists. It bubbles instead, and the listener logs and leaves the file back
	 * where the user put it.
	 */
	public function testRestoreDoesNotRecreateWhenN8nMerelyFails(): void {
		$this->n8n->method('unarchiveWorkflow')->willThrowException(new N8nApiException('boom', 500));
		$this->create->expects(self::never())->method('createForFile');

		$this->expectException(N8nApiException::class);
		$this->service->restore($this->restoredFile(), 'wf1', Mapping::MODE_SYNC, $this->syncMapping());
	}

	// ── idempotency ────────────────────────────────────────────────────────────

	public function testSyncArchive404IsSwallowed(): void {
		// A missing workflow (404) is treated as success — nothing bubbles out.
		$this->n8n->expects(self::once())->method('archiveWorkflow')
			->willThrowException(new N8nApiException('gone', 404));
		$this->service->softDelete('wf1', Mapping::MODE_SYNC, null);
	}

	public function testSyncArchive500Throws(): void {
		$this->n8n->expects(self::once())->method('archiveWorkflow')
			->willThrowException(new N8nApiException('boom', 500));
		$this->expectException(N8nApiException::class);
		$this->service->softDelete('wf1', Mapping::MODE_SYNC, null);
	}
}
