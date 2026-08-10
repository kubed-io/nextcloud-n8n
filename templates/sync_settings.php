<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — all action buttons in one place:
 *   • Manual bulk sync: ⭱ Sync to n8n / ⭳ Sync from n8n
 *   • Connection tests: Test API / Test webhook (folded in from the old
 *     standalone "Test Connection" panel; wired by admin-test.js).
 *
 * @var array{status: array{pull: array<string,mixed>, push: array<string,mixed>}, webhook_enabled: bool} $_
 * @var \OCP\IL10N $l
 */

$pull = $_['status']['pull'] ?? [];
$push = $_['status']['push'] ?? [];
$webhookEnabled = (bool)($_['webhook_enabled'] ?? false);

/** One-line "last: ..." summary, with a queued/running lead-in. */
$summary = static function (array $rec) use ($l): string {
	if (empty($rec) || empty($rec['finished_at'])) {
		$last = $l->t('last: never');
	} else {
		// "synced" is every file reconciled; "unchanged" is the subset that already
		// matched n8n and so was not rewritten. Shown separately because a pull that
		// touched nothing is the normal, healthy result and used to read as N updates.
		$unchanged = (int)($rec['unchanged'] ?? 0);
		$counters = sprintf(
			'%d %s · %d %s',
			(int)($rec['succeeded'] ?? 0),
			$l->t('synced'),
			(int)($rec['failed'] ?? 0),
			$l->t('errors'),
		);
		if ($unchanged > 0) {
			$counters .= sprintf(' · %d %s', $unchanged, $l->t('unchanged'));
		}
		$last = $l->t('last: %1$s · %2$s', [(string)$rec['finished_at'], $counters]);
		if (!empty($rec['message'])) {
			$last .= ' · ' . (string)$rec['message'];
		}
	}
	$status = $rec['status'] ?? null;
	if ($status === 'queued') {
		return $l->t('Queued…') . ' · ' . $last;
	}
	if ($status === 'running') {
		return $l->t('Running…') . ' · ' . $last;
	}
	return $last;
};
?>
<div class="section">
<div id="n8n-sync-manual" class="n8n-sync-manual">
	<h3><?php p($l->t('Sync Actions')); ?></h3>

	<p class="settings-hint">
		<?php p($l->t('Run a one-shot bulk sync at any time. These always work regardless of the automatic Sync Settings above.')); ?>
	</p>

	<div class="n8n-sync-manual__row" data-direction="push">
		<button type="button" class="button js-run"><?php p($l->t('Sync to n8n')); ?></button>
		<span class="n8n-sync-manual__last js-last"><?php p($summary($push)); ?></span>
		<span class="n8n-sync-manual__hint"><?php p($l->t('(two-way sync mappings only)')); ?></span>
	</div>

	<div class="n8n-sync-manual__row" data-direction="pull">
		<button type="button" class="button js-run"><?php p($l->t('Sync from n8n')); ?></button>
		<span class="n8n-sync-manual__last js-last"><?php p($summary($pull)); ?></span>
	</div>

	<div class="n8n-sync-manual__footer">
		<span id="n8n-sync-manual-status" class="msg"></span>
	</div>

	<p class="settings-hint n8n-sync-actions__sep">
		<?php p($l->t('Check that Nextcloud can reach n8n — these just test the connection, nothing is synced.')); ?>
	</p>

	<div id="n8n-sync-test" class="n8n-sync-test-wrap">
		<button type="button" id="n8n-sync-test-btn" class="button"><?php p($l->t('Test API')); ?></button>
		<span id="n8n-sync-test-status" class="msg"></span>
	</div>
	<div class="n8n-sync-test-wrap">
		<button type="button" id="n8n-sync-webhook-btn" class="button"
			<?php if (!$webhookEnabled) {
				print_unescaped('disabled title="' . $l->t('Enable the Webhook channel above (and save) to test it.') . '"');
			} ?>><?php p($l->t('Test webhook')); ?></button>
		<span id="n8n-sync-webhook-status" class="msg"></span>
	</div>
</div>
</div>
