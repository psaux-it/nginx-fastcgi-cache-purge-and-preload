#!/bin/bash
# usage: build-nginx.sh <ngx_cache_purge branch> <install prefix>
#   build-nginx.sh releases/stable-4.0 $NGROOT/nginx-run      (v4, needs --with-threads)
#   build-nginx.sh releases/stable-3.0 $NGROOT/nginx-run303   (v3.0)
#   build-nginx.sh releases/stable-2.5 $NGROOT/nginx-run256   (v2.5)
# NGROOT (default ./ng next to this script) only moves the default build dir; BUILD overrides it.
# Needs: git build-essential libpcre2-dev zlib1g-dev libssl-dev
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
BR=$1; PFX=$2; TAG=${BR//\//_}; NGROOT=${NGROOT:-$HERE/ng}; B=${BUILD:-$NGROOT/build}
mkdir -p "$B"; cd "$B"
[ -d nginx-src ] || git clone -q --depth 1 --branch release-1.31.6 https://github.com/nginx/nginx.git nginx-src
[ -d mod-$TAG ] || git clone -q --depth 1 --branch "$BR" https://github.com/nginx-modules/ngx_cache_purge.git mod-$TAG
cd nginx-src
THREADS=""; [ "$BR" = releases/stable-4.0 ] && THREADS="--with-threads"
make clean >/dev/null 2>&1 || true
./auto/configure --prefix="$PFX" $THREADS --add-module="$B/mod-$TAG" > ../conf-$TAG.log 2>&1
make -j"$(nproc)" > ../make-$TAG.log 2>&1
make install > /dev/null 2>&1
echo "DONE $BR $(git -C "$B/mod-$TAG" rev-parse --short HEAD)"
