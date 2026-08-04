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
# minutes across four legs and needs a live Nextcloud and a live n8n to say the same.

set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

python3 - "$root" <<'PY'
import re, sys, pathlib

root = pathlib.Path(sys.argv[1])
bootstrap = root / 'tests' / 'integration' / 'bootstrap'
features = sorted((root / 'features').glob('*.feature'))

# Scenarios carrying any of these are specification, not implementation.
UNRUN = {'@todo', '@unbuilt', '@blocked', '@decision'}

fail = False

# ── the definitions ────────────────────────────────────────────────────────────
# TWO STYLES, AND THE GUARD MUST KNOW BOTH. This suite writes the overwhelming
# majority of steps as plain text (`@Given the app is enabled`, with Behat's
# `:name` placeholders) and a handful as regex (`@Then /^"([^"]*)" is gone$/`).
# Behat accepts either; a guard that knows only one reports every step of the
# other kind as undefined, which is a spectacularly unhelpful way to fail.
regex_re = re.compile(r'@(?:Given|When|Then)\s+/\^(.+?)\$/')
plain_re = re.compile(r'@(?:Given|When|Then)\s+(?!/)(\S.*?)\s*$')
patterns, seen, parens = [], {}, []
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
        body = re.sub(r':\w+', '(.+)', re.escape(text).replace('\\:', ':'))
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

if parens:
    fail = True
    print('✘ PARENTHESES IN A PLAIN-TEXT STEP — Behat reads these as an OPTIONAL group,')
    print('  so the step also matches with the text removed and collides with the bare form:')
    for p in parens:
        print(f'    {p}')
    print('  Reword, or use a regex-delimited pattern. Only "file(s)" is sanctioned.')

compiled = [re.compile('^' + p + '$') for p in patterns]

# ── the steps the suite actually runs ──────────────────────────────────────────
step_re = re.compile(r'^\s*(?:Given|When|Then|And|But)\s+(.*?)\s*$')
undefined = []
for feature in features:
    tags, in_scenario, runs = set(), False, False
    background_gaps, any_runs = [], False
    # A TAG ON THE `Feature:` LINE APPLIES TO EVERY SCENARIO BELOW IT, and
    # uninstall.feature uses exactly that to mark a whole file @blocked. Read
    # without this, all seven of its steps look like undefined steps in live
    # scenarios — a false report on a file the runner has never once executed.
    feature_tags = set()
    for raw in feature.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if line.startswith('@'):
            tags |= set(line.split())
            continue
        if line.startswith(('Scenario:', 'Scenario Outline:')):
            in_scenario, runs = True, not ((tags | feature_tags) & UNRUN)
            any_runs = any_runs or runs
            tags = set()
            continue
        if line.startswith('Feature:'):
            feature_tags = tags
            in_scenario, runs = True, None
            tags = set()
            continue
        if line.startswith('Background:'):
            # A BACKGROUND IS ONLY REQUIRED IF SOMETHING IN ITS FILE RUNS. It runs
            # once per scenario, so in a file that is entirely specification it never
            # runs at all — and demanding its steps be implemented would report false
            # failures on a suite CI is happily green on. Held aside and judged after
            # the file is read.
            in_scenario, runs = True, None
            tags = set()
            continue
        if line.startswith(('Examples:', '#')) or not line:
            continue
        if not in_scenario or runs is False:
            continue
        m = step_re.match(line)
        if not m:
            continue
        text = m.group(1)
        if '<' in text:  # a Scenario Outline placeholder; resolved per example row
            continue
        if not any(c.match(text) for c in compiled):
            (background_gaps if runs is None else undefined).append(f'{feature.name}: {text}')
    if any_runs:
        undefined.extend(background_gaps)

if undefined:
    fail = True
    print('✘ UNDEFINED STEPS in scenarios the matrix runs — these prove nothing today,')
    print('  and become hard failures the moment the run turns on --strict:')
    for u in undefined:
        print(f'    {u}')
    print('  Either add the definition, or tag the scenario as specification.')

if not fail:
    print(f'✓ step definitions: {len(patterns)} patterns, no duplicates, '
          f'every runnable step defined across {len(features)} feature files')
sys.exit(1 if fail else 0)
PY
