# Security Policy

Thanks for taking the time to look at the security of **n8n Sync**. This file describes
how to report vulnerabilities and what to expect after you do.

## Supported versions

This app is pre-1.0 and ships fixes only on the latest release. Always update to the
newest version before reporting an issue — the bug may already be fixed.

| Version | Supported |
|---|---|
| Latest minor on `main` | ✅ |
| Anything older | ❌ |

The supported Nextcloud range is declared in [`appinfo/info.xml`](appinfo/info.xml)
(`dependencies/nextcloud` `min-version` / `max-version`). Versions outside that range are
out of scope.

## Reporting a vulnerability

**Do not open a public GitHub issue for a security report.**

Use [GitHub's private vulnerability reporting](https://github.com/kubed-io/n8n-sync/security/advisories/new)
on this repo. That channel is encrypted, only visible to maintainers, and lets us
coordinate a fix and a release before anything goes public.

Please include:

- A short description of the issue and its impact.
- Steps to reproduce, ideally with a minimal proof-of-concept.
- The version of n8n Sync, the Nextcloud version, and the PHP version you tested on.
- Any relevant logs, request/response samples, or screenshots.
- Your assessment of severity (best guess is fine).

If for some reason you cannot use GitHub's private advisories, open a minimal public
issue saying "I have a security report, please contact me" with no details, and a
maintainer will reach out to set up a private channel.

## What to expect

- **Acknowledgement** of your report — usually within a few days.
- **A triage decision** (confirmed / not-a-vuln / out-of-scope / needs-info) once we've
  reproduced or investigated.
- **A coordinated fix** in a private branch when the report is confirmed.
- **A release with the fix** and a public advisory once the fix is available.
- **Credit** to you in the advisory and release notes, unless you'd rather stay anonymous.

This is a small, volunteer-run project — we don't have a paid security team or a bounty
program. We do take reports seriously and will work with you in good faith.

## Scope

In scope:

- The PHP backend in [`lib/`](lib/) (`OCA\N8nSync\…`).
- The JS frontend in [`src/`](src/) and its built bundle in `dist/`.
- The release tarball produced by [`publish.yml`](.github/workflows/publish.yml).
- The CI workflows in [`.github/workflows/`](.github/workflows/) when they could leak
  secrets or be coerced into running untrusted code (e.g. `pull_request_target` misuse,
  unpinned actions running with elevated permissions).
- The `appinfo/info.xml` permissions and routes declared by this app.

Out of scope:

- Nextcloud server itself — report those to [Nextcloud's security team](https://nextcloud.com/security/).
- n8n itself — report those to [n8n's security team](https://docs.n8n.io/hosting/securing/security/).
- Vulnerabilities in third-party dependencies are tracked by Dependabot and `composer
  audit` / `npm audit`. A report is welcome if you've found one that is exploitable
  *through this app's specific usage* (i.e. not just "dep X has CVE Y").
- The homelab cluster this app happens to be developed in — it is not a production
  service that this project ships.

## Secrets policy

A handful of secrets are required to operate or release this app. They never live in
the repo:

- **n8n API key** — entered by the admin in the Nextcloud admin section; stored
  encrypted via `OCP\Security\ICrypto`. Never logged.
- **n8n webhook bearer token** — same handling as the API key.
- **GitHub App private key** — used by the release workflow to bypass branch protection
  on the version-bump commit. Stored as the `GH_APP_KEY` repo secret. Never echoed.
- **Future Nextcloud app store signing key** — when Chapter 3 lands, the signing key
  for app-store releases will be a repo secret. The corresponding `.csr` / `.crt` files
  may be committed; the `.key` never is.

If you spot a secret committed to the repo (current or historical), treat it as a
vulnerability and report it via the private channel above. It will be rotated.

## Security-related CI gates

These run on every PR into `main` and on every push to `main`. They are part of why a
PR gets blocked or a release gets held:

- **`composer audit`** — fails on any advisory in PHP deps.
- **`npm audit --omit=dev --audit-level=high`** — fails on high-or-above JS deps.
- **Psalm** (PHP static analysis) — uploads SARIF to the Security tab; new findings
  block merge.
- **CodeQL** (JS / TS) — uploads to the Security tab; new findings block merge.
- **Dependabot** (planned, [Chapter 2 §13](saga/Chapter_2_Pretty_Package.md)) — alerts,
  security updates, and version updates for `composer`, `npm`, and `github-actions`.
- **Secret scanning + push protection** (planned, [Chapter 2 §13](saga/Chapter_2_Pretty_Package.md))
  — GitHub-side, blocks known secret formats at push time.

If a Quality gate is failing on a PR that purports to fix a vulnerability, **fix the
gate** rather than bypassing it. The gates are how we know the fix is sound.

## Disclosure timeline

We follow standard coordinated disclosure: a public advisory is published once a fix
has shipped in a tagged release, or 90 days after confirmation, whichever comes first.
We will work with you on a tighter timeline if active exploitation is reported.

Thanks again for helping keep this project safe.
