# Chapter 3 — Showtime

> **Prerequisite:** Chapter 2 (Pretty Package) is functionally complete *to Kelly's
> satisfaction* — the app does everything it should, the safe-refactor work (Ch2 §14) has
> covered the edge cases, and a clean, signed, versioned release tarball comes out of the
> pipeline. There is no fixed checklist for "done"; it's done when it's ready for the market.

The app works. It packages. Chapter 2 was about *function* — making it do the right thing and
making it safe to change. Chapter 3 is about *presence* — getting it onto
[apps.nextcloud.com](https://apps.nextcloud.com), the official Nextcloud app store, so anyone
can install it with one click.

But there's a hinge between the two, and it deserves to be named: **branding.** The moment the
app is functionally ready for the market, the work pivots from *what it does* to *what it is* —
its name, its face, its story. That pivot starts on the tail end of Chapter 2 and is the first
thing we do in Chapter 3. A good transition makes a good story.

---

## 3.1 Branding — from a functional app to a product

This is the transition. It begins the instant Kelly decides the app is **fully functional and
usable for the market** (the close of the Chapter 2 refactor work) and carries us into the
store submission below. Everything mechanical in §3.2+ *consumes the assets this phase
produces* — the description, the screenshots, the icon, the name. Brand first; submit second.

Deliverables (☐):

- **Name & tagline.** The app id stays `n8n_sync`; the display **name** is "n8n Sync". Land a
  one-line **summary** (the `<summary>` in `info.xml`, shown under the title in the store) that
  says what it is and who it's for — e.g. "Your n8n workflows as native Nextcloud files."
- **Identity / icon.** The app needs its **own** store + app-list icon, distinct from the n8n
  workflow *mimetype* icon (`img/n8n.svg`, which marks files). Pick an accent colour. This is
  the face users scan in the store grid and the Files sidebar.
- **Description copy.** Replace the Phase-0 placeholder `<description>` with real, benefit-led
  store copy — the rewritten README intro (Modes, Move/Copy lifecycle) is the source material.
  This is the body of the store listing; write it for a user deciding whether to install.
- **Screenshots.** The visual proof, telling the story in 3–4 frames: the Files app showing
  `.n8n.json` files with the n8n icon + mode tags; the admin Settings (mappings + sync); a
  click opening a workflow in n8n. ≥1 HTTPS URL, each ≤2 MiB (store rule).
- **Store presentation.** Category (`integration`), keywords, and the first-impression order
  of the listing.
- **Repo branding.** README header / banner, the GitHub social-preview image + About blurb +
  topics, and a consistent voice across README ↔ store ↔ changelog.

**Gate:** none of this should start while the model is still in flux — branding a moving target
wastes the work. It begins when Kelly calls the function done. (It's fine for the very tail of
Chapter 2 to bleed into early branding — that overlap *is* the transition.)

The outputs here flow straight into the store mechanics: **description + summary + screenshots →
`info.xml` (§3.2 Step 1) and store registration (§3.2 Step 3)**, and the **icon** ships in the
tarball.

---

## 3.2 One-time setup (do once, never again)

### Step 1 — Fix `appinfo/info.xml`

The app store validates `info.xml` against an XSD schema. Several fields need attention
before a submission will be accepted:

| Field | Current | Fix needed |
|---|---|---|
| `licence` | `agpl` | ✅ already correct — the official apps (deck, integration_openai) use the **`agpl`** short form in `info.xml`, NOT the SPDX string. (SPDX `AGPL-3.0-or-later` belongs in `package.json`/`composer.json`/source headers + the root `LICENSE` file — all already present.) |
| `bugs` | ~~`kubed-io/nextcloud`~~ → `kubed-io/nextcloud-n8n/issues` | ✅ fixed |
| `repository` | ~~missing~~ → added (`…/nextcloud-n8n.git`) | ✅ fixed |
| `description` | placeholder copy ("Phase 0 skeleton") | Write real user-facing copy |
| `screenshot` | missing | Add at least one HTTPS screenshot URL (≤2 MiB each) |

The store requires at minimum: `id`, `name`, `summary`, `description` (real English copy),
`version`, `licence` (the `agpl` short form is accepted), `author`, `bugs` (URL), and
`dependencies/nextcloud` with both
`min-version` and `max-version`.

> The `summary`, `description`, and `screenshot` values are **branding outputs** (§3.1) — this
> step just drops the finished assets into `info.xml`. Don't write store copy here; write it in
> the branding phase and paste it in.

### Step 2 — Generate a signing key and submit the CSR

The app store requires every release to be cryptographically signed. This is the gating
step — the Nextcloud team must countersign your certificate before you can register the app.

```sh
mkdir -p ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 \
  -keyout ~/.nextcloud/certificates/n8n_sync.key \
  -out ~/.nextcloud/certificates/n8n_sync.csr \
  -subj "/CN=n8n_sync"
```

Then open a PR to [github.com/nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
adding your `n8n_sync.csr` file. Include a link to the public source repo in the PR description.
The Nextcloud team signs and returns `n8n_sync.crt`. **Keep `n8n_sync.key` secret; never commit it.**

This step can take a few days. Start it early — it blocks registration.

### Step 3 — Register the app on the store

Once you have `n8n_sync.crt`, go to [apps.nextcloud.com](https://apps.nextcloud.com),
create an account, and register the app by providing:

- Your certificate contents (`cat ~/.nextcloud/certificates/n8n_sync.crt`)
- A ownership signature:
  ```sh
  echo -n "n8n_sync" \
    | openssl dgst -sha512 -sign ~/.nextcloud/certificates/n8n_sync.key \
    | openssl base64
  ```

This is a one-time claim that proves you hold the private key for the app id `n8n_sync`.

---

## 3.3 Per-release: sign and upload

Every release needs two things beyond what Chapter 2's pipeline already produces:

### Sign the release tarball

After the tarball is built, sign it with your private key:

```sh
openssl dgst -sha512 \
  -sign ~/.nextcloud/certificates/n8n_sync.key \
  dist/n8n_sync-X.Y.Z.tar.gz \
  | openssl base64
```

This signature is submitted alongside the tarball URL when uploading to the store. It is
separate from (and in addition to) the `appinfo/signature.json` inside the tarball.

### Sign the app contents (`appinfo/signature.json`)

The tarball itself must contain a `signature.json` in `appinfo/` that covers every file in
the app. This is generated by Nextcloud's own `occ` tool against an extracted copy of the app:

```sh
php occ integrity:sign-app \
  --privateKey=~/.nextcloud/certificates/n8n_sync.key \
  --certificate=~/.nextcloud/certificates/n8n_sync.crt \
  --path=/path/to/extracted/n8n_sync
```

This needs to happen **before** the tarball is created. The publish workflow (Chapter 2)
needs a signing step inserted between **Build** and **Package**.

The private key must be available in CI — store it as a GitHub Actions secret (e.g.
`NC_APP_PRIVATE_KEY`) and write it to a temp file during the workflow run. The certificate
is not secret and can live in the repo under `appinfo/` or a `certs/` folder.

### Upload the release to the store

After GitHub creates the release (Chapter 2's `softprops/action-gh-release` step), upload
to the store via its API:

```sh
curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
  -H "Authorization: Token <store-api-token>" \
  -H "Content-Type: application/json" \
  -d '{
    "download": "https://github.com/<repo>/releases/download/vX.Y.Z/n8n_sync-X.Y.Z.tar.gz",
    "signature": "<base64 tarball signature from above>",
    "nightly": false
  }'
```

The store downloads and validates the tarball itself — it must be publicly reachable. The
GitHub release asset URL works.

---

## 3.4 App store rules checklist

Things that must be true or the app will be rejected / removed:

- **License:** AGPL-3.0-or-later (or compatible). Already true; just fix the `licence` field format.
- **Name:** must not contain the word "Nextcloud". `n8n Sync` is fine.
- **APIs:** must only use public Nextcloud APIs. No private/internal class usage.
- **Uninstall:** must clean up completely on disable/remove (no leftover DB tables, config, or files).
- **Performance:** must not crash Nextcloud, consume excessive resources, or degrade unrelated features.
- **Contact:** must provide a bug tracker URL in `info.xml` (`<bugs>`). Already there; just needs the right URL.
- **Security:** Nextcloud may audit apps unannounced. Malicious intent = 2-year ban from all NC infrastructure.
- **CHANGELOG.md:** must follow Keep a Changelog format (`## X.Y.Z` headers matching the version in `info.xml`).

---

## 3.5 What the publish pipeline needs added (Chapter 2 → Chapter 3 delta)

The Chapter 2 pipeline produces a tarball. To go to the store, it needs:

1. **`appinfo/signature.json` generation** — insert an `occ integrity:sign-app` step in the
   workflow after `Build` and before `Package`. Requires:
   - NC app private key as a GitHub secret (`NC_APP_PRIVATE_KEY`)
   - Certificate committed to the repo (or fetched from a secret)
   - A temporary Nextcloud install in CI to run `occ`, OR a standalone signing script
     (the `integrity:sign-app` logic is straightforward enough to replicate)

2. **Store upload step** — after `Create Release`, add a step that POSTs to
   `apps.nextcloud.com/api/v1/apps/releases` with the tarball URL and tarball signature.
   Requires a store API token as a GitHub secret.

3. **`info.xml` fixes** — the schema validation happens server-side on upload; failures
   return a clear error. Fix the fields in §3.2 (with the copy/screenshots from §3.1 branding)
   before the first upload attempt.

---

## 3.6 Sequence (order matters)

```
Fix info.xml  ──┐
                ├──▶  Generate key + submit CSR PR  ──▶  (wait for Nextcloud team)
                │                                              │
                │                              ┌──────────────┘
                │                              ▼
                │                     Register app on store
                │                              │
                └──▶  Chapter 2 pipeline ──────┤
                       (tarball ready)         │
                                               ▼
                                     Add sign + upload steps
                                     to publish.yml  ──▶  First store release
```

The CSR wait is the only external dependency. Everything else is in our control.

---

Sources:
- [The Nextcloud app store rules](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
- [App Developer Guide — Nextcloud AppStore](https://nextcloudappstore.readthedocs.io/en/latest/developer.html)
- [App metadata — Nextcloud Developer Manual](https://docs.nextcloud.com/server/latest/developer_manual/app_development/info.html)
- [Code signing — Nextcloud Developer Manual](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html)
