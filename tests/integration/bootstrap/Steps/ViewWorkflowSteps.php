<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * View-workflow steps (`view-workflow.feature`): the custom
 * mimetype + icon, the four `nc:metadata-*` props exposed (and read-only) over
 * WebDAV, and the per-mode descriptive `n8n_mode` value.
 *
 * Unlike open-with (which is front-end and can't be clicked from Behat), this is
 * pure WebDAV behaviour — PROPFIND / PROPPATCH are exactly what a desktop client
 * issues — so these are real assertions, not proxies. The metadata is registered
 * by {@see \OCA\N8nSync\Service\WorkflowMetadata} (all four keys EDIT_FORBIDDEN;
 * n8n_mode + n8n_mapping indexed) and the mimetype by the RegisterMimetype
 * migration. Reuses {@see OpenWithSteps::arrangeManagedFile} for the fixtures.
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait ViewWorkflowSteps {
	/** @var array<string,mixed>|null the last `list-workflows` payload */
	private ?array $cliListing = null;

	/** @var array<string,mixed>|null the last `get-workflow` payload */
	private ?array $cliWorkflow = null;

	/** Which workflow the CLI view asked for, so the Then can check it got that one. */
	private string $viewedWorkflowId = '';

	private const N8N_MIME = 'application/n8n+json';

	/** @var list<string> what the folder listing returned, for the icon assertion */
	private array $viewedFiles = [];

	// ── Given ─────────────────────────────────────────────────────────────────

	/** @Given a managed workflow file */
	public function aManagedWorkflowFileForType(): void {
		// Any managed file is enough for the type/PROPFIND/PROPPATCH scenarios; a
		// plain sync file is the simplest. (The mode-specific rows use the
		// "in :mode mode" step owned by OpenWithSteps.)
		$this->arrangeManagedFile('sync');
	}

	// ── the CLI view: what n8n holds, read without the mirror ──────────────────

	/** @When the admin lists the workflows tagged :tag */
	public function theAdminListsTheWorkflowsTagged(string $tag): void {
		$res = $this->occ('n8n_sync:list-workflows --tag=' . escapeshellarg($tag) . ' --limit=50');
		Assert::assertSame(0, $res['exit'], "list-workflows failed:\n{$res['output']}");
		$this->cliListing = json_decode($res['output'], true);
		Assert::assertIsArray($this->cliListing, "list-workflows did not return JSON:\n{$res['output']}");
	}

	/** @Then the listing names each of those workflows */
	public function theListingNamesEachOfThoseWorkflows(): void {
		Assert::assertNotEmpty($this->seededWorkflows, 'no seeded workflows to look for');

		$rows = $this->cliListing['data'] ?? $this->cliListing;
		Assert::assertIsArray($rows, 'the listing carried no rows');
		$ids = array_map(static fn (array $w): string => (string)($w['id'] ?? ''), $rows);

		foreach ($this->seededWorkflows as $name => $id) {
			Assert::assertContains((string)$id, $ids, "the listing does not name '$name' ($id)");
		}
	}

	/** @When the admin views one of those workflows by its id */
	public function theAdminViewsOneOfThoseWorkflowsById(): void {
		$id = (string)reset($this->seededWorkflows);
		Assert::assertNotSame('', $id, 'no seeded workflow to view');
		$this->viewedWorkflowId = $id;

		$res = $this->occ('n8n_sync:get-workflow ' . escapeshellarg($id));
		Assert::assertSame(0, $res['exit'], "get-workflow failed:\n{$res['output']}");
		$this->cliWorkflow = json_decode($res['output'], true);
	}

	/**
	 * @Then the workflow's JSON is printed
	 *
	 * ASSERTS IT IS THE RIGHT WORKFLOW, not merely that something was printed. A
	 * command that prints any valid JSON would satisfy the looser reading, and the
	 * point of viewing one BY ID is that you get that one.
	 */
	public function theWorkflowsJsonIsPrinted(): void {
		Assert::assertIsArray($this->cliWorkflow, 'get-workflow did not return JSON');
		Assert::assertSame(
			$this->viewedWorkflowId,
			(string)($this->cliWorkflow['id'] ?? ''),
			'get-workflow printed a different workflow',
		);
		Assert::assertArrayHasKey('nodes', $this->cliWorkflow, 'the printed JSON is not a workflow body');
	}

	// ── Then: mimetype + icon ───────────────────────────────────────────────────

	/**
	 * @When I open :folder in the Files app
	 *
	 * Opening a folder in the Files app is a Depth-1 PROPFIND — the same request the
	 * browser makes — so this lists the folder for real and remembers what came
	 * back. The assertion is in the matching Then.
	 */
	public function iOpenTheFolderInTheFilesApp(string $folder): void {
		$this->currentFolder = $folder;
		$this->viewedFiles = array_map(
			fn (string $href): string => $this->hrefToFilesPath($href),
			array_values($this->mappedFilesByWorkflowId($folder)),
		);
	}

	/**
	 * @Then the mapped folder shows the workflows with the n8n icon
	 *
	 * EVERY FILE THE USER JUST SAW, not merely the last one arranged. A folder of
	 * workflows where one row renders as a generic document is exactly the failure
	 * this scenario exists to catch, and checking a single file would miss it.
	 */
	public function theFilesAppShowsTheN8nIcon(): void {
		// The icon is a deterministic consequence of the registered mimetype: the
		// RegisterMimetype migration maps application/n8n+json → the app's icon, so
		// a file carrying that mimetype (not application/json) renders the n8n glyph.
		// Behat can't read the rendered pixels, so the mimetype IS the testable proof.
		$paths = $this->viewedFiles !== [] ? $this->viewedFiles : [$this->currentFilePath];
		Assert::assertNotSame([], array_filter($paths), 'no files to inspect — the folder listed nothing');

		foreach ($paths as $path) {
			$ct = $this->davContentType($path);
			Assert::assertSame(self::N8N_MIME, $ct, "$path does not carry the custom n8n mimetype — its icon would be the generic glyph");
		}
	}

	// ── When/Then: PROPFIND exposes the metadata ─────────────────────────────────

	/** @When /^a WebDAV client requests the file's properties(?: \(PROPFIND\))?$/ */
	public function aWebdavClientRequestsTheProperties(): void {
		// The assertion is in the matching Then; each property is read back via the
		// shared davReadMetadata (a Depth-0 PROPFIND that only returns 200-block props).
	}

	/**
	 * The app-managed properties a mirror carries, as ONE SENTENCE.
	 *
	 * It replaced a table of four property names spelled out in the feature file,
	 * which made the metadata look like a thing under test rather than the end
	 * state it is. Which four properties the app writes is the app's business, not
	 * the reader's — and every behaviour that produces a mirror wants to say "and
	 * it carries its metadata" without restating the list.
	 *
	 * @Then the file carries its n8n metadata
	 * @Then each file carries its n8n metadata
	 */
	public function theFileCarriesItsN8nMetadata(): void {
		$paths = $this->currentFilePath !== ''
			? [$this->currentFilePath]
			: array_map(fn (string $href): string => $this->hrefToFilesPath($href),
				array_values($this->mappedFilesByWorkflowId($this->folderNameForTag($this->currentTag))));

		Assert::assertNotSame([], $paths, 'no mirrored file to inspect');

		foreach ($paths as $path) {
			foreach (['n8n_id', 'n8n_mode', 'n8n_versionId', 'n8n_mapping'] as $key) {
				Assert::assertNotNull(
					$this->davReadMetadata($path, $key),
					"PROPFIND did not expose nc:metadata-$key on $path in a 200 block",
				);
			}
		}
	}

	// ── REPORT (indexed query) — @todo until the DAV-search plumbing is proven ────

	/** @When a DAV REPORT searches for files where :prop is :value */
	public function aDavReportSearchesForFilesWhere(string $prop, string $value): void {
		throw new \RuntimeException('DAV REPORT on nc:metadata-* not yet wired — confirm the search plumbing against the pod, then flip this @todo');
	}

	/** @Then only the file in :folder is returned */
	public function onlyTheFileInIsReturned(string $folder): void {
		throw new \RuntimeException('DAV REPORT on nc:metadata-* not yet wired — scenario is @todo');
	}
}
