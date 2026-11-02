#!/bin/sh
# Entry point of the Varnish / Vinyl Cache sync harness (varnish-test/).
#
#   run-varnish-tests.sh                       everything: unit + sanitize + e2e
#   run-varnish-tests.sh --only unit sanitize  layers only (unit | sanitize | e2e | deferred)
#   run-varnish-tests.sh --only unit:parse e2e:single deferred:holdgate   single sections (layer:section)
#   run-varnish-tests.sh --no-e2e              skip the layers that need a real varnishd (e2e, deferred)
#   run-varnish-tests.sh --strict              a skipped section (varnishd missing) counts as a failure
#   run-varnish-tests.sh -v                    print the output of passing sections too
#   run-varnish-tests.sh --list                list the sections and exit
#
# Layers:
#   unit      tests/unit.php      hook wiring and helpers on a scripted transport (no Varnish, no root)
#   sanitize  tests/sanitize.php  Settings save path for the Varnish fields (no Varnish, no root)
#   e2e       tests/e2e.php       real varnishd + counting backend (needs varnishd, python3, cc, php-curl)
#   deferred  tests/deferred.php  the real Purge All call chain: status 7, crawl hold, deferred preload (same tools as e2e)
#
# Every section runs in its own PHP process: the plugin keeps per-request statics (endpoint circuit-breaker memo,
# "Purge All runs once" guard) that must not leak from one section into the next.
#
# Environment: PHP (default php), NPPP_INCLUDES (plugin includes/ directory, default ../includes),
#              VT_KEEP_LAB=1 (keep the e2e work directory with varnishd logs and VCL), VT_LAB (work directory).
#
# Exit: 0 all passed, 1 a section failed, 2 usage / lab error.

HERE=$(cd "$(dirname "$0")" && pwd) || exit 2
PHP=${PHP:-php}

UNIT_SECTIONS="parse config classify single results budget all preload allveto allfail sslverify hosts dedupe scope exceptions logging state logreason fallback transport hang source varnishonly varnishonly2 varnishonly3 varnishonly4 varnishonly5 varnishonly6 varnishonly7"
E2E_SECTIONS="vcl single purgeall preload escalate scope auth noregex multi tls lurker"
DEFERRED_SECTIONS="sync gen async deferred waiterdead preloadall supersede holdgate"

ONLY=""; STRICT=0; VERBOSE=0; NO_E2E=0

usage() { sed -n '2,/^# Exit:/p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
    case "$1" in
        --only)    shift
                   while [ $# -gt 0 ] && [ "${1#-}" = "$1" ]; do ONLY="$ONLY $1"; shift; done
                   continue ;;
        --no-e2e)  NO_E2E=1 ;;
        --strict)  STRICT=1 ;;
        -v|--verbose) VERBOSE=1 ;;
        --list)    echo "unit:     $UNIT_SECTIONS"; echo "sanitize: (single run)"; echo "e2e:      $E2E_SECTIONS"; echo "deferred: $DEFERRED_SECTIONS"; exit 0 ;;
        -h|--help) usage; exit 0 ;;
        *)         echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

command -v "$PHP" >/dev/null 2>&1 || { echo "PHP CLI not found (set PHP=/path/to/php)" >&2; exit 2; }
"$PHP" -r 'exit( PHP_VERSION_ID >= 70400 ? 0 : 1 );' || { echo "PHP 7.4 or newer is required" >&2; exit 2; }

if [ ! -f "${NPPP_INCLUDES:-$HERE/../includes}/compat-varnish.php" ]; then
    echo "compat-varnish.php not found in '${NPPP_INCLUDES:-$HERE/../includes}' - run from a v2.1.9 checkout or set NPPP_INCLUDES" >&2
    exit 2
fi

# Which (layer, section) pairs to run
PLAN=""
add_layer() {
    case "$1" in
        unit)     for s in $UNIT_SECTIONS; do PLAN="$PLAN unit:$s"; done ;;
        sanitize) PLAN="$PLAN sanitize:-" ;;
        e2e)      [ "$NO_E2E" = 1 ] || for s in $E2E_SECTIONS; do PLAN="$PLAN e2e:$s"; done ;;
        deferred) [ "$NO_E2E" = 1 ] || for s in $DEFERRED_SECTIONS; do PLAN="$PLAN deferred:$s"; done ;;
        *)        echo "unknown layer '$1' (unit | sanitize | e2e | deferred)" >&2; exit 2 ;;
    esac
}
if [ -z "$ONLY" ]; then
    add_layer unit; add_layer sanitize; add_layer e2e; add_layer deferred
else
    for item in $ONLY; do
        case "$item" in
            *:*) layer=${item%%:*}; sect=${item#*:}
                 case "$layer" in
                     unit) case " $UNIT_SECTIONS " in *" $sect "*) PLAN="$PLAN $item" ;; *) echo "unknown unit section '$sect'" >&2; exit 2 ;; esac ;;
                     e2e)  case " $E2E_SECTIONS "  in *" $sect "*) PLAN="$PLAN $item" ;; *) echo "unknown e2e section '$sect'"  >&2; exit 2 ;; esac ;;
                     deferred) case " $DEFERRED_SECTIONS " in *" $sect "*) PLAN="$PLAN $item" ;; *) echo "unknown deferred section '$sect'" >&2; exit 2 ;; esac ;;
                     *)    echo "only unit, e2e and deferred have sections: '$item'" >&2; exit 2 ;;
                 esac ;;
            *)   add_layer "$item" ;;
        esac
    done
fi
[ -n "$PLAN" ] || { echo "nothing to run" >&2; exit 2; }

TMP=$(mktemp -d "${TMPDIR:-/tmp}/npp-vt-run.XXXXXX") || exit 2
trap 'rm -rf "$TMP"; exit 130' INT TERM
trap 'rm -rf "$TMP"' EXIT

PASS=0; FAIL=0; KNOWN=0; SKIPPED=0; BAD=""; SKIPS=""; LABERR=0; N=0
START=$(date +%s)

run_one() {   # $1 = layer:section
    layer=${1%%:*}; sect=${1#*:}
    case "$layer" in
        unit)     file=tests/unit.php;     arg=$sect ;;
        sanitize) file=tests/sanitize.php; arg="" ;;
        e2e)      file=tests/e2e.php;      arg=$sect ;;
        deferred) file=tests/deferred.php; arg=$sect ;;
    esac
    label="$layer"; [ -n "$arg" ] && label="$layer:$arg"
    out="$TMP/$N.out"; N=$((N + 1))

    t0=$(date +%s)
    # shellcheck disable=SC2086
    ( cd "$HERE" && "$PHP" "$file" $arg ) >"$out" 2>&1
    rc=$?
    dt=$(( $(date +%s) - t0 ))

    # last line: "N passed, M failed, K known-limit documented, S skipped"
    sum=$(grep -E '^[0-9]+ passed, [0-9]+ failed' "$out" | tail -n 1)
    if [ -n "$sum" ]; then
        p=$(echo "$sum" | awk '{print $1}');  f=$(echo "$sum" | awk '{print $3}')
        k=$(echo "$sum" | awk '{print $5}');  s=$(echo "$sum" | awk '{print $8}')
        PASS=$((PASS + p)); FAIL=$((FAIL + f)); KNOWN=$((KNOWN + k)); SKIPPED=$((SKIPPED + s))
    fi

    case $rc in
        0)  printf '  PASS  %-20s %s (%ss)\n' "$label" "${sum:-no summary line}" "$dt"
            [ "$VERBOSE" = 1 ] && sed 's/^/        /' "$out" ;;
        77) printf '  SKIP  %-20s %s\n' "$label" "$(head -n 1 "$out")"
            SKIPS="$SKIPS $label" ;;
        2)  printf '  ERROR %-20s lab / usage error (rc 2)\n' "$label"
            sed 's/^/        /' "$out"; BAD="$BAD $label"; LABERR=1 ;;
        *)  printf '  FAIL  %-20s %s (rc %s)\n' "$label" "${sum:-no summary line}" "$rc"
            sed 's/^/        /' "$out"; BAD="$BAD $label"
            [ -z "$sum" ] && FAIL=$((FAIL + 1)) ;;
    esac
}

echo "Varnish / Vinyl Cache sync harness  (PHP $("$PHP" -r 'echo PHP_VERSION;'))"
for item in $PLAN; do run_one "$item"; done

echo
printf '%d passed, %d failed, %d known-limit documented, %d skipped assertions' "$PASS" "$FAIL" "$KNOWN" "$SKIPPED"
printf '  [%d sections, %ds]\n' "$N" "$(( $(date +%s) - START ))"
[ -n "$SKIPS" ] && echo "skipped sections (missing varnishd / python3 / cc / php-curl):$SKIPS"
[ -n "$BAD" ]   && echo "failed sections:$BAD"

if [ -n "$BAD" ]; then
    [ "$LABERR" = 1 ] && [ "$FAIL" -eq 0 ] && exit 2
    exit 1
fi
if [ -n "$SKIPS" ] && [ "$STRICT" = 1 ]; then
    echo "--strict: skipped sections count as failure" >&2
    exit 1
fi
exit 0
