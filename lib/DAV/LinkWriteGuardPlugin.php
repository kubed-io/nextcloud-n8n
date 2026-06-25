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
use OCA\N8nSync\Service\WorkflowMetadata;
use OCA\N8nSync\Service\WritebackNotifier;
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
		private WritebackNotifier $notifier,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		// Run early (low priority number = higher precedence) so we refuse before
		// any bytes are streamed to the part file.
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
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
		if (!str_ends_with($name, FilenameCodec::EXT)) {
			return true; // only our workflow files are constrained
		}

		// Classify the file from its own metadata. Anything we can't read is NOT a
		// link, so we must never block it — fail open on any doubt.
		try {
			$fileId = $node->getId();
			$meta = $this->metadata->read($fileId);
		} catch (\Throwable) {
			return true;
		}
		if ($meta === null || ($meta[WorkflowMetadata::KEY_MODE] ?? '') !== Mapping::MODE_LINK) {
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
