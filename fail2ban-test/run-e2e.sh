#!/usr/bin/env bash
# run-e2e.sh - start the fake RIPEstat (if needed) and run the end-to-end phases.
#   ./run-e2e.sh                      all phases except ratelimit
#   ./run-e2e.sh --only happy,faults
#   ./run-e2e.sh --with-ratelimit
# Environment: WP_PATH (default /var/www/html), SITE_URL (default http://127.0.0.1:8080)
set -euo pipefail
LAB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export WP_PATH="${WP_PATH:-/var/www/html}"
mkdir -p "$LAB_DIR/run"

if ! getent ahosts stat.ripe.net | grep -q '^127\.0\.0\.2'; then
    echo "stat.ripe.net does not resolve to 127.0.0.2 - run: sudo $LAB_DIR/setup-lab.sh $WP_PATH" >&2
    exit 2
fi

PIDF="$LAB_DIR/run/fake_ripestat.pid"
if ! { [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null; }; then
    setsid nohup python3 "$LAB_DIR/fake_ripestat.py" > "$LAB_DIR/run/fake_ripestat.log" 2>&1 < /dev/null &
    echo $! > "$PIDF"
    for _ in $(seq 1 20); do
        curl -s --cacert "$LAB_DIR/pki/ca.crt" -o /dev/null "https://stat.ripe.net/__ctl/stats" && break
        sleep 0.25
    done
fi
exec python3 "$LAB_DIR/e2e.py" "$@"
