<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Classic "Test Connection" panel: one button per channel (REST API +
 * Webhook). JS + CSS are loaded by AdminTest::getForm() via Util::addScript() /
 * addStyle() so they pick up the Nextcloud CSP nonce (inline <script> is
 * blocked by the strict CSP).
 *
 * @var \OCP\IL10N $l
 * @var array{webhook_enabled: bool} $_
 */
$webhookEnabled = (bool)($_['webhook_enabled'] ?? false);
?>
<div class="section">
	<h3 class="n8n-sync-test__heading"><?php p($l->t('Test Connection')); ?></h3>
	<div id="n8n-sync-test" class="n8n-sync-test-wrap">
		<button type="button" id="n8n-sync-test-btn" class="button">
			<?php p($l->t('Test API')); ?>
		</button>
		<span id="n8n-sync-test-status" class="msg"></span>
	</div>
	<div class="n8n-sync-test-wrap">
		<?php /* Same rule as sync_settings.php: never concatenate a TRANSLATED string
		         into an attribute through print_unescaped() — one straight double quote
		         from a translator closes the attribute early. `p()` uses ENT_QUOTES. */ ?>
		<button type="button" id="n8n-sync-webhook-btn" class="button"
			<?php if (!$webhookEnabled) { ?>disabled title="<?php p($l->t('Enable and save the Webhook channel above to test it.')); ?>"<?php } ?>>
			<?php p($l->t('Test webhook')); ?>
		</button>
		<span id="n8n-sync-webhook-status" class="msg"></span>
	</div>
</div>
