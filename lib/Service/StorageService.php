<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCP\Constants;
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
	 */
	public function ensureFolder(Mapping $mapping): Folder {
		if ($mapping->useTeamFolder) {
			if (!$this->teamFolders->isAvailable()) {
				throw new \RuntimeException(
					'This mapping uses a Team Folder, but the Team Folders (groupfolders) app is not enabled.',
				);
			}
			$this->teamFolders->ensure($mapping->teamFolder, $mapping->ncGroups, $mapping->mode);
			return $this->teamFolders->getWritableFolder($mapping->teamFolder);
		}

		// Admin-owned backend.
		$uid = $this->teamFolders->resolveActorUid();
		$home = $this->rootFolder->getUserFolder($uid);
		$folder = $this->mkdirP($home, $mapping->teamFolder);
		$this->syncGroupShares($folder, $uid, $mapping);
		return $folder;
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
	 * Ensure the admin-owned folder is shared with each of the mapping's groups
	 * at the right permission level. Idempotent: creates missing group shares,
	 * fixes permissions on existing ones. Does NOT remove shares to groups no
	 * longer listed (a removed group's share is left for the admin to clean up,
	 * so we never clobber a manual share).
	 */
	private function syncGroupShares(Folder $folder, string $ownerUid, Mapping $mapping): void {
		$perms = ($mapping->mode === Mapping::MODE_SYNC)
			? (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE)
			: Constants::PERMISSION_READ;

		$existing = [];
		foreach ($this->shareManager->getSharesBy($ownerUid, IShare::TYPE_GROUP, $folder, false, -1, 0) as $share) {
			$existing[$share->getSharedWith()] = $share;
		}

		foreach ($mapping->ncGroups as $gid) {
			if ($gid === '') {
				continue;
			}
			if (isset($existing[$gid])) {
				$share = $existing[$gid];
				if ($share->getPermissions() !== $perms) {
					$share->setPermissions($perms);
					try {
						$this->shareManager->updateShare($share);
					} catch (\Throwable $e) {
						$this->logger->warning('n8n_sync: failed to update group share', ['exception' => $e, 'group' => $gid]);
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
				$share->setPermissions($perms);
				$this->shareManager->createShare($share);
			} catch (\Throwable $e) {
				// Most likely the group doesn't exist (admin-managed/LDAP). Log + continue.
				$this->logger->warning('n8n_sync: failed to share with group (does it exist?)', [
					'exception' => $e,
					'group' => $gid,
				]);
			}
		}
	}
}
