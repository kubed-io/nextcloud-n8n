<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Classic "Test Connection" panel. It used to carry one button per writeback
 * channel; there is one channel now, so there is one button. JS + CSS are loaded
 * by AdminTest::getForm() via Util::addScript() / addStyle() so they pick up the
 * Nextcloud CSP nonce (inline <script> is blocked by the strict CSP).
 *
 * ESCAPING RULE FOR THIS FILE (same as sync_settings.php): a TRANSLATED string is
 * never concatenated into an HTML attribute through print_unescaped() — one straight
 * double quote from a translator would close the attribute early. Anything that has
 * to reach an attribute goes through p(), which sanitises with ENT_QUOTES.
 *
 * @var \OCP\IL10N $l
 */
?>
<div class="section">
	<h3 class="n8n-sync-test__heading"><?php p($l->t('Test Connection')); ?></h3>
	<div id="n8n-sync-test" class="n8n-sync-test-wrap">
		<button type="button" id="n8n-sync-test-btn" class="button">
			<?php p($l->t('Test connection')); ?>
		</button>
		<span id="n8n-sync-test-status" class="msg"></span>
	</div>
</div>
