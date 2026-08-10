<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// Admin-only: test the saved n8n URL + API key by calling /api/v1/workflows.
		['name' => 'config#testConnection', 'url' => '/testconnection', 'verb' => 'GET'],
		// Admin-only: test the Webhook channel by POSTing to the test-event path.
		['name' => 'config#testWebhook', 'url' => '/testwebhook', 'verb' => 'GET'],

		// Folder mappings CRUD (admin-gated via #[AuthorizedAdminSetting]).
		['name' => 'mapping#index',   'url' => '/mappings',      'verb' => 'GET'],
		['name' => 'mapping#create',  'url' => '/mappings',      'verb' => 'POST'],
		['name' => 'mapping#update',  'url' => '/mappings/{id}', 'verb' => 'PUT'],
		['name' => 'mapping#destroy', 'url' => '/mappings/{id}', 'verb' => 'DELETE'],
		// Per-mapping manual sync (pull just this mapping from n8n).
		['name' => 'mapping#sync',    'url' => '/mappings/{id}/sync', 'verb' => 'POST'],

		// Manual sync buttons. (Strategy settings auto-persist via the declarative
		// "Sync Settings" form — no controller needed.)
		['name' => 'sync#status', 'url' => '/sync/status', 'verb' => 'GET'],
		['name' => 'sync#pull',   'url' => '/sync/pull',   'verb' => 'POST'],
		['name' => 'sync#push',   'url' => '/sync/push',   'verb' => 'POST'],
		// Admin-only: delete the restorable (sync/link) files this app created.

		// Admin-only smoke tests for the n8n REST client (Phase 1).
		// Same admin gate as Test connection. Useful while Phase 3/4 stubs
		// are still in place — exercises the full config → decrypt → HTTP
		// path against the live n8n.
		['name' => 'debug#listWorkflows', 'url' => '/debug/workflows',      'verb' => 'GET'],
		['name' => 'debug#getWorkflow',   'url' => '/debug/workflows/{id}', 'verb' => 'GET'],
	],
];
