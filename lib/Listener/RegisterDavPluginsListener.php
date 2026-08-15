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
 * A SECOND PLUGIN LIVED HERE BRIEFLY AND HAD TO GO. `CopyNamePlugin` rewrote a COPY's
 * `Destination` header so a colliding copy was born under our spelling rather than
 * Nextcloud's. It worked, and it broke the Files app, which stats the path IT chose the
 * moment the copy returns. The rename is deferred to {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob}
 * instead — see `features/AGENTS.md#the-copy-cannot-be-renamed-before-the-client-has-looked-at-it`.
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
