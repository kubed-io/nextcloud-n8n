<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;

/**
 * The post-event file node of the two events this app treats as "a file landed
 * here": a write carries it as the node, a rename as the target. One reader,
 * because two listeners each carrying their own copy is how the pair drifts.
 */
final class EventNode {
	public static function of(Event $event): ?Node {
		if ($event instanceof NodeWrittenEvent) {
			return $event->getNode();
		}
		if ($event instanceof NodeRenamedEvent) {
			return $event->getTarget();
		}
		return null;
	}
}
