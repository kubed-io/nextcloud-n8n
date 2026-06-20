<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Notification;

use OCA\N8nSync\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders n8n_sync notifications into display text. We don't build any UI — the
 * Notifications app draws the bell entry + toast; this class only turns our
 * stored {subject, parameters} into the strings it shows.
 *
 * Subjects:
 *   - `push_failed` — a save couldn't be written back to n8n. The parsed
 *     message is n8n's own complaint (e.g. "connections must be object") so the
 *     user can fix the workflow JSON.
 */
final class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('n8n sync');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		switch ($notification->getSubject()) {
			case 'push_failed':
				$params = $notification->getSubjectParameters();
				$file = (string)($params['file'] ?? $l->t('workflow'));
				// Rich subject (best practice) with a plain-text fallback for
				// clients that don't render rich objects.
				$notification->setRichSubject(
					$l->t('Couldn’t sync {file} to n8n'),
					['file' => ['type' => 'highlight', 'id' => $file, 'name' => $file]]
				);
				$notification->setParsedSubject(
					$l->t('Couldn’t sync “%s” to n8n', [$file])
				);
				// n8n's own complaint — the actionable detail the user fixes.
				$reason = (string)($notification->getMessageParameters()['reason'] ?? '');
				if ($reason !== '') {
					$notification->setParsedMessage($reason);
				}
				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}
}
