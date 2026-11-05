# Purge Single benchmark

Compares the three layers the plugin can use to purge ONE page (plus its related URLs):

| Layer | Plugin function | How it purges |
|:---|:---|:---|
| `http` | `nppp_purge_fp1_http()` | one HTTP request per target to the ngx_cache_purge "single" location |
| `rg`   | `nppp_purge_fp3_rg()`   | one ripgrep pass over the whole cache dir for all targets, then unlink |
| `scan` | `nppp_purge_fp4_scan()` | PHP `RecursiveDirectoryIterator` scan, then unlink |

The plugin functions are not re-typed: `single_purge.php` extracts them from the plugin sources at run time and runs
them against a real nginx and real cache files. Only WordPress itself is stubbed.

## Files

| File | Role |
|:---|:---|
| `bench_single.py` | orchestrator: starts nginx, fills the cache, runs the steps, verifies, writes `results/single.jsonl` |
| `single_purge.php` | worker: runs one layer once in a fresh PHP process, prints one JSON line |
| `report_single.py` | Markdown tables on stdout, optional self-contained HTML page with charts |
| `run-all.sh` | the full matrix + report |

It reuses `../benchmark/bench.py` (nginx start/stop, cache population, `get`, `tree_stats`) and `../build-nginx.sh`.

## Requirements

Root (php-fpm as root, `/proc/sys/vm/drop_caches`), the nginx + ngx_cache_purge v4 build from
`../build-nginx.sh releases/stable-4.0 <prefix>/ng/nginx-run`, `php` with the curl extension, `php-fpm`, `rg`, Python 3.
The harness `rm -rf`s its cache directory: it refuses to run unless the path contains `bench` or `npp-`
(or `NPP_BENCH_FORCE_WIPE=1`).

## Quick start

Run from inside `http-purge-test/benchmark-single/`:

```
python3 bench_single.py --smoke --methods http,rg,scan     # ~1 minute, validates the setup
./run-all.sh                                               # full matrix, then results/REPORT.md + results/report.html
python3 report_single.py results/single.jsonl              # re-render a report from existing data
```

Useful flags (all in `--help`): `--sizes wp:1000,wp:5000`, `--targets 1,5,20,60`, `--scenarios hit,miss,mixed`,
`--temps warm,cold`, `--rounds`, `--cold-rounds`, `--cell-budget-s`, `--stall-rounds`, `--stall-targets 1,60`,
`--taskset 0` (pin the PHP worker and rg to one CPU), `--index-entries N` (simulate the DB cost of the index write-back, rg/scan only).

## Scenarios

* `hit`: every target is cached.
* `miss`: no target is cached (the "page was never cached" case).
* `mixed`: a cached primary page plus related URLs shaped like what `includes/related.php` returns: `/`, `/feed/`,
  `/comments/feed/`, `/shop/`, date archives, author archives, `/category/x/` + `/category/x/feed/`, `/tag/x/` +
  `/tag/x/feed/`, and `/p/N/comment-page-K/`. Only `--mixed-hit-frac` (default 0.25) of the related targets are cached.
  The home page `/` is always a target, because `example.com/` is a prefix of every other cache key and is the riskiest
  shape for ripgrep's pattern. These 269 shape pages are cached next to the `/p/N` pages, so the cache holds N + 269 files
  when `mixed` runs.

`related.php` has no upper bound on the number of related URLs (one archive + one feed per term, comment pagination pages,
and a public filter), so the default targets are `1,5,20,60`: a plain page, a typical post, a post with many terms, a post
with many terms and comment pages.

## Method

* One nginx and one cache per size N, filled by real requests with log-normally distributed body sizes.
* Steps are **interleaved A/B**: in every round the same URL set is purged by each method in random order, so cache
  contents and drift (page-cache ageing, noisy neighbours) hit all methods equally. Ratios are paired per round.
* Every step runs the real layer in a **fresh PHP process**; the time is taken in-process around the layer call, so PHP
  start-up is excluded (a real purge runs inside an already-booted PHP-FPM worker). The ripgrep `exec()` is additionally
  timed on its own, by a source rewrite of the extracted function (asserted: exactly one `exec()` is rewritten).
* **cold** = `sync` + `drop_caches` before EVERY purge (the rg/php binaries are loaded again, untimed). **warm** = nothing dropped.
* After every purge the harness checks the outcome and then restores the cache to N entries (see below).
* The **stall pass** runs keep-alive probes against a cached URL of an UNRELATED cache zone while the purge runs, in a
  separate pass so the probes cannot disturb the latency numbers. The purge window is cut out with the worker's own
  monotonic timestamps. With several `--stall-targets` the purge is a mixed one.

## What is checked on every step

A step that fails one of these is flagged, excluded from the statistics and counted in the report:

* targets gone afterwards; for `miss`, still uncached;
* decoy pages untouched (a few other cached `/p/N` pages; in `mixed` also untargeted cached home / feeds / archives);
* the layer reported success, and for HTTP every answer was a real module answer (200 / 204 / 404 / 412);
* ripgrep matched exactly the cached targets (no more, no fewer);
* the next request for a purged page is a MISS, and the one after is a HIT (the page is re-cached, so the next step starts
  from the same N entries).

Recorded and printed in the report's integrity section, but not a pass/fail gate for a step:

* warn / error / crit / alert lines in the nginx error log during each step (the report lists any);
* the number of cache files at the end of each size against the expected N (+ the related-shape pages when `mixed` ran).

## Reading the report

* Latencies are medians, `p99` is the 99th percentile of the rounds; ratios are medians of per-round ratios with a
  bootstrap 95 % CI.
* **Break-even** fits HTTP cost as `fixed + per-target·T` (least squares over every measured T) and takes rg as flat in T.
  The `rg change` column reports the measured drift of rg between the smallest and the largest T, so that assumption is
  checked against the data. The last column is measured, the break-even column is an extrapolation.
* **Stall**: read `probe samples` before comparing; an HTTP purge window of a few milliseconds collects few samples.

## What it does not model

* Related-URL discovery (`url_to_postid()`, `get_the_terms()`, ...): the worker is handed a ready URL list. That cost is
  paid before any layer and is the same for all of them.
* `wp_remote_request()` overhead: the worker uses plain curl. Add roughly 1 ms per HTTP request for WordPress' own stack.
* Body sizes of the related shapes: `../benchmark/site/index.php` answers every path that is not `/p/N` with the size of page 0.
* The index layer (FP2), the multi-variant bypass to an index purge, and the related-URL preload after a purge.
* More than one CPU for ripgrep: rg is multi-threaded, and on a machine with more cores its scan is faster than on the
  1 vCPU VM used for the reference numbers. Use `--taskset` to compare like with like.
* Real WordPress page-size and file-count distributions: bodies are synthetic (log-normal, 24 KB median in profile `wp`).
