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
	 * `whose tags are` is the same arrange with labels on it. `copy.feature`'s base
	 * case needs a file that is BOTH named (so it can spell the collision name it
	 * expects) and tagged (so it can claim the tags travelled), and those were two
	 * arranges that could not be used together — which is why the tags were a scenario
	 * of their own rather than part of the end state they belong to.
	 *
	 * @Given a workflow file named :filename in :folder
	 * @Given a workflow file named :filename in :folder whose tags are :tags
	 * @Given a workflow file named :filename in a subfolder of :folder
	 */
	public function aWorkflowFileNamedIn(string $filename, string $folder, string $tags = ''): void {
		if (str_contains((string)func_get_arg(1), 'subfolder')) {
			$folder .= '/Nested';
		}
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$stem = preg_replace('/\.n8n$/', '', $filename) ?? $filename;
		$path = $folder . '/' . $filename;

		// A NAMED FILE MUST BE A NEW FILE, and in a Team Folder that is not free.
		//
		// The teardown deletes the folders it made, but a Team Folder is provisioned by
		// the app rather than by MKCOL, so a DELETE of its mount point does not clear it
		// — anything a scenario left inside is still there for the next one. A PUT over
		// that leftover reuses its file id AND its metadata row, so the "new" file is born
		// already carrying an `n8n_id` whose workflow the previous teardown deleted from
		// n8n. create-on-land then bails (the file looks managed), and the first n8n call
		// of whatever follows 404s on a dead id.
		//
		// That cost a whole CI cycle to find, and it is the same lesson
		// {@see MoveSteps::arrangeManagedFileIn} already carries: it solved it by
		// generating a unique name. A scenario that SAYS its filename cannot do that, so
		// it clears the ground instead.
		//
		// EVERY MAPPED FOLDER, not just this one. A move scenario ends with the file
		// somewhere it did not start, so the leftover the next row trips over is usually
		// in the DESTINATION — where it also collides by name, and Nextcloud refuses that
		// move with a 412 long before this app sees it.
		$this->clearNamedFileEverywhere($filename, $folder);

		// MANAGED ONLY IF IT LANDED IN A MAPPING. {@see putManagedFile} asserts an
		// `n8n_id` was stamped, which is right for the rename scenarios that own it and
		// wrong the moment a scenario names a file in an UNMAPPED folder — `copy.feature`
		// arranges one in "Scratch", where there is no id by definition and that is the
		// arrange rather than a failure. Asserted arrangements fail in the least
		// informative place there is: the Given, before the behaviour under test has run.
		$names = self::tagList($tags);
		if ($this->isMappedFolder($folder)) {
			$this->putManagedFile($path, $stem, $names);
		} else {
			$this->davPut($path, json_encode(self::starterWorkflow($stem, $names), JSON_THROW_ON_ERROR));
			$this->currentFilePath = $path;
			$this->lastWorkflowId = null;
		}

		// CAPTURE THE PRE-STATE, so a scenario that names its file can still claim "the
		// original is unchanged" afterwards. `copy.feature` needs both halves: it can
		// only spell the collision name it expects if it chose the original's name, and
		// it can only prove the original survived if something read it first.
		$this->originalPath = $this->currentFilePath;
		$this->copyOriginalBefore = $this->readManagedMetadata($this->originalPath);
	}

	/**
	 * Remove `$filename` from every mapped folder and from `$folder`, so a scenario that
	 * names its file starts from a clean slate wherever it is about to move it.
	 *
	 * Deleting a managed mirror is itself a gesture — it archives the workflow in n8n —
	 * which is exactly right for a leftover: the previous scenario's teardown has already
	 * removed the workflow it belonged to, so what is left is a file pointing at nothing.
	 */
	private function clearNamedFileEverywhere(string $filename, string $folder): void {
		$folders = [$folder];
		foreach ($this->listMappings() as $m) {
			$mapped = (string)($m['team_folder'] ?? '');
			if ($mapped !== '' && !in_array($mapped, $folders, true)) {
				$folders[] = $mapped;
			}
		}
		foreach ($folders as $dir) {
			$candidate = $dir . '/' . $filename;
			if ($this->davExists($candidate)) {
				$this->davDelete($candidate);
			}
		}
	}

	/** True when a mapping owns $folder — i.e. a file landing there becomes managed. */
	private function isMappedFolder(string $folder): bool {
		foreach ($this->listMappings() as $m) {
			if ((string)($m['team_folder'] ?? '') === $folder) {
				return true;
			}
		}
		return false;
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
		$this->putManagedFile($this->currentFolder . '/' . $name . '.n8n', $name);
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
		$expected = $this->currentFolder . '/' . $value . '.n8n';
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
		$expected = $this->currentFolder . '/' . $name . '.n8n';
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
				'filename' => basename($this->currentFilePath, '.n8n'),
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
