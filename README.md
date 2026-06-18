# n8n Sync (Nextcloud app)

Maps n8n workflows into Nextcloud as JSON files with a native feel. Design lives in
[`prompts/nextcloud-n8n.md`](../../../../prompts/nextcloud-n8n.md).

## Status

**Phase 0 — skeleton.** Installs/enables/disables cleanly; registers nothing yet.

## Layout

```
appinfo/info.xml            app manifest (id n8n_sync, namespace OCA\N8nSync)
lib/AppInfo/Application.php  IBootstrap entrypoint (empty register/boot)
```

No `composer.json` is needed yet — Nextcloud auto-registers `OCA\N8nSync\` → `lib/`.

## Local test against the running pod

```sh
# copy into the pod's custom_apps path, then:
occ app:enable n8n_sync     # install/enable
occ app:list | grep n8n     # verify
occ app:disable n8n_sync    # uninstall
```
