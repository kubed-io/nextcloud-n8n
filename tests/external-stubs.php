<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Declaration-only stubs for classes the app references from OTHER bundled apps
 * and from the Sabre/DAV library, neither of which is shipped in `nextcloud/ocp`.
 *
 * Two consumers, one file:
 *   - the unit bootstrap `require`s it so PHPUnit can generate doubles of
 *     {@see \OCA\DAV\Connector\Sabre\File} et al. for {@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin};
 *   - `psalm.xml` loads it via `<stubs>` so static analysis resolves the same
 *     symbols instead of reporting UndefinedClass.
 *
 * They carry no behaviour — just enough surface (signatures) for the type system
 * and the mock builder. The real classes live in a running Nextcloud + Sabre.
 */

namespace Sabre\DAV {
	if (!interface_exists(INode::class, false)) {
		interface INode {
			public function getName(): string;
		}
	}
	if (!class_exists(Server::class, false)) {
		class Server {
			public function on(string $eventName, callable $callBack, int $priority = 100): bool {
				return true;
			}

			public function addPlugin(ServerPlugin $plugin): void {
			}
		}
	}
	if (!class_exists(ServerPlugin::class, false)) {
		abstract class ServerPlugin {
			abstract public function initialize(Server $server): void;
		}
	}
}

namespace Sabre\DAV\Exception {
	if (!class_exists(Forbidden::class, false)) {
		class Forbidden extends \Exception {
		}
	}
}

namespace OCA\DAV\Connector\Sabre {
	if (!class_exists(File::class, false)) {
		class File implements \Sabre\DAV\INode {
			public function getName(): string {
				return '';
			}

			public function getId(): int {
				return 0;
			}
		}
	}
}

namespace OCA\DAV\Events {
	if (!class_exists(SabrePluginAddEvent::class, false)) {
		class SabrePluginAddEvent extends \OCP\EventDispatcher\Event {
			public function getServer(): \Sabre\DAV\Server {
				return new \Sabre\DAV\Server();
			}
		}
	}
}

namespace {
	/**
	 * Private core class, shipped in a real Nextcloud but in neither `nextcloud/ocp`
	 * nor Composer. {@see \OCA\N8nSync\Listener\TrashPurgeHook} calls it to learn whose
	 * trash is being expired when the retention job runs with no session.
	 *
	 * A DECLARATION rather than a `psalm.xml` suppression, on review: suppressing
	 * `UndefinedClass` for `OC_User` would also hide every FUTURE unintended reach into
	 * private core APIs under the same name. Stubbing keeps Psalm strict and narrows the
	 * exemption to the one method actually used.
	 */
	if (!class_exists(OC_User::class, false)) {
		class OC_User {
			/** @return string|false the uid the filesystem is set up for, or false. */
			public static function getUser() {
				return false;
			}
		}
	}
}
