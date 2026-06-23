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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAssignedEvent;
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
 * Loop safety: `changeTo()` does its tag re-assert inside the {@see SyncGuard}, so the
 * `TagAssignedEvent` that re-fires lands here with {@see SyncGuard::active()} true and we
 * bail — no recursion.
 *
 * @implements IEventListener<TagAssignedEvent>
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
		if (!$event instanceof TagAssignedEvent || $event->getObjectType() !== 'files') {
			return;
		}
		if ($this->guard->active()) {
			return; // our own apply() re-assigns tags — don't re-enter
		}

		// Which of our mode tags was assigned? (Ignore any non-n8n tag change.)
		// TagAssignedEvent::getTags() yields tag *ids*; resolve them to names.
		$target = null;
		foreach ($this->tagManager->getTagsByIds($event->getTags()) as $tag) {
			$name = $tag->getName();
			if ($name === OwnershipTags::TAG_LINK) {
				$target = Mapping::MODE_LINK;
			} elseif ($name === OwnershipTags::TAG_SYNC) {
				$target = Mapping::MODE_SYNC;
			}
		}
		if ($target === null) {
			return;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return; // tag change with no acting user — nothing to resolve against
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);

		foreach ($event->getObjectIds() as $objectId) {
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
			$this->modeChange->changeTo($node, $target);
		}
	}
}
