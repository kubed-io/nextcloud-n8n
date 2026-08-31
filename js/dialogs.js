/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * THE ONE CONFIRMATION EVERY ADMIN SURFACE IN THIS APP USES.
 *
 * Loaded before `mapping-settings.js` and `sync-settings.js` on their settings
 * sections, so a modal added to either is the same modal — same shape, same
 * button order, same destructive styling. It exists because the panel had two
 * answers to "are you sure": a browser `window.confirm` for deleting a mapping
 * and nothing at all for the dashboard purge, which is the same question asked
 * twice in two voices.
 *
 * Vanilla, unbundled, and a `window` global on purpose: `js/` is served verbatim
 * with no build step, so the two page scripts cannot import from each other.
 *
 * Ported from `kubed-io/nextcloud-penpot`, including the latch below, which was
 * paid for the hard way there.
 */
(function () {
	'use strict';

	window.N8nSync = window.N8nSync || {};

	/**
	 * Ask a destructive question, and act on the answer AT MOST ONCE.
	 *
	 * ## THE NATIVE DIALOG, AND WHY IT IS THE RIGHT ONE
	 *
	 * `OC.dialogs.confirmDestructive` is not the browser's alert box and not the
	 * old jQuery one either: in Nextcloud 34 it is built on the same `DialogBuilder`
	 * the Vue components use, so it inherits the instance's theming for free. Read
	 * out of the shipped `core-main.js`, not assumed. The option shape below —
	 * `YES_NO_BUTTONS`, an explicit destructive verb, `confirmClasses: 'error'` —
	 * is copied from the Files app's own delete confirmation.
	 *
	 * ## THE ONCE-ONLY LATCH IS NOT DEFENSIVE PROGRAMMING. IT IS A CORE BUG.
	 *
	 * `confirmDestructive` finishes with:
	 *
	 *     .build().show().then(() => { callback.clicked || callback(false) })
	 *
	 * meaning "if no button was pressed, treat it as a no". But the button
	 * callbacks it built for `YES_NO_BUTTONS` come from `_getLegacyButtons`, which
	 * records the press as:
	 *
	 *     callback._clicked = true
	 *
	 * `clicked` is set on one path and `_clicked` on the other, so the guard never
	 * matches and EVERY answer is followed by a second, spurious `callback(false)`
	 * the moment the dialog closes.
	 *
	 * A cancel branch that merely returns hides it — which is why the sibling's
	 * mapping delete looked fine for a week. A cancel branch that reports anything
	 * shows it: confirming the purge flashed "Not saved" over a mapping that was
	 * being created successfully. Found by hand, on a live instance.
	 *
	 * So the latch lives here rather than in each caller, and no modal in this app
	 * can be written wrong in the same way again.
	 *
	 * @param {object} opts
	 * @param {string} opts.title       dialog heading
	 * @param {string} opts.text        the body — say what is at stake, in numbers
	 * @param {string} opts.confirm     the destructive verb, not "Yes"
	 * @param {string} [opts.cancel]    defaults to "Cancel"
	 * @param {Function} opts.onConfirm
	 * @param {Function} [opts.onCancel]
	 */
	window.N8nSync.confirmDestructive = function (opts) {
		var answered = false;

		OC.dialogs.confirmDestructive(
			opts.text,
			opts.title,
			{
				type: OC.dialogs.YES_NO_BUTTONS,
				confirm: opts.confirm,
				// Ignored by the current `_getLegacyButtons`, which styles the confirm
				// `primary` regardless — kept because it is what core's own callers
				// pass, so this reads as the same call they make rather than a variant.
				confirmClasses: 'error',
				cancel: opts.cancel || t('n8n_sync', 'Cancel')
			},
			function (ok) {
				if (answered) {
					return;
				}
				answered = true;

				if (ok) {
					opts.onConfirm();
				} else if (opts.onCancel) {
					opts.onCancel();
				}
			}
		);
	};
}());
