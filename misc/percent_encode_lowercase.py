"""
MITMProxy Script: Percent-Encoded Path Normalizer for Nginx Cache Purge Preload

Description:
    This script is designed to work specifically with the
    "Nginx Cache Purge Preload" WordPress plugin.

    It intercepts HTTP requests and rewrites the request path by converting
    all percent-encoded characters (e.g., %2f, %3a) to lowercase (e.g., %2f, %3a),
    ensuring consistent cache key generation when using NGINX caching.

Usage:
    - Run as a mitmproxy systemd service (see associated mitmproxy.service)
    - The NPP plugin must have **"Use proxy"** enabled in its settings

Example:
    Input  path: /product/%E6%B0%B4%E6%BB%B4%E8%BD%AE%E9%94%BB%E7%A2%B3%E5%8D%95%E6%91%87/
    Output path: /product/%e6%b0%b4%e6%bb%b4%e8%bd%ae%e9%94%bb%e7%a2%b3%e5%8d%95%e6%91%87/

Why:
    Normalizing the encoding helps prevent cache misses and routing inconsistencies.

Requirements:
    - WordPress plugin: Nginx Cache Purge Preload (v2.1.3+)
    - "Use proxy" option enabled in the plugin settings
    - mitmproxy or mitmdump installed and running this script

Author: Hasan CALISIR
"""

from mitmproxy import http, ctx
import re

# Normalize percent-encoded values to lowercase
percent_encoded_re = re.compile(r'%[0-9A-Fa-f]{2}')

def request(flow: http.HTTPFlow) -> None:
    path = flow.request.path

    # Normalize percent-encoded values to lowercase
    new_path = percent_encoded_re.sub(lambda m: m.group(0).lower(), path)

    if new_path != path:
        flow.request.path = new_path
        ctx.log.info(f"Rewriting path: {path} → {new_path}")
