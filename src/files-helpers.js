/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure, dependency-free helpers for the Files integration (src/files.js).
 *
 * These are split out from files.js precisely because files.js imports
 * `@nextcloud/*` ESM at the top level, which makes it awkward to unit test. This
 * module imports nothing, so Vitest can exercise the branchy logic directly —
 * the JS analog of PHP's FilenameCodec. Keep it free of NC imports and DOM/network.
 */

/** The custom mimetype the pull reconciler stamps onto workflow files. */
export const N8N_MIME = 'application/n8n+json'

/**
 * Read the n8n workflow id from a node's DAV attributes (the listing fast path).
 * Tolerates the three shapes the id can arrive as, depending on which PROPFIND
 * produced the node. Returns '' when absent or not a string.
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}
 */
export function getN8nId(node) {
  const a = node?.attributes ?? {}
  const id = a['metadata-n8n_id'] || a['n8n_id'] || a['{http://nextcloud.org/ns}metadata-n8n_id']
  return typeof id === 'string' ? id : ''
}

/**
 * Build the n8n deep link for a workflow id. Returns '' if either the base URL
 * or the id is empty (the caller hides the action in that case). The base url is
 * passed in (not closed over) so this stays pure and testable.
 *
 * @param {string} n8nUrl  Trailing-slash-trimmed n8n base URL.
 * @param {string} id      Workflow id.
 * @return {string}
 */
export function buildUrl(n8nUrl, id) {
  return n8nUrl && id ? `${n8nUrl}/workflow/${encodeURIComponent(id)}` : ''
}

/**
 * Is this file-action context a single n8n workflow file? True for the custom
 * mime OR a `.n8n.json` basename, and only when exactly one node is selected.
 * Plain JSON is never matched.
 *
 * @param {{nodes?: Array<{mime?: string, basename?: string}>}} [context]
 * @return {boolean}
 */
export function isN8nFile(context) {
  const node = context?.nodes?.[0]
  if (!node || context.nodes.length !== 1) return false
  return node.mime === N8N_MIME
    || (typeof node.basename === 'string' && node.basename.endsWith('.n8n.json'))
}

/**
 * Read the workflow's `n8n_mode` from a node's DAV attributes. Tolerates the same
 * three attribute shapes as {@see getN8nId}, and translates the WIRE value back:
 * a `link` is stored as `reference` over DAV (the literal `link` makes
 * `is_callable()` true and crashes core PROPFIND — saga §14.1), so we normalise
 * `reference` → `link` here. Returns '' when absent (the first-load PROPFIND race,
 * or an untracked file).
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}  '' | 'sync' | 'link' | 'unmapped' | 'ignored'
 */
export function getN8nMode(node) {
  const a = node?.attributes ?? {}
  const raw = a['metadata-n8n_mode'] || a['n8n_mode'] || a['{http://nextcloud.org/ns}metadata-n8n_mode']
  const mode = typeof raw === 'string' ? raw : ''
  return mode === 'reference' ? 'link' : mode
}

/**
 * Should "Open in n8n" be offered for a file in this mode? It is meaningful only
 * when a live workflow exists to open: `sync`/`link` have one, `unmapped`/`ignored`
 * do not (their workflow is archived in n8n — nothing to jump to). An absent mode
 * (the first-load race, or an untracked file) stays permissive → shown, matching
 * the pre-mode behaviour; the action no-ops harmlessly if there is no id to resolve.
 *
 * @param {string} mode
 * @return {boolean}
 */
export function canOpenInN8n(mode) {
  return mode !== 'unmapped' && mode !== 'ignored'
}

/**
 * Which opener a plain row-click uses, by mode. `sync`/`link` (and the permissive
 * absent case) → the live workflow in n8n; `unmapped`/`ignored` → the text editor
 * on the local JSON. Mirrors {@see canOpenInN8n} so the default click and the
 * action visibility never disagree.
 *
 * @param {string} mode
 * @return {'n8n'|'text'}
 */
export function defaultOpener(mode) {
  return canOpenInN8n(mode) ? 'n8n' : 'text'
}

/**
 * Should "Open with text editor" be offered for a file in this mode? Every mode
 * holds the full workflow JSON on disk EXCEPT `link`, which is only a small pointer
 * (id/name/url) — there is nothing meaningful to edit, and any change would just
 * break the pointer. So `sync`, `unmapped`, `ignored` (and the permissive absent
 * case, matching {@see canOpenInN8n}) → shown; `link` → hidden. This is the mirror
 * of {@see canOpenInN8n}'s intent and what makes "open as text" the user-visible
 * difference between a `sync` file (editable JSON) and a `link` (open in n8n only).
 *
 * @param {string} mode
 * @return {boolean}
 */
export function canEditAsText(mode) {
  return mode !== 'link'
}
