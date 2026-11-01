#!/usr/bin/env python3
"""
Stand-in for the Nginx / PHP-FPM layer behind Varnish in the e2e harness.

Every GET / HEAD answers a cacheable 200 whose body names the Host and the RAW request target, so a test can tell
which object Varnish served. The backend counts every request that actually reaches it, keyed "Host + raw target":
a cache HIT never reaches it, so "the count did not move" == "Varnish answered from cache" and "the count moved"
== "the object was gone (purged / banned)". The tests read the counters from THIS port, never through Varnish.

  GET /__counts   JSON {"host/target": n, ...}       (not cached, not counted)
  GET /__reset    clears the counters
  /nostore...     answers Cache-Control: no-store (never cached)

usage: backend.py <port>        (binds 127.0.0.1 only)
"""
import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

COUNTS = {}
LOCK = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    server_version = 'npp-vt-backend'

    def log_message(self, *args):   # keep the harness output clean
        pass

    def _send(self, code, body, headers=None):
        raw = body.encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(raw)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(raw)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        if self.path == '/__counts':
            with LOCK:
                snap = dict(COUNTS)
            return self._send(200, json.dumps(snap), {'Cache-Control': 'no-store'})
        if self.path == '/__reset':
            with LOCK:
                COUNTS.clear()
            return self._send(200, 'reset', {'Cache-Control': 'no-store'})

        host = self.headers.get('Host', '')
        key = host + self.path
        with LOCK:
            COUNTS[key] = COUNTS.get(key, 0) + 1
            n = COUNTS[key]

        cc = 'no-store' if self.path.startswith('/nostore') else 'public, max-age=300'
        self._send(200, 'page host=%s target=%s hit=%d\n' % (host, self.path, n), {'Cache-Control': cc})

    # PURGE must never reach the origin through a correct VCL; if it does, say so loudly (the test asserts on 405).
    def do_PURGE(self):
        self._send(405, 'PURGE reached the origin (the VCL did not handle it)\n')


if __name__ == '__main__':
    port = int(sys.argv[1])
    ThreadingHTTPServer(('127.0.0.1', port), Handler).serve_forever()