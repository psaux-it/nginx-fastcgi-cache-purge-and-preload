#!/usr/bin/env python3
"""
e2e.py - end-to-end test driver for the NPP Fail2Ban webhook -> RIPEstat worker chain.

    fake_f2b_producer.py  --HTTP-->  nginx/php-fpm webhook  -->  events table
                                          |  spawns
                                          v
                              php CLI worker (nppp_f2b_worker_run)
                                          |  WpOrg\\Requests parallel HTTPS
                                          v
                               fake_ripestat.py (stat.ripe.net -> 127.0.0.2)

Run it through run-e2e.sh (which starts the fake server), or directly:

    python3 e2e.py                      # all phases except ratelimit
    python3 e2e.py --only happy,faults
    python3 e2e.py --with-ratelimit

Phases: happy, edge, lifecycle, faults, ratelimit.
Every phase starts from a clean slate (events table, RDAP transients, rate
counter, worker state, plugin log, fake-server stats). Standard library only.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from fake_f2b_producer import gen_ips            # noqa: E402  (same IP generator)

WP_PATH = os.environ.get("WP_PATH", "/var/www/html")
WP_USER = os.environ.get("WP_USER", "www-data")
SITE = os.environ.get("SITE_URL", "http://127.0.0.1:8080")
FAKE = os.environ.get("FAKE_RIPESTAT_CTL", "https://127.0.0.2")
CA = os.path.join(HERE, "pki", "ca.crt")
ENDPOINT = SITE.rstrip("/") + "/wp-json/nppp_f2b/v1/event"
SOURCEAPP = "npp-wp-plugin-fail2ban-monitor"

RESULTS = []          # (phase, name, ok, detail)
PHASE = ["-"]


# --------------------------------------------------------------------------
# plumbing
# --------------------------------------------------------------------------
def wp(*args, php=None):
    """Run wp-cli as the web user so files the plugin creates stay writable."""
    base = ["wp", "--path=" + WP_PATH]
    if os.geteuid() == 0:
        os.makedirs("/tmp/wp-home", exist_ok=True)
        subprocess.run(["chown", WP_USER, "/tmp/wp-home"], check=False)
        base = ["runuser", "-u", WP_USER, "--", "env", "HOME=/tmp/wp-home"] + base
    cmd = base + list(args)
    if php is not None:
        cmd = base + ["eval", "nppp_load_bootstrap();" + php]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    if p.returncode != 0:
        raise RuntimeError("wp-cli failed: %s\n%s" % (" ".join(cmd[-3:]), p.stderr[-600:]))
    return p.stdout.strip()


def ctl(path, method="GET", data=None):
    import ssl
    ctx = ssl.create_default_context(cafile=CA)
    # connect to the loopback alias; the cert is for stat.ripe.net, so go via the name
    url = "https://stat.ripe.net" + path
    req = urllib.request.Request(url, method=method,
                                 data=json.dumps(data).encode() if data is not None else (b"" if method == "POST" else None))
    with urllib.request.urlopen(req, timeout=20, context=ctx) as r:
        return json.loads(r.read().decode())


def check(name, ok, detail=""):
    RESULTS.append((PHASE[0], name, bool(ok), detail))
    print("   [%s] %s%s" % ("PASS" if ok else "FAIL", name, ("  -> %s" % (detail,)) if (detail != "" and not ok) else ""))
    return ok


def info(msg):
    print("   " + msg)


def rows():
    out = wp(php=(
        'global $wpdb; $t = nppp_f2b_table_name();'
        'echo wp_json_encode( $wpdb->get_results("SELECT id, jail, ip, event_type, rdap_json IS NULL AS pending, rdap_json FROM $t ORDER BY id", ARRAY_A) );'
    ))
    data = json.loads(out or "[]")
    for r in data:
        r["pending"] = bool(int(r["pending"]))
        r["rdap"] = json.loads(r["rdap_json"]) if r["rdap_json"] else None
    return data


def pending():
    return int(wp(php=(
        'global $wpdb; $t = nppp_f2b_table_name();'
        'echo (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_type=\'ban\' AND rdap_json IS NULL");'
    )) or 0)


def log_path():
    return wp(php='echo nppp_get_runtime_file("fastcgi_ops.log");')


def log_text():
    try:
        with open(log_path(), errors="replace") as fh:
            return fh.read()
    except OSError:
        return ""


def reset_all():
    # stop a worker left over from the previous phase, then wipe state
    subprocess.run(["pkill", "-f", "nppp_f2b_worker_run"], check=False)
    time.sleep(0.4)
    wp(php=(
        'global $wpdb; $t = nppp_f2b_table_name(); $wpdb->query("TRUNCATE TABLE $t");'
        '$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%transient%nppp%\'");'
        'delete_option("nppp_f2b_rate_win"); delete_option(NPPP_F2B_SPAWN_TICK_KEY);'
        'delete_option(NPPP_F2B_LOG_GATE_OPTION);'
        'wp_cache_flush();'
        'foreach ( array( NPPP_F2B_WORKER_PID_FILE, NPPP_F2B_WORKER_HB_FILE, "f2b_worker.out", "fastcgi_ops.log" ) as $f ) { $p = nppp_get_runtime_file($f); if ( is_file($p) ) { unlink($p); } }'
    ))
    ctl("/__ctl/reset", "POST")


def producer(*args):
    cmd = [sys.executable, os.path.join(HERE, "fake_f2b_producer.py")] + list(args) + \
          ["--url", ENDPOINT, "--token", TOKEN] + \
          (["--insecure"] if ENDPOINT.startswith("https") else [])
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
    return p.returncode, p.stdout + p.stderr


def wait_drain(timeout=120, label="queue", nudge=True):
    """Wait until no ban row has rdap_json NULL. If a worker has exited while
    IPs are still cooling down, a webhook event for a fresh public IP respawns
    one (exactly what the next real ban would do)."""
    t0, last_nudge, nudges = time.time(), time.time(), []
    while time.time() - t0 < timeout:
        n = pending()
        if n == 0:
            info("%s drained in %.1fs" % (label, time.time() - t0))
            return True, nudges
        if nudge and time.time() - last_nudge > 20:
            ip = gen_ips(1, 9000 + len(nudges), 0)[0]
            producer("ban", "--count", "1", "--seed", str(9000 + len(nudges)))
            nudges.append(ip)
            last_nudge = time.time()
            info("pending=%d, sent a nudge ban for %s" % (n, ip))
        time.sleep(2)
    info("%s NOT drained after %ds (pending=%d)" % (label, timeout, pending()))
    return False, nudges


def expected(ip):
    return ctl("/__ctl/profile?ip=" + urllib.request.quote(ip, safe=""))["expected"]


def blank(r):
    return r == {"inetnum": "", "netname": "", "country": "", "org_id": "", "origin_asns": [], "abuse_emails": []}


def wait_log(needle, timeout=40):
    """The worker logs its stop line only after NPPP_F2B_WORKER_IDLE_SECONDS (10 s)
    of empty queue, so a log check right after the queue drains would be early."""
    t0 = time.time()
    while time.time() - t0 < timeout:
        if needle in log_text():
            return True
        time.sleep(1)
    return False


def log_errors(txt):
    return [l for l in txt.splitlines() if " ERROR " in l]


# --------------------------------------------------------------------------
# phases
# --------------------------------------------------------------------------
def phase_happy(a):
    reset_all()
    n = a.count
    rc, out = producer("ban", "--count", str(n), "--seed", "11", "--concurrency", "4",
                       "--dup", "2", "--v6-ratio", "0.15", "--ips-out", os.path.join(HERE, "run", "happy_ips.txt"))
    check("producer: %d bans (each sent twice, 4 parallel) all accepted" % n, rc == 0, out[-500:])
    ips = [l.strip() for l in open(os.path.join(HERE, "run", "happy_ips.txt")) if l.strip()]

    ok, _ = wait_drain(a.timeout)
    check("queue drained (no ban row left with rdap_json NULL)", ok)
    data = [r for r in rows() if r["event_type"] == "ban"]
    check("%d ban rows stored, replays collapsed" % n, len(data) == n, "rows=%d" % len(data))

    bad = []
    for r in data:
        want = expected(r["ip"])
        if r["rdap"] != want:
            bad.append((r["ip"], r["rdap"], want))
    check("every stored profile equals what fake RIPEstat served (parsers, ARIN+RIPE styles, country cleanup)",
          not bad, bad[:1])

    st = ctl("/__ctl/stats")
    info("fake stats: total=%d whois=%s abuse=%s unique=%d max_inflight=%d" % (
        st["total"], st["by_endpoint"].get("whois"), st["by_endpoint"].get("abuse"),
        st["unique_resources"], st["max_inflight"]))
    check("exactly one whois + one abuse request per IP (no duplicate lookups)",
          st["by_endpoint"].get("whois") == n and st["by_endpoint"].get("abuse") == n and st["unique_resources"] == n,
          st["by_endpoint"])
    check("never above RIPEstat's 8 concurrent requests per source IP", st["max_inflight"] <= 8 and st["limit_429"] == 0,
          "max_inflight=%s 429s=%s" % (st["max_inflight"], st["limit_429"]))
    check("sourceapp sent on every request and equals the registered prefix",
          st["missing_sourceapp"] == 0 and st["bad_sourceapp"] == 0 and list(st["sourceapps"]) == [SOURCEAPP], st["sourceapps"])
    check("User-Agent identifies the plugin", all(k.startswith("NPP-Fail2Ban-Monitor/") for k in st["user_agents"]), st["user_agents"])
    check("every IPv6 and IPv4 resource reached the server intact", st["bad_resource"] == 0 and set(st["by_resource"]) == set(ips),
          "bad_resource=%s" % st["bad_resource"])

    wait_log("Worker stopped:")
    txt = log_text()
    check("worker logged start and a clean idle stop", "Worker started:" in txt and "Worker stopped:" in txt and "reason=idle" in txt,
          txt[-400:])
    check("no ERROR lines in the plugin log", not log_errors(txt), log_errors(txt)[:3])
    info("worker stop line: " + next((l[l.find("Worker stopped"):] for l in txt.splitlines() if "Worker stopped" in l), "?"))


NONPUBLIC = ["10.20.30.40", "127.0.0.1", "100.64.7.7", "203.0.113.77", "169.254.1.1", "fd00::1234"]


def phase_edge(a):
    reset_all()
    rc, out = producer("edge", "--seed", "5")
    check("producer edge matrix: all %s expectations held" % out.count("[PASS]"), rc == 0,
          "\n".join(l for l in out.splitlines() if "[FAIL]" in l))
    ok, _ = wait_drain(a.timeout)
    check("queue drained", ok)
    data = [r for r in rows() if r["event_type"] == "ban"]
    by_ip = {r["ip"]: r for r in data}
    check("non-public IPs stored with a blank profile", all(ip in by_ip and blank(by_ip[ip]["rdap"]) for ip in NONPUBLIC),
          {ip: (by_ip.get(ip) or {}).get("rdap") for ip in NONPUBLIC})
    st = ctl("/__ctl/stats")
    check("non-public IPs were never sent to RIPEstat", not (set(NONPUBLIC) & set(st["by_resource"])), list(st["by_resource"]))
    pub = gen_ips(1, 5, 0)[0]
    pub6 = gen_ips(1, 6, 1.0)[0]
    check("public IPv4 and IPv6 enriched", all(ip in by_ip and by_ip[ip]["rdap"] == expected(ip) for ip in (pub, pub6)),
          [(ip, (by_ip.get(ip) or {}).get("rdap")) for ip in (pub, pub6)])
    check("the 'test' probe left no row behind", not any(r["event_type"] == "test" for r in rows()))
    wait_log("Worker stopped:")
    txt = log_text()
    # The EP10 gate samples its log (attempt #1 and every 5th), so 3 bad tokens
    # leave exactly one line, for the first attempt (the wrong 64-hex token).
    ep10 = [l for l in txt.splitlines() if " EP10:" in l]
    check("gate logged the first bad-token attempt once (sampled, masked IP)",
          len(ep10) == 1 and "TOKEN MISMATCH" in ep10[0] and "Attempt: #1" in ep10[0] and re.search(r"\d+\.\d+\.\d+\.\*\*", ep10[0]), ep10)


def phase_lifecycle(a):
    reset_all()
    rc, out = producer("lifecycle", "--seed", "21")
    check("producer lifecycle: replay / unban / reban rules", rc == 0, "\n".join(l for l in out.splitlines() if "[FAIL]" in l))
    ok, _ = wait_drain(a.timeout)
    check("queue drained", ok)
    ip = gen_ips(1, 21, 0)[0]
    data = [r for r in rows() if r["ip"] == ip]
    bans = [r for r in data if r["event_type"] == "ban"]
    unbans = [r for r in data if r["event_type"] == "unban"]
    check("3 ban rows (jail A, reban, jail B) and 1 unban row", len(bans) == 3 and len(unbans) == 1, (len(bans), len(unbans)))
    check("all ban rows share one enrichment", all(r["rdap"] == expected(ip) for r in bans))
    st = ctl("/__ctl/stats")
    check("RIPEstat asked once for this IP despite 3 bans", st["by_resource"].get(ip) == {"whois": 1, "abuse": 1}, st["by_resource"].get(ip))


def phase_faults(a):
    reset_all()
    ips = gen_ips(6, 77, 0)
    A, B, C, D, E, F = ips
    rules = [
        {"match": A, "endpoint": "whois", "mode": "http:500", "times": 2},     # transient, recovers
        {"match": B, "endpoint": "whois", "mode": "http:404", "times": None},   # permanent 4xx
        {"match": C, "endpoint": "abuse", "mode": "hang", "times": 1},          # > 6 s plugin timeout
        {"match": D, "endpoint": "whois", "mode": "badjson", "times": 1},       # 200 but not JSON
        {"match": E, "endpoint": "whois", "mode": "rir_unknown", "times": None},# bogus best-match answer
        {"match": F, "endpoint": "whois", "mode": "http:429", "times": 2},      # upstream rate limit
    ]
    ctl("/__ctl/faults", "POST", rules)
    ipfile = os.path.join(HERE, "run", "faults_ips.txt")
    open(ipfile, "w").write("\n".join(ips) + "\n")
    rc, out = producer("ban", "--ips-in", ipfile)
    check("producer: 6 bans accepted", rc == 0, out[-300:])
    ok, nudges = wait_drain(max(a.timeout, 150), "faulted queue")
    check("queue drained despite faults", ok)

    byip = {r["ip"]: r["rdap"] for r in rows() if r["event_type"] == "ban"}
    st = ctl("/__ctl/stats")
    per = st["by_resource"]
    info("faults applied by fake: %s" % st["faults_applied"])

    check("A whois 500 x2 -> retried, full profile stored", byip.get(A) == expected(A), byip.get(A))
    check("A took 3 whois attempts, abuse asked once (partial progress kept)", per.get(A) == {"whois": 3, "abuse": 1}, per.get(A))
    check("C abuse timeout -> retried, full profile stored", byip.get(C) == expected(C), byip.get(C))
    check("D badjson -> retried, full profile stored", byip.get(D) == expected(D), byip.get(D))
    check("F whois 429 x2 -> retried, full profile stored", byip.get(F) == expected(F), byip.get(F))

    b = byip.get(B)
    check("B whois 404 forever -> retry budget spent, partial profile stored (abuse only)",
          b is not None and b["inetnum"] == "" and b["abuse_emails"] == [expected(B)["abuse_emails"][0]], b)
    check("B was asked max_attempts=3 times, not forever", per.get(B, {}).get("whois") == 3, per.get(B))

    e = byip.get(E)
    check("E bogus 'authoritative rir could not be identified' answer is NOT stored as registry data",
          e is not None and e["inetnum"] == "" and e["netname"] == "" and e["country"] == "", e)
    check("E asked once per endpoint (an answer, not a failure)", per.get(E) == {"whois": 1, "abuse": 1}, per.get(E))

    wait_log("Worker stopped:")
    txt = log_text()
    check("give-up of B is logged", "Retry budget spent" in txt, txt[-500:])
    check("hung worker watchdog did not fire", "Hung worker" not in txt)
    info("log errors: %s" % (log_errors(txt)[:3] or "none"))


def phase_ratelimit(a):
    reset_all()
    rc, out = producer("ratelimit", "--concurrency", "8", "--count", "320", "--seed", "3")
    check("producer ratelimit: <=300 accepted, overflow answered 429", rc == 0, "\n".join(l for l in out.splitlines() if "[FAIL]" in l))
    check("the 429 'rate limit reached' error is logged once", log_text().count("Webhook rate limit reached") == 1,
          log_text()[-300:])


PHASES = {"happy": phase_happy, "edge": phase_edge, "lifecycle": phase_lifecycle,
          "faults": phase_faults, "ratelimit": phase_ratelimit}


def main():
    global TOKEN
    ap = argparse.ArgumentParser()
    ap.add_argument("--only", help="comma list of phases")
    ap.add_argument("--with-ratelimit", action="store_true")
    ap.add_argument("--count", type=int, default=40, help="IPs in the happy phase")
    ap.add_argument("--timeout", type=int, default=120)
    a = ap.parse_args()

    os.makedirs(os.path.join(HERE, "run"), exist_ok=True)
    TOKEN = wp("option", "get", "nppp_f2b_token")
    try:
        ctl("/__ctl/stats")
    except Exception as exc:
        sys.exit("fake RIPEstat not reachable as https://stat.ripe.net (%s). Run setup-lab.sh and start fake_ripestat.py." % exc)

    todo = a.only.split(",") if a.only else ["happy", "edge", "lifecycle", "faults"] + (["ratelimit"] if a.with_ratelimit else [])
    for name in todo:
        PHASE[0] = name
        print("\n== phase: %s" % name)
        t0 = time.time()
        try:
            PHASES[name](a)
        except Exception as exc:
            check("phase crashed", False, repr(exc))
        info("(%.0fs)" % (time.time() - t0))

    bad = [r for r in RESULTS if not r[2]]
    print("\n== SUMMARY: %d checks, %d passed, %d failed" % (len(RESULTS), len(RESULTS) - len(bad), len(bad)))
    for ph, name, _, detail in bad:
        print("   FAIL [%s] %s\n        %s" % (ph, name, str(detail)[:300]))
    sys.exit(1 if bad else 0)


TOKEN = ""
if __name__ == "__main__":
    main()
