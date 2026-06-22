<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Rename steps (UC name-sync: filename ⇄ JSON name ⇄ n8n name). Rename/edit are
 * deferred to ReconcileNameJob (the file is locked during a rename), so each
 * scenario drains that job class with the occ worker before asserting. The
 * stable link is the n8n id, which never changes. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait RenameSteps {
	/**
	 * Create a managed sync file with a specific name (so the rename has a known
	 * "before"). Reuses the create-on-land path: a WebDAV PUT into a sync mapping.
	 *
	 * @Given a managed :mode workflow file named :filename
	 */
	public function aManagedWorkflowFileNamed(string $mode, string $filename): void {
		$tag = 'nextcloud:rename-' . bin2hex(random_bytes(3));
		$this->setupSyncMappingAndFolder($mode, $tag);
		$stem = preg_replace('/\.n8n\.json$/', '', $filename) ?? $filename;
		$this->putManagedFile($this->currentFolder . '/' . $filename, $stem);
	}

	/**
	 * Create a managed sync file with a generated name. The same step text backs
	 * the "…file", "…file with a known n8n_id" phrasings — all we need is a
	 * managed sync file; the extra clauses are just narrative.
	 *
	 * @Given a managed :mode workflow file
	 * @Given a managed :mode workflow file with a known :key
	 */
	public function aManagedWorkflowFile(string $mode, ?string $key = null): void {
		$tag = 'nextcloud:rename-' . bin2hex(random_bytes(3));
		$this->setupSyncMappingAndFolder($mode, $tag);
		$name = 'Old Name';
		$this->putManagedFile($this->currentFolder . '/' . $name . '.n8n.json', $name);
	}

	/** @When I rename the file to :filename */
	public function iRenameTheFileTo(string $filename): void {
		$dest = $this->currentFolder . '/' . $filename;
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\ReconcileNameJob');
	}

	/** @When I edit the file and change the JSON :field field to :value */
	public function iEditTheJsonField(string $field, string $value): void {
		$body = (string)$this->davGet($this->currentFilePath);
		$wf = json_decode($body, true);
		if (!is_array($wf)) {
			$wf = [];
		}
		$wf[$field] = $value;
		$this->davPut($this->currentFilePath, json_encode($wf, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		// The save's writeback push (async) and the filename reconcile both run as
		// jobs; drain push first so n8n has the new name, then the rename job.
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\PushWorkflowJob');
		$this->drainJobs('OCA\\N8nSync\\BackgroundJob\\ReconcileNameJob');
		// After a filename_from_name reconcile the file moved; track its new path.
		$expected = $this->currentFolder . '/' . $value . '.n8n.json';
		if ($this->davExists($expected)) {
			$this->currentFilePath = $expected;
		}
	}

	/** @Then the JSON :field field inside the file becomes :value */
	public function theJsonFieldBecomes(string $field, string $value): void {
		$body = (string)$this->davGet($this->currentFilePath);
		$wf = json_decode($body, true);
		Assert::assertIsArray($wf, "file is not JSON:\n$body");
		Assert::assertSame($value, (string)($wf[$field] ?? ''), "JSON $field did not become '$value'");
	}

	/** @Then the workflow is renamed to :name in n8n */
	public function theWorkflowIsRenamedInN8n(string $name): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id captured');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "n8n has no workflow {$this->lastWorkflowId}");
		Assert::assertSame($name, (string)($wf['name'] ?? ''), "n8n workflow name is not '$name'");
	}

	/** @Then the file is renamed to :filename */
	public function theFileIsRenamedTo(string $filename): void {
		$expected = $this->currentFolder . '/' . $filename;
		Assert::assertTrue($this->davExists($expected), "expected the file at $expected, but it isn't there");
		$this->currentFilePath = $expected;
	}

	/** @When the file is renamed by any of the above means */
	public function theFileIsRenamedByAnyMeans(): void {
		// Exercise the filename→everywhere path (the simplest of the two).
		$this->iRenameTheFileTo('Renamed Link Check.n8n.json');
	}

	/** @Then the :key metadata is unchanged */
	public function theMetadataIsUnchanged(string $key): void {
		$value = $this->davReadMetadata($this->currentFilePath, $key);
		if ($key === self::META_ID) {
			Assert::assertSame($this->lastWorkflowId, $value, 'the n8n_id changed across the rename — the link broke');
		} else {
			Assert::assertNotNull($value, "metadata-$key is missing after rename");
		}
	}
}
