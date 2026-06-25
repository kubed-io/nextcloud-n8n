/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Manual sync handlers (vanilla JS, no build step).
 *
 *  - One row per direction (pull / push).
 *  - Click → POST /apps/n8n_sync/sync/{direction} → re-render the row's
 *    "last: ..." line from the response payload.
 *  - Disables the button while the run is in flight to keep double-clicks
 *    from queueing duplicate jobs.
 */
(function () {
	'use strict';

	function init() {
		var root = document.getElementById('n8n-sync-manual');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		root.addEventListener('click', function (e) {
			var purgeBtn = e.target.closest('.js-purge');
			if (purgeBtn) { purge(purgeBtn); return; }
			var btn = e.target.closest('.js-run');
			if (!btn) { return; }
			var row = btn.closest('.n8n-sync-manual__row');
			if (!row) { return; }
			run(row, btn);
		});
	}

	// Purge: delete the restorable (sync/link) files this app created. Destructive,
	// so confirm first. Synchronous + local (no n8n), so a plain POST → toast counts.
	function purge(btn) {
		var ok = window.confirm(t('n8n_sync',
			'Remove the sync & link workflow files this app created from Nextcloud? '
			+ 'n8n is not touched, and you can restore them with “Sync from n8n”. '
			+ 'Unmapped and standalone files are kept.'));
		if (!ok) { return; }
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = t('n8n_sync', 'Purging…');
		api('POST', OC.generateUrl('/apps/n8n_sync/sync/purge'))
			.then(function (res) {
				flash('success', t('n8n_sync', 'Removed {deleted} file(s); kept {kept}.', {
					deleted: (res && res.deleted) || 0,
					kept: (res && res.kept) || 0,
				}));
			})
			.catch(function (err) {
				flash('error', (err && err.message) || t('n8n_sync', 'Purge failed.'));
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = prev;
			});
	}

	// Bulk sync is asynchronous: the POST enqueues a background job and returns
	// 'queued' immediately. We then poll /sync/status until that direction
	// reaches ok|error, so the admin sees progress and can navigate away/return.
	function run(row, btn) {
		var direction = row.dataset.direction;
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = t('n8n_sync', 'Queued…');

		api('POST', OC.generateUrl('/apps/n8n_sync/sync/' + encodeURIComponent(direction)))
			.then(function (res) {
				if (res && res.status === 'error') {
					throw new Error(res.message || t('n8n_sync', 'Could not start sync.'));
				}
				flash('success', t('n8n_sync', 'Sync queued — running in the background.'));
				pollUntilDone(row, btn, direction, prev);
			})
			.catch(function (err) {
				flash('error', err.message || t('n8n_sync', 'Sync failed.'));
				btn.disabled = false;
				btn.textContent = prev;
			});
	}

	function pollUntilDone(row, btn, direction, prev) {
		var lastEl = row.querySelector('.js-last');
		var tries = 0;
		var timer = setInterval(function () {
			tries++;
			fetch(OC.generateUrl('/apps/n8n_sync/sync/status'), {
				headers: { 'requesttoken': OC.requestToken, 'Accept': 'application/json' },
			})
				.then(function (r) { return r.json(); })
				.then(function (all) {
					var rec = (all && all[direction]) || {};
					if (rec.status === 'running') {
						btn.textContent = t('n8n_sync', 'Running…');
					}
					if (rec.status === 'ok' || rec.status === 'error') {
						clearInterval(timer);
						lastEl.textContent = formatLast(rec);
						flash(rec.status === 'error' ? 'error' : 'success',
							rec.status === 'error' ? (rec.message || t('n8n_sync', 'Sync failed.')) : t('n8n_sync', 'Done.'));
						btn.disabled = false;
						btn.textContent = prev;
					}
				})
				.catch(function () { /* transient; keep polling */ });
			if (tries > 150) { // ~5 min cap if the cron worker is slow/idle
				clearInterval(timer);
				btn.disabled = false;
				btn.textContent = prev;
				flash('', t('n8n_sync', 'Still running in the background — check back shortly.'));
			}
		}, 2000);
	}

	function formatLast(rec) {
		var line = formatLastBase(rec);
		if (rec && rec.status === 'queued') { return t('n8n_sync', 'Queued…') + ' · ' + line; }
		if (rec && rec.status === 'running') { return t('n8n_sync', 'Running…') + ' · ' + line; }
		return line;
	}

	function formatLastBase(rec) {
		if (!rec || !rec.finished_at) {
			return t('n8n_sync', 'last: never');
		}
		var counters = ((rec.succeeded || 0) + ' ' + t('n8n_sync', 'synced')
			+ ' · ' + (rec.failed || 0) + ' ' + t('n8n_sync', 'errors'));
		var line = t('n8n_sync', 'last: {when} · {counters}', {
			when: rec.finished_at,
			counters: counters,
		});
		if (rec.message) {
			line += ' · ' + rec.message;
		}
		return line;
	}

	function api(method, url) {
		return fetch(url, {
			method: method,
			headers: {
				'requesttoken': OC.requestToken,
				'Accept': 'application/json',
			},
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					var err = new Error(data && data.message ? data.message : 'HTTP ' + res.status);
					return Promise.reject(err);
				}
				return data;
			});
		});
	}

	var flashTimer = null;
	function flash(kind, text) {
		var el = document.getElementById('n8n-sync-manual-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = 'msg ' + kind;
		if (flashTimer) { clearTimeout(flashTimer); }
		flashTimer = setTimeout(function () {
			el.textContent = '';
			el.className = 'msg';
		}, 4000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
