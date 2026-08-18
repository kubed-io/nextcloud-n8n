<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;

/**
 * All Team Folder (groupfolders) interaction lives here, so the rest of the
 * plugin never touches groupfolders internals directly.
 *
 * Design (plan §12.4 / §13 spec):
 *  - **No owner, and we never create groups.** Team Folders are ownerless;
 *    content groups are whatever the admin already manages (often LDAP-mapped) —
 *    creating groups is out of scope. To *write* server-side we must act as a
 *    member of an assigned group, so we lean on the **built-in `admin` group**
 *    (always present, contains the sync actor, never created by us) and assign
 *    it to each managed folder with full rights. groupfolders 21.x has no
 *    per-user applicable, so a group is required; `admin` is the safe one.
 *  - **groupfolders has no stable PHP API**, but `FolderManager` is its public
 *    service and the cleanest surface; resolved lazily so a disabled app doesn't
 *    break DI. Name→id lookup hits the `group_folders` table directly (stable).
 *
 * Side effect (documented, acceptable): because the folder is shared to the
 * `admin` group, admins see managed Team Folders in their own Drive. Fine for
 * homelab/single-admin; revisit if per-user applicable lands upstream.
 */
final class TeamFolderService {
	/** Built-in group used to grant the write actor access. We never create it. */
	public const ADMIN_GROUP = 'admin';

	/** FQCN resolved lazily so a disabled groupfolders app doesn't break DI. */
	private const FOLDER_MANAGER = 'OCA\\GroupFolders\\Folder\\FolderManager';

	public function __construct(
		private ContainerInterface $container,
		private IDBConnection $db,
		private IGroupManager $groupManager,
		private IRootFolder $rootFolder,
		private IAppConfig $config,
	) {
	}

	public function isAvailable(): bool {
		return $this->container->has(self::FOLDER_MANAGER);
	}

	/**
	 * Content-group permissions.
	 *
	 * NO LONGER VARIES BY MODE, and that is a deliberate correction. A `link`
	 * mapping used to grant READ only, on the reasoning that content never flows
	 * back to n8n — but a permission bit was the wrong place to express that. It
	 * stopped no write to n8n (the listeners and the absence of a content push do
	 * that); it only stopped the user from using their own files: no subfolder of
	 * their own, no drag between folders, no rename.
	 *
	 * The one definition — {@see StorageService} aliases it so both backends
	 * grant the same surface by construction rather than by a comment asking
	 * them to. Identical to both sibling apps.
	 */
	public const CONTENT_PERMISSIONS = Constants::PERMISSION_READ
		| Constants::PERMISSION_UPDATE
		| Constants::PERMISSION_CREATE
		| Constants::PERMISSION_DELETE;

	/**
	 * Ensure a Team Folder named $mountPoint exists and is writable by the actor,
	 * and — only when asked — set exactly which content groups it is shared with.
	 * Returns the groupfolders folder id.
	 *
	 * PASS `null` TO LEAVE THE SHARING ALONE. Every sync does: the groups belong to
	 * the folder now, so a sync that re-asserted them would undo an admin's edit on
	 * the next run. A non-null list is an explicit instruction and is applied
	 * exactly, pruning groups not named.
	 *
	 * @param list<string>|null $contentGroups null = leave sharing untouched
	 */
	public function ensure(string $mountPoint, ?array $contentGroups): int {
		$fm = $this->container->get(self::FOLDER_MANAGER);

		$folderId = $this->findByMountPoint($mountPoint);
		if ($folderId === null) {
			$folderId = $fm->createFolder($mountPoint);
		}

		// LEAVE SHARING ALONE MEANS LEAVE THE ADMIN ASSIGNMENT ALONE TOO.
		//
		// The actor group must exist for the app to write at all, so it is added
		// when absent — but never RE-STAMPED. Re-asserting PERMISSION_ALL would
		// overwrite the case where `admin` is a deliberate content group at
		// CONTENT_PERMISSIONS, and contentGroups() would then hide it as plumbing:
		// a sync would silently drop a group the admin had chosen.
		if ($contentGroups === null) {
			if (!$this->groupIsApplied($folderId, self::ADMIN_GROUP)) {
				$this->assignGroup($fm, $folderId, self::ADMIN_GROUP, Constants::PERMISSION_ALL);
			}

			return $folderId;
		}

		foreach ($contentGroups as $gid) {
			if ($gid === '') {
				continue;
			}
			$this->assignGroup($fm, $folderId, $gid, self::CONTENT_PERMISSIONS);
		}

		// The actor group, unless the admin asked for it as a content group — in
		// which case the loop above has already given it CONTENT_PERMISSIONS, which
		// is enough to write with, and stamping PERMISSION_ALL over it would make
		// contentGroups() read it back as plumbing rather than as a chosen group.
		if (!in_array(self::ADMIN_GROUP, $contentGroups, true)) {
			$this->assignGroup($fm, $folderId, self::ADMIN_GROUP, Constants::PERMISSION_ALL);
		}

		$keep = array_merge([self::ADMIN_GROUP], $contentGroups);
		foreach ($this->appliedGroups($folderId) as $row) {
			if (!in_array($row['gid'], $keep, true)) {
				$fm->removeApplicableGroup($folderId, $row['gid']);
			}
		}

		return $folderId;
	}

	/**
	 * The content groups a Team Folder is shared with — what the admin chose, with
	 * the app's own plumbing filtered out.
	 *
	 * The actor group is only plumbing when it carries PERMISSION_ALL, which is
	 * how {@see ensure()} stamps it. An `admin` group the admin deliberately added
	 * as a content group carries CONTENT_PERMISSIONS instead, and is reported.
	 * That bitmask is the ONLY thing separating the two, which is why nothing may
	 * re-stamp it.
	 *
	 * @return list<string>
	 */
	public function contentGroups(string $mountPoint): array {
		$folderId = $this->findByMountPoint($mountPoint);
		if ($folderId === null) {
			return [];
		}

		$out = [];
		foreach ($this->appliedGroups($folderId) as $row) {
			if ($row['gid'] === self::ADMIN_GROUP && $row['permissions'] === Constants::PERMISSION_ALL) {
				continue;
			}
			$out[] = $row['gid'];
		}

		return $out;
	}

	/**
	 * The writable {@see Folder} node for a managed Team Folder, via the actor's
	 * Files view (the only context the mount exists in). Re-inits the actor FS so
	 * a folder/assignment created earlier in this same request is picked up.
	 */
	public function getWritableFolder(string $mountPoint): Folder {
		$actor = $this->resolveActorUid();
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($actor);
		$userFolder = $this->rootFolder->getUserFolder($actor);
		if (!$userFolder->nodeExists($mountPoint)) {
			throw new \RuntimeException(
				"Team Folder '$mountPoint' is not mounted for actor '$actor'. "
				. 'Check the actor is in the "' . self::ADMIN_GROUP . '" group.',
			);
		}
		$node = $userFolder->get($mountPoint);
		if (!$node instanceof Folder) {
			throw new \RuntimeException("'$mountPoint' exists but is not a folder for actor '$actor'.");
		}
		return $node;
	}

	/**
	 * uid we act as when writing. Must be a local user (LDAP doesn't resolve in
	 * bare CLI/job context). Default: first member of the built-in `admin` group;
	 * override with AppConfig `sync_actor` if ever needed.
	 */
	public function resolveActorUid(): string {
		$configured = $this->config->getValueString(Application::APP_ID, 'sync_actor', '');
		if ($configured !== '') {
			return $configured;
		}
		$admin = $this->groupManager->get(self::ADMIN_GROUP);
		if ($admin !== null) {
			foreach ($admin->getUsers() as $user) {
				return $user->getUID();
			}
		}
		throw new \RuntimeException('No sync actor available: the built-in admin group has no members.');
	}

	/** Assign $groupId (idempotent) and set its permission bitmask. */
	private function assignGroup(object $fm, int $folderId, string $groupId, int $permissions): void {
		if (!$this->groupIsApplied($folderId, $groupId)) {
			$fm->addApplicableGroup($folderId, $groupId);
		}
		$fm->setGroupPermissions($folderId, $groupId, $permissions);
	}

	/**
	 * Group ids currently applied to the folder, with their permission bitmask
	 * (excludes Circles, which store an empty group_id).
	 *
	 * THE PERMISSIONS ARE LOAD-BEARING, not diagnostic: they are the only thing
	 * that tells the app's own actor assignment apart from an `admin` group the
	 * admin chose as a content group. See {@see contentGroups()}.
	 *
	 * ## A LIST OF ROWS, NOT A MAP KEYED ON THE GROUP ID
	 *
	 * The obvious shape is `array<string, int>` — gid => permissions — and it is
	 * WRONG for exactly the reason it was wrong in {@see StorageService}: PHP casts
	 * a numeric-string array KEY to an int, so a group called "2024" comes back as
	 * `int(2024)`. `in_array($gid, $keep, true)` then fails against the string, so
	 * the prune would remove and re-add that group on every save forever — and
	 * `contentGroups()` would return an int inside a `list<string>`.
	 *
	 * This shape has no keys to coerce. Caught in review, one file away from the
	 * identical bug being fixed in the same change: the map really is the tempting
	 * shape, which is why this note is here rather than in a commit message.
	 *
	 * @return list<array{gid: string, permissions: int}>
	 */
	private function appliedGroups(int $folderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('group_id', 'permissions')
			->from('group_folders_groups')
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)))
			->andWhere($qb->expr()->neq('group_id', $qb->createNamedParameter('')));
		$res = $qb->executeQuery();
		$out = [];
		foreach ($res->fetchAll() as $row) {
			$out[] = ['gid' => (string)$row['group_id'], 'permissions' => (int)$row['permissions']];
		}
		$res->closeCursor();
		return $out;
	}

	private function groupIsApplied(int $folderId, string $groupId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_id')
			->from('group_folders_groups')
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$found = $res->fetchOne() !== false;
		$res->closeCursor();
		return $found;
	}

	private function findByMountPoint(string $mountPoint): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_id')
			->from('group_folders')
			->where($qb->expr()->eq('mount_point', $qb->createNamedParameter($mountPoint)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$id = $res->fetchOne();
		$res->closeCursor();
		return $id === false ? null : (int)$id;
	}
}
