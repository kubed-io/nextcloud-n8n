/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Test Connection" handlers — one button per channel (REST API + Webhook).
 * Loaded via Util::addScript() so the Nextcloud CSP nonce is applied
 * automatically — inline <script> tags are blocked by the strict-dynamic CSP.
 */
(function () {
	'use strict';

	// Wire one test button → its status span → its endpoint. The endpoint
	// returns {status: 'ok'|'error', message}; we render the canonical
	// `.msg.success` / `.msg.error` pill used across the admin pages.
	function wire(btnId, statusId, path) {
		var btn = document.getElementById(btnId);
		var status = document.getElementById(statusId);
		if (!btn || !status || btn.dataset.bound === '1') {
			return;
		}
		btn.dataset.bound = '1';

		btn.addEventListener('click', function () {
			btn.disabled = true;
			status.textContent = t('n8n_sync', 'Testing…');
			status.className = 'msg';

			fetch(OC.generateUrl(path), {
				headers: {
					'requesttoken': OC.requestToken,
					'Accept': 'application/json',
				},
			})
				.then(function (res) { return res.json(); })
				.then(function (data) {
					if (data.status === 'ok') {
						status.textContent = data.message;
						status.className = 'msg success';
					} else {
						status.textContent = data.message || t('n8n_sync', 'Unknown error');
						status.className = 'msg error';
					}
				})
				.catch(function (err) {
					status.textContent = err.message;
					status.className = 'msg error';
				})
				.finally(function () {
					btn.disabled = false;
				});
		});
	}

	function init() {
		wire('n8n-sync-test-btn', 'n8n-sync-test-status', '/apps/n8n_sync/testconnection');
		wire('n8n-sync-webhook-btn', 'n8n-sync-webhook-status', '/apps/n8n_sync/testwebhook');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
