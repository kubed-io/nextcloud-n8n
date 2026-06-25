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
use OCA\N8nSync\Listener\CopyListener;
use OCA\N8nSync\Listener\CreateInN8nListener;
use OCA\N8nSync\Listener\DeleteToN8nListener;
use OCA\N8nSync\Listener\LoadFilesScriptListener;
use OCA\N8nSync\Listener\MimeRestampListener;
use OCA\N8nSync\Listener\ModeTagListener;
use OCA\N8nSync\Listener\MotionListener;
use OCA\N8nSync\Listener\MoveGuardListener;
use OCA\N8nSync\Listener\NameSyncListener;
use OCA\N8nSync\Listener\NodeWrittenListener;
use OCA\N8nSync\Listener\RegisterDavPluginsListener;
use OCA\N8nSync\Listener\RestoreFromTrashListener;
use OCA\N8nSync\Notification\Notifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCA\N8nSync\Settings\AdminSettings;
use OCA\N8nSync\Settings\AutoSyncSettings;
use OCA\N8nSync\Settings\InstanceSettings;
use OCA\N8nSync\Settings\WebhookSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
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
		// n8n_sync file is determined by the file itself — the `.n8n.json`
		// extension, the `n8n_id` Files-Metadata stamp, and/or one of the
		// `n8n:sync` / `n8n:link` system tags. Anything carrying those
		// markers inside a mapped folder is fair game for sync; anything
		// without them is ignored. No setting needed.
		//
		// Section layout (by priority): Instance URL (5) → REST API (10) → Test
		// connection (15, classic panel via info.xml) → Webhook (20) →
		// Writeback timing (25) → Mappings/Manual sync (30+). API and Webhook are
		// independent, composable writeback channels; timing governs *when*
		// either fires, so it follows both.
		$context->registerDeclarativeSettings(InstanceSettings::class);
		$context->registerDeclarativeSettings(AdminSettings::class);
		$context->registerDeclarativeSettings(AutoSyncSettings::class);
		$context->registerDeclarativeSettings(WebhookSettings::class);

		// Push wiring: push saved sync-mode files to every enabled channel.
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

		// §17.2 create-on-land: a `.n8n.json` without `n8n_id` appearing in a
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

		// Re-stamp `application/n8n+json` on rename. NC's rename re-detects mime
		// from the new path; `Detection::detectPath()` only inspects the last
		// extension (`json`) so our compound `.n8n.json` resolves wrong and the
		// icon goes missing until the next save re-stamps. Closes that gap with
		// a cheap global UPDATE keyed on the extension.
		$context->registerEventListener(NodeRenamedEvent::class, MimeRestampListener::class);

		// Exclude / restore by retag. The per-file sync/link toggle was removed in §15.3:
		// the mapping's mode is the single source of truth, so only `n8n:ignore` is
		// actionable: assigning it routes to ModeChangeService to archive the workflow
		// and flip the file to `ignored`. Our own apply() re-touches tags under SyncGuard, so
		// the listener bails when the guard is active (no recursion).
		$context->registerEventListener(TagAssignedEvent::class, ModeTagListener::class);
		// Removing n8n:ignore is the inverse: unarchive the workflow and return the file
		// to its mapping's default mode (saga §14.8). Same listener, TagUnassignedEvent.
		$context->registerEventListener(TagUnassignedEvent::class, ModeTagListener::class);

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

		// Register the scheduled n8n→NC pull (§17.3). IJobList::add is idempotent,
		// so calling it every boot just ensures the TimedJob exists; the job
		// self-gates on `schedule_enabled` and reads its interval from app config.
		$container->get(\OCP\BackgroundJob\IJobList::class)->add(ScheduledPullJob::class);
	}
}
