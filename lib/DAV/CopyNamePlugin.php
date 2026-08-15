<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\DAV;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Names a copied workflow file OUR way before the copy happens, so it is never called
 * anything else — not even for a moment.
 *
 * ## WHO NAMES A COPY, AND WHY IT IS NOT NEXTCLOUD'S SERVER
 *
 * Nextcloud's SERVER does not name a copy at all. WebDAV COPY means "copy to exactly
 * this path"; if something is already there and `Overwrite: F`, the answer is a flat
 * **412 Precondition Failed**. There is no server-side rename on the copy path, and
 * nothing there calls `Folder::getNonExistingName()`.
 *
 * The name is chosen in the BROWSER, by `getUniqueName()` from `@nextcloud/files`,
 * before any of our code exists. It counts from 1 and puts the counter before the LAST
 * extension, because to it our file is a `.json` called `Fleet Health.n8n`:
 *
 *     Fleet Health.n8n (1).json     <- what the client asks for
 *     Fleet Health (1).n8n.json     <- what the file should be called
 *
 * There is no extension point for that rule. The Files app calls `getUniqueName()`
 * internally, and it takes a custom suffix function that nobody can reach from an app.
 * So the rule cannot be changed.
 *
 * **It can be got ahead of, though, and this is the only place.** The chosen name
 * arrives at the server as the `Destination` header of the COPY, and Sabre fires
 * `beforeMethod:COPY` while that header is still just a string. Rewriting it here means
 * the file is BORN as `Fleet Health (1).n8n.json`. It never exists under the other
 * name, so nothing downstream has to tolerate one.
 *
 * ## WHY BEING BORN RIGHT MATTERS MORE THAN BEING FIXED LATER
 *
 * The alternative was to let the file land wrong and rename it from a background job.
 * That leaves a window — up to a cron tick — in which a real file on disk is called
 * something only this app can read, and it does not scale: the window has to be
 * tolerated for `(1)`, `(2)`, and every `(N)` after them, in every predicate that ever
 * looks at a filename. One rule applied once at the door replaces all of it.
 *
 * {@see FilenameCodec::canonicalise()} still READS the other spelling, and
 * {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob} still repairs it. Those are the
 * safety net for copies that never touch WebDAV — an internal `File::copy()` from PHP,
 * or a file restored out of an old backup. Belt and braces, with this as the belt.
 *
 * Fails open on absolutely everything. A copy that this plugin cannot classify is a copy
 * Nextcloud should perform exactly as asked; a naming preference is never worth turning
 * into a failed user gesture.
 */
final class CopyNamePlugin extends ServerPlugin {
	private ?Server $server = null;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		// Early, but after authentication — this only rewrites a header, and it must
		// happen before the DAV app's own COPY handling reads it.
		$server->on('beforeMethod:COPY', [$this, 'beforeCopy'], 15);
	}

	public function beforeCopy(RequestInterface $request, ResponseInterface $response): bool {
		try {
			$this->rewriteDestination($request);
		} catch (\Throwable $e) {
			// NEVER let a naming preference break a copy. The worst case without this
			// rewrite is the file the app already knows how to read and repair.
			$this->logger->debug('n8n_sync: left a copy destination alone', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
		return true;
	}

	/**
	 * Swap Nextcloud's collision spelling for ours in the `Destination` header, if that
	 * is what it holds and the name we want is free.
	 */
	private function rewriteDestination(RequestInterface $request): void {
		$destination = $request->getHeader('Destination');
		if ($destination === null || $destination === '' || $this->server === null) {
			return;
		}

		$path = $this->server->calculateUri($destination);
		$name = basename($path);
		if (!FilenameCodec::isNextcloudSpelling($name)) {
			return; // an ordinary copy, or not one of ours — leave it exactly as asked
		}

		$wanted = FilenameCodec::canonicalise($name);
		$parent = \dirname($path);
		$target = ($parent === '.' || $parent === '/') ? $wanted : $parent . '/' . $wanted;

		// THE CLIENT ALREADY PROVED ITS OWN NAME WAS FREE; ours is a different name and
		// has to be checked separately. If it is taken, the client's name is the one
		// that works, and a copy that succeeds under a name we would rather not have
		// beats a 412 the user did not ask for.
		if ($this->server->tree->nodeExists($target)) {
			return;
		}

		$request->setHeader('Destination', $this->replaceLastSegment($destination, $wanted));
	}

	/**
	 * Put $name where the last path segment of $url is, URL-encoded as a path segment.
	 *
	 * Operating on the ORIGINAL header rather than rebuilding a URL from the internal
	 * path: the header may be absolute or root-relative, and everything before the last
	 * slash is already whatever this deployment's DAV endpoint looks like. Reconstructing
	 * that is a way to get it subtly wrong on somebody else's reverse proxy.
	 */
	private function replaceLastSegment(string $url, string $name): string {
		$slash = strrpos($url, '/');
		return $slash === false ? rawurlencode($name) : substr($url, 0, $slash + 1) . rawurlencode($name);
	}
}
