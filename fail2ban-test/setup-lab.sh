#!/usr/bin/env bash
# setup-lab.sh - one-time wiring so the plugin's hardcoded https://stat.ripe.net
# calls land on fake_ripestat.py instead of the internet.
#
#   ./setup-lab.sh [/path/to/wordpress]     install   (idempotent)
#   ./setup-lab.sh --remove [/path/to/wp]   undo it
#
# What it does
#   1. private CA + server cert for stat.ripe.net (SAN) under ./pki
#   2. /etc/hosts:  127.0.0.2 stat.ripe.net   (all of 127.0.0.0/8 is loopback,
#      so the fake gets :443 without clashing with nginx on 127.0.0.1)
#   3. lab CA added to the SYSTEM trust store (update-ca-certificates).
#      Not the WordPress bundle: WpOrg\Requests applies its 'verify' CA file
#      only on the single request() path. The worker uses request_multiple(),
#      whose curl handles ignore it and fall back to libcurl's system CAs.
#      (request_multiple() also bypasses WP_Http, so pre_http_request and
#      http_request_args filters cannot redirect the worker either.)
#   4. wp-content/mu-plugins/f2b-lab.php: shortens the RIPEstat retry timers so
#      fault tests finish in seconds
# Nothing in the plugin is modified.
set -euo pipefail

REMOVE=0
[ "${1:-}" = "--remove" ] && { REMOVE=1; shift; }
WP_PATH="${1:-${WP_PATH:-/var/www/html}}"
LAB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKI="$LAB_DIR/pki"
HOST_LINE="127.0.0.2 stat.ripe.net"
MU="$WP_PATH/wp-content/mu-plugins/f2b-lab.php"

[ "$(id -u)" -eq 0 ] || { echo "run as root (needs /etc/hosts and :443)"; exit 1; }

if [ "$REMOVE" -eq 1 ]; then
    [ -z "${LAB_IN_DOCKER:-}" ] && sed -i '/[[:space:]]stat\.ripe\.net$/d' /etc/hosts
    rm -f "$MU" /usr/local/share/ca-certificates/f2b-lab-ca.crt
    update-ca-certificates --fresh >/dev/null 2>&1 || true
    echo "removed hosts entry, mu-plugin and system CA (pki kept in $PKI)"
    exit 0
fi

[ -f "$WP_PATH/wp-load.php" ] || { echo "no WordPress at $WP_PATH"; exit 1; }
mkdir -p "$PKI" "$LAB_DIR/run"

if [ ! -f "$PKI/ca.crt" ] || [ ! -f "$PKI/stat.ripe.net.crt" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 -subj "/CN=F2B Lab CA" \
        -addext "basicConstraints=critical,CA:TRUE" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -addext "subjectKeyIdentifier=hash" \
        -keyout "$PKI/ca.key" -out "$PKI/ca.crt" 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=stat.ripe.net" \
        -keyout "$PKI/stat.ripe.net.key" -out "$PKI/stat.ripe.net.csr" 2>/dev/null
    printf 'subjectAltName=DNS:stat.ripe.net\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n' > "$PKI/san.ext"
    openssl x509 -req -in "$PKI/stat.ripe.net.csr" -CA "$PKI/ca.crt" -CAkey "$PKI/ca.key" \
        -CAcreateserial -days 825 -extfile "$PKI/san.ext" -out "$PKI/stat.ripe.net.crt" 2>/dev/null
    echo "created lab CA and stat.ripe.net certificate"
fi
chmod 644 "$PKI"/*.crt; chmod 600 "$PKI"/*.key

install -m 644 "$PKI/ca.crt" /usr/local/share/ca-certificates/f2b-lab-ca.crt
update-ca-certificates >/dev/null 2>&1 || { echo "update-ca-certificates failed (install ca-certificates)"; exit 1; }

if [ -z "${LAB_IN_DOCKER:-}" ]; then
    sed -i '/[[:space:]]stat\.ripe\.net$/d' /etc/hosts
    echo "$HOST_LINE" >> /etc/hosts
else
    grep -q '[[:space:]]stat\.ripe\.net$' /etc/hosts || { echo "stat.ripe.net missing: add extra_hosts (docker-compose.lab.yml)"; exit 1; }
fi

mkdir -p "$(dirname "$MU")"
cat > "$MU" <<PHP
<?php
/**
 * Plugin Name: F2B Lab (test environment only - do not deploy)
 * Description: Shortens the NPP RDAP retry timers so fault tests finish in seconds.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Production defaults: 120 s gap, 900 s once max_attempts is spent, 300 s negative cache.
add_filter( 'nppp_f2b_rdap_retry_gap', static function () { return 2; } );
add_filter( 'nppp_f2b_rdap_exhausted_retry_gap', static function () { return 4; } );
add_filter( 'nppp_f2b_rdap_negative_cache_ttl', static function () { return 20; } );
PHP
chmod 644 "$MU"

echo "OK"
echo "  hosts : $(getent ahosts stat.ripe.net | head -1)"
echo "  mu    : $MU"
echo "next: python3 $LAB_DIR/fake_ripestat.py &   (or ./run-e2e.sh, which starts it)"
