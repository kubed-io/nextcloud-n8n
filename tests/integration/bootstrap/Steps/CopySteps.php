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
		$name = 'Source-' . bin2hex(random_bytes(3));
		$path = $folder . '/' . $name . '.n8n.json';
		$body = ['name' => $name, 'nodes' => [], 'connections' => new \stdClass(), 'settings' => new \stdClass()];
		$names = array_values(array_filter(array_map('trim', explode(',', $tags))));
		if ($names !== []) {
			$body['tags'] = array_map(static fn (string $n): object => (object)['name' => $n], $names);
		}
		$this->davPut($path, json_encode($body, JSON_THROW_ON_ERROR));
		$this->currentFolder = $folder;
		$this->currentFilePath = $path;
		// CREATE-ON-LAND RENAMES THE FILE to match the workflow's name, so the path we
		// PUT to may already be gone. Re-resolve before anything reads it.
		if (!$this->davExists($path)) {
			$this->currentFilePath = $folder . '/' . $name . '.n8n.json';
		}
		$this->lastWorkflowId = $this->davReadMetadataId($this->currentFilePath);
		if (is_string($this->lastWorkflowId) && $this->lastWorkflowId !== '') {
			$this->createdWorkflowIds[] = $this->lastWorkflowId;
		}
		$this->originalPath = $this->currentFilePath;
		$this->copyOriginalBefore = $this->readManagedMetadata($this->originalPath);
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
	 * @Then the original file and its workflow are unchanged
	 */
	public function theOriginalIsUnchanged(): void {
		if ($this->originalPath === '') {
			throw new \RuntimeException('no original was captured — a Given must establish one');
		}
		$now = $this->readManagedMetadata($this->originalPath);
		if ($now !== $this->copyOriginalBefore) {
			// SPELLED OUT, NOT ASSERTED. A PHPUnit array diff inside Behat is eaten by
			// the Registry TypeError (see MappingSteps::fail), and this step's message
			// IS the diagnosis — it names which key drifted and to what.
			$keys = array_unique([...array_keys($this->copyOriginalBefore), ...array_keys($now)]);
			sort($keys);
			$drift = [];
			foreach ($keys as $key) {
				$was = (string)($this->copyOriginalBefore[$key] ?? '');
				$is = (string)($now[$key] ?? '');
				if ($was !== $is) {
					$drift[] = "$key: '$was' became '$is'";
				}
			}
			throw new \RuntimeException(
				"the original at {$this->originalPath} changed — " . implode('; ', $drift),
			);
		}
		$id = (string)($this->copyOriginalBefore['n8n_id'] ?? '');
		if ($id !== '' && !is_array($this->n8nGetWorkflow($id))) {
			throw new \RuntimeException("the original's workflow $id disappeared from n8n");
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
	 * NEXTCLOUD COPIES BYTES, so the copy's content is the original's content. The
	 * app's whole contribution to a copy landing outside every mapping is what it
	 * takes OFF — the identity — and this asserts it kept its hands off the rest.
	 *
	 * @Then the copy's body is byte-for-byte the original's
	 */
	public function theCopysBodyIsByteForByteTheOriginals(): void {
		$original = $this->davGet($this->originalPath);
		$copy = $this->davGet($this->copyFilePath);
		if ($original !== $copy) {
			throw new \RuntimeException(
				"the copy's body differs from the original's: " . strlen($original)
				. ' bytes became ' . strlen($copy),
			);
		}
	}

	/**
	 * THE INVARIANT A COPY WOULD OTHERWISE BREAK. Nextcloud copies bytes but not
	 * system tags, so a copy lands with a `tags` array and no pills — and the app
	 * promises those two agree for any `.n8n.json`, mapped or not. This asserts the
	 * copy path put that right.
	 *
	 * @Then the copy's pills match its body
	 */
	public function theCopysPillsMatchItsBody(): void {
		$wf = json_decode($this->davGet($this->copyFilePath), true);
		if (!is_array($wf)) {
			throw new \RuntimeException("the copy at {$this->copyFilePath} is not JSON");
		}
		$body = [];
		foreach ((array)($wf['tags'] ?? []) as $row) {
			$name = is_array($row) ? (string)($row['name'] ?? '') : '';
			if ($name !== '') {
				$body[] = $name;
			}
		}
		sort($body);
		$pills = $this->fileSystemTags($this->copyFilePath);
		sort($pills);

		if ($body !== $pills) {
			throw new \RuntimeException(
				"the copy's body says [" . implode(', ', $body) . '] but its pills say ['
				. implode(', ', $pills) . ']',
			);
		}
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
