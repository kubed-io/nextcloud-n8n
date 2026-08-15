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
use OCA\N8nSync\Service\SyncNotifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

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
		if (!$node instanceof DavFile || !FilenameCodec::isWorkflowName($node->getName())) {
			return true;
		}

		try {
			$managed = $this->metadata->read($node->getId());
		} catch (\Throwable) {
			return true;
		}
		if (!$managed?->isLink()) {
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
		if (!$node instanceof DavFile) {
			return true; // not a file node we care about
		}
		$name = $node->getName();
		if (!FilenameCodec::isWorkflowName($name)) {
			return true; // only our workflow files are constrained
		}

		// Classify the file from its own metadata. Anything we can't read is NOT a
		// link, so we must never block it — fail open on any doubt.
		try {
			$fileId = $node->getId();
			$managed = $this->metadata->read($fileId);
		} catch (\Throwable) {
			return true;
		}
		if (!$managed?->isLink()) {
			return true; // not a link — sync/unmapped/ignored all hold full JSON and may be edited
		}

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
}
