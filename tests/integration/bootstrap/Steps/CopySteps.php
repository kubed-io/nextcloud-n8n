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
 * Copy lifecycle (copy.feature). A COPY is the opposite of a MOVE: always a
 * brand-new instance, never the original's identity. NC fires NodeCopiedEvent,
 * which CopyListener handles by stripping any inherited metadata and (if the
 * copy landed in a mapping) registering it as a fresh workflow. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait CopySteps {
	/** The original's full DAV metadata, read before the copy — the "unchanged" baseline. */
	private array $copyOriginalBefore = [];

	/**
	 * A workflow file in a NAMED folder, whatever that folder happens to be.
	 *
	 * ONE ARRANGE FOR EVERY SOURCE, because a copy does not care what its source
	 * was — that is the rule under test. Landing it in a mapped folder makes it
	 * managed in that mapping's mode; landing it outside every mapping makes it a
	 * plain document. The scenario says the folder and the Background says what the
	 * folder IS, so nothing has to restate "sync" or "unmapped" here.
	 *
	 * @Given a workflow file in :folder
	 * @Given a workflow file in :folder whose tags are :tags
	 */
	public function aWorkflowFileIn(string $folder, string $tags = ''): void {
		$this->davMkdir($folder);
		$path = $folder . '/Source-' . bin2hex(random_bytes(3)) . '.n8n.json';
		$body = ['name' => 'Source', 'nodes' => [], 'connections' => new \stdClass(), 'settings' => new \stdClass()];
		$names = array_values(array_filter(array_map('trim', explode(',', $tags))));
		if ($names !== []) {
			$body['tags'] = array_map(static fn (string $n): object => (object)['name' => $n], $names);
		}
		$this->davPut($path, json_encode($body, JSON_THROW_ON_ERROR));
		$this->currentFolder = $folder;
		$this->currentFilePath = $path;
		$this->lastWorkflowId = $this->davReadMetadataId($path);
		if (is_string($this->lastWorkflowId) && $this->lastWorkflowId !== '') {
			$this->createdWorkflowIds[] = $this->lastWorkflowId;
		}
		$this->copyOriginalBefore = $this->readManagedMetadata($path);
	}

	/**
	 * @When I copy the file into :folder
	 */
	public function iCopyTheFileInto(string $folder): void {
		$this->davMkdir($folder);
		$dest = $folder . '/' . $this->copyBasename();
		$this->davCopy($this->currentFilePath, $dest);
		$this->currentFolder = $folder;
		$this->captureCopy($dest);
	}

	/**
	 * The copy's whole managed state. Same vocabulary as {@see CreateSteps::theFileHolds},
	 * plus one value this file needs and no other does:
	 *
	 *   its own, not the original's   present, non-empty, and DIFFERENT from the
	 *                                 original's — which is the entire anti-hijack
	 *                                 claim, and stronger than either half alone
	 *
	 * @Then the copy holds this DAV metadata:
	 */
	public function theCopyHolds(TableNode $table): void {
		Assert::assertNotSame('', (string)$this->copyFilePath, 'no copy to inspect — a When must make one');
		$this->assertManagedMetadata($this->copyFilePath, $table, [
			"its own, not the original's" => function (string $key, ?string $actual): void {
				Assert::assertNotNull($actual, "the copy carries no $key — create-on-copy did not run");
				Assert::assertNotSame('', $actual, "the copy has an empty $key");
				Assert::assertNotSame(
					(string)($this->copyOriginalBefore[$key] ?? ''),
					$actual,
					"the copy inherited the original's $key — a copy must never hijack identity",
				);
			},
		]);
	}

	/** @Then the copy holds no n8n DAV metadata at all */
	public function theCopyHoldsNoMetadataAtAll(): void {
		Assert::assertTrue($this->davExists($this->copyFilePath), 'the copy vanished');
		foreach (self::MANAGED_KEYS as $key) {
			$actual = $this->davReadMetadata($this->copyFilePath, $key);
			Assert::assertTrue(
				$actual === null || $actual === '',
				"the copy carries $key ('$actual') but a copy outside every mapping is nobody's",
			);
		}
	}

	/**
	 * THE ANTI-HIJACK INVARIANT, stated as pre/post state rather than as a list of
	 * things that did not happen. The original's metadata is read before the copy
	 * and compared after: every key it had, it still has, with the same value.
	 *
	 * That covers what three separate steps used to claim — that the original kept
	 * its id, that its workflow was not restored, that it was not duplicated —
	 * because all three are the same sentence: the original did not move.
	 *
	 * @Then the original is unchanged
	 */
	public function theOriginalIsUnchanged(): void {
		Assert::assertSame(
			$this->copyOriginalBefore,
			$this->readManagedMetadata($this->currentFilePath),
			"the copy changed the original's metadata",
		);
		$id = (string)($this->copyOriginalBefore['n8n_id'] ?? '');
		if ($id !== '') {
			Assert::assertIsArray($this->n8nGetWorkflow($id), 'the original workflow disappeared from n8n');
		}
	}

	/**
	 * The copy's tags, on both surfaces, as ONE step — because they are one claim.
	 * The tags travelled in the body, so both the pills and the workflow in n8n end
	 * up with the same set; asserting them separately would be two sentences for a
	 * thing that is only true together.
	 *
	 * The mapping tag is excluded, as everywhere else: it is the binding, not a label
	 * the file brought with it.
	 *
	 * @Then the copy's normal tags are :tags in n8n and in Nextcloud
	 */
	public function theCopysNormalTagsAre(string $tags): void {
		$want = array_values(array_filter(array_map('trim', explode(',', $tags))));
		sort($want);

		$mappingTag = $this->mappingTagForFolder($this->currentFolder);
		$strip = fn (array $names): array => array_values(array_filter(
			$names,
			static fn (string $n): bool => $n !== '' && $n !== $mappingTag,
		));

		$id = (string)$this->copyWorkflowId;
		Assert::assertNotSame('', $id, 'the copy has no workflow to read tags from');
		$inN8n = $strip($this->n8nWorkflowTagNames($id));
		sort($inN8n);
		$inNextcloud = $strip($this->fileSystemTags($this->copyFilePath));
		sort($inNextcloud);

		Assert::assertSame(['n8n' => $want, 'Nextcloud' => $want], ['n8n' => $inN8n, 'Nextcloud' => $inNextcloud]);
	}

	/**
	 * THE BINDING IS POST-STATE TOO, and it is the one tag that CHANGES on a copy
	 * across mappings. A workflow belongs to a mapping by wearing its tag, so a copy
	 * that lands in `Pointers` must come out carrying `nextcloud:pointers` — and must
	 * NOT still carry the tag of the mapping it was copied from, which would leave one
	 * workflow claimed by two mappings and mirrored into both folders.
	 *
	 * Asserted as "this one, and no other mapping's" rather than as an exact set,
	 * because the normal tags are asserted separately and this step should fail for
	 * exactly one reason.
	 *
	 * @Then the copy's workflow carries the :folder mapping tag, and no other mapping's
	 */
	public function theCopysWorkflowCarriesTheMappingTag(string $folder): void {
		$id = (string)$this->copyWorkflowId;
		Assert::assertNotSame('', $id, 'the copy has no workflow to read tags from');

		$want = $this->mappingTagForFolder($folder);
		$on = $this->n8nWorkflowTagNames($id);
		Assert::assertContains($want, $on, "the copy's workflow does not carry '$want', the tag binding it to $folder");

		foreach ($this->listMappings() as $m) {
			$other = (string)($m['n8n_tag'] ?? '');
			if ($other === '' || $other === $want) {
				continue;
			}
			Assert::assertNotContains(
				$other,
				$on,
				"the copy's workflow still carries '$other' — it would be claimed by two mappings and mirrored into both",
			);
		}
	}

	/**
	 * THE BODY IS WHAT SURVIVES LEAVING, and this is the one assertion in the file
	 * that reads the file's own bytes rather than its metadata or its pills.
	 *
	 * A copy landing outside every mapping is stripped of IDENTITY — the id, the
	 * mapping, the mode, the hash — because none of that is true of it any more. Its
	 * LABELS are not identity, so they stay, INCLUDING the tag of the mapping it came
	 * from: out here that string binds nothing, and keeping it is a breadcrumb saying
	 * where the file was born. It costs nothing and it is the only record left.
	 *
	 * Pills are not asserted, deliberately: Nextcloud does not copy system tags, so a
	 * copy has none whatever the app does. The body is the surface that travels.
	 *
	 * @Then the copy's body still carries the tags :tags
	 */
	public function theCopysBodyStillCarriesTheTags(string $tags): void {
		$want = array_values(array_filter(array_map('trim', explode(',', $tags))));
		sort($want);

		$wf = json_decode($this->davGet($this->copyFilePath), true);
		Assert::assertIsArray($wf, "the copy at {$this->copyFilePath} is not JSON");
		$got = [];
		foreach ((array)($wf['tags'] ?? []) as $row) {
			$name = is_array($row) ? (string)($row['name'] ?? '') : '';
			if ($name !== '') {
				$got[] = $name;
			}
		}
		sort($got);

		Assert::assertSame($want, $got, "the copy's body tags are not what travelled with it");
	}

	/** @Then no workflow is created in n8n for the copy */
	public function noWorkflowIsCreatedForTheCopy(): void {
		Assert::assertTrue($this->copyWorkflowId === null || $this->copyWorkflowId === '', 'a workflow was created in n8n for an unmapped copy');
	}

	/** A unique copy basename so a COPY never collides with its source (Overwrite: F). */
	private function copyBasename(): string {
		return 'Copy-' . bin2hex(random_bytes(3)) . '.n8n.json';
	}

	/** Record the just-made copy and the workflow id (if any) create-on-copy stamped. */
	private function captureCopy(string $dest): void {
		$this->copyFilePath = $dest;
		$this->copyWorkflowId = $this->davReadMetadataId($dest);
		if (is_string($this->copyWorkflowId) && $this->copyWorkflowId !== '') {
			$this->createdWorkflowIds[] = $this->copyWorkflowId;
		}
	}
}
