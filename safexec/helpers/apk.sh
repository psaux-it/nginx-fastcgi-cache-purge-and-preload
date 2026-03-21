#!/usr/bin/env bash
set -euo pipefail

# =========================================================
# safexec Alpine APK packager (binary-only; musl shim only)
#   - Builds ONE .apk named 'safexec'
#   - Installs:
#       /usr/bin/safexec                  (static musl binary, SUID 4755)
#       /usr/lib/npp/libnpp_norm.so       (musl LD_PRELOAD shim)
#   - Post-install / post-upgrade:
#       * Warns if /usr/bin/safexec is on a nosuid filesystem
#   - SUID declared via options="suid" (mandatory in APKBUILD)
#   - No compilation; uses prebuilt artifacts from safexec/bin/
# =========================================================

# ---------- pretty output ----------
c_red=$'\e[31m'; c_grn=$'\e[32m'; c_ylw=$'\e[33m'; c_cya=$'\e[36m'; c_rst=$'\e[0m'
say()   { printf '%s>>%s %s\n' "$c_cya" "$c_rst" "$*"; }
ok()    { printf '%s✓%s  %s\n' "$c_grn" "$c_rst" "$*"; }
warn()  { printf '%s!%s  %s\n' "$c_ylw" "$c_rst" "$*"; }
die()   { printf '%s✗ ERROR:%s %s\n' "$c_red" "$c_rst" "$*" >&2; exit 1; }

# ---------- parse args ----------
ARCH=""; VERSION=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --arch)    ARCH="${2:-}"; shift 2 ;;
    --version) VERSION="${2:-}"; shift 2 ;;
    -h|--help)
      cat <<EOF
Usage: $0 --arch <x86_64|amd64|aarch64|arm64> --version <VERSION[-rREL]|VERSION[-REL]|VERSION>

Builds a single 'safexec' .apk from prebuilt binaries (no compile).
Alpine APK version format: pkgver-r<pkgrel>  (e.g. 1.9.3-r0)

Examples:
  $0 --arch x86_64  --version 1.9.3
  $0 --arch aarch64 --version 1.9.3-r0
  $0 --arch amd64   --version 1.9.3-1
EOF
      exit 0 ;;
    *) die "Unknown arg: $1" ;;
  esac
done

[[ -n "${ARCH:-}"    ]] || die "--arch is required (x86_64|amd64|aarch64|arm64)"
[[ -n "${VERSION:-}" ]] || die "--version is required (e.g., 1.9.3 or 1.9.3-r0)"

# ---------- arch normalisation ----------
# Accept Debian-style (amd64/arm64) or native Alpine style (x86_64/aarch64)
case "$ARCH" in
  x86_64|amd64)  APK_ARCH="x86_64";  BIN_ARCH="x86_64"  ;;
  aarch64|arm64) APK_ARCH="aarch64"; BIN_ARCH="aarch64" ;;
  *) die "Unsupported --arch: $ARCH (use x86_64/amd64 or aarch64/arm64)" ;;
esac

# ---------- version split ----------
# Accept:  1.9.3        → pkgver=1.9.3 pkgrel=0  (new package, starts at 0)
#          1.9.3-r2     → pkgver=1.9.3 pkgrel=2   (native Alpine format)
#          1.9.3-2      → pkgver=1.9.3 pkgrel=2   (RPM/deb compat format)
# BUG-FIX #6: pkgrel starts at 0 per Alpine convention, not 1
PKG_VER="$VERSION"; PKG_REL="0"
if [[ "$VERSION" == *-r* ]]; then
  PKG_VER="${VERSION%%-r*}"
  PKG_REL="${VERSION##*-r}"
elif [[ "$VERSION" == *-* ]]; then
  PKG_VER="${VERSION%%-*}"
  PKG_REL="${VERSION##*-}"
fi

FULL_VER="${PKG_VER}-r${PKG_REL}"   # e.g. 1.9.3-r0

script_dir="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
ROOT="$(cd "$script_dir/../.." && pwd)"
SRC_DIR="$ROOT/safexec"
BIN_DIR="$SRC_DIR/bin"

# Alpine is musl-only
SAFEEXEC_BIN="$BIN_DIR/safexec-${BIN_ARCH}-linux-musl"
SHIM_MUSL="$BIN_DIR/libnpp_norm-${BIN_ARCH}-musl.so"

say "Validating prebuilt inputs…"
[[ -s "$SAFEEXEC_BIN" ]] || die "Missing safexec binary: $SAFEEXEC_BIN"
[[ -s "$SHIM_MUSL"   ]] || die "Missing musl shim:    $SHIM_MUSL"
ok "Found binaries"

# ---------- build tree ----------
PKG_ROOT="$ROOT/pkg-safexec-${APK_ARCH}-apk"
BUILD_DIR="$PKG_ROOT/build"    # abuild will treat this as startdir
REPO_DIR="$PKG_ROOT/repo"
rm -rf "$PKG_ROOT"
mkdir -p "$BUILD_DIR" "$REPO_DIR"

# ---------- install Alpine build tooling ----------
say "Installing build deps (if needed)…"
if ! command -v apk >/dev/null 2>&1; then
  die "apk not found; this script must run inside an Alpine container"
fi
apk add --no-cache alpine-sdk sudo >/dev/null 2>&1 || \
  apk add --no-cache alpine-sdk sudo
ok "alpine-sdk present"

# ---------- non-root builder user ----------
# abuild refuses to run as root; create a throwaway user inside the container.
BUILD_USER="nppp_builder"
if ! id "$BUILD_USER" >/dev/null 2>&1; then
  adduser -D -s /sbin/nologin "$BUILD_USER"
  adduser "$BUILD_USER" abuild
  echo "$BUILD_USER ALL=(ALL) NOPASSWD: ALL" >> /etc/sudoers
fi

# ---------- stage source binaries alongside APKBUILD ----------
# abuild resolves local source= files relative to startdir (= BUILD_DIR).
cp -a "$SAFEEXEC_BIN" "$BUILD_DIR/safexec.bin"
cp -a "$SHIM_MUSL"    "$BUILD_DIR/libnpp_norm.so"
chmod 755 "$BUILD_DIR/safexec.bin"

# ---------- compute sha512 checksums for source= entries ONLY ----------
SHA512_BIN="$(sha512sum "$BUILD_DIR/safexec.bin"    | awk '{print $1}')"
SHA512_SO="$( sha512sum "$BUILD_DIR/libnpp_norm.so" | awk '{print $1}')"

HOMEPAGE="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload"
MAINT="Hasan Calisir <hasan.calisir@psauxit.com>"

say "Writing install scripts…"

_write_install_script() {
  cat > "$1" <<'INSTALL_SCRIPT'
#!/bin/sh
# Alpine install script — called by apk as:
#   sh safexec.post-install  post_install  <new_ver>
#   sh safexec.post-upgrade  post_upgrade  <new_ver> <old_ver>

_check_nosuid() {
  _p="$1"
  _mp=""; _opts=""

  # Prefer findmnt when available (not always present on Alpine)
  if command -v findmnt >/dev/null 2>&1; then
    _opts="$(findmnt -T "$_p" -no OPTIONS 2>/dev/null || true)"
    _mp="$(  findmnt -T "$_p" -no TARGET  2>/dev/null || true)"
  fi

  # Fallback: parse /proc/self/mountinfo — pick longest matching mountpoint
  if [ -z "${_opts:-}" ] || [ -z "${_mp:-}" ]; then
    _abs="$(readlink -f "$_p" 2>/dev/null || echo "$_p")"
    _line="$(
      awk -v ABS="$_abs" '
        BEGIN { best=0; bmp=""; bopts="" }
        {
          mp=$5; opts=$6;
          for (i=7;i<=NF;i++) { if ($i=="-") break; opts=opts" "$i }
          if (index(ABS,mp)==1 && (ABS==mp || substr(ABS,length(mp)+1,1)=="/")) {
            if (length(mp) > best) { best=length(mp); bmp=mp; bopts=opts }
          }
        }
        END { if (best>0) print bmp "|" bopts }
      ' /proc/self/mountinfo 2>/dev/null || true
    )"
    [ -n "$_line" ] && { _mp="${_line%%|*}"; _opts="${_line#*|}"; }
  fi

  case ",${_opts}," in
    *,nosuid,*)
      echo "Warning: filesystem '${_mp:-?}' containing $_p is mounted nosuid." \
           " The SUID bit on safexec will be ignored (pass-through mode only)." >&2
      ;;
  esac
}

post_install() {
  _check_nosuid "/usr/bin/safexec"
}

post_upgrade() {
  _check_nosuid "/usr/bin/safexec"
}
INSTALL_SCRIPT
}

_write_install_script "$BUILD_DIR/safexec.post-install"
_write_install_script "$BUILD_DIR/safexec.post-upgrade"

# ---------- APKBUILD ----------
say "Writing APKBUILD…"
cat > "$BUILD_DIR/APKBUILD" <<APKBUILD
# Contributor: $MAINT
# Maintainer: $MAINT
pkgname=safexec
pkgver=${PKG_VER}
pkgrel=${PKG_REL}
pkgdesc="Privilege-dropping wrapper for executing a restricted set of tools"
url="${HOMEPAGE}"
arch="${APK_ARCH}"
license="GPL-2.0-only"

options="suid !check !strip"

install="\$pkgname.post-install \$pkgname.post-upgrade"

source="
	safexec.bin
	libnpp_norm.so
"

sha512sums="
${SHA512_BIN}  safexec.bin
${SHA512_SO}  libnpp_norm.so
"

build() { :; }

package() {
	install -Dm4755 "\$srcdir/safexec.bin"    "\$pkgdir/usr/bin/safexec"
	install -Dm0755 "\$srcdir/libnpp_norm.so" "\$pkgdir/usr/lib/npp/libnpp_norm.so"
}
APKBUILD

# ---------- hand ownership to the builder user ----------
chown -R "$BUILD_USER:abuild" "$BUILD_DIR" "$REPO_DIR"

# ---------- generate signing key + build ----------
say "Building .apk (${APK_ARCH}, version ${FULL_VER})…"

# Write an explicit build helper script
BUILD_SCRIPT="$PKG_ROOT/run-abuild.sh"
cat > "$BUILD_SCRIPT" <<SCRIPT
#!/bin/sh
set -eux

# -a  generate RSA keypair
# -i  install the public key to /etc/apk/keys/ (uses sudo internally)
# -n  non-interactive (no passphrase prompt)
abuild-keygen -a -i -n

# Export REPODEST — the canonical way to set the output directory.
export REPODEST='${REPO_DIR}'

cd '${BUILD_DIR}'
# -r  install missing makedepends (none here, but required by abuild)
# -f  force rebuild even if abuild considers the package up-to-date
abuild -r -f
SCRIPT
chmod +x "$BUILD_SCRIPT"
chown "$BUILD_USER:abuild" "$BUILD_SCRIPT"

su -s /bin/sh "$BUILD_USER" "$BUILD_SCRIPT"

# ---------- locate the produced .apk ----------
ART="$(find "$REPO_DIR" -maxdepth 4 -type f \
        -name "safexec-${PKG_VER}-r${PKG_REL}.apk" \
        ! -name '*-doc-*' ! -name '*-dev-*' \
        | sort | tail -1 || true)"

# Fallback: any safexec apk produced in this run
[[ -n "${ART:-}" ]] || \
  ART="$(find "$REPO_DIR" -maxdepth 4 -type f \
              -name 'safexec-*.apk' \
              ! -name '*-doc-*' ! -name '*-dev-*' \
              | sort | tail -1 || true)"

if [[ -f "${ART:-}" ]]; then
  ok "Built: $(basename "$ART")"
  say "Artifact: $ART"
  echo
  ok "Done."
else
  echo
  warn "Expected artifact not found; listing repo dir:"
  find "$REPO_DIR" -type f | sort || true
  die "Build finished without expected .apk"
fi
