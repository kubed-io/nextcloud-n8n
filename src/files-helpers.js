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
