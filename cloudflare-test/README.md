# Test suite for Cloudflare APO sync

Regression suite for the **NPP** Cloudflare sync in [`includes/compat-cloudflare.php`](../includes/compat-cloudflare.php). Once NPP has purged nginx, the sync listens on `nppp_purged_urls` and `nppp_purged_all` (priority 20) and asks Cloudflare to drop the same pages. It does not talk to Cloudflare with credentials of its own: it reads the zone, the token and the APO flag from the **official Cloudflare plugin** installed on the site, and lets that plugin's `Client::beforeSend()` build the auth headers.

It guards against three failure modes:

- **False "purged"**: the plugin logs success, Cloudflare keeps serving the page (wrong zone, wrong auth header for the key format, a refused request counted as done, a foreign host sent along).
- **Lost or stalled work**: a slow API stalls the editor, a `429` / `5xx` / `403` drops the URLs that were still queued, one refused chunk takes the other chunks with it.
- **Fatal on a changed plugin**: the official plugin is absent, comes in the old `CF\` or the new `Cloudflare\APO\` namespace, or a future release changes its shape. NPP must detect it and carry on, never fatal.

The official plugin is not a copy either. The `real-cf` layer and the real-WordPress layer run against its unpacked source.

## Quick start

Linux host, from this repository:

```bash
cloudflare-test/run-cloudflare-tests.sh --fast --no-real   # PHP only, seconds
cloudflare-test/run-cloudflare-tests.sh --no-real          # + the 10 s time budget test
CF_SRC=/path/to/cloudflare-4.14.5 CF_SRC_OLD=/path/to/cloudflare-4.12.8 \
    cloudflare-test/run-cloudflare-tests.sh                # + real plugin source
```

Real WordPress layer (a throwaway site, see [Real-WordPress layer](#real-wordpress-layer)):

```bash
WP_DIR=/var/www/lab-wp cloudflare-test/realwp/run-realwp.sh
```

> **Run the real-WordPress layer somewhere disposable.** It needs root, binds the mock API to `:443`, edits `/etc/hosts`, installs a mu-plugin, and overwrites `nginx_cache_settings` and the `cloudflare_*` options of the site it runs on. The stub layers only need PHP and are harmless.

## Layers

| Layer | Entry | What runs | Needs |
| --- | --- | --- | --- |
| `unit` (37 sections) | `tests/unit.php` | The real `state.php` and `compat-cloudflare.php` on a WordPress stub, scripted Cloudflare answers. | PHP 7.4+, `php-intl` |
| `detect` (4 modes) | `tests/detect.php new\|old\|both\|none` | Namespace detection for each installed layout. | PHP 7.4+ |
| `unsupported` | `tests/unsupported.php` | A hollow or future plugin layout ends in "unsupported", never a fatal. | PHP 7.4+ |
| `contract` | `tests/contract.php` | Static: PHP 7.4 syntax, forbidden behaviour, kept public surface, wiring in the rest of the plugin. | PHP 7.4+ |
| `budget` | `tests/budget.php` | 8 s request / 20 s cron time budget against a slow API. Takes about 11 s. | PHP 7.4+ |
| `real-cf` | `tests/real-cf.php` | `compat-cloudflare.php` against the real official plugin source, WordPress still stubbed. | `CF_SRC` and/or `CF_SRC_OLD` |
| `realwp` | `realwp/run-realwp.sh` | Real WordPress, real official plugin, real cURL + TLS, a mock `api.cloudflare.com`. | WordPress + DB, root, `wp`, `python3`, `openssl` |

The first six layers need no WordPress. The `realwp` layer is not part of `run-cloudflare-tests.sh`, start it by hand.

Each test file, and each `detect` mode, runs in its own PHP process. The plugin keeps per-request statics (zone cache, park state, "pending" claim) that must not leak between runs.

## Files

| File | Role |
| --- | --- |
| `run-cloudflare-tests.sh` | Entry for the stub layers and `real-cf`. Prints the last line of each layer, the failing assertions, then `ALL GREEN` or `FAILED`. |
| `tests/boot.php` | Loads the real `state.php` + `compat-cloudflare.php` on the stub. Provides `t_reset()`, `t_enable()`, `t_expire()`, `t_happy()`, `ok()`, `section()`, `finish()`. |
| `tests/unit.php`, `detect.php`, `unsupported.php`, `budget.php`, `contract.php`, `real-cf.php` | The layers above. |
| `lib/wpstub.php` | Minimal WordPress and a fake official plugin in both namespaces: options with a write log, hook registry that really dispatches, cron events, `wp_remote_request()` with a scripted transport, a `$wpdb` whose `delete()` reports rows affected (the atomic claim). |
| `lib/psrlog-stub.php` | `psr/log` stand-in for the official plugin checkouts that ship no `vendor/`. |
| `realwp/run-realwp.sh` | Entry for the real-WordPress layer: certs, hosts entry, mu-plugin, mock API, `wp eval-file`. |
| `realwp/scenarios.php` | The real-WordPress scenarios. |
| `realwp/mock-cf.py` | Mock Cloudflare v4 API: HTTPS on `:443`, control API on `127.0.0.1:18080`. |
| `realwp/make-certs.sh` | Local CA and a certificate for `api.cloudflare.com`. |
| `realwp/mu-plugins/nppp-cf-harness.php` | Loads the real `state.php` and `compat-cloudflare.php` into the real site and trusts the local CA. |
| `realwp/live-smoke.php` | Optional smoke test against the **real** Cloudflare API of a free-plan zone. Not part of any run. |

## How it works

- **Scripted** (`unit`, `detect`, `budget`, `real-cf`): `t_script( '#regex#', response )` answers `"METHOD url"` matches. `t_happy()` scripts the zone lookup and the purge. `t_reqs( '#regex#' )` returns what was sent, `t_logs()` what was logged, `t_state( 'cf_pending' )` the queued work. `t_expire( key )` rewinds a state row's deadline, because the harness cannot move `time()`.
- **Namespaces**: the stub fakes the official plugin in `Cloudflare\APO\` (4.14.x) and `CF\` (4.12.8 / 4.13.x). `both` means 4.14.x, which also ships the deprecated `CF\` classes. NPP must pick the new one first and never instantiate the legacy classes.
- **`real-cf`**: with `CF_SRC` the real classes come from the plugin's `vendor/autoload.php`. With `CF_LAYOUT=psr4` (4.12.8 / 4.13.x, no `vendor/` in git) `CF\` is autoloaded over `src/` and `psr/log` is stubbed. Only WordPress is faked, so the auth headers are the ones the real `beforeSend()` builds for every key format (`cfut_`, `cfat_`, `cfk_`, legacy 37-char hex, legacy 40-char token).
- **Real WordPress**: the mock only replaces `api.cloudflare.com`. The mock validates what the Cloudflare docs say a client must send (auth, exactly one of `purge_everything` / `files` / `tags` / `hosts` / `prefixes`, at most `max_files` URLs, URL length 4096), so a malformed request fails here. Its behaviour is modelled on the documentation, not recorded from the service.
- `ok()` must hold. In the real-WordPress layer `known()` documents a limit: it prints `KNOWN-ISSUE` and does not fail the run.

## Requirements

| Layer | Needs |
| --- | --- |
| Stub layers | PHP 7.4+ (CLI). `php-intl` for the IDN host test (`host sent as punycode`). It is part of the Docker stack image. |
| `real-cf` | An unpacked official plugin directory. `CF_SRC` for 4.14.x with its `vendor/`, `CF_SRC_OLD` for a 4.12.8 / 4.13.x checkout. Either one is optional, a missing one is `SKIP`. |
| `realwp` | root (port 443, `/etc/hosts`), a WordPress install with a database, the official Cloudflare plugin unpacked in `wp-content/plugins/cloudflare` and active, `wp` (WP-CLI), `python3`, `openssl`, `curl`. The scenarios expect the official client's User-Agent of release **4.14.5**. |

## Docker stack

Not wired into [`wordpress-nginx-cache-docker`](https://github.com/psaux-it/wordpress-nginx-cache-docker). `wp-post.sh` strips this folder from the deployed plugin directory, so it is not inside `wordpress-fpm`.

Run the stub layers on any Linux host or CI runner from a repository checkout. The real-WordPress layer wants its own disposable WordPress. Do not point it at the stack's site: the stack runs the real NPP, and the harness mu-plugin defines `nppp_display_admin_notice()` which the plugin defines too (`Cannot redeclare` on the next admin or cron request).

## Host usage

```bash
cloudflare-test/run-cloudflare-tests.sh                 # all stub layers, real-cf only with CF_SRC / CF_SRC_OLD
cloudflare-test/run-cloudflare-tests.sh --fast          # skip the 10 s budget test
cloudflare-test/run-cloudflare-tests.sh --no-real       # skip the layers that need the official plugin source
```

Single files run on their own and print one `ok` / `FAIL` line per assertion:

```bash
php cloudflare-test/tests/unit.php
php cloudflare-test/tests/detect.php both
CF_SRC=/path/to/cloudflare php cloudflare-test/tests/real-cf.php
CF_SRC=/path/to/cloudflare-4.12.8 CF_LAYOUT=psr4 php cloudflare-test/tests/real-cf.php
```

| Option | Meaning |
| --- | --- |
| `--fast` | Skip `budget`. |
| `--no-real` | Skip `real-cf:4.14.x` and `real-cf:old`. |

Exit codes: `0` all green, `1` a layer failed. Environment: `PHP` (default `php`), `CF_SRC`, `CF_SRC_OLD`, `CF_LAYOUT`.

The tests find the plugin two directories above `tests/` (`dirname( __DIR__, 2 )`), so they must run from a repository checkout. Only the real-WordPress layer honours `NPPP_ROOT`.

### Real-WordPress layer

```bash
WP_DIR=/var/www/lab-wp cloudflare-test/realwp/run-realwp.sh
```

What the script does, in order:

1. Creates a local CA and a certificate for `api.cloudflare.com` in `CERTS` (default `/tmp/nppp-cf-certs`) when missing.
2. Adds `127.0.0.1 api.cloudflare.com` to `/etc/hosts` when missing, and removes it again at the end only if it added it.
3. Copies `nppp-cf-harness.php` into `$WP_DIR/wp-content/mu-plugins/`. It is **not** removed afterwards.
4. Starts `mock-cf.py` (HTTPS `:443`, control `127.0.0.1:18080`) and waits for the control port.
5. Runs `wp eval-file scenarios.php --allow-root` in `$WP_DIR`, stops the mock, returns the scenario exit code.

The mock control API (plain HTTP, `127.0.0.1:18080`):

| Call | Effect |
| --- | --- |
| `POST /reset` | Clear log, scripts and limits, keep tokens and zones. |
| `POST /config {json}` | Merge `max_files`, `zones`, `tokens`, `global_keys`, `rate {capacity, per_second}`. |
| `POST /script {"match":"purge\|zones\|*","status":503,"headers":{},"body":{},"delay":0,"times":1}` | Script a failure or latency. |
| `GET /log` | Every request the mock received. |

### Live smoke test

`realwp/live-smoke.php` purges a URL on the **real** Cloudflare API, on a site that has the official plugin configured and NPP active. A free-plan zone is enough, URL purge and purge-everything exist on every plan, only the APO flag is read from the plugin option.

```bash
LIVE_URL=https://example.com/wp-content/themes/x/style.css wp eval-file cloudflare-test/realwp/live-smoke.php
LIVE_PROBE=1 LIVE_URL=... wp eval-file cloudflare-test/realwp/live-smoke.php   # also probes the per-request URL limit
```

It warms the edge, purges through the compat layer, and prints `cf-cache-status` before and after. If you only ever see `DYNAMIC` or `BYPASS`, the URL is not cached by Cloudflare and the run proves nothing. `LIVE_PROBE=1` sends dummy URLs in one request (30, 31, 100, 101) to find the real per-request limit of the plan.

## What is covered

### `unit`

| Sections | Pinned down |
| --- | --- |
| `registration contract` | Listeners at priority 20 (`nppp_purged_urls` 4 args, `nppp_purged_all` 1 arg), filter at 10 with 6 args, no `nppp_preload_all_started`, no `shutdown` queue, public functions kept. |
| `happy path`, `zone cache`, `zone candidates`, `filter ...zone`, `no zone` | One `POST /zones/{id}/purge_cache` with deduped URLs, host lower-cased, foreign hosts, other schemes and look-alike hosts rejected. Zone lookup by the cached domain name, candidates for multi-level hosts, zone cached, `nppp_cloudflare_apo_zone` filter wins, no active zone is reported. |
| `chunking`, `cache by device type`, `purge all`, `no skip rule` | Chunk size filter, mobile request shape (headers as a JSON object), one `purge_everything`, no "purge-all ran" flag that suppresses later URL purges. |
| `429`, `5xx`, `retry event moves earlier`, `403` | `429` + `Retry-After` parks, queues and schedules one cron event. `5xx` backoff and give-up. `403` is not retried and parks. |
| `queued work survives ...`, `403 on purge_cache`, `403 on the second chunk`, `refused purge-all`, `stale zone id` | Queued URLs are put back after a refusal, fresh ones are not. A queued purge-all stays a purge-all. A stale zone id (`404` / `7003`) is rediscovered. |
| `network error`, `transport that throws`, `partial progress`, `refused chunk`, `next run merges` | Coarse failure reason, retry, a throwing transport is contained, leftover chunks are kept, one `400` chunk does not drop the others, the next run merges pending work into one request. |
| `pending set`, `gates`, `filter callback never writes`, `retry event honours the setting` | Atomic claim and collapse of the pending row. Gates: setting, `nppp_sync_cloudflare_apo_enabled` filter, credentials, APO flag. Listeners never write settings. |
| `global API key headers`, `secrets never leave`, `IDN host`, `path and query kept byte for byte` | Legacy global key goes out as `X-Auth-Email` / `X-Auth-Key`, token and key never reach a log line, IDN host is sent as punycode, path and query are not rewritten. |
| `reset / cleanup`, `no setting write` | `nppp_cloudflare_apo_reset_state()` and cleanup, no option written anywhere in the run. |

### `detect`, `unsupported`, `budget`

| Test | Proves |
| --- | --- |
| `detect new` / `old` / `both` / `none` | Namespace chosen (`Cloudflare\APO\`, `CF\`, new first when both), `is_available()`, one working purge with the layout's own `beforeSend()`. With `none`: nothing sent, status `absent`, the user setting stays `yes`, nothing written. |
| `unsupported` | A plugin that looks installed but is hollow: `supported()` is false, listeners do nothing and do not fatal, status `unsupported`, one warning. |
| `budget` | Slow API in a request: stops after the 8 s budget, the unsent URLs stay pending, one retry event, a budget stop is not a failure (no park, no try counted). Cron budget is 20 s. |

### `contract`

Static checks of `compat-cloudflare.php`: PHP 7.4 syntax bans (`match`, `enum`, `readonly`, `?->`, union types), `php -l`, no `update_option()` / `add_option()` / `delete_option()`, no `shutdown` hook, no capability or nonce dependence (listeners run headless), `sslverify` never `false`, official plugin hooks never removed, purge method is `POST`, only `nginx_cache_settings` is read as an option, text domain on every `__()`. Wiring in the rest of the plugin: retry event whitelisted in the main file, removed by `uninstall.php` and on deactivation, `compat-cloudflare.php` still required by the admin bootstrap, no render-time write of the toggle in `settings-callbacks.php`.

### `real-cf`

Detection and `supported()` against the real classes, credentials, APO flag and cached domain read through the real `DataStore`, auth headers from the real `beforeSend()` for five key formats, a full purge flow (zone lookup by the real cached domain name, one purge request, purge-all), nothing written to settings. With 4.14.x also: the deprecated `CF\` classes exist and are never instantiated.

### Real-WordPress scenarios

| Section | Proves |
| --- | --- |
| `happy path` | URL purge over the real WP HTTP stack (cURL + TLS), User-Agent set by the official client. |
| `zone cache reused; purge everything` | One zone lookup for several purges. |
| `cache by device type` | Mobile request shape: headers must be a JSON object. |
| `global API key` | Legacy hex key goes out as `X-Auth-Email` / `X-Auth-Key`. |
| `chunking`, `RISK` sections | Chunking against the documented limit (100). Cloudflare docs disagree on the free-plan limit (100 vs 30 URLs per request), one `400` chunk (URL over 4096 chars) next to the others. Reported as `KNOWN-ISSUE` where the behaviour is a documented risk. |
| `429`, `503`, `bad token` | Park, pending and **one** real cron event in the real cron array. `403` is not retried and parks. |
| `real request timeout` | The mock stalls 7 s, the client gives up in 5 s or less. |
| `TLS verification` | Untrusted CA means failure, never a silent insecure send. |
| `atomic claim` | 8 PHP processes race for one pending row on a real `$wpdb`, exactly one wins. |
| `nothing written` | Settings, credentials and APO flag unchanged after purges. |

Not covered: purge by tag, host or prefix, cache rules of a paid plan, multi-zone sites behind one account, a real Cloudflare answer (only `live-smoke.php` touches the real API).

## Cleanup

Stub layers leave nothing behind except `/tmp/cf-fake-plugin-b` (the `unsupported` test).

After the real-WordPress layer:

```bash
rm -f  "$WP_DIR/wp-content/mu-plugins/nppp-cf-harness.php"
rm -rf /tmp/nppp-cf-certs /tmp/nppp-mock-cf.log /tmp/nppp-notices.log
grep -q api.cloudflare.com /etc/hosts && sed -i '/api.cloudflare.com/d' /etc/hosts   # only if the run was killed
pkill -f mock-cf.py
```

The scenarios overwrite `nginx_cache_settings`, `cloudflare_api_key`, `cloudflare_api_email`, `cloudflare_cached_domain_name` and the APO options. Use a database you can drop.

## Environment variables

| Variable | Used by | Meaning |
| --- | --- | --- |
| `PHP` | `run-cloudflare-tests.sh` | PHP binary (default `php`). |
| `CF_SRC` | `real-cf` | Unpacked official plugin, with `vendor/` (4.14.x). |
| `CF_SRC_OLD` | `real-cf` | Unpacked official plugin 4.12.8 / 4.13.x, run with `CF_LAYOUT=psr4`. |
| `CF_LAYOUT` | `real-cf` | `psr4` for checkouts without `vendor/`. |
| `WP_DIR` | `run-realwp.sh` | WordPress root (required). |
| `NPPP_ROOT` | `run-realwp.sh`, mu-plugin | Plugin repository root (default: two levels above `realwp/`). |
| `CERTS` | `run-realwp.sh` | Directory for the local CA and certificate (default `/tmp/nppp-cf-certs`). |
| `NPPP_H_CA` | mu-plugin | CA file WordPress trusts for `api.cloudflare.com`. |
| `NPPP_H_NOTICES` | mu-plugin, scenarios | File where admin notices are written (default `/tmp/nppp-notices.log`). |
| `LIVE_URL`, `LIVE_PROBE` | `live-smoke.php` | URL to purge, and the limit probe switch. |

Fixed ports: `:443` (mock API, `api.cloudflare.com` via `/etc/hosts`) and `127.0.0.1:18080` (mock control API). [`wget-test`](../wget-test) also uses `18080`, do not run the two at the same time.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `FAIL host sent as punycode` in `unit` | `php-intl` is missing. Install it (`php-intl`). |
| `SKIP` for `real-cf:4.14.x` / `real-cf:old` | Set `CF_SRC` / `CF_SRC_OLD` to an unpacked plugin directory. |
| `Failed opening required .../vendor/autoload.php` | `CF_SRC` has no `vendor/`. Use a release zip that contains it, or `CF_SRC_OLD` with `CF_LAYOUT=psr4` for 4.12.8 / 4.13.x. |
| Include errors for `includes/state.php` | The tests resolve the plugin two directories above `tests/`. Run from a repository checkout. |
| `mock reachable: NO` in the scenario header | `mock-cf.py` did not start (port `:443` busy, no root). See `/tmp/nppp-mock-cf.log`. |
| `Cannot redeclare nppp_display_admin_notice()` | The harness mu-plugin runs next to the real NPP. Use a WordPress without NPP, and remove the mu-plugin when done. |
| TLS errors from the real-WordPress layer | `api.cloudflare.com` does not resolve to `127.0.0.1`, or `NPPP_H_CA` points to another CA. |
| User-Agent assertion fails | The scenarios expect official plugin **4.14.5**. A different release changes the User-Agent. |
| `KNOWN-ISSUE ...` | A documented limit, not a failure. The line says which. |

## Adding a test

1. A scripted case goes into a `section( 'name' );` block in `tests/unit.php`. Start it with `t_reset(); t_enable(); t_happy();`, script what differs with `t_script()`, fire `do_action( 'nppp_purged_urls', ... )` and assert with `ok( condition, 'what it proves' )`.
2. A new layout or detection rule goes into `tests/detect.php` (a new mode) and the `for m in new old both none` list in `run-cloudflare-tests.sh`.
3. A new test file must be added to `run-cloudflare-tests.sh` through `run name $PHP file`, otherwise it never runs.
4. A new real-WordPress case goes into `realwp/scenarios.php`: start with `reset_all()`, script the mock with `mk_script()` / `mk_cfg()`, read `mk_log()`, assert with `ok()`. Use `known()` only for a limit you want to pin.
5. A new function or hook in `compat-cloudflare.php` that other code relies on belongs into the `surface kept` or `wiring` list of `tests/contract.php`.
