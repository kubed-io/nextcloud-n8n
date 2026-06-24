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
use OCA\N8nSync\Tests\Integration\Steps\AdminSteps;
use OCA\N8nSync\Tests\Integration\Steps\AppLifecycleSteps;
use OCA\N8nSync\Tests\Integration\Steps\CopySteps;
use OCA\N8nSync\Tests\Integration\Steps\CreateSteps;
use OCA\N8nSync\Tests\Integration\Steps\DeleteSteps;
use OCA\N8nSync\Tests\Integration\Steps\FileTypeSteps;
use OCA\N8nSync\Tests\Integration\Steps\MappingMembershipSteps;
use OCA\N8nSync\Tests\Integration\Steps\MappingSteps;
use OCA\N8nSync\Tests\Integration\Steps\ModeChangeSteps;
use OCA\N8nSync\Tests\Integration\Steps\MoveSteps;
use OCA\N8nSync\Tests\Integration\Steps\OpenWithSteps;
use OCA\N8nSync\Tests\Integration\Steps\ReconcileSteps;
use OCA\N8nSync\Tests\Integration\Steps\RenameSteps;
use OCA\N8nSync\Tests\Integration\Steps\ReservedTagsSteps;
use OCA\N8nSync\Tests\Integration\Support\N8nApiTrait;
use OCA\N8nSync\Tests\Integration\Support\OccTrait;
use OCA\N8nSync\Tests\Integration\Support\SetupTrait;
use OCA\N8nSync\Tests\Integration\Support\WebDavTrait;

/**
 * Behat context for the n8n_sync integration suite.
 *
 * This class is intentionally thin: it owns the shared per-scenario state (the
 * three transport clients + the carried-between-steps fields), the constructor
 * that wires them from the environment, and the @AfterScenario teardown. Every
 * actual step definition lives in a per-concern trait composed in below, so a
 * new feature adds (or grows) ONE `*Steps` trait rather than bloating a single
 * 1000-line file. This mirrors how nextcloud/server composes its Behat context
 * from BasicStructure / WebDav / Sharing / … traits.
 *
 *   bootstrap/
 *     FeatureContext.php      ← you are here (state + lifecycle + composition)
 *     Steps/                  ← gherkin-facing step definitions, one trait/concern
 *       AppLifecycleSteps, AdminSteps, MappingSteps, CreateSteps,
 *       RenameSteps, DeleteSteps, MoveSteps, CopySteps
 *     Support/                ← transport + setup plumbing (no @Given/@When/@Then)
 *       OccTrait, WebDavTrait, N8nApiTrait, SetupTrait
 *
 * Transport: three channels, each faithful to a real actor —
 *  - **occ** (the $OCC env var, e.g. "php occ" run from the server root) drives
 *    admin setup the way an operator / our own CLI commands do.  → OccTrait
 *  - **WebDAV** (Guzzle, basic-auth as the admin user) writes/reads/PROPFINDs
 *    files the way the desktop client or web UI would — this is what fires the
 *    NodeWrittenEvent the create/delete/rename listeners hang off, so it is the
 *    only way to exercise the real server-side wiring.  → WebDavTrait
 *  - **n8n REST** (Guzzle, X-N8N-API-KEY) is the assertion side: did the app
 *    actually create / tag / archive / delete the workflow in n8n? It is also
 *    used to clean up workflows the scenarios create so re-runs stay isolated.
 *    → N8nApiTrait
 */
final class FeatureContext implements Context {
	use OccTrait;
	use WebDavTrait;
	use N8nApiTrait;
	use SetupTrait;
	use AppLifecycleSteps;
	use AdminSteps;
	use MappingSteps;
	use CreateSteps;
	use RenameSteps;
	use DeleteSteps;
	use MoveSteps;
	use OpenWithSteps;
	use CopySteps;
	use ModeChangeSteps;
	use ReconcileSteps;
	use ReservedTagsSteps;
	use FileTypeSteps;
	use MappingMembershipSteps;

	private const APP_ID = 'n8n_sync';

	/**
	 * The DAV-exposed metadata key for the workflow id. Mirrors
	 * {@see \OCA\N8nSync\Service\WorkflowMetadata::KEY_ID}; redeclared here as a
	 * literal because the integration suite autoloads only its own bootstrap/,
	 * not the app's lib/. The Gherkin says "n8n_id"; this is the same string.
	 */
	private const META_ID = 'n8n_id';

	/** The metadata keys the move/motion steps read (see WorkflowMetadata::KEY_*). */
	private const META_VERSION_ID = 'n8n_versionId';
	private const META_MODE = 'n8n_mode';
	private const META_MAPPING = 'n8n_mapping';

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
	private ?string $lastVersionId = null;
	private int $lastMoveStatus = 0;
	/** Whether the workflow under test is expected to be archived in n8n right now. */
	private bool $expectedArchived = false;
	/** A workflow id deliberately hard-deleted mid-scenario (for the create-fallback move-in). */
	private string $deletedWorkflowId = '';
	/** Merge-on-collision (§14.19): the shared workflow id, the existing synced copy, and the incoming copy. */
	private string $collisionWorkflowId = '';
	private string $collisionSyncedPath = '';
	private string $collisionIncomingPath = '';
	/** The copy made by a copy step, and the workflow id (if any) it was registered as. */
	private string $copyFilePath = '';
	private ?string $copyWorkflowId = null;

	public function __construct() {
		$this->occ = getenv('OCC') ?: 'php occ';
		$this->ncBaseUrl = rtrim(getenv('NC_BASE_URL') ?: 'http://localhost:8080', '/');
		$this->ncUser = getenv('NC_ADMIN_USER') ?: 'admin';
		$this->ncPass = getenv('NC_ADMIN_PASS') ?: 'admin';
		$this->n8nUrl = rtrim(getenv('N8N_URL') ?: 'http://localhost:5678', '/');
		$this->n8nApiKey = getenv('N8N_API_KEY') ?: '';
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
}
