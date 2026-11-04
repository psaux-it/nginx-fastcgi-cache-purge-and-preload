#!/bin/sh
# Entry point of the Cloudflare sync harness (cloudflare-test/).
#
#   run-cloudflare-tests.sh                 everything
#   run-cloudflare-tests.sh --fast          skip the 10 s budget test
#   run-cloudflare-tests.sh --no-real       skip the layers that need the official plugin source
#
# Layers (each section runs in its own PHP process: the plugin keeps per-request statics):
#   unit      tests/unit.php          the real compat-cloudflare.php + state.php on a WordPress stub, scripted HTTP
#   detect    tests/detect.php        namespace detection: new | old | both | none
#   unsupported tests/unsupported.php a hollow / future plugin layout yields "unsupported", never a fatal
#   budget    tests/budget.php        8 s request / 20 s cron time budget (slow API)
#   contract  tests/contract.php      static: PHP 7.4 syntax, forbidden behaviour, kept surface, wiring in the plugin
#   real-cf   tests/real-cf.php       the REAL official plugin source (CF_SRC=dir of 4.14.5; CF_SRC_OLD=dir of a
#                                     4.12.8 / 4.13.x checkout, run with CF_LAYOUT=psr4)
# Environment: PHP (default php), CF_SRC, CF_SRC_OLD
HERE=$(cd "$(dirname "$0")" && pwd) || exit 2
PHP=${PHP:-php}; FAST=0; REAL=1
for a in "$@"; do case "$a" in --fast) FAST=1;; --no-real) REAL=0;; esac; done
rc=0
run() { name=$1; shift; out=$("$@" 2>&1); r=$?; echo "$out" | tail -1 | sed "s/^/  $name: /"; if [ $r -ne 0 ]; then echo "$out" | grep FAIL; rc=1; fi; }
run unit         $PHP "$HERE/tests/unit.php"
for m in new old both none; do run "detect:$m" $PHP "$HERE/tests/detect.php" $m; done
run unsupported  $PHP "$HERE/tests/unsupported.php"
run contract     $PHP "$HERE/tests/contract.php"
[ $FAST -eq 0 ] && run budget $PHP "$HERE/tests/budget.php"
if [ $REAL -eq 1 ]; then
  if [ -n "$CF_SRC" ] && [ -d "$CF_SRC" ]; then run real-cf:4.14.x env CF_SRC="$CF_SRC" $PHP "$HERE/tests/real-cf.php"; else echo "  real-cf:4.14.x: SKIP (set CF_SRC)"; fi
  if [ -n "$CF_SRC_OLD" ] && [ -d "$CF_SRC_OLD" ]; then run real-cf:old env CF_SRC="$CF_SRC_OLD" CF_LAYOUT=psr4 $PHP "$HERE/tests/real-cf.php"; else echo "  real-cf:old: SKIP (set CF_SRC_OLD)"; fi
fi
[ $rc -eq 0 ] && echo "ALL GREEN" || echo "FAILED"; exit $rc
