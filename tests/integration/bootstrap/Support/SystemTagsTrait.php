<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Support;

use GuzzleHttp\Client;
use PHPUnit\Framework\Assert;

/**
 * SYSTEM-TAG PLUMBING, shared across the suite — transport only, no
 * @Given/@When/@Then, which is what makes it Support/ rather than Steps/.
 * (It began life as `ModeChangeSteps`, the steps for a hand-applied `n8n:*`
 * re-mode toggle; that feature went, its steps went, and the transport every
 * tag step leans on is what survives.)
 *
 * System-tag assignment goes over the systemtags DAV endpoints (no occ for it):
 * resolve/create the tag id under `…/dav/systemtags`, then PUT the relation under
 * `…/dav/systemtags-relations/files/<fileId>/<tagId>`. Composed into
 * {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 */
trait SystemTagsTrait {
	private ?Client $tagDav = null;

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
