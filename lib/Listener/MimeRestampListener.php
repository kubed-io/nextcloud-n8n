<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Listener;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\IMimeTypeLoader;
use Psr\Log\LoggerInterface;

/**
 * Re-stamps the `application/n8n+json` mimetype on every `*.n8n.json` filecache
 * row after a rename.
 *
 * The bug this fixes: NC's rename pipeline runs the NEW filename through
 * `\OC\Files\Type\Detection::detectPath()`, which only inspects the LAST
 * extension segment (`json`). For our compound extension `.n8n.json` that
 * resolves to `application/json` and the filecache row's `mimetype` is reset,
 * which in turn drops the custom icon. The user reproduced this on rename
 * `New Workflow.n8n.json` → `FlowBurger.n8n.json` and saw the icon vanish.
 *
 * `NodeWrittenListener` already re-stamps after a write (it's how MyBaddieFlow
 * eventually got its icon back — the next cron tick rewrote the JSON), but a
 * pure rename never triggers `NodeWrittenEvent`. This listener closes that gap.
 *
 * The {@see IMimeTypeLoader::updateFilecache} call is one global UPDATE keyed
 * on the extension `n8n.json`; it's idempotent, cheap, and catches the renamed
 * file along with any other rows that may have drifted. There's no need to
 * scope to the renamed node — and doing so would require lookups we'd rather
 * avoid in a hot event path.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class MimeRestampListener implements IEventListener {
	public function __construct(
		private IMimeTypeLoader $mimeLoader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeRenamedEvent) {
			return;
		}
		$target = $event->getTarget();
		// Bail unless the post-rename name is one of ours — pre-rename name is
		// irrelevant because the filecache row is already keyed by the new
		// name when this event fires.
		if (!str_ends_with($target->getName(), FilenameCodec::EXT)) {
			return;
		}
		try {
			$this->mimeLoader->updateFilecache(
				ltrim(FilenameCodec::EXT, '.'),
				$this->mimeLoader->getId('application/n8n+json'),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: mimetype re-stamp on rename failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
