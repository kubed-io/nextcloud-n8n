<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\N8nSync\DAV\LinkWriteGuardPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds n8n_sync's Sabre plugins to every DAV server as it is built.
 *
 * Core fires {@see SabrePluginAddEvent} during DAV server setup (files, public,
 * remote endpoints) so apps can register their own {@see \Sabre\DAV\ServerPlugin}s.
 * We attach {@see LinkWriteGuardPlugin}, which refuses WebDAV overwrites of
 * `link`-mode workflow files (saga §14.2c).
 *
 * @implements IEventListener<SabrePluginAddEvent>
 */
final class RegisterDavPluginsListener implements IEventListener {
	public function __construct(
		private LinkWriteGuardPlugin $linkWriteGuard,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->linkWriteGuard);
	}
}
