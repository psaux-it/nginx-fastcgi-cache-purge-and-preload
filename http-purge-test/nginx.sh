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
  scanfail) USERLINE="user nobody nogroup;";;
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
PALLBLOCK="location = /purge_all { allow 127.0.0.1; deny all; $BIND fastcgi_cache $ALLZONE; $KEYLINE fastcgi_cache_purge GET purge_all from all; }"
[ "$MODE" = v25 ] && PALLBLOCK=""
SINGLEBLOCK="location ~ ^/purge(/.*) { allow 127.0.0.1; deny all; $BIND1 fastcgi_cache_purge NPP $PK; }"
case $MODE in
  denied)    PALLBLOCK="location = /purge_all { allow 127.0.0.1; deny all; fastcgi_cache $ALLZONE; $KEYLINE fastcgi_cache_purge GET purge_all from 10.255.255.1; }"
             SINGLEBLOCK="location ~ ^/purge(/.*) { deny all; fastcgi_cache_purge NPP $PK; }";;
  method405) PALLBLOCK="location = /purge_all { allow 127.0.0.1; deny all; fastcgi_cache $ALLZONE; $KEYLINE fastcgi_cache_purge PURGE purge_all from all; }"
             SINGLEBLOCK="location ~ ^/purge(/.*) { allow 127.0.0.1; deny all; fastcgi_cache NPP; fastcgi_cache_key \"$SKG\"; fastcgi_cache_purge PURGE from all; }";;
  nocache)   PALLBLOCK="location = /purge_all { allow 127.0.0.1; deny all; fastcgi_cache off; $KEYLINE fastcgi_cache_purge GET purge_all from all; }";;
  wrongep)   SINGLEBLOCK="location ~ ^/purge(/.*) { allow 127.0.0.1; deny all; cache_purge_queue_status /tmp/npp-cache; }";;
  keymismatch) PALLBLOCK="location = /purge_all { allow 127.0.0.1; deny all; $BIND fastcgi_cache $ALLZONE; fastcgi_cache_key \"x\$cache_purge_key\"; fastcgi_cache_purge GET purge_all from all; }";;
esac
[ "$STATUS" = yes ] && ST='location = /purge_status { allow 127.0.0.1; deny all; cache_purge_queue_status /tmp/npp-cache; }'
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
    location / {
      $BIND
      fastcgi_pass unix:/tmp/fpm.sock;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $HERE/site/index.php;
      $KEYLINE
      fastcgi_cache NPP;
      fastcgi_cache_valid 200 30d;
      add_header X-Cache \$upstream_cache_status;
    }
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
rm -rf /tmp/npp-cache/* /tmp/npp-other/*; rm -f $NG/conf/state/*
# scanfail runs the workers as nobody: its temp dirs (left over from root runs) must be writable
# ... and so must the cache dirs, or the worker cannot even write the cache (warm() then caches nothing)
if [ "$MODE" = scanfail ]; then chown -R nobody:nogroup $NG/*_temp /tmp/npp-cache /tmp/npp-other 2>/dev/null; else chown -R root:root $NG/*_temp /tmp/npp-cache /tmp/npp-other 2>/dev/null; fi
$NG/sbin/nginx -c $NG/conf/nginx.conf && sleep 1 && echo "nginx up: $MODE ($NG)"
