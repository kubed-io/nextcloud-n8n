<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\MoveIdentityStore;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use Psr\Log\LoggerInterface;

/**
 * Carries a workflow file's identity across a move (saga Ch3 §14.2), because
 * Nextcloud does not.
 *
 * A move between two *storages* — two Team Folders, or a Team Folder and a home
 * folder — is a copy followed by an unlink of the source, and the unlink takes the
 * file's `files_metadata` row with it. Measured on a live instance: the file id was
 * preserved and the metadata came back `[]`. Everything the app stamps lives in that
 * row, so the file lands looking like one this app has never seen.
 *
 * That is precisely the shape create-on-land adopts, so a workflow that already had
 * an id in n8n got a SECOND one minted for it, while the original kept the tag of the
 * folder it left — and the next pull dutifully wrote it back there. One drag, two
 * workflows, two files. See {@see MoveIdentityStore} for the full account.
 *
 * So this listener brackets the move:
 *
 *   - {@see BeforeNodeRenamedEvent} — the source still has its row; read it and stash
 *     it against the source path.
 *   - {@see NodeRenamedEvent} — if the arriving file has lost its id, put the stash
 *     back before anything else looks at it.
 *
 * ## IT MUST RUN FIRST, AND THAT IS A REGISTRATION ORDER, NOT A PRIORITY
 *
 * Symfony's dispatcher calls same-priority listeners in registration order, so this
 * one is registered in {@see \OCA\N8nSync\AppInfo\Application::register} ahead of every
 * other `NodeRenamedEvent` listener. {@see CreateInN8nListener} and {@see MotionListener}
 * both branch on whether the file is managed; restoring after either of them has already
 * decided is restoring after the damage.
 *
 * ## WHY IT ONLY WRITES WHEN THE ID IS GONE
 *
 * A same-storage move keeps the row, and re-writing it would be a pointless round-trip
 * on the common path. More importantly the stash is a photograph of the PAST: on a
 * within-mapping rename the live row is the truth, and stamping the old one over it
 * could undo a change another listener made in between. Absence of an id is the only
 * signal that the row was destroyed rather than merely unchanged.
 *
 * @implements IEventListener<BeforeNodeRenamedEvent|NodeRenamedEvent>
 */
final class MoveIdentityListener implements IEventListener {
	public function __construct(
		private WorkflowMetadata $metadata,
		private MoveIdentityStore $store,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * `@param Event` DELIBERATELY WIDER THAN THE `@implements` ABOVE. Psalm reads the
	 * template parameter as the type of `$event`, so after the first branch returns it
	 * narrows the union to its other member and calls the second `instanceof`
	 * redundant. It is not: the dispatcher hands this method whatever it is registered
	 * for, and both checks are what make an unrelated event a no-op rather than a
	 * TypeError. Restating the real parameter type is the fix; deleting a live guard to
	 * satisfy a static reading of a docblock is not.
	 *
	 * @param Event $event
	 */
	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof BeforeNodeRenamedEvent) {
			$this->capture($event);
			return;
		}
		if ($event instanceof NodeRenamedEvent) {
			$this->restore($event);
		}
	}

	/**
	 * Photograph the source's stamp while it still exists. Keyed by the source path,
	 * which both events name identically by construction — the file id is preserved by
	 * today's storage pair but that is an implementation detail, and the path is not.
	 */
	private function capture(BeforeNodeRenamedEvent $event): void {
		$source = $event->getSource();
		if (!FilenameCodec::isWorkflowFile($source)) {
			return;
		}
		$this->store->keep($source->getPath(), $this->metadata->readRaw($source->getId()));
	}

	/**
	 * Put the stamp back if the move lost it. Guarded, because this is our own write
	 * and no listener should treat it as a user gesture.
	 */
	private function restore(NodeRenamedEvent $event): void {
		$target = $event->getTarget();
		if (!FilenameCodec::isWorkflowFile($target)) {
			return;
		}
		$stashed = $this->store->take($event->getSource()->getPath());
		if ($stashed === null) {
			return; // nothing was captured — not a file we were tracking
		}
		if ($this->metadata->read($target->getId())?->isManaged() === true) {
			return; // the row survived the move; the live one is the truth
		}

		$this->guard->run(function () use ($target, $stashed): void {
			$this->metadata->write($target->getId(), $stashed);
		});
		$this->logger->info('n8n_sync move: the file lost its stamp crossing storages; restored it', [
			'app' => Application::APP_ID,
			'fileId' => $target->getId(),
			'workflowId' => $stashed[WorkflowMetadata::KEY_ID] ?? '',
			'path' => $target->getPath(),
		]);
	}
}
