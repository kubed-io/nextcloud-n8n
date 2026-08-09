<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Steps;

use GuzzleHttp\Client;
use PHPUnit\Framework\Assert;

/**
 * SYSTEM-TAG PLUMBING, shared across the suite. This trait was written for a
 * per-file sync ⇄ link re-mode driven by a hand-applied `n8n:*` tag; that toggle
 * went when the mapping became the single source of a file's mode, and the last of
 * it — the `n8n:ignore` exclude — went with the ignore feature.
 *
 * What survives is the transport every tag step in the suite leans on.
 *
 * System-tag assignment goes over the systemtags DAV endpoints (no occ for it):
 * resolve/create the tag id under `…/dav/systemtags`, then PUT the relation under
 * `…/dav/systemtags-relations/files/<fileId>/<tagId>`. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait ModeChangeSteps {
	private ?Client $tagDav = null;
	/** The n8n workflow id behind the file under test, for n8n-side override steps. */
	private string $overrideWorkflowId = '';
	/** The mapping's n8n tag that the override workflow must keep, alongside the override. */
	private string $overrideMappingTag = '';

	/** Guzzle client rooted at the dav base, admin basic-auth (systemtags live there). */
	private function tagDavClient(): Client {
		if ($this->tagDav === null) {
			$base = rtrim(getenv('NC_BASE_URL') ?: 'http://localhost:8080', '/');
			$this->tagDav = new Client([
				'base_uri' => $base . '/remote.php/dav/',
				'auth' => [getenv('NC_ADMIN_USER') ?: 'admin', getenv('NC_ADMIN_PASS') ?: 'admin'],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->tagDav;
	}

	// ── When ────────────────────────────────────────────────────────────────────

	/** @When I add :tag without removing :other */
	public function iAddTagWithoutRemoving(string $tag, string $other): void {
		$this->assignSystemTag($this->currentFilePath, $tag);
	}

	/** @When I change its system tag from :from to :to */
	public function iChangeItsSystemTagFromTo(string $from, string $to): void {
		// The app strips the other mode tag itself; assigning the target is enough.
		$this->assignSystemTag($this->currentFilePath, $to);
	}

	// ── n8n-side override (sync ⇄ link applied from n8n, then pulled) ────────────

	/**
	 * Arrange a managed file by landing one in the tag's mapped folder
	 * (create-on-land), so the file's mode follows that mapping (set in the
	 * Background) and its workflow carries the mapping tag. Captures the workflow
	 * id + mapping tag for the n8n-side override steps below.
	 *
	 * @Given a managed :mode workflow file for a workflow tagged :tag
	 */
	public function aManagedWorkflowFileForAWorkflowTagged(string $mode, string $tag): void {
		$this->aManagedWorkflowFileInTheFolder($mode, $tag);
		$this->overrideWorkflowId = $this->lastWorkflowId ?? '';
		$this->overrideMappingTag = $tag;
		Assert::assertNotSame('', $this->overrideWorkflowId, 'arrange: the managed file was not stamped with an n8n id');
	}

	/**
	 * The link variant: arrange a sync file, apply the n8n override, and pull so it
	 * re-modes into the target (link) — reaching "a managed link file whose workflow
	 * carries the override" without needing a separate link mapping.
	 *
	 * @Given a managed :mode workflow file for a workflow tagged :tag and :reserved
	 */
	public function aManagedWorkflowFileForAWorkflowTaggedAndReserved(string $mode, string $tag, string $reserved): void {
		$this->aManagedWorkflowFileInTheFolder('sync', $tag);
		$this->overrideWorkflowId = $this->lastWorkflowId ?? '';
		$this->overrideMappingTag = $tag;
		Assert::assertNotSame('', $this->overrideWorkflowId, 'arrange: the managed file was not stamped with an n8n id');
		$this->applyN8nOverride($reserved);
		$this->runMappingSync('pull', $tag);
		Assert::assertSame(
			$this->wireMode($mode),
			$this->davReadMetadata($this->currentFilePath, self::META_MODE),
			"arrange: the file did not reach $mode mode after the override pull",
		);
	}

	/** @When I add :reserved to that workflow in n8n */
	public function iAddToThatWorkflowInN8n(string $reserved): void {
		$this->applyN8nOverride($reserved);
	}

	/** @When I change that workflow's override tag to :reserved in n8n */
	public function iChangeThatWorkflowsOverrideTagTo(string $reserved): void {
		$this->applyN8nOverride($reserved);
	}

	/**
	 * Set the workflow's n8n tags to exactly {mapping tag, $reserved} — keeping the
	 * mapping tag so the workflow still belongs to the mapping, and replacing any
	 * prior override so "change the override" and "add an override" share one path.
	 */
	private function applyN8nOverride(string $reserved): void {
		Assert::assertNotSame('', $this->overrideWorkflowId, 'no workflow captured for the override');
		$mappingTagId = $this->ensureN8nTag($this->overrideMappingTag);
		$reservedId = $this->ensureN8nTag($reserved);
		$this->setN8nWorkflowTags($this->overrideWorkflowId, [$mappingTagId, $reservedId]);
	}

	// ── Then ────────────────────────────────────────────────────────────────────

	/** @Then the file transitions to :mode mode */
	public function theFileTransitionsToMode(string $mode): void {
		// n8n_mode is exposed over DAV in its WIRE form — link is stored as
		// "reference" (the literal "link" crashes core PROPFIND). Compare to that.
		$wire = $mode === 'link' ? 'reference' : $mode;
		Assert::assertSame(
			$wire,
			$this->davReadMetadata($this->currentFilePath, self::META_MODE),
			"file did not transition to $mode mode",
		);
	}

	/** @Then /^"(n8n:[a-z]+)" is stripped so exactly one mode tag remains$/ */
	public function theTagIsStrippedExactlyOneRemains(string $stripped): void {
		$tags = $this->fileSystemTags($this->currentFilePath);
		$modeTags = array_values(array_filter($tags, fn (string $t) => $t === 'n8n:sync' || $t === 'n8n:link'));
		Assert::assertNotContains($stripped, $modeTags, "'$stripped' was not stripped");
		Assert::assertCount(1, $modeTags, 'expected exactly one mode tag, got: ' . implode(',', $modeTags));
	}

	/** @Then /^the file content collapses to the link pointer \(.*\)$/ */
	public function theFileContentCollapsesToPointer(): void {
		$wf = json_decode((string)$this->davGet($this->currentFilePath), true);
		Assert::assertIsArray($wf, 'file is not JSON after collapse');
		Assert::assertSame('n8n.reference/v1', $wf['$schema'] ?? null, 'body is not a link pointer');
		Assert::assertArrayNotHasKey('nodes', $wf, 'the full workflow JSON is still present — not collapsed');
	}

	/** @Then the full workflow JSON is pulled into the file */
	public function theFullWorkflowJsonIsPulledIn(): void {
		$wf = json_decode((string)$this->davGet($this->currentFilePath), true);
		Assert::assertIsArray($wf, 'file is not JSON after pull');
		Assert::assertArrayHasKey('nodes', $wf, 'the full workflow JSON was not pulled into the file');
	}

	/** @Then the full workflow JSON is now in the file */
	public function theFullWorkflowJsonIsNowInTheFile(): void {
		$this->theFullWorkflowJsonIsPulledIn();
	}

	/**
	 * Proxy for "no longer pushes" / "now pushes": the mode drives push behaviour
	 * (only sync pushes), so asserting the resulting mode is the testable signal.
	 *
	 * @Then saving the file no longer pushes to n8n
	 */
	public function savingNoLongerPushes(): void {
		Assert::assertSame('reference', $this->davReadMetadata($this->currentFilePath, self::META_MODE), 'file is not in link mode, so it would still push');
	}

	/** @Then saving the file now pushes to n8n */
	public function savingNowPushes(): void {
		Assert::assertSame('sync', $this->davReadMetadata($this->currentFilePath, self::META_MODE), 'file is not in sync mode, so it would not push');
	}

	/** @Then the workflow's :key is unchanged */
	public function theWorkflowsKeyIsUnchanged(string $key): void {
		Assert::assertSame($this->lastWorkflowId, $this->davReadMetadata($this->currentFilePath, $key), "$key changed across the re-mode");
	}

	// ── systemtags DAV plumbing ──────────────────────────────────────────────────

	/** Assign a system tag (by name; created if missing) to the file at $path. */
	private function assignSystemTag(string $path, string $tagName): void {
		$fileId = $this->fileId($path);
		$tagId = $this->ensureSystemTag($tagName);
		$res = $this->tagDavClient()->request('PUT', 'systemtags-relations/files/' . $fileId . '/' . $tagId);
		Assert::assertContains($res->getStatusCode(), [201, 204, 409], 'assign tag failed: ' . (string)$res->getBody());
	}

	/** Numeric Nextcloud file id for a path under the admin's files (oc:fileid PROPFIND). */
	private function fileId(string $path): int {
		$user = getenv('NC_ADMIN_USER') ?: 'admin';
		$href = 'files/' . rawurlencode($user) . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
		$res = $this->tagDavClient()->request('PROPFIND', $href, [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		]);
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		$id = (string)($doc->xpath('//oc:fileid')[0] ?? '');
		Assert::assertNotSame('', $id, "could not resolve fileid for $path");
		return (int)$id;
	}

	/** Find the system tag id by name; create it (visible+assignable) if missing. */
	private function ensureSystemTag(string $name): int {
		$res = $this->tagDavClient()->request('PROPFIND', 'systemtags', [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
				. '<d:prop><oc:id/><oc:display-name/></d:prop></d:propfind>',
		]);
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		foreach ($doc->xpath('//d:response') ?: [] as $r) {
			$r->registerXPathNamespace('oc', 'http://owncloud.org/ns');
			$dn = (string)($r->xpath('.//oc:display-name')[0] ?? '');
			$id = (string)($r->xpath('.//oc:id')[0] ?? '');
			if ($dn === $name && $id !== '') {
				return (int)$id;
			}
		}
		// Not found → create it; the new id comes back in Content-Location.
		$create = $this->tagDavClient()->request('POST', 'systemtags', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode(['name' => $name, 'userVisible' => true, 'userAssignable' => true, 'canAssign' => true], JSON_THROW_ON_ERROR),
		]);
		$loc = $create->getHeaderLine('Content-Location');
		$id = (int)basename(rtrim($loc, '/'));
		Assert::assertGreaterThan(0, $id, 'could not create/resolve system tag ' . $name);
		return $id;
	}

	/** Names of the system tags currently assigned to the file at $path. */
	private function fileSystemTags(string $path): array {
		$fileId = $this->fileId($path);
		$res = $this->tagDavClient()->request('PROPFIND', 'systemtags-relations/files/' . $fileId, [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
				. '<d:prop><oc:display-name/></d:prop></d:propfind>',
		]);
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		$names = [];
		foreach ($doc->xpath('//oc:display-name') ?: [] as $n) {
			$v = trim((string)$n);
			if ($v !== '') {
				$names[] = $v;
			}
		}
		return $names;
	}
}
