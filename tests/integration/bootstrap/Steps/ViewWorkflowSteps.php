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

	/** The mapping the current file belongs to, for the `the mapping's id` cell. */
	private string $currentMappingId = '';

	/** @var list<string> what the folder listing returned, for the icon assertion */
	private array $viewedFiles = [];

	/** Carried between the PROPPATCH When and its Then. */
	private string $proppatchKey = '';
	private ?string $proppatchOriginal = null;
	private int $proppatchStatus = 0;
	private string $proppatchBody = '';

	// ── Given ─────────────────────────────────────────────────────────────────

	/** @Given a managed workflow file */
	public function aManagedWorkflowFileForType(): void {
		// Any managed file is enough for the type/PROPFIND/PROPPATCH scenarios; a
		// plain sync file is the simplest. (The mode-specific rows use the
		// "in :mode mode" step owned by OpenWithSteps.)
		$this->arrangeManagedFile('sync');
	}

	/**
	 * @Given a workflow :name mirrored into that folder
	 *
	 * The follow-on to `a mapping with the following values:`, which describes the
	 * mapping but does not create its folder — it is a pre-state for scenarios
	 * about the mapping itself, where nothing is ever stored in it.
	 *
	 * "That folder" is the one the table just named. It is read back from the store
	 * rather than taken from the table, which is what makes the scenario's claim
	 * honest: the file lands where the APP put the mapping's folder, not where the
	 * test assumed it would be. The preceding step resets the store, so there is
	 * exactly one mapping to find.
	 */
	public function aWorkflowMirroredIntoThatFolder(string $name): void {
		$mappings = $this->listMappings();
		if (count($mappings) !== 1) {
			$this->fail('expected exactly one mapping in the store, found ' . count($mappings));
		}
		$mapping = $mappings[0];

		$folder = (string)($mapping['team_folder'] ?? '');
		if ($folder === '') {
			$this->fail('the mapping stored no folder to mirror into');
		}
		$this->currentMappingId = (string)($mapping['id'] ?? '');
		$this->currentTag = (string)($mapping['n8n_tag'] ?? '');
		$this->currentFolder = $folder;

		$this->davMkdir($folder);
		// A PUT into a mapped folder is create-on-land: the app makes the workflow
		// in n8n and stamps the file. putManagedFile fails loudly if it did not.
		$this->putManagedFile($folder . '/' . $name . '.n8n.json', $name);
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

	/** @Then its mimetype is :mime */
	public function itsMimetypeIs(string $mime): void {
		Assert::assertSame($mime, $this->davContentType($this->currentFilePath), 'the file does not carry the custom mimetype');
	}

	/**
	 * @When the user views the contents of the mapped folder
	 *
	 * Opening a folder in the Files app is a Depth-1 PROPFIND — the same request the
	 * browser makes — so this lists the folder for real and remembers what came
	 * back. The assertion is in the matching Then.
	 */
	public function theUserViewsTheContentsOfTheMappedFolder(): void {
		Assert::assertNotSame('', $this->currentFolder, 'no mapped folder — a Given must set one');
		$this->viewedFiles = array_map(
			fn (string $href): string => $this->hrefToFilesPath($href),
			array_values($this->mappedFilesByWorkflowId($this->currentFolder)),
		);
	}

	/**
	 * @Then the mapped folder shows the workflows with the n8n icon
	 * @Then the Files app shows the n8n icon instead of a generic JSON icon
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

	/**
	 * @Then the response carries the properties the app manages:
	 *
	 * Reads each `nc:metadata-*` prop back with a Depth-0 PROPFIND that only counts
	 * values returned in a 200 block, so a prop the server reports as 404 fails
	 * here rather than reading as an empty string.
	 *
	 * THE VALUE COLUMN TAKES THREE FORMS, and no more — a table that can say
	 * anything stops being readable:
	 *
	 *   set                  present and non-empty; the value is opaque by design
	 *   the workflow's id    the id the app stamped when the file landed
	 *   the mapping's id     the mapping the file came from
	 *   anything else        an exact literal (the mode's stored value)
	 *
	 * The two `the …'s id` forms exist because presence is too weak for them: an id
	 * that is merely non-empty could be any workflow's, and the whole point of
	 * publishing it is that it names THIS one.
	 */
	public function theResponseCarriesTheManagedProperties(TableNode $table): void {
		Assert::assertNotSame('', $this->currentFilePath, 'no file to inspect — a Given must arrange one');

		foreach ($table->getHash() as $row) {
			$prop = trim((string)($row['property'] ?? ''));
			$expected = trim((string)($row['value'] ?? ''));
			$key = $this->metadataKeyFromProp($prop);
			$actual = $this->davReadMetadata($this->currentFilePath, $key);

			if ($actual === null || $actual === '') {
				$this->fail("PROPFIND did not return $prop on {$this->currentFilePath} in a 200 block");
			}

			$want = match ($expected) {
				'set' => null,
				"the workflow's id" => (string)$this->lastWorkflowId,
				"the mapping's id" => $this->currentMappingId,
				default => $expected,
			};
			if ($want === null) {
				continue;
			}
			if ($want === '') {
				$this->fail("the scenario asked for '$expected' but the arrange never recorded one");
			}
			Assert::assertSame($want, $actual, "$prop carried the wrong value");
		}
	}

	/** @Then its :prop property is :value */
	public function itsPropertyIs(string $prop, string $value): void {
		$key = $this->metadataKeyFromProp($prop);
		Assert::assertSame($value, $this->davReadMetadata($this->currentFilePath, $key), "$prop did not carry the expected value");
	}

	// ── When/Then: metadata is read-only (PROPPATCH rejected) ────────────────────

	/** @When a client tries to change :prop via PROPPATCH */
	public function aClientTriesToChangeViaProppatch(string $prop): void {
		$this->proppatchKey = $this->metadataKeyFromProp($prop);
		$this->proppatchOriginal = $this->davReadMetadata($this->currentFilePath, $this->proppatchKey);
		$res = $this->davProppatch($this->currentFilePath, $prop, 'tampered-by-client');
		$this->proppatchStatus = $res->getStatusCode();
		$this->proppatchBody = (string)$res->getBody();
	}

	/** @Then the change is rejected — the sync engine owns these properties */
	public function theChangeIsRejected(): void {
		// The load-bearing guarantee: the value did not change. (NC reports the
		// forbidden prop as a 403 inside the 207 multistatus — also asserted.)
		Assert::assertSame(
			$this->proppatchOriginal,
			$this->davReadMetadata($this->currentFilePath, $this->proppatchKey),
			'the property changed — PROPPATCH was NOT rejected (these props must be read-only)',
		);
		$rejected = $this->proppatchStatus >= 400
			|| str_contains($this->proppatchBody, '403')
			|| stripos($this->proppatchBody, 'forbidden') !== false;
		Assert::assertTrue($rejected, "PROPPATCH did not report a rejection (HTTP {$this->proppatchStatus}):\n{$this->proppatchBody}");
	}

	// ── REPORT (indexed query) — @todo until the DAV-search plumbing is proven ────

	/** @Given a :modeA workflow file and a :modeB workflow file in the same user's storage */
	public function twoWorkflowFilesInStorage(string $modeA, string $modeB): void {
		throw new \RuntimeException('REPORT-by-indexed-mode harness pending (view-workflow.feature scenario is @blocked)');
	}

	/** @When a DAV REPORT searches for files where :prop is :value */
	public function aDavReportSearchesForFilesWhere(string $prop, string $value): void {
		throw new \RuntimeException('DAV REPORT on nc:metadata-* not yet wired — confirm the search plumbing against the pod, then flip this @todo');
	}

	/** @Then only the sync file is returned */
	public function onlyTheSyncFileIsReturned(): void {
		throw new \RuntimeException('DAV REPORT on nc:metadata-* not yet wired — scenario is @todo');
	}

	// ── DAV plumbing ──────────────────────────────────────────────────────────────

	/** `nc:metadata-n8n_mode` → `n8n_mode` (the key davReadMetadata wants). */
	private function metadataKeyFromProp(string $prop): string {
		$local = str_contains($prop, ':') ? substr($prop, (int)strpos($prop, ':') + 1) : $prop;
		return str_starts_with($local, 'metadata-') ? substr($local, strlen('metadata-')) : $local;
	}


	/** Attempt to set a single nc:metadata-* prop via PROPPATCH; returns the raw response. */
	private function davProppatch(string $path, string $prop, string $value): \Psr\Http\Message\ResponseInterface {
		$local = str_contains($prop, ':') ? substr($prop, (int)strpos($prop, ':') + 1) : $prop;
		$body = '<?xml version="1.0"?>'
			. '<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:set><d:prop><nc:' . $local . '>' . htmlspecialchars($value, ENT_XML1) . '</nc:' . $local . '></d:prop></d:set>'
			. '</d:propertyupdate>';
		return $this->davClient()->request('PROPPATCH', $this->davEncode($path), [
			'headers' => ['Content-Type' => 'application/xml'],
			'body' => $body,
		]);
	}
}
