/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Files-app integration for n8n_sync.
 *
 *  - Registers an "Open in n8n" file action, promoted to DEFAULT for
 *    `application/n8n+json` so a row click opens the workflow in n8n instead of
 *    the Text editor. Plain JSON files are unaffected (action is gated by mime).
 *  - The deep-link id is the `n8n_id` Files-Metadata, exposed over WebDAV.
 *
 * Getting the id to the click handler — two tiers, no custom endpoint:
 *   1. PRIMARY (zero extra calls): registerDavProperty() adds `metadata-n8n_id`
 *      to the Files app's directory PROPFIND, so it rides the listing and lands
 *      on `node.attributes`. Works for every navigation.
 *   2. FALLBACK (one call, rare): on the very first folder after a full page
 *      load, our script registers a beat after core's first PROPFIND, so that
 *      listing misses the prop. When that happens we do a targeted single-node
 *      PROPFIND via the built-in @nextcloud/files WebDAV client requesting just
 *      our prop. No bespoke controller/route — same authenticated DAV core uses.
 */
import { registerFileAction, addNewFileMenuEntry, getUniqueName, DefaultType, NewMenuEntryCategory } from '@nextcloud/files'
import { registerDavProperty, getDefaultPropfind, getClient, getRootPath, resultToNode } from '@nextcloud/files/dav'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import { getN8nId, buildUrl, isN8nFile, getN8nMode, canOpenInN8n, canEditAsText } from './files-helpers.js'

const APP_ID = 'n8n_sync'

// Register our metadata key as a DAV property so it rides the directory PROPFIND
// (writes to the shared _nc_files_scope.v4_0 store core's PROPFIND reads). `nc`
// is a default namespace, so the bare prefixed name is enough.
registerDavProperty('nc:metadata-n8n_id')
// Also ride the mode on the listing so the toggle action knows sync vs link.
registerDavProperty('nc:metadata-n8n_mode')

// Base URL of the n8n instance (server-rendered initial state). Empty until the
// admin sets it — we hide the action in that case.
const n8nUrl = (() => {
  try {
    return String(loadState(APP_ID, 'n8n_url') || '').replace(/\/+$/, '')
  } catch {
    return ''
  }
})()

/**
 * Fallback for the first-load race: ask the built-in WebDAV endpoint for just
 * this node's metadata. getDefaultPropfind() now includes our registered prop,
 * so the single-node stat returns `metadata-n8n_id`.
 */
async function propfindN8nId(node) {
  if (!node?.path) return ''
  try {
    const res = await getClient().stat(getRootPath() + node.path, {
      details: true,
      data: getDefaultPropfind(),
    })
    return res?.data?.props?.['metadata-n8n_id'] || ''
  } catch (e) {
    console.warn('[n8n_sync] metadata PROPFIND failed', e)
    return ''
  }
}

/** Node → n8n deep link: node attributes first (free), else a one-shot PROPFIND. */
async function resolveUrl(node) {
  return buildUrl(n8nUrl, getN8nId(node)) || buildUrl(n8nUrl, await propfindN8nId(node))
}

// ── "Edit as text" — a plain-text source editor in a modal ─────────────────
// We deliberately do NOT use Text's createEditor(): that's a Markdown rich-text
// editor and it reflows JSON (drops indentation, can corrupt it). For workflow
// JSON we want a verbatim source view, so we load/save through the built-in
// WebDAV client into a monospace textarea. Saving fires NodeWrittenEvent → the
// normal writeback path.
let stylesInjected = false
function injectStyles() {
  if (stylesInjected) return
  stylesInjected = true
  const el = document.createElement('style')
  el.textContent = `
.n8n-sync-text-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.4)}
.n8n-sync-text-dialog{display:flex;flex-direction:column;width:min(92vw,1000px);height:min(92vh,820px);background:var(--color-main-background);border-radius:var(--border-radius-large,12px);box-shadow:0 0 30px rgba(0,0,0,.4);overflow:hidden}
.n8n-sync-text-bar{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--color-border)}
.n8n-sync-text-title{flex:1;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.n8n-sync-text-status{color:var(--color-text-maxcontrast);font-size:.9em}
.n8n-sync-text-area{flex:1;width:100%;box-sizing:border-box;border:none;resize:none;padding:12px 14px;font-family:var(--font-face-monospace,monospace);font-size:13px;line-height:1.5;tab-size:2;white-space:pre;overflow:auto;background:var(--color-main-background);color:var(--color-main-text)}
.n8n-sync-text-area:focus{outline:none}`
  document.head.appendChild(el)
}

async function openInText(node) {
  injectStyles()
  const path = getRootPath() + node.path
  const client = getClient()

  const overlay = document.createElement('div')
  overlay.className = 'n8n-sync-text-overlay'
  overlay.innerHTML =
    '<div class="n8n-sync-text-dialog">'
    + '<div class="n8n-sync-text-bar">'
    +   '<span class="n8n-sync-text-title"></span>'
    +   '<span class="n8n-sync-text-status"></span>'
    +   '<button type="button" class="button primary js-save">' + t(APP_ID, 'Save') + '</button>'
    +   '<button type="button" class="button js-close">' + t(APP_ID, 'Close') + '</button>'
    + '</div>'
    + '<textarea class="n8n-sync-text-area" spellcheck="false" wrap="off"></textarea>'
    + '</div>'
  document.body.appendChild(overlay)

  const sel = (s) => overlay.querySelector(s)
  sel('.n8n-sync-text-title').textContent = node.basename || 'workflow.n8n.json'
  const ta = sel('.n8n-sync-text-area')
  const setStatus = (m) => { sel('.n8n-sync-text-status').textContent = m }

  const close = () => { document.removeEventListener('keydown', onKey); overlay.remove() }
  const save = async () => {
    setStatus(t(APP_ID, 'Saving…'))
    try {
      await client.putFileContents(path, ta.value, { overwrite: true })
      setStatus(t(APP_ID, 'Saved'))
    } catch (e) {
      console.error('[n8n_sync] save failed', e)
      setStatus(t(APP_ID, 'Save failed'))
    }
  }
  const onKey = (e) => {
    if (e.key === 'Escape') { close() } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save() }
  }
  sel('.js-close').addEventListener('click', close)
  sel('.js-save').addEventListener('click', save)
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close() })
  document.addEventListener('keydown', onKey)

  setStatus(t(APP_ID, 'Loading…'))
  try {
    ta.value = await client.getFileContents(path, { format: 'text' })
    setStatus('')
    ta.focus()
  } catch (e) {
    console.error('[n8n_sync] could not load file', e)
    setStatus(t(APP_ID, 'Could not load file'))
  }
  return true
}

// @nextcloud/files v4: registerFileAction takes a plain IFileAction object.
// enabled()/exec() receive a single context `{ nodes, view, folder, contents }`.
registerFileAction({
  id: 'n8n_sync.open',
  displayName: () => t(APP_ID, 'Open in n8n'),
  iconSvgInline: () => `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
  <circle cx="7" cy="12" r="3"/>
  <circle cx="17" cy="12" r="3"/>
  <rect x="9" y="11" width="6" height="2"/>
</svg>`,

  // Offered for sync/link (a live workflow to open); HIDDEN for unmapped/ignored
  // (archived in n8n — nothing live to jump to). The opener set follows the file's
  // MODE, not its type (open-with.feature / saga §14.1). enabled() also keeps it
  // off plain JSON via isN8nFile.
  enabled: (context) => isN8nFile(context) && canOpenInN8n(getN8nMode(context?.nodes?.[0])),

  async exec(context) {
    const url = await resolveUrl(context?.nodes?.[0])
    if (!url) return null
    window.open(url, '_blank', 'noopener,noreferrer')
    return true
  },

  // Default click for sync/link; for unmapped/ignored this action is disabled, so
  // the lower-priority "Open with text editor" default wins instead (see below).
  default: DefaultType.DEFAULT,
  order: -50, // above other JSON claimers (Text ~0) and above the text opener
})

// "Open with text editor" — edit the raw JSON. Offered for every mode that holds
// the full workflow on disk (sync / unmapped / ignored), and the DEFAULT click for
// unmapped/ignored (no live workflow to open). HIDDEN for `link`: a link is only a
// pointer, so there is nothing to edit and any change would break it — making
// "sync" the mode you flip to in order to edit the JSON (see the toggle action),
// which is the user-visible sync-vs-link difference.
// It is also marked DEFAULT, but at a *lower* priority (order -49) than "Open in
// n8n" (-50): for sync both are enabled and n8n wins; for unmapped/ignored
// "Open in n8n" is disabled, so this becomes the default click; for link this
// action is disabled and n8n is the only opener. (open-with.feature)
registerFileAction({
  id: 'n8n_sync.edit',
  displayName: () => t(APP_ID, 'Open with text editor'),
  iconSvgInline: () => `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
  <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
</svg>`,
  // Offered for any n8n file that holds editable JSON (sync/unmapped/ignored, and
  // the permissive loading case); hidden for `link` (a pointer — nothing to edit).
  // Don't gate on window.OCA.Text here — it can be defined a touch later than our
  // row render; openInText() handles the (unlikely) case where Text's API isn't ready.
  enabled: (context) => isN8nFile(context) && canEditAsText(getN8nMode(context?.nodes?.[0])),
  async exec(context) {
    // null = silent (the modal is the feedback); false = error toast on failure.
    return (await openInText(context.nodes[0])) ? null : false
  },
  default: DefaultType.DEFAULT,
  order: -49, // below "Open in n8n"; the fallback default for unmapped/ignored
})

// ── "New → n8n workflow" ───────────────────────────────────────────────────
// Always offered, in any folder (we deliberately don't gate on a mapping). A
// new file outside a mapped folder is just a `.n8n.json` with our icon and
// empty metadata — not synced. Drop it into a mapped folder to make it real in
// n8n (see the move-in/create-on-land path). The NodeWrittenListener re-stamps
// the custom mimetype on write, so the icon is correct immediately.
const STARTER_WORKFLOW = JSON.stringify({
  name: 'New workflow',
  nodes: [],
  connections: {},
  settings: {},
  active: false,
}, null, 2) + '\n'

addNewFileMenuEntry({
  id: 'n8n_sync.new-workflow',
  displayName: t(APP_ID, 'n8n workflow'),
  category: NewMenuEntryCategory.CreateNew,
  order: 20,
  iconSvgInline: `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
  <circle cx="7" cy="12" r="3"/>
  <circle cx="17" cy="12" r="3"/>
  <rect x="9" y="11" width="6" height="2"/>
</svg>`,
  async handler(context, content) {
    const names = (content || []).map((n) => n.basename)
    const name = getUniqueName(t(APP_ID, 'New workflow') + '.n8n.json', names)
    const dir = context.path === '/' ? '' : context.path
    const davPath = `${getRootPath()}${dir}/${name}`
    try {
      const client = getClient()
      await client.putFileContents(davPath, STARTER_WORKFLOW, {
        contentType: 'application/json',
        overwrite: false,
      })
      // Stat back the freshly-written file (mimetype already re-stamped by the
      // server listener) and announce it so the Files view picks it up.
      const res = await client.stat(davPath, { details: true, data: getDefaultPropfind() })
      emit('files:node:created', resultToNode(res.data))
    } catch (e) {
      console.error('[n8n_sync] could not create workflow', e)
      window.OC?.Notification?.showTemporary?.(t(APP_ID, 'Could not create the workflow file'))
    }
  },
})

console.info('[n8n_sync] files integration loaded — actions: open in n8n (sync/link) + open with text editor; New: n8n workflow')
