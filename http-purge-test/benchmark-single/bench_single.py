#!/usr/bin/env python3
"""
Purge SINGLE benchmark: HTTP purge (ngx_cache_purge, FP1) vs ripgrep purge (FP3) [vs PHP scan (FP4)].

Question: how long does ONE single-page purge take with each layer, how does that scale with cache size, with the
number of targets (primary + related pages), with a cold or warm kernel cache, and for a cached vs an uncached URL?
And does the ripgrep scan make the site stall while it runs?

Method (see README.md for the reasoning behind every choice)
  * ONE nginx + cache is populated per size N (real cache files, created by real requests, log-normal body sizes).
  * Steps are INTERLEAVED A/B: for every round the same URL set is purged by each method in random order, so both
    methods see the same cache contents and drift (page-cache ageing, noisy neighbours) hits them equally.
  * Each step runs the REAL plugin layer in a fresh PHP process (single_purge.php): nppp_purge_fp1_http() against the real
    nginx / nppp_purge_fp3_rg() against the real cache files. Time is taken in-process around the layer call.
  * cold = sync + drop_caches (+ pre-load of the rg/php binaries) before EVERY purge; warm = nothing is dropped.
  * After every purge the harness checks the outcome (target files gone, decoys intact, nothing else touched), then
    re-requests the purged pages: the first request must be a MISS (purge effective), and they are re-cached, so the
    cache holds exactly N entries again for the next step. Steps that fail a check are flagged and excluded from stats.
  * Site stall is measured in a separate pass (keep-alive probes against an unrelated cached URL) so the probes
    cannot perturb the latency pass.

Scenarios   hit   = every target is cached          miss = no target is cached (the common "page was never cached" case)
            mixed = what a real single purge looks like: the primary page is cached, the related URLs have the SHAPES that
                    nppp_get_related_urls_for_single() returns (/, /feed/, /comments/feed/, /category/x/, /category/x/feed/,
                    /tag/x/, /author/x/, /2026/11/05/, /p/N/comment-page-K/) and only --mixed-hit-frac of them are cached.
                    The cached shapes are created in the cache next to the /p/N pages (SHAPE_* below); they are also used as
                    decoys, so a purge of "/" that swallows neighbours is caught by the integrity checks.
Methods     http = FP1 (one HTTP request per target)   rg = FP3 (one rg pass for all targets)   scan = FP4 (PHP iterator)
"""
import argparse, hashlib, json, os, platform, random, shutil, statistics, subprocess, sys, threading, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "benchmark"))
import bench as B  # noqa: E402  (reuse: sh, populate, tree_stats, disk_bytes, get, start_nginx, stop_nginx, env defaults)

WORKER = os.path.join(HERE, "single_purge.php")
PHPBIN = os.environ.get("PHP", "php")
RG_BIN = shutil.which("rg") or "rg"
ERRLOG = f"{B.NG}/logs/pb-error.log"
WORKERS = int(os.environ.get("NGX_WORKERS", "2"))
HOST = "example.com"
SAFE_WIPE_HINTS = ("bench", "npp-")


# ----------------------------------------------------------------------------------------------- nginx
def write_conf(mode, median_kb):
    """Same server as benchmark/bench.py plus the Purge Single location the plugin's help page documents."""
    conf = f"""user root;
worker_processes {WORKERS};
thread_pool default threads=2;
error_log {B.NG}/logs/pb-error.log info;
pid {B.NG}/logs/pb-nginx.pid;
events {{ worker_connections 1024; }}
http {{
  access_log off;
  include mime.types;
  fastcgi_cache_path {B.CACHE} levels=1:2 keys_zone=NPP:256m max_size=200g inactive=30d;
  fastcgi_cache_path {B.OTHER} levels=1:2 keys_zone=OTHER:10m max_size=1g inactive=30d;
  cache_purge_response_type json;
  server {{
    listen 127.0.0.1:{B.PORT};
    server_name {HOST};
    location / {{
      fastcgi_pass unix:/tmp/pb-fpm.sock;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME {B.HERE}/site/index.php;
      fastcgi_param SIZE_MEDIAN_KB {median_kb};
      fastcgi_cache_key "$scheme$request_method$host$request_uri";
      fastcgi_cache NPP;
      fastcgi_cache_valid 200 30d;
      add_header X-Cache $upstream_cache_status;
    }}
    location = /probe {{
      fastcgi_pass unix:/tmp/pb-fpm.sock;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME {B.HERE}/site/index.php;
      fastcgi_param SIZE_MEDIAN_KB 8;
      fastcgi_cache_key "$scheme$request_method$host$request_uri";
      fastcgi_cache OTHER;
      fastcgi_cache_valid 200 30d;
      add_header X-Cache $upstream_cache_status;
    }}
    location ~ ^/npp_purge_single(/.*) {{
      allow 127.0.0.1; deny all;
      fastcgi_cache_purge NPP "$scheme$request_method$host$1$is_args$args";
    }}
  }}
}}
"""
    with open(f"{B.NG}/conf/pb-nginx.conf", "w") as f:
        f.write(conf)


B.write_conf = write_conf  # B.start_nginx() resolves write_conf from its own globals: use ours


# ----------------------------------------------------------------------------------------------- helpers
def cache_file(path):
    """nginx names a cache file md5(key) and, with levels=1:2, puts it in <last char>/<two chars before>/. Lets the harness
    check one entry in O(1) without walking (and thereby warming) the whole tree."""
    h = hashlib.md5(f"httpGET{HOST}{path}".encode()).hexdigest()
    return f"{B.CACHE}/{h[-1]}/{h[-3:-1]}/{h}"


def exists(path):
    return os.path.exists(cache_file(path))


def drop_caches():
    os.sync()
    with open("/proc/sys/vm/drop_caches", "w") as f:
        f.write("3\n")
    # the rg / php binaries are evicted too; in production they are hot (used all day), so load them again, untimed
    B.sh(f"{RG_BIN} --version >/dev/null; command -v rg >/dev/null; {PHPBIN} -r 'echo 1;' >/dev/null", check=False)
    time.sleep(0.2)


def can_drop_caches():
    try:
        with open("/proc/sys/vm/drop_caches", "w") as f:
            f.write("1\n")
        return True
    except Exception:
        return False


def pct(sorted_vals, q):
    if not sorted_vals:
        return None
    return sorted_vals[min(len(sorted_vals) - 1, int(q * len(sorted_vals)))]


def run_worker(method, paths, cpus, index_entries):
    urls = [f"http://{HOST}{p}" for p in paths]
    cmd = [PHPBIN, WORKER, method, B.CACHE] + urls
    if cpus:
        cmd = ["taskset", "-c", cpus] + cmd
    env = dict(os.environ, NPPP_PLUGIN=B.PLUGIN, NPPP_BENCH_PORT=str(B.PORT))
    if index_entries:
        env["NPPP_BENCH_INDEX"] = str(index_entries)
    t = time.perf_counter()
    p = subprocess.run(cmd, capture_output=True, text=True, env=env)
    wall = (time.perf_counter() - t) * 1000
    if p.returncode != 0:
        raise RuntimeError(f"worker failed ({p.returncode}): {p.stderr[:600]}")
    lines = [l for l in p.stdout.splitlines() if l.startswith("{")]
    if not lines:
        raise RuntimeError(f"worker produced no JSON: {p.stdout[:300]} {p.stderr[:300]}")
    return json.loads(lines[-1]), wall, p.stderr.strip()


def errlog_delta(offset):
    """Counts of nginx error-log lines written since $offset, by level (info is ignored)."""
    try:
        with open(ERRLOG, "rb") as f:
            f.seek(offset)
            data = f.read().decode("utf-8", "replace")
    except FileNotFoundError:
        return {}, []
    out, sample = {}, []
    for line in data.splitlines():
        for lvl in ("emerg", "alert", "crit", "error", "warn"):
            if f"[{lvl}]" in line:
                out[lvl] = out.get(lvl, 0) + 1
                if len(sample) < 2:
                    sample.append(line[:200])
                break
    return out, sample


def errlog_size():
    try:
        return os.path.getsize(ERRLOG)
    except FileNotFoundError:
        return 0


# ----------------------------------------------------------------------------------------------- one measured step
def run_step(ctx, method, paths, scenario, temp, was_cached=None):
    """One purge by one method + verification + restore. Returns the result record."""
    n = ctx["n"]
    decoys = []
    while len(decoys) < min(3, n - len(paths)):  # a few other cached pages that must survive the purge untouched
        p = f"/p/{ctx['rng'].randrange(n)}"
        if p not in paths and p not in decoys:
            decoys.append(p)
    if scenario == "mixed":  # neighbours of the shapes that must survive too: untargeted cached feeds / archives / home
        left = [s_ for s_ in ctx["shape_pool"] if s_ not in paths]
        decoys += ctx["rng"].sample(left, min(3, len(left)))
    was_cached = was_cached if was_cached is not None else [scenario == "hit"] * len(paths)
    pre_targets = [exists(p) for p in paths]
    pre_decoys = [exists(p) for p in decoys]
    log0 = errlog_size()

    if temp == "cold":
        drop_caches()
    else:
        os.sync()

    worker, wall_ms, stderr = run_worker(method, paths, ctx["cpus"], ctx["index_entries"])

    post_targets = [exists(p) for p in paths]
    post_decoys = [exists(p) for p in decoys]

    checks = {"decoys_intact": all(post_decoys) and all(pre_decoys)}
    if scenario == "miss":
        checks["targets_were_uncached"] = not any(pre_targets)
        checks["still_uncached"] = not any(post_targets)
    else:  # hit, mixed: every target (cached or not) is gone afterwards, the primary (always cached) was reported purged
        checks["targets_were_cached" if scenario == "hit" else "targets_as_intended"] = pre_targets == was_cached
        checks["targets_gone"] = not any(post_targets)
        checks["primary_purged"] = bool(worker.get("primary_purged"))
    want = True if method == "http" else "done"
    checks["layer_result"] = worker.get("result") == want
    if method == "rg" and worker.get("instrumented"):
        # ripgrep must have matched exactly the cached targets: no more (a pattern like "example.com/" swallowing neighbours), no fewer
        checks["rg_matched_exactly_the_cached"] = worker.get("rg_hits") == sum(was_cached)
    if method == "http":
        # every request must have come back as a genuine module answer (200 / 412 / 404), never a transport error
        checks["http_answered"] = all(r.get("status", 0) in (200, 204, 404, 412) for r in worker.get("http_detail", []))

    restore = None
    if any(was_cached):  # purge must be effective, then put the pages back so the cache holds N entries again
        hit_paths = [p for p, w in zip(paths, was_cached) if w]  # (never-cached targets stay uncached: not re-requested)
        first = [B.get(p)[2] for p in hit_paths]
        second = [B.get(p)[2] for p in hit_paths]
        restore = {"first": first, "second": second}
        checks["next_request_miss"] = all(x == "MISS" for x in first)
        checks["recached_hit"] = all(x == "HIT" for x in second)
    os.sync()

    levels, sample = errlog_delta(log0)
    ok = all(checks.values())
    rec = {
        "type": "step", "n": n, "temp": temp, "scenario": scenario, "targets": len(paths), "method": method,
        "ok": ok, "layer_ms": worker["layer_ms"], "wall_ms": round(wall_ms, 2), "boot_ms": worker.get("boot_ms"),
        "checks": checks, "nginx_log": levels,
    }
    rec["target_hits"] = sum(was_cached)  # how many of the targets were cached (hit: all, miss: 0, mixed: in between)
    for k in ("http_requests", "http_ms", "non_http_ms", "rg_exec_ms", "non_rg_ms", "rg_exit", "rg_hits", "instrumented"):
        if k in worker:
            rec[k] = worker[k]
    if method == "http":
        rec["http_status"] = [r.get("status") for r in worker.get("http_detail", [])]
        rec["http_code"] = sorted({r.get("code", "") for r in worker.get("http_detail", [])})
    if not ok:
        rec["fail_detail"] = {"checks": {k: v for k, v in checks.items() if not v}, "result": worker.get("result"),
                              "notices": worker.get("notices"), "restore": restore, "log_sample": sample}
    if stderr:
        rec["worker_stderr"] = stderr[:300]
    return rec


SHAPE_CATS, SHAPE_TAGS = 30, 100


def shape_pool():
    """Cached related-page shapes. Not /p/N: the URLs related.php adds around a post (home, feeds, one archive + one feed
    per term, author, date archives). 9 + 2*30 + 2*100 = 269 URLs."""
    pool = ["/", "/feed/", "/comments/feed/", "/shop/", "/2026/", "/2026/11/", "/2026/11/05/", "/author/admin/", "/author/editor/"]
    for i in range(SHAPE_CATS):
        pool += [f"/category/c{i}/", f"/category/c{i}/feed/"]
    for i in range(SHAPE_TAGS):
        pool += [f"/tag/t{i}/", f"/tag/t{i}/feed/"]
    return pool


def populate_shapes():
    pool = shape_pool()
    for p in pool:
        B.get(p)
    return pool


def uncached_shape(rng, n):
    """A related URL that was never requested: unknown term archive / feed, or a comment page of some post."""
    k = rng.randrange(10 ** 9)
    return rng.choice([f"/category/u{k}/", f"/category/u{k}/feed/", f"/tag/u{k}/", f"/tag/u{k}/feed/",
                       f"/p/{rng.randrange(n)}/comment-page-{rng.randrange(2, 60)}/"])


def pick_paths(ctx, scenario, t):
    """-> (paths, want): want[i] says whether paths[i] is cached before the purge. The primary is always paths[0]."""
    n, rng = ctx["n"], ctx["rng"]
    if scenario == "hit":
        return [f"/p/{i}" for i in rng.sample(range(n), t)], [True] * t
    if scenario == "miss":
        return [f"/miss/{rng.randrange(10**9)}" for _ in range(t)], [False] * t
    primary = f"/p/{rng.randrange(n)}"
    # the home page is in nearly every real related set (Home switch) and is the riskiest shape for ripgrep's pattern
    # ("example.com/" is a prefix of every other key), so it is always a target of a multi-target mixed purge
    others = [x for x in ctx["shape_pool"] if x != "/"]
    n_hit = min(max(round((t - 1) * ctx["hit_frac"]), 1 if t >= 2 else 0), len(ctx["shape_pool"]), t - 1)
    rel_hits = (["/"] if t >= 2 else []) + rng.sample(others, max(0, n_hit - 1))
    rel = [(p, True) for p in rel_hits]
    seen = {primary, *rel_hits}
    while len(rel) < t - 1:
        u = uncached_shape(rng, n)
        if u not in seen and not exists(u):
            seen.add(u)
            rel.append((u, False))
    rng.shuffle(rel)
    return [primary] + [p for p, _ in rel], [True] + [w for _, w in rel]


# ----------------------------------------------------------------------------------------------- stall pass
class TProbe(threading.Thread):
    """Keep-alive GET of a cached URL in an UNRELATED zone; records (start, latency_ms) so a purge window can be cut out."""

    def __init__(self, interval_s):
        super().__init__(daemon=True)
        self.stop, self.samples, self.interval = False, [], interval_s

    def run(self):
        import http.client
        c = None
        while not self.stop:
            t = time.perf_counter()
            try:
                if c is None:
                    c = http.client.HTTPConnection("127.0.0.1", B.PORT, timeout=300)
                c.request("GET", "/probe", headers={"Host": HOST})
                c.getresponse().read()
                self.samples.append((t, (time.perf_counter() - t) * 1000))
            except Exception:
                self.samples.append((t, -1.0))
                c = None
            time.sleep(self.interval)


def summarize(vals):
    ok = sorted(v for v in vals if v >= 0)
    if not ok:
        return {"samples": 0}
    return {"samples": len(ok), "p50": round(pct(ok, 0.5), 2), "p99": round(pct(ok, 0.99), 2), "max": round(ok[-1], 1),
            "over_50ms": sum(1 for v in ok if v > 50), "errors": len(vals) - len(ok)}


def stall_pass(ctx, temp, methods, rounds, probes_n, interval_ms, emit, targets=(1,)):
    probes = [TProbe(interval_ms / 1000) for _ in range(probes_n)]
    B.get("/probe")
    [p.start() for p in probes]
    time.sleep(3.0)
    base_end = time.perf_counter()
    base = summarize([l for p in probes for (t, l) in p.samples if t < base_end])
    emit({"type": "stall_baseline", "n": ctx["n"], "temp": temp, "probes": probes_n, "interval_ms": interval_ms, **base})
    scenario = "mixed" if ctx.get("shape_pool") else "hit"  # a multi-target purge is a realistic one when the shapes exist
    for tg in targets:
        for r in range(rounds):
            paths, want = pick_paths(ctx, scenario, tg)
            order = list(methods)
            ctx["rng"].shuffle(order)
            for m in order:
                if temp == "cold":
                    drop_caches()
                py0 = time.perf_counter()
                worker, wall_ms, _ = run_worker(m, paths, ctx["cpus"], ctx["index_entries"])
                py1 = time.perf_counter()
                # purge window = the layer call only (PHP start-up excluded); both clocks are CLOCK_MONOTONIC
                t0, t1 = worker["t0_ns"] / 1e9, worker["t1_ns"] / 1e9
                assert py0 - 0.05 <= t0 <= t1 <= py1 + 0.05, f"worker and harness clocks disagree: {py0} {t0} {t1} {py1}"
                # samples that overlap the purge window [t0, t1]
                win = [l for p in probes for (t, l) in p.samples if t <= t1 and (t + max(l, 0) / 1000) >= t0]
                for p, w in zip(paths, want):  # restore what was cached (untimed, probes keep running but the window is closed)
                    if w:
                        B.get(p); B.get(p)
                os.sync()
                emit({"type": "stall", "n": ctx["n"], "temp": temp, "method": m, "round": r, "targets": tg, "scenario": scenario,
                      "layer_ms": worker["layer_ms"], "wall_ms": round(wall_ms, 2), "window_ms": round((t1 - t0) * 1000, 2), **summarize(win)})
    for p in probes:
        p.stop = True
    [p.join() for p in probes]


# ----------------------------------------------------------------------------------------------- environment
def env_info(args, cold_ok):
    sh = lambda c: B.sh(c, check=False).stdout.strip()
    dev = sh(f"df --output=source {B.CACHE.rsplit('/', 1)[0] if os.path.isdir(B.CACHE.rsplit('/', 1)[0]) else '/tmp'} | tail -1")
    base = os.path.basename(dev.rstrip("0123456789p")) if dev.startswith("/dev/") else ""
    rota = sh(f"cat /sys/block/{base}/queue/rotational 2>/dev/null") if base else ""
    return {
        "kernel": platform.release(), "cpu": sh("lscpu | grep 'Model name' | sed 's/.*: *//'"), "cpus": os.cpu_count(),
        "mem_mb": int(sh("free -m | awk '/Mem:/{print $2}'") or 0), "fs": sh("df -T " + B.CACHE.rsplit("/", 1)[0] + " | tail -1 | awk '{print $2, $1}'"),
        "rotational": rota, "cold_method": "drop_caches" if cold_ok else "unavailable",
        "nginx": sh(f"{B.NG}/sbin/nginx -V 2>&1 | head -1"), "workers": WORKERS,
        "module": sh(f"git -C {B.MODULE} describe --tags --always 2>/dev/null; git -C {B.MODULE} log --oneline -1"),
        "php": sh(f"{PHPBIN} -v | head -1"), "rg": sh(f"{RG_BIN} --version | head -1"),
        "plugin": sh(f"git -C {B.PLUGIN} log --oneline -1; git -C {B.PLUGIN} branch --show-current"),
        "worker_cpus": args.taskset or "all", "index_entries": args.index_entries, "seed": args.seed,
        "args": vars(args),
    }


def preflight():
    missing = []
    if not os.path.exists(f"{B.NG}/sbin/nginx"):
        missing.append(f"nginx build not found at {B.NG}  ->  http-purge-test/build-nginx.sh releases/stable-4.0 http-purge-test/ng/nginx-run")
    for tool in (RG_BIN, PHPBIN):
        if not shutil.which(tool):
            missing.append(f"{tool} not on PATH")
    if not B.PHP_FPM:
        missing.append("php-fpm not found (apt-get install php-fpm)")
    if missing:
        sys.exit("preflight failed:\n  " + "\n  ".join(missing))
    if not any(h in B.CACHE for h in SAFE_WIPE_HINTS) and not os.environ.get("NPP_BENCH_FORCE_WIPE"):
        sys.exit(f"refusing to wipe CACHE={B.CACHE}: the harness rm -rf's it before every run. Use a dedicated directory "
                 f"(its path should contain 'bench' or 'npp-'), or set NPP_BENCH_FORCE_WIPE=1.")


# ----------------------------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--sizes", default="wp:1000,wp:5000,wp:20000", help="profile:N list. profile wp = 24 KB median page, heavy = 48 KB")
    ap.add_argument("--temps", default="warm,cold")
    ap.add_argument("--scenarios", default="hit,miss", help="hit, miss, mixed (comma list; mixed = cached primary + related-shaped URLs, see docstring)")
    ap.add_argument("--targets", default="1,5,20,60", help="targets per purge: 1 = the page, 5 = page + 4 related pages, 60 = a post with many terms / comment pages")
    ap.add_argument("--mixed-hit-frac", type=float, default=0.25, help="mixed scenario: share of the RELATED targets that are cached (the primary always is)")
    ap.add_argument("--methods", default="http,rg", help="http, rg, scan (comma list)")
    ap.add_argument("--rounds", type=int, default=20, help="measured rounds per cell, warm")
    ap.add_argument("--cold-rounds", type=int, default=8, help="measured rounds per cell, cold (each one drops the kernel caches)")
    ap.add_argument("--cell-budget-s", type=float, default=0, help="stop adding rounds to a cell once it has run this long (after --min-rounds); 0 = off. "
                    "Keeps big cold cells bounded. Rounds stay interleaved: the cut happens between rounds")
    ap.add_argument("--min-rounds", type=int, default=3)
    ap.add_argument("--warmup", type=int, default=2, help="untimed warm-up rounds at the start of every warm cell series")
    ap.add_argument("--stall-rounds", type=int, default=4, help="rounds of the site-stall pass (0 = skip)")
    ap.add_argument("--stall-temps", default="warm,cold")
    ap.add_argument("--stall-targets", default="1", help="targets per purge during the stall pass (comma list, e.g. 1,60); with the mixed shapes present they are mixed purges")
    ap.add_argument("--stall-sizes", default="largest", help="'largest', 'all', or an N")
    ap.add_argument("--probes", type=int, default=6)
    ap.add_argument("--probe-interval-ms", type=float, default=3.0)
    ap.add_argument("--taskset", default="", help="CPU list for the PHP worker + rg (e.g. 0 or 0-1) to emulate a smaller server")
    ap.add_argument("--index-entries", type=int, default=0, help="entries in the plugin's URL->path index (DB round-trip cost of the rg write-back)")
    ap.add_argument("--seed", type=int, default=20261105)
    ap.add_argument("--smoke", action="store_true", help="tiny end-to-end run (~1 min) to validate the setup")
    ap.add_argument("--out", default=os.path.join(HERE, "results", "single.jsonl"))
    a = ap.parse_args()

    if a.smoke:
        a.sizes, a.rounds, a.cold_rounds, a.warmup, a.targets = "wp:400", 3, 2, 1, "1,3,20"
        a.scenarios, a.stall_targets = "hit,miss,mixed", "1,20"
        a.stall_rounds, a.stall_sizes = 2, "all"
        if a.out.endswith("single.jsonl"):
            a.out = "/tmp/single-smoke.jsonl"

    preflight()
    cold_ok = can_drop_caches()
    temps = [t for t in a.temps.split(",") if t]
    if "cold" in temps and not cold_ok:
        print("WARNING: /proc/sys/vm/drop_caches is not writable (container / not root): cold cells are skipped", file=sys.stderr)
        temps = [t for t in temps if t != "cold"]
    methods = a.methods.split(",")
    scenarios = a.scenarios.split(",")
    targets = [int(x) for x in a.targets.split(",")]
    sizes = [(p, int(n)) for p, n in (x.split(":") for x in a.sizes.split(","))]

    os.makedirs(os.path.dirname(os.path.abspath(a.out)), exist_ok=True)
    os.makedirs(os.path.dirname(B.CACHE), exist_ok=True)
    with open(a.out.replace(".jsonl", "-env.json"), "w") as f:
        json.dump(env_info(a, cold_ok), f, indent=2)
    if a.smoke and os.path.exists(a.out):
        os.remove(a.out)
    out = open(a.out, "a", buffering=1)
    run_id = time.strftime("%Y%m%dT%H%M%S")  # appended runs stay distinguishable; report_single.py uses the latest by default

    def emit(rec):
        rec["run"] = run_id
        out.write(json.dumps(rec) + "\n")

    failures = 0
    for pi, (profile, n) in enumerate(sizes):
        rng = random.Random(a.seed + n)
        B.start_nginx("single", B.PROFILES[profile])
        B.get("/probe")
        pop_s, pop_err = B.populate(n)
        shapes = populate_shapes() if "mixed" in scenarios else []  # related-page shapes, cached next to the /p/N pages
        extra = len(shapes)
        time.sleep(1.0)
        files, apparent, dirs = B.tree_stats(B.CACHE)
        mem_avail = int(B.sh("awk '/MemAvailable/{print $2}' /proc/meminfo").stdout) * 1024
        meta = {"type": "cell_meta", "profile": profile, "n": n, "files": files, "dirs": dirs, "apparent_mb": round(apparent / 1e6, 1),
                "disk_mb": round(B.disk_bytes(B.CACHE) / 1e6, 1), "avg_file_kb": round(apparent / max(1, files) / 1024, 1),
                "populate_s": round(pop_s, 2), "populate_errors": pop_err, "fits_in_ram": apparent < 0.8 * mem_avail}
        meta["extra_files"] = extra
        emit(meta)
        print(f"\n=== {profile} N={n}: {files} files, {meta['apparent_mb']} MB, populated in {pop_s:.1f}s"
              f"{'' if meta['fits_in_ram'] else '  (does NOT fit in RAM: the warm cells are partly cold)'}", flush=True)
        if files != n + extra:
            sys.exit(f"populate produced {files} files, wanted {n + extra} (errors: {pop_err})")
        ctx = {"n": n, "rng": rng, "cpus": a.taskset, "index_entries": a.index_entries, "shape_pool": shapes, "hit_frac": a.mixed_hit_frac}
        B.sh("sync")

        for temp in temps:
            nrounds = a.rounds if temp == "warm" else a.cold_rounds
            for scenario in scenarios:
                for t in targets:
                    if scenario in ("hit", "mixed") and t > n:
                        continue
                    series = {m: [] for m in methods}
                    warm_extra = a.warmup if temp == "warm" else 0
                    cell_t0 = time.perf_counter()
                    for r in range(-warm_extra, nrounds):
                        if a.cell_budget_s and r >= max(a.min_rounds, 1) and time.perf_counter() - cell_t0 > a.cell_budget_s:
                            print(f"    (cell budget {a.cell_budget_s:.0f}s reached after {r} rounds)", flush=True)
                            break
                        paths, want = pick_paths(ctx, scenario, t)
                        order = list(methods)
                        rng.shuffle(order)
                        for m in order:
                            rec = run_step(ctx, m, paths, scenario, temp, want)
                            rec["round"], rec["warmup"], rec["order"] = r, r < 0, order.index(m)
                            emit(rec)
                            if r >= 0:
                                if rec["ok"]:
                                    series[m].append(rec["layer_ms"])
                                else:
                                    failures += 1
                                    print(f"  !! FAILED CHECK {m} {temp}/{scenario}/T{t} round {r}: {rec['fail_detail']}", flush=True)
                    med = {m: statistics.median(v) for m, v in series.items() if v}
                    cells = "  ".join(f"{m}={med[m]:9.2f} ms (n={len(series[m])})" for m in methods if m in med)
                    ratio = f"  rg/http={med['rg'] / med['http']:.1f}x" if "rg" in med and "http" in med and med["http"] else ""
                    print(f"  {temp:4s} {scenario:4s} T={t}: {cells}{ratio}", flush=True)

        if a.stall_rounds > 0:
            do_stall = a.stall_sizes == "all" or (a.stall_sizes == "largest" and pi == len(sizes) - 1) or a.stall_sizes == str(n)
            if do_stall:
                for temp in [t for t in a.stall_temps.split(",") if t and (t != "cold" or cold_ok)]:
                    stall_pass(ctx, temp, methods, a.stall_rounds, a.probes, a.probe_interval_ms, emit,
                               [int(x) for x in a.stall_targets.split(",") if x])
                    print(f"  stall pass done: {temp}", flush=True)

        # end-of-size integrity: the cache must hold exactly N entries again (every hit step restores what it purged)
        files_end, _, _ = B.tree_stats(B.CACHE)
        emit({"type": "cell_end", "n": n, "files_end": files_end, "files_expected": n + extra})
        print(f"  integrity: {files_end} files at the end (expected {n + extra}){'' if files_end == n + extra else '   <-- MISMATCH'}", flush=True)

    B.stop_nginx()
    out.close()
    print(f"\nDONE -> {a.out}   failed-check steps: {failures}")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
