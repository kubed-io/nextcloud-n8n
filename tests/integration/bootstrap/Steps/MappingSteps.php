<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * Mapping steps: the feature describes mappings in plain English (titled table
 * columns); the step translates "storage"/"mode" words into the data model and
 * adds them. Owns `modeToModel`, which the create/move/setup traits also call.
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait MappingSteps {
	/** @When the admin adds these mappings: */
	public function theAdminAddsTheseMappings(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$res = $this->addMapping(
				$row['n8n tag'],
				$row['folder'],
				$row['storage'],
				$row['mode'],
			);
			Assert::assertSame(0, $res['exit'], "adding mapping {$row['n8n tag']} failed:\n{$res['output']}");
		}
	}

	/** @When the admin adds a mapping with an unknown mode for tag :tag */
	public function theAdminAddsAMappingWithAnUnknownMode(string $tag): void {
		// The mode model is exactly sync|link (saga Ch2 §14); anything else must be
		// rejected by Mapping::fromArray validation.
		$json = json_encode([
			'n8n_tag' => $tag, 'team_folder' => 'x', 'nc_groups' => ['admin'],
			'mode' => 'bogus', 'use_team_folder' => true,
		], JSON_THROW_ON_ERROR);
		$this->occ('n8n_sync:add-mapping ' . escapeshellarg($json));
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was unexpectedly accepted:\n{$this->lastOutput}");
	}

	/** @Then there are :count configured mappings */
	public function thereAreNConfiguredMappings(int $count): void {
		Assert::assertCount($count, $this->listMappings(), "expected $count mappings");
	}

	/** @Then the mapping for tag :tag is a :storage folder in :mode mode */
	public function theMappingForTagIs(string $tag, string $storage, string $mode): void {
		$m = $this->findMapping($tag);
		Assert::assertNotNull($m, "no mapping for tag $tag");
		// storage: "team" → use_team_folder true; "admin" → false.
		Assert::assertSame(str_contains($storage, 'team'), (bool)($m['use_team_folder'] ?? false), "tag $tag storage");
		Assert::assertSame($this->modeToModel($mode), $m['mode'], "tag $tag mode");
	}

	/** Translate a UI mode word to the stored mode (sync|link; saga Ch2 §14). */
	private function modeToModel(string $mode): string {
		return match ($mode) {
			'sync' => 'sync',
			'link' => 'link',
			default => throw new \InvalidArgumentException("unknown mode '$mode'"),
		};
	}

	/** Build + run an add-mapping from plain-English storage/mode words. */
	private function addMapping(string $tag, string $folder, string $storage, string $mode): array {
		$data = [
			'n8n_tag' => $tag,
			'team_folder' => $folder,
			'nc_groups' => ['admin'],
			'mode' => $this->modeToModel($mode),
			'use_team_folder' => str_contains($storage, 'team'),
		];
		return $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
	}

	/** @return list<array<string,mixed>> */
	private function listMappings(): array {
		$res = $this->occ('n8n_sync:list-mappings');
		$decoded = json_decode($res['output'], true);
		Assert::assertIsArray($decoded, "list-mappings did not return JSON:\n{$res['output']}");
		return $decoded;
	}

	/** @return array<string,mixed>|null */
	private function findMapping(string $tag): ?array {
		foreach ($this->listMappings() as $m) {
			if (($m['n8n_tag'] ?? null) === $tag) {
				return $m;
			}
		}
		return null;
	}
}
