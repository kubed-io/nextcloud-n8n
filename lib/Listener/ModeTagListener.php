<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\ModeChangeService;
use OCA\N8nSync\Service\OwnershipTags;
use OCA\N8nSync\Service\SyncGuard;
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
 * Turns a user's system-tag change into a sync ⇄ link re-mode (saga Ch2 §14.2b
 * `mode-change.feature`). This is the trigger; {@see ModeChangeService} is the engine.
 *
 * When `n8n:sync` or `n8n:link` is assigned to a managed `*.n8n.json` file — whether
 * by the Files context-menu toggle (which just flips the tag), a manual tag change, or
 * adding the second tag by hand — we route to {@see ModeChangeService::changeTo()} with
 * the assigned tag's mode. Because `changeTo()` stamps the target tag and strips the
 * other (via {@see OwnershipTags::apply()}), "the just-added tag wins" + mutual
 * exclusivity both fall out for free.
 *
 * Assigning `n8n:ignore` routes the same way with target `ignored` (saga §14.8): the
 * workflow is archived in n8n and the file flips to `ignored` mode, keeping its body,
 * id, and location — sync then skips it. **Removing** `n8n:ignore` is the inverse — a
 * {@see TagUnassignedEvent} routes to {@see ModeChangeService::unignore()}, which
 * unarchives the workflow and returns the file to its mapping's default mode.
 *
 * Loop safety: `changeTo()`/`unignore()` do their tag re-asserts inside the
 * {@see SyncGuard}, so the tag event that re-fires lands here with
 * {@see SyncGuard::active()} true and we bail — no recursion.
 *
 * @implements IEventListener<TagAssignedEvent|TagUnassignedEvent>
 */
final class ModeTagListener implements IEventListener {
	public function __construct(
		private ModeChangeService $modeChange,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private ISystemTagManager $tagManager,
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
			// Which of our mode tags was assigned? (Ignore any non-n8n tag change.)
			$target = $this->modeForAssignedTags($event->getTags());
			if ($target === null) {
				return;
			}
			$this->forEachWorkflowFile(
				$event->getObjectIds(),
				fn (File $node) => $this->modeChange->changeTo($node, $target),
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
	 * Map a set of just-assigned tag ids to the mode they request, or null if none of
	 * them is one of ours. `TagAssignedEvent::getTags()` yields tag *ids*; resolve to names.
	 *
	 * @param array<int|string> $tagIds
	 */
	private function modeForAssignedTags(array $tagIds): ?string {
		$target = null;
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			$name = $tag->getName();
			if ($name === OwnershipTags::TAG_LINK) {
				$target = Mapping::MODE_LINK;
			} elseif ($name === OwnershipTags::TAG_SYNC) {
				$target = Mapping::MODE_SYNC;
			} elseif ($name === OwnershipTags::TAG_IGNORE) {
				$target = WorkflowMetadata::MODE_IGNORED;
			}
		}
		return $target;
	}

	/**
	 * True when `n8n:ignore` is among the unassigned tag ids.
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
	 * skipped; an unattributable tag change (no acting user) is a no-op.
	 *
	 * @param array<int|string> $objectIds
	 * @param callable(File): void $action
	 */
	private function forEachWorkflowFile(array $objectIds, callable $action): void {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return; // tag change with no acting user — nothing to resolve against
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);

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
			if (!$node instanceof File || !str_ends_with($node->getName(), FilenameCodec::EXT)) {
				continue;
			}
			$action($node);
		}
	}
}
