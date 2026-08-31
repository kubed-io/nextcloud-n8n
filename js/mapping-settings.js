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

	// Card glyphs (info / save / sync / delete) are read from the root element's
	// data-icons attribute, which the server fills from img/icons/ — the same SVG
	// folder the Files-app bundle imports. This unbundled script has no build step
	// (see vite.config.js), so injection is how it shares the one icon source.
	var ICONS = {};

	function init() {
		var root = document.getElementById('n8n-sync-mappings');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		try {
			ICONS = JSON.parse(root.dataset.icons || '{}');
		} catch {
			ICONS = {};
		}

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

	// A saved card renders its immutable fields as text carrying data-value; the
	// add-card still renders real inputs. Read either.
	function fieldValue(card, selector) {
		var el = card.querySelector(selector);
		if (!el) { return ''; }
		return (el.dataset && typeof el.dataset.value === 'string' ? el.dataset.value : (el.value || '')).trim();
	}

	function readCard(card) {
		var groups = [];
		Array.prototype.forEach.call(
			card.querySelectorAll('.js-groups input[type="checkbox"]:checked'),
			function (cb) { groups.push(cb.value); }
		);
		var tfEl = card.querySelector('.js-use-team-folder');
		return {
			id: card.dataset.id || '',
			n8n_tag: fieldValue(card, '.js-n8n-tag'),
			team_folder: fieldValue(card, '.js-team-folder'),
			nc_groups: groups,
			// Mode is a single value: 'sync' or 'link' (saga Ch2 §14 — writeback gone).
			mode: fieldValue(card, '.js-mode') === 'link' ? 'link' : 'sync',
			use_team_folder: tfEl ? tfEl.checked : true,
		};
	}

	function saveCard(card, purge) {
		var data = readCard(card);
		var isNew = !data.id;
		// AN EXISTING CARD SENDS ONLY ITS GROUPS. Everything else about a mapping is
		// immutable, and the endpoint takes nothing else — sending the rest would be
		// a payload the server is right to ignore, which is exactly how a UI comes to
		// offer an edit that silently does nothing.
		var payload = isNew ? data : { nc_groups: data.nc_groups };
		if (purge) {
			// A COPY, so a retry cannot leave the flag on the card's own state and
			// quietly arm the next save. Passed on the RETRY only, never on the first
			// attempt, so the panel cannot destroy anything the admin has not just been
			// shown a number for.
			payload = Object.assign({}, payload, { purge_workflows: true });
		}
		var url = OC.generateUrl(APP_URL_BASE + (isNew ? '' : '/' + encodeURIComponent(data.id)));
		api(isNew ? 'POST' : 'PUT', url, payload)
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
				// A link mapping over a folder that already holds workflow files comes
				// back 422 with a count. Everything else is a dead end and lands in the
				// card's status line; this one becomes a question, because the admin can
				// answer it — and answering it destroys files that do NOT go to the trash.
				if (typeof err.workflows === 'number' && !purge) {
					confirmPurge(card, err.workflows, err.folder || data.team_folder);
					return;
				}
				cardStatus(card, 'error', err.message || t('n8n_sync', 'Save failed.'));
			});
	}

	/**
	 * Ask before destroying workflow files, and say how many and that they will not
	 * come back.
	 *
	 * THE COUNT AND THE WORD "PERMANENTLY" ARE THE POINT. This is the only gesture in
	 * the app that destroys something outright — a link mirror is a pointer, so a
	 * workflow file already in the folder cannot survive there, and it may not go to
	 * the trash either: restoring one into a link mapping cannot work, so offering the
	 * restore would be a worse lie than refusing it.
	 *
	 * Cancelling needs no cleanup, and that is a property of the rule rather than an
	 * omission. The admin goes and moves the files, and when they come back the folder
	 * holds none — so the mapping is created with no warning at all.
	 */
	function confirmPurge(card, count, folder) {
		var msg = n(
			'n8n_sync',
			'"{folder}" already holds {count} workflow file. Mapping it in link mode will permanently delete it — it will not go to the trash and cannot be recovered. Move it elsewhere first if you want to keep it.',
			'"{folder}" already holds {count} workflow files. Mapping it in link mode will permanently delete them — they will not go to the trash and cannot be recovered. Move them elsewhere first if you want to keep them.',
			count,
			{ folder: folder, count: count }
		);

		window.N8nSync.confirmDestructive({
			title: t('n8n_sync', 'Delete these workflow files?'),
			text: msg,
			confirm: n('n8n_sync', 'Delete {count} file', 'Delete {count} files', count, { count: count }),
			onConfirm: function () {
				saveCard(card, true);
			},
			onCancel: function () {
				cardStatus(card, 'error', t('n8n_sync', 'Not saved — the folder still holds workflow files.'));
			}
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
			'Also delete the .n8n files this integration created in the folder?\n\n'
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

	// Per-field help + the ⓘ button markup. The CSS turns the span into a
	// hover/focus tooltip.
	//
	// THESE MUST STAY WORD-FOR-WORD IDENTICAL to $desc in
	// templates/mapping_settings.php. The server renders the existing cards and
	// this renders every card added after load, so a string changed in only one
	// place makes the same field explain itself two different ways — which is
	// exactly how the `mode` tip went on describing the removed `backup` mode
	// here after the template had dropped it. Same text, same translation key.
	var DESC = {
		tag: t('n8n_sync', 'Workflows with this tag in n8n appear in this folder. One tag per mapping.'),
		mode: t('n8n_sync', 'Link: a read-only pointer to n8n. Sync: the full workflow lives here and edits push back.'),
		folder: t('n8n_sync', 'The Nextcloud folder the workflows appear in.'),
		tf: t('n8n_sync', 'On: an ownerless Team Folder (groupfolders). Off: an admin-owned folder shared to the groups. Fixed once saved.'),
		groups: t('n8n_sync', 'Nextcloud groups the folder is shared with. Pick at least one, or no one can see it.'),
	};
	function info(tip) {
		var e = escapeHtml(tip);
		return ' <span class="n8n-sync-info" tabindex="0" role="note" aria-label="' + e + '" data-tip="' + e + '">'
			+ (ICONS.info || '') + '</span>';
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
			+     (ICONS.save || '') + '</button>'
			+   '<button type="button" class="button js-sync" title="' + t('n8n_sync', 'Sync') + '" aria-label="' + t('n8n_sync', 'Sync') + '">'
			+     (ICONS.sync || '') + '</button>'
			+   '<button type="button" class="button js-delete" title="' + t('n8n_sync', 'Delete') + '" aria-label="' + t('n8n_sync', 'Delete') + '">'
			+     (ICONS.delete || '') + '</button>'
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
