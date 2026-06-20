/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest config, kept separate from vite.config.js (which is a lib/IIFE *build*
 * config, not a test config). Node environment is enough — the unit suite only
 * exercises the pure, DOM-free helpers in src/files-helpers.js. Mirrors the
 * nextcloud-libraries convention (dedicated vitest.config).
 */
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/js/**/*.test.js'],
  },
})
