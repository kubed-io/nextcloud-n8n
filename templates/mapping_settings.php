<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Folder-mapping admin UI. One **card per mapping** (a repeating form, not a
 * table). Each field label carries a ⓘ info button (CSS tooltip) explaining it.
 * Server-renders existing cards; JS handles add/save/sync/delete.
 *
 * Card grid: tag + mode on row 1, folder + team-folder on row 2 (left two
 * columns); groups picker spans both rows on the right; Save/Sync/Delete below.
 *
 * @var array{mappings: list<array<string,mixed>>, groups: list<string>, team_folders_available: bool} $_
 * @var \OCP\IL10N $l
 */

/** @var list<array<string,mixed>> $mappings */
$mappings = $_['mappings'] ?? [];
/** @var list<string> $groups */
$groups = $_['groups'] ?? [];
/** @var bool $tfAvailable */
$tfAvailable = (bool)($_['team_folders_available'] ?? false);

// Per-field help, shown via the ⓘ tooltip on each label.
$desc = [
	'tag' => $l->t('Workflows carrying this tag in n8n sync into this folder. One tag per mapping.'),
	'mode' => $l->t('Link: a read-only pointer to n8n. Sync: the full workflow JSON lives here and edits push back. Backup: a read-only copy (edits only ever come from n8n).'),
	'folder' => $l->t('Name of the Nextcloud folder the workflows appear in.'),
	'tf' => $l->t('On = an ownerless Team Folder (groupfolders). Off = a folder in the admin account shared to the groups. Fixed once saved.'),
	'groups' => $l->t('Which Nextcloud groups the folder is shared with. Pick at least one — otherwise no one can see it.'),
];

// Renders a ⓘ info button with a hover/focus tooltip (styled in CSS).
$info = static function (string $tip): string {
	$t = \OCP\Util::sanitizeHTML($tip);
	return ' <span class="n8n-sync-info" tabindex="0" role="note" aria-label="' . $t . '" data-tip="' . $t . '">'
		. '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>'
		. '</span>';
};
?>
<div class="section">
<div id="n8n-sync-mappings" class="n8n-sync-mappings"
	data-groups="<?php p(json_encode($groups)); ?>"
	data-tf-available="<?php p($tfAvailable ? '1' : '0'); ?>">
	<h3 class="n8n-sync-mappings__heading"><?php p($l->t('Folder mappings')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Each mapping projects tagged n8n workflows into a shared Nextcloud folder. Hover the ⓘ on a field for details.')); ?>
		<?php if (!$tfAvailable): ?>
			<strong><?php p($l->t('Team Folders app not enabled — admin-owned folders only.')); ?></strong>
		<?php endif; ?>
	</p>

	<div class="n8n-sync-mappings__list">
		<?php foreach ($mappings as $m): ?>
			<?php
			$selectedGroups = $m['nc_groups'] ?? [];
			$useTf = (bool)($m['use_team_folder'] ?? true);
			$modeSel = ($m['mode'] === 'link') ? 'link' : 'sync';
			?>
			<div class="n8n-sync-mappings__card" data-id="<?php p($m['id']); ?>">
				<div class="n8n-sync-mappings__grid">
					<div class="n8n-sync-field nf-tag">
						<label><?php p($l->t('n8n tag'));
			print_unescaped($info($desc['tag'])); ?></label>
						<input type="text" class="js-n8n-tag" value="<?php p($m['n8n_tag']); ?>" placeholder="nextcloud:tasking" />
					</div>
					<div class="n8n-sync-field nf-mode">
						<label><?php p($l->t('Mode'));
			print_unescaped($info($desc['mode'])); ?></label>
						<select class="js-mode">
							<option value="sync" <?php if ($modeSel === 'sync') {
								print_unescaped('selected');
							} ?>><?php p($l->t('Sync')); ?></option>
							<option value="link" <?php if ($modeSel === 'link') {
								print_unescaped('selected');
							} ?>><?php p($l->t('Link')); ?></option>
						</select>
					</div>
					<div class="n8n-sync-field nf-folder">
						<label><?php p($l->t('Folder'));
			print_unescaped($info($desc['folder'])); ?></label>
						<input type="text" class="js-team-folder" value="<?php p($m['team_folder']); ?>" placeholder="n8n" />
					</div>
					<div class="n8n-sync-field nf-tf">
						<label class="n8n-sync-checkbox"><input type="checkbox" class="js-use-team-folder" <?php if ($useTf) {
							print_unescaped('checked');
						} ?> disabled /> <?php p($l->t('Team Folder'));
			print_unescaped($info($desc['tf'])); ?></label>
					</div>
					<div class="n8n-sync-field nf-groups">
						<label><?php p($l->t('Groups'));
			print_unescaped($info($desc['groups'])); ?></label>
						<div class="js-groups n8n-sync-groups">
							<?php foreach ($groups as $g): ?>
								<label class="n8n-sync-groups__item">
									<input type="checkbox" value="<?php p($g); ?>" <?php if (in_array($g, $selectedGroups, true)) {
										print_unescaped('checked');
									} ?> /> <?php p($g); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="n8n-sync-mappings__actions">
						<button type="button" class="button js-save" title="<?php p($l->t('Save')); ?>" aria-label="<?php p($l->t('Save')); ?>">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
						</button>
						<button type="button" class="button js-sync" title="<?php p($l->t('Sync')); ?>" aria-label="<?php p($l->t('Sync')); ?>">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l1.46 1.46A6.94 6.94 0 0 0 19 13c0-3.87-3.13-7-7-7zm0 12c-2.76 0-5-2.24-5-5 0-.65.13-1.26.36-1.83L5.9 9.71A6.94 6.94 0 0 0 5 13c0 3.87 3.13 7 7 7v3l4-4-4-4z"/></svg>
						</button>
						<button type="button" class="button js-delete" title="<?php p($l->t('Delete')); ?>" aria-label="<?php p($l->t('Delete')); ?>">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
						</button>
						<span class="js-card-status"></span>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="n8n-sync-mappings__footer">
		<button type="button" id="n8n-sync-mappings-add" class="button">
			+ <?php p($l->t('Add mapping')); ?>
		</button>
		<span id="n8n-sync-mappings-status" class="msg"></span>
	</div>
</div>
</div>
