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
	// `File` (and its parent `Node`) are mocked in motion/listener tests; PHPUnit
	// needs the interfaces to exist to generate the double. Declaration-only — the
	// real server provides the full surface; here we name just what the tests call.
	if (!interface_exists(Node::class, false)) {
		interface Node {
			public function getId(): int;

			public function getName(): string;

			public function getPath(): string;
		}
	}
	if (!interface_exists(File::class, false)) {
		interface File extends Node {
			public function getContent(): string;
		}
	}
}
