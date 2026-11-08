# Test suite for wget / wget2 preload and URL normalization

Regression suite for the wget layer of **NPP**: [`includes/compat-wget.php`](../includes/compat-wget.php) builds every `wget` / `wget2` command line the plugin runs (Preload All crawl, single URL preload, preload on update, related URLs, the preflight probe), optionally behind [`safexec`](../safexec) and its `libnpp_norm.so` shim, which drops privileges, enforces an allowlist and can normalize the case of `%xx` escapes in the request target. [`includes/purge-http.php`](../includes/purge-http.php) adds the shell gate, waiter and watchdog that hold the crawl back while nginx is still deleting after Purge All.

It guards against three failure modes:

- **Wrong cache key**: the same URL reaches nginx as `%c3%a9` from one preload path and as `%C3%A9` from another (safexec or not, wget or wget2, HTTP/1.1 or HTTP/2), so preload warms an object the visitor never asks for.
- **Silent downgrade**: the safexec binary is older than the plugin's minimum, or refuses the command, and wget2 runs **without** the sandbox and without normalization, with no error.
- **Crawl in the wrong moment**: the crawl starts while nginx's purge queue is busy (it deletes what was just warmed), or never starts because the queue status is unreadable.

The oracle is nginx itself. It logs the raw `$request_uri` it received (the HTTP/1.1 request-line or the HTTP/2 `:path`), and every assertion compares that with what the same command sends without safexec.

## Quick start

Linux host, from this repository, as root:

```bash
sudo wget-test/run-wget-matrix.sh --only=static --flavors=wget          # seconds, no traffic
sudo wget-test/run-wget-matrix.sh --quick --flavors=wget                # a few minutes
sudo wget-test/run-wget-matrix.sh                                       # all flavors, full corpus
php  wget-test/test-wget-compat.php wget                                # no root, no nginx
```

> **Run it somewhere disposable.** The matrix must run as root. It **overwrites `/usr/local/bin/safexec`, `/usr/local/bin/safexec-asis` and `/usr/lib/npp/libnpp_norm.so`** with binaries built from the checkout (the first one with its version forced to the plugin minimum), starts nginx and two Python receivers on fixed loopback ports, deletes `$NPPM_WORK` (default `/tmp/npp-work`) at the start and kills any process whose command line contains `NPPM-` at the end. Nothing restores the previous safexec.

## Layers

| Group | What runs | Needs |
| --- | --- | --- |
| `static` | Guards on the sources, once per run (first flavor): safexec version vs the plugin minimum, wget call sites vs the door definitions, hard-coded `wget`, the `SAFEXEC_PCTNORM` env mapping, positive and negative controls for safexec. | gcc, PHP |
| `wire` | Every door x binary x safexec on/off x normalization mode x transport x URL corpus. The request target nginx received is compared with the one the same command sends without safexec. | + nginx (ssl + http_v2), wget, wget2 |
| `gate` | The Purge All preload gate in a real `sh`, against a scripted queue status endpoint. | as `wire` |
| `aux` | The deferred Purge All waiter, the watchdog POST, the network probe. | as `wire`, python3 |
| `proxy` | `use_proxy=yes` through a fake forward proxy: `wget -e http_proxy` and `wget2 --http-proxy`. | as `wire`, python3 |

Everything runs in one PHP process per flavor (`matrix.php <flavor>`). `test-wget-compat.php` is separate: it builds the same command strings the plugin would and does not start anything.

## Files

| File | Role |
| --- | --- |
| `run-wget-matrix.sh` | Entry point: builds safexec and the shim from the checkout, installs them, renders and starts nginx and the receivers, runs `matrix.php` for each flavor, exits `1` on any failure. |
| `matrix.php` | The harness: WordPress stubs, the door builder, the oracle, the four groups. Writes one TSV row per assertion. |
| `test-wget-compat.php` | Standalone: `nppp_wget_build_command()` against the hard-coded v2.1.9 command strings (byte for byte) for `wget`, the generated `wget2` commands for the same inputs. |
| `nginx.conf.in`, `locations.conf.in` | nginx templates. Three listeners (plain HTTP/1.1, TLS HTTP/1.1, TLS + ALPN h2), `merge_slashes off`, access log in the `wire` format. `/queue-status` serves the queue file the harness swaps. |
| `postrecv.py` | POST receiver: records `METHOD path body` (waiter and watchdog tests). |
| `proxyrecv.py` | Fake forward proxy: records `UA\|METHOD\|TARGET\|PROTO`, the raw request-line as sent (absolute-form targets). |
| `site/page.html`, `site/crawl/index.html` | Fixtures. Every path answers `200`. The crawl page links to URLs with mixed-case hex triplets. |

## How it works

- **Real commands**: every command is the real output of `nppp_wget_build_command()` with the option set of the matching call site, run through the same safexec environment the plugin exports (`SAFEXEC_QUIET`, `SAFEXEC_SAFE_CWD`, `SAFEXEC_DETACH`, `SAFEXEC_PCTNORM`, `SAFEXEC_PCTNORM_CASE`).
- **Doors**: `preflight` (`nppp_detect_premature_process()`), `preload_all` (`nppp_preload_locked()`, recursive crawl), `single` (`nppp_preload_single_locked()`), `on_update` (`nppp_preload_cache_on_update()`), `related` (`nppp_preload_urls_fire_and_forget()`, several URLs in one process).
- **Modes** (what the admin setting maps to):

| Mode | `SAFEXEC_PCTNORM` | `SAFEXEC_PCTNORM_CASE` | Expected on the wire |
| --- | --- | --- | --- |
| `off` | `0` | unset | Identical to the no-safexec run. |
| `preserve` | `1` | `off` | Each URL keeps its own hex case. |
| `upper` | `1` | `upper` | Only the hex case changes, to upper. |
| `lower` | `1` | `lower` | Only the hex case changes, to lower. |

- **Flavors**: `wget` (GNU Wget 1.x), `wget2`, and `wget2-as-wget` (Fedora `wget2-wget`, where `/usr/bin/wget` **is** Wget2). safexec pins the tool from its trusted directories and ignores `PATH`, so the third flavor bind-mounts `wget2` over `wget` inside a private mount namespace (`unshare -m`), the host is not changed.
- **Transports**: `http`, `h1s` (TLS, HTTP/1.1 only) for every flavor, `h2` (TLS + ALPN h2) for the wget2 flavors. The `h2-negotiated` assertion makes sure Wget2 really used HTTP/2, otherwise the `:path` hook would be untested.
- **Corpus**: `mixed`, `lower`, `upper`, `digits`, `plain`, `slash` (`%2f`), `resv` (reserved characters), `raw8` (raw UTF-8), `query`, `many` (40 triplets) for single-URL doors, and five multi-URL sets for `related` (`rel_mixed_lower`, `rel_lower_upper`, `rel_upper_lower`, `rel_short_long`, `rel_plain_mixed`). `--quick` reduces the single-URL corpus to `mixed`, `lower`, `raw8`, `plain`.
- **Severity**: `FAIL` is a broken invariant (exit `1`), `WARN` is a documented limit or a suspicious result (never fails the run), `INFO` is context. For `preload_all` a `preserve` mismatch is downgraded to `WARN`, the crawl's own inputs are not known exactly.
- **Safexec builds**: the *as-is* build (`safexec-asis`, the version the repository ships) is what the static version guard measures. The *matrix* build is the same source with `SAFEXEC_VERSION` forced to `NPPP_SAFEXEC_WGET2_MIN`, so that the plugin's version gate lets wget2 through. That only passes the gate: it adds no wget2 allowlist.
- **Ambient proxy variables** (`http_proxy`, `NO_PROXY`, `WGETRC` and friends) are scrubbed for every child process, so a CI box or sandbox cannot change what wget does behind the harness' back.

## Requirements

Root, and: `gcc`, `php` (CLI), `nginx` built with `ssl` and `http_v2`, `wget`, `wget2`, `openssl`, `stdbuf` (coreutils), `python3`, `curl`. `unshare` (util-linux) for `wget2-as-wget`, otherwise that flavor is `SKIP`. The runner checks the first seven and stops with `FATAL: missing tool` otherwise.

## Docker stack

Not wired into [`wordpress-nginx-cache-docker`](https://github.com/psaux-it/wordpress-nginx-cache-docker). `wp-post.sh` strips this folder from the deployed plugin directory. The reasons it does not run there:

- It replaces the stack's safexec and shim, and `command -v safexec` then returns the harness binary for the NPP preload of the same container.
- It needs an nginx with `ssl` and `http_v2`. `wordpress-fpm` has no nginx, and the purge lab's source-built nginx has neither module.
- It builds safexec from `safexec/`, which the edge deploy removes from the plugin directory.
- Its fixtures would need `chmod go+rX`, the lab installer makes lab directories root-only and nginx workers (`nobody`) would get `403`.

Run it on a disposable Linux host, VM or CI runner from a repository checkout. `wget` and `wget2` have to be installed there (the Docker stack image carries `wget2` once its Dockerfile installs it).

## Host usage

```bash
sudo wget-test/run-wget-matrix.sh [--quick] [--only=GROUP[,GROUP]] [--flavors=LIST] [--keep]
```

| Option | Meaning |
| --- | --- |
| `--quick` | Reduced single-URL corpus. |
| `--only=GROUP,...` | Groups `static`, `wire`, `gate`, `aux`, `proxy`. |
| `--flavors=LIST` | Comma list of `wget`, `wget2`, `wget2-as-wget`. Default: all three. The static guards run once, on the first flavor. |
| `--keep` | Keep `$NPPM_WORK/tmp` (nginx temp paths). |

Exit codes: `0` all flavors pass, `1` a `FAIL` somewhere (details above the summary), `2` usage or lab error (not root, missing tool, build failed, nginx did not start, h2 listener does not negotiate HTTP/2).

Per flavor the run ends with `-- wget: N PASS, N FAIL, N WARN (Ns)` and the list of failing ids. The same rows, `PASS` included, are in `$NPPM_WORK/results-<flavor>.tsv` (`SEVERITY<TAB>id<TAB>message`).

Wire ids read `wire:flavor:door:transport:set:sfx|nosfx:mode:assertion#N`, for example `wire:wget:related:h1s:rel_lower_upper:sfx:preserve:preserve#1`: the `preserve` assertion on the second request (`#1`, counted from 0) of that run. Gate, aux and proxy ids start with `gate:`, `aux:` and `proxy:`.

`test-wget-compat.php` needs neither root nor nginx:

```bash
php wget-test/test-wget-compat.php wget          # builder output == v2.1.9 hard-coded strings, byte for byte
php wget-test/test-wget-compat.php wget2         # the generated wget2 commands for the same inputs
php wget-test/test-wget-compat.php wget2 print   # print them
```

## What is covered

### `static`

| Id | Proves |
| --- | --- |
| `S1a` | `SAFEXEC_VERSION` in `safexec/safexec.c` is at least `NPPP_SAFEXEC_WGET2_MIN`. Otherwise the plugin treats safexec as too old and runs wget2 without it. |
| `S1b` | The same for `NPPP_SAFEXEC_STDBUF_MIN` (the `stdbuf` wrapper). |
| `S1c` | With the as-is (repository) safexec, the command builder still puts it in front of wget2. Only checked when the installed `wget` flavor is Wget2. |
| `S2a`, `S2b` | Every `nppp_wget_build_command()` call site is covered by a door, and the option keys of each call site match the door. A new call site must get a door in `matrix.php`. |
| `S3` | No hard-coded `wget` outside `nppp_wget_bin()` (`WARN`). |
| `S4` | The admin `putenv` mapping of the normalization setting equals the harness' `sfx_env()`. |
| `S5` | Positive control: a valid wget2 command runs under safexec. Negative controls: wget2 without `--` before the URL, and without `--no-config`, are refused. |

### `wire`

Per door, flavor, transport, corpus entry and `safexec` on/off, the request nginx saw is compared with the native one (same command without safexec and shim).

| Assertion | Proves |
| --- | --- |
| `:started` | The command ran and made as many requests as the native run. |
| `:h2-negotiated` | Wget2 spoke `HTTP/2.0` on the h2 listener. |
| `:identity#N` | No safexec, or safexec with `PCTNORM=0`: the target is untouched. |
| `:upper#N`, `:lower#N` | Only the hex case changes. |
| `:preserve#N` | Each URL keeps its own hex case, also several URLs in one process. |
| `LOCALE-DIVERGENCE` (`WARN`) | The same URL arrives with a different hex case under safexec (`LANG=C.UTF-8`) than without, even with normalization off. A case-sensitive cache key then differs between the safexec and the no-safexec preload path. Only `upper`, `lower` and `preserve` make it deterministic. |

### `gate`

The Purge All preload gate runs in a real `sh`, with and without safexec. A queue status endpoint (`/queue-status`, Host `queue.test.local`) is swapped by the harness between idle, busy, garbage and missing.

| Case | Proves |
| --- | --- |
| `A-idle` | The user request returns at once with a pid, two idle polls, the crawl starts promptly on its own start URL. |
| `B-busy` | No crawl request while the queue is busy, the crawl starts after the flip to idle. |
| `C-404` | An unreadable status (`404`) fails open after 5 polls, never a stuck crawl. |
| `D-garbage` | A garbage body (an HTML error page from a proxy) fails open the same way. |
| `E-hold` | A hold file for a pending deferred follow-up holds the crawl, releases it on release, and fails open when the hold expires or its content is garbage. |
| `F-shape` | Command shape: `exec` prefix detection (wget2 with a directory becomes `mkdir -p ..; cd .. && exec ...`, wget1 stays a plain command), a single `exec`, a `;` in the directory path. Without a pending hold the gate emits the plain command. |

### `aux`

| Case | Proves |
| --- | --- |
| `W1` | The deferred Purge All waiter (`nppp_http_purge_purged_all_waiter_command()`) waits for an idle queue, then POSTs the wake token **once**. |
| `W1b` | It does not POST while the queue is busy. |
| `W2` | The watchdog POST, with the flags copied from `includes/watchdog.php` (drift-guarded). |
| `N1` | The network probes of `nppp_check_network_env()`: the verbatim command and verdict logic of `includes/preload.php`. |

### `proxy`

`use_proxy=yes` against `proxyrecv.py`, one and two URLs, with and without safexec, in every mode. The proxy must really be used (the origin nginx sees no direct request), the target must be absolute-form (`http://127.0.0.1:PORT/...`), and the same normalization rules as `wire` apply to the target the proxy received.

Not covered: real internet hosts, redirects and cookies, `--limit-rate` accuracy, wget builds without TLS, `wget2` HTTP/3, the plugin's own decision *whether* to preload (only the commands and what they send).

## Cleanup

```bash
rm -rf /tmp/npp-work /tmp/npp-build                 # NPPM_WORK, NPPM_BUILD
sudo pkill -f NPPM-                                 # stray wget processes of an aborted run
sudo rm -f /usr/local/bin/safexec-asis              # the harness' extra binary
# reinstall the real safexec package (this restores /usr/local/bin/safexec and /usr/lib/npp/libnpp_norm.so)
```

The runner's exit trap stops nginx and both receivers, kills `NPPM-` processes and removes `$NPPM_WORK/tmp` (unless `--keep`). It does not undo the three installed files.

## Environment variables

| Variable | Meaning |
| --- | --- |
| `NPPM_WORK` | Work directory (default `/tmp/npp-work`), deleted at the start of every run. Holds certs, nginx config and logs, `wire.log`, `post.log`, `proxy.log` and the TSV results. |
| `NPPM_BUILD` | Build directory for the shim and the two safexec builds (default `/tmp/npp-build`). |

`matrix.php` reads `NPPM_REPO`, `NPPM_WORK`, `NPPM_SAFEXEC`, `NPPM_SAFEXEC_ASIS`, `NPPM_SO`, `NPPM_ALIAS_DIR`, `NPPM_PORT_*`, `NPPM_QUICK` and `NPPM_ONLY`. The runner exports them, set them by hand only to start `matrix.php` on its own.

Fixed loopback ports: `18080` (nginx HTTP), `18443` (TLS HTTP/1.1), `18444` (TLS h2), `18081` (POST receiver), `18082` (fake proxy). [`cloudflare-test`](../cloudflare-test)'s mock control API also uses `18080`, do not run the two at the same time.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `FATAL: run as root` / `missing tool: wget2` | See [Requirements](#requirements). |
| `FATAL: nginx config invalid` / `nginx did not start (see .../error.log)` | nginx lacks `ssl` or `http_v2` (`nginx -V`), or a harness port is taken. |
| `FATAL: h2 listener does not negotiate HTTP/2` | `curl` without HTTP/2 support, or nginx built without `http_v2`. |
| `S1a`, `S1b`, `S5:positive-control-wget2-accepted` fail, wget2 cells fail with `'wget2' is not allowed by safexec` | `safexec/safexec.c` has no wget2 support yet and its version is below `NPPP_SAFEXEC_WGET2_MIN`. This is the harness running ahead of safexec: these stay red until the safexec release with wget2 is in the checkout. |
| `FAIL ...:preserve#N`: `shim changed the case but not to the input URL's case` | The shim does not restore each URL's own hex case in `preserve` mode. Read `input`, `native`, `wire`, `want` in the message. |
| `WARN ...:LOCALE-DIVERGENCE` | Documented: safexec's locale changes the hex case even with normalization off. Use `upper`, `lower` or `preserve`. |
| `403` for every wire request | nginx workers cannot read the `site/` fixture (the folder was copied with root-only permissions). Run from a normal checkout, or `chmod -R go+rX wget-test/site`. |
| `SKIP wget2-as-wget: bind mount not permitted here` / `unshare -m unavailable` | No mount namespace (container without `CAP_SYS_ADMIN`, or no util-linux). Run on a host or VM. |
| `proxy:...:proxy-honored` fails | The origin saw a direct request: the proxy flag was ignored by that wget build. |
| Different results between runs | Another process uses the fixed ports, or ambient proxy settings in a wrapper script. Check `ss -ltnp` for `18080-18082`, `18443`, `18444`. |

## Adding a case

1. A new wget call site in the plugin: add a door to `door_cmd()` (copy the option set of the call site), the door's key set to `$door_keys` in the `S2` guard, and an entry to `$door_sets` in the wire group.
2. A new URL shape goes into `$SETS_FULL` (and `$SETS_QUICK` if it is cheap and important). Multi-URL shapes go into `$MULTI`.
3. A new gate or waiter case: add it to the `gate` / `aux` group with `qs( QS_IDLE | QS_BUSY )` or `qs_remove()` to set the queue status, `gate_case()` / `gate_start()` to start the shell gate, `wait_wire()` to wait for requests.
4. Assert with `check( condition, 'id', 'details on failure', 'FAIL'|'WARN' )`. Use `WARN` only for a limit you want to document, `rec( 'INFO', ... )` for context.
5. A new binary or env knob belongs into `$FLAVORS` or `sfx_env()`, and into the `S4` mapping guard when it comes from the admin setting.
