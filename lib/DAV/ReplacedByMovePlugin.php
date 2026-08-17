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
use OCA\N8nSync\Service\ReplacedByMoveStore;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Notices that a WebDAV MOVE is about to REPLACE an existing workflow file, and says
 * so before sabre deletes it.
 *
 * ## THE GESTURE IS "KEEP THE NEW VERSION", AND NOTHING WAS DELETED
 *
 * Move a file onto a name that already exists and the Files app asks *"Which files
 * do you want to keep?"*. Answer "the new version" and it sends one ordinary MOVE,
 * whose absent `Overwrite` header means T. Sabre answers that by calling
 * `tree->delete($destination)` and THEN moving — so the file being replaced goes to
 * the trash, `BeforeNodeDeletedEvent` fires, and
 * {@see \OCA\N8nSync\Listener\DeleteToN8nListener} archives the workflow in n8n.
 *
 * That is the wrong answer to the question that was actually asked. The user did not
 * delete a workflow; they said which of two bodies should survive. Archiving it and
 * hoping the arrival unarchives it a moment later is not a design, it is a race that
 * happens to have been winnable.
 *
 * So this plugin marks the destination in {@see ReplacedByMoveStore} from sabre's
 * `beforeMove` — the one hook that fires while both halves are still one gesture —
 * and the delete listener stands down for that file. The workflow stays live
 * throughout; {@see \OCA\N8nSync\Service\MotionService::moveIn} then re-stamps the
 * arriving file onto it and pushes the body that won.
 *
 * ## FAILING OPEN, DELIBERATELY, AND WHAT THAT COSTS
 *
 * Every lookup that cannot answer leaves the mark unset, which means the delete
 * behaves exactly as it did before this plugin existed. The failure mode is
 * therefore the OLD behaviour rather than a new one — a workflow archived when it
 * need not have been, which a move-in unarchives — and never a delete that fails to
 * reach n8n. Of the two ways to be wrong here, that is the recoverable one.
 *
 * PRIORITY 10, ahead of sabre's own `httpMove` work, for the same reason
 * {@see LinkWriteGuardPlugin} runs early: the mark has to exist before the delete it
 * describes.
 */
final class ReplacedByMovePlugin extends ServerPlugin {
	public function __construct(
		private ReplacedByMoveStore $store,
		private LoggerInterface $logger,
	) {
	}

	/** Kept from {@see initialize} so {@see beforeMove} can resolve a path to its node. */
	private ?Server $server = null;

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		$server->on('beforeMove', [$this, 'beforeMove'], 10);
	}

	/**
	 * @param string $source the path being moved (unused — the mark is about what it lands on)
	 * @return bool always true; this plugin observes and never refuses a move
	 */
	public function beforeMove(string $source, string $destination): bool {
		try {
			$node = $this->server?->tree->getNodeForPath($destination);
		} catch (\Throwable) {
			// The overwhelmingly common case: nothing is there, so this is an
			// ordinary move and there is nothing to mark.
			return true;
		}
		if (!$node instanceof DavFile || !FilenameCodec::isWorkflowName($node->getName())) {
			return true;
		}
		try {
			$fileId = $node->getId();
		} catch (\Throwable $e) {
			$this->logger->debug('n8n_sync overwrite: could not read the id of the file being replaced', [
				'app' => Application::APP_ID,
				'destination' => $destination,
				'exception' => $e,
			]);
			return true;
		}
		$this->store->mark($fileId);
		$this->logger->info('n8n_sync overwrite: a move is replacing a workflow file; its workflow stays live', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'file' => $node->getName(),
		]);
		return true;
	}
}
