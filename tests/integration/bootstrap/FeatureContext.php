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
use OCA\N8nSync\Tests\Integration\Steps\MappingMembershipSteps;
use OCA\N8nSync\Tests\Integration\Steps\MappingSteps;
use OCA\N8nSync\Tests\Integration\Steps\ModeChangeSteps;
use OCA\N8nSync\Tests\Integration\Steps\MoveSteps;
use OCA\N8nSync\Tests\Integration\Steps\OpenWithSteps;
use OCA\N8nSync\Tests\Integration\Steps\RenameSteps;
use OCA\N8nSync\Tests\Integration\Steps\ReservedTagsSteps;
use OCA\N8nSync\Tests\Integration\Steps\SyncSteps;
use OCA\N8nSync\Tests\Integration\Steps\TagSyncSteps;
use OCA\N8nSync\Tests\Integration\Steps\ViewWorkflowSteps;
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
	use SyncSteps;
	use ReservedTagsSteps;
	use TagSyncSteps;
	use ViewWorkflowSteps;
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
	/**
	 * A throwaway account a scenario borrowed to act as somebody who was SHARED a
	 * folder rather than owning it — see {@see Steps\MoveSteps::folderIsSharedWithMe}.
	 * Held so teardown can put the original user back and delete the account, whatever
	 * the scenario did in between.
	 */
	private string $borrowedUser = '';
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
	/**
	 * THE FILE THE SCENARIO CALLS "the original" — which is NOT $currentFilePath.
	 *
	 * $currentFilePath is a cursor: it follows whatever the last gesture touched, so
	 * a move or a rename repoints it. "The original" is a role, and it has to stay
	 * put while the gesture happens somewhere else, or a post-condition about the
	 * original silently reads the thing that just moved. Kept apart so an arrange
	 * that redefines which file plays the role can say so.
	 */
	private string $originalPath = '';
	private ?string $lastWorkflowId = null;
	private string $currentTag = '';
	private int $lastDeleteStatus = 0;
	/** The `<s:message>` from the last refused DAV delete — what the user is actually told. */
	private string $lastDeleteMessage = '';
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
	/**
	 * The folder a scenario last named the whole contents of, and the names it found.
	 *
	 * Held so a following step can re-read the SAME set — "these names survive another
	 * sync" is a before/after claim, and re-deriving the "before" after the sync would
	 * compare the folder with itself and pass no matter what happened.
	 */
	private string $namedFolder = '';
	/** @var list<string> */
	private array $namedFiles = [];

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
	 * Arm the once-per-scenario mapping reset.
	 *
	 * `a mapping with the following values:` clears the store the FIRST time a
	 * scenario uses it and appends afterwards, so a Background can declare several
	 * mappings by repeating the step. That "first time" is per scenario, which is
	 * what this re-arms — without it the second scenario in a feature would append
	 * to the first one's leftovers.
	 *
	 * @BeforeScenario
	 */
	public function armMappingReset(): void {
		$this->mappingsDeclared = false;
	}

	/**
	 * After every scenario, delete any n8n workflows the app created and the NC
	 * folders we made, and clear the mappings list. Keeps re-runs isolated on the
	 * shared CI n8n + NC instance. Best-effort: failures here never fail a test.
	 *
	 * @AfterScenario
	 */
	public function tearDown(): void {
		// FIRST, because everything below speaks over DAV or occ as the current user, and
		// a borrowed member cannot clean up folders the admin owns.
		if ($this->borrowedUser !== '') {
			$this->ncUser = getenv('NC_ADMIN_USER') ?: 'admin';
			$this->ncPass = getenv('NC_ADMIN_PASS') ?: 'admin';
			$this->dav = null;
			$this->occ('user:delete ' . escapeshellarg($this->borrowedUser));
			$this->borrowedUser = '';
		}

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
		// Reset the writeback timing knob (some tag scenarios set it) back to default.
		$this->occ('config:app:delete ' . self::APP_ID . ' timing');
		$this->createdWorkflowIds = [];
		$this->createdFolders = [];
		$this->currentFolder = '';
		$this->currentFilePath = '';
		$this->originalPath = '';
		$this->lastWorkflowId = null;
	}
}
