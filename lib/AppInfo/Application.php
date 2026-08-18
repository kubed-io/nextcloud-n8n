<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\N8nSync\BackgroundJob\ScheduledPullJob;
use OCA\N8nSync\Listener\BodyTagListener;
use OCA\N8nSync\Listener\ContentTagListener;
use OCA\N8nSync\Listener\CopyGuardListener;
use OCA\N8nSync\Listener\CopyListener;
use OCA\N8nSync\Listener\CreateInN8nListener;
use OCA\N8nSync\Listener\DeleteToN8nListener;
use OCA\N8nSync\Listener\LoadFilesScriptListener;
use OCA\N8nSync\Listener\MotionListener;
use OCA\N8nSync\Listener\MoveGuardListener;
use OCA\N8nSync\Listener\MoveIdentityListener;
use OCA\N8nSync\Listener\NameSyncListener;
use OCA\N8nSync\Listener\NodeWrittenListener;
use OCA\N8nSync\Listener\RegisterDavPluginsListener;
use OCA\N8nSync\Listener\RestoreFromTrashListener;
use OCA\N8nSync\Listener\TeamFolderPurgeListener;
use OCA\N8nSync\Listener\TrashPurgeHook;
use OCA\N8nSync\Listener\TrashRestoreHook;
use OCA\N8nSync\Notification\Notifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCA\N8nSync\Settings\AutoSyncSettings;
use OCA\N8nSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\BeforeNodeCopiedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;

/**
 * App bootstrap. Phase 0 is an intentionally empty skeleton: it must install,
 * enable, and disable cleanly before any behaviour is wired in.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'n8n_sync';

	/** Guards against connectHook stacking the restore handler on a repeat boot(). */
	private static bool $restoreHookRegistered = false;

	/** Guards against connectHook stacking the purge handler on a repeat boot(). */
	private static bool $purgeHookRegistered = false;

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Declarative forms shown in the n8n_sync admin section.
		// AdminSection (the sidebar entry) and the classic ISettings panels
		// (MappingSettings, SyncSettings) are wired through info.xml's <settings>
		// block — the only IRegistrationContext settings hook is
		// registerDeclarativeSettings().
		//
		// Note: there is intentionally no "guard" panel. Ownership of an
		// n8n_sync file is determined by the file itself — the `.n8n`
		// extension and the `n8n_id` Files-Metadata stamp. Anything carrying
		// those markers inside a mapped folder is fair game for sync; anything
		// without them is left alone. No setting needed.
		//
		// Section layout (by priority): Instance — URL + API key (5) → Test
		// connection (15, classic panel via info.xml) → Sync Settings (33) →
		// Mappings/Manual sync (30+).
		//
		// It used to read "Instance URL (5) → REST API (10) → Test connection (15)
		// → Webhook (20) → …, API and Webhook are independent, composable writeback
		// channels". There is one channel now, so the URL and the key live in one
		// card, and the push fires on a save rather than on a schedule of its own.
		$context->registerDeclarativeSettings(InstanceSettings::class);
		$context->registerDeclarativeSettings(AutoSyncSettings::class);

		// Push wiring: push saved sync-mode files to n8n.
		$context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);

		// §14.2c link is read-only on disk: a link file is only a pointer to a workflow
		// in n8n, so overwriting its bytes (WebDAV PUT, desktop client, curl) is refused
		// *before* the write lands. RegisterDavPluginsListener attaches LinkWriteGuardPlugin,
		// which throws Sabre Forbidden (→ 403) from `beforeWriteContent`. This is the only
		// reliable choke point: core's BeforeNodeWrittenEvent is emitted from File::put()
		// *only on the non-part-file branch*, so a normal PUT (which uploads via a .part
		// file) slips past it. Our own pull / sync↔link re-mode writes use the View/Node
		// API, not Sabre, so they never reach the plugin.
		$context->registerEventListener(SabrePluginAddEvent::class, RegisterDavPluginsListener::class);

		// §14.2 a move must not lose the file's identity, and REGISTRATION ORDER IS THE
		// POINT: same-priority listeners run in the order they are registered, so this
		// pair brackets the move ahead of every other rename listener below. A move
		// across storages (two Team Folders, or a Team Folder and a home folder) is a
		// copy + unlink, and the unlink destroys the file's `files_metadata` row — so
		// the file arrives with no `n8n_id` and create-on-land would adopt a workflow
		// that already exists, minting a duplicate. This reads the stamp off the source
		// before the move and puts it back on the target after.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, MoveIdentityListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, MoveIdentityListener::class);

		// §17.2 create-on-land: a `.n8n` without `n8n_id` appearing in a
		// mapped folder (created via the New menu, saved by the Text editor,
		// uploaded by WebDAV, or moved in from elsewhere) becomes a real n8n
		// workflow + tag + stamp. NodeWrittenEvent covers create/save; NodeRenamedEvent
		// covers move-in (NC doesn't fire NodeWritten on a pure move). Re-entrancy
		// is handled by SyncGuard inside CreateService::stampFile().
		$context->registerEventListener(NodeWrittenEvent::class, CreateInN8nListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, CreateInN8nListener::class);

		// §14.2 motion guard: a managed workflow may rename / move within its
		// mapping; moving a *sync* file out to an unmapped folder is allowed (it
		// becomes `unmapped` + archived — see MotionListener); moving a *link* out,
		// or any file directly into a different mapping, is blocked.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, MoveGuardListener::class);

		// §14.2 motion consequence: after an allowed move, archive+unmap (sync
		// move-out) or unarchive/restore (unmapped move-in) the same workflow in
		// n8n. Untracked move-in (create-on-land) stays with CreateInN8nListener.
		$context->registerEventListener(NodeRenamedEvent::class, MotionListener::class);

		// §14.2 copy is the opposite of move: a copy is ALWAYS a brand-new instance.
		// NC fires NodeCopiedEvent (not NodeWrittenEvent) on a copy, so create-on-land
		// misses it; this listener strips the copy's inherited identity and registers
		// it as a fresh workflow if it landed in a mapping (see CopyService).
		$context->registerEventListener(NodeCopiedEvent::class, CopyListener::class);

		// …and the guard that runs BEFORE it. A link is a pointer, so copying one
		// duplicates nothing, and a link mapping is filled from its tag in n8n rather
		// than by hand. LinkWriteGuardPlugin refuses both over WebDAV with a 403 the
		// user can read; this is the same rule for every route that never touches Sabre.
		$context->registerEventListener(BeforeNodeCopiedEvent::class, CopyGuardListener::class);

		// Three-way name sync: keep filename stem ≡ JSON `name` ≡ n8n name for
		// two-way files. Rename → name into JSON (writeback pushes); edit name +
		// save → rename the file. Loop-safe by idempotency (see NameSyncListener).
		$context->registerEventListener(NodeWrittenEvent::class, NameSyncListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NameSyncListener::class);

		// §17.7 delete mirror: NC's right-click "Delete file" fires
		// BeforeNodeDeletedEvent **before** storage->unlink and supports
		// AbortedEventException, so a single listener cleanly covers
		//   - the move-to-trash leg  (path = `/<uid>/files/…`)  → archive or untag
		//   - the trash-purge leg    (path = `/<uid>/files_trashbin/files/…`) → DELETE
		// in n8n, aborting the NC delete if n8n rejects the call. Restore from
		// trash is a separate event with separate (non-aborting) semantics.
		$context->registerEventListener(BeforeNodeDeletedEvent::class, DeleteToN8nListener::class);
		$context->registerEventListener(NodeRestoredEvent::class, RestoreFromTrashListener::class);

		// …and the purge leg for every OTHER trash. The legacy `preDelete` hook wired in
		// boot() is emitted by `Files_Trashbin\Trashbin` and nowhere else, so emptying a
		// TEAM FOLDER's trash — which is the trash this app's mappings actually use —
		// reached n8n never. groupfolders' backend emits nothing; the one thing it cannot
		// skip is dropping the file's cache entry. See {@see TeamFolderPurgeListener} for
		// why that event, and for the three filters keeping it to actual purges.
		$context->registerEventListener(CacheEntryRemovedEvent::class, TeamFolderPurgeListener::class);

		// §5.6.2 reactive tag sync (surface 3): a CONTENT pill add/remove on a managed
		// sync file reconciles that tag to n8n on its own — the tag-side sibling of the
		// body writeback, taking the same inline-vs-queued decision from WritebackStrategy.
		// Loop-safe: its reconcile writes pills under SyncGuard, so the tag events it
		// re-fires bail here.
		$context->registerEventListener(TagAssignedEvent::class, ContentTagListener::class);
		$context->registerEventListener(TagUnassignedEvent::class, ContentTagListener::class);

		// §5.9 the THIRD tag direction: a hand-edit of the `tags` array inside a
		// .n8n reaches n8n and the pills. Its OWN listener rather than a branch in
		// NodeWrittenListener — an earlier attempt made the pill path and the body path
		// share one "read the NC side" step and broke the shipping pill path (§5.6.2.3).
		// They share the merge engine and nothing else. Cheap on an ordinary save: the
		// body's tags are compared to the pills and bail before touching n8n when they
		// agree, which is reliable only because a pill edit keeps the body in step.
		$context->registerEventListener(NodeWrittenEvent::class, BodyTagListener::class);

		// Files-app frontend: load the file-action bundle (icon + "Open in n8n"
		// default click) on every page that fires LoadAdditionalScriptsEvent.
		// Phase 5 surface — the only client-side code outside the admin pages.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);

		// Renders writeback-failure notifications (bell + toast) so a user whose
		// save n8n rejected sees the reason and can fix the JSON.
		$context->registerNotifierService(Notifier::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// getAppContainer() resolves THIS app's services (WorkflowMetadata etc.).
		// Its declared return type (IAppContainer) is deprecated by core with no
		// non-deprecated accessor on IBootContext, so this one Psalm deprecation
		// is unavoidable and rides the baseline; the call itself is correct.
		$container = $context->getAppContainer();

		// Register our managed Files-Metadata keys (n8n_id, n8n_mode,
		// n8n_versionId). Idempotent — safe to call on every boot — and the
		// only thing standing between us and automatic DAV/PROPFIND exposure
		// at `{nc:}metadata-n8n_id` etc.
		$container->get(WorkflowMetadata::class)->register();

		// Register the scheduled n8n→NC pull (§17.3). The job self-gates on
		// `schedule_enabled` and reads its interval from app config.
		//
		// ## `IJobList::add()` IS NOT IDEMPOTENT, AND CALLING IT EVERY BOOT STARVED THE JOB
		//
		// This line used to run unconditionally, on the belief — written right here —
		// that `add()` on an existing job is a no-op. It is not. Core's `JobList::add()`
		// takes the `else` branch and UPDATES the row:
		//
		//     ->set('reserved_at', 0)
		//     ->set('last_checked', $firstCheck)   // = now
		//     ->set('last_run', 0)
		//
		// `boot()` runs on every request that loads the app, so `last_checked` was
		// being reset to *now* many times a minute. Cron picks work with
		// `ORDER BY last_checked ASC` — oldest first — so the job kept being pushed to
		// the back of its own queue and only ran when the instance happened to go quiet.
		//
		// Measured on a live instance set to `5m`: consecutive runs 21 minutes apart,
		// then 11, while `last_run` sat at 0 permanently and `last_checked` moved every
		// few seconds. The interval was not being applied to anything. Nothing looked
		// broken — the pull is correct when it runs — so it read as "sync is just slow",
		// and the only reliable way to get a workflow mirrored was Sync now.
		//
		// `has()` is the guard core itself uses inside `add()`; asking first means the
		// registration happens once and the row is then left alone.
		$jobList = $container->get(\OCP\BackgroundJob\IJobList::class);
		if (!$jobList->has(ScheduledPullJob::class, null)) {
			$jobList->add(ScheduledPullJob::class);
		}

		// EMPTYING THE TRASH IS NOT AN EVENT. Nextcloud fires no typed event when a
		// file is purged from the trash — the trashbin emits the legacy
		// `\OCP\Trashbin` `preDelete` hook just before it unlinks the node, and that
		// is the only entry point there is, so the deprecation is unavoidable.
		// Connecting the handler INSTANCE because the legacy hook calls
		// object+method. See {@see TrashPurgeHook} for why this was missing for so
		// long and what it cost a sibling app.
		//
		// connectHook APPENDS with no de-duplication, so a second boot() in the same
		// process (tests, repeated loadApp) would stack the handler and delete twice
		// per file. Guarded.
		if (!self::$purgeHookRegistered) {
			self::$purgeHookRegistered = true;
			$purgeHook = $container->get(TrashPurgeHook::class);
			/** @psalm-suppress DeprecatedMethod */
			\OCP\Util::connectHook('\OCP\Trashbin', 'preDelete', $purgeHook, 'preDelete');
		}

		// RESTORING FROM A TEAM FOLDER'S TRASH IS NOT THE TYPED EVENT EITHER.
		// `NodeRestoredEvent` comes from Files_Trashbin's own restore and nowhere else;
		// groupfolders has its own trash backend and emits no typed event, so a workflow
		// file restored from a Team Folder came back with its workflow still archived —
		// and the next pull, seeing an archived workflow, trashed the file again. Both
		// backends DO emit the legacy `post_restore` hook, so that is the one signal
		// covering both. See {@see TrashRestoreHook}; same guard, same reason.
		if (!self::$restoreHookRegistered) {
			self::$restoreHookRegistered = true;
			$restoreHook = $container->get(TrashRestoreHook::class);
			/** @psalm-suppress DeprecatedMethod */
			\OCP\Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', $restoreHook, 'postRestore');
		}
	}
}
