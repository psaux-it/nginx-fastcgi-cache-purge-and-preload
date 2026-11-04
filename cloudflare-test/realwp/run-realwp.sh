#!/bin/sh
# Real-WordPress layer of the Cloudflare harness. Needs (see README.md): a WordPress install with a database, the
# official Cloudflare plugin unpacked in wp-content/plugins/cloudflare and active, wp-cli, python3, openssl, root
# (port 443 + /etc/hosts for api.cloudflare.com -> 127.0.0.1).
#
#   WP_DIR=/path/to/wordpress  run-realwp.sh
HERE=$(cd "$(dirname "$0")" && pwd) || exit 2
: "${WP_DIR:?set WP_DIR to the WordPress root}"
export NPPP_ROOT=${NPPP_ROOT:-$(cd "$HERE/../.." && pwd)}
CERTS=${CERTS:-/tmp/nppp-cf-certs}
[ -f "$CERTS/api.pem" ] || sh "$HERE/make-certs.sh" "$CERTS" >/dev/null || exit 2
grep -q 'api.cloudflare.com' /etc/hosts || { echo "127.0.0.1 api.cloudflare.com" >> /etc/hosts; ADDED=1; }
mkdir -p "$WP_DIR/wp-content/mu-plugins" && cp "$HERE/mu-plugins/nppp-cf-harness.php" "$WP_DIR/wp-content/mu-plugins/"
export NPPP_H_CA="$CERTS/ca.pem"
python3 "$HERE/mock-cf.py" "$CERTS/api.pem" "$CERTS/api.key" >/tmp/nppp-mock-cf.log 2>&1 &
MOCK=$!
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -m 1 http://127.0.0.1:18080/log >/dev/null && break; sleep 0.5; done
( cd "$WP_DIR" && wp eval-file "$HERE/scenarios.php" --allow-root "$@" ); rc=$?
kill $MOCK 2>/dev/null
[ -n "$ADDED" ] && sed -i '/api.cloudflare.com/d' /etc/hosts
exit $rc
