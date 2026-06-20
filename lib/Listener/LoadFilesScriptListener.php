<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\N8nSync\AppInfo\Application;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/**
 * Loads the Files-app frontend bundle (`n8n_sync-files`) and ships the n8n
 * base URL through Initial State so the bundle can build deep links without a
 * round-trip.
 *
 * Wired to {@see LoadAdditionalScriptsEvent}, which the Files app fires once
 * per page render right before its <script> tags are emitted — exactly the
 * moment NC's CSP nonce is in scope.
 *
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
final class LoadFilesScriptListener implements IEventListener {
	public function __construct(
		private IConfig $config,
		private IInitialState $initialState,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		$this->initialState->provideInitialState(
			'n8n_url',
			rtrim((string)$this->config->getAppValue(Application::APP_ID, 'n8n_url', ''), '/'),
		);
		// Bundle lives under dist/ (built by `npm run build`, gitignored). NC's
		// Util::addScript appends `js/<file>.js` to `apps/<appid>/`, so the
		// `../dist/` prefix walks back out and into the dist directory.
		//
		// No `afterAppId` (was 'files'): we want this to run as early as possible
		// so registerDavProperty() lands in the shared scope BEFORE the Files app
		// issues its first directory PROPFIND — otherwise the first folder view
		// races and our metadata-n8n_id prop misses that request.
		Util::addScript(Application::APP_ID, '../dist/' . Application::APP_ID . '-files');
	}
}
