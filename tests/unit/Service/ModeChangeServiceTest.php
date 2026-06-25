<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\ModeChangeService;
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\OwnershipTags;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see ModeChangeService} (saga Ch2 §14.2b `mode-change.feature`).
 *
 * The load-bearing rules: only managed files (those with an `n8n_id`) re-mode; the
 * identity (`n8n_id`) is never touched; sync→link rewrites the body to the pointer
 * shape while link→sync rewrites it to the full workflow JSON; the new mode is
 * stamped and {@see OwnershipTags::apply()} is called for the target (which enforces
 * the one-tag mutual exclusivity). Collaborators are `final`, doubled via the unit
 * bootstrap's `dg/bypass-finals`.
 */
#[CoversClass(ModeChangeService::class)]
final class ModeChangeServiceTest extends TestCase {
	private N8nClient $n8n;
	private WorkflowMetadata $metadata;
	private OwnershipTags $tags;
	private MappingService $mappings;
	private ModeChangeService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		$this->tags = $this->createMock(OwnershipTags::class);
		$this->mappings = $this->createMock(MappingService::class);

		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://n8n.example.com');

		$this->service = new ModeChangeService(
			$this->n8n,
			$this->metadata,
			$this->tags,
			$guard,
			$this->mappings,
			$config,
			new NullLogger(),
		);
	}

	/** @param array<string,?string> $meta */
	private function file(int $id = 7): File {
		return $this->createMock(File::class);
	}

	/** @param array<string,?string> $meta */
	private function expectRead(array $meta): void {
		$this->metadata->method('read')->willReturn($meta + [
			'n8n_id' => null, 'n8n_mode' => null, 'n8n_versionId' => null,
			'n8n_syncedHash' => null, 'n8n_mapping' => null,
		]);
	}

	public function testUnmanagedFileIsNoOp(): void {
		$node = $this->file();
		$node->expects(self::never())->method('putContent');
		$this->expectRead(['n8n_id' => null]);
		$this->n8n->expects(self::never())->method('getWorkflow');
		$this->tags->expects(self::never())->method('apply');

		$this->service->changeTo($node, 'link');
	}

	public function testInvalidTargetIsNoOp(): void {
		$node = $this->file();
		$node->expects(self::never())->method('putContent');
		$this->n8n->expects(self::never())->method('getWorkflow');

		$this->service->changeTo($node, 'backup'); // not sync|link
	}

	public function testAlreadyInTargetReassertsTagOnly(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->expects(self::never())->method('putContent'); // no body rewrite
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'link']);
		$this->n8n->expects(self::never())->method('getWorkflow');
		$this->tags->expects(self::once())->method('apply')->with(7, 'link');

		$this->service->changeTo($node, 'link');
	}

	public function testSyncToLinkCollapsesToPointer(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'sync']);
		$this->n8n->expects(self::once())->method('getWorkflow')->with('w1')->willReturn([
			'id' => 'w1', 'name' => 'My Flow', 'versionId' => 'v9',
			'nodes' => [['x' => 1]], 'connections' => [], 'tags' => [['name' => 'team:flows']],
		]);

		$captured = '';
		$node->expects(self::once())->method('putContent')->willReturnCallback(function (string $b) use (&$captured) {
			$captured = $b;
			return true;
		});
		$this->metadata->expects(self::once())->method('write')->with(7, self::callback(
			fn (array $v) => ($v['n8n_mode'] ?? null) === 'link' && ($v['n8n_versionId'] ?? null) === 'v9'
		));
		$this->tags->expects(self::once())->method('apply')->with(7, 'link');

		$this->service->changeTo($node, 'link');

		$decoded = json_decode($captured, true);
		self::assertSame('n8n.reference/v1', $decoded['$schema']);
		self::assertSame('w1', $decoded['id']);
		self::assertSame('https://n8n.example.com/workflow/w1', $decoded['url']);
		self::assertArrayNotHasKey('nodes', $decoded); // collapsed — not the full JSON
	}

	public function testLinkToSyncPullsFullJson(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'link']);
		$workflow = ['id' => 'w1', 'name' => 'My Flow', 'versionId' => 'v9', 'nodes' => [['x' => 1]], 'connections' => []];
		$this->n8n->expects(self::once())->method('getWorkflow')->with('w1')->willReturn($workflow);

		$captured = '';
		$node->expects(self::once())->method('putContent')->willReturnCallback(function (string $b) use (&$captured) {
			$captured = $b;
			return true;
		});
		$this->metadata->expects(self::once())->method('write')->with(7, self::callback(
			fn (array $v) => ($v['n8n_mode'] ?? null) === 'sync'
		));
		$this->tags->expects(self::once())->method('apply')->with(7, 'sync');

		$this->service->changeTo($node, 'sync');

		$decoded = json_decode($captured, true);
		self::assertSame($workflow, $decoded); // full workflow JSON, verbatim
	}

	public function testN8nFetchFailureLeavesFileUntouched(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->expects(self::never())->method('putContent');
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'sync']);
		$this->n8n->method('getWorkflow')->willThrowException(new N8nApiException('boom', 500));
		$this->tags->expects(self::never())->method('apply');

		$this->service->changeTo($node, 'link');
	}

	// ── unmapped guard (no link outside a mapping) ───────────────────────────────

	public function testUnmappedFileRefusesLinkAndReassertsUnmappedTag(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->expects(self::never())->method('putContent'); // body left intact — no data loss
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => WorkflowMetadata::MODE_UNMAPPED]);
		$this->n8n->expects(self::never())->method('getWorkflow'); // never even reach out
		$this->metadata->expects(self::never())->method('write');
		// The manually-added n8n:link is scrubbed; the unmapped pill is re-asserted.
		$this->tags->expects(self::once())->method('apply')->with(7, WorkflowMetadata::MODE_UNMAPPED);

		$this->service->changeTo($node, 'link');
	}

	public function testUnmappedFileRefusesSyncAndReassertsUnmappedTag(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->expects(self::never())->method('putContent');
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => WorkflowMetadata::MODE_UNMAPPED]);
		$this->n8n->expects(self::never())->method('getWorkflow');
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::once())->method('apply')->with(7, WorkflowMetadata::MODE_UNMAPPED);

		$this->service->changeTo($node, 'sync');
	}

	// ── ignored mode (saga §14.8 reserved-tags) ──────────────────────────────────

	public function testChangeToIgnoredArchivesAndKeepsTheFile(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->expects(self::never())->method('putContent'); // body kept as-is
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'sync']);

		// The workflow is archived in n8n; its content is NOT fetched/rewritten.
		$this->n8n->expects(self::once())->method('archiveWorkflow')->with('w1');
		$this->n8n->expects(self::never())->method('getWorkflow');

		// Mode flips to ignored; the sync/link pills are cleared (no ignored pill).
		$this->metadata->expects(self::once())->method('write')->with(7, self::callback(
			fn (array $v) => ($v['n8n_mode'] ?? null) === WorkflowMetadata::MODE_IGNORED
		));
		$this->tags->expects(self::never())->method('apply');
		$this->tags->expects(self::once())->method('clear')->with(7);

		$this->service->changeTo($node, WorkflowMetadata::MODE_IGNORED);
	}

	public function testAlreadyIgnoredIsANoOp(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'ignored']);

		// Nothing to do — no archive, no write, no tag churn.
		$this->n8n->expects(self::never())->method('archiveWorkflow');
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::never())->method('apply');
		$this->tags->expects(self::never())->method('clear');

		$this->service->changeTo($node, WorkflowMetadata::MODE_IGNORED);
	}

	public function testIgnoreArchiveFailureLeavesFileUntouched(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->expects(self::never())->method('putContent');
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'sync']);

		$this->n8n->method('archiveWorkflow')->willThrowException(new N8nApiException('boom', 500));
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::never())->method('clear');

		$this->service->changeTo($node, WorkflowMetadata::MODE_IGNORED);
	}

	// ── un-ignore (saga §14.18 — TagUnassignedEvent restore) ─────────────────────

	public function testUnignoreUnarchivesAndRestoresToMappingDefault(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->method('getPath')->willReturn('/admin/files/links/My Flow.n8n.json');
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'ignored']);

		// The workflow is unarchived, then re-moded to the mapping's default (link).
		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('w1');
		$this->mappings->method('resolveForPath')->willReturn(
			new Mapping('m1', 'team:links', 'links', ['admin'], Mapping::MODE_LINK, false),
		);
		$this->n8n->expects(self::once())->method('getWorkflow')->with('w1')->willReturn([
			'id' => 'w1', 'name' => 'My Flow', 'versionId' => 'v9', 'nodes' => [['x' => 1]], 'connections' => [],
		]);
		$node->expects(self::once())->method('putContent');
		$this->metadata->expects(self::once())->method('write')->with(7, self::callback(
			fn (array $v) => ($v['n8n_mode'] ?? null) === 'link'
		));
		$this->tags->expects(self::once())->method('apply')->with(7, 'link');

		$this->service->unignore($node);
	}

	public function testUnignoreFallsBackToSyncWhenNoMapping(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$node->method('getPath')->willReturn('/admin/files/loose/My Flow.n8n.json');
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'ignored']);

		$this->n8n->expects(self::once())->method('unarchiveWorkflow')->with('w1');
		$this->mappings->method('resolveForPath')->willReturn(null); // outside any mapping
		$this->n8n->expects(self::once())->method('getWorkflow')->with('w1')->willReturn([
			'id' => 'w1', 'name' => 'My Flow', 'versionId' => 'v9', 'nodes' => [], 'connections' => [],
		]);
		$this->metadata->expects(self::once())->method('write')->with(7, self::callback(
			fn (array $v) => ($v['n8n_mode'] ?? null) === 'sync'
		));
		$this->tags->expects(self::once())->method('apply')->with(7, 'sync');

		$this->service->unignore($node);
	}

	public function testUnignoreOnNonIgnoredFileIsNoOp(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'sync']); // not ignored

		$this->n8n->expects(self::never())->method('unarchiveWorkflow');
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::never())->method('apply');

		$this->service->unignore($node);
	}

	public function testUnignoreUnarchiveFailureLeavesFileUntouched(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$this->expectRead(['n8n_id' => 'w1', 'n8n_mode' => 'ignored']);

		$this->n8n->method('unarchiveWorkflow')->willThrowException(new N8nApiException('boom', 500));
		$this->n8n->expects(self::never())->method('getWorkflow');
		$this->metadata->expects(self::never())->method('write');
		$this->tags->expects(self::never())->method('apply');

		$this->service->unignore($node);
	}
}
