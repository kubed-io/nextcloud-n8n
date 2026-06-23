<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\N8nApiException;
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
	private ModeChangeService $service;

	protected function setUp(): void {
		$this->n8n = $this->createMock(N8nClient::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		$this->tags = $this->createMock(OwnershipTags::class);

		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://n8n.example.com');

		$this->service = new ModeChangeService(
			$this->n8n,
			$this->metadata,
			$this->tags,
			$guard,
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
}
