<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

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
 * (n8n permits this), the second through Nth file get an NC-style `(2)`,
 * `(3)`, ... suffix. The chosen filename is what gets stored in metadata,
 * so subsequent pulls are stable and won't oscillate.
 *
 * This class is **pure logic**: no filesystem access, no DI dependencies,
 * trivial to unit test.
 */
final class FilenameCodec {
	/** Trailing extension shared by both shapes. */
	public const EXT = '.n8n.json';

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
	 * @return array{name:string, id:?string, suffix:int}|null
	 *                                                         `suffix` is the collision counter (0 for the canonical name,
	 *                                                         1+ for "(N)" duplicates).
	 */
	public static function parse(string $basename): ?array {
		$slash = strrpos($basename, '/');
		if ($slash !== false) {
			$basename = substr($basename, $slash + 1);
		}
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
		// the same logical file.
		$suffix = 0;
		if (preg_match('/^(?<base>.+) \\((?<n>\\d+)\\)$/', $name, $m)) {
			$suffix = (int)$m['n'];
			$name = $m['base'];
		}

		return ['name' => $name, 'id' => $id, 'suffix' => $suffix];
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
