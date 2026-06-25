<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Raises a Nextcloud notification (bell + toast) when a push to n8n fails (or a
 * link-mode edit is blocked), so the user who saved the file sees *what* happened
 * and can fix the JSON. This is
 * the native channel for an async/background failure — we don't break the file
 * save itself (that would lose their edits) and we don't invent any UI.
 *
 * The notification is keyed on the file id so a later success can clear it
 * ({@see cleared}) and repeated failures collapse onto one entry instead of
 * spamming the bell.
 */
final class SyncNotifier {
	public function __construct(
		private IManager $manager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Notify $userId that pushing $fileName (file id $fileId) to n8n failed
	 * because of $reason (n8n's own message).
	 */
	public function failed(string $userId, int $fileId, string $fileName, string $reason): void {
		if ($userId === '') {
			return;
		}
		try {
			$notification = $this->manager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($this->timeFactory->getDateTime())
				->setObject('workflow', (string)$fileId)
				->setSubject('push_failed', ['file' => $fileName])
				// Cap the stored reason: notification storage isn't a log.
				->setMessage('push_failed', ['reason' => mb_substr($reason, 0, 320)]);
			$this->manager->notify($notification);
		} catch (\Throwable $e) {
			// Notifications are a courtesy; never let them mask the real failure.
			$this->logger->warning('n8n_sync: could not raise push-failure notification', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Notify $userId that an edit to $fileName (file id $fileId) was refused because
	 * the file is in **link** mode — a pointer to a workflow that lives in n8n, with
	 * no local JSON to change. Keyed on the file id + a distinct subject so repeated
	 * blocked saves (a desktop client retrying) collapse onto one bell entry.
	 */
	public function linkEditBlocked(string $userId, int $fileId, string $fileName): void {
		if ($userId === '') {
			return;
		}
		try {
			$notification = $this->manager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($this->timeFactory->getDateTime())
				->setObject('workflow', (string)$fileId)
				->setSubject('link_edit_blocked', ['file' => $fileName]);
			$this->manager->notify($notification);
		} catch (\Throwable $e) {
			// Notifications are a courtesy; the 403 is the real, load-bearing signal.
			$this->logger->warning('n8n_sync: could not raise link-edit-blocked notification', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Clear any pending failure notification for $fileId (all users) — called on
	 * a successful push so a fixed file doesn't keep a stale error in the bell.
	 */
	public function cleared(int $fileId): void {
		try {
			$notification = $this->manager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setObject('workflow', (string)$fileId);
			$this->manager->markProcessed($notification);
		} catch (\Throwable $e) {
			$this->logger->debug('n8n_sync: could not clear push-failure notification', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
