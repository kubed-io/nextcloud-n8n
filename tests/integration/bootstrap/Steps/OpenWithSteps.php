<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Open-with steps (saga §14.8 `open-with.feature`): which openers a managed
 * workflow file offers and which is the default click, driven by the file's
 * MODE (not its type).
 *
 * Behat can't drive the Files app's JavaScript, so these steps verify the
 * *server-observable backing state* the front-end keys off — they are deliberately
 * NOT an attempt to simulate a click (that would be an illusion):
 *  - the DAV-exposed `n8n_mode` (sync / reference=link / unmapped), the
 *    exact signal `canOpenInN8n(mode)` / `defaultOpener(mode)` read in
 *    src/files-helpers.js;
 *  - whether the workflow is LIVE (sync/link) or ARCHIVED (unmapped) in
 *    n8n — "is there anything to open"; and
 *  - that the raw JSON is readable over WebDAV (what the text editor loads).
 *
 * The opener *decision logic* itself is unit-tested concretely in
 * tests/js/files-helpers.test.js. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait OpenWithSteps {
	/** The opener the scenario picked ("Open in n8n" / "Open with text editor"). */
	private string $lastOpenerChoice = '';

	// ── Given ─────────────────────────────────────────────────────────────────

	/**
	 * A managed file in a specific mode. Distinct step text from RenameSteps'
	 * "a managed :mode workflow file" (which only makes sync/link via a mapping) —
	 * open-with also needs an `unmapped` file, so it owns this arrange.
	 *
	 * @Given a managed workflow file in :mode mode
	 */
	public function aManagedWorkflowFileInMode(string $mode): void {
		$this->arrangeManagedFile($mode);
	}

	// ── When ──────────────────────────────────────────────────────────────────

	/** @When I choose :opener from its context menu */
	public function iChooseOpenerFromContextMenu(string $opener): void {
		// No browser to click; the assertion lives in the matching Then.
		$this->lastOpenerChoice = $opener;
	}

	/**
	 * Opening the menu performs nothing — what is under test is which entries it
	 * offers, which the Then reads off the file's own mode.
	 *
	 * @When /^I look at its context menu$/
	 */
	public function iLookAtItsContextMenu(): void {
		// No-op by design; the assertions follow.
	}

	/** @When I click the file in the Files app */
	public function iClickTheFileInTheFilesApp(): void {
		// A plain row-click resolves to the default opener; asserted in the Then.
	}

	// ── Then ──────────────────────────────────────────────────────────────────

	/** Called from theActionIsOfferedOrHidden (no live Gherkin phrasing of its own). */
	public function n8nOpensAtThatWorkflow(): void {
		// "Open in n8n" jumps to <n8nUrl>/workflow/<n8n_id>; the real backing is a
		// LIVE workflow (mode sync/link) with an id to deep-link to.
		$mode = $this->davReadMetadata($this->currentFilePath, self::META_MODE);
		Assert::assertContains($mode, ['sync', 'reference'], "Open in n8n is only offered for sync/link; mode was '$mode'");
		$id = $this->davReadMetadata($this->currentFilePath, self::META_ID);
		Assert::assertNotNull($id, 'no n8n_id to open');
		Assert::assertNotSame('', $id, 'no n8n_id to open');
		$wf = $this->n8nGetWorkflow($id);
		Assert::assertIsArray($wf, "workflow $id is not in n8n — nothing to open");
		Assert::assertFalse((bool)($wf['isArchived'] ?? false), 'workflow is archived — not a live workflow to open');
	}

	/**
	 * OFFERED OR HIDDEN, as one step, so the four modes are one outline instead
	 * of a scenario for the offered case and one per hidden mode. Offering is
	 * asserted through the thing that makes it meaningful — a live workflow to
	 * open — rather than by reading the front-end's own answer back.
	 *
	 * @Then /^"([^"]*)" is (offered|hidden)$/
	 */
	public function theActionIsOfferedOrHidden(string $action, string $verdict): void {
		if ($verdict === 'offered') {
			$this->n8nOpensAtThatWorkflow();

			return;
		}
		$this->theActionIsHiddenFromContextMenu($action);
	}

	/** Called from theActionIsOfferedOrHidden (no live Gherkin phrasing of its own). */
	public function theActionIsHiddenFromContextMenu(string $action): void {
		Assert::assertSame('Open in n8n', $action, 'this step only models hiding the "Open in n8n" action');
		// The front-end hides it exactly when canOpenInN8n(mode) is false → the
		// backing mode is unmapped. Assert that, plus that no live workflow
		// remains behind it.
		$mode = $this->davReadMetadata($this->currentFilePath, self::META_MODE);
		Assert::assertContains($mode, ['unmapped'], "\"Open in n8n\" would NOT be hidden for mode '$mode'");
		$id = $this->davReadMetadata($this->currentFilePath, self::META_ID);
		if ($id !== null && $id !== '') {
			$wf = $this->n8nGetWorkflow($id);
			if (is_array($wf)) {
				Assert::assertTrue((bool)($wf['isArchived'] ?? false), 'a live (non-archived) workflow still exists — "nothing to open" is false');
			}
		}
	}

	/** @Then the file's raw JSON opens in the text editor */
	public function theRawJsonOpensInTheTextEditor(): void {
		// The text editor loads the file via WebDAV getFileContents; the testable
		// guarantee is that the raw bytes are present and valid JSON, for any mode.
		$body = (string)$this->davGet($this->currentFilePath);
		$decoded = json_decode($body, true);
		Assert::assertIsArray($decoded, "the file did not return readable JSON over DAV:\n$body");
	}

	/** @Then it opens with :opener by default */
	public function itOpensWithOpenerByDefault(string $opener): void {
		$mode = $this->davReadMetadata($this->currentFilePath, self::META_MODE);
		// Front-end rule (defaultOpener): sync/link → n8n; unmapped → text.
		$expected = $mode === 'unmapped' ? 'text editor' : 'n8n';
		Assert::assertSame($expected, $opener, "the default opener for mode '$mode' should be '$expected'");
	}

	// ── arrange ─────────────────────────────────────────────────────────────────

	/**
	 * Stand up a managed workflow file in the requested mode, leaving
	 * `currentFilePath` pointing at it and `expectedArchived` set for n8n checks.
	 *
	 * - sync: a file in a sync mapping (live workflow, mode=sync).
	 * - link: a file in a link mapping (mode=reference).
	 * - unmapped: a sync file moved OUT of its mapping (archived, mode=unmapped).
	 */
	private function arrangeManagedFile(string $mode): void {
		switch ($mode) {
			case 'sync':
				$this->setupSyncMappingAndFolder('sync', 'nextcloud:openwith');
				$this->putManagedFile($this->currentFolder . '/Opener.n8n', 'Opener');
				$this->expectedArchived = false;
				break;
			case 'link':
				$this->setupSyncMappingAndFolder('link', 'nextcloud:openwith-link');
				$this->putManagedFile($this->currentFolder . '/Opener.n8n', 'Opener');
				$this->expectedArchived = false;
				break;
			case 'unmapped':
				$this->setupSyncMappingAndFolder('sync', 'nextcloud:openwith');
				$this->putManagedFile($this->currentFolder . '/Opener.n8n', 'Opener');
				$this->iMoveTheFileToAnUnmappedFolder(); // sets currentFilePath + expectedArchived=true
				break;
			default:
				throw new \InvalidArgumentException("unknown mode '$mode'");
		}
	}
}
