<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\OwnershipTags;
use OCA\N8nSync\Service\ReservedTagResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ReservedTagResolver} — the pull-time exclude resolution
 * against the `n8n:ignore` reserved tag (saga §14.8 `reserved-tags.feature`).
 *
 * The mapping's mode is authoritative; the resolver only decides whether a workflow
 * is excluded. Pure logic, no collaborators: a workflow row + the mapping mode in,
 * the effective mode (the mapping mode, or null = ignore) out.
 */
#[CoversClass(ReservedTagResolver::class)]
final class ReservedTagResolverTest extends TestCase {
	private ReservedTagResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new ReservedTagResolver();
	}

	/** @param list<string> $tagNames */
	private function workflow(array $tagNames): array {
		$tags = [];
		foreach ($tagNames as $i => $name) {
			$tags[] = ['id' => (string)$i, 'name' => $name];
		}
		return ['id' => 'wf-1', 'name' => 'Flow', 'tags' => $tags];
	}

	public function testNoReservedTagTakesTheMappingDefault(): void {
		self::assertSame(Mapping::MODE_SYNC, $this->resolver->resolve($this->workflow(['team:flows']), Mapping::MODE_SYNC));
		self::assertSame(Mapping::MODE_LINK, $this->resolver->resolve($this->workflow(['team:flows']), Mapping::MODE_LINK));
	}

	public function testIgnoreTagResolvesToNull(): void {
		self::assertNull($this->resolver->resolve($this->workflow(['team:flows', OwnershipTags::TAG_IGNORE]), Mapping::MODE_SYNC));
	}

	public function testIgnoreAlongsideOtherTagsStillResolvesToNull(): void {
		// ignore is the exclude switch — any other tag riding along is irrelevant.
		self::assertNull($this->resolver->resolve(
			$this->workflow(['team:flows', OwnershipTags::TAG_IGNORE]),
			Mapping::MODE_LINK,
		));
	}

	public function testAWorkflowWithNoTagsKeyTakesTheDefault(): void {
		self::assertSame(Mapping::MODE_SYNC, $this->resolver->resolve(['id' => 'wf-2', 'name' => 'N'], Mapping::MODE_SYNC));
	}

	public function testOddTagEntriesAreIgnored(): void {
		// Malformed tag entries are skipped; with no n8n:ignore the mapping mode wins.
		$workflow = ['id' => 'wf-3', 'tags' => [['id' => '1'], 'not-an-array', ['name' => 42], ['name' => 'team:flows']]];
		self::assertSame(Mapping::MODE_LINK, $this->resolver->resolve($workflow, Mapping::MODE_LINK));
	}
}
