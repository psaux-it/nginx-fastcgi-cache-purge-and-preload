#!/usr/bin/env python3
"""
fake_f2b_producer.py - stands in for fail2ban's "nppp-webhook" action.

Exactly what the plugin's generated action file sends:

    curl -X POST <endpoint> -H "Authorization: Bearer <token>" \
         -H "Content-Type: application/json" \
         -d '{"event":"ban","jail":"<name>","ip":"<ip>"}'

with scenarios and assertions, whole chain can be exercised
without a real fail2ban or real attackers:

  webhook (EP10 gate + REST) -> events table -> worker spawn -> RIPEstat
  lookup  (fake_ripestat.py) -> rdap_json written back

Scenarios
  ban        N unique public IPs (optionally concurrent, with curl-retry dups)
  unban      unban the IPs written by an earlier `ban --ips-out`
  lifecycle  ban, replayed ban, unban, re-ban of one IP (dedup rules)
  edge       validation matrix: bad token, bad JSON, bad jail, private IPs ...
  ratelimit  more than 300 events inside one fixed 60 s window -> 429s
  test       the Security tab's {"event":"test"} probe

Exit code 0 = every expectation of the scenario held, 1 otherwise.
Standard library only (Python 3.8+).
"""
import argparse
import concurrent.futures as cf
import ipaddress
import json
import os
import random
import ssl
import statistics
import subprocess
import sys
import time
import urllib.error
import urllib.request

# Ranges the plugin (FILTER_FLAG_NO_PRIV/NO_RES_RANGE + its own list) treats
# as non-public. Python's is_global covers most; these are belt and braces so
# generated "attacker" IPs always reach the RIPEstat stage.
_EXTRA_EXCLUDED = [ipaddress.ip_network(n) for n in (
    "0.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16",
    "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24", "198.18.0.0/15",
    "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4",
)]

DEFAULT_URL = "http://127.0.0.1:8080/wp-json/nppp_f2b/v1/event"


# --------------------------------------------------------------------------
# helpers
# --------------------------------------------------------------------------
def public_ipv4(rng):
    while True:
        a = ipaddress.IPv4Address(rng.getrandbits(32))
        if a.is_global and not any(a in n for n in _EXTRA_EXCLUDED):
            return str(a)


def public_ipv6(rng):
    while True:
        a = ipaddress.IPv6Address((0x2 << 125) | rng.getrandbits(125))   # 2000::/3
        if a.is_global and not a.ipv4_mapped and not str(a).startswith(("2001:db8", "2002:", "2001::")):
            return str(a)


def gen_ips(n, seed, v6_ratio):
    rng = random.Random(seed)
    seen, out = set(), []
    while len(out) < n:
        ip = public_ipv6(rng) if rng.random() < v6_ratio else public_ipv4(rng)
        if ip not in seen:
            seen.add(ip)
            out.append(ip)
    return out


def resolve_token(args):
    if args.token:
        return args.token
    if os.environ.get("NPPP_F2B_TOKEN"):
        return os.environ["NPPP_F2B_TOKEN"]
    if args.wp_path:
        cmd = ["wp", "option", "get", "nppp_f2b_token", "--path=" + args.wp_path]
        if os.geteuid() == 0:
            cmd.append("--allow-root")
        try:
            return subprocess.check_output(cmd, stderr=subprocess.DEVNULL, timeout=60).decode().strip()
        except Exception as exc:
            sys.exit("cannot read token through wp-cli: %s" % exc)
    sys.exit("no token: use --token, NPPP_F2B_TOKEN or --wp-path")


class Client:
    def __init__(self, url, token, insecure, timeout):
        self.url, self.token, self.timeout = url, token, timeout
        self.ctx = ssl._create_unverified_context() if insecure else None

    def post(self, body, token="__default__", content_type="application/json"):
        """body: dict (-> JSON) or raw str/bytes. Returns (status, parsed|text, seconds)."""
        raw = body if isinstance(body, (bytes, bytearray)) else (
            body.encode() if isinstance(body, str) else json.dumps(body).encode())
        tok = self.token if token == "__default__" else token
        req = urllib.request.Request(self.url, data=raw, method="POST")
        req.add_header("Content-Type", content_type)
        if tok is not None:
            req.add_header("Authorization", "Bearer " + tok)
        t0 = time.time()
        try:
            with urllib.request.urlopen(req, timeout=self.timeout, context=self.ctx) as r:
                status, text = r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode("utf-8", "replace")
        except Exception as e:                         # connection refused, timeout ...
            return 0, str(e), time.time() - t0
        try:
            return status, json.loads(text), time.time() - t0
        except ValueError:
            return status, text, time.time() - t0

    def event(self, event, jail, ip, **kw):
        return self.post({"event": event, "jail": jail, "ip": ip}, **kw)


class Checks:
    def __init__(self):
        self.rows = []

    def expect(self, name, ok, detail=""):
        self.rows.append((name, bool(ok), detail))
        print("  [%s] %s%s" % ("PASS" if ok else "FAIL", name, ("  -> " + str(detail)) if detail and not ok else ""))
        return ok

    @property
    def failed(self):
        return [r for r in self.rows if not r[1]]


# --------------------------------------------------------------------------
# scenarios
# --------------------------------------------------------------------------
def sc_ban(c, a):
    if a.ips_in:
        ips = [l.strip() for l in open(a.ips_in) if l.strip()]
    else:
        ips = gen_ips(a.count, a.seed, a.v6_ratio)
    if a.ips_out:
        with open(a.ips_out, "w") as fh:
            fh.write("\n".join(ips) + "\n")

    jobs = []
    for ip in ips:
        for _ in range(max(1, a.dup)):            # curl --retry replay simulation
            jobs.append(ip)

    results, interval = [], (1.0 / a.rate) if a.rate > 0 else 0
    t0 = time.time()

    def one(ip):
        return ip, c.event(a.event, a.jail, ip)

    with cf.ThreadPoolExecutor(max_workers=max(1, a.concurrency)) as ex:
        futs = []
        for ip in jobs:
            futs.append(ex.submit(one, ip))
            if interval:
                time.sleep(interval)
        for f in cf.as_completed(futs):
            results.append(f.result())

    wall = time.time() - t0
    codes, dups, lat = {}, 0, []
    for ip, (st, body, dt) in results:
        codes[st] = codes.get(st, 0) + 1
        lat.append(dt)
        if isinstance(body, dict) and body.get("duplicate"):
            dups += 1
    lat.sort()
    summary = {
        "scenario": "ban" if a.event == "ban" else a.event, "unique_ips": len(ips), "requests": len(jobs),
        "status_codes": codes, "duplicates_reported": dups,
        "expected_duplicates": len(jobs) - len(ips),
        "wall_s": round(wall, 2), "req_per_s": round(len(jobs) / wall, 1) if wall else None,
        "lat_p50_ms": int(statistics.median(lat) * 1000) if lat else 0,
        "lat_p95_ms": int(lat[int(len(lat) * 0.95) - 1] * 1000) if lat else 0,
        "ips_file": a.ips_out,
    }
    print(json.dumps(summary, indent=2))
    ch = Checks()
    ch.expect("all requests answered 200", codes.keys() == {200}, codes)
    if a.dup > 1:
        ch.expect("curl-retry replays reported as duplicate", dups == summary["expected_duplicates"],
                  "%d vs %d" % (dups, summary["expected_duplicates"]))
    return ch


def sc_lifecycle(c, a):
    ip = gen_ips(1, a.seed, 0)[0]
    print("lifecycle IP:", ip)
    ch = Checks()
    s, b, _ = c.event("ban", a.jail, ip)
    ch.expect("1 ban accepted", s == 200 and b.get("ok") is True and not b.get("duplicate"), (s, b))
    s, b, _ = c.event("ban", a.jail, ip)
    ch.expect("2 immediate repeat is a replay", s == 200 and b.get("duplicate") is True, (s, b))
    s, b, _ = c.event("unban", a.jail, ip)
    ch.expect("3 unban after ban is a new event", s == 200 and not b.get("duplicate"), (s, b))
    s, b, _ = c.event("ban", a.jail, ip)
    ch.expect("4 ban->unban->ban is a real reban", s == 200 and not b.get("duplicate"), (s, b))
    s, b, _ = c.event("ban", a.jail + "2", ip)
    ch.expect("5 same IP in another jail is not a replay", s == 200 and not b.get("duplicate"), (s, b))
    return ch


def sc_edge(c, a):
    ch = Checks()
    good = gen_ips(1, a.seed, 0)[0]
    s, b, _ = c.event("ban", a.jail, good)
    ch.expect("valid public ban -> 200", s == 200 and b.get("ok") is True, (s, b))

    # Non-public addresses are accepted and stored with a blank profile; they
    # must never reach RIPEstat (checked by the runner through fake stats).
    for label, ip in (("RFC1918", "10.20.30.40"), ("loopback", "127.0.0.1"),
                      ("CGNAT", "100.64.7.7"), ("TEST-NET-3", "203.0.113.77"),
                      ("link-local", "169.254.1.1"), ("IPv6 ULA", "fd00::1234"),
                      ("public IPv6", gen_ips(1, a.seed + 1, 1.0)[0])):
        s, b, _ = c.event("ban", a.jail, ip)
        ch.expect("%s ban accepted (%s)" % (label, ip), s == 200 and b.get("ok") is True, (s, b))

    real = c.token or ""
    wrong = "0" * 64 if real != "0" * 64 else "1" * 64
    s, _, _ = c.event("ban", a.jail, good, token=wrong)
    ch.expect("wrong 64-hex token -> 403", s == 403, s)
    s, _, _ = c.event("ban", a.jail, good, token="abc")
    ch.expect("malformed token -> 403", s == 403, s)
    s, _, _ = c.event("ban", a.jail, good, token=None)
    ch.expect("missing token -> 403", s == 403, s)

    s, _, _ = c.post("{this is not json")
    ch.expect("invalid JSON body -> 400", s == 400, s)
    s, _, _ = c.event("ban", "bad jail!", good)
    ch.expect("jail with illegal characters -> 400", s == 400, s)
    s, _, _ = c.event("ban", "x" * 65, good)
    ch.expect("jail longer than 64 -> 400", s == 400, s)
    s, _, _ = c.event("ban", a.jail, "<ip>")
    ch.expect("unreplaced <ip> placeholder -> 400", s == 400, s)
    s, _, _ = c.event("ban", a.jail, "999.1.1.1")
    ch.expect("invalid IP -> 400", s == 400, s)
    s, _, _ = c.event("flush", a.jail, good)
    ch.expect("unknown event type -> 400", s == 400, s)
    s, b, _ = c.event("test", a.jail, good)
    ch.expect("test event -> 200 test:true", s == 200 and isinstance(b, dict) and b.get("test") is True, (s, b))
    return ch


def sc_ratelimit(c, a):
    # The counter is a fixed window of floor(time()/60). Start of a window gives
    # the whole 300 budget to this run; do not straddle a rollover.
    left = 60 - (time.time() % 60)
    if left < 20:
        print("aligning to the next minute window (%.0fs)..." % (left + 0.5))
        time.sleep(left + 0.5)
    n = a.count if a.count > 300 else 320
    rng = random.Random(a.seed)
    # RFC1918 IPs: counted by the limiter but no enrichment work is created.
    ips = ["10.%d.%d.%d" % (rng.randrange(256), rng.randrange(256), 1 + i % 250) for i in range(n)]
    ips = list(dict.fromkeys(ips))[:n]
    with cf.ThreadPoolExecutor(max_workers=max(1, a.concurrency)) as ex:
        res = list(ex.map(lambda ip: c.event("ban", a.jail, ip)[0], ips))
    ok, limited = res.count(200), res.count(429)
    print(json.dumps({"sent": len(res), "200": ok, "429": limited, "other": len(res) - ok - limited}))
    ch = Checks()
    ch.expect("at most 300 accepted in the window", ok <= 300, ok)
    ch.expect("overflow answered 429", limited >= len(res) - 300 and limited > 0, limited)
    ch.expect("nothing but 200/429", ok + limited == len(res), res[:10])
    return ch


def sc_test(c, a):
    s, b, _ = c.event("test", a.jail, "127.0.0.1")
    ch = Checks()
    ch.expect("test event accepted and its row removed", s == 200 and isinstance(b, dict) and b.get("test") and b.get("write"), (s, b))
    return ch


def main():
    ap = argparse.ArgumentParser(description="Fake fail2ban webhook producer for the NPP plugin")
    ap.add_argument("scenario", choices=("ban", "unban", "lifecycle", "edge", "ratelimit", "test"))
    ap.add_argument("--url", default=DEFAULT_URL)
    ap.add_argument("--token")
    ap.add_argument("--wp-path", help="read the token with wp-cli from this WordPress path")
    ap.add_argument("--jail", default="nginx-botsearch")
    ap.add_argument("--insecure", action="store_true", help="skip TLS verification (https URL)")
    ap.add_argument("--timeout", type=float, default=15)
    ap.add_argument("--count", type=int, default=20, help="unique IPs (ban) / events (ratelimit)")
    ap.add_argument("--seed", type=int, default=1, help="same seed = same IPs")
    ap.add_argument("--v6-ratio", type=float, default=0.0, help="share of IPv6 addresses 0..1")
    ap.add_argument("--concurrency", type=int, default=1, help="1 mimics the flock-serialised action")
    ap.add_argument("--rate", type=float, default=0, help="max submissions per second (0 = unlimited)")
    ap.add_argument("--dup", type=int, default=1, help="send each event N times (curl --retry replays)")
    ap.add_argument("--ips-out", help="write generated IPs here")
    ap.add_argument("--ips-in", help="use these IPs instead of generating")
    a = ap.parse_args()

    a.event = "unban" if a.scenario == "unban" else "ban"
    token = resolve_token(a)
    c = Client(a.url, token, a.insecure, a.timeout)

    print("== scenario: %s  url=%s  jail=%s" % (a.scenario, a.url, a.jail))
    fn = {"ban": sc_ban, "unban": sc_ban, "lifecycle": sc_lifecycle, "edge": sc_edge,
          "ratelimit": sc_ratelimit, "test": sc_test}[a.scenario]
    ch = fn(c, a)
    bad = ch.failed
    print("== %d checks, %d failed" % (len(ch.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
