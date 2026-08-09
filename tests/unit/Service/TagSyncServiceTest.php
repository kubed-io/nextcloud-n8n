<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\N8nClient;
use OCA\N8nSync\Service\TagSyncService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see TagSyncService} — the IO shell around the pure
 * {@see \OCA\N8nSync\Service\TagMerge} three-way merge (saga Ch5 §5.6). The merge
 * algebra itself is pinned in {@see TagMergeTest}; here we verify the seams the
 * shell owns: the reserved-namespace filter, the protected mapping-tag force-keep,
 * the pull "keep NC-local additions" rule with a source-only baseline, and the push
 * "preserve n8n's reserved markers + NC wins" rule.
 *
 * The NC system-tag collaborators are mocked ({@see ISystemTagManager} +
 * {@see ISystemTagObjectMapper}); a small in-memory tag table backs id⇄name lookups
 * so the assertions read in tag *names*, not opaque ids.
 */
#[CoversClass(TagSyncService::class)]
final class TagSyncServiceTest extends TestCase {
	private ISystemTagManager $tagManager;
	private ISystemTagObjectMapper $tagMapper;
	private N8nClient $n8n;
	private WorkflowMetadata $metadata;
	private TagSyncService $service;

	protected function setUp(): void {
		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->n8n = $this->createMock(N8nClient::class);
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		$this->service = new TagSyncService(
			$this->tagManager,
			$this->tagMapper,
			$this->n8n,
			$this->metadata,
		);
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	private function tagId(string $name): string {
		return 'tag:' . $name;
	}

	private function makeTag(string $name): ISystemTag {
		$tag = $this->createStub(ISystemTag::class);
		$tag->method('getId')->willReturn($this->tagId($name));
		$tag->method('getName')->willReturn($name);
		return $tag;
	}

	/** Make the file at $fileId currently carry exactly $names (as NC system tags). */
	private function fileHasTags(int $fileId, array $names): void {
		$objId = (string)$fileId;
		$ids = array_map(fn (string $n): string => $this->tagId($n), $names);
		$this->tagMapper->method('getTagIdsForObjects')->willReturn([$objId => $ids]);
		$this->tagManager->method('getTagsByIds')->willReturnCallback(
			function (array $wanted) use ($names): array {
				$out = [];
				foreach ($names as $n) {
					if (in_array($this->tagId($n), $wanted, true)) {
						$out[] = $this->makeTag($n);
					}
				}
				return $out;
			},
		);
	}

	private function managed(string $baselineJson = ''): ManagedFile {
		return new ManagedFile('wf-1', Mapping::MODE_SYNC, '', '', 'map-alpha', $baselineJson);
	}

	private function linkManaged(string $baselineJson = ''): ManagedFile {
		return new ManagedFile('wf-1', Mapping::MODE_LINK, '', '', 'map-alpha', $baselineJson);
	}

	// ── readNcContentTags ──────────────────────────────────────────────────────

	public function testReadNcContentTagsReturnsEveryTagOnTheFile(): void {
		// NOTHING IS CARVED OUT ANY MORE. This used to assert that the app's own
		// `n8n:*` pills were stripped here — they were on the same files as content
		// tags and had to be told apart. Nothing writes a pill now, so a tag is a tag,
		// and a name that merely looks reserved is somebody's label.
		$this->fileHasTags(1, ['prod', 'linux', 'n8n-ish']);

		$content = $this->service->readNcContentTags(1);

		sort($content);
		self::assertSame(['linux', 'n8n-ish', 'prod'], $content);
	}

	public function testReadNcContentTagsEmptyWhenNoTags(): void {
		$this->tagMapper->method('getTagIdsForObjects')->willReturn(['1' => []]);

		self::assertSame([], $this->service->readNcContentTags(1));
	}

	// ── reconcilePull ──────────────────────────────────────────────────────────

	public function testPullMirrorsSourceTagsOntoTheFile(): void {
		// n8n has {dns, linux}; file starts empty; nothing protected.
		$this->fileHasTags(1, []);
		$this->tagManager->method('createTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));

		$assigned = [];
		$this->tagMapper->method('assignTags')->willReturnCallback(
			function (string $objId, string $type, array $ids) use (&$assigned): void {
				$assigned = array_merge($assigned, $ids);
			},
		);

		$workflow = ['id' => 'wf-1', 'tags' => [['id' => 'a', 'name' => 'dns'], ['id' => 'b', 'name' => 'linux']]];
		$this->service->reconcilePull(1, $workflow, $this->managed());

		sort($assigned);
		self::assertSame([$this->tagId('dns'), $this->tagId('linux')], $assigned);
	}

	public function testPullBaselineIsSourceOnlyNotNcLocalAdds(): void {
		// File already has a local add "urgent" not in n8n and not in the baseline.
		// The pill survives, but the baseline is stamped to the SOURCE set only —
		// "urgent" isn't agreed until a push lands it.
		$this->fileHasTags(1, ['urgent']);
		$this->tagManager->method('createTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagMapper->method('assignTags');

		$stamped = null;
		$this->metadata->method('stampTags')->willReturnCallback(
			function (int $fileId, array $tags) use (&$stamped): void {
				$stamped = $tags;
			},
		);

		$workflow = ['id' => 'wf-1', 'tags' => [['id' => 'a', 'name' => 'linux']]];
		$this->service->reconcilePull(1, $workflow, $this->managed('[]'));

		self::assertSame(['linux'], $stamped, 'baseline must be the source set, not source + local adds');
	}

	public function testPullDropsAMappingTagTheSourceNoLongerCarries(): void {
		// THE FORCE-KEEP IS GONE, and this is its inverse. The mapping tag used to be
		// pushed back onto both sides so a Nextcloud pill could never unbind a workflow;
		// dropping it is now the sanctioned way to leave a mapping, so a pull mirrors
		// its absence like any other tag. The mirror itself is pruned by SyncService,
		// which is where "this workflow is no longer ours" belongs.
		$this->fileHasTags(1, ['flows', 'linux']);
		$this->tagManager->method('createTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagManager->method('getTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagMapper->method('haveTag')->willReturn(true);
		$this->tagMapper->method('assignTags');

		$unassigned = [];
		$this->tagMapper->method('unassignTags')->willReturnCallback(
			function (string $objId, string $type, array $ids) use (&$unassigned): void {
				$unassigned = array_merge($unassigned, $ids);
			},
		);

		$workflow = ['id' => 'wf-1', 'tags' => [['id' => 'a', 'name' => 'linux']]];
		$this->service->reconcilePull(1, $workflow, $this->managed('["flows","linux"]'));

		self::assertContains($this->tagId('flows'), $unassigned, 'a mapping tag the source dropped is no longer force-kept');
	}

	public function testPullForALinkDropsLocalAddsAsPureMirror(): void {
		// A link is a READ-ONLY projection of n8n's tags. A pill added locally
		// ("urgent") that n8n does not carry has no push channel to ever land it, so
		// the pull wipes it — unlike a sync file, where a local add survives to push.
		$this->fileHasTags(1, ['urgent']);
		$this->tagManager->method('createTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagManager->method('getTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagMapper->method('haveTag')->willReturn(true);
		$this->tagMapper->method('assignTags');

		$unassigned = [];
		$this->tagMapper->method('unassignTags')->willReturnCallback(
			function (string $objId, string $type, array $ids) use (&$unassigned): void {
				$unassigned = array_merge($unassigned, $ids);
			},
		);

		$workflow = ['id' => 'wf-1', 'tags' => [['id' => 'a', 'name' => 'linux']]];
		$this->service->reconcilePull(1, $workflow, $this->linkManaged('[]'));

		self::assertContains($this->tagId('urgent'), $unassigned, 'a link must drop a local pill n8n does not carry');
	}

	// ── reconcilePush ──────────────────────────────────────────────────────────

	public function testPushWritesTheMergedSetToN8n(): void {
		// A tag that merely LOOKS like one of the app's old markers
		// plus "flows"; NC added "urgent". Push must send flows+urgent+the reserved
		// marker (full-replace would otherwise drop the marker).
		$this->fileHasTags(1, ['flows', 'urgent']);
		$this->tagManager->method('createTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagManager->method('getTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagMapper->method('haveTag')->willReturn(true);
		$this->tagMapper->method('assignTags');
		$this->tagMapper->method('unassignTags');

		$this->n8n->method('getWorkflow')->willReturn([
			'id' => 'wf-1',
			'tags' => [['id' => 'a', 'name' => 'flows'], ['id' => 'r', 'name' => 'n8n-ish']],
		]);
		$this->n8n->method('ensureTags')->willReturnCallback(
			fn (array $names): array => array_map(static fn (string $n): string => 'n8nid:' . $n, $names),
		);

		$sent = null;
		$this->n8n->method('setWorkflowTags')->willReturnCallback(
			function (string $id, array $ids) use (&$sent): array {
				$sent = $ids;
				return $ids;
			},
		);
		$this->metadata->method('stampTags');

		$this->service->reconcilePush(1, $this->managed('["flows"]'));

		self::assertContains('n8nid:flows', $sent);
		self::assertContains('n8nid:urgent', $sent);
		// It is an ordinary tag now, so the merge decides its fate like any other's:
		// it is in n8n and not in the baseline, so it is an add and it survives.
		self::assertContains('n8nid:n8n-ish', $sent);
	}

	public function testPushStampsMergedSetAsBaseline(): void {
		$this->fileHasTags(1, ['flows', 'urgent']);
		$this->tagManager->method('createTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagManager->method('getTag')->willReturnCallback(fn (string $n): ISystemTag => $this->makeTag($n));
		$this->tagMapper->method('haveTag')->willReturn(true);
		$this->tagMapper->method('assignTags');
		$this->tagMapper->method('unassignTags');
		$this->n8n->method('getWorkflow')->willReturn(['id' => 'wf-1', 'tags' => [['id' => 'a', 'name' => 'flows']]]);
		$this->n8n->method('ensureTags')->willReturnCallback(
			fn (array $names): array => array_map(static fn (string $n): string => 'n8nid:' . $n, $names),
		);
		$this->n8n->method('setWorkflowTags')->willReturn([]);

		$stamped = null;
		$this->metadata->method('stampTags')->willReturnCallback(
			function (int $fileId, array $tags) use (&$stamped): void {
				$stamped = $tags;
			},
		);

		$this->service->reconcilePush(1, $this->managed('["flows"]'));

		sort($stamped);
		self::assertSame(['flows', 'urgent'], $stamped);
	}
}
