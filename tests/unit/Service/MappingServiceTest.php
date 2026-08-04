<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\StorageService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MappingService::resolveForPath} — the folder-membership
 * resolver (saga §14.9 / `mapping-membership.feature`).
 *
 * Mappings are metadata on a folder, so membership is "where does the file live",
 * and folders **nest**: a mapping can sit inside another mapping's folder and the
 * **nearest enclosing** (deepest) one wins. The resolver is pure prefix-matching
 * over the path after `files/`, so it needs no NC plumbing — `IAppConfig` is a
 * canned stub that just hands back the persisted mappings JSON. Clean (non-legacy)
 * rows are used throughout so the one-shot migration re-persist never fires (the
 * stub only declares the read side).
 */
#[CoversClass(MappingService::class)]
final class MappingServiceTest extends TestCase {
	/** @var array<string, list<string>> what reached each mapping's FOLDER */
	private array $appliedGroups = [];

	/**
	 * Build a service whose stored mappings are exactly $mappings.
	 *
	 * @param list<Mapping> $mappings
	 */
	private function serviceWith(array $mappings): MappingService {
		$json = json_encode(array_map(static fn (Mapping $m) => $m->toArray(), $mappings));
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn($json === false ? '[]' : $json);
		return new MappingService($config, $this->storage());
	}

	/**
	 * A StorageService that records what was applied to each mapping's folder.
	 *
	 * A FAKE, NOT A STUB: the whole point of the groups change is that they go to
	 * the FOLDER and not into the store, and a stub returning null would let a
	 * service that quietly persisted them pass. This one remembers, so the tests
	 * can assert where the groups actually landed.
	 */
	private function storage(): StorageService {
		$storage = $this->createMock(StorageService::class);
		$storage->method('ensureFolder')->willReturnCallback(
			function (Mapping $m, array|string|null $groups = null) {
				if ($groups !== null) {
					$this->appliedGroups[$m->id] = StorageService::normaliseGroups($groups);
				}
				return $this->createStub(\OCP\Files\Folder::class);
			},
		);
		$storage->method('groupsOf')->willReturnCallback(
			fn (Mapping $m): array => $this->appliedGroups[$m->id] ?? [],
		);

		return $storage;
	}

	private function mapping(string $id, string $tag, string $folder): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'n8n_tag' => $tag,
			'team_folder' => $folder,
			'mode' => 'sync',
		]);
	}

	public function testFileDirectlyInAMappedFolderBelongsToIt(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/demo/wf.n8n.json');

		self::assertNotNull($resolved);
		self::assertSame('m1', $resolved->id);
	}

	public function testFileInADeepSubfolderStillBelongsToTheMappedFolder(): void {
		// A single mapping on the top folder encloses everything beneath it, no
		// matter how deep — there is no nearer mapping to win.
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/demo/a/b/c/wf.n8n.json');

		self::assertNotNull($resolved);
		self::assertSame('m1', $resolved->id);
	}

	public function testTheMappedFolderItselfResolvesToItsMapping(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/demo');

		self::assertNotNull($resolved);
		self::assertSame('m1', $resolved->id);
	}

	public function testNearestEnclosingMappingWinsForANestedFolder(): void {
		// outer/ and outer/inner/ are both mapped; a file under inner belongs to
		// inner, the nearer (deeper) of the two.
		$service = $this->serviceWith([
			$this->mapping('outer', 'nextcloud:outer', 'outer'),
			$this->mapping('inner', 'nextcloud:inner', 'outer/inner'),
		]);

		$resolved = $service->resolveForPath('/admin/files/outer/inner/wf.n8n.json');

		self::assertNotNull($resolved);
		self::assertSame('inner', $resolved->id);
	}

	public function testNearestEnclosingIsIndependentOfMappingOrder(): void {
		// Same as above but the inner mapping is listed first — "longest wins"
		// must not depend on iteration order.
		$service = $this->serviceWith([
			$this->mapping('inner', 'nextcloud:inner', 'outer/inner'),
			$this->mapping('outer', 'nextcloud:outer', 'outer'),
		]);

		$resolved = $service->resolveForPath('/admin/files/outer/inner/deep/wf.n8n.json');

		self::assertNotNull($resolved);
		self::assertSame('inner', $resolved->id);
	}

	public function testAFileInTheOuterButNotInnerFolderBelongsToTheOuter(): void {
		$service = $this->serviceWith([
			$this->mapping('outer', 'nextcloud:outer', 'outer'),
			$this->mapping('inner', 'nextcloud:inner', 'outer/inner'),
		]);

		$resolved = $service->resolveForPath('/admin/files/outer/sibling/wf.n8n.json');

		self::assertNotNull($resolved);
		self::assertSame('outer', $resolved->id);
	}

	public function testASiblingFolderSharingAPrefixIsNotSwallowed(): void {
		// "outerwear" must not match the "outer" mapping — the resolver pins the
		// match to a segment boundary.
		$service = $this->serviceWith([$this->mapping('outer', 'nextcloud:outer', 'outer')]);

		$resolved = $service->resolveForPath('/admin/files/outerwear/wf.n8n.json');

		self::assertNull($resolved);
	}

	public function testAFileOutsideEveryMappedFolderBelongsToNoMapping(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/elsewhere/wf.n8n.json');

		self::assertNull($resolved);
	}

	public function testAPathWithoutAFilesRootResolvesToNothing(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		self::assertNull($service->resolveForPath('/admin/cache/demo/wf.n8n.json'));
	}

	public function testNoMappingsAtAllResolvesToNothing(): void {
		$service = $this->serviceWith([]);

		self::assertNull($service->resolveForPath('/admin/files/demo/wf.n8n.json'));
	}

	public function testDeepestOfThreeNestedFoldersWins(): void {
		// outer ⊃ outer/mid ⊃ outer/mid/inner — a file in the deepest belongs to it.
		$service = $this->serviceWith([
			$this->mapping('outer', 'nextcloud:outer', 'outer'),
			$this->mapping('mid', 'nextcloud:mid', 'outer/mid'),
			$this->mapping('inner', 'nextcloud:inner', 'outer/mid/inner'),
		]);

		$resolved = $service->resolveForPath('/admin/files/outer/mid/inner/wf.n8n.json');

		self::assertNotNull($resolved);
		self::assertSame('inner', $resolved->id);
	}

	// ── groups are the folder's, not the mapping's ───────────────────────────────

	/**
	 * THE HEADLINE: groups given at create reach the FOLDER and are not persisted.
	 *
	 * Three apps in this family can map to one folder. While each stored its own
	 * list, every sync stamped that list over the others' and they fought forever,
	 * none of them wrong. This is the assertion that they have stopped.
	 */
	public function testGroupsGivenAtCreateGoToTheFolderNotTheStore(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[]');
		$written = null;
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written = $value;
				return true;
			},
		);

		$service = new MappingService($config, $this->storage());
		$mapping = $service->add(
			Mapping::fromArray(['id' => 'm1', 'n8n_tag' => 'a', 'team_folder' => 'a', 'mode' => 'sync']),
			['design', 'admin'],
		);

		self::assertStringNotContainsString('nc_groups', (string)$written, 'the stored blob must not carry groups');
		self::assertSame(['design', 'admin'], $this->appliedGroups['m1'], 'the groups must reach the folder');
		self::assertSame(['design', 'admin'], $service->groupsOf($mapping));
	}

	/** Changing the groups writes to the folder and persists nothing at all. */
	public function testUpdatingGroupsStoresNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');
		$config->expects(self::never())->method('setValueString');

		$service = new MappingService($config, $this->storage());
		self::assertSame(['design'], $service->updateGroups('m1', 'design'));
		self::assertSame(['design'], $this->appliedGroups['m1']);
	}

	/** A narrowed set really narrows — the old code could only ever add. */
	public function testGroupsCanBeNarrowedAndCleared(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage());
		$service->updateGroups('m1', 'design,admin,sales');
		self::assertSame(['design'], $service->updateGroups('m1', 'design'));
		self::assertSame([], $service->updateGroups('m1', ''));
	}

	/** describe() is the stored shape PLUS what the folder currently reports. */
	public function testDescribeAddsTheFoldersGroupsToTheStoredShape(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage());
		$service->updateGroups('m1', 'design');
		$mapping = $service->getById('m1');
		self::assertNotNull($mapping);

		$described = $service->describe($mapping);
		self::assertSame(['design'], $described['nc_groups']);
		self::assertArrayNotHasKey('nc_groups', $mapping->toArray(), 'the STORED shape carries no groups');
	}

	/**
	 * A stored row from before this change still parses — its `nc_groups` key is
	 * simply not a field any more, and reading it must not throw.
	 */
	public function testAStoredRowWithGroupsStillParsesAndIgnoresThem(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(
			'[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync","nc_groups":["devs"]}]',
		);

		$mappings = (new MappingService($config, $this->storage()))->list();
		self::assertCount(1, $mappings);
		self::assertArrayNotHasKey('nc_groups', $mappings[0]->toArray());
	}

	// ── memoisation + migration (R6) ─────────────────────────────────────────────

	public function testListReadsTheConfigOnceAndCachesForTheRequest(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())  // memoised: the several list()/resolveForPath calls read once
			->method('getValueString')
			->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage());
		$service->list();
		$service->resolveForPath('/admin/files/a/wf.n8n.json');
		self::assertCount(1, $service->list());
	}

	public function testListNeverWritesEvenForLegacyRows(): void {
		// Migration is no longer on the read path: reading legacy data parses it but
		// must not persist anything (that's MigrateMappings' job, on upgrade).
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"n8n_path":"a","nc_path":"a","mode":"reference"}]');
		$config->expects(self::never())->method('setValueString');

		self::assertCount(1, (new MappingService($config, $this->storage()))->list());
	}

	public function testMigrateRewritesLegacyRowsAndReportsChange(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"reference"}]');
		$config->expects(self::once())
			->method('setValueString')
			->with(self::anything(), 'mappings', self::stringContains('"mode":"link"'));

		self::assertTrue((new MappingService($config, $this->storage()))->migrate());
	}

	public function testMigrateIsANoOpOnACleanStore(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');
		$config->expects(self::never())->method('setValueString');

		self::assertFalse((new MappingService($config, $this->storage()))->migrate());
	}
}
