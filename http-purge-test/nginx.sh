#!/bin/bash
# usage: NG=/prefix ./nginx.sh MODE
# MODE: sync | ns_slow | async | async_slow | async_nostatus | ns | none | wrongzone | single202 | legacy_off
#       | denied | method405 | nocache | wrongep | keymismatch | vary | vary_off      (v4 error-code / Vary scenarios)
#   async       background queue; walk of 3000 files ~4 s
#   async_slow  background queue; walk of 3000 files ~34 s (longer than the plugin waits)
#   denied      purge_all: module ACL "from" excludes the client (403 access_denied); single: nginx deny (bare 403)
#   method405   purge locations configured for method PURGE only (GET -> 405 method_not_allowed + Allow: PURGE)
#   nocache     purge_all location without a cache zone (500 cache_unavailable)
#   wrongep     single purge location bound to cache_purge_queue_status (200 status)
#   keymismatch v4 namespace mode, purge_all location whose key does not contain $cache_purge_key (500 key_mismatch)
#   vary        cache_purge_vary_aware on; vary_off = same site, directive off (Vary variants kept)
#   inline*     the INLINE setup: "fastcgi_cache_purge PURGE from 127.0.0.1;" in the cached PHP location, no dedicated
#               single purge location (Purge All keeps its own "... PURGE purge_all" location). Variants:
#     inline         cache key WITHOUT $request_method, every path answers 200 (site/)
#     inline_wp      same, WordPress-like upstream: the purge prefix answers 404, and nginx caches that 404 (site-wp/)
#     inline_try     inline purge in a named location reached through try_files (the classic WordPress layout)
#     inline_denied  inline purge whose "from" list excludes the client (403)
#     inline_rm      cache key WITH $request_method: a PURGE request can never build the key of the GET-cached page
#     inline_map     key built from a map that turns PURGE into GET: inline purge works with a method-keyed cache
#   noloc       no purge location at all (WordPress-like 404 upstream)
HERE=$(cd "$(dirname "$0")" && pwd)
NGROOT=${NGROOT:-$HERE/ng}; V4=$NGROOT/nginx-run   # v4 build; v3.0 = ${V4}303, v2.5 = ${V4}256
NG=${NG:-$V4}
PHP_FPM=${PHP_FPM:-$(command -v php-fpm8.3 || command -v php-fpm)}
MODE=${1:-sync}
BG=""; NS=""; RT="json"; STATUS="yes"; EXTRA=""; USERLINE="user root;"
case $MODE in
  async) BG="cache_purge_background_queue on; cache_purge_throttle_ms 800ms; cache_purge_batch_size 1;";;
  async_slow) BG="cache_purge_background_queue on; cache_purge_throttle_ms 4000ms; cache_purge_batch_size 1;";;
  async_nostatus) BG="cache_purge_background_queue on; cache_purge_throttle_ms 500ms; cache_purge_batch_size 1;"; STATUS="no";;
  ns) NS="cache_purge_namespace objects 1m $NG/conf/state;";;
  ns_slow) NS="cache_purge_namespace objects 1m $NG/conf/state;"; BG="cache_purge_background_queue on; cache_purge_throttle_ms 800ms; cache_purge_batch_size 1;";;
  none) RT="none";;
  legacy_off) EXTRA="cache_purge_legacy_status off;";;
  scanfail) USERLINE="user nobody nogroup;"; EXTRA="fastcgi_temp_path /tmp/npp-temp;";;
  vary) EXTRA="cache_purge_vary_aware on;";;
  keymismatch) NS="cache_purge_namespace objects 1m $NG/conf/state;";;
esac
[ "$NG" != "$V4" ] && STATUS="no"
KEYEXPR='$scheme$request_method$host$request_uri'
SK='$scheme$request_method$host$1$is_args$args'
SKG='${scheme}GET${host}$1$is_args$args'   # method405: a PURGE request must still build the key of the GET-cached page
KEYLINE="fastcgi_cache_key \"$KEYEXPR\";"; BIND=""; BIND1=""; PK="\"$SK\""
if [ "$MODE" = ns ] || [ "$MODE" = ns_slow ] || [ "$MODE" = keymismatch ]; then
  BIND="cache_purge_key objects \"$KEYEXPR\";"; KEYLINE='fastcgi_cache_key $cache_purge_key;'
  BIND1="cache_purge_key objects \"$SK\";"; PK='"$cache_purge_key"'
fi
ALLZONE=NPP; ALLCACHE_KEY="$KEYLINE"
[ "$MODE" = wrongzone ] && ALLZONE=OTHER
[ "$MODE" = single202 ] && PK='"$scheme$request_method$host$1*"'
ST=""
RTLINE="cache_purge_response_type $RT;"; [ "$MODE" = v25 ] && RTLINE=""
PALLBLOCK="location = /npp_purge_all { allow 127.0.0.1; deny all; $BIND fastcgi_cache $ALLZONE; $KEYLINE fastcgi_cache_purge GET purge_all from all; }"
[ "$MODE" = v25 ] && PALLBLOCK=""
SINGLEBLOCK="location ~ ^/npp_purge_single(/.*) { allow 127.0.0.1; deny all; $BIND1 fastcgi_cache_purge NPP $PK; }"
case $MODE in
  denied)    PALLBLOCK="location = /npp_purge_all { allow 127.0.0.1; deny all; fastcgi_cache $ALLZONE; $KEYLINE fastcgi_cache_purge GET purge_all from 10.255.255.1; }"
             SINGLEBLOCK="location ~ ^/npp_purge_single(/.*) { deny all; fastcgi_cache_purge NPP $PK; }";;
  method405) PALLBLOCK="location = /npp_purge_all { allow 127.0.0.1; deny all; fastcgi_cache $ALLZONE; $KEYLINE fastcgi_cache_purge PURGE purge_all from all; }"
             SINGLEBLOCK="location ~ ^/npp_purge_single(/.*) { allow 127.0.0.1; deny all; fastcgi_cache NPP; fastcgi_cache_key \"$SKG\"; fastcgi_cache_purge PURGE from all; }";;
  nocache)   PALLBLOCK="location = /npp_purge_all { allow 127.0.0.1; deny all; fastcgi_cache off; $KEYLINE fastcgi_cache_purge GET purge_all from all; }";;
  wrongep)   SINGLEBLOCK="location ~ ^/npp_purge_single(/.*) { allow 127.0.0.1; deny all; cache_purge_queue_status /tmp/npp-cache; }";;
  keymismatch) PALLBLOCK="location = /npp_purge_all { allow 127.0.0.1; deny all; $BIND fastcgi_cache $ALLZONE; fastcgi_cache_key \"x\$cache_purge_key\"; fastcgi_cache_purge GET purge_all from all; }";;
esac
[ "$STATUS" = yes ] && ST='location = /purge_status { allow 127.0.0.1; deny all; cache_purge_queue_status /tmp/npp-cache; }'
# --- inline / noloc family: no dedicated single purge location -------------------------------------------------------
SITEDIR=site; FCV="fastcgi_cache_valid 200 30d;"; INLPURGE=""; INLINE_FROM="127.0.0.1"; INLINE_KEY='$scheme$host$request_uri'
case $MODE in
  inline|inline_wp|inline_try|inline_denied|inline_rm|inline_map)
    [ "$MODE" = inline_rm ]     && INLINE_KEY='$scheme$request_method$host$request_uri'
    [ "$MODE" = inline_map ]    && { INLINE_KEY='$scheme$npp_key_method$host$request_uri'; EXTRA="$EXTRA map \$request_method \$npp_key_method { PURGE GET; default \$request_method; }"; }
    [ "$MODE" = inline_denied ] && INLINE_FROM="10.255.255.1"
    [ "$MODE" = inline_wp ]     && { SITEDIR=site-wp; FCV="fastcgi_cache_valid 200 30d; fastcgi_cache_valid 404 1m;"; }
    KEYLINE="fastcgi_cache_key \"$INLINE_KEY\";"
    INLPURGE="fastcgi_cache_purge PURGE from $INLINE_FROM;"
    SINGLEBLOCK=""
    PALLBLOCK="location = /npp_purge_all { allow 127.0.0.1; deny all; fastcgi_cache NPP; $KEYLINE fastcgi_cache_purge PURGE purge_all from all; }"
    ;;
  noloc) SITEDIR=site-wp; SINGLEBLOCK=""; PALLBLOCK="";;
esac
# a v2.5 build has neither cache_purge_response_type nor purge_all
case "$NG" in *256) RTLINE=""; PALLBLOCK="";; esac
PHPBODY="$BIND fastcgi_pass unix:/tmp/fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $HERE/$SITEDIR/index.php; $KEYLINE fastcgi_cache NPP; $FCV $INLPURGE add_header X-Cache \$upstream_cache_status;"
MAINLOC="location / { $PHPBODY }"
[ "$MODE" = inline_try ] && MAINLOC="location / { try_files \$uri @php; } location @php { $PHPBODY }"
THR=""; [ "$NG" = "$V4" ] && THR="thread_pool default threads=2;"
[ "$MODE" = single202 ] && BG="cache_purge_background_queue on; cache_purge_throttle_ms 50ms;"
mkdir -p $NG/conf/state $NG/logs
cat > $NG/conf/nginx.conf <<CONF
$USERLINE
worker_processes 2;
error_log $NG/logs/error.log info;
pid $NG/logs/nginx.pid;
$THR
events { worker_connections 256; }
http {
  access_log off;
  include mime.types;
  fastcgi_cache_path /tmp/npp-cache levels=1:2 keys_zone=NPP:10m max_size=1g inactive=30d;
  fastcgi_cache_path /tmp/npp-other levels=1:2 keys_zone=OTHER:10m max_size=1g inactive=30d;
  $BG
  $NS
  $EXTRA
  $RTLINE
  server {
    listen 127.0.0.1:8081;
    server_name example.com;
    root $HERE/site;
    $MAINLOC
    $SINGLEBLOCK
    $PALLBLOCK
    $ST
  }
}
CONF
$NG/sbin/nginx -t -c $NG/conf/nginx.conf 2>&1 | tail -1
[ -f /tmp/fpm.pid ] && kill -0 $(cat /tmp/fpm.pid) 2>/dev/null || $PHP_FPM -y $HERE/fpm.conf --allow-to-run-as-root
for pf in $V4/logs/nginx.pid ${V4}303/logs/nginx.pid ${V4}256/logs/nginx.pid; do
  [ -f $pf ] && kill -0 $(cat $pf) 2>/dev/null && kill $(cat $pf) 2>/dev/null; done; sleep 1
rm -rf /tmp/npp-cache/* /tmp/npp-other/* /tmp/npp-temp; rm -f $NG/conf/state/*
# scanfail runs the workers as nobody: its temp dirs (left over from root runs) must be writable
# ... and so must the cache dirs, or the worker cannot even write the cache (warm() then caches nothing)
if [ "$MODE" = scanfail ]; then chown -R nobody:nogroup $NG/*_temp /tmp/npp-cache /tmp/npp-other 2>/dev/null; else chown -R root:root $NG/*_temp /tmp/npp-cache /tmp/npp-other 2>/dev/null; fi
$NG/sbin/nginx -c $NG/conf/nginx.conf && sleep 1 && echo "nginx up: $MODE ($NG)"
