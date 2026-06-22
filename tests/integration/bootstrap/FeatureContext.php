<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration;

use Behat\Behat\Context\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\Assert;

/**
 * Behat step definitions for the n8n_sync integration suite.
 *
 * Transport: three channels, each faithful to a real actor —
 *  - **occ** (the $OCC env var, e.g. "php occ" run from the server root) drives
 *    admin setup the way an operator / our own CLI commands do.
 *  - **WebDAV** (Guzzle, basic-auth as the admin user) writes/reads/PROPFINDs
 *    files the way the desktop client or web UI would — this is what fires the
 *    NodeWrittenEvent the create/delete/rename listeners hang off, so it is the
 *    only way to exercise the real server-side wiring.
 *  - **n8n REST** (Guzzle, X-N8N-API-KEY) is the assertion side: did the app
 *    actually create / tag / archive / delete the workflow in n8n? It is also
 *    used to clean up workflows the scenarios create so re-runs stay isolated.
 */
final class FeatureContext implements Context {
	private const APP_ID = 'n8n_sync';

	/**
	 * The DAV-exposed metadata key for the workflow id. Mirrors
	 * {@see \OCA\N8nSync\Service\WorkflowMetadata::KEY_ID}; redeclared here as a
	 * literal because the integration suite autoloads only its own bootstrap/,
	 * not the app's lib/. The Gherkin says "n8n_id"; this is the same string.
	 */
	private const META_ID = 'n8n_id';

	/** The occ invocation prefix, e.g. "php occ". */
	private string $occ;

	/** Result of the most recent occ command. */
	private int $lastExit = 0;
	private string $lastOutput = '';

	// ── HTTP channels (lazily built so occ-only scenarios pay nothing) ────────
	private ?Client $dav = null;
	private ?Client $n8n = null;

	private string $ncBaseUrl;
	private string $ncUser;
	private string $ncPass;
	private string $n8nUrl;
	private string $n8nApiKey;

	/**
	 * NC folders this scenario created (relative to the user's files root), torn
	 * down after the scenario so re-runs start clean.
	 *
	 * @var list<string>
	 */
	private array $createdFolders = [];

	/**
	 * n8n workflow ids the app (or the scenario) created, deleted in teardown so
	 * the n8n service doesn't accumulate test workflows across re-runs.
	 *
	 * @var list<string>
	 */
	private array $createdWorkflowIds = [];

	/** State carried between steps within a scenario. */
	private string $currentFolder = '';
	private string $currentFilePath = '';
	private ?string $lastWorkflowId = null;
	private string $currentTag = '';
	private int $lastDeleteStatus = 0;

	public function __construct() {
		$this->occ = getenv('OCC') ?: 'php occ';
		$this->ncBaseUrl = rtrim(getenv('NC_BASE_URL') ?: 'http://localhost:8080', '/');
		$this->ncUser = getenv('NC_ADMIN_USER') ?: 'admin';
		$this->ncPass = getenv('NC_ADMIN_PASS') ?: 'admin';
		$this->n8nUrl = rtrim(getenv('N8N_URL') ?: 'http://localhost:5678', '/');
		$this->n8nApiKey = getenv('N8N_API_KEY') ?: '';
	}

	// ── occ plumbing ────────────────────────────────────────────────────────

	/**
	 * Run an occ command. $args is appended to the occ prefix verbatim.
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occ(string $args): array {
		$cmd = $this->occ . ' ' . $args . ' 2>&1';
		$output = [];
		$exit = 0;
		exec($cmd, $output, $exit);
		$this->lastExit = $exit;
		$this->lastOutput = implode("\n", $output);
		return ['exit' => $exit, 'output' => $this->lastOutput];
	}

	// ── lifecycle steps ───────────────────────────────────────────────────────
	// Steps read in plain English (medium-agnostic); occ is an implementation
	// detail of the step definitions, not the feature.

	/**
	 * Precondition: make sure the app is enabled (idempotent).
	 *
	 * @Given the app is enabled
	 */
	public function givenTheAppIsEnabled(): void {
		$this->occ('app:enable --force ' . self::APP_ID);
	}

	/** @When the admin enables the app */
	public function theAdminEnablesTheApp(): void {
		$res = $this->occ('app:enable --force ' . self::APP_ID);
		Assert::assertSame(0, $res['exit'], "enabling the app failed:\n{$res['output']}");
	}

	/** @When the admin disables the app */
	public function theAdminDisablesTheApp(): void {
		$res = $this->occ('app:disable ' . self::APP_ID);
		Assert::assertSame(0, $res['exit'], "disabling the app failed:\n{$res['output']}");
	}

	/** @Then the app should be enabled */
	public function theAppIsEnabled(): void {
		$res = $this->occ('app:list');
		Assert::assertMatchesRegularExpression(
			'/^\s+- ' . preg_quote(self::APP_ID, '/') . ':/m',
			$this->enabledBlock($res['output']),
			'the app is not in the Enabled list',
		);
	}

	/** @Then the app is not enabled */
	public function theAppIsNotEnabled(): void {
		$res = $this->occ('app:list');
		Assert::assertDoesNotMatchRegularExpression(
			'/^\s+- ' . preg_quote(self::APP_ID, '/') . ':/m',
			$this->enabledBlock($res['output']),
			'the app is still enabled',
		);
	}

	/** @Then the app is installed correctly */
	public function theAppIsInstalledCorrectly(): void {
		$res = $this->occ('app:getpath ' . self::APP_ID);
		Assert::assertSame(0, $res['exit'], 'app:getpath failed');
		Assert::assertNotSame('', trim($res['output']), 'app path did not resolve');
	}

	// ── admin-setup steps ─────────────────────────────────────────────────────

	/** @When I set app config :key to :value */
	public function iSetAppConfig(string $key, string $value): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' ' . escapeshellarg($key) . ' --value=' . escapeshellarg($value));
		Assert::assertSame(0, $res['exit'], "config:app:set $key failed:\n{$res['output']}");
	}

	/**
	 * Multi-line (PyString) form, e.g. for the mappings JSON.
	 *
	 * @When I set app config :key to:
	 */
	public function iSetAppConfigMultiline(string $key, \Behat\Gherkin\Node\PyStringNode $value): void {
		$this->iSetAppConfig($key, $value->getRaw());
	}

	/** @When I set sensitive app config :key to :value */
	public function iSetSensitiveAppConfig(string $key, string $value): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' ' . escapeshellarg($key) . ' --value=' . escapeshellarg($value) . ' --sensitive');
		Assert::assertSame(0, $res['exit'], "config:app:set $key (sensitive) failed:\n{$res['output']}");
	}

	/** @Then app config :key is :expected */
	public function appConfigIs(string $key, string $expected): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' ' . escapeshellarg($key));
		Assert::assertSame($expected, trim($res['output']), "config $key mismatch");
	}

	/** @Then app config :key contains :needle */
	public function appConfigContains(string $key, string $needle): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' ' . escapeshellarg($key));
		Assert::assertStringContainsString($needle, $res['output'], "config $key did not contain '$needle'");
	}

	// ── connection steps (the "admin makes connection" use case) ──────────────

	/** @Given the app is installed and enabled */
	public function theAppIsInstalledAndEnabled(): void {
		$this->occ('app:enable --force ' . self::APP_ID);
	}

	/** @When the admin sets the n8n base URL */
	public function theAdminSetsTheN8nBaseUrl(): void {
		$url = getenv('N8N_URL') ?: 'http://localhost:5678';
		$res = $this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($url));
		Assert::assertSame(0, $res['exit'], "setting the base URL failed:\n{$res['output']}");
	}

	/**
	 * Store the real, CI-provided key the way the admin UI does (encrypted).
	 *
	 * @When the admin provides the n8n API key
	 */
	public function theAdminProvidesTheN8nApiKey(): void {
		$key = getenv('N8N_API_KEY') ?: '';
		Assert::assertNotSame('', $key, 'N8N_API_KEY is not set — the test setup must provide it');
		$res = $this->occStdin($this->occ . ' n8n_sync:set-api-key', $key);
		Assert::assertSame(0, $res['exit'], "providing the API key failed:\n{$res['output']}");
	}

	/** @When the admin provides an invalid API key */
	public function theAdminProvidesAnInvalidApiKey(): void {
		$res = $this->occStdin($this->occ . ' n8n_sync:set-api-key', 'not-a-real-key');
		Assert::assertSame(0, $res['exit'], "storing the (invalid) key failed:\n{$res['output']}");
	}

	/** @When the admin enables the REST API */
	public function theAdminEnablesTheRestApi(): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' api_enabled --value=1');
		Assert::assertSame(0, $res['exit'], "enabling the REST API failed:\n{$res['output']}");
	}

	/** @Given the admin has set the n8n base URL and enabled the REST API */
	public function theAdminHasSetUrlAndEnabledApi(): void {
		$this->theAdminSetsTheN8nBaseUrl();
		$this->theAdminEnablesTheRestApi();
	}

	/** @When the admin tests the connection */
	public function theAdminTestsTheConnection(): void {
		$this->occ('n8n_sync:test-connection');
	}

	/** @Then the connection is verified */
	public function theConnectionIsVerified(): void {
		Assert::assertSame(0, $this->lastExit, "the connection test failed:\n{$this->lastOutput}");
	}

	/** @Then the connection test reports a failure */
	public function theConnectionTestReportsAFailure(): void {
		Assert::assertNotSame(0, $this->lastExit, "the connection test unexpectedly succeeded:\n{$this->lastOutput}");
	}

	// ── mapping steps ─────────────────────────────────────────────────────────
	// The feature describes mappings in plain English (titled table columns); the
	// step translates "storage"/"mode" words into the data model and adds them.

	/** @When the admin adds these mappings: */
	public function theAdminAddsTheseMappings(\Behat\Gherkin\Node\TableNode $table): void {
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

	// ── create-on-land steps (UC-6: author in NC, live in n8n) ─────────────────
	// A managed .n8n.json written into a mapped folder over WebDAV fires
	// NodeWrittenEvent → CreateInN8nListener → the workflow appears in n8n. We
	// assert the n8n side over its REST API and the NC stamp over DAV PROPFIND.

	/**
	 * Set up an admin-owned (no groupfolders) mapping + the backing folder so a
	 * WebDAV PUT into it resolves to a mapping. Admin-owned keeps CI free of the
	 * groupfolders app; resolveForPath only cares about the folder name.
	 *
	 * @Given a folder mapped as :mode to the n8n tag :tag
	 */
	public function aFolderMappedAsModeToTag(string $mode, string $tag): void {
		$folder = $this->folderNameForTag($tag);
		$data = [
			'n8n_tag' => $tag,
			'team_folder' => $folder,
			'nc_groups' => ['admin'],
			'mode' => $this->modeToModel($mode),
			'use_team_folder' => false,
		];
		$res = $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
		Assert::assertSame(0, $res['exit'], "adding mapping for $tag failed:\n{$res['output']}");
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
	}

	/** @Given a folder that is not mapped */
	public function aFolderThatIsNotMapped(): void {
		$folder = 'unmapped-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
	}

	/**
	 * Create a workflow file over WebDAV. Both phrasings ("via the Files New
	 * menu" and a plain create) land the same way server-side — a PUT that fires
	 * NodeWrittenEvent — so one step backs both.
	 *
	 * @When I create a new :ext file in that folder via the Files "New" menu
	 * @When I create a :ext file in that folder
	 */
	public function iCreateAWorkflowFile(string $ext): void {
		Assert::assertNotSame('', $this->currentFolder, 'no current folder — a Given must set one');
		$name = 'demo-' . bin2hex(random_bytes(3)) . $ext;
		$path = $this->currentFolder . '/' . $name;
		// A minimal but valid starter workflow body, like the New-menu template.
		$body = json_encode([
			'name' => 'Demo ' . substr($name, 0, 12),
			'nodes' => [],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		], JSON_THROW_ON_ERROR);
		$this->davPut($path, $body);
		$this->currentFilePath = $path;
		// Remember any workflow the app just created so teardown can delete it.
		$id = $this->davReadMetadataId($path);
		if ($id !== null && $id !== '') {
			$this->lastWorkflowId = $id;
			$this->createdWorkflowIds[] = $id;
		} else {
			$this->lastWorkflowId = null;
		}
	}

	/** @Then a matching workflow is created in n8n */
	public function aMatchingWorkflowIsCreatedInN8n(): void {
		Assert::assertNotNull($this->lastWorkflowId, 'the file was not stamped with an n8n_id — no workflow was created');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "n8n has no workflow with id {$this->lastWorkflowId}");
		Assert::assertSame($this->lastWorkflowId, (string)($wf['id'] ?? ''), 'n8n returned a different workflow id');
	}

	/** @Then the workflow carries the :tag tag */
	public function theWorkflowCarriesTheTag(string $tag): void {
		Assert::assertNotNull($this->lastWorkflowId, 'no workflow id captured');
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		$names = array_map(
			static fn (array $t): string => (string)($t['name'] ?? ''),
			array_values(array_filter((array)($wf['tags'] ?? []), 'is_array')),
		);
		Assert::assertContains($tag, $names, "workflow {$this->lastWorkflowId} is not tagged '$tag' (has: " . implode(',', $names) . ')');
	}

	/** @Then the file is stamped with the workflow's :key */
	public function theFileIsStampedWith(string $key): void {
		$value = $this->davReadMetadata($this->currentFilePath, $key);
		Assert::assertNotNull($value, "file has no metadata-$key");
		Assert::assertNotSame('', $value, "metadata-$key is empty");
		if ($key === self::META_ID) {
			Assert::assertSame($this->lastWorkflowId, $value, 'stamped id disagrees with the n8n workflow id');
		}
	}

	/** @Then no workflow is created in n8n */
	public function noWorkflowIsCreatedInN8n(): void {
		Assert::assertNull($this->lastWorkflowId, "a workflow ({$this->lastWorkflowId}) was unexpectedly created in n8n");
	}

	/** @Then the file has no :key metadata */
	public function theFileHasNoMetadata(string $key): void {
		$value = $this->davReadMetadata($this->currentFilePath, $key);
		Assert::assertTrue($value === null || $value === '', "file unexpectedly has metadata-$key='$value'");
	}

	/** @Then /^the file is treated as a plain document \(unmapped state\)$/ */
	public function theFileIsTreatedAsPlain(): void {
		// "Plain" = no n8n metadata id; the create listener bailed (outside any
		// mapping). The id check above is the operative assertion; this step is a
		// readable restatement so the scenario reads as a sentence.
		$this->theFileHasNoMetadata(self::META_ID);
	}

	// ── rename steps (UC name-sync: filename ⇄ JSON name ⇄ n8n name) ──────────
	// Rename/edit are deferred to ReconcileNameJob (the file is locked during a
	// rename), so each scenario drains that job class with the occ worker before
	// asserting. The stable link is the n8n id, which never changes.

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

	// ── delete steps (UC-7: delete/trash/restore mirrors into n8n) ────────────
	// DeleteToN8nListener runs synchronously on BeforeNodeDeletedEvent (it must,
	// to abort the NC delete when n8n is down). Soft step = trash-move; hard =
	// purge from trash; restore = move back out of the trashbin.

	/** @Given a trashed :mode workflow file */
	public function aTrashedWorkflowFile(string $mode): void {
		$this->aManagedWorkflowFile($mode);
		$this->davDelete($this->currentFilePath); // → trashbin (soft step)
	}

	/**
	 * A plain .n8n.json with no n8n metadata — "untracked", distinct from the
	 * "unmapped" mode (saga Chapter 2 §14) which keeps its id + an archived workflow.
	 *
	 * @Given an untracked :ext file
	 */
	public function anUntrackedFile(string $ext): void {
		$folder = 'untracked-' . bin2hex(random_bytes(3));
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$path = $folder . '/plain-' . bin2hex(random_bytes(3)) . $ext;
		$this->davPut($path, json_encode(['name' => 'Plain', 'nodes' => [], 'connections' => new \stdClass()], JSON_THROW_ON_ERROR));
		$this->currentFilePath = $path;
		$this->lastWorkflowId = null;
	}

	/**
	 * @When I move it to the trash
	 * @When I delete it
	 */
	public function iMoveItToTheTrash(): void {
		$this->lastDeleteStatus = $this->davDeleteStatus($this->currentFilePath);
	}

	/** @When I purge it from the trash */
	public function iPurgeItFromTheTrash(): void {
		$trashPath = $this->trashbinPathFor($this->currentFilePath);
		Assert::assertNotNull($trashPath, 'could not find the file in the trashbin to purge');
		$res = $this->davClient()->request('DELETE', $this->trashHref($trashPath));
		$this->assertStatus($res, [204, 200], 'purge from trash');
	}

	/** @When I restore it from the trash */
	public function iRestoreItFromTheTrash(): void {
		$trashPath = $this->trashbinPathFor($this->currentFilePath);
		Assert::assertNotNull($trashPath, 'could not find the file in the trashbin to restore');
		$dest = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/restore/' . rawurlencode(basename($trashPath));
		$res = $this->davClient()->request('MOVE', $this->trashHref($trashPath), [
			'headers' => ['Destination' => $dest],
		]);
		$this->assertStatus($res, [201, 204], 'restore from trash');
	}

	/** @Then /^the workflow is archived \(hidden, preserved\) in n8n$/ */
	public function theWorkflowIsArchivedInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone — it should be archived, not deleted");
		Assert::assertTrue((bool)($wf['isArchived'] ?? false), 'workflow is not archived in n8n');
	}

	/** @Then the workflow is permanently deleted in n8n */
	public function theWorkflowIsDeletedInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertNull($wf, "workflow {$this->lastWorkflowId} still exists in n8n");
	}

	/** @Then the workflow is unarchived in n8n */
	public function theWorkflowIsUnarchivedInN8n(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone");
		Assert::assertFalse((bool)($wf['isArchived'] ?? false), 'workflow is still archived in n8n');
	}

	/** @Then the mapping tag is stripped from the workflow in n8n */
	public function theMappingTagIsStripped(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} is gone");
		$names = array_map(
			static fn (array $t): string => (string)($t['name'] ?? ''),
			array_values(array_filter((array)($wf['tags'] ?? []), 'is_array')),
		);
		Assert::assertNotContains($this->currentTag, $names, "tag '{$this->currentTag}' was not stripped (has: " . implode(',', $names) . ')');
	}

	/** @Then the workflow itself is not archived or deleted */
	public function theWorkflowIsNotArchivedOrDeleted(): void {
		$wf = $this->n8nGetWorkflow($this->lastWorkflowId);
		Assert::assertIsArray($wf, "workflow {$this->lastWorkflowId} was deleted — link must leave it alone");
		Assert::assertFalse((bool)($wf['isArchived'] ?? false), 'workflow was archived — link must leave it alone');
	}

	/** @Then n8n is not contacted */
	public function n8nIsNotContacted(): void {
		// Operative meaning: the unmapped file had no n8n id, so nothing could be
		// contacted, and the NC delete succeeded normally.
		Assert::assertNull($this->lastWorkflowId, 'an unmapped file unexpectedly had an n8n id');
		Assert::assertContains($this->lastDeleteStatus, [204, 200], 'the unmapped delete did not succeed');
	}

	// ── shared setup helpers for the above ────────────────────────────────────

	/**
	 * Create an admin-owned mapping for $tag in mode $mode + its backing folder,
	 * wiring the connection so create/push/delete can reach n8n. Records the tag
	 * for tag-strip assertions and sets currentFolder.
	 */
	private function setupSyncMappingAndFolder(string $mode, string $tag): void {
		// Connection (idempotent): URL + key + REST API on.
		$this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($this->n8nUrl));
		$this->occ('config:app:set ' . self::APP_ID . ' api_enabled --value=1');
		if ($this->n8nApiKey !== '') {
			$this->occStdin($this->occ . ' n8n_sync:set-api-key', $this->n8nApiKey);
		}
		$folder = $this->folderNameForTag($tag);
		$data = ['n8n_tag' => $tag, 'team_folder' => $folder, 'nc_groups' => ['admin'], 'mode' => $this->modeToModel($mode), 'use_team_folder' => false];
		$res = $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
		Assert::assertSame(0, $res['exit'], "adding mapping for $tag failed:\n{$res['output']}");
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$this->currentTag = $tag;
	}

	/** PUT a starter workflow body and capture the n8n id the app stamped. */
	private function putManagedFile(string $path, string $name): void {
		$body = json_encode([
			'name' => $name,
			'nodes' => [],
			'connections' => new \stdClass(),
			'settings' => new \stdClass(),
		], JSON_THROW_ON_ERROR);
		$this->davPut($path, $body);
		$this->currentFilePath = $path;
		$id = $this->davReadMetadataId($path);
		Assert::assertNotNull($id, "the file at $path was not stamped with an n8n_id — create-on-land did not run");
		$this->lastWorkflowId = $id;
		$this->createdWorkflowIds[] = $id;
	}

	/**
	 * Execute every queued job of $jobClass now, deterministically.
	 *
	 * `background-job:worker --once` honours the worker's last-run / reservation
	 * timing, so a job queued microseconds ago is often skipped on an immediate
	 * pass — which made rename reconciles flaky. Instead we list the jobs of the
	 * class (JSON) and run each by id with `--force-execute`, which bypasses the
	 * last-run + reservation gates. Idempotent: the reconcile job no-ops if the
	 * names are already in sync, so running a stale id is harmless.
	 */
	private function drainJobs(string $jobClass): void {
		$res = $this->occ('background-job:list --class=' . escapeshellarg($jobClass) . ' --output=json');
		$jobs = json_decode($res['output'], true);
		if (!is_array($jobs)) {
			return;
		}
		foreach ($jobs as $job) {
			$id = $job['id'] ?? null;
			if (is_int($id) || (is_string($id) && $id !== '')) {
				$this->occ('background-job:execute ' . escapeshellarg((string)$id) . ' --force-execute');
			}
		}
	}

	// ── HTTP plumbing: WebDAV (NC) + REST (n8n) ───────────────────────────────

	private function davClient(): Client {
		if ($this->dav === null) {
			$this->dav = new Client([
				'base_uri' => $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/',
				'auth' => [$this->ncUser, $this->ncPass],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->dav;
	}

	private function n8nClient(): Client {
		if ($this->n8n === null) {
			Assert::assertNotSame('', $this->n8nApiKey, 'N8N_API_KEY is not set — n8n assertions need it');
			$this->n8n = new Client([
				'base_uri' => $this->n8nUrl . '/api/v1/',
				'headers' => ['X-N8N-API-KEY' => $this->n8nApiKey, 'Accept' => 'application/json'],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->n8n;
	}

	/**
	 * Assert an HTTP response status is in $allowed, throwing a plain, legible
	 * exception otherwise. Deliberately NOT a PHPUnit assertion: PHPUnit 12's
	 * failure exporter reaches into PHPUnit\TextUI\Configuration\Registry, which
	 * is null under Behat (no TextUI bootstrap), so a failing PHPUnit assertion
	 * here throws an opaque "Registry::get(): ... null returned" TypeError that
	 * masks the real status. A RuntimeException shows the actual code + body.
	 *
	 * @param list<int> $allowed
	 */
	private function assertStatus(\Psr\Http\Message\ResponseInterface $res, array $allowed, string $what): void {
		$code = $res->getStatusCode();
		if (!in_array($code, $allowed, true)) {
			throw new \RuntimeException("$what failed: HTTP $code (expected " . implode('/', $allowed) . ")\n" . (string)$res->getBody());
		}
	}

	/** Create a top-level folder in the admin's files root (idempotent). */
	private function davMkdir(string $folder): void {
		// 201 created, 405 already exists — both are fine for our purposes.
		$this->assertStatus($this->davClient()->request('MKCOL', rawurlencode($folder)), [201, 405], "MKCOL $folder");
		if (!in_array($folder, $this->createdFolders, true)) {
			$this->createdFolders[] = $folder;
		}
	}

	/** PUT file content at a path under the user's files root. */
	private function davPut(string $path, string $body): void {
		$this->assertStatus($this->davClient()->request('PUT', $this->davEncode($path), ['body' => $body]), [201, 204], "PUT $path");
	}

	/** GET a file's content. */
	private function davGet(string $path): string {
		$res = $this->davClient()->request('GET', $this->davEncode($path));
		$this->assertStatus($res, [200], "GET $path");
		return (string)$res->getBody();
	}

	/** True if a file exists (HEAD 200). */
	private function davExists(string $path): bool {
		return $this->davClient()->request('HEAD', $this->davEncode($path))->getStatusCode() === 200;
	}

	/** MOVE (rename) a file within the user's files root. */
	private function davMove(string $from, string $to): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		$res = $this->davClient()->request('MOVE', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->assertStatus($res, [201, 204], "MOVE $from → $to");
	}

	/** DELETE a file (asserting success → trash). */
	private function davDelete(string $path): void {
		$this->assertStatus($this->davClient()->request('DELETE', $this->davEncode($path)), [204, 200], "DELETE $path");
	}

	/** DELETE a file, returning the raw status (so abort scenarios can inspect it). */
	private function davDeleteStatus(string $path): int {
		return $this->davClient()->request('DELETE', $this->davEncode($path))->getStatusCode();
	}

	/**
	 * Find the trashbin entry for a file we deleted, by basename. NC trashbin DAV
	 * lives at /remote.php/dav/trashbin/<user>/trash and renames entries with a
	 * `.dNNNN` deletion-time suffix, so we match on the original basename prefix.
	 * Returns the trashbin entry filename (e.g. "Old Name.n8n.json.d171...") or null.
	 */
	private function trashbinPathFor(string $originalPath): ?string {
		$base = basename($originalPath);
		$href = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash';
		$res = $this->davClient()->request('PROPFIND', $href, [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
				. '<d:prop><nc:trashbin-filename/></d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), 'trashbin PROPFIND failed: ' . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
		foreach ($doc->xpath('//d:response') ?: [] as $resp) {
			$resp->registerXPathNamespace('d', 'DAV:');
			$resp->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
			$origName = trim((string)($resp->xpath('.//nc:trashbin-filename')[0] ?? ''));
			$rawHref = rawurldecode(trim((string)($resp->xpath('d:href')[0] ?? '')));
			if ($origName === $base && $rawHref !== '') {
				return basename(rtrim($rawHref, '/'));
			}
		}
		return null;
	}

	/** Full trashbin href for a trash entry filename. */
	private function trashHref(string $entry): string {
		return $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash/' . rawurlencode($entry);
	}

	/**
	 * PROPFIND a single nc:metadata-<key> on a file. Returns the property value,
	 * or null if the property is absent (404 inside the multistatus). This is the
	 * exact DAV surface the README documents for the file-type feature.
	 */
	private function davReadMetadata(string $path, string $key): ?string {
		$ns = 'http://nextcloud.org/ns';
		$reqBody = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:nc="' . $ns . '">'
			. '<d:prop><nc:metadata-' . $key . '/></d:prop></d:propfind>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => $reqBody,
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $path failed: " . (string)$res->getBody());
		$xml = (string)$res->getBody();
		$doc = new \SimpleXMLElement($xml);
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $ns);
		// Only consider the 200-OK propstat block; a missing prop lands in a 404 block.
		foreach ($doc->xpath('//d:propstat') ?: [] as $propstat) {
			$propstat->registerXPathNamespace('d', 'DAV:');
			$propstat->registerXPathNamespace('nc', $ns);
			$status = (string)($propstat->xpath('d:status')[0] ?? '');
			if (!str_contains($status, '200')) {
				continue;
			}
			$node = $propstat->xpath('d:prop/nc:metadata-' . $key);
			if ($node) {
				return trim((string)$node[0]);
			}
		}
		return null;
	}

	/** Convenience: read just the n8n_id (used right after a create to capture it). */
	private function davReadMetadataId(string $path): ?string {
		return $this->davReadMetadata($path, self::META_ID);
	}

	/** GET an n8n workflow by id; returns the decoded body or null on 404. */
	private function n8nGetWorkflow(string $id): ?array {
		$res = $this->n8nClient()->request('GET', 'workflows/' . rawurlencode($id));
		if ($res->getStatusCode() === 404) {
			return null;
		}
		Assert::assertSame(200, $res->getStatusCode(), "GET n8n workflow $id failed: " . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		return is_array($decoded) ? $decoded : null;
	}

	/** Percent-encode each path segment but keep the slashes. */
	private function davEncode(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
	}

	/** A stable, filesystem-safe folder name derived from an n8n tag. */
	private function folderNameForTag(string $tag): string {
		$slug = preg_replace('/[^a-z0-9]+/i', '-', $tag) ?? 'mapped';
		return trim(strtolower($slug), '-') ?: 'mapped';
	}

	// ── per-scenario lifecycle (teardown) ─────────────────────────────────────

	/**
	 * After every scenario, delete any n8n workflows the app created and the NC
	 * folders we made, and clear the mappings list. Keeps re-runs isolated on the
	 * shared CI n8n + NC instance. Best-effort: failures here never fail a test.
	 *
	 * @AfterScenario
	 */
	public function tearDown(): void {
		foreach ($this->createdWorkflowIds as $id) {
			try {
				$this->n8nClient()->request('DELETE', 'workflows/' . rawurlencode($id));
			} catch (GuzzleException) {
				// best-effort cleanup
			}
		}
		foreach ($this->createdFolders as $folder) {
			try {
				$this->davClient()->request('DELETE', rawurlencode($folder));
			} catch (GuzzleException) {
				// best-effort cleanup
			}
		}
		// Reset the mapping list so the next scenario starts from zero mappings.
		$this->occ('config:app:delete ' . self::APP_ID . ' mappings');
		$this->createdWorkflowIds = [];
		$this->createdFolders = [];
		$this->currentFolder = '';
		$this->currentFilePath = '';
		$this->lastWorkflowId = null;
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * Run an occ command with data piped on stdin (for `set-api-key`, which reads
	 * the key from stdin to keep it off the process list).
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occStdin(string $cmd, string $stdin): array {
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $descriptors, $pipes);
		Assert::assertIsResource($proc, "could not start: $cmd");
		fwrite($pipes[0], $stdin);
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($proc);
		$this->lastExit = $exit;
		$this->lastOutput = $out;
		return ['exit' => $exit, 'output' => $out];
	}

	/** Slice the "Enabled:" block out of `occ app:list` output (stop at "Disabled:"). */
	private function enabledBlock(string $appList): string {
		$lines = explode("\n", $appList);
		$out = [];
		$in = false;
		foreach ($lines as $line) {
			if (str_starts_with($line, 'Enabled:')) {
				$in = true;
				continue;
			}
			if (str_starts_with($line, 'Disabled:')) {
				break;
			}
			if ($in) {
				$out[] = $line;
			}
		}
		return implode("\n", $out);
	}
}
