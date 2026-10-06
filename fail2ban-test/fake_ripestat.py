#!/usr/bin/env python3
"""
fake_ripestat.py - local stand-in for https://stat.ripe.net used to test the
NPP Fail2Ban IP enrichment worker end to end.

The plugin hardcodes two endpoints:
    GET /data/whois/data.json?resource=<ip>&sourceapp=<id>
    GET /data/abuse-contact-finder/data.json?resource=<ip>&sourceapp=<id>
This server answers both with deterministic per-IP data (RIPE style and ARIN
style record blocks), can inject faults per IP/endpoint, emulates RIPEstat's
"max 8 concurrent requests per source IP" rule, and records everything so the
test runner can assert on it.

Control API (same listener, prefix /__ctl):
    GET  /__ctl/stats                  counters, max in-flight, sourceapps ...
    GET  /__ctl/requests?limit=50      last requests
    GET  /__ctl/profile?ip=1.2.3.4     the exact profile this server serves
    GET  /__ctl/faults                 current fault rules
    POST /__ctl/faults                 replace rules (JSON list)
    POST /__ctl/reset                  clear stats and fault rules

Fault rule: {"match": "*"|"<ip>"|"<cidr>", "endpoint": "whois"|"abuse"|"*",
             "mode": "<mode>", "times": <int or null = forever>}
Modes:
    http:<code>   reply with that HTTP status (http:500, http:429, http:404 ...)
    hang          accept, then sleep --hang-seconds (plugin timeout test)
    reset         close the socket without answering (transport error)
    slow:<ms>     answer correctly after an extra delay
    badjson       HTTP 200 with a body that is not JSON
    shape         HTTP 200, valid JSON, but "data" list missing (permanent)
    status_error  HTTP 200, valid JSON, "status": "error"
    rir_unknown   (whois) RIPEstat's bogus "authoritative rir could not be
                  identified" best-match answer
    empty         valid answer with no records / no abuse contacts

CLI shortcut: --fault "8.8.8.8,whois,http:500,2"  (match,endpoint,mode[,times])

Standard library only (Python 3.8+).
"""
import argparse
import collections
import hashlib
import ipaddress
import json
import os
import ssl
import sys
import threading
import time
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

COUNTRIES = ["NL", "DE", "FR", "GB", "US", "RU", "CN", "BR", "TR", "IN",
             "JP", "KR", "UA", "PL", "SE", "ES", "IT", "VN", "ID", "AU"]

LOCK = threading.Lock()
STATE = {}
FAULTS = []
ARGS = None


def reset_state():
    with LOCK:
        STATE.clear()
        STATE.update({
            "started": time.time(),
            "total": 0,
            "by_endpoint": collections.Counter(),
            "by_resource": collections.defaultdict(collections.Counter),
            "status_codes": collections.Counter(),
            "faults_applied": collections.Counter(),
            "sourceapps": collections.Counter(),
            "user_agents": collections.Counter(),
            "missing_sourceapp": 0,
            "bad_sourceapp": 0,
            "bad_resource": 0,
            "limit_429": 0,
            "inflight": collections.Counter(),
            "max_inflight": 0,
            "log": collections.deque(maxlen=5000),
        })


# --------------------------------------------------------------------------
# Deterministic data
# --------------------------------------------------------------------------
def _h(ip):
    return int(hashlib.sha256(ip.encode()).hexdigest(), 16)


def profile(ip):
    """The exact profile the plugin should end up storing for this IP."""
    h = _h(ip)
    addr = ipaddress.ip_address(ip)
    cc = COUNTRIES[h % len(COUNTRIES)]
    if addr.version == 4:
        net = ipaddress.ip_network(ip + "/24", strict=False)
        inetnum_ripe = "%s - %s" % (net.network_address, net.broadcast_address)
        key_ripe = "inetnum"
    else:
        net = ipaddress.ip_network(ip + "/48", strict=False)
        inetnum_ripe = str(net)
        key_ripe = "inet6num"
    style = "arin" if h % 3 == 0 else "ripe"
    netname = "LAB-NET-%s-%04d" % (cc, h % 10000)
    org = "ORG-LAB%03d-%s" % (h % 1000, "ARIN" if style == "arin" else "RIPE")
    asn = 64512 + (h >> 16) % 1000          # private-use ASN range
    abuse = "abuse@lab-%s-%04d.example.net" % (cc.lower(), h % 10000)
    return {
        "style": style, "key_ripe": key_ripe, "inetnum_ripe": inetnum_ripe,
        "cidr": str(net), "netname": netname, "country": cc, "org": org,
        "asn": asn, "abuse": abuse,
        # country with a registry comment on a fraction of IPs
        "country_raw": ("EU # Country is really world wide" if h % 17 == 0 else cc),
    }


def expected_plugin_result(ip):
    """What nppp_f2b_rdap_apply_whois/_abuse should produce (for assertions)."""
    p = profile(ip)
    inetnum = p["inetnum_ripe"] if p["style"] == "ripe" else \
        ("%s - %s" % (ipaddress.ip_network(p["cidr"]).network_address,
                      ipaddress.ip_network(p["cidr"]).broadcast_address))
    raw = p["country_raw"]
    return {
        "inetnum": inetnum,
        "netname": p["netname"],
        "country": raw[:2].upper(),
        "org_id": p["org"],
        "origin_asns": ["AS%d" % p["asn"]],
        "abuse_emails": [p["abuse"]],
    }


def envelope(call, data, messages=None):
    return {
        "messages": messages if messages is not None else [["info", "fake-ripestat"]],
        "see_also": [],
        "version": "4.1",
        "data_call_name": call,
        "data_call_status": "supported",
        "cached": False,
        "data": data,
        "query_id": uuid.uuid4().hex,
        "process_time": 7,
        "server_id": "fake-ripestat",
        "build_version": "lab",
        "status": "ok",
        "status_code": 200,
        "time": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f"),
    }


def whois_body(ip, mode=None):
    p = profile(ip)
    if mode == "empty":
        return envelope("whois", {"records": [], "irr_records": [], "authorities": [], "resource": ip})
    if mode == "rir_unknown":
        data = {
            "records": [[
                {"key": "inetnum", "value": "0.0.0.0 - 255.255.255.255", "details_link": None},
                {"key": "netname", "value": "IANA-BLK", "details_link": None},
                {"key": "country", "value": "EU # Country field is actually all countries", "details_link": None},
            ]],
            "irr_records": [], "authorities": [], "resource": ip,
        }
        return envelope("whois", data, [["warning", "The authoritative RIR could not be identified for this resource."]])
    if p["style"] == "ripe":
        records = [[
            {"key": p["key_ripe"], "value": p["inetnum_ripe"], "details_link": None},
            {"key": "netname", "value": p["netname"], "details_link": None},
            {"key": "country", "value": p["country_raw"], "details_link": None},
            {"key": "org", "value": p["org"], "details_link": None},
            {"key": "status", "value": "ASSIGNED PA", "details_link": None},
        ]]
    else:   # ARIN naming scheme
        net = ipaddress.ip_network(p["cidr"])
        records = [[
            {"key": "NetRange", "value": "%s - %s" % (net.network_address, net.broadcast_address), "details_link": None},
            {"key": "CIDR", "value": p["cidr"], "details_link": None},
            {"key": "NetName", "value": p["netname"], "details_link": None},
            {"key": "OrgName", "value": p["org"], "details_link": None},
        ], [
            {"key": "OrgName", "value": "Lab Org " + p["org"], "details_link": None},
            {"key": "Country", "value": p["country_raw"], "details_link": None},
        ]]
    data = {
        "records": records,
        "irr_records": [[
            {"key": "route", "value": p["cidr"], "details_link": None},
            {"key": "origin", "value": str(p["asn"]), "details_link": None},
        ]],
        "authorities": ["arin" if p["style"] == "arin" else "ripe"],
        "resource": ip,
        "query_time": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S"),
    }
    return envelope("whois", data)


def abuse_body(ip, mode=None):
    p = profile(ip)
    contacts = [] if mode == "empty" else [p["abuse"]]
    data = {
        "abuse_contacts": contacts,
        "authoritative_rir": "arin" if p["style"] == "arin" else "ripe",
        "earliest_time": "2026-01-01T00:00:00",
        "latest_time": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S"),
        "parameters": {"resource": ip, "cache": None},
    }
    return envelope("abuse-contact-finder", data)


# --------------------------------------------------------------------------
# Fault matching
# --------------------------------------------------------------------------
def _rule_matches(rule, ip, endpoint):
    if rule.get("endpoint", "*") not in ("*", endpoint):
        return False
    m = rule.get("match", "*")
    if m == "*":
        return True
    try:
        if "/" in m:
            return ipaddress.ip_address(ip) in ipaddress.ip_network(m, strict=False)
    except ValueError:
        return False
    return m == ip


def take_fault(ip, endpoint):
    with LOCK:
        for rule in FAULTS:
            if not _rule_matches(rule, ip, endpoint):
                continue
            left = rule.get("times")
            if left is not None:
                if left <= 0:
                    continue
                rule["times"] = left - 1
            STATE["faults_applied"][rule["mode"]] += 1
            return rule["mode"]
    return None


def parse_cli_fault(spec):
    parts = [x.strip() for x in spec.split(",")]
    if len(parts) < 3:
        raise ValueError("--fault needs match,endpoint,mode[,times]: %r" % spec)
    return {"match": parts[0], "endpoint": parts[1], "mode": parts[2],
            "times": int(parts[3]) if len(parts) > 3 and parts[3] != "" else None}


# --------------------------------------------------------------------------
# HTTP handler
# --------------------------------------------------------------------------
class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "fake-ripestat/1.0"

    def log_message(self, fmt, *a):       # keep stdout clean; use /__ctl/requests
        if ARGS.verbose:
            sys.stderr.write("%s %s\n" % (self.address_string(), fmt % a))

    def _send(self, code, obj=None, raw=None, ctype="application/json"):
        body = raw if raw is not None else json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        return code

    def do_POST(self):
        self._route()

    def do_GET(self):
        self._route()

    def _route(self):
        url = urlparse(self.path)
        if url.path.startswith("/__ctl/"):
            return self._control(url)
        return self._ripestat(url)

    # ---- control plane ---------------------------------------------------
    def _control(self, url):
        q = parse_qs(url.query)
        if url.path == "/__ctl/stats" and self.command == "GET":
            with LOCK:
                s = STATE
                out = {
                    "uptime_s": int(time.time() - s["started"]),
                    "total": s["total"],
                    "by_endpoint": dict(s["by_endpoint"]),
                    "unique_resources": len(s["by_resource"]),
                    "by_resource": {k: dict(v) for k, v in s["by_resource"].items()},
                    "status_codes": dict(s["status_codes"]),
                    "faults_applied": dict(s["faults_applied"]),
                    "sourceapps": dict(s["sourceapps"]),
                    "user_agents": dict(s["user_agents"]),
                    "missing_sourceapp": s["missing_sourceapp"],
                    "bad_sourceapp": s["bad_sourceapp"],
                    "bad_resource": s["bad_resource"],
                    "limit_429": s["limit_429"],
                    "max_inflight": s["max_inflight"],
                    "max_concurrent_allowed": ARGS.max_concurrent,
                }
            return self._send(200, out)
        if url.path == "/__ctl/requests" and self.command == "GET":
            n = int(q.get("limit", ["50"])[0])
            with LOCK:
                return self._send(200, list(STATE["log"])[-n:])
        if url.path == "/__ctl/profile" and self.command == "GET":
            ip = q.get("ip", [""])[0]
            try:
                ipaddress.ip_address(ip)
            except ValueError:
                return self._send(400, {"error": "bad ip"})
            return self._send(200, {"profile": profile(ip), "expected": expected_plugin_result(ip)})
        if url.path == "/__ctl/faults":
            if self.command == "GET":
                with LOCK:
                    return self._send(200, FAULTS)
            n = int(self.headers.get("Content-Length") or 0)
            try:
                rules = json.loads(self.rfile.read(n) or b"[]")
                assert isinstance(rules, list)
                for r in rules:
                    assert isinstance(r, dict) and "mode" in r
            except Exception:
                return self._send(400, {"error": "expected a JSON list of rule objects"})
            with LOCK:
                FAULTS[:] = rules
            return self._send(200, {"ok": True, "rules": len(rules)})
        if url.path == "/__ctl/reset" and self.command == "POST":
            n = int(self.headers.get("Content-Length") or 0)
            if n:
                self.rfile.read(n)
            with LOCK:
                FAULTS[:] = []
            reset_state()
            return self._send(200, {"ok": True})
        return self._send(404, {"error": "unknown control path"})

    # ---- RIPEstat emulation ----------------------------------------------
    def _ripestat(self, url):
        client = self.client_address[0]
        q = parse_qs(url.query)
        resource = q.get("resource", [""])[0]
        sourceapp = q.get("sourceapp", [""])[0]
        endpoint = {"/data/whois/data.json": "whois",
                    "/data/abuse-contact-finder/data.json": "abuse"}.get(url.path)
        t0 = time.time()
        fault = None
        code = 0

        with LOCK:
            STATE["inflight"][client] += 1
            cur = STATE["inflight"][client]
            STATE["max_inflight"] = max(STATE["max_inflight"], cur)
            STATE["total"] += 1
            if endpoint:
                STATE["by_endpoint"][endpoint] += 1
            STATE["user_agents"][self.headers.get("User-Agent", "-")] += 1
            if not sourceapp:
                STATE["missing_sourceapp"] += 1
            elif not all(c.isalnum() or c in "-_" for c in sourceapp):
                STATE["bad_sourceapp"] += 1
            else:
                STATE["sourceapps"][sourceapp] += 1

        try:
            # RIPEstat: at most N concurrent requests per source IP.
            if cur > ARGS.max_concurrent:
                with LOCK:
                    STATE["limit_429"] += 1
                code = self._send(429, {"status": "error", "status_code": 429,
                                        "messages": [["error", "Too many concurrent requests"]]})
                return
            if endpoint is None:
                code = self._send(404, {"status": "error", "status_code": 404,
                                        "messages": [["error", "Unknown data call"]]})
                return
            try:
                ipaddress.ip_address(resource)
            except ValueError:
                with LOCK:
                    STATE["bad_resource"] += 1
                code = self._send(400, {"status": "error", "status_code": 400,
                                        "messages": [["error", "Invalid resource: %s" % resource[:60]]]})
                return

            with LOCK:
                STATE["by_resource"][resource][endpoint] += 1

            time.sleep((ARGS.latency_ms + (_h(resource + endpoint) % max(1, ARGS.jitter_ms))) / 1000.0)

            fault = take_fault(resource, endpoint)
            if fault:
                code = self._apply_fault(fault, endpoint, resource)
                if code is not None:
                    return
                # slow:<ms> falls through to a normal answer
            body = whois_body(resource, fault) if endpoint == "whois" else abuse_body(resource, fault)
            if fault == "shape":
                body["data"].pop("records" if endpoint == "whois" else "abuse_contacts", None)
            code = self._send(200, body)
        finally:
            with LOCK:
                STATE["inflight"][client] -= 1
                STATE["status_codes"][str(code)] += 1
                STATE["log"].append({
                    "t": round(t0, 3), "ms": int((time.time() - t0) * 1000),
                    "client": client, "endpoint": endpoint, "resource": resource,
                    "sourceapp": sourceapp, "fault": fault, "status": code, "inflight": cur,
                })

    def _apply_fault(self, mode, endpoint, resource):
        """Return an HTTP status when the fault consumed the request, None to
        fall through to the normal answer."""
        if mode.startswith("http:"):
            c = int(mode.split(":", 1)[1])
            return self._send(c, {"status": "error", "status_code": c,
                                  "messages": [["error", "injected %d" % c]]})
        if mode == "hang":
            time.sleep(ARGS.hang_seconds)
            self.close_connection = True
            return -1
        if mode == "reset":
            self.close_connection = True
            try:
                self.connection.shutdown(2)
            except OSError:
                pass
            return -2
        if mode.startswith("slow:"):
            time.sleep(int(mode.split(":", 1)[1]) / 1000.0)
            return None
        if mode == "badjson":
            return self._send(200, raw=b"{this is not json", ctype="text/plain")
        if mode == "status_error":
            return self._send(200, {"status": "error", "status_code": 500, "data": {},
                                    "messages": [["error", "injected"]]})
        return None     # shape / rir_unknown / empty are handled in the body


def main():
    global ARGS
    ap = argparse.ArgumentParser(description="Fake RIPEstat for NPP Fail2Ban worker tests")
    ap.add_argument("--bind", default="127.0.0.2")
    ap.add_argument("--port", type=int, default=443)
    here = os.path.dirname(os.path.abspath(__file__))
    ap.add_argument("--cert", default=os.path.join(here, "pki", "stat.ripe.net.crt"))
    ap.add_argument("--key", default=os.path.join(here, "pki", "stat.ripe.net.key"))
    ap.add_argument("--plain", action="store_true", help="plain HTTP instead of TLS")
    ap.add_argument("--latency-ms", type=int, default=150, help="base latency per request")
    ap.add_argument("--jitter-ms", type=int, default=100)
    ap.add_argument("--max-concurrent", type=int, default=8)
    ap.add_argument("--hang-seconds", type=float, default=15.0)
    ap.add_argument("--fault", action="append", default=[], help="match,endpoint,mode[,times]")
    ap.add_argument("--faults-file")
    ap.add_argument("-v", "--verbose", action="store_true")
    ARGS = ap.parse_args()

    reset_state()
    for spec in ARGS.fault:
        FAULTS.append(parse_cli_fault(spec))
    if ARGS.faults_file:
        with open(ARGS.faults_file) as fh:
            FAULTS.extend(json.load(fh))

    class Server(ThreadingHTTPServer):
        daemon_threads = True
        request_queue_size = 256
        allow_reuse_address = True

    srv = Server((ARGS.bind, ARGS.port), Handler)
    scheme = "http"
    if not ARGS.plain:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(ARGS.cert, ARGS.key)
        # handshake happens lazily in the per-connection thread, so a stalled
        # client cannot block the accept loop
        srv.socket = ctx.wrap_socket(srv.socket, server_side=True, do_handshake_on_connect=False)
        scheme = "https"
    print("fake-ripestat listening on %s://%s:%d (max_concurrent=%d, faults=%d)"
          % (scheme, ARGS.bind, ARGS.port, ARGS.max_concurrent, len(FAULTS)), flush=True)
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
