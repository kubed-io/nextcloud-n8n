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
 * First-class-file-type steps (saga §14.9 `file-type.feature`): the custom
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
trait FileTypeSteps {
	private const N8N_MIME = 'application/n8n+json';

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

	// ── Then: mimetype + icon ───────────────────────────────────────────────────

	/** @Then its mimetype is :mime */
	public function itsMimetypeIs(string $mime): void {
		Assert::assertSame($mime, $this->davContentType($this->currentFilePath), 'the file does not carry the custom mimetype');
	}

	/** @Then the Files app shows the n8n icon instead of a generic JSON icon */
	public function theFilesAppShowsTheN8nIcon(): void {
		// The icon is a deterministic consequence of the registered mimetype: the
		// RegisterMimetype migration maps application/n8n+json → the app's icon, so
		// a file carrying that mimetype (not application/json) renders the n8n glyph.
		// Behat can't read the rendered pixels, so the mimetype IS the testable proof.
		$ct = $this->davContentType($this->currentFilePath);
		Assert::assertSame(self::N8N_MIME, $ct, 'mimetype is not the custom n8n type — the icon would be the generic JSON glyph');
		Assert::assertNotSame('application/json', $ct, 'file is still plain JSON');
	}

	// ── When/Then: PROPFIND exposes the metadata ─────────────────────────────────

	/** @When /^a WebDAV client requests the file's properties \(PROPFIND\)$/ */
	public function aWebdavClientRequestsTheProperties(): void {
		// The assertion is in the matching Then; each property is read back via the
		// shared davReadMetadata (a Depth-0 PROPFIND that only returns 200-block props).
	}

	/** @Then the raw XML includes: */
	public function theRawXmlIncludes(TableNode $table): void {
		foreach ($table->getColumnsHash() as $row) {
			$prop = $row['property'];
			$key = $this->metadataKeyFromProp($prop);
			Assert::assertNotNull(
				$this->davReadMetadata($this->currentFilePath, $key),
				"PROPFIND did not expose $prop in a 200 block",
			);
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
		throw new \RuntimeException('REPORT-by-indexed-mode harness pending (file-type.feature scenario is @todo)');
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

	/** Read the file's mimetype via PROPFIND (d:getcontenttype). */
	private function davContentType(string $path): string {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontenttype/></d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $path failed: " . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$ct = (string)($doc->xpath('//d:getcontenttype')[0] ?? '');
		// getcontenttype can carry a "; charset=..." suffix — keep only the type.
		return trim(explode(';', $ct)[0]);
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
