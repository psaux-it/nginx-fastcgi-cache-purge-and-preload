#!/usr/bin/env bash
# safexec/helpers/smoke.sh <package-file>
# Run INSIDE a clean container of the target distro, as root.
set -euo pipefail
PKG="${1:?usage: smoke.sh <package-file>}"
PORT="${SMOKE_PORT:-8899}"
ok()   { echo "SMOKE OK:   $*"; }
fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

# run a command as an unprivileged caller (like PHP-FPM); portable across distros
as_user() { python3 -c 'import os,sys; os.setgroups([]); os.setgid(1000); os.setuid(1000); os.execv(sys.argv[1], sys.argv[1:])' "$@"; }

# 1) install test deps + the package under test
if command -v apt-get >/dev/null; then
  FAMILY=deb; export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq || true; apt-get install -y -qq wget python3 >/dev/null
  apt-get install -y -qq "./$PKG" >/dev/null || fail "deb install failed (missing deps?)"
elif command -v dnf >/dev/null; then
  FAMILY=rpm
  dnf -y -q install wget python3 >/dev/null
  dnf -y -q install "$PKG" >/dev/null
elif command -v apk >/dev/null; then
  FAMILY=apk
  apk add -q wget python3
  apk add -q --allow-untrusted --force-overwrite "$PKG"
else
  fail "no supported package manager"
fi
ok "installed $(basename "$PKG") ($FAMILY, $(uname -m))"

# 2) install-time properties
[[ "$(stat -c '%a %U:%G' /usr/bin/safexec)" == "4755 root:root" ]] || fail "safexec is not 4755 root:root"
ok "safexec is setuid root"
[[ "$(readlink -f /usr/lib/npp/libnpp_norm.so)" == *libnpp_norm.so ]] || fail "shim missing at /usr/lib/npp/libnpp_norm.so"
[[ "$(stat -c '%U:%G' "$(readlink -f /usr/lib/npp/libnpp_norm.so)")" == "root:root" ]] || fail "shim not root:root"
ok "shim present: $(readlink -f /usr/lib/npp/libnpp_norm.so)"

# 3) safexec -v
/usr/bin/safexec -v | grep -q 'NPP-restricted' || fail "safexec -v: not the NPP-restricted build"
ok "safexec -v reports NPP-restricted build"

# 4) allowlist (NPP build): only wget/rg, only 'nohup' wrapper
set +e
as_user /usr/bin/safexec curl -s http://127.0.0.1/  >/dev/null 2>&1; rc1=$?
as_user /usr/bin/safexec nice wget http://127.0.0.1/ >/dev/null 2>&1; rc2=$?
set -e
[[ $rc1 -eq 3 && $rc2 -eq 3 ]] || fail "allowlist not enforced (curl rc=$rc1, nice rc=$rc2; expected 3)"
ok "curl and nice wrapper rejected (exit 3)"

# 5) real wget through safexec: shim must be injected and %2f -> %2F
mkdir -p /tmp/www/a && echo ok > /tmp/www/a/b
( cd /tmp/www && exec python3 -m http.server "$PORT" --bind 127.0.0.1 >/tmp/srv.log 2>&1 ) &
SRV=$!; trap 'kill $SRV 2>/dev/null || true' EXIT
for _ in $(seq 40); do python3 -c "import socket;socket.create_connection(('127.0.0.1',$PORT),1)" 2>/dev/null && break; sleep 0.25; done
cd /
out="$(as_user /usr/bin/safexec wget -q -O /dev/null "http://127.0.0.1:$PORT/a%2fb" 2>&1)" || { echo "$out"; fail "safexec wget failed"; }
grep -q 'Injected: LD_PRELOAD' <<<"$out" || { echo "$out"; fail "shim was not injected"; }
grep -q 'GET /a%2Fb ' /tmp/srv.log      || { cat /tmp/srv.log; fail "request-line not normalized to %2F"; }
ok "wget via safexec: shim injected, request-line normalized (%2f -> %2F)"

# 6) clean removal (no dangling shim symlink)
case "$FAMILY" in
  deb) dpkg -r safexec >/dev/null ;;
  rpm) dnf -y -q remove safexec >/dev/null ;;
  apk) apk del -q safexec ;;
esac
{ [ ! -e /usr/lib/npp/libnpp_norm.so ] && [ ! -L /usr/lib/npp/libnpp_norm.so ]; } || fail "shim/symlink left behind after removal"
ok "clean removal"
echo "ALL SMOKE CHECKS PASSED"
