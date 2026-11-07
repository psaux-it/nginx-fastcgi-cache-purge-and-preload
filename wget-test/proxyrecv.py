#!/usr/bin/env python3
"""Fake HTTP forward proxy: logs 'UA|METHOD|TARGET|PROTO' (the raw request-line as sent by the client) to argv[1]
and answers 200. Listens on 127.0.0.1:argv[2]. Lets the harness see absolute-form targets (http_proxy mode)."""
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

LOG, PORT = sys.argv[1], int(sys.argv[2])

class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def _rec(self):
        parts = self.raw_requestline.decode("latin-1").rstrip("\r\n").split(" ")
        with open(LOG, "a") as f:
            f.write("%s|%s|%s|%s\n" % (self.headers.get("User-Agent", ""), parts[0], parts[1] if len(parts) > 1 else "", parts[-1]))
        self.send_response(200); self.send_header("Content-Type", "text/html"); self.send_header("Content-Length", "3")
        self.send_header("Connection", "close"); self.end_headers()
        if self.command != "HEAD": self.wfile.write(b"ok\n")
    do_GET = do_HEAD = do_POST = _rec
    def log_message(self, *a): pass

HTTPServer(("127.0.0.1", PORT), H).serve_forever()
