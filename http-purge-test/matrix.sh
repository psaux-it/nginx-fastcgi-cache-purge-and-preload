#!/bin/bash
# usage: matrix.sh path/to/purge-http.php [scenarios...]
# Runs the real-nginx scenarios against the v4 build ($NGROOT/nginx-run, NGROOT default ./ng next to this script). Override NG for another build.
# E2E_EXTRA is appended to the e2e.php arguments (e.g. E2E_EXTRA=v30 for a v3.0 build).
# Scenario -> nginx mode. Needs tests/build-nginx.sh to have been run first.
HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(readlink -f "${1:?usage: matrix.sh path/to/purge-http.php [scenarios]}"); shift
NG=${NG:-${NGROOT:-$HERE/ng}/nginx-run}
declare -A MODE=( [sync]=sync [ambig]=sync [ns]=ns [none]=none [wrongzone]=wrongzone [single202]=single202 [legacy_off]=legacy_off [race_timeout]=async [race_invalidated]=ns_slow [race_invalidated_single]=ns_slow [ns_keys]=ns_slow [ns_index]=ns_slow [v25]=v25 [race]=async [race_nostatus]=async_nostatus [race_off]=async [slowq]=async_slow [slowq_marker]=async_slow [deferred_waiter]=async_slow [denied]=denied [method405]=method405 [nocache]=nocache [keymismatch]=keymismatch [wrongep]=wrongep [deletefail]=sync [scanfail]=scanfail [vary]=vary [vary_off]=vary_off [badsel]=vary [inline]=inline [inline_wp]=inline_wp [inline_try]=inline_try [inline_denied]=inline_denied [inline_rm]=inline_rm [inline_map]=inline_map [inline_switch]=inline [noloc]=noloc )
ORDER=(sync ambig ns none wrongzone single202 legacy_off race_timeout race_invalidated race_invalidated_single ns_keys ns_index race race_nostatus race_off slowq slowq_marker deferred_waiter denied method405 nocache keymismatch wrongep deletefail scanfail vary vary_off badsel inline inline_wp inline_try inline_denied inline_rm inline_map inline_switch noloc)
SC=("$@"); [ ${#SC[@]} -eq 0 ] && SC=("${ORDER[@]}")
for p in $(pgrep -f "python3 .*delayproxy"); do kill $p 2>/dev/null; done
(DELAY=${DELAY:-3} setsid nohup python3 "$HERE/delayproxy.py" >/dev/null 2>&1 < /dev/null &)
FAILED=0
for s in "${SC[@]}"; do
  NG=$NG "$HERE/nginx.sh" "${MODE[$s]}" >/dev/null 2>&1
  out=$(timeout 240 php "$HERE/e2e.php" "$s" "$PLUGIN" $E2E_EXTRA 2>&1); rc=$?
  if [ $rc -eq 0 ]; then if echo "$out" | grep -q '^SKIP'; then printf "%-14s SKIP\n" "$s"; else printf "%-14s PASS\n" "$s"; fi; else printf "%-14s FAIL\n" "$s"; echo "$out" | grep FAIL | head -5; FAILED=$((FAILED+1)); fi
  # (removed) if [ $rc -eq 0 ]; then printf "%-14s PASS\n" "$s"; else printf "%-14s FAIL\n" "$s"; echo "$out" | grep FAIL | head -5; FAILED=$((FAILED+1)); fi
done
echo "failed scenarios: $FAILED"
exit $FAILED
