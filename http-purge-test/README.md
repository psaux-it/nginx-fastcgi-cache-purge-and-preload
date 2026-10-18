# Test suite for HTTP Purge

Test suite for NPP HTTP Purge via [ngx_cache_purge](https://github.com/nginx-modules/ngx_cache_purge).

It lives in the plugin repository, outside the WordPress distribution. The Docker stack
([`psaux-it/wordpress-nginx-cache-docker`](https://github.com/psaux-it/wordpress-nginx-cache-docker))
installs it outside the web root and runs it.

| File | Role |
| --- | --- |
| `run-purge-tests.sh` | Entry point: mock harness + real-nginx matrix, builds nginx if missing, exits with code 1 on any failure. |
| `run.php` | Mock-WordPress harness. Every HTTP exchange is scripted (PHP CLI only). |
| `e2e.php` | Real-nginx scenarios (requires the nginx builds described below). |
| `matrix.sh`, `nginx.sh`, `build-nginx.sh`, `delayproxy.py`, `fpm.conf`, `site/` | Real-nginx plumbing: nginx config per mode, module build, delay proxy, WordPress stand-in. |

## What is tested

- **Mock harness (`run.php`)**: response classification branches, locks, timeouts, the cache-directory
  proof, ambiguous URLs (a miss *and* a purge in the same response) and the Purge All response timeout.
  Requires only PHP CLI.
- **Real nginx (`e2e.php`)**: the same purge logic against a real nginx with a real `ngx_cache_purge`
  module, one scenario per module mode.

Matrix scenarios: `sync`, `ambig`, `ns`, `none`, `wrongzone`, `single202`, `legacy_off`,
`race_timeout`, `race_invalidated`, `race_invalidated_single`, `ns_keys`, `race`, `race_nostatus`, `race_off`, `slowq`,
`slowq_marker`, and the v4 error-code / Vary scenarios below.

### Slow background queue (`slowq`)

nginx in `async_slow` mode (the queue needs ~35-40 s) and a plugin wait budget of 5 s (`SLOWQ_WAIT`, default 5; the plugin
caps it at 40). A caller that can defer its preload passes `$pending` to `nppp_http_purge_all()`: after the budget a queue that
is verifiably still working returns `false` plus `$pending`, and the caller must not run the filesystem purge. The test then
spawns a stand-in crawl through `nppp_http_purge_gate_command()` and checks that the spawn returns at once, that the crawl does
not start while nginx is still deleting, that it starts only when the queue is idle with no old file left, and that all 400
preloaded pages survive. With `SLOWQ_WAIT=40` the plugin waits the queue out and returns `true` (nothing pending, no gate).

### Pending Purge All seen by a later request (`slowq_marker`)

Same `async_slow` nginx and 5 s budget as `slowq`, but the crawl is spawned the way a *later* request does it: the Purge All
request is over (the in-request hand-off is cleared, transients are wiped as an object-cache flush would), and
`nppp_http_purge_gate_arm()` is **not** called. Only the persisted marker option (`nppp_http_purge_pending`) is left. The test
checks against the real queue status of `ngx_cache_purge`:

- the marker is stored as a non-autoloaded option, and without it the spawn is the plain, ungated command (control);
- with the marker, while nginx is still deleting, the spawn returns a PID at once, the crawl does **not** start, and the marker
  is not consumed: a stopped and restarted crawl is gated again;
- the crawl starts only when the queue is idle with no old file left, and all 400 preloaded pages survive;
- once the queue is idle the stale marker neither gates (byte-identical plain command) nor stays behind;
- with an unreadable status endpoint the spawn is still gated and the marker kept, and the shell gate fails open after 5 failed
  polls instead of blocking the crawl forever.

Removing the marker fallback in `nppp_http_purge_gate_state( 'take' )` makes 8 of these checks fail, among them
"the crawl started only when the real queue was idle" (it starts with ~900 old files still on disk).

### v4 error-code and Vary scenarios

Each one makes a real `ngx_cache_purge` v4 produce one catalog code, so the mock fixtures in `run.php`
are checked against what the module really answers (they were written from the module source).

| Scenario | nginx mode | Module answer | Plugin must |
| --- | --- | --- | --- |
| `denied` | `denied` | single: bare nginx `403`; purge_all: `403 access_denied` | return false, lock that endpoint only |
| `method405` | `method405` | `405 method_not_allowed`, `Allow: PURGE` | retry once with PURGE, remember it, no lock |
| `nocache` | `nocache` | `500 cache_unavailable` (purge_all without a cache zone) | return false, lock purge_all; single keeps working |
| `keymismatch` | `keymismatch` | `500 key_mismatch` (namespace key contract broken) | return false, lock purge_all |
| `wrongep` | `wrongep` | `200 status` (single bound to `cache_purge_queue_status`) | not treat it as a purge, lock single |
| `deletefail` | `sync` | `500 delete_failed` (one file made immutable with `chattr +i`) | return false, no lock; skipped where `chattr` is unsupported |
| `scanfail` | `scanfail` | `500 scan_failed` (workers run as `nobody`, one cache dir is mode 000) | return false, no lock |
| `badsel` | `vary` | `400 invalid_selector` (exact key over 511 bytes with Vary-aware purge) | return false, no lock; next URL still works |
| `vary` | `vary` | `200 purged` for variants when the requested variant is not cached | return true; all variants gone |
| `vary_off` | `vary_off` | `200 no_match` while Vary variants are still cached | pin the documented limitation: `miss`, variants stay; `nppp_http_purge_trust_miss` => false is the workaround |

`/v/...` on the stand-in site (`site/index.php`) answers `Vary: Accept-Encoding`, which is what produces the variants.
Not reproducible without extra modules or builds: `invalid_cursor` (needs a proxy REFRESH location) and
`internal_error`. A full queue is not an error in v4 (the request is deferred), and no ngx_cache_purge version answers 429.

## Run it in the Docker stack

From the `wordpress-nginx-cache-docker` checkout (see `actionstart-purge.sh --help`):

```bash
./actionstart-purge.sh                                    # mock harness + real-nginx matrix (v4 build, compiled once)
./actionstart-purge.sh --only mock                        # seconds, no nginx build
./actionstart-purge.sh --only matrix sync ambig           # selected scenarios only
./actionstart-purge.sh --file ./includes/purge-http.php   # test a candidate file instead of the deployed one
```

The tests run inside the `wordpress-fpm` container against the plugin deployed there, on
`127.0.0.1:8081` of that container (separate from the stack's own nginx). Nginx builds and state live in
the `npp_purge_lab` volume.

## Run it directly (host or container)

```bash
http-purge-test/run-purge-tests.sh --only mock                      # PLUGIN_FILE defaults to ../includes/purge-http.php
http-purge-test/run-purge-tests.sh --build --only matrix            # builds nginx + ngx_cache_purge v4 into ./ng on first use
http-purge-test/run-purge-tests.sh --ng v30 --only matrix sync ambig
```

`--ng` selects the module build: `v4` (default), `v30` or `v25`. For `v30` and `v25` the default
scenarios are `sync` and `ambig`; name others explicitly.

Requirements for the real-nginx matrix: `git`, `build-essential`, `libpcre2-dev`, `zlib1g-dev`,
`libssl-dev`, `php-fpm` (8.3 preferred), PHP CLI, `curl` and `python3`. The `scanfail` scenario runs the nginx
workers as `nobody` (the container runs it as root), and `deletefail` needs a filesystem that supports `chattr +i`.

### Environment variables

| Variable | Meaning | Default |
| --- | --- | --- |
| `NGROOT` | Where the nginx builds live | `http-purge-test/ng` (next to the scripts) |
| `BUILD` | Source/build cache used by `build-nginx.sh` | `http-purge-test/build` (wrapper), `$NGROOT/build` (raw script) |
| `PLUGIN_FILE` | `purge-http.php` under test | deployed plugin in the Docker stack, else `../includes/purge-http.php` |
| `PHP_FPM` | php-fpm binary | `php-fpm8.3`, else `php-fpm` |
| `E2E_EXTRA` | Extra argument passed to `e2e.php` (e.g. `v30`) | none |
| `DELAY` | Seconds the delay proxy holds answers in `race_timeout` | `3` |

## Run the pieces manually

Mock harness:

```bash
php http-purge-test/run.php includes/purge-http.php
```

Build the nginx variants (the nginx version is pinned in `build-nginx.sh`):

```bash
http-purge-test/build-nginx.sh releases/stable-4.0 http-purge-test/ng/nginx-run      # v4 (with --with-threads)
http-purge-test/build-nginx.sh releases/stable-3.0 http-purge-test/ng/nginx-run303   # v3.0
http-purge-test/build-nginx.sh releases/stable-2.5 http-purge-test/ng/nginx-run256   # v2.5
```

Bring a mode up and run a scenario:

```bash
NG="$PWD/http-purge-test/ng/nginx-run" http-purge-test/nginx.sh sync    # also: async async_slow ns none wrongzone single202 legacy_off ...
php http-purge-test/e2e.php ambig includes/purge-http.php               # a purge on /p/%41/ is not a success
php http-purge-test/e2e.php sync  includes/purge-http.php
```

Run the whole matrix against one build:

```bash
NG="$PWD/http-purge-test/ng/nginx-run" http-purge-test/matrix.sh includes/purge-http.php
```

### Purge All timeout race

nginx in `async` mode, the delay proxy holds the answer for 3 s, the plugin timeout is 1 s:

```bash
NG="$PWD/http-purge-test/ng/nginx-run" http-purge-test/nginx.sh async
DELAY=3 python3 http-purge-test/delayproxy.py &
php http-purge-test/e2e.php race_timeout includes/purge-http.php
```

## Fixed locations

The real-nginx tests use fixed paths and ports, so run only one instance at a time:

| Resource | Location |
| --- | --- |
| Page cache | `/tmp/npp-cache` |
| php-fpm socket | `/tmp/fpm.sock` |
| nginx | `127.0.0.1:8081` |
| Delay proxy | `127.0.0.1:8090` |

`http-purge-test/site/index.php` stands in for WordPress, and `http-purge-test/fpm.conf` runs it.
