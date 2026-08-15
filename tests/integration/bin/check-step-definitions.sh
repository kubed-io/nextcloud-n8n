#!/usr/bin/env bash
#
# Fast structural checks on the Behat step definitions, so mistakes that cost a FULL
# CI CYCLE each are caught in a second instead.
#
# Merged from the two siblings, because each had found a different failure:
#
#   1. DUPLICATE STEP PATTERN (nextcloud-grafana). Behat ignores the KEYWORD when
#      matching, so the same sentence under @Given and @When is ONE step registered
#      twice. Behat refuses the second and then fails EVERY scenario in the suite —
#      including ones that never mention it — reporting "already defined" against
#      whatever ran first. It reads as "the app is broken". One function may carry
#      several phrasings (that is how a gesture gets its past-tense pre-state form);
#      it may never carry one twice.
#
#   2. PARENTHESES IN A PLAIN-TEXT PATTERN (nextcloud-grafana). Behat reads `(...)`
#      as an optional group, so `n8n is not contacted (already deleted)` also matches
#      the bare `n8n is not contacted` and collides with it. Reported as an ambiguous
#      match on a line resembling neither pattern. The one legitimate use is the
#      optional plural, `file(s)`. This suite is overwhelmingly plain-text, so the
#      hazard is live here; it does not apply to the handful of regex-delimited
#      steps, where parentheses are the capture groups the step depends on.
#
#   3. UNDEFINED STEPS (nextcloud-penpot). A step renamed in a .feature file without
#      its definition becomes an undefined step. This suite does not yet run
#      `--strict`, so today that is not even a failure — it is a scenario that
#      silently proves nothing. Answering it here costs no services at all, and is
#      what makes turning `--strict` on later a non-event.
#
#      SCOPED TO WHAT CI ACTUALLY RUNS. @unbuilt, @blocked, @decision and @todo mark
#      specification that deliberately has no implementation — they are the point of
#      the spec-first style, not a defect. The tag list here MUST track the one the
#      integration workflow filters on; check-suites.sh already pins that expression.
#
# Runs in the PHP Quality job, which finishes in seconds. The integration matrix takes
# minutes across every leg and needs a live Nextcloud and a live n8n to say the same.

set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

python3 - "$root" <<'PY'
import re, sys, pathlib

root = pathlib.Path(sys.argv[1])
bootstrap = root / 'tests' / 'integration' / 'bootstrap'
features = sorted((root / 'features').rglob('*.feature'))

# Scenarios carrying any of these are specification, not implementation.
UNRUN = {'@todo', '@unbuilt', '@blocked', '@decision'}

fail = False

# ── the definitions ────────────────────────────────────────────────────────────
# TWO STYLES, AND THE GUARD MUST KNOW BOTH. This suite writes the overwhelming
# majority of steps as plain text (`@Given the app is enabled`, with Behat's
# `:name` placeholders) and a handful as regex (`@Then /^"([^"]*)" is gone$/`).
# Behat accepts either; a guard that knows only one reports every step of the
# other kind as undefined, which is a spectacularly unhelpful way to fail.
# What Behat's `:name` placeholder actually accepts: a quoted string, or a single
# non-space token. NOT multi-word unquoted text.
PLACEHOLDER = '(?:"[^"]*"|\'[^\']*\'|[^\\s"\']+)'

regex_re = re.compile(r'@(?:Given|When|Then)\s+/\^(.+?)\$/')
plain_re = re.compile(r'@(?:Given|When|Then)\s+(?!/)(\S.*?)\s*$')
patterns, seen, parens = [], {}, []
# METHOD NAMES, NOT ONLY STEP TEXT. Two traits composed into one FeatureContext
# may not both define a method name: PHP fatals on the collision before Behat
# reads a single step, so every matrix leg dies at once reporting a trait
# conflict rather than a test failure. Two steps can legitimately want the same
# obvious name (`theWorkflowStillExistsInN8n` was claimed by PurgeSteps and
# written again by TagSyncSteps), and nothing else catches it until CI does.
methods = {}
func_re = re.compile(r'^\s*(?:public|protected|private)\s+function\s+(\w+)\s*\(')
for php in sorted(bootstrap.rglob('*.php')):
    for line in php.read_text(encoding='utf-8').splitlines():
        m = func_re.match(line)
        if m:
            methods.setdefault(m.group(1), []).append(php.name)
for php in sorted(bootstrap.rglob('*.php')):
    for line in php.read_text(encoding='utf-8').splitlines():
        m = regex_re.search(line)
        if m:
            patterns.append(m.group(1))
            seen.setdefault(m.group(1), []).append(php.name)
            continue
        m = plain_re.search(line)
        if not m:
            continue
        # Plain text is literal, except Behat's `:name` placeholders — and a
        # one-line docblock closes on the same line, so `*/` is not step text.
        text = re.sub(r'\s*\*/\s*$', '', m.group(1))
        # `file(s)` is the sanctioned optional plural; anything else is a group
        # Behat will silently make optional.
        if re.search(r'\((?!s\))', text):
            parens.append(f'{php.name}: {text}')
        # `:name` AS BEHAT ACTUALLY MATCHES IT, not as `(.+)`.
        #
        # Behat's turnip placeholder accepts a quoted string or a single
        # non-space token — it does NOT match multi-word unquoted text. Modelling
        # it as `(.+)` made this check MORE PERMISSIVE THAN BEHAT, so it reported
        # every step resolved while three scenarios came back undefined in CI.
        # A guard that passes where the real thing fails is worse than no guard.
        # A LAMBDA, because re.sub parses the replacement for escapes and would
        # choke on the `\s` inside it.
        body = re.sub(r':\w+', lambda _: PLACEHOLDER,
                      re.escape(text).replace('\\:', ':'))
        # …and the sanctioned `file(s)` really IS an optional group, so compile it
        # as one. Escaped literally instead, a step declared `... file(s)` only
        # matches text carrying the parentheses, and every singular use of it in a
        # .feature file gets reported as undefined while Behat matches it fine.
        body = body.replace(r'\(s\)', 's?')
        patterns.append(body)
        seen.setdefault(body, []).append(php.name)

dupes = {p: f for p, f in seen.items() if len(f) > 1}
if dupes:
    fail = True
    print('✘ DUPLICATE STEP PATTERN — Behat ignores the keyword, so these register twice')
    print('  and fail the WHOLE suite, including scenarios that never mention them:')
    for p, files in sorted(dupes.items()):
        print(f'    {p}  ({", ".join(files)})')
    print('  One function may carry several phrasings; never the same phrasing twice.')

collisions = {n: f for n, f in methods.items() if len(set(f)) > 1}
if collisions:
    fail = True
    print('\u2718 TRAIT METHOD COLLISION — PHP fatals when the context composes these,')
    print('  so EVERY leg dies before a single step runs:')
    for n, files in sorted(collisions.items()):
        print(f'    {n}()  ({", ".join(sorted(set(files)))})')
    print('  Rename one. A step may be phrased freely; its method name must be unique.')

if parens:
    fail = True
    print('✘ PARENTHESES IN A PLAIN-TEXT STEP — Behat reads these as an OPTIONAL group,')
    print('  so the step also matches with the text removed and collides with the bare form:')
    for p in parens:
        print(f'    {p}')
    print('  Reword, or use a regex-delimited pattern. Only "file(s)" is sanctioned.')

compiled = [re.compile('^' + p + '$') for p in patterns]

# ── the steps the suite actually runs ──────────────────────────────────────────
#
# OUTLINE STEPS ARE EXPANDED, NOT SKIPPED. They used to be waved through on
# sight of a `<`, on the reasoning that a placeholder resolves per Examples row —
# which is true, and is precisely why they have to be checked per Examples row
# instead. Behat matches the SUBSTITUTED text, so a step that only ever appears
# inside an outline was never checked at all here.
#
# That is not hypothetical: `When <actor> syncs <scope>` sat in this repo behind
# a `:actor syncs :scope` definition that Behat cannot match (its `:name`
# placeholder takes a quoted string or one non-space token, never `the admin`),
# and this check reported everything resolved while three scenarios came back
# undefined in CI. A guard that passes where the real thing fails is worse than
# no guard, because it is trusted.
step_re = re.compile(r'^\s*(?:Given|When|Then|And|But)\s+(.*?)\s*$')
row_re = re.compile(r'^\s*\|(.*)\|\s*$')


def cells(line):
    return [c.strip() for c in row_re.match(line).group(1).split('|')]


def expansions(text, examples):
    """Every concrete form of a step, one per Examples row (or itself if plain)."""
    if '<' not in text or not examples:
        return [text]
    out = []
    for header, rows in examples:
        for row in rows:
            concrete = text
            for name, value in zip(header, row):
                concrete = concrete.replace(f'<{name}>', value)
            out.append(concrete)
    return out or [text]


# AN OUTLINE WITH NO `Examples` IS A SILENT HOLE, and it is how this check let a
# real break through: splitting a file lifted a Scenario Outline away from its
# Examples and left them behind, attached to the scenario above. The outline then
# ran with `<groups>` as a literal, and the orphaned tables turned a plain
# Scenario into an outline over columns it never used. Neither is visible to the
# undefined-step pass below, which skips any step still holding a `<placeholder>`.
outline_holes = []

undefined, ambiguous = [], []
for feature in features:
    lines = feature.read_text(encoding='utf-8').splitlines()
    feature_tags, tags = set(), set()
    # (runs, [steps], [(header, [rows])]) for the block being read
    blocks, cur = [], None
    for raw in lines:
        line = raw.strip()
        if line.startswith('@'):
            tags |= set(line.split())
            continue
        if line.startswith('Feature:'):
            feature_tags, tags = tags, set()
            cur = None
            continue
        if line.startswith(('Scenario:', 'Scenario Outline:')):
            cur = {'runs': not ((tags | feature_tags) & UNRUN), 'steps': [], 'ex': [],
                   'outline': line.startswith('Scenario Outline:'),
                   'title': line.split(':', 1)[1].strip()}
            blocks.append(cur)
            tags = set()
            continue
        if line.startswith('Background:'):
            # Only required if something in the file runs; judged after the read.
            cur = {'runs': None, 'steps': [], 'ex': []}
            blocks.append(cur)
            tags = set()
            continue
        if line.startswith('Examples:'):
            if cur is not None:
                cur['ex'].append(None)  # marker: the next table row is the header
            continue
        if not line or line.startswith('#'):
            continue
        if cur is None:
            continue
        if row_re.match(line):
            if cur['ex'] and cur['ex'][-1] is None:
                cur['ex'][-1] = (cells(line), [])
            elif cur['ex'] and isinstance(cur['ex'][-1], tuple):
                cur['ex'][-1][1].append(cells(line))
            continue
        m = step_re.match(line)
        if m:
            cur['steps'].append(m.group(1))

    for b in blocks:
        if b.get('outline') and not [e for e in b['ex'] if isinstance(e, tuple)]:
            outline_holes.append(f"{feature.relative_to(root / 'features')}: {b['title']}")

    any_runs = any(b['runs'] for b in blocks)
    for b in blocks:
        if b['runs'] is False:
            continue
        if b['runs'] is None and not any_runs:
            continue
        examples = [e for e in b['ex'] if isinstance(e, tuple)]
        for text in b['steps']:
            for concrete in expansions(text, examples):
                if '<' in concrete:
                    continue  # a placeholder with no Examples column to fill it
                hits = [p for c, p in zip(compiled, patterns) if c.match(concrete)]
                if not hits:
                    undefined.append(f'{feature.relative_to(root / "features")}: {concrete}')
                elif len(hits) > 1:
                    ambiguous.append(
                        f'{feature.relative_to(root / "features")}: {concrete}\n'
                        + '\n'.join(f'        matches: {h}' for h in hits)
                    )

if outline_holes:
    fail = True
    print('\u2718 SCENARIO OUTLINE WITH NO EXAMPLES — every step keeps its <placeholder>')
    print('  literally, and nothing else in this check can see it:')
    for h in outline_holes:
        print(f'    {h}')
    print('  Give it an Examples table, or make it a plain Scenario.')

# TWO DEFINITIONS MATCHING ONE STEP is a failure Behat reports at RUN time, and
# this check could not see it: it only ever asked whether a step matched at all.
# `the original is unchanged` was also matched by `the :key is unchanged` — the
# `:name` placeholder happily eats the word "original" — so the step was defined
# twice over and every scenario using it died with "Ambiguous match", after a
# full integration leg had booted a Nextcloud and an n8n to find out.
if ambiguous:
    fail = True
    print('\u2718 AMBIGUOUS STEPS — more than one definition matches these, which Behat')
    print('  reports as a failure at run time, after the whole stack has booted:')
    for a in ambiguous:
        print(f'    {a}')
    print('  Reword one of them. A `:name` placeholder matches any single token,')
    print('  so a short generic phrase can swallow a longer specific one.')

if undefined:
    fail = True
    print('✘ UNDEFINED STEPS in scenarios the matrix runs — these prove nothing today,')
    print('  and become hard failures the moment the run turns on --strict:')
    for u in undefined:
        print(f'    {u}')
    print('  Either add the definition, or tag the scenario as specification.')

if not fail:
    print(f'✓ step definitions: {len(patterns)} patterns, no duplicates, '
          f'{len(methods)} method names unique, '
          f'every runnable step defined across {len(features)} feature files')
sys.exit(1 if fail else 0)
PY
