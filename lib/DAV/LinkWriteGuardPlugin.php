<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\DAV;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncNotifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Refuses to let a `link`-mode workflow file be overwritten over WebDAV (saga §14.2c).
 *
 * A link is only a tiny pointer to a workflow that lives in n8n — there is no full
 * JSON on the Nextcloud side to change, and any byte written over it would just
 * corrupt the pointer. The Files UI already hides the text editor for links, but a
 * desktop client, a raw WebDAV PUT, or `curl` would otherwise overwrite the pointer
 * blindly. This plugin closes that door at the only reliable choke point.
 *
 * Why a Sabre plugin and not a `BeforeNodeWrittenEvent` listener: that event is
 * produced by core's {@see \OC\Files\Node\HookConnector} from the legacy `write`
 * filesystem hook, and {@see \OCA\DAV\Connector\Sabre\File::put()} only emits that
 * pre-write hook on the **non-part-file** branch (`if (!$needsPartFile)`). Almost
 * every storage uploads through a `.part` file first, so the pre-write event never
 * fires for a normal PUT and the write slips through. Sabre's `beforeWriteContent`
 * is emitted by {@see \Sabre\DAV\Server::updateFile()} *before* `File::put()` runs,
 * so it fires for every PUT regardless of the part-file dance.
 *
 * Throwing {@see Forbidden} here is the native deny: Sabre answers the WebDAV/curl/
 * desktop client with a clean **403 Forbidden** and the bytes are never written. We
 * also log a warning and raise a Nextcloud notification so the user sees *why* the
 * edit bounced and what to do (switch the file to sync, or edit it in n8n).
 *
 * No loop guard is needed: the app's own link writes (pull reconcile, sync↔link
 * re-mode) go through the View/Node API, not Sabre, so they never reach this plugin.
 */
final class LinkWriteGuardPlugin extends ServerPlugin {
	public function __construct(
		private WorkflowMetadata $metadata,
		private MappingService $mappings,
		private SyncNotifier $notifier,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/** Kept from {@see initialize} so {@see beforeUnbind} can resolve a path to its node. */
	private ?Server $server = null;

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		// Run early (low priority number = higher precedence) so we refuse before
		// any bytes are streamed to the part file.
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
		// EXISTENCE IS THE OTHER HALF OF READ-ONLY, and it needs its own hook. The
		// delete IS refused without this — `DeleteToN8nListener` throws
		// `AbortedEventException` from `BeforeNodeDeletedEvent` — but that surfaces over
		// DAV as a bare 403 with no `<s:message>`, so the Files app shows the user a
		// failure with nothing in it. Sabre's `beforeUnbind` is where a refusal can still
		// say why.
		$server->on('beforeUnbind', [$this, 'beforeUnbind'], 10);
		// COPY IS NEITHER A WRITE NOR AN UNBIND, so neither hook above sees it, and the
		// typed `BeforeNodeCopiedEvent` is no help on its own: aborting it stops the copy
		// but Sabre still answers 201, so the user is told it worked and no file appears.
		// Measured in a pod. {@see \OCA\N8nSync\Listener\CopyGuardListener} carries that
		// event for the non-DAV routes; this is the one a person sees.
		//
		// `method:COPY` rather than `beforeBind`: it fires only for a copy, and it HANDS
		// OVER the request, so the source path needs no reaching into `Server::$httpRequest`
		// — an untyped public property psalm will not resolve. The priority runs it ahead
		// of Sabre's own `httpCopy` (100).
		$server->on('method:COPY', [$this, 'onCopy'], 10);
	}

	/**
	 * Refuse a COPY that involves a link, in either direction, with a message.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 *
	 * ## TWO REFUSALS, ONE HOOK, BECAUSE A COPY HAS TWO ENDS
	 *
	 * **A link is not copyable.** It is a read-only projection of a workflow that lives
	 * in n8n; duplicating the pointer does not duplicate anything, it just makes a second
	 * file claiming the same workflow. The same reasoning already refuses editing one
	 * ({@see beforeWriteContent}) and deleting one ({@see beforeUnbind}) — copy was the
	 * hole left in a rule the other two state.
	 *
	 * **A link mapping is not a destination.** Its folder is filled from the mapping's
	 * tag in n8n and from nothing else, so a file put there by hand is at best ignored and
	 * at worst minted as a workflow the tag does not select — which the next pull would
	 * then delete, taking the copy with it.
	 *
	 * ## FAILING OPEN IS THE RULE HERE, AS EVERYWHERE IN THIS PLUGIN
	 *
	 * Every lookup that cannot answer leaves the copy alone. A guard that blocks on doubt
	 * turns a missing mapping or an unreadable node into a user who cannot copy their own
	 * files, which is worse than the thing being guarded against.
	 */
	public function onCopy(RequestInterface $request, ResponseInterface $response): bool {
		$this->refuseIfSourceIsALink($request->getPath());

		$destination = $request->getHeader('Destination');
		if ($destination !== null && $destination !== '' && $this->server !== null) {
			try {
				$path = $this->server->calculateUri($destination);
			} catch (\Throwable) {
				return true; // a destination Sabre cannot place is not ours to judge
			}
			$this->refuseIfDestinationIsALinkMapping($path);
		}
		return true;
	}

	/** The source of the COPY — the path the request was made against. */
	private function refuseIfSourceIsALink(string $source): void {
		try {
			$node = $this->server?->tree->getNodeForPath($source);
		} catch (\Throwable) {
			return;
		}
		if (!$this->isLinkFile($node)) {
			return;
		}

		$name = $node->getName();
		$this->logger->warning('n8n_sync: refused a WebDAV copy of a link-mode workflow file', [
			'app' => Application::APP_ID,
			'fileId' => $node->getId(),
			'file' => $name,
		]);
		throw new Forbidden(
			'“' . $name . '” is a linked n8n workflow — only a pointer to a workflow that lives in n8n, '
			. 'so there is nothing here to copy. Duplicate the workflow in n8n instead, and it will '
			. 'appear here on the next sync.',
		);
	}

	/**
	 * The destination the copy is binding to. The node does not exist yet, so the
	 * mapping is resolved from the PATH — built the way the rest of the app spells an
	 * internal path (`/<uid>/files/<relative>`), which is what
	 * {@see MappingService::resolveForPath} is given everywhere else.
	 */
	private function refuseIfDestinationIsALinkMapping(string $path): void {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return;
		}
		$relative = preg_replace('#^files/[^/]+/#', '', ltrim($path, '/'));
		if (!is_string($relative) || $relative === '') {
			return;
		}
		try {
			$mapping = $this->mappings->resolveForPath('/' . $uid . '/files/' . $relative);
		} catch (\Throwable) {
			return;
		}
		if ($mapping === null || $mapping->mode !== Mapping::MODE_LINK) {
			return;
		}

		$this->logger->warning('n8n_sync: refused a WebDAV copy into a link mapping', [
			'app' => Application::APP_ID,
			'path' => $relative,
			'mapping' => $mapping->id,
		]);
		throw new Forbidden(
			'“' . $mapping->teamFolder . '” mirrors an n8n tag in link mode, so its contents come from n8n '
			. 'and files can’t be added here. Tag the workflow in n8n instead, or switch the mapping '
			. 'to sync mode to author workflows in Nextcloud.',
		);
	}

	/**
	 * Refuse DELETE on a link file, with a message.
	 *
	 * A link is a read-only projection of a workflow that lives in n8n and is perfectly
	 * fine. Removing the pointer only makes the mapped folder disagree with the tag it
	 * mirrors, and the next pull writes the file straight back — so the delete was never
	 * durable, it was just silent. The listener is the backstop that catches every route
	 * (occ, another app, a script); this is the one the user sees.
	 */
	public function beforeUnbind(string $path): bool {
		try {
			$node = $this->server?->tree->getNodeForPath($path);
		} catch (\Throwable) {
			return true; // gone already, or not ours to judge — never block on doubt
		}
		if (!$this->isLinkFile($node)) {
			return true; // sync/unmapped files are the user's to delete
		}

		$name = $node->getName();
		$this->logger->warning('n8n_sync: refused a WebDAV delete of a link-mode workflow file', [
			'app' => Application::APP_ID,
			'fileId' => $node->getId(),
			'file' => $name,
		]);

		throw new Forbidden(
			'“' . $name . '” is a linked n8n workflow — only a pointer to a workflow that lives in n8n, '
			. 'so it can’t be deleted here. Remove the workflow from this mapping’s tag in n8n, '
			. 'or remove the mapping itself.',
		);
	}

	/**
	 * @param mixed $data
	 * @param bool|null $modified
	 */
	public function beforeWriteContent(string $path, INode $node, &$data, &$modified): bool {
		if (!$this->isLinkFile($node)) {
			return true; // not a link — sync/unmapped files hold full JSON and may be edited
		}
		$name = $node->getName();
		$fileId = $node->getId();

		$this->logger->warning('n8n_sync: refused a WebDAV edit to a link-mode workflow file', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'file' => $name,
		]);

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->notifier->linkEditBlocked($uid, $fileId, $name);

		throw new Forbidden(
			'“' . $name . '” is a linked n8n workflow — only a pointer to a workflow that lives in n8n, '
			. 'so its file can’t be edited here. Switch it to sync mode to edit the JSON locally, '
			. 'or open it in n8n to make changes.',
		);
	}

	/**
	 * Is this DAV node one of our link-mode workflow files? Classified from the
	 * file's own metadata, and ANY doubt — wrong node type, foreign name, an
	 * unreadable stamp — answers no: everything this plugin does is a refusal,
	 * so failing open is what keeps a metadata hiccup from blocking a user.
	 *
	 * `@psalm-assert-if-true` narrows $node for the caller's true branch, which
	 * is what lets the refusal sites call getId()/getName() without re-checking.
	 *
	 * @psalm-assert-if-true DavFile $node
	 */
	private function isLinkFile(?INode $node): bool {
		if (!$node instanceof DavFile || !FilenameCodec::isWorkflowName($node->getName())) {
			return false;
		}
		try {
			return $this->metadata->read($node->getId())?->isLink() ?? false;
		} catch (\Throwable) {
			return false;
		}
	}
}
