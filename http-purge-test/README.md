# Test suite for HTTP Purge

Regression suite for the HTTP Purge fast path in [`includes/purge-http.php`](../includes/purge-http.php), which asks nginx to drop cache entries through [ngx_cache_purge](https://github.com/nginx-modules/ngx_cache_purge) before falling back to the filesystem.

It guards against two failure modes:

- **False "purged"**: the plugin claims success but nothing was purged, so stale pages keep being served.
- **Giving up too early**: the plugin runs the filesystem purge and starts the preload while nginx is still deleting in the background, and the late walk deletes the freshly warmed pages.

## Quick start

Docker stack ([`wordpress-nginx-cache-docker`](https://github.com/psaux-it/wordpress-nginx-cache-docker)), from the stack checkout:

```bash
./actionstart-purge.sh --only mock    # ~40 s, no nginx build
./actionstart-purge.sh                # mock + full real-nginx matrix
```

Linux host, from this repository:

```bash
http-purge-test/run-purge-tests.sh --only mock
http-purge-test/run-purge-tests.sh --build --only matrix sync ambig   # first run compiles nginx
```

> **Run it somewhere disposable.** The real-nginx part runs nginx and php-fpm as root, writes fixed names under `/tmp`, wipes the page cache between scenarios and stops any nginx from its own builds. The mock harness is harmless.

## Files

| File | Role |
| --- | --- |
| `run-purge-tests.sh` | Entry point: runs mock and/or matrix, builds nginx on demand, exits `1` on failure. |
| `run.php` | Mock harness: stubbed WordPress functions, scripted HTTP answers. |
| `e2e.php` | Real-nginx scenarios (one per invocation). |
| `matrix.sh` | Per scenario: configure nginx, run `e2e.php`, print `PASS` / `FAIL` / `SKIP`. |
| `nginx.sh` | Writes `nginx.conf` for one mode, restarts nginx (and php-fpm), empties the cache. |
| `build-nginx.sh` | Builds nginx + one `ngx_cache_purge` branch (v4, v3.0, v2.5). |
| `delayproxy.py` | Holds answers for a few seconds to force a Purge All timeout. |
| `wakelistener.py` | Stands in for `admin-ajax.php` in `deferred_waiter`: answers `200`, logs every POST. |
| `fpm.conf`, `site/index.php` | php-fpm pool and WordPress stand-in (every path answers a cacheable `200`). |

## How it works

- **Mock**: every answer is scripted (`R(200, 'purged')`, `E('cURL error 28: ...')`), so every branch is deterministic. ~650 assertions, ~40 s.
- **Real**: real nginx + `ngx_cache_purge` + php-fpm stand-in + real cache directory. Mock fixtures were written from the module source, so a mismatch here means a wrong fixture, not a plugin bug.

Neither layer needs WordPress.

## Requirements

- Mock: PHP CLI 8.x with `curl` and `posix`; POSIX `sh`.
- Real matrix, additionally: Linux, root; `git`, `build-essential`, `libpcre2-dev`, `zlib1g-dev`, `libssl-dev` and access to `github.com` for the first build; `php-fpm` (`php-fpm8.3` preferred, override with `PHP_FPM`); `curl`, `python3`, `procps`; `chattr` (`e2fsprogs`) for `deletefail`.

## Docker stack

Run on the Docker host, from the directory with `docker-compose.yml`:

```bash
./actionstart-purge.sh                                    # mock + matrix (v4, compiled once)
./actionstart-purge.sh --only mock
./actionstart-purge.sh --only matrix sync ambig
./actionstart-purge.sh --ng v30 --only matrix sync ambig  # v4 | v30 | v25
./actionstart-purge.sh --file ./includes/purge-http.php   # test a candidate file
./actionstart-purge.sh --src ../plugin/http-purge-test    # copy harness from a local checkout
```

| Mode | Effect |
| --- | --- |
| `--no-run` | Prepare everything, run nothing. |
| `--shell` | Root shell in the lab directory. |
| `--clean` | Stop harness nginx, php-fpm, delay proxy; keep nginx builds. |
| `--purge` | `--clean` + delete nginx builds. |
| `--build` | Rebuild the stack images (not the nginx build). |

Overrides: `C` (container, default `wordpress-fpm`), `WP_PATH`, `LAB_DIR`, `WAIT` (stack wait in seconds, default 300).

## Host usage

```bash
http-purge-test/run-purge-tests.sh --only mock
http-purge-test/run-purge-tests.sh --build --only matrix
http-purge-test/run-purge-tests.sh --ng v30 --only matrix sync ambig
http-purge-test/run-purge-tests.sh --build --ng v25 --only matrix
```

| Option | Meaning |
| --- | --- |
| `--only mock` / `--only matrix` | Run one layer (default: both). |
| `--ng v4` / `v30` / `v25` | Module build to test (default `v4`). |
| `--build` | Build the selected nginx if missing (otherwise exit `2`). |
| `SCENARIO ...` | Matrix scenarios to run (default: all for `v4`). |

### Module builds

| `--ng` | Branch | Prefix under `$NGROOT` | Default scenarios | Notes |
| --- | --- | --- | --- | --- |
| `v4` | `releases/stable-4.0` | `nginx-run` | all 26 | `--with-threads`; status endpoint + error-code catalog. |
| `v30` | `releases/stable-3.0` | `nginx-run303` | `sync`, `ambig` | Wrapper passes `v30` to `e2e.php` (affects `legacy_off`). |
| `v25` | `releases/stable-2.5` | `nginx-run256` | `v25` | Single purge only, no Purge All location. |

## What is covered

### Mock harness

| Area | Pinned down |
| --- | --- |
| Request shape | URL, method, no redirects, `sslverify` off for loopback, 16 KB response cap, 8 s / 30 s / 3 s timeouts, `Host` header, cURL hook added and removed; custom base URL, suffix, Purge All path. |
| Success bodies | v4 `Cache-Purge-Code` (header or JSON body); v2/v3 `200` bodies in html/json/xml/text. A page merely containing `Key:` or an empty/foreign `200` is not a purge. |
| Method learning | Foreign `200` or `405` with `Allow: PURGE` is retried once with `PURGE`; working method remembered per scope (single, all) and dropped when it stops working. |
| Misses | `412`, `200`/`204 no_match` → `'miss'`, only for unambiguous URLs on the same host and scheme. |
| Ambiguous URLs | Percent-encoding, non-ASCII, `//`, dot segments, backslashes are never trusted (nginx decodes `$1` and may have deleted a different entry). Overridable via `nppp_http_purge_trust_miss` / `trust_purged`. |
| Locks | Which answer arms which lock (`single`, `all`, `*`) and for how long (15 min unreachable, 1 h config fault, 5 min transient module error); `400`, `429`, `delete_failed`, `scan_failed` and read timeouts arm none; locks never block the other scope. |
| Config issues | `key_mismatch`, `cache_unavailable` remembered for a day, shown as a settings notice, cleared by the next good answer. |
| URL guards | Wildcards (`*`, `%2A`), control chars, no host, host ≠ `Host` header, `nppp_http_purge_url` filter. |
| Transport | Read timeout (nginx alive: no lock) vs connect failure (locks `*`); exceptions contained. |
| Purge All and 202 | Baseline status poll, wait for two idle samples, failure on exhausted retries or rising file errors, wait capped by the lock TTL, `$pending` hand-off when the queue is still busy. |
| Directory proof | Cache scanner, `200` cross-check against leftover old files, best effort without a status endpoint. |
| Response timeout | A timed-out Purge All is waited out through the directory; a single-purge timeout is not. |
| Preload gate | Plain crawl command byte-identical when nothing is pending; otherwise `( trap; poll; exec crawl )`, valid POSIX `sh`, 30 s / 900 s / 7200 s wait bounds. |
| Persisted marker | Pending Purge All stored as a non-autoloaded option; survives the request and an object-cache flush; gates a later request's crawl; expires with the gate bounds; dropped once the queue is idle. Dead status endpoint still gates (shell gate gives up after 5 failed polls); expired, malformed or URL-less markers never gate. |

### Real-nginx scenarios

26 scenarios run by default on v4.

**Core**

| Scenario | Mode | Proves |
| --- | --- | --- |
| `sync` | `sync` | Hit → `true` and page MISS afterwards; never-cached URL → `'miss'`; percent-encoded miss not trusted; `www` URL not claimed; Purge All empties the cache without waiting, `true` on an empty cache. |
| `ambig` | `sync` | Purging `/p/%41/` makes nginx delete `/p/A/` and answer `200`; the plugin must not claim success. |
| `ns` | `ns` | Namespace mode: single and Purge All answer `invalidated`; page MISS at once, untouched pages stay HIT. |
| `none` | `none` | `cache_purge_response_type none`: bodiless `204` read from `Cache-Purge-Code`. |
| `wrongzone` | `wrongzone` | Purge All bound to another zone answers `200` but old files remain → `false`, filesystem purge runs. |
| `single202` | `single202` | Single-purge endpoint answering `202` → `false` and locked, so it can't become a whole-cache wipe loop. |
| `legacy_off` | `legacy_off` | `cache_purge_legacy_status off`: v4 ignores it (`no_match` → `'miss'`); v3.0.x answers an ambiguous `404` → `false`. |

**Background queue and races** (3000 cached pages, then a 400-page preload; nginx must never delete what the preload warmed)

| Scenario | Mode | Proves |
| --- | --- | --- |
| `race` | `async` | Purge All → `202`; completion proven via the status endpoint, `true` after a real wait, 400/400 preloaded pages survive. |
| `race_nostatus` | `async_nostatus` | Same without a status endpoint (directory-based best effort). |
| `race_off` | `async` | With queue verification off, the late walk deletes preloaded pages. Pins the old behaviour. |
| `race_timeout` | `async` + delay proxy | Response held 3 s, plugin times out after 1 s while nginx deletes; plugin waits it out, returns `true`, preload intact. |
| `slowq` | `async_slow` | See below. |
| `slowq_marker` | `async_slow` | See below. |
| `deferred_waiter` | `async_slow` | See below. Needs `wget` (skipped without it). |

**Namespace mode with slow cleanup**

| Scenario | Proves |
| --- | --- |
| `race_invalidated` | After Purge All the old generation is unreachable at once while its files remain on disk; late cleanup doesn't delete pages warmed in the new generation. |
| `race_invalidated_single` | Same for a single URL. |
| `ns_keys` | Cache files carry `KEY: p4:<namespace>:<epoch>:<generation>:<key>`; the default `cache_key_regex` parses every one; stale and live files coexist during cleanup; the plugin's generation helpers see exactly one live file; a single purge raises only that key's generation. |

**v4 error-code and Vary scenarios** (a real v4 module produces one catalog code each, validating the mock fixtures)

| Scenario | Mode | Module answer | Plugin must |
| --- | --- | --- | --- |
| `denied` | `denied` | single: bare `403`; Purge All: `403 access_denied` | return `false`, lock that endpoint only |
| `method405` | `method405` | `405 method_not_allowed`, `Allow: PURGE` | retry once with `PURGE`, remember it, no lock; a miss answered to `PURGE` is not trusted |
| `nocache` | `nocache` | `500 cache_unavailable` | return `false`, lock Purge All, single keeps working, settings page gets the fix |
| `keymismatch` | `keymismatch` | `500 key_mismatch` | return `false`, lock Purge All, name the directive to fix |
| `wrongep` | `wrongep` | `200 status` (single bound to the status endpoint) | not treat it as a purge, lock single |
| `deletefail` | `sync` | `500 delete_failed` (one file `chattr +i`) | return `false`, no lock. Skipped without `chattr +i` support |
| `scanfail` | `scanfail` | `500 scan_failed` (workers as `nobody`, one cache dir mode 000) | return `false`, no lock |
| `badsel` | `vary` | `400 invalid_selector` (exact key > 511 bytes) | return `false`, no lock; next URL still works |
| `vary` | `vary` | `200 purged` when the requested variant isn't cached | return `true`, all variants gone |
| `vary_off` | `vary_off` | `200 no_match` while variants are still cached | pin the known limitation: `'miss'`, variants stay (workaround: `nppp_http_purge_trust_miss => false`) |

`/v/...` on the stand-in site answers `Vary: Accept-Encoding`, which produces the variants.

**Other module line**

| Scenario | Build | Proves |
| --- | --- | --- |
| `v25` | `--ng v25` | 2.5.x has no Purge All location: single purge works, `nppp_http_purge_all()` returns `false` (filesystem purge runs), never a false success. |

Not reproducible here (mock only): `invalid_cursor`, `internal_error`, a full queue (deferred, not an error in v4), `429`.

#### `slowq`

`async_slow` nginx (queue takes ~35–40 s), plugin wait budget 5 s (`SLOWQ_WAIT`, capped at 40). A caller that can defer its preload passes `$pending` to `nppp_http_purge_all()`: after the budget, a queue still verifiably working returns `false` plus `$pending`, and the caller must not run the filesystem purge.

A stand-in crawl spawned via `nppp_http_purge_gate_command()` must:

- return a PID at once (request not blocked);
- not start while nginx is still deleting;
- start only when the queue is idle and no old file is left;
- leave all 400 preloaded pages intact and no gate process behind.

With `SLOWQ_WAIT=40` the plugin waits the queue out and returns `true`.

#### `slowq_marker`

Same setup, but the crawl is spawned as a **later** request would: hand-off cleared, transients wiped, `nppp_http_purge_gate_arm()` not called. Only the persisted option `nppp_http_purge_pending` remains.

- Without the marker the spawn is the plain, ungated command (control).
- With the marker while nginx is still deleting: PID returned at once, crawl does not start, marker not consumed (a restarted crawl is gated again).
- Crawl starts only when the queue is idle with no old file left; all 400 pages survive.
- Once idle, the stale marker neither gates nor stays behind.
- With an unreadable status endpoint the spawn is still gated; the shell gate fails open after 5 failed polls.

#### `deferred_waiter`

`async_slow` nginx, plugin wait budget 5 s (`SLOWQ_WAIT`). A Purge All that ends with nginx's queue still deleting (status 7) must not fire `nppp_purged_all` (Cloudflare purge-all, Redis flush) yet. The scenario drives the real `nppp_http_purge_fire_purged_all()` against the real queue; `wakelistener.py` (`127.0.0.1:8092`) stands in for the wake endpoint (`admin_url()` is pointed at it; `NPP_WAKE_BASE` overrides).

Part A, the detached waiter (real `bash`, `wget`, `date`, `sleep`, real `purge_status` JSON):

- hook not fired, state armed with a 32-hex one-shot token, waiter spawned, cron only the slow second path (+60 s);
- the waiter survives the spawn and does not POST while nginx still deletes;
- exactly one POST of `action=nppp_purged_all_wake&token=<token>` to `/wp-admin/admin-ajax.php`, only after every old file is gone and the queue is idle;
- the POSTed token claims the state exactly once; the waiter process is gone; a late cron tick fires nothing.

Part B, waiter dead (killed), WP-Cron alone:

- cron ticks while the queue is busy fire nothing and re-arm;
- the hook fires once, after the real queue deleted every file, with the `auto_preload` flag of the arming Purge All; state and stale marker are gone; no wake POST.

## Running the pieces by hand

```bash
php http-purge-test/run.php includes/purge-http.php                    # mock

http-purge-test/build-nginx.sh releases/stable-4.0 http-purge-test/ng/nginx-run      # v4
http-purge-test/build-nginx.sh releases/stable-3.0 http-purge-test/ng/nginx-run303   # v3.0
http-purge-test/build-nginx.sh releases/stable-2.5 http-purge-test/ng/nginx-run256   # v2.5

NG="$PWD/http-purge-test/ng/nginx-run" http-purge-test/nginx.sh sync   # one nginx mode
php http-purge-test/e2e.php ambig includes/purge-http.php              # one scenario (add `v30` last for v3.0)
NG="$PWD/http-purge-test/ng/nginx-run" http-purge-test/matrix.sh includes/purge-http.php   # whole matrix
```

`race_timeout` by hand (nginx `async`, proxy holds answers 3 s, plugin timeout 1 s; `matrix.sh` does this itself):

```bash
NG="$PWD/http-purge-test/ng/nginx-run" http-purge-test/nginx.sh async
DELAY=3 python3 http-purge-test/delayproxy.py &
php http-purge-test/e2e.php race_timeout includes/purge-http.php
```

## Environment variables

| Variable | Meaning | Default |
| --- | --- | --- |
| `PLUGIN_FILE` | `purge-http.php` under test | deployed plugin in Docker, else `../includes/purge-http.php` |
| `NGROOT` | Where nginx builds live | `http-purge-test/ng` |
| `BUILD` | Source/build cache for `build-nginx.sh` | `http-purge-test/build` |
| `NG` | nginx prefix for `nginx.sh` / `matrix.sh` | `$NGROOT/nginx-run` (set from `--ng`) |
| `PHP_FPM` | php-fpm binary | `php-fpm8.3`, else `php-fpm` |
| `E2E_EXTRA` | Extra arg for `e2e.php` (e.g. `v30`) | none |
| `DELAY` | Delay-proxy hold time in `race_timeout` (s) | `3` |
| `SLOWQ_WAIT` | Plugin wait budget in `slowq*` (s) | `5` |
| `MOCK_TAIL` | Mock output lines shown | `25` |
| `NPP_INCLUDES_DIR` | Plugin `includes/` for `ns_keys` (needs `advanced.php`, `preload-defaults.php`) | `../includes`, else the deployed plugin under `$WP_PATH` |
| `WP_PATH` | WordPress root | `/var/www/html` |

## Fixed locations

Fixed paths and ports: **run only one instance at a time**.

| Resource | Location |
| --- | --- |
| Page cache (zone `NPP`) | `/tmp/npp-cache` |
| Second zone (`OTHER`, for `wrongzone`) | `/tmp/npp-other` |
| Temp dir for `scanfail` | `/tmp/npp-temp` |
| php-fpm socket, pid, log | `/tmp/fpm.sock`, `/tmp/fpm.pid`, `/tmp/fpm.log` |
| nginx | `127.0.0.1:8081` (logs in `$NG/logs/`; Docker: `/opt/npp-http-purge-test/ng/*/logs/error.log`) |
| Delay proxy | `127.0.0.1:8090` |
| `slowq*` crawl markers | `/tmp/npp-gate-files`, `/tmp/npp-gate-done`, `/tmp/npp-gate.log`, `/tmp/npp-gate-failopen` |

## Cleanup

Docker: `./actionstart-purge.sh --clean` (keeps builds) or `--purge` (deletes them).

Host:

```bash
pkill -f 'delayproxy[.]py'
for pf in http-purge-test/ng/*/logs/nginx.pid; do kill "$(cat "$pf")" 2>/dev/null; done
kill "$(cat /tmp/fpm.pid)" 2>/dev/null
rm -rf /tmp/npp-cache /tmp/npp-other /tmp/npp-temp /tmp/fpm.sock /tmp/fpm.pid /tmp/fpm.log
rm -rf http-purge-test/ng http-purge-test/build        # only to drop the builds too
```

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `nginx build missing: ... - run with --build` (exit `2`) | Add `--build`, or run `build-nginx.sh` for that branch. |
| `purge-http.php not found - set PLUGIN_FILE=...` (exit `2`) | Run from a plugin checkout or set `PLUGIN_FILE`. |
| Scenario fails right after a crashed run, or `Address already in use` on `8081` / `8090` | Leftover nginx/php-fpm/delay proxy. Clean up and retry. |
| `SKIP deletefail` | Not a failure: the filesystem under `/tmp/npp-cache` doesn't support `chattr +i`. |
| `scanfail`: `precondition: worker (nobody) can write to /tmp/npp-cache` fails | Workers run as `nobody`; `nginx.sh` chowns the dirs, which needs root. |
| nginx build fails | Check `conf-*.log` / `make-*.log` in the build dir and the `-dev` packages. |
| php-fpm not found | Set `PHP_FPM=/path/to/php-fpm`. |

## Adding a scenario

1. Different nginx config needed → add a mode to `nginx.sh`.
2. Register it in `matrix.sh`: add to the `MODE` map (scenario → mode) and to `ORDER`.
3. Add an `if ( $sc === 'name' ) { ... }` block to `e2e.php` that sets up the cache, calls the plugin and asserts with `chk( condition, 'what it proves', 'details on failure' )`.
4. Add the name to the usage header of `run-purge-tests.sh` and to the tables here.
5. If the behaviour is about answer classification, add a matching mock case in `run.php`, and keep the mock fixture identical to what the real module sends.
