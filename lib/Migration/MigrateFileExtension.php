<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Migration;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\FilenameCodec;
use OCA\N8nSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames the workflow files this app already wrote from the retired compound
 * extension to the single segment: `Fleet Health.n8n.json` → `Fleet Health.n8n`.
 *
 * Runs once on upgrade. Without it, an instance that was synced before the change wakes
 * up with every workflow file invisible to the app — {@see FilenameCodec::isWorkflowName}
 * is the single predicate for "is this one of ours?", and after the cut a `.n8n.json`
 * file is not. The files would keep their metadata and their workflows, and nothing
 * would ever act on them again.
 *
 * ## IT ALSO SWEEPS UP THE NAMES THE OLD EXTENSION MADE US LIVE WITH
 *
 * Nextcloud's collision counter goes before the LAST extension, so under `.n8n.json`
 * a copy landing beside its source was born `Fleet Health.n8n (1).json` — the shape
 * the whole `canonicalise()` layer existed to read. Those names are on disk on any
 * instance where somebody copied a workflow, so this converts them too, to the name the
 * new extension would have produced in the first place: `Fleet Health (1).n8n`.
 *
 * ## SCOPE: EVERY FILE, NOT JUST THE ONES IN A MAPPING
 *
 * This walked the mapped folders first, on the reasoning that they are the app's
 * footprint. **That was wrong, and the live instance said so:** of 20 workflow files,
 * six sat in a plain home folder outside every mapping and would have been left behind.
 *
 * An unmapped `.n8n.json` is not litter, it is a **supported state**. Moving a file out
 * of a mapping ejects it — it keeps its body and becomes a plain document — and moving
 * it back in re-registers it. That round trip is `move.feature`'s subject. A file left
 * on the old extension could never complete it: the move-in listener asks
 * `isWorkflowName()` first, so the file would land in a mapped folder and simply be
 * ignored, with no error and nothing to see. The migration has to reach every file the
 * app might ever be asked about, not every file it currently owns.
 *
 * So it walks USERS, not mappings: one indexed `Folder::search()` per seen user, which
 * covers their home and every Team Folder mounted into it, deduplicated by file id
 * because a Team Folder is mounted once per member. Measured on the live instance: 20
 * distinct files found across 7 users and 2 Team Folders.
 *
 * Guarded by {@see SyncGuard} so the renames don't echo: a `NodeRenamedEvent` would
 * otherwise enqueue a name reconcile per file and push each one to n8n, turning an
 * upgrade into a write storm against the user's n8n. The names are equivalent
 * either way — the same stem, the same counter — so there is nothing for a reconcile
 * to do.
 *
 * ## THIS ONE STAYS, UNLIKE THE GRAFANA SIBLING'S
 *
 * `grafana_sync` deleted its copy of this class the moment it had run: that app is not
 * published, so there was exactly one instance to migrate and it was already done. This
 * app IS on the Nextcloud App Store. Its population is not ours to count, and an admin
 * upgrading from an older release a year from now still needs their files renamed. It
 * stays for a version or two, and comes out when the release it migrates from is far
 * enough behind to be unsupported.
 *
 * Idempotent and fail-soft: a file already carrying the new extension is skipped, and a
 * rename that cannot happen is logged and stepped over rather than failing the upgrade.
 */
final class MigrateFileExtension implements IRepairStep {
	/** The extension this app used to write. */
	private const LEGACY_EXT = '.n8n.json';

	/**
	 * Nextcloud's spelling of a collision under the legacy extension: the counter landed
	 * before `.json`, because to Nextcloud the file was a `.json` called `<stem>.n8n`.
	 */
	private const LEGACY_COUNTED_RE = '/^(?<stem>.+)\.n8n \((?<n>\d+)\)\.json$/';

	/**
	 * What we hand `Folder::search()`. Deliberately the BARE extension and not
	 * {@see LEGACY_EXT}: `search()` matches a substring, and the counted legacy form
	 * `Fleet Health.n8n (1).json` does not contain the string `.n8n.json` — searching for
	 * the full legacy extension would silently miss every copied file, which is the half
	 * most in need of migrating. {@see newName()} is the real filter.
	 */
	private const SEARCH_TERM = '.n8n';

	/** Runaway guard when stepping a counter to find a free name; see {@see freeName()}. */
	private const MAX_COLLISION = 1000;

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Migrate .n8n.json workflow files to .n8n';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$renamed = 0;
		/**
		 * File ids that are SETTLED — renamed, or confirmed to need nothing. A Team Folder
		 * is mounted once per member, so without this the same file is offered by every
		 * member's search and would be renamed again on the second pass, moving an
		 * already-correct `Fleet Health.n8n` to `Fleet Health.n8n (1).n8n`.
		 *
		 * @var array<int,true>
		 */
		$done = [];
		/**
		 * File ids a rename has FAILED on so far, which is not the same thing as settled.
		 * A Team Folder can be mounted read-only for one group and writable for another,
		 * and `callForSeenUsers` yields in no particular order — so a failure under the
		 * first member's mount must leave the file open for the next member to try. An id
		 * here is still retried; it is only counted at the end if nobody ever managed it.
		 *
		 * @var array<int,true>
		 */
		$failed = [];

		$this->guard->run(function () use (&$renamed, &$failed, &$done): void {
			$this->userManager->callForSeenUsers(function (IUser $user) use (&$renamed, &$failed, &$done): void {
				$this->migrateForUser($user->getUID(), $renamed, $failed, $done);
			});
		});

		if ($renamed > 0 || $failed !== []) {
			$output->info(sprintf(
				'n8n_sync: renamed %d workflow file(s) to %s (%d could not be renamed)',
				$renamed,
				FilenameCodec::EXT,
				count($failed),
			));
		}
	}

	/**
	 * Rename every legacy-named workflow file this user can see.
	 *
	 * `search()` is one filecache query scoped to the user's mounts, so this costs a
	 * query per user rather than a directory walk per folder — and it reaches files in
	 * folders this app has never heard of, which is the whole point.
	 *
	 * @param array<int,true> $failed
	 * @param array<int,true> $done
	 */
	private function migrateForUser(string $uid, int &$renamed, array &$failed, array &$done): void {
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$hits = $userFolder->search(self::SEARCH_TERM);
		} catch (\Throwable $e) {
			// A user whose home cannot be set up (never logged in, broken mount) is not a
			// reason to abandon everyone else's files.
			$this->logger->warning('n8n_sync: could not search a user\'s files for the extension migration', [
				'app' => Application::APP_ID,
				'user' => $uid,
				'exception' => $e,
			]);
			return;
		}

		foreach ($hits as $node) {
			if (!$node instanceof File || isset($done[$node->getId()])) {
				continue;
			}

			$wanted = self::newName($node->getName());
			if ($wanted === null) {
				// Already migrated, or never one of ours. Settled either way — and this is
				// how a file another member already renamed is recognised on this pass.
				$done[$node->getId()] = true;
				continue;
			}
			try {
				$parent = $node->getParent();
				$target = $this->freeName($parent, $wanted);
				$node->move($parent->getPath() . '/' . $target);
				$renamed++;
				$done[$node->getId()] = true;
				unset($failed[$node->getId()]);
			} catch (\Throwable $e) {
				// NOT marked done: the next member's mount may be the writable one. The id
				// is remembered so the final count is distinct FILES rather than attempts.
				$failed[$node->getId()] = true;
				$this->logger->warning('n8n_sync: could not migrate a workflow file to the new extension', [
					'app' => Application::APP_ID,
					'user' => $uid,
					'fileId' => $node->getId(),
					'from' => $node->getName(),
					'to' => $wanted,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * The new-extension name for a legacy filename, or null when there isn't one.
	 *
	 * The counted form is checked first: `Fleet Health.n8n (1).json` also ends in
	 * `.json`, and testing the plain suffix first would leave it unmatched and untouched,
	 * which is the one shape most in need of fixing.
	 */
	private static function newName(string $old): ?string {
		if (preg_match(self::LEGACY_COUNTED_RE, $old, $m) === 1) {
			return $m['stem'] . ' (' . $m['n'] . ')' . FilenameCodec::EXT;
		}
		if (strlen($old) > strlen(self::LEGACY_EXT) && str_ends_with($old, self::LEGACY_EXT)) {
			return substr($old, 0, -strlen(self::LEGACY_EXT)) . FilenameCodec::EXT;
		}
		return null;
	}

	/**
	 * $wanted if it is free in $folder, otherwise the same name wearing the next unused
	 * collision counter.
	 *
	 * Two legacy names CAN want one new name — `Fleet Health.n8n (1).json` and a
	 * hand-made `Fleet Health (1).n8n.json` both migrate to `Fleet Health (1).n8n` —
	 * and a migration that threw there would strand every remaining file it had not
	 * reached. Stepping the counter is what Nextcloud itself would do with the second one.
	 */
	private function freeName(Folder $folder, string $wanted): string {
		if (!$folder->nodeExists($wanted)) {
			return $wanted;
		}
		$stem = substr($wanted, 0, -strlen(FilenameCodec::EXT));
		for ($n = 1; $n <= self::MAX_COLLISION; $n++) {
			$candidate = $stem . ' (' . $n . ')' . FilenameCodec::EXT;
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException('no free name for ' . $wanted);
	}
}
