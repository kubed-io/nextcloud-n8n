<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

/**
 * Who a legacy trash hook is acting for. An interactive gesture has a session
 * user; a hook driven from occ (the retention job expiring a trash, a scripted
 * restore) sets up the filesystem for the user it is processing instead, so
 * `\OC_User::getUser()` names them. Shared by {@see TrashPurgeHook} and
 * {@see TrashRestoreHook}, which need the same answer for the same reason.
 *
 * Deliberately NOT the rule the event listeners use — they fall back to the
 * file's owner or the sync actor, which would be wrong here: a trash is always
 * somebody's, and the filesystem setup says whose.
 */
trait ResolvesHookActor {
	private function resolveUid(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid !== '') {
			return $uid;
		}
		$fsUser = \OC_User::getUser();
		return $fsUser === false ? '' : $fsUser;
	}
}
