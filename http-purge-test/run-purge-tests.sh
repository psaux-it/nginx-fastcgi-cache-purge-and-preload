#!/usr/bin/env bash
# run-purge-tests.sh - run the purge-http.php harness (the counterpart of fail2ban-test/run-e2e.sh).
#
#   ./run-purge-tests.sh                          mock harness + real-nginx matrix (v4 build)
#   ./run-purge-tests.sh --only mock              mock harness only (php CLI, no nginx)
#   ./run-purge-tests.sh --only matrix [SC ...]   real nginx only, optionally just the named scenarios
#   ./run-purge-tests.sh --build                  build the nginx variant first if it is missing (see below)
#   ./run-purge-tests.sh --ng v30 --only matrix sync ambig     other ngx_cache_purge build (v4 | v30 | v25)
#
# Scenarios (matrix): sync ambig ns none wrongzone single202 legacy_off race_timeout race_invalidated race_invalidated_single
#                     race race_nostatus race_off
#                     v4 error codes / Vary: denied method405 nocache keymismatch wrongep deletefail scanfail vary vary_off badsel
#
# Environment:
#   PLUGIN_FILE  purge-http.php under test
#                (default: the plugin deployed in the Docker stack, else ../includes/purge-http.php)
#   NGROOT       where the nginx builds live (default: ./ng next to this script)
#   BUILD        build/source cache of build-nginx.sh (default: ./build)
#   DELAY        seconds the delay proxy holds answers in race_timeout (default 3)
#
# The ruleset itself is run.php (mock) and e2e.php (real nginx); this wrapper only wires them.
# Real nginx needs: git build-essential libpcre2-dev zlib1g-dev libssl-dev python3 curl and php-fpm
# (the Docker stack's actionstart-purge.sh installs them inside wordpress-fpm).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export NGROOT="${NGROOT:-$HERE/ng}"
export BUILD="${BUILD:-$HERE/build}"
export XDEBUG_MODE=off                       # the wordpress image ships xdebug; it only slows the run

DEPLOYED=/var/www/html/wp-content/plugins/fastcgi-cache-purge-and-preload-nginx/includes/purge-http.php
if [ -z "${PLUGIN_FILE:-}" ];                       then
    if   [ -f "$DEPLOYED" ];                        then PLUGIN_FILE="$DEPLOYED"
    elif [ -f "$HERE/../includes/purge-http.php" ]; then PLUGIN_FILE="$HERE/../includes/purge-http.php"
    else echo "purge-http.php not found - set PLUGIN_FILE=/path/to/purge-http.php" >&2; exit 2; fi
fi
PLUGIN_FILE="$(readlink -f "$PLUGIN_FILE")"
[ -f "$PLUGIN_FILE" ] || { echo "PLUGIN_FILE not found: $PLUGIN_FILE" >&2; exit 2; }

ONLY=all; NGV=v4; DO_BUILD=0; SC=()
while [ $# -gt 0 ]; do
    case "$1" in
        --only)  ONLY="${2:?--only mock|matrix}"; shift ;;
        --ng)    NGV="${2:?--ng v4|v30|v25}"; shift ;;
        --build) DO_BUILD=1 ;;
        -h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d;s/^# \{0,1\}//'; exit 0 ;;
        *)       SC+=("$1") ;;
    esac
    shift
done
case "$ONLY" in all|mock|matrix) ;; *) echo "--only must be mock or matrix" >&2; exit 2 ;; esac

case "$NGV" in
    v4)  BRANCH=releases/stable-4.0; PFX="$NGROOT/nginx-run";    E2E_EXTRA="" ;;
    v30) BRANCH=releases/stable-3.0; PFX="$NGROOT/nginx-run303"; E2E_EXTRA="v30" ;;
    v25) BRANCH=releases/stable-2.5; PFX="$NGROOT/nginx-run256"; E2E_EXTRA="" ;;
    *)   echo "--ng must be v4, v30 or v25" >&2; exit 2 ;;
esac
# Scenarios other than the v4 ones depend on module features; pick them explicitly for v30 / v25.
[ ${#SC[@]} -eq 0 ] && [ "$NGV" = v30 ] && SC=(sync ambig)
[ ${#SC[@]} -eq 0 ] && [ "$NGV" = v25 ] && SC=(v25)

echo "purge-http.php : $PLUGIN_FILE"
RC=0

if [ "$ONLY" = all ] || [ "$ONLY" = mock ]; then
    echo; echo "== mock harness (run.php)"
    php "$HERE/run.php" "$PLUGIN_FILE" | tail -n "${MOCK_TAIL:-25}" || RC=1   # pipefail: run.php's exit status counts
fi

if [ "$ONLY" = all ] || [ "$ONLY" = matrix ]; then
    echo; echo "== real nginx matrix ($NGV: $PFX)"
    if [ ! -x "$PFX/sbin/nginx" ]; then
        if [ "$DO_BUILD" -eq 1 ]; then
            "$HERE/build-nginx.sh" "$BRANCH" "$PFX"
        else
            echo "nginx build missing: $PFX/sbin/nginx - run with --build (or build-nginx.sh $BRANCH $PFX)" >&2
            exit 2
        fi
    fi
    NG="$PFX" E2E_EXTRA="$E2E_EXTRA" "$HERE/matrix.sh" "$PLUGIN_FILE" ${SC[@]+"${SC[@]}"} || RC=1
fi

echo
if [ "$RC" -eq 0 ]; then echo "ALL PURGE-HTTP CHECKS PASSED"; else echo "PURGE-HTTP CHECKS FAILED"; fi
exit "$RC"
