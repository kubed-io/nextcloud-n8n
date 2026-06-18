/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Plain vite config (no @nextcloud preset).
 *
 * Why not the preset? `@nextcloud/vite-config`'s app preset hard-wipes the
 * entire `js/` directory before each build (its `EmptyJSDirPlugin` calls
 * `rmSync('js', recursive: true)`). The preset is designed for apps where
 * every JS file is vite-built, but our admin-settings scripts in `js/` are
 * hand-written and unbundled. So we ship a minimal IIFE bundle instead.
 *
 * Output: `dist/n8n_sync-files.js` (single self-contained file, no chunks).
 * Loaded by `LoadFilesScriptListener` via `Util::addScript('n8n_sync',
 * '../dist/n8n_sync-files', 'files')` so NC's loader walks out of `js/` and
 * into `dist/`. All generated artefacts stay under `dist/` (gitignored).
 */
import { defineConfig } from 'vite'

export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: true,
    target: 'es2020',
    minify: 'esbuild',
    lib: {
      // IIFE so the bundle adds nothing to the global scope and runs
      // inline at <script> load time — no module loader plumbing needed.
      entry: 'src/files.js',
      name: 'n8nSyncFiles',
      formats: ['iife'],
      fileName: () => 'n8n_sync-files.js',
    },
  },
})
