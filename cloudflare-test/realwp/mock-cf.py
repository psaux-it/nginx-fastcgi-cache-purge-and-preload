#!/usr/bin/env python3
"""
Mock Cloudflare v4 API for the real-WordPress layer of the Cloudflare harness.

Speaks HTTPS on :443 (cert for api.cloudflare.com, signed by a local CA) and a plain control API on 127.0.0.1:18080.
It validates what the official docs say a client must send, so a malformed request fails here, not on production:
  * auth: Authorization: Bearer <token>  OR  X-Auth-Email + X-Auth-Key
  * GET  /client/v4/zones?name=&status=active
  * POST /client/v4/zones/{id}/purge_cache : exactly one of purge_everything / files / tags / hosts / prefixes,
        files: <= max_files entries, each a string URL or {url, headers{}}, URL <= 4096 chars
  * optional token bucket (429 + Retry-After), scripted failures, scripted latency

Mock behaviour is MODELLED on the documentation, not recorded from the real service: it proves the client sends a
well formed request and survives the documented failure modes, not that Cloudflare behaves exactly like this.

Control (plain HTTP, 127.0.0.1:18080):
  POST /reset                         clear log, scripts, limits (keeps tokens / zones)
  POST /config   {json}               merge: max_files, zones, tokens, global_keys, rate {capacity, per_second}
  POST /script   {"match":"purge|zones|*","status":503,"headers":{},"body":{..},"delay":0,"times":1}
  GET  /log                           every request received (JSON)
"""
import json, ssl, sys, threading, time, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK = threading.Lock()
ZONE_ID = 'a' * 32
DEFAULT = lambda: {
    'max_files': 100,
    'tokens': ['cfut_' + 'a' * 40 + 'b3c4d5e6'],
    'global_keys': {},                       # email -> key
    'zones': [{'id': ZONE_ID, 'name': 'example.com', 'status': 'active'}],
    'rate': None,                            # {'capacity': 25, 'per_second': 5/60}
    'bucket': None, 'bucket_t': 0.0,
    'scripts': [], 'log': [],
}
S = DEFAULT()

def err(status, code, msg, headers=None):
    return status, {'success': False, 'errors': [{'code': code, 'message': msg}], 'messages': [], 'result': None}, headers or {}

def ok(result, info=None):
    b = {'success': True, 'errors': [], 'messages': [], 'result': result}
    if info: b['result_info'] = info
    return 200, b, {}

def auth(h):
    a = h.get('Authorization', '')
    if a.startswith('Bearer '):
        return a[7:] in S['tokens']
    if h.get('X-Auth-Email') and h.get('X-Auth-Key'):
        return S['global_keys'].get(h['X-Auth-Email']) == h['X-Auth-Key']
    return None   # no credentials at all

def bucket_take():
    r = S['rate']
    if not r: return 0
    now = time.time()
    if S['bucket'] is None: S['bucket'], S['bucket_t'] = float(r['capacity']), now
    S['bucket'] = min(r['capacity'], S['bucket'] + (now - S['bucket_t']) * r['per_second']); S['bucket_t'] = now
    if S['bucket'] >= 1: S['bucket'] -= 1; return 0
    return max(1, int((1 - S['bucket']) / r['per_second']) + 1)

def handle_purge(zone_id, body):
    if not any(z['id'] == zone_id for z in S['zones']):
        return err(404, 7003, 'Could not route to /zones/%s/purge_cache, perhaps your object identifier is invalid?' % zone_id)
    if not isinstance(body, dict): return err(400, 1012, 'Request must contain one of "files", "tags", "hosts", "prefixes" or "purge_everything"')
    keys = [k for k in ('purge_everything', 'files', 'tags', 'hosts', 'prefixes') if k in body]
    extra = [k for k in body if k not in ('purge_everything', 'files', 'tags', 'hosts', 'prefixes')]
    if len(keys) != 1 or extra: return err(400, 1012, 'Request must contain exactly one of purge_everything/files/tags/hosts/prefixes')
    wait = bucket_take()
    if wait: return err(429, 971, 'Rate limit exceeded', {'Retry-After': str(wait)})
    if keys[0] == 'purge_everything':
        if body['purge_everything'] is not True: return err(400, 1012, 'purge_everything must be true')
        return ok({'id': zone_id})
    if keys[0] != 'files': return ok({'id': zone_id})
    files = body['files']
    if not isinstance(files, list) or not files: return err(400, 1012, 'files must be a non-empty array')
    if len(files) > S['max_files']: return err(400, 1012, 'You can only purge up to %d files at a time' % S['max_files'])
    for f in files:
        if isinstance(f, str): url = f
        elif isinstance(f, dict) and isinstance(f.get('url'), str) and isinstance(f.get('headers', {}), dict) and set(f) <= {'url', 'headers'}: url = f['url']
        else: return err(400, 1012, 'each file must be a URL string or {url, headers}')
        if len(url) > 4096: return err(400, 1012, 'URL too long')
        p = urllib.parse.urlparse(url)
        if p.scheme not in ('http', 'https') or not p.hostname: return err(400, 1012, 'Invalid URL')
    return ok({'id': zone_id})

class Api(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def _send(self, status, body, headers=None):
        raw = json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(raw)))
        for k, v in (headers or {}).items(): self.send_header(k, v)
        self.end_headers(); self.wfile.write(raw)
    def _go(self, method):
        n = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(n) if n else b''
        u = urllib.parse.urlparse(self.path); q = dict(urllib.parse.parse_qsl(u.query))
        kind = 'purge' if u.path.endswith('/purge_cache') else ('zones' if u.path.rstrip('/').endswith('/zones') else 'other')
        with LOCK:
            entry = {'t': time.time(), 'method': method, 'path': u.path, 'query': q, 'kind': kind,
                     'headers': {k: v for k, v in self.headers.items()}, 'raw': raw.decode('utf8', 'replace'), 'status': None}
            S['log'].append(entry)
            sc = None
            for s in S['scripts']:
                if s['match'] in ('*', kind) and s.get('times', 1) != 0:
                    sc = s
                    if s.get('times', 1) > 0: s['times'] -= 1
                    break
        if sc and sc.get('delay'): time.sleep(sc['delay'])
        with LOCK:
            if sc and sc.get('status'):
                status, body, hdr = sc['status'], sc.get('body', {'success': False, 'errors': [{'code': 0, 'message': 'scripted'}], 'result': None}), sc.get('headers', {})
            else:
                a = auth(self.headers)
                if a is None: status, body, hdr = err(400, 6003, 'Invalid request headers')
                elif a is False: status, body, hdr = err(403, 10000, 'Authentication error')
                elif kind == 'zones' and method == 'GET':
                    res = [z for z in S['zones'] if z['name'] == q.get('name') and z['status'] == q.get('status', z['status'])]
                    status, body, hdr = ok(res, {'page': 1, 'per_page': 20, 'count': len(res), 'total_count': len(res), 'total_pages': 1})
                elif kind == 'purge' and method == 'POST':
                    if 'application/json' not in self.headers.get('Content-Type', ''): status, body, hdr = err(400, 6003, 'Content-Type must be application/json')
                    else:
                        try: parsed = json.loads(raw or b'null')
                        except Exception: parsed = None
                        zid = u.path.split('/')[-2]
                        status, body, hdr = handle_purge(zid, parsed)
                elif kind == 'purge': status, body, hdr = err(405, 1000, 'Method not allowed (purge_cache accepts POST here)')
                else: status, body, hdr = err(404, 7000, 'No route for that URI')
            entry['status'] = status
        self._send(status, body, hdr)
    def do_GET(self): self._go('GET')
    def do_POST(self): self._go('POST')
    def do_DELETE(self): self._go('DELETE')
    def do_PUT(self): self._go('PUT')

class Ctl(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def _send(self, body):
        raw = json.dumps(body).encode(); self.send_response(200)
        self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def do_GET(self):
        with LOCK: self._send(S['log'])
    def do_POST(self):
        global S
        n = int(self.headers.get('Content-Length') or 0); body = json.loads(self.rfile.read(n) or b'{}')
        with LOCK:
            if self.path == '/reset':
                keep = {k: S[k] for k in ('tokens', 'global_keys', 'zones')}
                S = DEFAULT(); S.update(keep)
            elif self.path == '/config':
                for k, v in body.items(): S[k] = v
                S['bucket'] = None
            elif self.path == '/script': S['scripts'].append(body)
        self._send({'ok': True})

def main():
    cert, key = sys.argv[1], sys.argv[2]
    api = ThreadingHTTPServer(('127.0.0.1', 443), Api)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert, key)
    api.socket = ctx.wrap_socket(api.socket, server_side=True)
    ctl = ThreadingHTTPServer(('127.0.0.1', 18080), Ctl)
    threading.Thread(target=ctl.serve_forever, daemon=True).start()
    print('mock-cf ready', flush=True)
    api.serve_forever()

if __name__ == '__main__':
    main()
