# fail2ban-test

end-to-end test lab for the NPP Fail2Ban webhook and RIPEstat IP enrichment chain.
It exercises the whole path without a real Fail2Ban, without real attackers and
without any real RIPEstat traffic: `stat.ripe.net` is redirected to a local fake
RIPEstat server that serves synthetic, deterministic data.

<img width="2720" height="1400" alt="Image" src="https://github.com/user-attachments/assets/7685eef7-f062-4b30-93f7-c0242f5a953f" />

> **Lab only.** `setup-lab.sh` edits `/etc/hosts`, adds a CA to the system trust
> store and installs a mu-plugin. Never run it on a production host.

## Contents

| File | Role |
| --- | --- |
| `setup-lab.sh` | One-time wiring: private CA + `stat.ripe.net` certificate, hosts entry, system trust, `f2b-lab.php` mu-plugin. `--remove` undoes it. |
| `run-e2e.sh` | Starts `fake_ripestat.py` if needed, then runs `e2e.py`. This is the normal entry point. |
| `e2e.py` | Test driver. Runs the phases, resets state between them, prints PASS/FAIL and a summary. Exit code 1 on any failure. |
| `fake_f2b_producer.py` | Stands in for the `nppp-webhook` Fail2Ban action. Sends the same `POST` with a Bearer token and JSON body, with scenarios and assertions. |
| `fake_ripestat.py` | Local stand-in for `https://stat.ripe.net` with deterministic per-IP data, fault injection and a control API. |

`pki/` (CA and certificates, including a private key) and `run/` (pid file, logs,
IP lists) are created at runtime and are git-ignored.

## Requirements

- Linux, root access (needs `/etc/hosts` and port 443 on `127.0.0.2`)
- Python 3.8+ (standard library only)
- `openssl`, `curl`, `update-ca-certificates` (package `ca-certificates`)
- WP-CLI available as `wp`
- A WordPress site with NPP **2.1.8 or newer** active, reachable over HTTP(S)
  from the machine that runs the tests
- Nginx + PHP-FPM in front of WordPress (the webhook is a real REST call)

## Setup

```bash
cd /path/to/wp-content/plugins/fastcgi-cache-purge-and-preload-nginx/fail2ban-test
sudo ./setup-lab.sh /var/www/html        # path of the WordPress install
```

What it does:

1. Creates a private CA and a server certificate for `stat.ripe.net` (SAN) under `./pki`.
   The CA carries `keyUsage=keyCertSign`, so Python 3.13+ strict verification accepts it.
2. Adds `127.0.0.2 stat.ripe.net` to `/etc/hosts`. All of `127.0.0.0/8` is loopback, so the
   fake can own `:443` without clashing with nginx on `127.0.0.1`.
3. Adds the lab CA to the **system** trust store. The worker uses `request_multiple()`,
   whose curl handles ignore the WordPress CA bundle and fall back to libcurl's system CAs.
4. Writes `wp-content/mu-plugins/f2b-lab.php`, which shortens the RDAP retry timers so
   fault tests finish in seconds:

   | Filter | Lab value | Production default |
   | --- | --- | --- |
   | `nppp_f2b_rdap_retry_gap` | 2 s | 120 s |
   | `nppp_f2b_rdap_exhausted_retry_gap` | 4 s | 900 s |
   | `nppp_f2b_rdap_negative_cache_ttl` | 20 s | 300 s |

The plugin itself is not modified. The script is idempotent.

## Running the tests

```bash
sudo ./run-e2e.sh                          # happy, edge, lifecycle, faults
sudo ./run-e2e.sh --only happy,faults      # selected phases
sudo ./run-e2e.sh --with-ratelimit         # also run the rate-limit phase
sudo ./run-e2e.sh --only happy --count 100 # more IPs in the happy phase
```

Options (passed through to `e2e.py`):

| Option | Default | Meaning |
| --- | --- | --- |
| `--only a,b` | all but `ratelimit` | Comma list of phases |
| `--with-ratelimit` | off | Add the `ratelimit` phase |
| `--count N` | 40 | IPs in the `happy` phase |
| `--timeout S` | 120 | Seconds to wait for the queue to drain |

Environment variables:

| Variable | Default | Meaning |
| --- | --- | --- |
| `WP_PATH` | `/var/www/html` | WordPress root |
| `WP_USER` | `www-data` | User WP-CLI runs as when the driver is root. Use the PHP-FPM pool user so runtime files stay writable. |
| `SITE_URL` | `http://127.0.0.1:8080` | Base URL of the site. The webhook is `<SITE_URL>/wp-json/nppp_f2b/v1/event`. An `https://` URL is called with TLS verification off. |

The webhook token is read with `wp option get nppp_f2b_token`.

### Phases

Every phase starts from a clean slate: events table, RDAP transients, rate counter,
worker state, plugin log and fake-server statistics.

| Phase | What it verifies |
| --- | --- |
| `happy` | Concurrent bans (each sent twice, IPv4 and IPv6) are accepted, replays collapse, the queue drains, every stored profile equals what the fake served, exactly one whois + one abuse request per IP, never more than 8 concurrent requests, `sourceapp` and `User-Agent` are correct, clean worker start/stop in the log. |
| `edge` | Validation matrix of the producer (bad token, bad JSON, bad jail, private IPs, ...). Non-public IPs are stored with a blank profile and never sent to RIPEstat. The `test` probe leaves no row. The EP10 gate logs the first bad-token attempt once, with a masked IP. |
| `lifecycle` | Ban, replayed ban, unban and re-ban of one IP across two jails: 3 ban rows, 1 unban row, one shared enrichment, a single RIPEstat lookup. |
| `faults` | Injected failures per IP: HTTP 500, 404, 429, hang (plugin timeout), invalid JSON, and RIPEstat's bogus "authoritative rir could not be identified" answer. Checks retries, partial progress kept, the retry budget (`max_attempts`) and that the bogus answer is not stored as registry data. |
| `ratelimit` | More than 300 events in one fixed 60 s window: the overflow is answered with 429 and the limit is logged once. Run it last; it fills the plugin's counter. |

`wait_drain()` sends a "nudge" ban if the queue has not drained after 20 s. A worker that
exited while IPs were still cooling down is respawned by the next webhook event, exactly as
the next real ban would do. A `sent a nudge ban` line in the output means that happened.

## Using the tools on their own

### `fake_f2b_producer.py`

```bash
python3 fake_f2b_producer.py ban --count 50 --concurrency 4 --dup 2 \
    --url https://example.test/wp-json/nppp_f2b/v1/event --token <TOKEN> --insecure
python3 fake_f2b_producer.py lifecycle --wp-path /var/www/html --url <URL>
```

Scenarios: `ban`, `unban`, `lifecycle`, `edge`, `ratelimit`, `test`.
Token source, in order: `--token`, `NPPP_F2B_TOKEN`, `--wp-path` (WP-CLI).
Useful flags: `--jail`, `--seed` (same seed, same IPs), `--v6-ratio`, `--concurrency`,
`--rate`, `--dup` (curl `--retry` replay simulation), `--ips-out` / `--ips-in`, `--insecure`.

### `fake_ripestat.py`

```bash
sudo python3 fake_ripestat.py -v                         # 127.0.0.2:443, TLS
sudo python3 fake_ripestat.py --fault "8.8.8.8,whois,http:500,2"
```

It answers `/data/whois/data.json` and `/data/abuse-contact-finder/data.json`, emulates the
"max 8 concurrent requests per source IP" rule and records everything. Control API under
`/__ctl`: `stats`, `requests?limit=N`, `profile?ip=<ip>`, `faults` (GET/POST), `reset` (POST).

Fault rule: `{"match": "*"|"<ip>"|"<cidr>", "endpoint": "whois"|"abuse"|"*", "mode": "<mode>", "times": <int|null>}`

Modes: `http:<code>`, `hang`, `reset`, `slow:<ms>`, `badjson`, `shape`, `status_error`,
`rir_unknown`, `empty`.

## Running inside the Docker stack

Works with [wordpress-nginx-cache-docker](https://github.com/psaux-it/wordpress-nginx-cache-docker),
which deploys the latest `v*` branch of this plugin, including this directory, into the
`wordpress-fpm` container.

1. Give the container the hosts entry. `/etc/hosts` is bind-mounted by Docker and cannot be
   edited with `sed -i`, so `setup-lab.sh` must not write it. Use a compose override
   (`docker-compose.lab.yml`):

   ```yaml
   services:
     wordpress:
       extra_hosts:
         - "stat.ripe.net:127.0.0.2"
   ```

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.lab.yml up -d --force-recreate wordpress
   ```

2. Make sure `python3` exists in the container (install it, or add it to the image).

3. Run the lab:

   ```bash
   D=/var/www/html/wp-content/plugins/fastcgi-cache-purge-and-preload-nginx/fail2ban-test
   docker exec wordpress-fpm getent ahosts stat.ripe.net          # first line must be 127.0.0.2
   docker exec -e LAB_IN_DOCKER=1 wordpress-fpm $D/setup-lab.sh /var/www/html
   docker exec -e WP_USER=npp -e SITE_URL=https://nginx wordpress-fpm $D/run-e2e.sh
   ```

   - `LAB_IN_DOCKER=1` makes `setup-lab.sh` check for the hosts entry instead of writing it.
   - `WP_USER=npp` is the PHP-FPM pool user of the stack.
   - `SITE_URL=https://nginx` reaches nginx over the compose network.

Notes for the Docker stack:

- The worker runs inside the same container as the driver, which is why the driver belongs
  there and not on the host.
- The stack's updater syncs the plugin directory with `rsync --delete`. After a plugin update
  `pki/` and `run/` are gone. Run `setup-lab.sh` again.
- Files under `wp-content/plugins/` are served by nginx. Deny the directory so the lab CA
  private key in `pki/` cannot be downloaded:

  ```nginx
  location ^~ /wp-content/plugins/fastcgi-cache-purge-and-preload-nginx/fail2ban-test/ { deny all; }
  ```

## Cleanup

```bash
sudo pkill -f 'fake_ripestat[.]py'
sudo ./setup-lab.sh --remove /var/www/html    # hosts entry, mu-plugin, system CA (pki/ is kept)
```

In Docker, prefix with `docker exec -e LAB_IN_DOCKER=1 wordpress-fpm $D/` and drop `sudo`.
`f2b-lab.php` lives in the WordPress tree. If you skip `--remove`, a site with 2-second retry
timers stays behind.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `stat.ripe.net does not resolve to 127.0.0.2` | `setup-lab.sh` has not run, or in Docker the `extra_hosts` entry is missing. Check with `getent ahosts stat.ripe.net` (not `getent hosts`, which can fall through to DNS). |
| `CERTIFICATE_VERIFY_FAILED ... CA cert does not include key usage extension` | The CA in `pki/` predates the `keyUsage` fix. Run `--remove`, delete `pki/` and `run/fake_ripestat.pid`, stop the fake, run `setup-lab.sh` again. |
| Still the old certificate after regenerating | The running fake keeps the old cert in memory and `run-e2e.sh` reuses it while the pid file is alive. Kill it and delete the pid file. |
| `fake RIPEstat not reachable as https://stat.ripe.net` | `setup-lab.sh` not run, fake not started, or port 443 on `127.0.0.2` is taken. See `run/fake_ripestat.log`. |
| `sed: cannot rename /etc/hosts: Device or resource busy` | Running in Docker without `LAB_IN_DOCKER=1`. |
| `wp-cli failed` | Wrong `WP_PATH`, plugin inactive, or NPP older than 2.1.8 (no Fail2Ban subsystem). |
| `producer ... all accepted` fails with 4xx | Wrong `SITE_URL`, the Fail2Ban feature is off, or the token changed. Read the detail printed under the FAIL line. |
| `queue drained` fails | The webhook accepted events but no worker ran or the RDAP calls never reached the fake. Read `run/fake_ripestat.log` and the plugin log. |
| `ratelimit` makes later runs fail for a minute | It fills the plugin's 300 events per 60 s counter. Wait a minute or run it last. |
