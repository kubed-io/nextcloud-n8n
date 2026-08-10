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
	 * A workflow file with a known name in a NAMED folder — the "before" half of
	 * every rename here. The Background says what the folder is, so this arrange does
	 * not restate the mode.
	 *
	 * @Given a workflow file named :filename in :folder
	 * @Given a workflow file named :filename in a subfolder of :folder
	 */
	public function aWorkflowFileNamedIn(string $filename, string $folder): void {
		if (str_contains((string)func_get_arg(1), 'subfolder')) {
			$folder .= '/Nested';
		}
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$stem = preg_replace('/\.n8n\.json$/', '', $filename) ?? $filename;
		$this->putManagedFile($folder . '/' . $filename, $stem);
	}

	/**
	 * A managed file with a generated name, in a mapping of its own.
	 *
	 * KEPT FOR THE FILES THAT HAVE NOT BEEN CONVERTED YET — `delete.feature` and
	 * friends still say "a managed sync workflow file" without naming a folder,
	 * which works because this arrange makes one for them. Files brought up to the
	 * standard say where their file is instead, and use the folder-named arrange
	 * above.
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

	/**
	 * @When I change the JSON :field field to :value
	 * @When I edit the file and change the JSON :field field to :value
	 */
	public function iEditTheJsonField(string $field, string $value): void {
		$body = (string)$this->davGet($this->currentFilePath);
		// Object decode, because this PUTs the whole body back: an assoc round-trip
		// turns the managed file's empty `connections`/`settings` objects into `[]`,
		// which n8n rejects on the next push. Editing the name must not reshape the
		// rest of the workflow.
		$wf = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
		if (!$wf instanceof \stdClass) {
			$wf = new \stdClass();
		}
		$wf->{$field} = $value;
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

	/**
	 * THE WHOLE POINT OF THIS FILE, IN ONE ASSERTION: the name is the same value in
	 * all three places it lives — the filename stem, the JSON `name`, and the
	 * workflow in n8n.
	 *
	 * It replaced three separate `Then`s that each checked one place, and a fourth
	 * (`Renaming never breaks the link`) that checked the id survived. Split up, a
	 * scenario could assert two of the three and look complete while the third had
	 * drifted — which is the only failure mode that matters here, since any one of
	 * them being right is not the claim. The claim is that they AGREE.
	 *
	 * @Then the name is :name in the filename, the JSON, and n8n
	 */
	public function theNameIsEverywhere(string $name): void {
		$expected = $this->currentFolder . '/' . $name . '.n8n.json';
		Assert::assertTrue($this->davExists($expected), "expected the file at $expected, but it isn't there");
		$this->currentFilePath = $expected;

		$body = (string)$this->davGet($this->currentFilePath);
		$wf = json_decode($body, true);
		Assert::assertIsArray($wf, "file is not JSON:\n$body");

		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id captured');
		$remote = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($remote, "n8n has no workflow {$this->lastWorkflowId}");

		Assert::assertSame(
			['filename' => $name, 'JSON' => $name, 'n8n' => $name],
			[
				'filename' => basename($this->currentFilePath, '.n8n.json'),
				'JSON' => (string)($wf['name'] ?? ''),
				'n8n' => (string)($remote['name'] ?? ''),
			],
			'the three places a name lives do not agree',
		);
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
