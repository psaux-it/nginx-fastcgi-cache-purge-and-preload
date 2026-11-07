#!/usr/bin/env python3
"""POST receiver: appends 'METHOD path body' per request to argv[1] (listens on 127.0.0.1:argv[2])."""
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

LOG, PORT = sys.argv[1], int(sys.argv[2])

class H(BaseHTTPRequestHandler):
    def _rec(self, body=b""):
        with open(LOG, "a") as f:
            f.write("%s %s %s\n" % (self.command, self.path, body.decode("utf-8", "replace")))
    def _ok(self):
        self.send_response(200); self.send_header("Content-Length", "2"); self.end_headers(); self.wfile.write(b"ok")
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        self._rec(self.rfile.read(n)); self._ok()
    def do_GET(self):
        self._rec(); self._ok()
    def log_message(self, *a):
        pass

HTTPServer(("127.0.0.1", PORT), H).serve_forever()
