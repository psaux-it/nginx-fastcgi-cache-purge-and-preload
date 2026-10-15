# http-purge-test

Test suit for HTTP Purge via ngx_cache_purge.
Lives in the plugin repo, outside the WordPress distribution, and the
Docker stack (`psaux-it/wordpress-nginx-cache-docker`) installs it outside the web root and runs it.

| File | Role |
| --- | --- |
| `run-purge-tests.sh` | Entry point: mock harness + real-nginx matrix, build-if-missing, exit code 1 on any failure. |
| `run.php` | Mock-WordPress harness, every HTTP exchange scripted (PHP CLI only). |
| `e2e.php` | Real-nginx scenarios (needs the nginx builds below). |
| `matrix.sh`, `nginx.sh`, `build-nginx.sh`, `delayproxy.py`, `fpm.conf`, `site/` | Real-nginx plumbing: nginx config per mode, module build, delay proxy, WordPress stand-in. |

## Run it in the Docker stack

From the `wordpress-nginx-cache-docker` checkout (see its `actionstart-purge.sh --help`):

    ./actionstart-purge.sh                       # mock harness + real-nginx matrix (v4 build, compiled once)
    ./actionstart-purge.sh --only mock           # seconds, no nginx build
    ./actionstart-purge.sh --only matrix sync ambig
    ./actionstart-purge.sh --file ./includes/purge-http.php    # test a candidate file instead of the deployed one

It runs inside `wordpress-fpm` against the plugin deployed there, on 127.0.0.1:8081 of that container
(separate from the stack's own nginx). Nginx builds and state live in the `npp_purge_lab` volume.

## Run it directly (host or container)

    http-purge-test/run-purge-tests.sh --only mock                     # PLUGIN_FILE defaults to ../includes/purge-http.php
    http-purge-test/run-purge-tests.sh --build --only matrix           # builds nginx + ngx_cache_purge v4 into ./ng on first use
    http-purge-test/run-purge-tests.sh --ng v30 --only matrix sync ambig

Path variables: `NGROOT` (nginx builds, default `http-purge-test/ng`, i.e. `./ng` next to the scripts),
`BUILD` (sources), `PLUGIN_FILE`, `PHP_FPM` (default `php-fpm8.3`, else `php-fpm`), `E2E_EXTRA` (extra e2e.php
argument, e.g. `v30`).

---

## Original usage (any host)

## 1. Mock harness (no nginx needed, ~1 min)

    php http-purge-test/run.php includes/purge-http.php        # 500 passed, 0 failed

Every HTTP exchange is scripted: classification branches, locks, timeouts, the
cache-directory proof, ambiguous URLs (miss AND purged), Purge All response timeout.
Needs only PHP CLI. The same file run against the older h8 purge-http.php fails 38 of the new checks.

## 2. Real nginx + real ngx_cache_purge

Build (needs git build-essential libpcre2-dev zlib1g-dev libssl-dev, php-fpm8.3, curl, python3):

    http-purge-test/build-nginx.sh dev/development-4.0  http-purge-test/ng/nginx-run      # v4 (with --with-threads)
    http-purge-test/build-nginx.sh releases/stable-3.0  http-purge-test/ng/nginx-run303   # v3.0
    http-purge-test/build-nginx.sh releases/stable-2.5  http-purge-test/ng/nginx-run256   # v2.5

Bring a mode up, run a scenario:

    NG=http-purge-test/ng/nginx-run http-purge-test/nginx.sh sync        # also: async async_slow ns none wrongzone single202 legacy_off ...
    php http-purge-test/e2e.php ambig includes/purge-http.php      # purged on /p/%41/ is not a success
    php http-purge-test/e2e.php sync includes/purge-http.php

Purge All timeout race (nginx in `async` mode, delay proxy holds the answer for 3 s, plugin timeout 1 s):

    NG=http-purge-test/ng/nginx-run http-purge-test/nginx.sh async
    DELAY=3 python3 http-purge-test/delayproxy.py &
    php http-purge-test/e2e.php race_timeout includes/purge-http.php

Fixed locations: /tmp/npp-cache, /tmp/fpm.sock, nginx on 127.0.0.1:8081, delay proxy on 8090.
http-purge-test/site/index.php stands in for WordPress; http-purge-test/fpm.conf runs it.
