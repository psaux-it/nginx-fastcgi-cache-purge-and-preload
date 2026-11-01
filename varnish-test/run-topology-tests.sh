#!/bin/sh
# Entry point of the Varnish / Vinyl Cache TOPOLOGY layer (tests/topology.php, helpers in lib/topo.php).
#
#   visitor -> nginx:443 (edge) -> varnish:80 -> nginx:8080 (fastcgi_cache) -> wordpress-fpm
#
# It runs INSIDE the wordpress-fpm container as root and drives the REAL plugin with WP-CLI against the REAL containers.
# Do not start it by hand: ./actionstart-varnish.sh --topology (stack repo) brings the stack up, installs the VCL,
# points NPP at the Varnish container and passes the TOPO_* environment (see lib/topo.php).
#
#   run-topology-tests.sh                    phases happy + faults
#   run-topology-tests.sh happy              one phase (happy | faults | preload)
#   run-topology-tests.sh happy faults preload
#   run-topology-tests.sh all                every phase, preload included
#   run-topology-tests.sh single badkey      single sections
#   run-topology-tests.sh -v                 print the output of passing sections too
#   run-topology-tests.sh --strict           a skipped section counts as a failure
#   run-topology-tests.sh --list             list phases and sections and exit
#
# Phases:
#   happy    wiring single purgeall outsider                                    the wiring works, a visitor can not purge
#   faults   vclclass foreignhost badkey dns refused blackhole                  what NPP does when the Varnish side is wrong
#   preload  preload                                                            opt-in: slow, needs wget and the mitmproxy container
#
# Every section runs in its own PHP process and restores every NPP setting it changed (also after a failed assertion).
# A section never stops the run; only a lab error (exit 2: edge unreachable, WP-CLI broken) does, because every
# later section would fail for the same reason.
#
# Environment: PHP (default php), TOPO_* and WP_PATH / WP_USER (set by actionstart-varnish.sh).
#
# Exit: 0 all passed, 1 a section failed, 2 usage / lab error, 77 nothing ran (every section skipped).

HERE=$(cd "$(dirname "$0")" && pwd) || exit 2
PHP=${PHP:-php}

HAPPY="wiring single purgeall outsider"
FAULTS="vclclass foreignhost badkey dns refused blackhole"
PRELOAD="preload"
ALL_SECTIONS="$HAPPY $FAULTS $PRELOAD"

STRICT=0; VERBOSE=0; WANT=""

usage() { sed -n '2,/^# Exit:/p' "$0" | sed 's/^# \{0,1\}//'; }

phase_of() {
    case " $HAPPY "   in *" $1 "*) echo happy;   return ;; esac
    case " $FAULTS "  in *" $1 "*) echo faults;  return ;; esac
    echo preload
}

while [ $# -gt 0 ]; do
    case "$1" in
        --strict)     STRICT=1 ;;
        -v|--verbose) VERBOSE=1 ;;
        --list)       echo "happy:   $HAPPY"; echo "faults:  $FAULTS"; echo "preload: $PRELOAD   (opt-in)"; exit 0 ;;
        -h|--help)    usage; exit 0 ;;
        happy)        WANT="$WANT $HAPPY" ;;
        faults)       WANT="$WANT $FAULTS" ;;
        preload)      WANT="$WANT $PRELOAD" ;;
        all)          WANT="$WANT $ALL_SECTIONS" ;;
        -*)           echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
        *)            case " $ALL_SECTIONS " in
                          *" $1 "*) WANT="$WANT $1" ;;
                          *)        echo "unknown phase or section '$1' (see --list)" >&2; exit 2 ;;
                      esac ;;
    esac
    shift
done
[ -n "$WANT" ] || WANT="$HAPPY $FAULTS"

# Plan: the order of ALL_SECTIONS (wiring first, vclclass first of the faults), each section once.
PLAN=""
for s in $ALL_SECTIONS; do
    case " $WANT " in *" $s "*) PLAN="$PLAN $s" ;; esac
done

# ---- preflight: the same conditions tests/topology.php checks, said once instead of once per section ----
command -v "$PHP" >/dev/null 2>&1 || { echo "PHP CLI not found (set PHP=/path/to/php)" >&2; exit 2; }
"$PHP" -r 'exit( PHP_VERSION_ID >= 70400 ? 0 : 1 );' || { echo "PHP 7.4 or newer is required" >&2; exit 2; }
if [ -z "$TOPO_VARNISH" ] || [ -z "$TOPO_KEY" ]; then
    echo "TOPO_VARNISH and TOPO_KEY are not set. Run it through: ./actionstart-varnish.sh --topology" >&2
    exit 2
fi
if ! "$PHP" -r 'exit( extension_loaded( "curl" ) ? 0 : 1 );'; then
    echo "SKIP topology: PHP curl extension not loaded in this container"
    exit 77
fi
[ -f "$HERE/tests/topology.php" ] || { echo "$HERE/tests/topology.php not found" >&2; exit 2; }

TMP=$(mktemp -d "${TMPDIR:-/tmp}/npp-topo-run.XXXXXX") || exit 2
trap 'rm -rf "$TMP"; exit 130' INT TERM
trap 'rm -rf "$TMP"' EXIT

PASS=0; FAIL=0; KNOWN=0; SKIPPED=0; BAD=""; SKIPS=""; LABERR=0; RAN=0; N=0
START=$(date +%s)

run_one() {   # $1 = section
    sect=$1; label="$(phase_of "$sect"):$sect"
    out="$TMP/$N.out"; N=$((N + 1))

    t0=$(date +%s)
    ( cd "$HERE" && "$PHP" tests/topology.php "$sect" ) >"$out" 2>&1
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
        0)  printf '  PASS  %-18s %s (%ss)\n' "$label" "${sum:-no summary line}" "$dt"
            RAN=$((RAN + 1))
            [ "$VERBOSE" = 1 ] && sed 's/^/        /' "$out" ;;
        77) printf '  SKIP  %-18s %s\n' "$label" "$(head -n 1 "$out")"
            SKIPS="$SKIPS $label"
            [ "$VERBOSE" = 1 ] && sed 's/^/        /' "$out" ;;
        2)  printf '  ERROR %-18s lab / usage error (rc 2)\n' "$label"
            sed 's/^/        /' "$out"; BAD="$BAD $label"; LABERR=1 ;;
        *)  printf '  FAIL  %-18s %s (rc %s)\n' "$label" "${sum:-no summary line}" "$rc"
            sed 's/^/        /' "$out"; BAD="$BAD $label"
            RAN=$((RAN + 1))
            [ -z "$sum" ] && FAIL=$((FAIL + 1)) ;;
    esac
}

echo "Varnish / Vinyl Cache topology layer  (PHP $("$PHP" -r 'echo PHP_VERSION;'))"
echo "  varnish ${TOPO_VARNISH}   edge ${TOPO_EDGE:-https://nginx}   origin ${TOPO_ORIGIN:-http://nginx:8080}   vcl ${TOPO_VCL_VARIANT:-auto}   acl ${TOPO_ACL_MODE:-ip}"
for item in $PLAN; do
    run_one "$item"
    if [ "$LABERR" = 1 ]; then echo "  stopping: a lab error makes the remaining sections meaningless"; break; fi
done

echo
printf '%d passed, %d failed, %d known-limit documented, %d skipped assertions' "$PASS" "$FAIL" "$KNOWN" "$SKIPPED"
printf '  [%d sections, %ds]\n' "$N" "$(( $(date +%s) - START ))"
[ -n "$SKIPS" ] && echo "skipped sections:$SKIPS   (a section skips itself when its precondition is missing; the line above says why)"
[ -n "$BAD" ]   && echo "failed sections:$BAD"

if [ -n "$BAD" ]; then
    [ "$LABERR" = 1 ] && [ "$FAIL" -eq 0 ] && exit 2
    exit 1
fi
if [ -n "$SKIPS" ] && [ "$STRICT" = 1 ]; then
    echo "--strict: skipped sections count as failure" >&2
    exit 1
fi
# Green must mean something ran: every section skipped is not a pass.
if [ "$RAN" -eq 0 ]; then
    echo "nothing ran: every section was skipped" >&2
    exit 77
fi
exit 0
