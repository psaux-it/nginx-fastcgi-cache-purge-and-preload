#!/usr/bin/env python3
"""Wake listener: stands in for admin-ajax.php in the deferred_waiter scenario.
Answers every request with 200 and appends one line per POST to the log:  <epoch>\t<path>\t<body>
usage: python3 wakelistener.py   (listens on 127.0.0.1:8092, log /tmp/npp-wake.log; WAKE_LOG overrides the log path)"""
import http.server, socketserver, os, time
LOG = os.environ.get('WAKE_LOG', '/tmp/npp-wake.log')
class H(http.server.BaseHTTPRequestHandler):
    def _ok(self):
        self.send_response(200); self.send_header('Content-Length', '0'); self.end_headers()
    def do_GET(self): self._ok()
    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n).decode('utf-8', 'replace').replace('\n', ' ').replace('\t', ' ')
        with open(LOG, 'a') as f: f.write('%.3f\t%s\t%s\n' % (time.time(), self.path, body))
        self._ok()
    def log_message(self, *a): pass
class S(socketserver.ThreadingMixIn, http.server.HTTPServer): daemon_threads = True; allow_reuse_address = True
S(('127.0.0.1', 8092), H).serve_forever()
