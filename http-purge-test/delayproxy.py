#!/usr/bin/env python3
"""Delay proxy: forwards to nginx at once, then holds the answer for DELAY seconds.
Makes the plugin's request time out while nginx has already started (or finished) its work.
usage: DELAY=3 python3 delayproxy.py   (listens on 127.0.0.1:8090 -> 127.0.0.1:8081)"""
import http.server, socketserver, urllib.request, os, time
DELAY = float(os.environ.get('DELAY', '3'))
class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        req = urllib.request.Request('http://127.0.0.1:8081' + self.path, headers={'Host': self.headers.get('Host', 'example.com')})
        try:
            r = urllib.request.urlopen(req); code, body, hdrs = r.status, r.read(), r.getheaders()
        except urllib.error.HTTPError as e:
            code, body, hdrs = e.code, e.read(), e.headers.items()
        time.sleep(DELAY)
        try:
            self.send_response(code)
            for k, v in hdrs:
                if k.lower() not in ('transfer-encoding', 'connection', 'content-length'): self.send_header(k, v)
            self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        except Exception: pass
    do_PURGE = do_GET
    def log_message(self, *a): pass
class S(socketserver.ThreadingMixIn, http.server.HTTPServer): daemon_threads = True
S(('127.0.0.1', 8090), H).serve_forever()
