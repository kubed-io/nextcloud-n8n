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
 * Mapping steps: the feature describes a mapping in plain English (a table of
 * the fields the creation form takes); these translate those words into the data
 * model. Owns `modeToModel`, which the create/move/setup traits also call.
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 *
 * ## ONE VOCABULARY FOR THE PRE-STATE AND THE ACTION
 *
 * `a mapping with the following values:` and `the admin maps the tag :tag with:`
 * take the SAME table, because they describe the same object — one as something
 * that is already true, one as something being done. That is what lets a
 * uniqueness scenario put a mapping in the pre-state and then perform the very
 * action that created it, with the difference visible in the table rather than
 * hidden in two differently-worded steps.
 *
 * A blank cell means "the admin left this field alone", so it is dropped from
 * the payload entirely and the app applies its own default. That is the only way
 * an Examples row can say "unset" — an empty string is a value, and would test
 * the wrong thing.
 */
trait MappingSteps {
	/**
	 * Fail with a message that SURVIVES.
	 *
	 * PHPUnit's assertions are unusable for diagnosis inside Behat: when one
	 * fails, its formatter reaches for `PHPUnit\TextUI\Configuration\Registry`,
	 * which Behat never bootstraps, and the run reports
	 *
	 *     Type error: Registry::get(): Return value must be of type
	 *     Configuration, null returned
	 *
	 * INSTEAD OF THE ASSERTION MESSAGE. The failure looks like a tooling
	 * incompatibility, the actual cause is invisible, and every diagnosis costs a
	 * full CI cycle — it cost three on this change alone, including one where the
	 * message it ate said exactly what was wrong ("no Team Folder mounted at ...").
	 *
	 * So the steps whose message IS the diagnosis throw plainly instead. The
	 * sibling penpot app arrived at the same thing for the same reason.
	 *
	 * @throws \RuntimeException
	 */
	private function fail(string $message): never {
		throw new \RuntimeException($message);
	}

	/** @var array<string,string> the last form submitted, for the post-condition */
	private array $lastMappingForm = [];

	/** @var array<string,string> what an unset field is expected to become */
	private array $mappingDefaults = [];

	/** Whether this scenario has already reset the store — see the step's docblock. */
	private bool $mappingsDeclared = false;

	/** @Given no n8n tags are mapped */
	public function noN8nTagsAreMapped(): void {
		foreach ($this->listMappings() as $m) {
			$id = (string)($m['id'] ?? '');
			if ($id !== '') {
				$this->occ('n8n_sync:remove-mapping ' . escapeshellarg($id));
			}
		}
		if ($this->listMappings() !== []) {
			$this->fail('the mapping store did not empty');
		}
	}

	/**
	 * @Given an unset field on the mapping form defaults to:
	 *
	 * KEEPS BLANK CELLS, unlike every other table here. This one DECLARES what a
	 * default is rather than submitting a form, so `| groups | |` is the assertion
	 * "the default is nothing" — dropping it would leave the row decorative and the
	 * scenario would keep passing if the app started defaulting groups to
	 * something. The two tables look alike and mean opposite things.
	 */
	public function anUnsetFieldDefaultsTo(TableNode $table): void {
		$out = [];
		foreach ($table->getRowsHash() as $field => $value) {
			$out[(string)$field] = trim((string)$value);
		}
		$this->mappingDefaults = $out;
	}

	/**
	 * @Given a mapping with the following values:
	 *
	 * The pre-state twin of `the admin maps the tag :tag with:`.
	 *
	 * REPEATING IT DECLARES ANOTHER MAPPING; it does not replace the first. The
	 * reset happens once per scenario, on the first use, which is what the isolation
	 * was ever for — starting from a known count instead of inheriting whatever the
	 * previous scenario left behind. Resetting on EVERY use meant a Background could
	 * only ever describe one mapping, and silently: the second table wiped the first
	 * and nothing said so.
	 */
	public function aMappingWithTheFollowingValues(TableNode $table): void {
		if (!$this->mappingsDeclared) {
			$this->noN8nTagsAreMapped();
			$this->mappingsDeclared = true;
		}

		$form = $this->formValues($table);
		$tag = $form['tag'] ?? '';
		unset($form['tag']);

		$res = $this->addMappingFromForm($tag, $form);
		if ($res['exit'] !== 0) {
			$this->fail("the pre-state mapping could not be created:\n{$res['output']}");
		}

		if (isset($form['folder'])) {
			$this->davMkdir($form['folder']);
		}
	}

	/**
	 * The n8n tag whose mapping owns $folder, read from the LIVE store rather than
	 * anything recorded at arrange time.
	 *
	 * copy.feature, purge.feature and delete.feature still declare their Background
	 * mapping through `CreateSteps::aFolderMappedAsModeToTag` (a direct
	 * `occ add-mapping` call), not `a mapping with the following values:` — an
	 * earlier version of this method only knew about mappings made the second way,
	 * and failed with "no mapping declares the folder…" for the other three
	 * features in CI. Every mapping lands in the same store however it was made, so
	 * reading it back from there works regardless of which arrange a feature uses.
	 */
	private function tagForFolder(string $folder): string {
		foreach ($this->listMappings() as $m) {
			if (($m['team_folder'] ?? '') === $folder) {
				return (string)($m['n8n_tag'] ?? '');
			}
		}
		$this->fail("no mapping owns the folder '$folder' — check the Background");
	}

	/**
	 * The stored mode of the mapping owning a folder, read back from the store for the
	 * same reason {@see tagForFolder()} is: every arrange makes its mappings a different
	 * way and they all land in one place.
	 *
	 * Exists so an arrange can ask "may I write into this folder?" rather than assume.
	 * A LINK mapping refuses authoring from Nextcloud, so seeding one by DAV PUT is a
	 * gesture the app is right to reject — see {@see seedManagedFileIn()}.
	 *
	 * AN UNMAPPED FOLDER ANSWERS `''` RATHER THAN FAILING, unlike {@see tagForFolder()},
	 * and the difference is real: a tag is something every mapping must have, so asking
	 * for one of a folder with no mapping is a broken Background. A MODE is not — "no
	 * mode" is the correct and useful answer for `Scratch`, which several arranges name
	 * on purpose. Failing here instead took three suites down, because the callers ask
	 * before they know whether the folder is mapped at all.
	 */
	private function modeForFolder(string $folder): string {
		foreach ($this->listMappings() as $m) {
			if (($m['team_folder'] ?? '') === $folder) {
				return (string)($m['mode'] ?? '');
			}
		}
		return '';
	}

	/** The folder of the first `sync` mapping in the store, for backend-agnostic arranges. */
	private function firstSyncFolder(): string {
		foreach ($this->listMappings() as $m) {
			if (($m['mode'] ?? '') === 'sync') {
				$folder = (string)($m['team_folder'] ?? '');
				if ($folder !== '') {
					return $folder;
				}
			}
		}
		$this->fail('no sync mapping in the store to arrange against');
	}

	/** @When the admin maps the tag :tag with: */
	public function theAdminMapsTheTagWith(string $tag, TableNode $table): void {
		$form = $this->formValues($table);
		$this->lastMappingForm = ['tag' => $tag] + $form;
		$this->addMappingFromForm($tag, $form);
	}

	/**
	 * @Then the mapping matches the form, unset fields at their defaults
	 *
	 * Reads back what was stored and compares it against the submitted form,
	 * substituting the declared default for every field the form left blank. One
	 * assertion for the whole object, so a scenario says "it saved what I typed"
	 * rather than listing the fields one at a time.
	 */
	public function theMappingMatchesTheForm(): void {
		$tag = $this->lastMappingForm['tag'] ?? '';
		$m = $this->findMapping($tag);
		Assert::assertNotNull($m, "no mapping was stored for tag $tag");

		$expected = $this->lastMappingForm + $this->mappingDefaults;

		Assert::assertSame($expected['folder'] ?? '', (string)($m['team_folder'] ?? ''), 'folder');
		Assert::assertSame(
			$this->modeToModel($expected['mode'] ?? 'sync'),
			(string)($m['mode'] ?? ''),
			'mode',
		);
		Assert::assertSame(
			$this->storageToModel($expected['storage'] ?? ''),
			(bool)($m['use_team_folder'] ?? false),
			'storage',
		);

		$wanted = $this->groupList($expected['groups'] ?? '');
		$stored = array_values(array_map('strval', (array)($m['nc_groups'] ?? [])));
		sort($wanted);
		sort($stored);
		Assert::assertSame($wanted, $stored, 'groups');
	}

	/**
	 * @Given the Nextcloud groups :groups exist
	 * @Given the Nextcloud groups :groups exists
	 *
	 * THE GROUPS HAVE TO REALLY EXIST. Nextcloud cannot share a folder with a group
	 * that is not there, so a scenario that just names one and asserts it comes
	 * back would be asserting nothing — which is precisely how the old stored-list
	 * model passed: it echoed its own stored intent back without ever touching a
	 * share.
	 */
	public function theNextcloudGroupsExist(string $groups): void {
		foreach (explode(',', $groups) as $gid) {
			$gid = trim($gid);
			if ($gid !== '') {
				// Idempotent: an existing group makes this a non-zero no-op.
				$this->occ('group:add ' . escapeshellarg($gid));
			}
		}
	}

	/** @When the admin changes that mapping's groups to :groups */
	public function theAdminChangesThatMappingsGroupsTo(string $groups): void {
		$id = (string)($this->listMappings()[0]['id'] ?? '');
		if ($id === '') {
			$this->fail('no mapping to change');
		}
		$this->occ('n8n_sync:set-groups ' . escapeshellarg($id) . ' ' . escapeshellarg($groups));
	}

	/**
	 * @When the Team Folder :folder is shared with the group :group outside this app
	 *
	 * Uses groupfolders' OWN occ command, so the share is made exactly the way an
	 * admin would make it in the Files admin UI — by something that is not this
	 * app. That is the whole point: the next read has to report the FOLDER's
	 * sharing rather than this app's memory of it.
	 *
	 * There is no core `occ` command that creates a plain group share (checked
	 * against a live Nextcloud: core ships `sharing:cleanup-remote-storages`,
	 * `delete-orphan-shares`, `expiration-notification` and `fix-share-owners`,
	 * and nothing that shares). So this scenario is written on a Team Folder,
	 * where groupfolders gives us one.
	 *
	 * `read write delete` rather than the default read-only, so the group is
	 * assigned at the same permissions the app itself grants — otherwise the app
	 * would fix them on the next explicit set and the difference would look like
	 * churn.
	 */
	public function theTeamFolderIsSharedWithTheGroupOutsideThisApp(string $folder, string $group): void {
		$this->theNextcloudGroupsExist($group);

		$res = $this->occ('groupfolders:list --output=json');
		$folders = json_decode($res['output'], true);
		if (!is_array($folders)) {
			$this->fail("groupfolders:list did not return JSON:\n{$res['output']}");
		}

		$id = null;
		foreach ($folders as $f) {
			if (($f['mountPoint'] ?? null) === $folder) {
				$id = (string)($f['id'] ?? '');
				break;
			}
		}
		if ($id === null) {
			$this->fail(sprintf(
				"no Team Folder mounted at '%s'. groupfolders:list reported: %s",
				$folder,
				implode(', ', array_map(static fn (array $f): string => (string)($f['mountPoint'] ?? '?'), $folders)) ?: '(none)',
			));
		}

		$res = $this->occ(sprintf(
			'groupfolders:group %s %s read write delete',
			escapeshellarg((string)$id),
			escapeshellarg($group),
		));
		if ($res['exit'] !== 0) {
			$this->fail("could not share $folder with $group:\n{$res['output']}");
		}
	}

	/**
	 * @Then the mapping's groups are :groups
	 *
	 * Compared as a SET, not a list: which groups the folder is shared with is the
	 * fact, and the order Nextcloud happens to return them in is not.
	 */
	public function theMappingsGroupsAre(string $groups): void {
		$want = $this->groupList($groups);
		$got = array_values(array_map('strval', (array)($this->listMappings()[0]['nc_groups'] ?? [])));
		sort($want);
		sort($got);
		if ($want !== $got) {
			$this->fail(sprintf(
				'expected the mapped folder to be shared with [%s]; it reports [%s]',
				implode(', ', $want),
				implode(', ', $got) ?: '(none)',
			));
		}
	}

	// ── mapping/create: the parity set ─────────────────────────────────────────

	/** @var array<string,string> the form the scenario last submitted, for the retry */
	private array $lastSubmittedMapping = [];

	/** @var int|null how many mappings existed before the last submit */
	private ?int $mappingsBeforeCreate = null;

	/**
	 * @Given the n8n base URL points at the test instance
	 *
	 * Idempotent, and separate from the key on purpose: the two halves of the
	 * connection fail differently, and only one of them stops a mapping being made.
	 */
	public function theN8nBaseUrlPointsAtTheTestInstance(): void {
		$this->occ('config:app:set ' . self::APP_ID . ' n8n_url --value=' . escapeshellarg($this->n8nUrl));
	}

	/**
	 * @Given the admin has configured the API key
	 *
	 * THE PRECONDITION FOR EVERY OTHER SCENARIO IN THE FILE, which is why it is in the
	 * Background rather than repeated. `Without an API key, nothing can be mapped`
	 * removes it again for the one scenario that is about its absence.
	 */
	public function theAdminHasConfiguredTheApiKey(): void {
		// FAILS LOUDLY WITH NO KEY, unlike the tolerant `setupSyncMappingAndFolder()`
		// this was modelled on. That tolerance made sense while a missing key only
		// meant "the sync will not reach n8n"; it does not now, because creating a
		// mapping HARD-REFUSES without one. A silent no-op here would turn a missing
		// N8N_API_KEY into five confusing refusals further down the file — or, worse,
		// into a pass on whatever key an earlier scenario happened to leave behind.
		// Raised by Copilot on #89.
		if ($this->n8nApiKey === '') {
			$this->fail(
				'no N8N_API_KEY is available, and every scenario in this file needs one: '
				. 'mapping creation is refused without a configured key.',
			);
		}
		$this->occStdin($this->occ . ' n8n_sync:set-api-key', $this->n8nApiKey);
	}

	/**
	 * @Given no API key is configured
	 *
	 * Clears what the Background set. The stored value is encrypted, so this empties
	 * the config key directly rather than trying to write an empty one through the
	 * command, which rejects it.
	 */
	public function noApiKeyIsConfigured(): void {
		$this->occ('config:app:delete ' . self::APP_ID . ' api_key');
	}

	/** @Given a folder :folder already exists */
	public function aFolderAlreadyExists(string $folder): void {
		$this->davMkdir(trim($folder, '/'));
	}

	/**
	 * @Given an unmapped workflow file at :path
	 *
	 * A `.n8n` THIS APP DID NOT WRITE — no metadata, no id, no mapping. It is the state
	 * a removed sync mapping leaves behind, and the one a link mapping cannot hold.
	 * Written straight over WebDAV with its parents made first, because nothing is
	 * mapped here yet and there is no mirror to seed it through.
	 */
	public function anUnmappedWorkflowFileAt(string $path): void {
		$path = ltrim($path, '/');
		$parent = trim(dirname($path), '/.');
		if ($parent !== '') {
			$this->davMkdir($parent);
		}
		$this->davPut($path, json_encode(
			self::starterWorkflow(basename($path, '.n8n')),
			JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
		) . "\n");
	}

	/**
	 * @When the admin submits this mapping:
	 *
	 * THE TAG IS A ROW LIKE EVERY OTHER FIELD, matching the siblings. The older
	 * `the admin maps the tag :tag with:` named it in the step text, which made the
	 * mapping's own key the one thing that could not be written the way the rest of the
	 * form is. Both are kept — the pre-state arrange still uses the other one.
	 */
	public function theAdminSubmitsThisMapping(TableNode $table): void {
		$form = $this->formValues($table);
		$this->lastSubmittedMapping = $form;
		$tag = (string)($form['tag'] ?? '');
		unset($form['tag']);
		$this->lastMappingForm = ['tag' => $tag] + $form;
		// COUNTED BEFORE THE ATTEMPT, so the refusal step can check the store is
		// unchanged RELATIVE to whatever was already configured — see
		// `the mapping is rejected, explaining` for why an absolute count is wrong.
		$this->mappingsBeforeCreate = count($this->listMappings());
		$this->addMappingFromForm($tag, $form);
	}

	/**
	 * @When allows the existing unmapped workflows to be purged
	 *
	 * THE SECOND BEAT, NOT A FORM FIELD. It is not a setting a mapping stores, and as
	 * an `And` after the `When` it reads the way the interaction actually goes — the
	 * admin submits, the app answers with a count, the admin accepts.
	 *
	 * IT ASSERTS THE REFUSAL HAPPENED FIRST. Re-submitting with the flag when the first
	 * attempt already succeeded would prove nothing at all, quietly: the scenario would
	 * pass whether or not the app ever warns anybody.
	 */
	public function allowsTheExistingUnmappedWorkflowsToBePurged(): void {
		if ($this->lastExit === 0) {
			$this->fail(
				'the mapping was accepted without asking about the workflow files already there, '
				. "so there was nothing to allow:\n{$this->lastOutput}",
			);
		}
		$form = $this->lastSubmittedMapping;
		$tag = (string)($form['tag'] ?? '');
		unset($form['tag']);
		$this->addMappingFromForm($tag, $form, true);
	}

	/**
	 * @Then no ".n8n" workflows exist under :folder in Nextcloud
	 *
	 * AT EVERY DEPTH. The file the scenario seeds sits in a subfolder on purpose — a
	 * purge that only swept the top level would leave the contradiction it exists to
	 * prevent one folder down, and a top-level-only assertion could never say so.
	 */
	public function noWorkflowsExistUnderInNextcloud(string $folder): void {
		$found = [];
		foreach ($this->davTreeUnder(trim($folder, '/')) as $child) {
			if (str_ends_with($child, '.n8n')) {
				$found[] = ltrim($child, '/');
			}
		}
		if ($found !== []) {
			$this->fail("'$folder' still holds workflow files: " . implode(', ', $found));
		}
	}

	/**
	 * @Then :path left no trash entry
	 *
	 * PURGED, NOT TRASHED, and this is the line that says so. A trashed file offers a
	 * restore, and restoring into a link mapping cannot work — a link folder refuses
	 * authoring, so there is nowhere for the bytes to go. Offering the restore would be
	 * a worse lie than refusing it, so the file never reaches the trash at all.
	 */
	public function leftNoTrashEntry(string $path): void {
		$name = basename(trim($path, '/'));
		if ($this->trashbinPathFor($name) !== null) {
			$this->fail("'$path' went to the Nextcloud trash; a purged workflow file must not");
		}
	}

	/**
	 * @Then the mapping is rejected, explaining :fragment
	 *
	 * ONE SENTENCE FOR ONE FACT. A refusal that does not say why is not a behaviour
	 * anybody wants, so "it was refused" and "it said why" were always asserted
	 * together — two lines that could never sensibly appear apart.
	 *
	 * AND NOTHING WAS STORED — CHECKED HERE RATHER THAN ASKED FOR IN A SENTENCE. A
	 * refusal that half-saved is not a refusal, so this is part of what the word MEANS.
	 * It replaces `And there are exactly 0 configured mappings`, which was a violation:
	 * it said a refusal is only observable on an empty store. The check is RELATIVE, so
	 * it holds with mappings already configured.
	 */
	public function theMappingIsRejectedExplaining(string $fragment): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was unexpectedly accepted:\n{$this->lastOutput}");
		Assert::assertStringContainsString(
			$fragment,
			$this->lastOutput,
			"the refusal did not mention '$fragment':\n{$this->lastOutput}",
		);

		if ($this->mappingsBeforeCreate === null) {
			return;
		}
		$now = count($this->listMappings());
		if ($now !== $this->mappingsBeforeCreate) {
			$this->fail(sprintf(
				"the mapping was refused and stored anyway: %d configured before the attempt, %d after.\n%s",
				$this->mappingsBeforeCreate,
				$now,
				$this->lastOutput,
			));
		}
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was unexpectedly accepted:\n{$this->lastOutput}");
	}

	/**
	 * @Then the refusal explains :fragment
	 *
	 * A FRAGMENT, NOT THE WHOLE MESSAGE. The scenario's job is to prove the
	 * refusal names the field at fault so an admin knows what to change; pinning
	 * the exact sentence would make every wording improvement a test failure.
	 */
	public function theRefusalExplains(string $fragment): void {
		Assert::assertStringContainsString(
			$fragment,
			$this->lastOutput,
			"the refusal did not mention '$fragment':\n{$this->lastOutput}",
		);
	}

	/**
	 * @Then there are exactly :count configured mappings
	 * @Then there is exactly :count configured mapping
	 */
	public function thereAreExactlyNConfiguredMappings(int $count): void {
		Assert::assertCount($count, $this->listMappings(), "expected $count mappings");
	}

	/** Translate a UI mode word to the stored mode (sync|link; saga Ch2 §14). */
	private function modeToModel(string $mode): string {
		return match ($mode) {
			'sync' => 'sync',
			'link' => 'link',
			default => throw new \InvalidArgumentException("unknown mode '$mode'"),
		};
	}

	/** "team folder" → true, "admin folder" → false. */
	private function storageToModel(string $storage): bool {
		return str_contains($storage, 'team');
	}

	/**
	 * A table of `| field | value |` rows as an array, with BLANK VALUES DROPPED.
	 *
	 * A blank cell in an Examples row means the admin left the field alone, which
	 * is not the same as submitting an empty string — so it must not reach the
	 * payload at all, or the app would validate the empty value instead of
	 * applying its default.
	 *
	 * @return array<string,string>
	 */
	private function formValues(TableNode $table): array {
		$out = [];
		foreach ($table->getRowsHash() as $field => $value) {
			$value = trim((string)$value);
			if ($value !== '') {
				$out[(string)$field] = $value;
			}
		}
		return $out;
	}

	/**
	 * Group ids from a comma-separated cell.
	 *
	 * @return list<string>
	 */
	private function groupList(string $value): array {
		$out = [];
		foreach (explode(',', $value) as $g) {
			$g = trim($g);
			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/**
	 * Submit a mapping form over occ.
	 *
	 * Only the keys the form actually supplied are sent, so the app's own
	 * defaults apply to the rest — which is the whole point of the blank cell.
	 *
	 * @param array<string,string> $form
	 * @return array{exit: int, output: string}
	 */
	private function addMappingFromForm(string $tag, array $form, bool $purge = false): array {
		$data = ['n8n_tag' => $tag];
		if ($purge) {
			// NOT A MAPPING FIELD — the admin's answer to the app's question, sent only
			// on the retry. See `allows the existing unmapped workflows to be purged`.
			$data['purge_workflows'] = true;
		}
		if (array_key_exists('folder', $form)) {
			$data['team_folder'] = $form['folder'];
		}
		if (array_key_exists('mode', $form)) {
			$data['mode'] = $form['mode'];
		}
		if (array_key_exists('groups', $form)) {
			$data['nc_groups'] = $this->groupList($form['groups']);
		}
		if (array_key_exists('storage', $form)) {
			$data['use_team_folder'] = $this->storageToModel($form['storage']);
		}

		return $this->occ('n8n_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
	}

	/**
	 * Every path under a folder, at any depth, as `/relative/paths`.
	 *
	 * A BREADTH-FIRST WALK RATHER THAN A DEEP PROPFIND, because `Depth: infinity` is
	 * refused by default on most Nextcloud deployments — including the one CI stands
	 * up. One request per folder is slower and always allowed.
	 *
	 * Ported from the Grafana sibling, where `no ".grafana" dashboards exist under …`
	 * needed the same thing for the same reason: a purge that only swept the top level
	 * would leave the very contradiction the rule exists to prevent one folder down.
	 *
	 * @return list<string>
	 */
	private function davTreeUnder(string $folder): array {
		$folder = trim($folder, '/');
		$out = [];
		$queue = [$folder];
		// An index cursor rather than array_shift(), which reindexes the whole queue on
		// every pop and turns a deep tree quadratic.
		for ($i = 0; $i < count($queue); $i++) {
			foreach ($this->davChildren($queue[$i]) as $child => $isFolder) {
				$out[] = '/' . $child;
				if ($isFolder) {
					$queue[] = $child;
				}
			}
		}
		return $out;
	}

	/**
	 * One level of a Nextcloud folder: child path => whether it is a collection.
	 *
	 * @return array<string, bool>
	 */
	private function davChildren(string $folder): array {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($folder), [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
			'http_errors' => false,
		]);
		if ($res->getStatusCode() === 404) {
			return []; // a folder that was never created, which is not a failure here
		}
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $folder failed: " . (string)$res->getBody());

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$self = trim($folder, '/');

		$out = [];
		foreach ($doc->xpath('//d:response') ?: [] as $response) {
			$response->registerXPathNamespace('d', 'DAV:');
			$href = rawurldecode((string)(($response->xpath('d:href')[0]) ?? ''));
			$isFolder = ($response->xpath('.//d:collection') ?: []) !== [];

			// The href is server-absolute (`/remote.php/dav/files/<user>/…`); everything
			// up to and including the folder itself is the prefix, and the remainder is
			// the path this suite speaks in.
			$pos = strpos($href, '/' . $self . '/');
			if ($self !== '' && $pos === false) {
				continue; // the collection's own entry
			}
			$rel = $self === '' ? $href : substr($href, $pos + 1);
			$rel = trim($rel, '/');
			if ($rel === '' || $rel === $self) {
				continue;
			}
			$out[$rel] = $isFolder;
		}
		return $out;
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
}
