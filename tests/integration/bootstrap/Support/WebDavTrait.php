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
 * WebDAV transport (Guzzle, basic-auth as the admin user): write/read/PROPFIND
 * files the way the desktop client or web UI would — this is what fires the
 * server-side events the create/delete/rename/move listeners hang off. Also
 * covers the trashbin DAV surface and the nc:metadata-* property reads.
 *
 * Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}; reads the
 * shared `$dav` client + `$ncBaseUrl` / `$ncUser` / `$ncPass` / `$createdFolders`.
 */
trait WebDavTrait {
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

	/**
	 * Read the file's mimetype via PROPFIND (d:getcontenttype).
	 *
	 * SHARED PLUMBING, not a step helper. It lived private inside the view-workflow
	 * steps, which worked only because every trait composes into one FeatureContext
	 * — so the lifecycle steps calling it were quietly depending on an unrelated
	 * step file continuing to exist under that name. It reads DAV; it belongs with
	 * the rest of the DAV.
	 */
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

	/** MOVE a file, returning the raw status (so move-refused scenarios can inspect it). */
	private function davMoveStatus(string $from, string $to): int {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		return $this->davClient()->request('MOVE', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		])->getStatusCode();
	}

	/** COPY a file within the user's files root (fires NodeCopiedEvent in NC). */
	private function davCopy(string $from, string $to): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		$res = $this->davClient()->request('COPY', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->assertStatus($res, [201, 204], "COPY $from → $to");
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
	 * exact DAV surface the README documents for viewing a workflow.
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

	/**
	 * The file's DAV etag — the sharpest "was this written?" observable a client has.
	 * Nextcloud mints a fresh etag on **every** write, even one that stores identical
	 * bytes, so an unchanged etag proves no write happened. Preferred over
	 * `getlastmodified` for exactly that reason: mtime has one-second resolution, so
	 * two writes inside the same second are invisible to it.
	 */
	private function davReadEtag(string $path): string {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND etag $path failed: " . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$node = $doc->xpath('//d:prop/d:getetag');
		Assert::assertNotEmpty($node, "no etag returned for $path");
		return trim((string)$node[0], " \t\n\r\0\x0B\"");
	}

	/**
	 * A DAV timestamp property on a file, as a Unix second. `getlastmodified` is
	 * RFC-1123; `{nc:}creation_time` is a Unix second already. Returns null when the
	 * property is absent (an unset creation time reads as 0 or is simply missing).
	 *
	 * NB this is the surface a CLIENT sees, which is the point — these two clocks only
	 * matter because a person sorting a folder in Files, or a desktop client deciding
	 * what to re-download, reads exactly these properties.
	 */
	private function davReadTime(string $path, string $property): ?int {
		$nc = 'http://nextcloud.org/ns';
		$prop = $property === 'creation_time' ? '<nc:creation_time/>' : '<d:' . $property . '/>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="' . $nc . '">'
				. '<d:prop>' . $prop . '</d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $property $path failed: " . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $nc);
		$node = $doc->xpath($property === 'creation_time' ? '//nc:creation_time' : '//d:' . $property);
		if (!$node) {
			return null;
		}
		$raw = trim((string)$node[0]);
		if ($raw === '' || $raw === '0') {
			return null;
		}
		$ts = ctype_digit($raw) ? (int)$raw : strtotime($raw);
		return $ts === false ? null : $ts;
	}

	/** Percent-encode each path segment but keep the slashes. */
	private function davEncode(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
	}
}
