# Purge All: `ngx_cache_purge` vs the NPP PHP Recursive Deleter

Question: when HTTP Purge is on and `cache_purge_background_queue` is **off**, nginx walks the cache **synchronously inside the
Purge All request**. How long does that take on realistic caches (5k / 10k / 20k files, up to 1.6 GB), how does it compare with the
plugin's own PHP deleter (`nppp_delete_tree()`), and when does it run into the plugin's 30 s HTTP timeout?

Everything below was **measured**, except the clearly marked extrapolations.

## TL;DR

- **At 5k-20k files (up to 1.6 GB) the synchronous module walk takes 0.9-6 s**, nowhere near the 30 s HTTP timeout. The PHP deleter takes 1.3-5 s. Neither is an order of magnitude better: warm caches favour the module (PHP 1.2-1.5x slower), cold caches at 10k+ files favour PHP by 5-17 %.
- **Both delete roughly 3,100-6,100 files/s on VM.** The 30 s timeout is crossed at roughly **130k files** (measured 23.2 s at 100k, linear extrapolation), but the real variable is the *per-file cost of your storage* (see table below). Fast local SSD: ~0.25 ms/file. FUSE / bindfs / network storage: milliseconds per file, so a few thousand files are enough.
- **The sync walk blocks an nginx worker for its whole duration.** Visitors whose keep-alive connection is pinned to that worker wait the full purge time (4.9 s at 20k cold, 23 s at 100k). PHP deletion and `cache_purge_background_queue on` leave the site responsive (worst response typically under 10 ms; one outlier run at 75 ms — the tables show medians).
- **Background queue on = same total work, no blocking:** HTTP `202` in ~1 ms, files gone after 3.8-6.4 s (20k) and 23.6 s (100k).
- **Directory handling differs:** the module only unlinks files (the 1:2 directory tree stays), PHP also `rmdir`s every directory.
- **The module validates each file **before** unlinking:** the MD5 of the `KEY:` line must match the trailing 32 hex digits of the filename. Files that fail validation, or whose header/`KEY:` is malformed, are silently skipped — even under Purge All. PHP has no such gate: `nppp_delete_tree()` unlinks everything.

## Test setup

| | |
|---|---|
| Machine | 2 vCPU Xeon @ 2.1 GHz, 8 GB RAM, ext4 on virtio disk, kernel 6.18 (VM) |
| nginx | 1.31.6, `worker_processes 2`, `thread_pool default threads=2` |
| Module | [nginx-modules/ngx_cache_purge](https://github.com/nginx-modules/ngx_cache_purge) `releases/stable-4.0` (4.0.0-rc2, `5c2b7d9`), sync mode: `fastcgi_cache_purge GET purge_all from all;`, background queue and namespaces off |
| Plugin | [psaux-it/nginx-fastcgi-cache-purge-and-preload](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload) branch `v2.1.9` (`57e57608`) |
| PHP deleter | the **real** `nppp_delete_tree()` extracted from `includes/wp-filesystem.php` at run time (not re-typed), driven by the same top-level `DirectoryIterator` loop and protected-temp-dir skip as `nppp_wp_purge()` Step 2. PHP 8.3.6 CLI |
| Cache layout | `fastcgi_cache_path ... levels=1:2 keys_zone=NPP:256m max_size=200g`, real nginx cache files created by real requests through nginx |
| File sizes | log-normal (sigma 0.9), deterministic per URL. `wp`: median 24 KB, mean ~40 KB (typical WordPress HTML). `heavy`: median 48 KB, mean ~80 KB (page-builder / WooCommerce), 20k files = **1.6 GB** |
| Cold vs warm | warm = `sync`, dentry/inode/page cache populated. cold = `sync; echo 3 > /proc/sys/vm/drop_caches` right before the purge |
| Repetitions | 3 per cell up to 20k files, 2 for 50k/100k. Every run starts with nginx restarted on an empty cache dir |
| Site-stall probe | during every purge, 8 keep-alive connections fetch a cached URL from a *second* cache zone every 5 ms. The purge never touches that zone, so any slow answer means the worker is busy |

Method order inside one run: restart nginx -> populate N files -> settle (warm/cold) -> start probes -> purge -> verify directory has 0 files -> request a purged URL twice (must be `MISS` then `HIT`).

## Results

#### Purge All duration (seconds, median of 3 [min-max])
| Cache | Files | Size (apparent / on disk) | Kernel caches | ngx_cache_purge sync | PHP `nppp_delete_tree` | PHP / module |
|---|---:|---|---|---:|---:|---:|
| wp 5k | 5,000 | 201 MB / 223 MB | warm | 0.88 [0.77-0.88] | 1.29 [1.22-1.31] | 1.47x |
| wp 5k | 5,000 | 201 MB / 223 MB | cold | 1.20 [1.15-1.21] | 1.63 [1.40-2.51] | 1.36x |
| wp 10k | 10,000 | 400 MB / 435 MB | warm | 1.65 [1.40-2.55] | 2.20 [2.06-2.51] | 1.34x |
| wp 10k | 10,000 | 400 MB / 435 MB | cold | 2.51 [2.39-2.52] | 2.39 [2.29-2.71] | 0.95x |
| wp 20k | 20,000 | 804 MB / 862 MB | warm | 3.35 [3.23-4.10] | 4.09 [4.00-4.80] | 1.22x |
| wp 20k | 20,000 | 804 MB / 862 MB | cold | 4.86 [4.28-6.30] | 4.30 [4.20-4.87] | 0.89x |
| heavy 20k | 20,000 | 1,598 MB / 1,656 MB | warm | 4.07 [3.98-5.03] | 4.95 [4.63-6.83] | 1.22x |
| heavy 20k | 20,000 | 1,598 MB / 1,656 MB | cold | 5.87 [5.32-6.02] | 4.89 [4.88-5.49] | 0.83x |

#### Throughput (files/s, median)
| Cache | Kernel caches | ngx_cache_purge sync | PHP `nppp_delete_tree` |
|---|---|---:|---:|
| wp 5k | warm | 5,714 | 3,879 |
| wp 5k | cold | 4,177 | 3,069 |
| wp 10k | warm | 6,075 | 4,537 |
| wp 10k | cold | 3,981 | 4,179 |
| wp 20k | warm | 5,972 | 4,895 |
| wp 20k | cold | 4,117 | 4,647 |
| heavy 20k | warm | 4,913 | 4,041 |
| heavy 20k | cold | 3,408 | 4,092 |

#### Does the site stall while the purge runs?
8 persistent (keep-alive) connections fetch an unrelated cached URL every 5 ms from a second cache zone while the purge runs.

| Cache | Kernel caches | Method | purge duration (s) | worst response (ms) | requests >100 ms | p99 (ms) |
|---|---|---|---:|---:|---:|---:|
| wp 5k | warm | module sync (worker walks) | 0.88 | 874 | 5 | 1.7 |
| wp 5k | warm | PHP delete | 1.29 | 4 | 0 | 1.4 |
| wp 5k | cold | module sync (worker walks) | 1.20 | 1,195 | 3 | 1.5 |
| wp 5k | cold | PHP delete | 1.63 | 7 | 0 | 2.0 |
| wp 10k | warm | module sync (worker walks) | 1.65 | 1,644 | 4 | 1.5 |
| wp 10k | warm | PHP delete | 2.20 | 5 | 0 | 1.6 |
| wp 10k | cold | module sync (worker walks) | 2.51 | 2,509 | 3 | 1.5 |
| wp 10k | cold | PHP delete | 2.39 | 5 | 0 | 1.4 |
| wp 20k | warm | module sync (worker walks) | 3.35 | 3,347 | 4 | 1.2 |
| wp 20k | warm | PHP delete | 4.09 | 6 | 0 | 1.4 |
| wp 20k | cold | module sync (worker walks) | 4.86 | 4,856 | 3 | 1.4 |
| wp 20k | cold | PHP delete | 4.30 | 6 | 0 | 1.7 |
| heavy 20k | warm | module sync (worker walks) | 4.07 | 4,070 | 4 | 1.4 |
| heavy 20k | warm | PHP delete | 4.95 | 9 | 0 | 1.5 |
| heavy 20k | cold | module sync (worker walks) | 5.87 | 5,866 | 4 | 1.4 |
| heavy 20k | cold | PHP delete | 4.89 | 10 | 0 | 1.4 |

Reading the ">100 ms" column: it counts stalled *requests*, i.e. roughly how many of the 8 probe connections were pinned to the busy worker. For the sync walk the stalled requests last as long as the purge itself (the "worst response" column equals the purge duration).

#### Module with `cache_purge_background_queue on` (defaults, thread pool)
| Cache | Kernel caches | HTTP response (s) | HTTP code | files gone after (s) | worst probe response (ms) |
|---|---|---:|---|---:|---:|
| wp 20k | warm | 0.001 [0.000-0.001] | 202 | 3.79 [3.73-3.83] | 8 |
| wp 20k | cold | 0.001 [0.000-0.002] | 202 | 5.55 [5.09-5.58] | 6 |
| heavy 20k | warm | 0.000 [0.000-0.001] | 202 | 4.73 [4.70-4.85] | 6 |
| heavy 20k | cold | 0.001 [0.000-0.001] | 202 | 6.37 [6.08-6.96] | 8 |
| wp 100k | cold | 0.001 [0.001-0.001] | 202 | 23.57 [22.96-24.19] | 10 |

#### Scaling to 100k files (cold kernel caches, median of 3 up to 20k, of 2 above)
| Cache | Files | Size (apparent) | ngx_cache_purge sync (s) | PHP `nppp_delete_tree` (s) | worst site stall during module purge (ms) |
|---|---:|---:|---:|---:|---:|
| wp 5k | 5,000 | 201 MB | 1.20 [1.15-1.21] | 1.63 [1.40-2.51] | 1,195 |
| wp 10k | 10,000 | 400 MB | 2.51 [2.39-2.52] | 2.39 [2.29-2.71] | 2,509 |
| wp 20k | 20,000 | 804 MB | 4.86 [4.28-6.30] | 4.30 [4.20-4.87] | 4,856 |
| wp 50k | 50,000 | 2,014 MB | 11.32 [10.75-11.88] | 9.85 [9.70-10.00] | 11,316 |
| wp 100k | 100,000 | 4,031 MB | 23.18 [22.91-23.44] | 17.22 [16.66-17.78] | 23,175 |

### When does the 30 s HTTP timeout bite?

The plugin gives the Purge All request 30 s by default (`nppp_http_purge_timeout`, `purge-http.php`), then waits up to 15 s more (`nppp_http_purge_queue_wait`) before falling back to the PHP filesystem purge. How many files fit in 30 s is simply `30 s / per-file cost`:

| Per-file cost | Typical storage | Files that fit in 30 s |
|---|---|---:|
| 0.23 ms (VM, cold, module sync, 100k files) | local virtio SSD | ~130,000 |
| 1 ms | slower SSD, busy disk | 30,000 |
| 5 ms | HDD, network block storage | 6,000 |
| 10 ms | FUSE / bindfs / NFS | 3,000 |

Only the first row was measured; the others are arithmetic on the per-file cost, shown to make the dependency obvious. Re-run the harness on your own disk (set `CACHE=` to the real cache volume) to fill in your row.

## What the numbers say

1. **The 30 s timeout is not a problem for ordinary caches on local SSD.**
   20k files (0.8-1.6 GB) purge in 3.4-5.9 s with the module and 4.1-5.0 s
   with the PHP deleter. On this VM the module's cold cost is ~0.23 ms/file
   (23.2 s for 100k), so the walk would cross 30 s at ~130k files; PHP
   (~0.17 ms/file at 100k) at ~175k. Both figures are linear extrapolations
   from the 100k runs.

2. **The timeout bites on storage with high per-file latency** (FUSE, bindfs,
   NFS, network volumes, busy HDD). Files that fit in 30 s = 30 s / per-file
   cost. The table is module-based; only the first row was measured.
   - The sync walk cannot be cancelled when the client gives up: it runs
     inside the worker until the tree is finished (the walk is called with
     limit 0), so it keeps going after the 30 s mark.
   - The PHP fallback then competes with it for the same slow storage. That is
     safe (the module ignores ENOENT on stat/open/unlink, and
     `nppp_delete_tree()` treats a vanished path as deleted), but it is not a
     speedup. It only helps when the module failed fast or skipped entries
     (PHP also unlinks files the module's key validation rejects).
   - The module does more per-file work than PHP (open, fstat, three small
     reads, close, unlink vs. PHP's lstat/stat + unlink), so on high-latency
     storage it is likely to lose more than PHP. Not measured.

3. **Sync mode costs the site more than it costs the admin.** The admin waits
   ~5 s at 20k, but the worker running the walk serves nothing else. With
   2 workers, 3-5 of 8 probe connections stalled (about half); in general
   expect roughly 1/worker_processes of established connections to stall for
   the full purge (worst 4.9 s at 20k cold, 23 s at 100k). p99 does not show
   this because few requests are affected; use "worst response" and
   ">100 ms". With the background queue the call returns 202 in ~1 ms and the
   worst probe stays ≤10 ms. The files are gone 2-16 % later than with the
   sync walk (e.g. 20k wp cold 5.55 s vs 4.86 s; 100k 23.6 s vs 23.2 s):
   same work, spread out, slightly longer.

4. **PHP vs module: no clear winner; it depends on cache temperature and size.**
   - Warm (≤20k): module 1.2-1.5x faster.
   - Cold: module faster at 5k (PHP 1.36x slower), equal at 10k, PHP faster
     from 20k up: 11 % (wp 20k), 17 % (heavy 20k), 13 % (50k), 26 % (100k).
   - Per file the module does readdir, stat, open, fstat, three reads (header,
     marker, key), MD5, close, unlink (`ngx_http_cache_purge_read_key()`,
     `ngx_http_cache_purge_delete_file()`). PHP does readdir, lstat/stat,
     unlink, then rmdir per directory; it never reads file contents.
   - Likely explanation (not profiled): when warm, C code with all data in the
     page cache beats PHP's interpreter overhead; when cold, the module's
     header read adds a data-block read per file that PHP avoids.
   - The module never removes directories; PHP does.

5. **Background queue + the plugin's default 15 s wait.** Measured drain times
   give 0.24-0.32 ms/file cold (100k wp 0.236; heavy 20k 0.318), so 15 s
   corresponds to roughly 45-65k files cold, ~65-80k warm (extrapolated).

## Caveats

- One VM, one filesystem (ext4 on virtio). Docker overlayfs, bindfs/FUSE, NFS and spinning disks will be slower per file, and the module/PHP ratio can differ there.
- The PHP side is the plugin's deletion routine, run from PHP CLI. A full `nppp_wp_purge()` call additionally needs WordPress (`WP_Filesystem` init, path validation, a first-file `KEY:` probe that stops at the first hit), which by code inspection should add little; it was not measured. In PHP-FPM, `max_execution_time` applies unless lifted; the plugin calls `set_time_limit(0)` in `purge.php`.
- Files are small and freshly written. Real caches that aged for weeks have a colder, more fragmented directory tree: use the *cold* rows as the closer match.
- Nothing else was running on the box. A busy production disk (preload crawling, MySQL, backups) will be slower.
- The extrapolation to 30 s assumes constant per-file cost between 100k and ~130k files, which held between 20k (4.9 s cold) and 100k (23.2 s cold).

## Reproduce

Run from a checkout of the plugin repo; everything below is relative to the repo root. All paths default to the repo layout, so no `NG` / `PLUGIN` / `MODULE` exports are needed unless you build nginx elsewhere.

```bash
# 0. build nginx + the module (installs under http-purge-test/ng/, module checkout in http-purge-test/ng/build/)
apt-get install -y build-essential libpcre2-dev zlib1g-dev libssl-dev php-cli php-fpm git
http-purge-test/build-nginx.sh releases/stable-4.0 http-purge-test/ng/nginx-run

# 1. run the harness (needs root: drop_caches, php-fpm as root)
export CACHE=/var/cache/npp-bench/cache OTHER=/var/cache/npp-bench/other   # point at the volume you want to test
http-purge-test/benchmark/run-all.sh          # full matrix, ~30 minutes
python3 http-purge-test/benchmark/report.py   # Markdown tables from results/results*.jsonl
```

Defaults (override with env vars if your layout differs): `PLUGIN` = repo root, `NGROOT` = `http-purge-test/ng`, `NG` = `$NGROOT/nginx-run`, `MODULE` = `$NGROOT/build/mod-releases_stable-4.0`.

Quick check on your own disk: `python3 http-purge-test/benchmark/bench.py --profiles wp:20000 --temps cold --methods module-sync,php --reps 1 --out /tmp/quick.jsonl`.

Files (all under `http-purge-test/benchmark/`): `bench.py` (orchestrator, nginx conf generator, probes), `php_delete.php` (extracts and runs the plugin's real `nppp_delete_tree()`), `site/index.php` (log-normal body sizes), `fpm.conf`, `run-all.sh`, `report.py`, raw data and environment snapshots in `results/` (`results*.jsonl`, `results*-env.json`).
