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

// Inline an SVG glyph from img/icons/ — the single source of truth for the
// app's icons. These are trusted, app-owned files, safe to embed verbatim; the
// licence-comment header is stripped so only the <svg> reaches the DOM. The
// same files feed the Files-app bundle (src/files.js, via ?raw) and the cards
// built client-side (js/mapping-settings.js, via the data-icons attribute below).
$icons = [];
$icon = static function (string $name) use (&$icons): string {
	if (!array_key_exists($name, $icons)) {
		$path = __DIR__ . '/../img/icons/' . $name . '.svg';
		$svg = is_file($path) ? (string)file_get_contents($path) : '';
		$icons[$name] = trim((string)preg_replace('/^\s*<!--.*?-->\s*/s', '', $svg));
	}
	return $icons[$name];
};

// Renders a ⓘ info button with a hover/focus tooltip (styled in CSS).
$info = static function (string $tip) use ($icon): string {
	$t = \OCP\Util::sanitizeHTML($tip);
	return ' <span class="n8n-sync-info" tabindex="0" role="note" aria-label="' . $t . '" data-tip="' . $t . '">'
		. $icon('info')
		. '</span>';
};
?>
<div class="section">
<div id="n8n-sync-mappings" class="n8n-sync-mappings"
	data-groups="<?php p(json_encode($groups)); ?>"
	data-tf-available="<?php p($tfAvailable ? '1' : '0'); ?>"
	data-icons="<?php p(json_encode([
		'info' => $icon('info'),
		'save' => $icon('save'),
		'sync' => $icon('sync'),
		'delete' => $icon('delete'),
	])); ?>">
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
						<?php /* Immutable: the tag IS the mapping — it decides which
								 workflows the mapping owns, so re-pointing it would
								 silently hand the folder a different set. Shown as text
								 rather than a disabled input, because a disabled input
								 still invites typing and implies it might save. */ ?>
						<span class="n8n-sync-fixed js-n8n-tag" data-value="<?php p($m['n8n_tag']); ?>"><?php p($m['n8n_tag']); ?>
							<span class="n8n-sync-hint"><?php p($l->t('(fixed)')); ?></span>
						</span>
					</div>
					<div class="n8n-sync-field nf-mode">
						<label><?php p($l->t('Mode'));
			print_unescaped($info($desc['mode'])); ?></label>
						<?php /* Immutable: sync→link would strip every downloaded file
								 under the mapping, link→sync would export the lot at once.
								 Re-create the mapping to change it. */ ?>
						<span class="n8n-sync-fixed js-mode" data-value="<?php p($modeSel); ?>"><?php p($modeSel === 'sync' ? $l->t('Sync') : $l->t('Link')); ?>
							<span class="n8n-sync-hint"><?php p($l->t('(fixed)')); ?></span>
						</span>
					</div>
					<div class="n8n-sync-field nf-folder">
						<label><?php p($l->t('Folder'));
			print_unescaped($info($desc['folder'])); ?></label>
						<?php /* Immutable: re-pointing it would orphan everything already
								 mirrored into the old folder. */ ?>
						<span class="n8n-sync-fixed js-team-folder" data-value="<?php p($m['team_folder']); ?>"><?php p($m['team_folder']); ?>
							<span class="n8n-sync-hint"><?php p($l->t('(fixed)')); ?></span>
						</span>
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
							<?php print_unescaped($icon('save')); ?>
						</button>
						<button type="button" class="button js-sync" title="<?php p($l->t('Sync')); ?>" aria-label="<?php p($l->t('Sync')); ?>">
							<?php print_unescaped($icon('sync')); ?>
						</button>
						<button type="button" class="button js-delete" title="<?php p($l->t('Delete')); ?>" aria-label="<?php p($l->t('Delete')); ?>">
							<?php print_unescaped($icon('delete')); ?>
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
