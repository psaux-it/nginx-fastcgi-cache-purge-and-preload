# Test suite for Varnish / Vinyl Cache sync

Regression suite for the **NPP** Varnish / Vinyl Cache sync in [`includes/compat-varnish.php`](../includes/compat-varnish.php). Once NPP has purged nginx, the sync listens on `nppp_purged_urls`, `nppp_purged_all` and `nppp_preload_all_started` and sends `PURGE` requests to up to five Varnish endpoints: one `PURGE` per URL, or a single host-wide `PURGE /.*` with `X-Purge-Method: regex` that the VCL turns into a ban.

It guards against two failure modes:

- **False "purged"**: the plugin logs success, Varnish keeps serving the page (wrong control key, ACL refusal, VCL without a regex branch, a request target rewritten on the wire).
- **Wrong order**: Varnish is purged while nginx is still deleting, so it re-caches stale HTML, or the preload crawl is answered by a stale Varnish object and nginx is never warmed. NPP's rule is inner layer first, outer layer second.

The VCL under test is not a copy. The harness cuts the "Example VCL" out of [`includes/help.php`](../includes/help.php), the text a site owner copies from the Help tab.

## Quick start

Docker stack ([`wordpress-nginx-cache-docker`](https://github.com/psaux-it/wordpress-nginx-cache-docker)), from the stack checkout:

```bash
./actionstart-varnish.sh --only unit sanitize   # ~15 s, PHP only
./actionstart-varnish.sh                        # unit + sanitize + e2e + deferred
./actionstart-varnish.sh --topology             # real containers: edge, varnish, origin
```

Linux host, from this repository:

```bash
varnish-test/run-varnish-tests.sh --only unit sanitize
varnish-test/run-varnish-tests.sh               # everything, needs varnishd
```

> **Run it somewhere disposable.** The e2e and deferred layers start varnishd and a backend on loopback ports. The topology layer recreates the `nginx` and `wordpress` containers and changes NPP settings (restored by `--clean`).

## Layers

| Layer | Entry | What runs | Needs |
| --- | --- | --- | --- |
| `unit` (22 sections) | `run-varnish-tests.sh` | Real plugin code on a WordPress stub, scripted HTTP answers. | PHP 7.4+ |
| `sanitize` | `run-varnish-tests.sh` | The Varnish blocks of `settings-sanitize.php`, cut out and run as they are. | PHP 7.4+ |
| `e2e` (11 sections) | `run-varnish-tests.sh` | The plugin's PURGE over real sockets to a real varnishd with the documented VCL, in front of a counting backend. | `varnishd`, `varnishadm`, `python3`, `cc`, `php-curl` |
| `deferred` (8 sections) | `run-varnish-tests.sh` | Real Purge All and Preload All call chain, real shell gate and waiter, scripted nginx queue, real varnishd. | as `e2e`, plus `curl`, `pkill`, `bash` |
| `topology` (11 sections) | `run-topology-tests.sh` | Real plugin driven with WP-CLI against the real containers of the Docker stack. | the stack, see below |

The first four layers need no WordPress. The topology layer only runs inside the Docker stack.

Each section runs in its own PHP process. The plugin keeps per-request statics (endpoint circuit-breaker memo, "Purge All runs once" guard) that must not leak between sections.

## Files

| File | Role |
| --- | --- |
| `run-varnish-tests.sh` | Entry for `unit`, `sanitize`, `e2e`, `deferred`. Runs sections, prints one line each, sums the assertions. |
| `run-topology-tests.sh` | Entry for the topology layer. Runs inside `wordpress-fpm` as root. |
| `tests/unit.php`, `tests/sanitize.php` | Scripted layers. |
| `tests/e2e.php`, `tests/deferred.php` | Real-varnishd layers. |
| `tests/topology.php` | Container topology layer. |
| `lib/wpstub.php` | Minimal WordPress: options, hook registry that really dispatches, `wp_remote_request()` with a scripted and a real curl transport, `chk()` / `known()` / `skip()`. |
| `lib/lab.php` | Starts varnishd and the backend, extracts and varies the VCL, `lab_prime()` / `lab_is_cached()` helpers. |
| `lib/topo.php` | Topology helpers: WP-CLI as the pool user, `X-Varnish` and `NPP-FastCGI-Cache` readers, VCL variant detection. |
| `lab/backend.py` | Counting origin. Every request that reaches it is counted per Host + raw target. |

## How it works

- **Scripted** (`unit`): `vt_script( '#regex#', response )` answers `"METHOD url"` matches, newest script wins. A URL with no script goes to a real socket, except `.test` names, where a missing script is reported as a test bug.
- **Real varnishd** (`e2e`, `deferred`): "the backend count did not move" means Varnish answered from cache, "it moved" means the object was gone. Counters are read from the backend port, never through Varnish.
- **Topology**: the two layers are read separately. Varnish through the number of ids in `X-Varnish` (1 = MISS, 2 = HIT), nginx through `NPP-FastCGI-Cache` on a GET straight to the origin `:8080`. The origin is always read first, because a MISS there fills nginx.
- `chk()` must hold. `known()` documents a limit of the plugin: it passes while the behaviour is as written down and **fails** when it changes, so a fixed gap (or a regression hiding one) forces the line and the docs to be updated.
- A failing assertion named `BUG?` (in `deferred`) is a candidate plugin defect, not a harness problem. The detail line says why.

## Docker stack

Run on the Docker host, from the directory with `docker-compose.yml`. The pieces involved:

| File (stack repo) | Role |
| --- | --- |
| `actionstart-varnish.sh` | Brings everything up, installs the VCL, configures NPP, runs the tests. |
| `docker-compose.lab.yml` | Mounts the volume `npp_varnish_lab` at `/opt/npp-varnish-test` in `wordpress-fpm`. |
| `docker-compose.varnish.yml` | Adds the `varnish` service, switches the vhost of `nginx` and `wordpress`. |
| `nginx/default.varnish.conf` | Vhost with three listeners: 443 edge, 8080 origin, 8443 purge. |
| `wordpress/wp-post.sh` | Deploys the plugin and installs this folder into the lab volume. |

How `varnish-test/` gets into the container: with `NPP_EDGE_=1` (the default in `.env.example`), `wp-post.sh` clones the newest `v*` branch of this repository (currently `v2.1.9`), moves `varnish-test/` out of the web root into `/opt/npp-varnish-test`, and deploys the rest as the plugin. The lab volume is only used when `docker-compose.lab.yml` is part of the compose set. To test a local checkout instead, copy it in first:

```bash
./actionstart-varnish.sh --src ../plugin/varnish-test --only unit sanitize
```

Tests run from the lab directory, plugin code comes from the **deployed** plugin (`NPPP_INCLUDES` points at it). `--src` replaces the harness only, not the plugin.

### Layer 1: harness inside `wordpress-fpm`

`./actionstart-varnish.sh [--only ...]` runs `run-varnish-tests.sh` inside `wordpress-fpm` and does not touch the topology. For `e2e` and `deferred` it installs the Debian `varnish`, `python3` and `build-essential` packages when they are missing (lost when the container is recreated). That is the distribution's Varnish, not the official image, so this layer says nothing about the image version.

### Layer 2: `--topology`

```
visitor ──https──> nginx:443  (edge, TLS only)
                      └─http──> varnish:80
                                   └─http──> nginx:8080  (origin, fastcgi_cache)
                                                └─fastcgi──> wordpress-fpm:9001

wp npp purge ──https──> nginx:8443   (HTTP Purge listener, internal)
             ──PURGE──> varnish:80   (Varnish sync)
```

The purge locations live on a separate listener (8443) so a visitor can never reach them through the origin port Varnish uses. The purge URL is `https` on purpose: NPP only trusts a purge answer when the scheme matches the page scheme. Cache entries are created over plain http (Varnish to 8080), so the key starts with `http`.

What the script does, in order:

1. Starts the stack with `docker-compose.yml` + `docker-compose.lab.yml` + `docker-compose.varnish.yml`. `nginx` and `wordpress` are recreated when they do not run the varnish vhost yet.
2. Waits for `wp-post.sh` (`.npp-ready`) and checks that `compat-varnish.php` was deployed.
3. Cuts the Example VCL out of the deployed `help.php`, sets the ACL to the PHP container IP (`--acl ip`) or the whole Docker subnet (`--acl subnet`), writes the control key, appends `backend default` pointing at `nginx:8080`, then `docker cp` + `varnishadm vcl.load` / `vcl.use`. The VCL is regenerated on every run, so a recreated `wordpress-fpm` (new IP) is picked up by running the script again.
4. Points NPP at it: HTTP Purge on, `Purge Custom Base URL = https://nginx:8443`, queue status path `npp_purge_status`, Varnish endpoint `http://varnish:80`, control header `X-Control-Key: <key>`, sync on. The previous Purge Custom Base URL is saved for `--clean`.
5. Runs wiring checks (these are not plugin tests): PURGE with, without and with a wrong key, PURGE through the edge, purge endpoints on 443 / 8080 / 8443, a page that is a Varnish HIT with the `NPP-FastCGI-Cache` header, a regex ban that turns it into a MISS.
6. Hands over to `run-topology-tests.sh` inside `wordpress-fpm`, with the addresses in `TOPO_*` variables.

Varnish 6.0 (`stable`) has no `std.ban()`. The script rewrites that block to the plain `ban()` statement and warns, which is a deviation from the Help text.

### Script options

| Option | Effect |
| --- | --- |
| *(none)* | Layer 1. Arguments such as `--only unit:parse e2e:auth` are passed through. |
| `--topology [phase or section ...]` | Layer 2. Default phases: `happy faults`. |
| `--image varnish:fresh` / `old` / `stable` | Image tag. `stable` is the 6.0 LTS line. |
| `--acl ip` / `subnet` | ACL = PHP container `/32` (default) or the Docker subnet. |
| `--vcl example` / `noregex` / `aclnone` / `nopurge` | Install a deliberately broken variant. Wiring checks are expected to fail, the `vclclass` section classifies it by behaviour. |
| `--src DIR` | Copy the harness from a local checkout first. |
| `--build` | Rebuild the stack images. |
| `--no-run` | Prepare everything, run nothing. |
| `--status` | Show containers, active VCL, ACL line and NPP settings. |
| `--shell` | Root shell in the lab directory. |
| `--clean` | Stop the layer 1 varnishd. If a topology is active: restore NPP settings, remove the `varnish` container, recreate `nginx` and `wordpress` with the base vhost. |
| `--purge` | `--clean` + delete the lab `run/` state. |

Overrides: `C` (default `wordpress-fpm`), `VC` (`varnish`), `NGX` (`nginx`), `WP_USER`, `WP_PATH`, `LAB_DIR`, `VARNISH_IMAGE`, `VARNISH_CONTROL_KEY` (default `npp-lab-control-key`), `NET` (`npp-wp`), `WAIT` (300 s per stage). Varnish is also published on `127.0.0.1:6081` for debugging (`VARNISH_DEBUG_PORT`).

## Host usage

```bash
varnish-test/run-varnish-tests.sh                              # all four layers
varnish-test/run-varnish-tests.sh --only unit sanitize
varnish-test/run-varnish-tests.sh --only unit:parse e2e:single deferred:holdgate
varnish-test/run-varnish-tests.sh --no-e2e                     # skip layers that need varnishd
varnish-test/run-varnish-tests.sh --list
```

| Option | Meaning |
| --- | --- |
| `--only LAYER ...` | Whole layers (`unit`, `sanitize`, `e2e`, `deferred`) or `layer:section`. |
| `--no-e2e` | Skip `e2e` and `deferred`. |
| `--strict` | A skipped section (missing tool) counts as a failure. |
| `-v` | Print the output of passing sections too. |

Exit codes: `0` all passed, `1` a section failed, `2` usage or lab error, `77` skipped (a tool is missing). Environment: `PHP`, `NPPP_INCLUDES` (plugin `includes/`, default `../includes`), `VT_LAB` (work directory), `VT_KEEP_LAB=1` (keep varnishd logs and VCL after the run).

`run-topology-tests.sh` refuses to start without `TOPO_VARNISH` and `TOPO_KEY`. Start it through `actionstart-varnish.sh --topology`. Phases and sections:

```bash
./actionstart-varnish.sh --topology                  # happy + faults
./actionstart-varnish.sh --topology preload          # opt-in, slow
./actionstart-varnish.sh --topology all
./actionstart-varnish.sh --topology single badkey
```

A lab error (exit `2`: edge unreachable, WP-CLI broken) stops the run, since every later section would fail for the same reason. Exit `77` means every section was skipped, which is not a pass.

## What is covered

### `unit` and `sanitize`

| Sections | Pinned down |
| --- | --- |
| `parse`, `config` | Endpoint list (max 5, schemes, ports, IPv6, IDN, duplicates collapsed before the cap) and control header (reserved names, CRLF, length limits). Switch must be exactly `yes`. Filters are re-validated. |
| `classify`, `scope`, `dedupe` | Single `PURGE` for clean paths. One host-wide purge for percent-encoded, non-ASCII, `//`, backslash, dot-segment, whitespace paths, rewritten query characters and more than 50 unique paths. Other host, port or scheme is skipped before escalation. |
| `single`, `all`, `preload`, `allveto`, `allfail` | Wire contract: `Host`, `X-Purge-Method`, `Content-Length: 0`, control header, no redirects, 4 KB response cap. One host-wide `PURGE /.*` per endpoint. Purge All followed by the preload trigger in the same request sends once. Veto filter per context. |
| `results`, `state` | `404` and 2xx count as done. `401`, `403`, `405`, `501` stop that endpoint for the event and warn once per 5 min. Other errors warn once. Transport errors park the endpoint for 5 min in the state store, shared by single purge and Purge All. |
| `budget`, `hang`, `transport` | About 5 s per hook, 1 s connect timeout, a hanging Varnish costs one 3 s timeout. Real closed port, blackhole and DNS failure. |
| `sslverify`, `hosts`, `exceptions`, `logging`, `logreason`, `fallback` | TLS verify off by default, IPv6 sites, Host filter, throwing filters contained, log lines never carry address, port or secret, behaviour without `purge-http.php`. |
| `source` | Static checks on the plugin: hook fire points before the preload, breaker reset on settings change, off by default, one HTTP call site, the `/.*` literal. |
| `sanitize` | Settings save path: invalid input is cleared with one settings error, nothing half-valid is stored, secret never echoed, breaker reset only on a real change. |

### `e2e`

| Section | Proves |
| --- | --- |
| `vcl` | The documented VCL and its variants compile (`varnishd -C`). |
| `single` | One `PURGE` removes exactly one object. Other queries, paths and Hosts survive. A regex-looking path is purged as a literal. |
| `purgeall` | Host-wide ban hits every object of the site host, none of another host, not objects stored later. |
| `preload` | `nppp_preload_all_started` purges, and does not duplicate a purge from the same request. |
| `escalate` | Why escalation exists: a single `PURGE` of a lower-case-hex URL answers 2xx and deletes nothing once WordPress rewrites the target. Each trigger becomes one ban. 50 URLs single, 51 one ban. |
| `scope` | Host header follows `home_url()` including the port. Foreign URLs send nothing. |
| `auth` | Wrong or missing key (403), ACL refusal (405), no PURGE handler (405 or 501): object stays, one warning, no secret in the log. |
| `noregex` | VCL with only `return (purge)`: Purge All answers 200 and deletes nothing. Documented gap. |
| `multi`, `tls` | Dead endpoint parked, live ones still purged. `https://` in front of a plain-HTTP Varnish is unreachable. |
| `lurker` | The ban expression uses `obj.*` only and the ban lurker kills every object without a request. |

### `deferred`

Purge All behind nginx's background queue (status 7), Auto Preload and the order rule. Only nginx is scripted (`ngx_cache_purge` v4 answers and a queue status endpoint). The crawl is a shim `wget` that really fetches through Varnish.

| Section | Proves |
| --- | --- |
| `sync`, `gen`, `async` | Controls. Hook fires once before the crawl, one ban, the crawl warms nginx. |
| `deferred` | Queue busy: no ban while nginx still deletes, crawl gated. One ban after idle through the waiter's wake request, token usable once. |
| `waiterdead` | Waiter dead, WP-Cron only. The crawl holds until the ban went out. |
| `preloadall` | Direct Preload All behind a busy queue. |
| `supersede` | Re-arm, superseding Purge All, deadline fail-open, opt-out filter. At most one ban per Purge All, never lost. |
| `holdgate` | The crawl gate's hold phase in a real `sh`: holds while a follow-up is pending, fails open on expiry or garbage. |

### `topology`

| Phase | Section | Proves |
| --- | --- | --- |
| happy | `wiring` | Settings read back, Varnish in the path, nginx cache key has the `http` scheme. |
| happy | `single` | Purged page is a MISS in nginx and Varnish, another page stays cached in both. |
| happy | `purgeall` | Every page is a MISS in both layers, refilled objects cached again. |
| happy | `outsider` | Visitor `PURGE` through the edge is refused, `/npp_purge_*` is 404 on edge and origin. |
| faults | `vclclass` | Active VCL found by behaviour, must match the installed variant. |
| faults | `foreignhost` | `PURGE` or ban for another Host never removes an object of this site. |
| faults | `badkey` | 403, one warning, nginx purged, Varnish keeps the stale page. |
| faults | `dns`, `refused`, `blackhole` | One warning, nginx purge unaffected, breaker, command returns inside the time budget. |
| preload | `preload` | After Purge All a full preload refills nginx and Varnish through the edge. Needs `wget` and the mitmproxy container. |

Not covered: stopping or starting the `varnish` container, a hostname in the ACL whose IP changes on recreate, two Varnish containers, Hitch, `cache_purge_background_queue`.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `compat-varnish.php not found in ...` (exit `2`) | Run from a `v2.1.9` checkout or set `NPPP_INCLUDES`. |
| `SKIP e2e/...: varnishd not found` (exit `77`) | Install `varnish`, `python3`, a C compiler and `php-curl`, or use `--no-e2e`. `--strict` turns it into a failure. |
| `run-varnish-tests.sh missing` from `actionstart-varnish.sh` | The deployed branch has no `varnish-test/`. Pass `--src <checkout>/varnish-test`. |
| `compat-varnish.php not in ...` from `actionstart-varnish.sh` | Deployed plugin has no Varnish support. Needs the `v2.1.9` branch and `NPP_EDGE_=1`. |
| `Example VCL block ... not found in help.php` or `example VCL no longer contains ...` (exit `2`) | `help.php` was refactored. Update `lab_vcl()` in `lib/lab.php` and the substitutions in `actionstart-varnish.sh`. |
| `SKIP topology/...: needs N page(s) that both layers cache` | A usable page is one whose second visitor GET is a Varnish HIT. Check `curl -skI https://nginx/ -H 'Host: localhost'`. |
| Wiring checks fail with `--vcl noregex` / `aclnone` / `nopurge` | Expected, the variant is broken on purpose. |
| `WARNING VARNISH ... transport error` instead of `connection refused` | libcurl 8 says "Couldn't connect to server". Cosmetic, the harness asks the local libcurl and adjusts. |

## Adding a section

1. Add the name to `UNIT_SECTIONS`, `E2E_SECTIONS` or `DEFERRED_SECTIONS` in `run-varnish-tests.sh` (topology: `ALL_SECTIONS` and the phase lists in `run-topology-tests.sh`, `$SECTIONS` in `tests/topology.php`).
2. Add an `if ( $S === 'name' ) { ... }` block to the test file and list it in the file header.
3. Use `chk( condition, 'what it proves', 'details on failure' )`. Use `known()` only for a limit you want to pin. A detail that does HTTP must be a closure, so it only runs on failure.
4. A new `e2e` or `deferred` section must start its own varnishd with `lab_start_varnish()` and leave nothing behind, teardown is automatic.
