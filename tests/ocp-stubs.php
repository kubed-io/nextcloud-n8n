<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Minimal OCP stubs for the standalone unit suite.
 *
 * `nextcloud/ocp` ships its public API as bare source with **no autoload block**,
 * so nothing under `OCP\` resolves outside a real Nextcloud server tree (verified:
 * `interface_exists(OCP\IConfig::class) === false` after a clean composer install).
 * Pure-logic tests (FilenameCodec, Mapping) never touch OCP and so don't care; but
 * any app class that merely *references* an OCP symbol to load — e.g.
 * {@see OCA\N8nSync\AppInfo\Application} (extended only for its `APP_ID` constant in
 * log context) — needs the base symbol to exist for its class declaration.
 *
 * These are declaration-only shims, just enough to let those classes autoload. They
 * carry no behaviour; collaborators that need real behaviour are mocked in the test.
 */

namespace OCP\AppFramework {
	if (!class_exists(App::class, false)) {
		class App {
			public function __construct(string $appName, array $urlParams = []) {
			}
		}
	}
}

namespace OCP\AppFramework\Bootstrap {
	if (!interface_exists(IBootstrap::class, false)) {
		interface IBootstrap {
			public function register(IRegistrationContext $context): void;

			public function boot(IBootContext $context): void;
		}
	}
	if (!interface_exists(IRegistrationContext::class, false)) {
		interface IRegistrationContext {
		}
	}
	if (!interface_exists(IBootContext::class, false)) {
		interface IBootContext {
		}
	}
}

namespace OCP\Files {
	// `File`/`Folder` (and their parent `Node`) are mocked in motion/listener/sync
	// tests; PHPUnit needs the interfaces to exist to generate the double.
	// Declaration-only — the real server provides the full surface; here we name
	// just what the tests call.
	if (!interface_exists(Node::class, false)) {
		interface Node {
			public function getId(): int;

			public function getName(): string;

			public function getPath(): string;

			public function getParent(): Folder;

			public function delete(): void;

			public function move(string $targetPath): Node;
		}
	}
	if (!interface_exists(File::class, false)) {
		interface File extends Node {
			public function getContent(): string;

			public function putContent($data): void;
		}
	}
	if (!interface_exists(Folder::class, false)) {
		interface Folder extends Node {
			/** @return list<Node> */
			public function getDirectoryListing(): array;

			public function nodeExists(string $path): bool;

			public function newFile(string $path, $content = null): File;
		}
	}
	if (!interface_exists(IMimeTypeLoader::class, false)) {
		interface IMimeTypeLoader {
			public function getId(string $mimetype): int;

			public function updateFilecache(string $ext, int $mimetypeId): int;
		}
	}
}

namespace OCP\BackgroundJob {
	// Passed to SyncService for the async dispatch path; the sync-path tests never
	// enqueue, so declaration-only is enough to satisfy the type.
	if (!interface_exists(IJobList::class, false)) {
		interface IJobList {
			public function add($job, $argument = null): void;
		}
	}
}

namespace OCP {
	// SyncService/MappingService read + write config via get/setValueString; declaration-only.
	if (!interface_exists(IAppConfig::class, false)) {
		interface IAppConfig {
			public function getValueString(string $app, string $key, string $default = '', bool $lazy = false, bool $sensitive = false): string;
			public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;
		}
	}
	// LinkWriteGuardPlugin resolves the acting user for its notification; both are
	// mocked in the plugin test, declaration-only here.
	if (!interface_exists(IUser::class, false)) {
		interface IUser {
			public function getUID(): string;
		}
	}
	if (!interface_exists(IUserSession::class, false)) {
		interface IUserSession {
			public function getUser(): ?IUser;
		}
	}
}

namespace OCP\EventDispatcher {
	// Base event class other bundled-app events (e.g. SabrePluginAddEvent) extend;
	// PHP resolves the parent at declaration time, so the external-stubs file needs
	// this to exist first. Declaration-only.
	if (!class_exists(Event::class, false)) {
		class Event {
		}
	}
}
