<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCA\N8nSync\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Resolves a mapping's writable folder, routing to the per-mapping storage
 * backend (plan §14.1):
 *
 *  - **Team Folder (use_team_folder = true):** delegate to {@see TeamFolderService}
 *    — ownerless groupfolders mount shared with the mapping's groups.
 *  - **Admin-owned (use_team_folder = false):** a folder in the admin user's home
 *    (the actor), shared to the mapping's groups via `OCP\Share\IManager` group
 *    shares. The owner is always the admin and is never switched (no migration);
 *    no groupfolders dependency.
 *
 * Both paths: never create groups (the content groups are admin-managed); files
 * carry the same metadata + tags written by {@see SyncService}.
 */
final class StorageService {
	/**
	 * Permissions a content group gets on an admin-owned mapped folder.
	 *
	 * NO LONGER VARIES BY MODE. A `link` mapping used to grant READ only, which
	 * expressed nothing useful: it stopped no write to n8n — the listeners and the
	 * absence of a content push do that — and only stopped the user from using
	 * their own files. Aliased from {@see TeamFolderService} so both backends
	 * grant the same surface by construction.
	 */
	private const CONTENT_PERMISSIONS = TeamFolderService::CONTENT_PERMISSIONS;

	public function __construct(
		private TeamFolderService $teamFolders,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private LoggerInterface $logger,
	) {
	}

	/** True if the mapping's chosen backend is usable right now. */
	public function isAvailable(Mapping $mapping): bool {
		return $mapping->useTeamFolder ? $this->teamFolders->isAvailable() : true;
	}

	/**
	 * Ensure the mapping's target folder exists + is shared, and return a node
	 * writable by the sync actor.
	 *
	 * PASS `$groups` ONLY WHEN SOMEONE EXPLICITLY SAID what the sharing should be;
	 * it is then applied exactly, pruning groups not listed. Leave it null — every
	 * sync does — and the folder's existing sharing is left alone, because the
	 * groups belong to the folder now and a sync that re-asserted them would undo
	 * an admin's edit on the next run.
	 *
	 * @param array<array-key, mixed>|string|null $groups
	 */
	public function ensureFolder(Mapping $mapping, array|string|null $groups = null): Folder {
		$wanted = $groups === null ? null : self::normaliseGroups($groups);

		if ($mapping->useTeamFolder) {
			if (!$this->teamFolders->isAvailable()) {
				throw new \RuntimeException(
					'This mapping uses a Team Folder, but the Team Folders (groupfolders) app is not enabled.',
				);
			}
			$this->teamFolders->ensure($mapping->teamFolder, $wanted);
			return $this->teamFolders->getWritableFolder($mapping->teamFolder);
		}

		// Admin-owned backend.
		$uid = $this->teamFolders->resolveActorUid();
		$home = $this->rootFolder->getUserFolder($uid);
		$folder = $this->mkdirP($home, $mapping->teamFolder);
		if ($wanted !== null) {
			$this->syncGroupShares($folder, $uid, $wanted);
		}
		return $folder;
	}

	/**
	 * The groups the mapping's folder is currently shared with — read from the
	 * folder, which is the only record there is.
	 *
	 * Answers `[]` for a mapping whose folder does not exist rather than throwing:
	 * every caller is rendering a list, and "no folder" is not more informative to
	 * an admin than "no groups" when the row beside it already shows the folder.
	 *
	 * @return list<string>
	 */
	public function groupsOf(Mapping $mapping): array {
		try {
			if ($mapping->useTeamFolder) {
				return $this->teamFolders->isAvailable()
					? $this->teamFolders->contentGroups($mapping->teamFolder)
					: [];
			}

			$folder = $this->findFolder($mapping);

			return $folder === null
				? []
				: $this->sharedGroups($folder, $this->teamFolders->resolveActorUid());
		} catch (\Throwable $e) {
			$this->logger->warning('n8n_sync: could not read the mapped folder\'s groups', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * Group ids: non-empty trimmed strings, de-duplicated, re-indexed. Tolerates a
	 * comma-separated string from a form field, or the untyped array a request
	 * hands a controller.
	 *
	 * Lives here, not on {@see Mapping}, because this is where groups live now —
	 * and it stays a SINGLE definition so `occ`, the panel and the sync cannot
	 * disagree about what a group list is.
	 *
	 * @return list<string>
	 */
	public static function normaliseGroups(mixed $value): array {
		if (is_string($value)) {
			$value = $value === '' ? [] : explode(',', $value);
		}

		if (!is_array($value)) {
			return [];
		}

		$out = [];
		foreach ($value as $g) {
			$g = trim((string)$g);
			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}

		return $out;
	}

	/**
	 * Return the existing target folder for the mapping, or null if it doesn't
	 * exist (used by purge — never creates anything).
	 */
	public function findFolder(Mapping $mapping): ?Folder {
		if ($mapping->useTeamFolder) {
			try {
				return $this->teamFolders->getWritableFolder($mapping->teamFolder);
			} catch (\Throwable) {
				return null;
			}
		}
		$uid = $this->teamFolders->resolveActorUid();
		$home = $this->rootFolder->getUserFolder($uid);
		$path = trim($mapping->teamFolder, '/');
		if ($path === '' || !$home->nodeExists($path)) {
			return null;
		}
		$node = $home->get($path);
		return $node instanceof Folder ? $node : null;
	}

	/** `mkdir -p` under $base; throws if a segment exists as a non-folder. */
	private function mkdirP(Folder $base, string $path): Folder {
		$path = trim($path, '/');
		if ($path === '') {
			return $base;
		}
		$current = $base;
		foreach (explode('/', $path) as $segment) {
			if ($segment === '') {
				continue;
			}
			if ($current->nodeExists($segment)) {
				$child = $current->get($segment);
				if (!$child instanceof Folder) {
					throw new \RuntimeException('path segment is not a folder: ' . $segment);
				}
				$current = $child;
				continue;
			}
			$current = $current->newFolder($segment);
		}
		return $current;
	}

	/**
	 * Share the admin-owned folder with EXACTLY $wanted: create what is missing,
	 * fix permissions on what is there, and delete the shares for groups that are
	 * not.
	 *
	 * ## IT PRUNES NOW, AND THAT IS THE POINT
	 *
	 * It used to leave a dropped group's share in place, "so we never clobber a
	 * manual share". That was defensible only while this ran on every sync from a
	 * stored list: pruning would have meant a sync silently revoking access an
	 * admin granted by hand. But it also meant the groups could only ever be added
	 * to — the one editable thing about a mapping was write-only.
	 *
	 * Now that the sync passes no groups at all, this runs only when someone
	 * explicitly said what the sharing should be, and "shared with these" has to
	 * mean "and not the others" or the list could never be narrowed. A hand-made
	 * share is still safe: nothing removes it unless an admin submits a set that
	 * omits it.
	 *
	 * ## A LIST, NOT A MAP KEYED ON THE GROUP ID
	 *
	 * The existing shares used to be indexed by group id, which PHP quietly casts
	 * to an INT for a numeric group name — and `in_array($gid, $wanted, true)` then
	 * compares `2024` against `'2024'` and says no. A group called "2024" would be
	 * pruned on every save and re-created immediately after, forever. Keeping the
	 * shares as a list and asking each one for its own id removes the coercion.
	 *
	 * @param list<string> $wanted
	 */
	private function syncGroupShares(Folder $folder, string $ownerUid, array $wanted): void {
		foreach ($this->groupShares($folder, $ownerUid) as $share) {
			$gid = $share->getSharedWith();
			if (in_array($gid, $wanted, true)) {
				continue;
			}
			try {
				$this->shareManager->deleteShare($share);
			} catch (\Throwable $e) {
				$this->logger->warning('n8n_sync: failed to unshare from group', [
					'app' => Application::APP_ID,
					'group' => $gid,
					'exception' => $e,
				]);
			}
		}

		foreach ($wanted as $gid) {
			if ($gid === '') {
				continue;
			}

			$share = null;
			foreach ($existing as $candidate) {
				if ($candidate->getSharedWith() === $gid) {
					$share = $candidate;
					break;
				}
			}

			if ($share !== null) {
				if ($share->getPermissions() !== self::CONTENT_PERMISSIONS) {
					$share->setPermissions(self::CONTENT_PERMISSIONS);
					try {
						$this->shareManager->updateShare($share);
					} catch (\Throwable $e) {
						$this->logger->warning('n8n_sync: failed to update group share', [
							'app' => Application::APP_ID,
							'group' => $gid,
							'exception' => $e,
						]);
					}
				}
				continue;
			}

			try {
				$share = $this->shareManager->newShare();
				$share->setNode($folder);
				$share->setShareType(IShare::TYPE_GROUP);
				$share->setSharedWith($gid);
				$share->setSharedBy($ownerUid);
				$share->setPermissions(self::CONTENT_PERMISSIONS);
				$this->shareManager->createShare($share);
			} catch (\Throwable $e) {
				// Most likely the group does not exist (admin-managed / LDAP). Log
				// and carry on — a missing content group must not fail the sync.
				$this->logger->warning('n8n_sync: failed to share with group (does it exist?)', [
					'app' => Application::APP_ID,
					'group' => $gid,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * The groups an admin-owned folder is currently shared with.
	 *
	 * @return list<string>
	 */
	private function sharedGroups(Folder $folder, string $ownerUid): array {
		$out = [];
		foreach ($this->groupShares($folder, $ownerUid) as $share) {
			$gid = $share->getSharedWith();
			if ($gid !== '' && !in_array($gid, $out, true)) {
				$out[] = $gid;
			}
		}

		return $out;
	}

	/**
	 * The folder's group shares, as the share objects (each is asked for its own
	 * `getSharedWith()` — see {@see syncGroupShares} for why ids are never used
	 * as array keys here).
	 *
	 * @return list<IShare>
	 */
	private function groupShares(Folder $folder, string $ownerUid): array {
		$shares = [];
		foreach ($this->shareManager->getSharesBy($ownerUid, IShare::TYPE_GROUP, $folder, false, -1, 0) as $share) {
			$shares[] = $share;
		}
		return $shares;
	}
}
