<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCP\Files\Node;

/**
 * Who a file gesture is acting for: the session user, else the node's owner.
 * This is the uid a background job re-resolves the node through (team-folder
 * files are mounted per-user) and the user a failure notification addresses.
 * Empty when neither resolves — the caller decides what that means.
 *
 * NOT the rule for tag events ({@see \OCA\N8nSync\Listener\ContentTagListener}
 * falls back to the configured sync actor instead — a tag change has no owner
 * to borrow) and not the rule for the legacy trash hooks
 * ({@see \OCA\N8nSync\Listener\ResolvesHookActor} reads the filesystem setup).
 */
trait ResolvesActingUser {
	private function actingUserUid(?Node $node): string {
		return $this->userSession->getUser()?->getUID() ?? $node?->getOwner()?->getUID() ?? '';
	}
}
