#!/usr/bin/env python3
"""
Sync Purge All benchmark: ngx_cache_purge (module) vs NPPP's PHP recursive deleter.

For every (profile, N, temperature, method, repetition) it:
  1. restarts nginx on an EMPTY cache dir,
  2. populates N real cache files through nginx (log-normal body sizes),
  3. flushes (warm) or drops (cold) the kernel dentry/inode/page caches,
  4. purges with ONE method while a probe hammers an unrelated cached URL,
  5. verifies the cache dir is empty and nginx still serves/re-caches,
  6. appends one JSON line to results/results.jsonl.

Methods
  module-sync  GET /npp_purge_all, background queue OFF  (request worker walks synchronously)
  php          plugin's real nppp_delete_tree() over the cache dir (what nppp_wp_purge() does)
  module-bq    GET /npp_purge_all, background queue ON, module defaults (202 + threaded walk)
"""
import argparse, http.client, json, os, platform, shutil, statistics, subprocess, sys, threading, time

HERE = os.path.dirname(os.path.abspath(__file__))
# Defaults follow the repo layout (http-purge-test/benchmark/): the plugin is two levels up, nginx and the
# module checkout come from ../build-nginx.sh (NGROOT defaults to http-purge-test/ng). Override via env.
PLUGIN = os.environ.get("PLUGIN", os.path.abspath(os.path.join(HERE, "..", "..")))
NGROOT = os.environ.get("NGROOT", os.path.abspath(os.path.join(HERE, "..", "ng")))
NG = os.environ.get("NG", os.path.join(NGROOT, "nginx-run"))
MODULE = os.environ.get("MODULE", os.path.join(NGROOT, "build", "mod-releases_stable-4.0"))
PHP_FPM = os.environ.get("PHP_FPM", shutil.which("php-fpm8.3") or shutil.which("php-fpm"))
CACHE = os.environ.get("CACHE", "/var/cache/npp-bench/cache")
OTHER = os.environ.get("OTHER", "/var/cache/npp-bench/other")
PORT = 8081
PROFILES = {"wp": 24, "heavy": 48}  # median body size in KB (log-normal, sigma 0.9)


def sh(cmd, check=True, **kw):
    return subprocess.run(cmd, shell=True, check=check, capture_output=True, text=True, **kw)


def write_conf(mode, median_kb):
    bg = "cache_purge_background_queue on;" if mode == "module-bq" else ""
    conf = f"""user root;
worker_processes 2;
thread_pool default threads=2;
error_log {NG}/logs/pb-error.log info;
pid {NG}/logs/pb-nginx.pid;
events {{ worker_connections 1024; }}
http {{
  access_log off;
  include mime.types;
  fastcgi_cache_path {CACHE} levels=1:2 keys_zone=NPP:256m max_size=200g inactive=30d;
  fastcgi_cache_path {OTHER} levels=1:2 keys_zone=OTHER:10m max_size=1g inactive=30d;
  {bg}
  server {{
    listen 127.0.0.1:{PORT};
    server_name example.com;
    location / {{
      fastcgi_pass unix:/tmp/pb-fpm.sock;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME {HERE}/site/index.php;
      fastcgi_param SIZE_MEDIAN_KB {median_kb};
      fastcgi_cache_key "$scheme$request_method$host$request_uri";
      fastcgi_cache NPP;
      fastcgi_cache_valid 200 30d;
      add_header X-Cache $upstream_cache_status;
    }}
    location = /probe {{
      fastcgi_pass unix:/tmp/pb-fpm.sock;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME {HERE}/site/index.php;
      fastcgi_param SIZE_MEDIAN_KB 8;
      fastcgi_cache_key "$scheme$request_method$host$request_uri";
      fastcgi_cache OTHER;
      fastcgi_cache_valid 200 30d;
      add_header X-Cache $upstream_cache_status;
    }}
    location = /npp_purge_all {{
      allow 127.0.0.1; deny all;
      fastcgi_cache NPP;
      fastcgi_cache_key "$scheme$request_method$host$request_uri";
      fastcgi_cache_purge GET purge_all from all;
    }}
    location = /purge_status {{
      allow 127.0.0.1; deny all;
      cache_purge_queue_status {CACHE};
    }}
  }}
}}
"""
    open(f"{NG}/conf/pb-nginx.conf", "w").write(conf)


def stop_nginx():
    pf = f"{NG}/logs/pb-nginx.pid"
    if os.path.exists(pf):
        try:
            pid = int(open(pf).read())
            os.kill(pid, 15)
            for _ in range(100):
                try:
                    os.kill(pid, 0)
                    time.sleep(0.1)
                except ProcessLookupError:
                    break
        except Exception:
            pass


def start_nginx(mode, median_kb):
    stop_nginx()
    if not (os.path.exists("/tmp/pb-fpm.pid") and sh("kill -0 $(cat /tmp/pb-fpm.pid)", check=False).returncode == 0):
        sh(f"{PHP_FPM} -y {HERE}/fpm.conf --allow-to-run-as-root")
        time.sleep(1)
    sh(f"rm -rf {CACHE} {OTHER}; mkdir -p {CACHE} {OTHER}")
    open(f"{NG}/logs/pb-error.log", "w").close()
    write_conf(mode, median_kb)
    r = sh(f"{NG}/sbin/nginx -t -c {NG}/conf/pb-nginx.conf", check=False)
    if r.returncode:
        sys.exit("nginx -t failed: " + r.stderr)
    sh(f"{NG}/sbin/nginx -c {NG}/conf/pb-nginx.conf")
    time.sleep(0.5)


def get(path, timeout=600):
    c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=timeout)
    c.request("GET", path, headers={"Host": "example.com"})
    r = c.getresponse()
    body = r.read()
    xc = r.getheader("X-Cache")
    c.close()
    return r.status, body, xc


def populate(n, threads=24):
    errs = [0]

    def w(k):
        c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=60)
        for i in range(k, n, threads):
            try:
                c.request("GET", f"/p/{i}", headers={"Host": "example.com"})
                r = c.getresponse()
                r.read()
                if r.status != 200:
                    errs[0] += 1
            except Exception:
                errs[0] += 1
                c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=60)

    t0 = time.time()
    th = [threading.Thread(target=w, args=(k,)) for k in range(threads)]
    [t.start() for t in th]
    [t.join() for t in th]
    return time.time() - t0, errs[0]


def tree_stats(path):
    """(files, apparent_bytes, dirs) without following symlinks."""
    files = size = dirs = 0
    stack = [path]
    while stack:
        d = stack.pop()
        with os.scandir(d) as it:
            for e in it:
                if e.is_dir(follow_symlinks=False):
                    dirs += 1
                    stack.append(e.path)
                else:
                    files += 1
                    size += e.stat(follow_symlinks=False).st_size
    return files, size, dirs


def disk_bytes(path):
    return int(sh(f"du -sB1 {path} | cut -f1").stdout.strip() or 0)


def settle(temp):
    sh("sync")
    if temp == "cold":
        open("/proc/sys/vm/drop_caches", "w").write("3\n")
    time.sleep(1.0)


class Probe(threading.Thread):
    """GET of a cached URL in an UNRELATED zone every 5 ms. A worker blocked inside a synchronous walk cannot answer,
    so connections it owns stall. kind='keepalive': one persistent connection (pinned to one worker, like real browsers);
    kind='fresh': new connection per request (the idle worker usually wins accept(), so this under-reports blocking)."""

    def __init__(self, kind):
        super().__init__(daemon=True)
        self.kind, self.stop, self.lat = kind, False, []

    def run(self):
        c = None
        while not self.stop:
            t = time.perf_counter()
            try:
                if c is None:
                    c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=300)
                c.request("GET", "/probe", headers={"Host": "example.com"})
                c.getresponse().read()
                if self.kind == "fresh":
                    c.close(); c = None
                self.lat.append((time.perf_counter() - t) * 1000)
            except Exception:
                self.lat.append(-1.0); c = None
            time.sleep(0.005)


def probe_summary(probes):
    lat = [x for p in probes for x in p.lat]
    ok = sorted(x for x in lat if x >= 0)
    if not ok:
        return {}
    q = lambda f: ok[min(len(ok) - 1, int(f * len(ok)))]
    return {"probes": len(lat), "probe_errors": lat.count(-1.0), "probe_p50_ms": round(q(0.5), 2),
            "probe_p99_ms": round(q(0.99), 2), "probe_max_ms": round(max(ok), 1),
            "probes_over_100ms": sum(1 for x in ok if x > 100),
            "probes_over_1s": sum(1 for x in ok if x > 1000)}


def run_one(profile, n, temp, method, rep):
    median = PROFILES[profile]
    start_nginx("module-bq" if method == "module-bq" else "module-sync", median)
    get("/probe")  # warm the unrelated zone
    pop_s, pop_err = populate(n)
    time.sleep(1.0)
    files0, apparent0, dirs0 = tree_stats(CACHE)
    disk0 = disk_bytes(CACHE)
    settle(temp)

    probes = [Probe("keepalive") for _ in range(8)] + [Probe("fresh")]
    [p.start() for p in probes]
    time.sleep(0.5)
    res = {"profile": profile, "n": n, "temp": temp, "method": method, "rep": rep,
           "populate_s": round(pop_s, 2), "populate_errors": pop_err,
           "files_before": files0, "dirs_before": dirs0, "apparent_mb": round(apparent0 / 1e6, 1),
           "disk_mb": round(disk0 / 1e6, 1), "avg_file_kb": round(apparent0 / max(1, files0) / 1024, 1)}
    t0 = time.perf_counter()
    if method == "php":
        r = sh(f"php {HERE}/php_delete.php {PLUGIN}/includes/wp-filesystem.php {CACHE}")
        res["total_s"] = round(time.perf_counter() - t0, 3)
        res["internal"] = json.loads(r.stdout)
    elif method == "module-sync":
        st, body, _ = get("/npp_purge_all")
        res["total_s"] = round(time.perf_counter() - t0, 3)
        res["http_status"], res["body"] = st, body.decode()[:300]
    elif method == "module-bq":
        st, body, _ = get("/npp_purge_all")
        res["http_s"] = round(time.perf_counter() - t0, 3)
        res["http_status"], res["body"] = st, body.decode()[:200]
        while True:  # drain: status idle AND tree empty
            s = json.loads(get("/purge_status")[1])["cache"]
            if s["pending"]["total"] == 0 and s["active"] == 0 and tree_stats(CACHE)[0] == 0:
                break
            time.sleep(0.05)
            if time.perf_counter() - t0 > 900:
                res["timeout"] = True
                break
        res["total_s"] = round(time.perf_counter() - t0, 3)
    time.sleep(0.5)
    for p in probes:
        p.stop = True
    [p.join() for p in probes]
    res.update(probe_summary(probes[:8]))  # keep-alive probes (8 persistent connections)
    fresh = probe_summary(probes[8:])
    res["fresh_probe_max_ms"] = fresh.get("probe_max_ms")

    files1, _, dirs1 = tree_stats(CACHE)
    res["files_after"], res["dirs_after"] = files1, dirs1
    st, _, xc = get("/p/1")  # nginx must still serve and re-cache after the purge
    st2, _, xc2 = get("/p/1")
    res["post_purge_requests"] = [xc, xc2]
    res["files_per_s"] = round(files0 / res["total_s"]) if res["total_s"] else None
    log = open(f"{NG}/logs/pb-error.log").read()
    res["nginx_alert_lines"] = sum(1 for l in log.splitlines() if "[alert]" in l or "[crit]" in l or "[emerg]" in l)
    return res


def env_info():
    nginx_v = sh(f"{NG}/sbin/nginx -V 2>&1 | head -1").stdout.strip()
    return {"kernel": platform.release(), "cpu": sh("lscpu | grep 'Model name' | sed 's/.*: *//'").stdout.strip(),
            "cpus": os.cpu_count(), "mem_mb": int(sh("free -m | awk '/Mem:/{print $2}'").stdout),
            "fs": sh(f"df -T {CACHE.rsplit('/',1)[0] if os.path.isdir(CACHE.rsplit('/',1)[0]) else '/tmp'} | tail -1 | awk '{{print $2, $1}}'").stdout.strip(),
            "nginx": nginx_v, "php": sh("php -v | head -1").stdout.strip(),
            "module": sh(f"git -C {MODULE} describe --tags --always 2>/dev/null; git -C {MODULE} log --oneline -1").stdout.strip(),
            "plugin": sh(f"git -C {PLUGIN} log --oneline -1; git -C {PLUGIN} branch --show-current").stdout.strip()}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--profiles", default="wp:5000,wp:10000,wp:20000,heavy:20000")
    ap.add_argument("--temps", default="warm,cold")
    ap.add_argument("--methods", default="module-sync,php")
    ap.add_argument("--reps", type=int, default=3)
    ap.add_argument("--out", default=os.path.join(HERE, "results", "results.jsonl"))
    a = ap.parse_args()
    os.makedirs(os.path.dirname(CACHE), exist_ok=True)
    os.makedirs(os.path.dirname(os.path.abspath(a.out)), exist_ok=True)
    with open(a.out.replace(".jsonl", "-env.json"), "w") as f:
        json.dump(env_info(), f, indent=2)
    combos = [tuple(x.split(":")) for x in a.profiles.split(",")]
    for rep in range(1, a.reps + 1):
        for prof, n in combos:
            for temp in a.temps.split(","):
                for method in a.methods.split(","):
                    r = run_one(prof, int(n), temp, method, rep)
                    with open(a.out, "a") as f:
                        f.write(json.dumps(r) + "\n")
                    print(f"[rep {rep}] {prof} N={n} {temp:4s} {method:11s} total={r['total_s']:>8}s files_after={r['files_after']} "
                          f"probe_max={r.get('probe_max_ms')}ms", flush=True)
    stop_nginx()


if __name__ == "__main__":
    main()
