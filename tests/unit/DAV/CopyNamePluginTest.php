<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\DAV;

use OCA\N8nSync\DAV\CopyNamePlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Unit tests for {@see CopyNamePlugin} — the header rewrite that decides what a copied
 * dashboard file is CALLED, before it exists.
 *
 * The whole class is one decision made on one string, so these are cheap and they cover
 * the cases that matter: the rewrite itself at more than one counter, the several ways a
 * destination is none of our business, and the two failure modes that must leave the copy
 * exactly as the client asked for it.
 */
#[CoversClass(CopyNamePlugin::class)]
final class CopyNamePluginTest extends TestCase {
	private const BASE = 'http://cloud.example/remote.php/dav/files/kelly';
	/** What {@see Server::calculateUri()} strips off the front of a destination. */
	private const DAV_ROOT = '/remote.php/dav/';

	/**
	 * @param list<string> $existing tree paths that are already taken, in the shape
	 *                               `calculateUri()` answers with — `files/kelly/Demo/…`
	 */
	private function plugin(array $existing = []): CopyNamePlugin {
		$tree = $this->createStub(Tree::class);
		$tree->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => in_array(trim($path, '/'), $existing, true),
		);

		$server = $this->createStub(Server::class);
		$server->tree = $tree;
		$server->method('calculateUri')->willReturnCallback(self::calculateUri(...));

		$plugin = new CopyNamePlugin(new NullLogger());
		$plugin->initialize($server);
		return $plugin;
	}

	/**
	 * Sabre's own behaviour, reproduced rather than mocked away: an absolute destination
	 * is reduced to its path, decoded, and **the DAV base is stripped** — so what a
	 * plugin gets back is `files/kelly/Demo/…`, not the whole URL path.
	 *
	 * That last part is not a detail. Leaving the base on made every path this handed the
	 * tree disagree with the paths the test declared as taken, so `nodeExists()` answered
	 * false to everything and the occupied-target case silently exercised the ordinary
	 * one instead. A stub that is wrong in the same direction as the code under test
	 * proves nothing at all.
	 */
	private static function calculateUri(string $uri): string {
		$path = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
		if (str_starts_with($path, self::DAV_ROOT)) {
			$path = substr($path, strlen(self::DAV_ROOT));
		}
		return trim($path, '/');
	}

	/**
	 * A request carrying one header and remembering what was written back to it — which
	 * is the entire interface between this plugin and Sabre.
	 */
	private function request(?string $destination): RequestInterface {
		return new class($destination) implements RequestInterface {
			/** @var array<string,string> */
			public array $headers = [];

			public function __construct(?string $destination) {
				if ($destination !== null) {
					$this->headers['Destination'] = $destination;
				}
			}

			public function getHeader(string $name): ?string {
				return $this->headers[$name] ?? null;
			}

			public function setHeader(string $name, string $value): void {
				$this->headers[$name] = $value;
			}
		};
	}

	private function response(): ResponseInterface {
		return new class implements ResponseInterface {
		};
	}

	/** Run the hook and report the `Destination` the request is left holding. */
	private function copyTo(CopyNamePlugin $plugin, string $destination): string {
		$request = $this->request($destination);
		$plugin->beforeCopy($request, $this->response());
		return (string)$request->getHeader('Destination');
	}

	/**
	 * THE CASE THE WHOLE CLASS EXISTS FOR, at three different counters. One counter
	 * proves nothing: a rewrite that only ever handles `(1)` is indistinguishable from
	 * one that appends a fixed string, and the interesting failures live at `(2)` and
	 * beyond — which is exactly where a user lands on their third copy.
	 */
	#[DataProvider('nextcloudsSpellings')]
	public function testTheDestinationIsRewrittenIntoOurSpelling(string $theirs, string $ours): void {
		$rewritten = $this->copyTo($this->plugin(), self::BASE . '/Demo/' . rawurlencode($theirs));

		self::assertSame(self::BASE . '/Demo/' . rawurlencode($ours), $rewritten);
	}

	/** @return iterable<string, array{string, string}> */
	public static function nextcloudsSpellings(): iterable {
		yield 'first copy' => ['Fleet Health.n8n (1).json', 'Fleet Health (1).n8n.json'];
		yield 'second copy' => ['Fleet Health.n8n (2).json', 'Fleet Health (2).n8n.json'];
		yield 'double digits' => ['Fleet Health.n8n (17).json', 'Fleet Health (17).n8n.json'];
		yield 'uid-suffixed shape' => [
			'Board.aBcDeF123456.n8n (1).json',
			'Board (1).aBcDeF123456.n8n.json',
		];
	}

	/**
	 * A copy that did not collide is already called the right thing. The plugin must be
	 * completely absent from that path — it is the overwhelmingly common one.
	 */
	public function testAnUncollidedCopyIsLeftAlone(): void {
		$destination = self::BASE . '/Demo/' . rawurlencode('Fleet Health.n8n.json');

		self::assertSame($destination, $this->copyTo($this->plugin(), $destination));
	}

	/** Not one of ours. Nextcloud's spelling of somebody else's file is their business. */
	public function testAnotherAppsCollidingCopyIsLeftAlone(): void {
		$destination = self::BASE . '/Demo/' . rawurlencode('Budget.xlsx (1).json');

		self::assertSame($destination, $this->copyTo($this->plugin(), $destination));
	}

	/**
	 * THE NAME WE WANT CAN ITSELF BE TAKEN — by an earlier copy that already claimed it.
	 * The client proved ITS name was free; ours is a different name and gets no such
	 * guarantee. Rewriting onto an occupied path would turn a copy that was going to
	 * work into a 412 the user never asked for, so the client's name wins.
	 */
	public function testAnOccupiedTargetLeavesTheClientsNameAlone(): void {
		$plugin = $this->plugin(['files/kelly/Demo/Fleet Health (1).n8n.json']);
		$destination = self::BASE . '/Demo/' . rawurlencode('Fleet Health.n8n (1).json');

		self::assertSame($destination, $this->copyTo($plugin, $destination));
	}

	/**
	 * The guard above must not be so eager that it declines every rewrite. Same fixture,
	 * one counter along: `(2)` is free, so it is taken — which is also what proves the
	 * occupied case was answering about the right path rather than failing to find any.
	 */
	public function testTheNextFreeCounterIsStillRewrittenAroundAnOccupiedOne(): void {
		$plugin = $this->plugin(['files/kelly/Demo/Fleet Health (1).n8n.json']);
		$rewritten = $this->copyTo(
			$plugin,
			self::BASE . '/Demo/' . rawurlencode('Fleet Health.n8n (2).json'),
		);

		self::assertSame(self::BASE . '/Demo/' . rawurlencode('Fleet Health (2).n8n.json'), $rewritten);
	}

	/**
	 * A COPY IS NEVER WORTH BREAKING OVER A NAME. Whatever goes wrong in here — an
	 * unparseable destination, a tree that throws — the request has to continue exactly
	 * as the client sent it, and the app already knows how to read and repair the
	 * resulting file.
	 */
	public function testAThrowingTreeLeavesTheRequestUntouched(): void {
		$tree = $this->createStub(Tree::class);
		$tree->method('nodeExists')->willThrowException(new \RuntimeException('storage is down'));
		$server = $this->createStub(Server::class);
		$server->tree = $tree;
		$server->method('calculateUri')->willReturnCallback(
			static fn (string $uri): string => trim(rawurldecode((string)parse_url($uri, PHP_URL_PATH)), '/'),
		);
		$plugin = new CopyNamePlugin(new NullLogger());
		$plugin->initialize($server);

		$destination = self::BASE . '/Demo/' . rawurlencode('Fleet Health.n8n (1).json');
		self::assertSame($destination, $this->copyTo($plugin, $destination));
	}

	/** A COPY with no destination at all is Sabre's problem to reject, not ours. */
	public function testAMissingDestinationIsNotOurProblem(): void {
		$request = $this->request(null);

		self::assertTrue($this->plugin()->beforeCopy($request, $this->response()));
		self::assertNull($request->getHeader('Destination'));
	}
}
