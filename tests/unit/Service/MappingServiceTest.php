<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Exception\ExistingWorkflowsException;
use OCA\N8nSync\Service\ExistingWorkflows;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\StorageService;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MappingService::resolveForPath} — the folder-membership
 * resolver (saga §14.9; `mapping-membership.feature` is retired — its rule lives on here).
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
		return new MappingService($config, $this->storage(), $this->existingWorkflows());
	}

	/**
	 * A StorageService that records what was applied to each mapping's folder.
	 *
	 * A FAKE, NOT A STUB: the whole point of the groups change is that they go to
	 * the FOLDER and not into the store, and a stub returning null would let a
	 * service that quietly persisted them pass. This one remembers, so the tests
	 * can assert where the groups actually landed.
	 */
	/**
	 * A walker that finds nothing, which is what every existing test assumes: they
	 * make mappings over folders that do not exist yet, so there is nothing to purge.
	 * The tests that DO care supply their own — see {@see existingWorkflowsHolding()}.
	 */
	private function existingWorkflows(): ExistingWorkflows {
		$existing = $this->createStub(ExistingWorkflows::class);
		$existing->method('under')->willReturn([]);
		return $existing;
	}

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

	// ── create: the rules mapping/create.feature states ────────────────────────

	/**
	 * A CONFIG THAT CAN TELL ITS KEYS APART, which the shared stub cannot: it answers
	 * the same JSON to every `getValueString`, so `api_key` reads as the mappings blob
	 * and looks configured. Fine everywhere else, useless for the one test about the
	 * key being absent.
	 */
	private function configWith(string $mappings, string $apiKey): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'mappings' => $mappings,
				'api_key' => $apiKey,
				default => $default,
			},
		);
		return $config;
	}

	private function emptyService(?ExistingWorkflows $existing = null): MappingService {
		return new MappingService(
			$this->configWith('[]', 'a-key'),
			$this->storage(),
			$existing ?? $this->existingWorkflows(),
		);
	}

	/** A walker holding $files, recording what it was asked to destroy. */
	private function existingWorkflowsHolding(array $files, ?array &$purged = null): ExistingWorkflows {
		$existing = $this->createMock(ExistingWorkflows::class);
		$existing->method('under')->willReturn($files);
		$existing->method('purge')->willReturnCallback(static function (array $f) use (&$purged): int {
			$purged = $f;
			return count($f);
		});
		return $existing;
	}

	private function workflowFile(): File {
		$file = $this->createStub(File::class);
		$file->method('getPath')->willReturn('/admin/files/Automations/Keeper.n8n');
		return $file;
	}

	private function linkMapping(): Mapping {
		return Mapping::fromArray([
			'n8n_tag' => 'nextcloud:alpha',
			'team_folder' => 'Automations',
			'mode' => 'link',
		]);
	}

	public function testTwoMappingsMayNotTargetTheSameNextcloudFolder(): void {
		$svc = $this->emptyService();
		$svc->add($this->mapping('m1', 'nextcloud:alpha', 'Automations'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/already uses the Nextcloud folder/');
		$svc->add($this->mapping('m2', 'nextcloud:beta', 'Automations'));
	}

	/**
	 * Compared case-insensitively, because Nextcloud will not create `Automations`
	 * beside `automations`. Two mappings differing only in case would both provision
	 * the SAME folder while each believing it had one to itself.
	 */
	public function testTheNextcloudFolderClashIsCaseInsensitive(): void {
		$svc = $this->emptyService();
		$svc->add($this->mapping('m1', 'nextcloud:alpha', 'Automations'));

		$this->expectException(\InvalidArgumentException::class);
		$svc->add($this->mapping('m2', 'nextcloud:beta', 'automations'));
	}

	public function testADifferentNextcloudFolderIsFine(): void {
		$svc = $this->emptyService();
		$svc->add($this->mapping('m1', 'nextcloud:alpha', 'Automations'));
		$svc->add($this->mapping('m2', 'nextcloud:beta', 'Pipelines'));

		self::assertCount(2, $svc->list());
	}

	public function testALinkMappingOverExistingWorkflowsIsRefusedWithTheCount(): void {
		$svc = $this->emptyService(
			$this->existingWorkflowsHolding([$this->workflowFile(), $this->workflowFile()]),
		);

		try {
			$svc->add($this->linkMapping());
			self::fail('a link mapping over two workflow files was accepted');
		} catch (ExistingWorkflowsException $e) {
			self::assertSame(2, $e->workflows);
			self::assertSame('Automations', $e->folder);
			self::assertStringContainsString('permanently deleted', $e->getMessage());
			self::assertStringContainsString('Move them elsewhere first', $e->getMessage());
		}
		self::assertSame([], $svc->list(), 'the refused mapping was stored anyway');
	}

	/** The admin answered, so the files go — and the mapping is saved. */
	public function testAcknowledgingThePurgeCreatesTheMappingAndDestroysTheFiles(): void {
		$purged = null;
		$files = [$this->workflowFile()];
		$svc = $this->emptyService($this->existingWorkflowsHolding($files, $purged));

		$svc->add($this->linkMapping(), [], true);

		self::assertCount(1, $svc->list());
		self::assertSame($files, $purged, 'the acknowledged files were not the ones destroyed');
	}

	/**
	 * A SYNC MAPPING IS UNTOUCHED. It pushes what it finds up to n8n — that is
	 * create-on-land, and it is the feature. Asking a sync mapping the question would
	 * refuse the ordinary case of mapping a folder that already holds work.
	 */
	public function testASyncMappingOverTheSameFilesIsNotEvenAsked(): void {
		$purged = null;
		$svc = $this->emptyService($this->existingWorkflowsHolding([$this->workflowFile()], $purged));

		$svc->add($this->mapping('m1', 'nextcloud:alpha', 'Automations'));

		self::assertCount(1, $svc->list());
		self::assertNull($purged, 'a sync mapping destroyed files it should have adopted');
	}

	/**
	 * NOTHING IS DESTROYED FOR A MAPPING THAT WAS NEVER MADE. The purge runs after the
	 * mapping is persisted, so an admin who acknowledges the files and then hits a
	 * different refusal keeps both the files and the absence of the mapping.
	 */
	public function testAMappingRefusedForAnotherReasonPurgesNothing(): void {
		$purged = null;
		$svc = $this->emptyService($this->existingWorkflowsHolding([$this->workflowFile()], $purged));
		$svc->add($this->mapping('m1', 'nextcloud:other', 'Automations'));

		// Same Nextcloud folder as the one just mapped — refused before anything else.
		$this->expectException(\InvalidArgumentException::class);
		try {
			$svc->add($this->linkMapping(), [], true);
		} finally {
			self::assertNull($purged, 'files were destroyed for a mapping that was refused');
		}
	}

	/**
	 * A MAPPING WITHOUT A KEY CAN NEVER SYNC, and it is refused at creation rather
	 * than discovered at the first pull — the folder is provisioned and shared the
	 * moment a mapping is stored, so the alternative is a real folder in people's
	 * Files that silently never fills.
	 */
	public function testWithoutAnApiKeyNothingCanBeMapped(): void {
		$svc = new MappingService(
			$this->configWith('[]', ''),
			$this->storage(),
			$this->existingWorkflows(),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/API key is not configured/');
		$svc->add($this->mapping('m1', 'nextcloud:alpha', 'Automations'));
	}

	public function testFileDirectlyInAMappedFolderBelongsToIt(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/demo/wf.n8n');

		self::assertNotNull($resolved);
		self::assertSame('m1', $resolved->id);
	}

	public function testFileInADeepSubfolderStillBelongsToTheMappedFolder(): void {
		// A single mapping on the top folder encloses everything beneath it, no
		// matter how deep — there is no nearer mapping to win.
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/demo/a/b/c/wf.n8n');

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

		$resolved = $service->resolveForPath('/admin/files/outer/inner/wf.n8n');

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

		$resolved = $service->resolveForPath('/admin/files/outer/inner/deep/wf.n8n');

		self::assertNotNull($resolved);
		self::assertSame('inner', $resolved->id);
	}

	public function testAFileInTheOuterButNotInnerFolderBelongsToTheOuter(): void {
		$service = $this->serviceWith([
			$this->mapping('outer', 'nextcloud:outer', 'outer'),
			$this->mapping('inner', 'nextcloud:inner', 'outer/inner'),
		]);

		$resolved = $service->resolveForPath('/admin/files/outer/sibling/wf.n8n');

		self::assertNotNull($resolved);
		self::assertSame('outer', $resolved->id);
	}

	public function testASiblingFolderSharingAPrefixIsNotSwallowed(): void {
		// "outerwear" must not match the "outer" mapping — the resolver pins the
		// match to a segment boundary.
		$service = $this->serviceWith([$this->mapping('outer', 'nextcloud:outer', 'outer')]);

		$resolved = $service->resolveForPath('/admin/files/outerwear/wf.n8n');

		self::assertNull($resolved);
	}

	public function testAFileOutsideEveryMappedFolderBelongsToNoMapping(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		$resolved = $service->resolveForPath('/admin/files/elsewhere/wf.n8n');

		self::assertNull($resolved);
	}

	public function testAPathWithoutAFilesRootResolvesToNothing(): void {
		$service = $this->serviceWith([$this->mapping('m1', 'nextcloud:demo', 'demo')]);

		self::assertNull($service->resolveForPath('/admin/cache/demo/wf.n8n'));
	}

	public function testNoMappingsAtAllResolvesToNothing(): void {
		$service = $this->serviceWith([]);

		self::assertNull($service->resolveForPath('/admin/files/demo/wf.n8n'));
	}

	public function testDeepestOfThreeNestedFoldersWins(): void {
		// outer ⊃ outer/mid ⊃ outer/mid/inner — a file in the deepest belongs to it.
		$service = $this->serviceWith([
			$this->mapping('outer', 'nextcloud:outer', 'outer'),
			$this->mapping('mid', 'nextcloud:mid', 'outer/mid'),
			$this->mapping('inner', 'nextcloud:inner', 'outer/mid/inner'),
		]);

		$resolved = $service->resolveForPath('/admin/files/outer/mid/inner/wf.n8n');

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

		$service = new MappingService($config, $this->storage(), $this->existingWorkflows());
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

		$service = new MappingService($config, $this->storage(), $this->existingWorkflows());
		self::assertSame(['design'], $service->updateGroups('m1', 'design'));
		self::assertSame(['design'], $this->appliedGroups['m1']);
	}

	/** A narrowed set really narrows — the old code could only ever add. */
	public function testGroupsCanBeNarrowedAndCleared(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage(), $this->existingWorkflows());
		$service->updateGroups('m1', 'design,admin,sales');
		self::assertSame(['design'], $service->updateGroups('m1', 'design'));
		self::assertSame([], $service->updateGroups('m1', ''));
	}

	/** describe() is the stored shape PLUS what the folder currently reports. */
	public function testDescribeAddsTheFoldersGroupsToTheStoredShape(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage(), $this->existingWorkflows());
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

		$mappings = (new MappingService($config, $this->storage(), $this->existingWorkflows()))->list();
		self::assertCount(1, $mappings);
		self::assertArrayNotHasKey('nc_groups', $mappings[0]->toArray());
	}

	// ── memoisation + migration (R6) ─────────────────────────────────────────────

	public function testListReadsTheConfigOnceAndCachesForTheRequest(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())  // memoised: the several list()/resolveForPath calls read once
			->method('getValueString')
			->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage(), $this->existingWorkflows());
		$service->list();
		$service->resolveForPath('/admin/files/a/wf.n8n');
		self::assertCount(1, $service->list());
	}

	public function testListNeverWritesEvenForLegacyRows(): void {
		// Migration is no longer on the read path: reading legacy data parses it but
		// must not persist anything (that's MigrateMappings' job, on upgrade).
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"n8n_path":"a","nc_path":"a","mode":"reference"}]');
		$config->expects(self::never())->method('setValueString');

		self::assertCount(1, (new MappingService($config, $this->storage(), $this->existingWorkflows()))->list());
	}

	public function testMigrateRewritesLegacyRowsAndReportsChange(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"reference"}]');
		$config->expects(self::once())
			->method('setValueString')
			->with(self::anything(), 'mappings', self::stringContains('"mode":"link"'));

		self::assertTrue((new MappingService($config, $this->storage(), $this->existingWorkflows()))->migrate());
	}

	public function testMigrateIsANoOpOnACleanStore(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","n8n_tag":"a","team_folder":"a","mode":"sync"}]');
		$config->expects(self::never())->method('setValueString');

		self::assertFalse((new MappingService($config, $this->storage(), $this->existingWorkflows()))->migrate());
	}
}
