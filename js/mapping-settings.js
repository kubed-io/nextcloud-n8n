/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Folder-mapping admin handlers (vanilla JS, no build step).
 *
 * One card per mapping. The single "Mode" selector has three values
 * a single Mode value (sync / link) — saga Ch2 §14 (writeback/backup dropped).
 * Groups are a wrapping checkbox list. Available groups + Team Folder
 * availability are embedded on the root element's data-* attributes.
 */
(function () {
	'use strict';

	var APP_URL_BASE = '/apps/n8n_sync/mappings';

	function init() {
		var root = document.getElementById('n8n-sync-mappings');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		var list = root.querySelector('.n8n-sync-mappings__list');
		var addBtn = document.getElementById('n8n-sync-mappings-add');

		list.addEventListener('click', function (e) {
			var btn = e.target.closest('button');
			if (!btn) { return; }
			var card = btn.closest('.n8n-sync-mappings__card');
			if (!card) { return; }
			if (btn.classList.contains('js-save')) {
				saveCard(card);
			} else if (btn.classList.contains('js-sync')) {
				syncCard(card);
			} else if (btn.classList.contains('js-delete')) {
				deleteCard(card);
			}
		});

		addBtn.addEventListener('click', function () {
			list.appendChild(buildEmptyCard());
		});
	}

	function availableGroups() {
		var root = document.getElementById('n8n-sync-mappings');
		try { return JSON.parse(root.dataset.groups || '[]'); } catch { return []; }
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function readCard(card) {
		// Mode is a single value now: 'sync' or 'link' (saga Ch2 §14 — writeback gone).
		var mode = card.querySelector('.js-mode').value === 'link' ? 'link' : 'sync';
		var groups = [];
		Array.prototype.forEach.call(
			card.querySelectorAll('.js-groups input[type="checkbox"]:checked'),
			function (cb) { groups.push(cb.value); }
		);
		var tfEl = card.querySelector('.js-use-team-folder');
		return {
			id: card.dataset.id || '',
			n8n_tag: card.querySelector('.js-n8n-tag').value.trim(),
			team_folder: card.querySelector('.js-team-folder').value.trim(),
			nc_groups: groups,
			mode: mode,
			use_team_folder: tfEl ? tfEl.checked : true,
		};
	}

	function saveCard(card) {
		var data = readCard(card);
		if (!data.nc_groups.length) {
			cardStatus(card, 'error', t('n8n_sync', 'Pick at least one group — otherwise the folder is invisible.'));
			return;
		}
		var isNew = !data.id;
		var url = OC.generateUrl(APP_URL_BASE + (isNew ? '' : '/' + encodeURIComponent(data.id)));
		api(isNew ? 'POST' : 'PUT', url, data)
			.then(function (res) {
				if (res.mapping && res.mapping.id) {
					card.dataset.id = res.mapping.id;
					// Backend is immutable once it exists — lock the checkbox.
					var tf = card.querySelector('.js-use-team-folder');
					if (tf) { tf.disabled = true; }
				}
				cardStatus(card, 'success', t('n8n_sync', 'Saved.'));
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('n8n_sync', 'Save failed.'));
			});
	}

	function syncCard(card) {
		var id = card.dataset.id || '';
		if (!id) {
			cardStatus(card, 'error', t('n8n_sync', 'Save the mapping before syncing.'));
			return;
		}
		var btn = card.querySelector('.js-sync');
		if (btn) { btn.disabled = true; }
		cardStatus(card, '', t('n8n_sync', 'Syncing…'));
		api('POST', OC.generateUrl(APP_URL_BASE + '/' + encodeURIComponent(id) + '/sync'))
			.then(function (res) {
				cardStatus(card, 'success', t('n8n_sync', 'Synced: {s} ok, {f} failed of {p}.',
					{ s: res.succeeded, f: res.failed, p: res.processed }));
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('n8n_sync', 'Sync failed.'));
			})
			.finally(function () { if (btn) { btn.disabled = false; } });
	}

	function deleteCard(card) {
		var id = card.dataset.id || '';
		if (!id) { card.remove(); return; }

		if (!window.confirm(t('n8n_sync', 'Remove this mapping? The folder and its files are kept.'))) {
			return;
		}
		var purge = window.confirm(t('n8n_sync',
			'Also delete the .n8n.json files this integration created in the folder?\n\n'
			+ 'OK = delete them. Cancel = keep them.\n'
			+ 'Foreign files and the folder itself are always kept. This cannot be undone.'));
		var url = OC.generateUrl(APP_URL_BASE + '/' + encodeURIComponent(id)) + (purge ? '?purge=1' : '');
		api('DELETE', url)
			.then(function (res) {
				card.remove();
				var msg = (res && typeof res.purged === 'number')
					? t('n8n_sync', 'Removed; {n} file(s) purged.', { n: res.purged })
					: t('n8n_sync', 'Removed.');
				flash('success', msg);
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('n8n_sync', 'Delete failed.'));
			});
	}

	// Per-mapping status, shown to the right of the card's buttons. Sticky —
	// it stays until the next action or a page reload (no auto-dismiss).
	function cardStatus(card, kind, text) {
		var el = card.querySelector('.js-card-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = 'js-card-status' + (kind ? (' msg ' + kind) : '');
	}

	// Per-field help (mirrors the PHP $desc) + the ⓘ button markup. The CSS
	// turns the span into a hover/focus tooltip.
	var DESC = {
		tag: t('n8n_sync', 'Workflows carrying this tag in n8n sync into this folder. One tag per mapping.'),
		mode: t('n8n_sync', 'Link: a read-only pointer to n8n. Sync: the full workflow JSON lives here and edits push back. Backup: a read-only copy (edits only ever come from n8n).'),
		folder: t('n8n_sync', 'Name of the Nextcloud folder the workflows appear in.'),
		tf: t('n8n_sync', 'On = an ownerless Team Folder (groupfolders). Off = a folder in the admin account shared to the groups. Fixed once saved.'),
		groups: t('n8n_sync', 'Which Nextcloud groups the folder is shared with. Pick at least one — otherwise no one can see it.'),
	};
	function info(tip) {
		var e = escapeHtml(tip);
		return ' <span class="n8n-sync-info" tabindex="0" role="note" aria-label="' + e + '" data-tip="' + e + '">'
			+ '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg></span>';
	}

	function buildEmptyCard() {
		var card = document.createElement('div');
		card.className = 'n8n-sync-mappings__card';
		var tfAvail = document.getElementById('n8n-sync-mappings').dataset.tfAvailable === '1';
		var tfAttrs = tfAvail ? ' checked' : ' disabled';
		var groupBoxes = availableGroups().map(function (g) {
			return '<label class="n8n-sync-groups__item"><input type="checkbox" value="'
				+ escapeHtml(g) + '" /> ' + escapeHtml(g) + '</label>';
		}).join('');
		card.innerHTML = ''
			+ '<div class="n8n-sync-mappings__grid">'
			+   '<div class="n8n-sync-field nf-tag"><label>' + t('n8n_sync', 'n8n tag') + info(DESC.tag) + '</label>'
			+     '<input type="text" class="js-n8n-tag" placeholder="nextcloud:tasking" /></div>'
			+   '<div class="n8n-sync-field nf-mode"><label>' + t('n8n_sync', 'Mode') + info(DESC.mode) + '</label>'
			+     '<select class="js-mode">'
			+       '<option value="sync" selected>' + t('n8n_sync', 'Sync') + '</option>'
			+       '<option value="link">' + t('n8n_sync', 'Link') + '</option>'
			+     '</select></div>'
			+   '<div class="n8n-sync-field nf-folder"><label>' + t('n8n_sync', 'Folder') + info(DESC.folder) + '</label>'
			+     '<input type="text" class="js-team-folder" placeholder="n8n" /></div>'
			+   '<div class="n8n-sync-field nf-tf"><label class="n8n-sync-checkbox">'
			+     '<input type="checkbox" class="js-use-team-folder"' + tfAttrs + ' /> ' + t('n8n_sync', 'Team Folder') + info(DESC.tf) + '</label></div>'
			+   '<div class="n8n-sync-field nf-groups"><label>' + t('n8n_sync', 'Groups') + info(DESC.groups) + '</label>'
			+     '<div class="js-groups n8n-sync-groups">' + groupBoxes + '</div></div>'
			+   '<div class="n8n-sync-mappings__actions">'
			+   '<button type="button" class="button js-save" title="' + t('n8n_sync', 'Save') + '" aria-label="' + t('n8n_sync', 'Save') + '">'
			+     '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></button>'
			+   '<button type="button" class="button js-sync" title="' + t('n8n_sync', 'Sync') + '" aria-label="' + t('n8n_sync', 'Sync') + '">'
			+     '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l1.46 1.46A6.94 6.94 0 0 0 19 13c0-3.87-3.13-7-7-7zm0 12c-2.76 0-5-2.24-5-5 0-.65.13-1.26.36-1.83L5.9 9.71A6.94 6.94 0 0 0 5 13c0 3.87 3.13 7 7 7v3l4-4-4-4z"/></svg></button>'
			+   '<button type="button" class="button js-delete" title="' + t('n8n_sync', 'Delete') + '" aria-label="' + t('n8n_sync', 'Delete') + '">'
			+     '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></button>'
			+     '<span class="js-card-status"></span>'
			+   '</div>'
			+ '</div>';
		return card;
	}

	function api(method, url, body) {
		var opts = {
			method: method,
			headers: { 'requesttoken': OC.requestToken, 'Accept': 'application/json' },
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					return Promise.reject(new Error(data && data.message ? data.message : 'HTTP ' + res.status));
				}
				return data;
			});
		});
	}

	var flashTimer = null;
	function flash(kind, text) {
		var el = document.getElementById('n8n-sync-mappings-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = kind ? ('msg ' + kind) : 'msg';
		if (flashTimer) { clearTimeout(flashTimer); }
		flashTimer = setTimeout(function () { el.textContent = ''; el.className = 'msg'; }, 5000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
