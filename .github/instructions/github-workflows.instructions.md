---
description: 'GitHub Actions workflow conventions for this repo'
applyTo: '.github/workflows/*.yml,.github/workflows/*.yaml,.github/actions/*/*.yml'
---
<!--
  SPDX-FileCopyrightText: 2026 Kelly Ferrone
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# GitHub Actions workflow conventions

Applies to CI workflows and composite actions. YAML formatting rules come from
[yaml.instructions.md](./yaml.instructions.md); this file is the Actions-specific
review rules. These are hard conventions in this repo — flag violations.

## Naming
- Every **job** has a `name` that summarises what it does.
- Every **step** has a `name`. Add an `id` only when a later step references its
  outputs (keep ids short, lowercase, hyphenated).

## No `${{ }}` inside `run:` scripts — bind to `env:` instead
GitHub interpolates `${{ }}` into the script *before* the shell runs — that mixes
templating with logic, is a shell-injection risk, and makes the step impossible to
run or debug outside Actions. Bind the expression to an `env:` entry on the step and
read the clean `$VAR` in bash. The step then works locally with the same env set.

```yaml
# Bad — expression woven into the script
- name: Tag image
  run: docker tag app:latest app:${{ github.sha }}

# Good — expression on env:, plain $VAR in the script (portable + injection-safe)
- name: Tag image
  env:
    SHA: ${{ github.sha }}
  run: docker tag app:latest "app:$SHA"
```
Prefer `env:` for static/derivable values too. This is the single most important
rule here.

## Keep `run:` blocks pure — comment *above* the step, not inside it
Put explanatory `#` comments as YAML comments above the step so the `run:` body
stays a clean, readable script. Don't narrate inside the script.

```yaml
# Wait for the DB to accept connections before migrating — the container reports
# ready before the socket is actually up.
- name: Wait for database
  run: |
    until pg_isready -h localhost; do sleep 1; done
```

## One function per step — don't build a mega-script
If a step does several logical things (build → sign → upload), split it into
separate `run:` steps, each doing one functional part with a clear tool and clear
inputs/outputs. Small steps are easier to read, cache, retry, and debug; a failure
points at the exact function. Pass data between them via step outputs (below).

## `GITHUB_ENV` only for flow-wide values; otherwise use step outputs
- Write to `GITHUB_ENV` **only** when a value is genuinely useful to the whole job
  (many later steps need it) — everything after it can see it, so it pollutes the
  env if overused.
- When data belongs to one producer→consumer hop, isolate it with a **step output**
  (`echo "key=value" >> "$GITHUB_OUTPUT"`, read as `${{ steps.<id>.outputs.key }}`)
  or a **job output**. Keep the shared env small and intentional.

## Don't guess action or tool versions — verify
LLMs (and humans) routinely pin stale majors. Before adding or bumping any
`uses:` pin, confirm the current version with the CLI, e.g.
`gh api repos/<owner>/<repo>/releases/latest --jq .tag_name`. Never assume `@vN`.

## Other conventions
- **Provision first, act second.** Group install/setup steps up front, then a
  readiness gate, then the actual work — don't stagger "install A → use A → install
  B → use B".
- **Invoke scripts as `bash path/to/x.sh`**, not via the executable bit.
- **Multiline output**: use a `cat` heredoc, not many `echo`s:
  ```yaml
  - name: Print summary
    run: |
      cat <<'EOF'
      line one
      line two
      EOF
  ```
- **Secrets**: never `echo` a secret; if a value is derived from one, `::add-mask::`
  it. Don't put secrets in step names or outputs.
