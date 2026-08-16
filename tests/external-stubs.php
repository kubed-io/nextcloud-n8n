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
			/**
			 * The node tree. A real public property on Sabre's Server, and the only route
			 * from the PATH that `beforeUnbind` hands a plugin to the NODE being deleted —
			 * {@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin::beforeUnbind}. Declared here
			 * because neither Psalm nor the unit suite ships Sabre.
			 *
			 * Untyped with a docblock rather than `public Tree $tree`: a typed property
			 * with no constructor to set it is an uninitialised-property finding waiting
			 * to happen, and this stub is never instantiated — its whole job is to tell
			 * Psalm the property exists and what it holds.
			 *
			 * @var Tree
			 */
			public $tree;

			public function on(string $eventName, callable $callBack, int $priority = 100): bool {
				return true;
			}

			public function addPlugin(ServerPlugin $plugin): void {
			}

			/**
			 * Turn an absolute `Destination:` URL into a path inside this DAV root — the
			 * only way to learn where a COPY is going, since the header is a URL and
			 * everything else in a plugin speaks paths.
			 *
			 * Real signature throws `Sabre\DAV\Exception\Forbidden` for a destination
			 * outside the root, which {@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin::onCopy}
			 * treats as "not ours to judge".
			 */
			public function calculateUri(string $uri): string {
				return '';
			}
		}
	}
	if (!class_exists(Tree::class, false)) {
		class Tree {
			public function getNodeForPath(string $path): INode {
				throw new \RuntimeException('stub');
			}
		}
	}
	if (!class_exists(ServerPlugin::class, false)) {
		abstract class ServerPlugin {
			abstract public function initialize(Server $server): void;
		}
	}
}

/**
 * `sabre/http` is a separate package from `sabre/dav` and neither is shipped to Psalm or
 * to the unit suite, so the two interfaces a `method:*` handler is handed need declaring
 * here alongside the DAV ones. Only the members
 * {@see \OCA\N8nSync\DAV\LinkWriteGuardPlugin::onCopy} actually calls.
 */
namespace Sabre\HTTP {
	if (!interface_exists(RequestInterface::class, false)) {
		interface RequestInterface {
			/** The request path, relative to the DAV root — the COPY's SOURCE. */
			public function getPath(): string;

			/** A header's value, or null when the request does not carry it. */
			public function getHeader(string $name): ?string;
		}
	}
	if (!interface_exists(ResponseInterface::class, false)) {
		/** Declared only because Sabre passes one; the copy guard never touches it. */
		interface ResponseInterface {
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
