<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCP\Files\File;
use OCP\Files\Node;

/**
 * Filename codec for n8n workflow files.
 *
 * Two on-disk shapes are supported:
 *
 *   1. Clean (default)         <name>.n8n.json
 *   2. Id-suffixed (opt-in)    <name>.<id>.n8n.json
 *
 * The clean shape is the default user-facing layout. The id-suffixed shape
 * is an admin opt-in (`id_in_filename` AppConfig flag) for environments
 * where the Files Metadata API exposure over WebDAV ever regresses, or for
 * users who want the deep-link to be resolvable purely from the filename
 * (e.g. offline shortcut creation from a synced desktop client).
 *
 * Both shapes carry exactly the same metadata server-side via the Files
 * Metadata API \u2014 the filename is *only* a redundant carrier. See plan
 * \u00a75-D' "id resolution layers".
 *
 * Collision policy: when two workflows in the same n8n tag share a `name`
 * (n8n permits this), the first file gets the plain name and the ones after it
 * get an NC-style ` (1)`, ` (2)`, ... suffix — Nextcloud's own counter, which
 * starts at one. The chosen filename is what gets stored in metadata, so
 * subsequent pulls are stable and won't oscillate.
 *
 * ## TWO NAMES, AND THE DIFFERENCE MATTERS
 *
 * {@see parse()} returns both, because callers want opposite things from a
 * suffixed file:
 *
 *   `name`     the LOGICAL name, counter stripped — `Fleet Health`. What a pull
 *              matches a workflow's name against, so a mirror already wearing
 *              `(1)` is recognised as the same logical file next time round.
 *   `display`  the name AS WRITTEN, counter and all — `Fleet Health (1)`. What
 *              the user sees, and therefore what the JSON `name` and the n8n
 *              workflow have to say whenever NEXTCLOUD named the file.
 *
 * Which is authoritative is decided by WHERE THE GESTURE HAPPENED, and a copy
 * exercises both directions:
 *
 *   - Copied in Nextcloud → Nextcloud picked the name, counter included, so
 *     `display` is the name and it propagates to the JSON and to n8n. All three
 *     agree.
 *   - Copied in n8n → n8n permits two workflows with one name and Nextcloud does
 *     not permit two files with one name, so the counter is added to the FILENAME
 *     ONLY and `name` is what still matches the workflow. This is the single
 *     exception to *a name is one value living in three places*.
 *
 * This class is **pure logic**: no filesystem access, no DI dependencies,
 * trivial to unit test.
 */
final class FilenameCodec {
	/** Trailing extension shared by both shapes. */
	public const EXT = '.n8n.json';

	/**
	 * True when $name is a managed n8n workflow filename (ends in {@see EXT}).
	 * Pure string test — the single source of truth for "is this one of ours?".
	 */
	public static function isWorkflowName(string $name): bool {
		$name = self::canonicalise($name);
		return str_ends_with($name, self::EXT);
	}

	/**
	 * True when $name is one of ours **as the trash renamed it**. Nextcloud appends
	 * a deletion timestamp when a file is moved to the trash —
	 * `Old Name.n8n.json.d1712345678` — so {@see isWorkflowName}'s `str_ends_with`
	 * is FALSE for every trashed workflow file.
	 *
	 * That is not a hypothetical: it is half of why the trash-purge step never ran
	 * (see {@see \OCA\N8nSync\Listener\TrashPurgeHook}). The integration harness had
	 * documented the `.dNNNN` suffix for a long time; production code had not.
	 *
	 * The timestamp is required, not optional — a bare `.n8n.json` is
	 * {@see isWorkflowName}'s job, and accepting both here would let a live file
	 * match a trash-only predicate.
	 */
	public static function isTrashedWorkflowName(string $name): bool {
		return (bool)preg_match('/' . preg_quote(self::EXT, '/') . '\.d\d+$/', $name);
	}

	/**
	 * True when $node is a managed n8n workflow file: a {@see File} whose name
	 * ends in {@see EXT}. The one predicate the listeners/services share instead
	 * of open-coding `$node instanceof File && str_ends_with(...)` everywhere.
	 *
	 * `@psalm-assert-if-true` narrows $node to File in the caller's true branch
	 * (and, via the negation, after a `if (!isWorkflowFile($node)) { return; }`
	 * guard) so callers keep the flow-narrowing the inline `instanceof` gave them.
	 *
	 * @psalm-assert-if-true File $node
	 */
	public static function isWorkflowFile(?Node $node): bool {
		return $node instanceof File && self::isWorkflowName($node->getName());
	}

	/**
	 * What an n8n workflow id looks like in practice (verified against the
	 * live instance: e.g. `0oOA4iz0T0GRmICc`, `-7AgWuz-iwnhC4dktuGxS`,
	 * `PQfdkurMHf6SdR4w`). Mixed case alphanumeric plus `-` and `_`, length
	 * roughly 16-21. The lower bound of 12 is deliberately lax \u2014 we want to
	 * recognise an id segment, not validate it; n8n is the source of truth.
	 *
	 * The character class explicitly excludes `.` so we can never confuse
	 * an id with a name fragment that happened to contain dots.
	 */
	private const ID_RE = '/^[A-Za-z0-9_-]{12,32}$/';

	/**
	 * Parse a basename (or full path; we ignore everything before the last
	 * slash) into its components. Returns null if the basename does not
	 * end in {@see EXT}.
	 *
	 * Both clean and id-suffixed shapes are recognised; the id field is
	 * `null` for the clean shape and a non-empty string for the suffixed
	 * shape.
	 *
	 * @return array{name:string, id:?string, suffix:int, display:string}|null
	 *                                                                     `suffix` is the collision counter (0 for the canonical name,
	 *                                                                     1+ for "(N)" duplicates); `display` is the name with that
	 *                                                                     counter still on it.
	 */
	public static function parse(string $basename): ?array {
		$slash = strrpos($basename, '/');
		if ($slash !== false) {
			$basename = substr($basename, $slash + 1);
		}
		$basename = self::canonicalise($basename);
		if (!str_ends_with($basename, self::EXT)) {
			return null;
		}
		$stem = substr($basename, 0, -strlen(self::EXT));
		if ($stem === '') {
			return null;
		}

		// Try id-suffixed shape first: `<name>.<id>` where `<id>` matches
		// ID_RE. Walk from the rightmost dot so a name containing dots
		// (e.g. "v1.2 thing") still parses.
		$id = null;
		$name = $stem;
		$lastDot = strrpos($stem, '.');
		if ($lastDot !== false) {
			$candidate = substr($stem, $lastDot + 1);
			if (preg_match(self::ID_RE, $candidate)) {
				$id = $candidate;
				$name = substr($stem, 0, $lastDot);
			}
		}

		// Strip an optional " (N)" collision suffix off the *end* of the
		// resolved name so subsequent pulls can detect they're updating
		// the same logical file. The unstripped form is kept as `display`,
		// because a counter Nextcloud put there is part of the name the user
		// sees — see this class's docblock for which one a caller wants.
		$display = $name;
		$suffix = 0;
		if (preg_match('/^(?<base>.+) \\((?<n>\\d+)\\)$/', $name, $m)) {
			$suffix = (int)$m['n'];
			$name = $m['base'];
		}

		return ['name' => $name, 'id' => $id, 'suffix' => $suffix, 'display' => $display];
	}

	/**
	 * Fold NEXTCLOUD'S collision spelling into ours.
	 *
	 * There are two conventions for "that name is taken". Ours puts the counter on
	 * the logical name — `Board (1).n8n.json` — and {@see parse()} strips it.
	 * Nextcloud puts it before the LAST extension, because to Nextcloud our file is
	 * a `.json` called `Board.n8n`:
	 *
	 *     Board.n8n (1).json          <- Nextcloud's spelling
	 *     Board (1).n8n.json          <- ours
	 *
	 * That does not end in `.n8n.json`, so every predicate in this app answered
	 * "not ours". Confirmed on the live instance as `FooBoblicious.n8n (1).json`:
	 * no metadata, no workflow in n8n, and a file that looks managed and is not.
	 *
	 * We do not get to choose this name — Nextcloud's web client picks it, on our
	 * files, whenever a copy lands beside its source. Nor can we get ahead of it: the
	 * file HAS to exist under that name until the client has stat'd it, or the Files
	 * app reports the copy as missing (see
	 * {@see \OCA\N8nSync\BackgroundJob\ReconcileNameJob::canonicaliseSpelling()}).
	 *
	 * So it has to be read, and reading it is load-bearing rather than a courtesy. Any
	 * `(N)` costs one `\d+` here rather than a case per counter.
	 */
	public static function canonicalise(string $name): string {
		if (preg_match('/^(.+)\.n8n \((\d+)\)\.json$/', $name, $m) !== 1) {
			return $name;
		}
		$stem = $m[1];
		$counter = ' (' . $m[2] . ')';

		// THE ID SEGMENT STAYS LAST. {@see format()} composes the opt-in shape as
		// `<name> (N).<id>.n8n.json` — counter on the NAME, id immediately before the
		// extension — and {@see parse()} looks for the id at the last dot. Appending
		// the counter blindly would produce `Board.<id> (1).n8n.json`, whose id segment
		// reads as `<id> (1)`, matches nothing, and silently loses the identity on
		// exactly the gesture most likely to need it.
		$lastDot = strrpos($stem, '.');
		if ($lastDot !== false && preg_match(self::ID_RE, substr($stem, $lastDot + 1)) === 1) {
			return substr($stem, 0, $lastDot) . $counter . substr($stem, $lastDot) . self::EXT;
		}
		return $stem . $counter . self::EXT;
	}

	/**
	 * The name a managed file shows the user: its stem with any collision counter
	 * left on, and no id segment or extension. Empty string when $basename is not
	 * one of ours.
	 *
	 * THE ONE-LINER FOR "WHAT IS THIS FILE CALLED", so callers that want the visible
	 * name don't reach into {@see parse()} and take `name` — the counter-stripped
	 * field — by mistake.
	 */
	public static function displayName(string $basename): string {
		$parsed = self::parse($basename);
		return $parsed !== null ? trim($parsed['display']) : '';
	}

	/**
	 * True when $name is one of ours but spelled NEXTCLOUD'S way — `Board.n8n (1).json`
	 * rather than `Board (1).n8n.json`.
	 *
	 * {@see canonicalise()} folds that spelling on the way IN so every predicate reads
	 * it. This is the write-side question: should the file on disk be renamed?
	 */
	public static function isNextcloudSpelling(string $name): bool {
		return self::canonicalise($name) !== $name;
	}

	/**
	 * Build a filename for a workflow.
	 *
	 * @param string $name Workflow display name from n8n.
	 * @param string $id Workflow id from n8n.
	 * @param bool $idInFilename If true, embed the id segment.
	 * @param int $collisionIndex 0 = canonical filename, 1+ adds "(N)".
	 */
	public static function format(string $name, string $id, bool $idInFilename, int $collisionIndex = 0): string {
		$safe = self::sanitiseName($name);
		if ($safe === '') {
			// Fall back to id so we never produce just ".n8n.json".
			$safe = $id;
		}
		$stem = $safe;
		if ($collisionIndex > 0) {
			$stem .= ' (' . $collisionIndex . ')';
		}
		if ($idInFilename) {
			$stem .= '.' . $id;
		}
		return $stem . self::EXT;
	}

	/**
	 * Replace characters that are unsafe in NC/WebDAV filenames with `_`.
	 * Keep this conservative \u2014 we'd rather have a slightly munged but
	 * predictable name than fight every locale's edge cases.
	 *
	 * Specifically banned by NC default: `\\ / : * ? " < > |` and control
	 * characters. We also collapse runs of whitespace so users don't end
	 * up with awkward "Foo   bar" filenames just because the n8n name had
	 * a tab in it.
	 */
	private static function sanitiseName(string $name): string {
		$n = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name) ?? '';
		$n = preg_replace('/[\\\\\\/:\\*\\?"<>\\|]/u', '_', $n) ?? '';
		$n = preg_replace('/\\s+/u', ' ', $n) ?? '';
		$n = trim($n);
		// Strip a leading dot so the file isn't hidden on POSIX storages.
		if ($n !== '' && $n[0] === '.') {
			$n = '_' . substr($n, 1);
		}
		return $n;
	}
}
