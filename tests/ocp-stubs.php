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

			/**
			 * The next four live on `FileInfo` upstream, which the real `Node` extends;
			 * the stub has no FileInfo, so they are declared here — the same place in
			 * the hierarchy. `getSize` is widened to `int|float` like the real
			 * signature, because the filecache size of a very large file exceeds PHP's
			 * int range on 32-bit.
			 */
			public function getSize(bool $includeMounts = true): int|float;

			public function getMTime(): int;

			public function getCreationTime(): int;

			public function getStorage(): \OCP\Files\Storage\IStorage;

			/** Sets the modification time — `$mtime` null means "now". */
			public function touch($mtime = null): void;
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

			/**
			 * Substring search over this folder's mounts, backed by the filecache — how
			 * {@see \OCA\N8nSync\Migration\MigrateFileExtension} finds every workflow
			 * file a user can see, including ones in folders no mapping covers.
			 *
			 * Signature mirrors core verbatim (`lib/public/Files/Folder.php`), which
			 * declares `search($query)` with no types at all. Adding them here would let
			 * an implementation compile against a stricter contract than the server
			 * offers — the same rule as the `OCP\Migration` stubs below.
			 *
			 * @return list<Node>
			 */
			public function search($query);
		}
	}
	// The storage root — declared after Folder, which it extends. The extension
	// migration asks it for each user's home so it can search their files.
	if (!interface_exists(IRootFolder::class, false)) {
		interface IRootFolder extends Folder {
			public function getUserFolder(string $userId): Folder;
		}
	}
	if (!interface_exists(IMimeTypeLoader::class, false)) {
		interface IMimeTypeLoader {
			public function getId(string $mimetype): int;

			public function updateFilecache(string $ext, int $mimetypeId): int;
		}
	}
}

namespace OCP\Files\Storage {
	// The first of the two hops {@see OCA\N8nSync\Service\MirrorTimes} takes to set a
	// creation time — there is no OCP setter for it, so the public cache API is the
	// supported route (Node::getStorage → IStorage::getCache → ICache::update).
	if (!interface_exists(IStorage::class, false)) {
		interface IStorage {
			public function getCache(string $path = '', ?IStorage $storage = null): \OCP\Files\Cache\ICache;
		}
	}
}

namespace OCP\Files\Cache {
	if (!interface_exists(ICache::class, false)) {
		interface ICache {
			public function update($id, array $data);
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
			// AutoSyncSettings stores `schedule_enabled` bool-typed so the scheduled
			// job's primary getValueBool() read succeeds (see that class's docblock).
			public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool;
			public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool;
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
	// {@see \OCA\N8nSync\Migration\MigrateFileExtension} walks every seen user, because
	// a workflow file outside any mapping still has to be migrated. Signature mirrors
	// core (`lib/public/IUserManager.php`), which declares no return type.
	if (!interface_exists(IUserManager::class, false)) {
		interface IUserManager {
			public function callForSeenUsers(\Closure $callback);
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

namespace OCP\Settings {
	// AdminSettings implements IDeclarativeSettingsForm and reads
	// DeclarativeSettingsTypes constants; the AdminSettings test instantiates one to
	// assert its dynamic "is a key stored?" copy. Declaration-only — the constant
	// *values* are irrelevant to the assertions (they check id/sensitive/description/
	// placeholder), so any strings suffice.
	if (!interface_exists(IDeclarativeSettingsForm::class, false)) {
		interface IDeclarativeSettingsForm {
			public function getSchema(): array;
		}
	}
	// AutoSyncSettings implements this (core `@since 31.0.0`) so it owns its own
	// storage — the only way an ADMIN form can carry a CHECKBOX (see that class).
	if (!interface_exists(IDeclarativeSettingsFormWithHandlers::class, false)) {
		interface IDeclarativeSettingsFormWithHandlers extends IDeclarativeSettingsForm {
			public function getValue(string $fieldId, \OCP\IUser $user): mixed;

			public function setValue(string $fieldId, mixed $value, \OCP\IUser $user): void;
		}
	}
	if (!class_exists(DeclarativeSettingsTypes::class, false)) {
		// Mirror every DeclarativeSettingsTypes constant the app's forms reference
		// (grep `DeclarativeSettingsTypes::` in lib/) so instantiating any settings
		// form under test can't fatal on an undefined constant — e.g. AutoSyncSettings
		// uses RADIO.
		final class DeclarativeSettingsTypes {
			public const SECTION_TYPE_ADMIN = 'admin';
			public const STORAGE_TYPE_INTERNAL = 'internal';
			public const STORAGE_TYPE_EXTERNAL = 'external';
			public const TEXT = 'text';
			public const PASSWORD = 'password';
			public const URL = 'url';
			public const CHECKBOX = 'checkbox';
			public const RADIO = 'radio';
		}
	}
}

namespace OCP\SystemTag {
	// TagSyncService reconciles a workflow's Nextcloud content tags through the
	// system-tag manager + object mapper; both are mocked in TagSyncServiceTest, so
	// these are declaration-only, naming just the surface the service calls.
	if (!interface_exists(ISystemTag::class, false)) {
		interface ISystemTag {
			public function getId(): string;

			public function getName(): string;
		}
	}
	if (!interface_exists(ISystemTagManager::class, false)) {
		interface ISystemTagManager {
			/**
			 * @param array<int|string> $tagIds tag ids (string or numeric, per NC events)
			 * @return array<string, ISystemTag>
			 */
			public function getTagsByIds($tagIds): array;

			public function getTag(string $tagName, bool $userVisible, bool $userAssignable): ISystemTag;

			public function createTag(string $tagName, bool $userVisible, bool $userAssignable): ISystemTag;
		}
	}
	if (!interface_exists(ISystemTagObjectMapper::class, false)) {
		interface ISystemTagObjectMapper {
			/**
			 * @param list<string> $objIds
			 * @return array<string, list<string>>
			 */
			public function getTagIdsForObjects($objIds, string $objectType): array;

			public function assignTags(string $objId, string $objectType, $tagIds): void;

			public function unassignTags(string $objId, string $objectType, $tagIds): void;

			public function haveTag($objIds, string $objectType, string $tagId, bool $all = true): bool;
		}
	}
	if (!class_exists(TagNotFoundException::class, false)) {
		class TagNotFoundException extends \RuntimeException {
		}
	}
	if (!class_exists(TagAlreadyExistsException::class, false)) {
		class TagAlreadyExistsException extends \RuntimeException {
		}
	}
}

namespace OCP\Migration {
	// The repair-step surface. `nextcloud/ocp` does not ship OCP\Migration, and the
	// migrations are ordinary classes with ordinary logic — MigrateFileExtension walks a
	// user's files and renames them, which is the one thing in the extension cut worth
	// unit-testing rather than only running on a real upgrade.
	//
	// SIGNATURES ARE COPIED FROM CORE VERBATIM (`lib/public/Migration/IOutput.php`),
	// including the inconsistency: `debug()` is typed `string ... : void` and the other
	// five are untyped. That is genuinely what core declares — do not "tidy" either half.
	// Tightening a signature here would let an implementation compile against a stricter
	// contract than the server offers, and loosening `debug()` would do the same in
	// reverse; the suite would be the last place to notice either.
	if (!interface_exists(IOutput::class, false)) {
		interface IOutput {
			public function debug(string $message): void;

			public function info($message);

			public function warning($message);

			public function startProgress($max = 0);

			public function advance($step = 1, $description = '');

			public function finishProgress();
		}
	}
	if (!interface_exists(IRepairStep::class, false)) {
		interface IRepairStep {
			public function getName();

			public function run(IOutput $output);
		}
	}
}
