// SPDX-FileCopyrightText: 2026 Kelly Ferrone
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// ESLint flat config. Flat config has to live in a real .js file — ESLint 9+
// dropped support for the `eslintConfig` key in package.json. Keep this small;
// the JS surface (src/files.js + vite.config.js) is tiny.

import js from '@eslint/js'
import globals from 'globals'

export default [
  {
    ignores: ['dist/', 'node_modules/'],
  },
  js.configs.recommended,
  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.node,
        // Nextcloud server-rendered globals injected on the admin page where
        // js/*.js loads. Not modules — these are real page-scoped globals.
        // t: translation helper (t(appName, str)). Not the lowercase `t` from
        // anywhere else; it's NC's i18n function attached to window.
        // OC: the global Nextcloud client API namespace.
        // OCA, OCP: companion namespaces sometimes used by admin scripts.
        // n: l10n plural helper used alongside t.
        t: 'readonly',
        n: 'readonly',
        OC: 'readonly',
        OCA: 'readonly',
        OCP: 'readonly',
      },
    },
    rules: {
      'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      // console.log is the classic leftover-debug crumb — flag it.
      // info/warn/error are legitimate runtime signals; keep them.
      'no-console': ['warn', { allow: ['info', 'warn', 'error'] }],
    },
  },
]
