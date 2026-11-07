#!/usr/bin/env bash
# wget / wget2 x safexec / no-safexec x URL-normalization matrix + purge-gate tests.
#
#   sudo ./run-wget-matrix.sh [--quick] [--only=GROUP[,GROUP]] [--flavors=wget,wget2,wget2-as-wget] [--keep]
#
# Needs root (safexec drops privileges), gcc, php-cli, nginx (ssl + http_v2), wget, wget2, stdbuf.
# Everything is built from the checked-out sources: nothing from safexec/bin is used.
#
# What it proves (see matrix.php for the individual assertions):
#   static  : safexec version vs plugin minimum, call-site drift, env mapping drift
#   wire    : every wget door x binary x safexec x PCTNORM mode x transport (HTTP/1.1, TLS/1.1, TLS/h2)
#             x URL corpus -> the request-target nginx really received
#   proxy   : use_proxy=yes (wget1 -e http_proxy / wget2 --http-proxy) x safexec x PCTNORM against a fake proxy
#   gate    : the Purge All preload gate + waiter + watchdog + network probe, per binary
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
WORK="${NPPM_WORK:-/tmp/npp-work}"
BUILD="${NPPM_BUILD:-/tmp/npp-build}"
SFX_DIR="/usr/local/bin"
SO_DIR="/usr/lib/npp"            # safexec only injects a shim from a root-owned, non-writable path
PORT_HTTP=18080; PORT_H1S=18443; PORT_H2=18444; PORT_POST=18081; PORT_PROXY=18082

QUICK=0; ONLY=""; FLAVORS="wget,wget2,wget2-as-wget"; KEEP=0
for a in "$@"; do
    case "$a" in
        --quick) QUICK=1 ;;
        --only=*) ONLY="${a#--only=}" ;;
        --flavors=*) FLAVORS="${a#--flavors=}" ;;
        --keep) KEEP=1 ;;
        -h|--help) sed -n 2,14p "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "unknown option: $a" >&2; exit 2 ;;
    esac
done

die() { echo "FATAL: $*" >&2; exit 2; }
[ "$(id -u)" = 0 ] || die "run as root (safexec drops to nobody)"
for t in gcc php nginx wget wget2 openssl stdbuf; do command -v "$t" >/dev/null 2>&1 || die "missing tool: $t"; done

NGINX_PID=""; POST_PID=""; PROXY_PID=""
cleanup() {
    [ -n "$NGINX_PID" ] && kill "$NGINX_PID" 2>/dev/null
    [ -n "$POST_PID" ] && kill "$POST_PID" 2>/dev/null
    [ -n "$PROXY_PID" ] && kill "$PROXY_PID" 2>/dev/null
    pkill -f 'NPPM-' 2>/dev/null
    [ "$KEEP" = 1 ] || rm -rf "$WORK/tmp" 2>/dev/null
}
trap cleanup EXIT

rm -rf "$WORK"; mkdir -p "$WORK/tmp" "$WORK/dl" "$WORK/alias" "$BUILD"
chmod 755 "$WORK"

# ---------------------------------------------------------------- build ----
echo "== build (from $REPO/safexec)"
S="$REPO/safexec"
gcc -O2 -pipe -fPIC -Wall -Wextra -Wformat=2 -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=2 -DWGET_FASTPATH \
    -shared "$S/libnpp_norm.c" -o "$BUILD/libnpp_norm.so" -Wl,-soname,libnpp_norm.so -ldl -lpthread \
    || die "libnpp_norm build failed"
SFX_FLAGS=(-D_GNU_SOURCE -DSAFEXEC_NPP -O2 -pipe -fPIE -pie -Wall -Wextra -Wformat=2 -Werror=format-security
           -fno-strict-overflow -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=2)
# as-is: the version the repo really ships (used by the static version-gate test)
gcc "${SFX_FLAGS[@]}" "$S/safexec.c" -o "$BUILD/safexec-asis" || die "safexec (as-is) build failed"
# matrix build: identical source, version forced to the plugin minimum so the wget2 + safexec paths are exercised
MINVER="$(grep -oE "NPPP_SAFEXEC_WGET2_MIN', *'[0-9.]+'" "$REPO/includes/compat-wget.php" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
sed -E "s/^(#define SAFEXEC_VERSION +)\"[0-9.]+\"/\1\"${MINVER:-1.9.8}\"/" "$S/safexec.c" > "$BUILD/safexec-minver.c"
gcc "${SFX_FLAGS[@]}" -I"$S" "$BUILD/safexec-minver.c" -o "$BUILD/safexec-minver" || die "safexec (min-version) build failed"

install -d -m 0755 -o root -g root "$SO_DIR"
install -m 0755 -o root -g root "$BUILD/libnpp_norm.so" "$SO_DIR/libnpp_norm.so"
install -m 0755 -o root -g root "$BUILD/safexec-minver" "$SFX_DIR/safexec"
install -m 0755 -o root -g root "$BUILD/safexec-asis"   "$SFX_DIR/safexec-asis"
ln -sf "$(command -v wget2)" "$WORK/alias/wget"         # Fedora wget2-wget / distro alternatives

# -------------------------------------------------------------- fixtures ----
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/key.pem" -out "$WORK/cert.pem" -days 2 \
    -subj "/CN=localhost" >/dev/null 2>&1 || die "openssl failed"
sed -e "s#@WORK@#$WORK#g" -e "s#@SITE@#$HERE/site#g" "$HERE/locations.conf.in" > "$WORK/locations.conf"
sed -e "s#@WORK@#$WORK#g" -e "s#@SITE@#$HERE/site#g" \
    -e "s#@PORT_HTTP@#$PORT_HTTP#" -e "s#@PORT_H1S@#$PORT_H1S#" -e "s#@PORT_H2@#$PORT_H2#" \
    "$HERE/nginx.conf.in" > "$WORK/nginx.conf"
echo '{"pending":{"total":0},"active":0}' > "$WORK/queue-status.json"
nginx -t -c "$WORK/nginx.conf" >/dev/null 2>&1 || { nginx -t -c "$WORK/nginx.conf"; die "nginx config invalid"; }
nginx -c "$WORK/nginx.conf" >/dev/null 2>&1 &
NGINX_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
    curl -s -o /dev/null "http://127.0.0.1:$PORT_HTTP/" && break; sleep 0.3
done
curl -s -o /dev/null "http://127.0.0.1:$PORT_HTTP/" || die "nginx did not start (see $WORK/error.log)"
PROTO="$(curl -sk --http2 -o /dev/null -w '%{http_version}' "https://127.0.0.1:$PORT_H2/")"
[ "$PROTO" = 2 ] || die "h2 listener does not negotiate HTTP/2 (got '$PROTO')"
python3 "$HERE/postrecv.py" "$WORK/post.log" "$PORT_POST" &
POST_PID=$!
python3 "$HERE/proxyrecv.py" "$WORK/proxy.log" "$PORT_PROXY" &
PROXY_PID=$!
: > "$WORK/wire.log"; : > "$WORK/proxy.log"
echo "   shim=$SO_DIR/libnpp_norm.so safexec(min)=$("$SFX_DIR/safexec" --version 2>&1 | head -1) safexec(as-is)=$("$SFX_DIR/safexec-asis" --version 2>&1 | head -1)"
echo "   wget=$(wget --version | head -1 | cut -d' ' -f1-3) wget2=$(wget2 --version | head -1 | cut -d' ' -f1-3) nginx=$(nginx -v 2>&1 | cut -d/ -f2)"

# ------------------------------------------------------------------- run ----
export NPPM_REPO="$REPO" NPPM_WORK="$WORK" NPPM_SAFEXEC="$SFX_DIR/safexec" NPPM_SAFEXEC_ASIS="$SFX_DIR/safexec-asis" \
       NPPM_SO="$SO_DIR/libnpp_norm.so" NPPM_ALIAS_DIR="$WORK/alias" \
       NPPM_PORT_HTTP="$PORT_HTTP" NPPM_PORT_H1S="$PORT_H1S" NPPM_PORT_H2="$PORT_H2" NPPM_PORT_POST="$PORT_POST" NPPM_PORT_PROXY="$PORT_PROXY" \
       NPPM_QUICK="$QUICK" NPPM_ONLY="$ONLY"

rc=0; first=1
IFS=',' read -r -a FL <<< "$FLAVORS"
for f in "${FL[@]}"; do
    echo
    echo "################ flavor: $f ################"
    PHPRUN=(php -d display_errors=1 -d error_reporting=-1 "$HERE/matrix.php" "$f")
    [ "$first" = 1 ] && PHPRUN+=(--static)
    if [ "$f" = wget2-as-wget ]; then
        # Fedora wget2-wget: /usr/bin/wget IS Wget2. safexec pins the tool from its trusted dirs (ignores PATH), so
        # bind-mount wget2 over wget inside a private mount namespace (nothing on the host is changed).
        if command -v unshare >/dev/null 2>&1 && unshare -m true 2>/dev/null; then
            W1="$(command -v wget)"; W2="$(command -v wget2)"
            unshare -m sh -c 'mount --bind "$1" "$2" || exit 97; shift 2; exec "$@"' sh "$W2" "$W1" "${PHPRUN[@]}"; r=$?
            [ "$r" = 97 ] && { echo "SKIP wget2-as-wget: bind mount not permitted here"; r=0; }
            [ "$r" = 0 ] || rc=1
        else
            echo "SKIP wget2-as-wget: unshare -m unavailable (needs util-linux + root)"
        fi
    else
        "${PHPRUN[@]}" || rc=1
    fi
    first=0
done
echo
[ "$rc" = 0 ] && echo "ALL FLAVORS PASS" || echo "FAILURES PRESENT (details above; TSV per flavor in $WORK/results-*.tsv)"
exit "$rc"
