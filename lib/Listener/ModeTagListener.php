<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\ModeChangeService;
use OCA\N8nSync\Service\OwnershipTags;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\TeamFolderService;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use Psr\Log\LoggerInterface;

/**
 * Turns a user's system-tag change into an **exclude / restore** (saga §14.8). The
 * mapping's mode (`sync` / `link`) is authoritative — there is no per-file sync↔link
 * override — so the only tag changes this listener acts on are `n8n:ignore`:
 *
 *   assign   `n8n:ignore` → {@see ModeChangeService::changeTo()} with target `ignored`:
 *            the workflow is archived in n8n and the file flips to `ignored` mode,
 *            keeping its body, id, and location — sync then skips it.
 *   unassign `n8n:ignore` → {@see ModeChangeService::unignore()}: the workflow is
 *            unarchived and the file returns to its mapping's mode.
 *
 * Assigning `n8n:sync` / `n8n:link` by hand does nothing — the mapping decides the
 * mode, so a stray mode tag is left for the next pull to reconcile.
 *
 * Loop safety: `changeTo()`/`unignore()` do their writes inside the {@see SyncGuard},
 * so the tag/metadata events they re-fire land here with {@see SyncGuard::active()}
 * true and we bail — no recursion.
 *
 * @implements IEventListener<TagAssignedEvent|TagUnassignedEvent>
 */
final class ModeTagListener implements IEventListener {
	public function __construct(
		private ModeChangeService $modeChange,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private ISystemTagManager $tagManager,
		private TeamFolderService $teamFolders,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof TagAssignedEvent && !$event instanceof TagUnassignedEvent) {
			return;
		}
		if ($event->getObjectType() !== 'files' || $this->guard->active()) {
			return; // our own apply()/changeTo() re-touch tags — don't re-enter
		}

		if ($event instanceof TagAssignedEvent) {
			// The mapping owns sync vs link — only `n8n:ignore` is actionable here.
			if (!$this->tagsIncludeIgnore($event->getTags())) {
				return;
			}
			$this->forEachWorkflowFile(
				$event->getObjectIds(),
				fn (File $node) => $this->modeChange->changeTo($node, WorkflowMetadata::MODE_IGNORED),
			);
			return;
		}

		// TagUnassignedEvent: only removing `n8n:ignore` matters — it returns the file
		// to its mapping's default mode.
		if (!$this->tagsIncludeIgnore($event->getTags())) {
			return;
		}
		$this->forEachWorkflowFile(
			$event->getObjectIds(),
			fn (File $node) => $this->modeChange->unignore($node),
		);
	}

	/**
	 * True when `n8n:ignore` is among the given tag ids. `TagAssignedEvent::getTags()`
	 * / `TagUnassignedEvent::getTags()` yield tag *ids*; resolve to names.
	 *
	 * @param array<int|string> $tagIds
	 */
	private function tagsIncludeIgnore(array $tagIds): bool {
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			if ($tag->getName() === OwnershipTags::TAG_IGNORE) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve each tagged object id to a managed `*.n8n.json` file (for the acting user)
	 * and run $action against it. Non-files, non-managed files, and unresolvable ids are
	 * skipped.
	 *
	 * THE ACTING USER MAY NOT BE A SESSION USER. `occ tag:files:add … n8n:ignore` is a
	 * supported way to exclude a workflow, and it dispatches this event with nobody
	 * logged in — so bailing on an empty session made the control tag silently inert on
	 * the CLI (penpot saga §C6.18). Falls back to the sync actor, the same uid the pull
	 * writes as; only "no session AND no resolvable actor" gives up.
	 *
	 * @param array<int|string> $objectIds
	 * @param callable(File): void $action
	 */
	private function forEachWorkflowFile(array $objectIds, callable $action): void {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			try {
				$uid = $this->teamFolders->resolveActorUid();
			} catch (\Throwable) {
				return;
			}
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync mode-tag: could not open a Files view', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return;
		}

		foreach ($objectIds as $objectId) {
			try {
				$node = $userFolder->getById((int)$objectId)[0] ?? null;
			} catch (\Throwable $e) {
				$this->logger->debug('n8n_sync mode-tag: could not resolve file', [
					'app' => Application::APP_ID,
					'objectId' => $objectId,
					'exception' => $e,
				]);
				continue;
			}
			if (!FilenameCodec::isWorkflowFile($node)) {
				continue;
			}
			$action($node);
		}
	}
}
