#!/usr/bin/env bash
set -euo pipefail

# =========================================================
# safexec Debian packager (binary-only; glibc shim only)
#   - Builds ONE .deb named 'safexec'
#   - Installs:
#       /usr/bin/safexec                      (static musl binary)
#       /usr/lib/<multiarch>/npp/libnpp_norm.so  (glibc shim)
#   - Postinst:
#       * Create/update convenience symlink:
#           /usr/lib/npp/libnpp_norm.so -> ../<multiarch>/npp/libnpp_norm.so
#   - SUID via dpkg-statoverride
#   - No compilation; uses prebuilt artifacts
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
Usage: $0 --arch <amd64|arm64> --version <VERSION|VERSION-REV>

Builds a single 'safexec' .deb from prebuilt binaries (no compile).

Examples:
  $0 --arch amd64 --version 1.9.2-1
  $0 --arch arm64 --version 1.9.2
EOF
      exit 0 ;;
    *) die "Unknown arg: $1" ;;
  esac
done

[[ -n "${ARCH:-}"    ]] || die "--arch is required (amd64|arm64)"
[[ -n "${VERSION:-}" ]] || die "--version is required (e.g., 1.9.2-1)"

case "$ARCH" in
  amd64)  CPU_TRIPLET_GLIBC="x86_64-linux-gnu"  ;;
  arm64)  CPU_TRIPLET_GLIBC="aarch64-linux-gnu" ;;
  *) die "Unsupported --arch: $ARCH (use amd64 or arm64)" ;;
esac

script_dir="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
ROOT="$(cd "$script_dir/../.." && pwd)"
SRC_DIR="$ROOT/safexec"
BIN_DIR="$SRC_DIR/bin"

# Prebuilt artifacts:
#  - safexec: static-musl binary
#  - libnpp_norm-<arch>-glibc.so: glibc-shared shim
if [[ "$ARCH" == "amd64" ]]; then
  SAFEEXEC_BIN="$BIN_DIR/safexec-x86_64-linux-musl"
  SHIM_GLIBC="$BIN_DIR/libnpp_norm-x86_64-glibc.so"
else
  SAFEEXEC_BIN="$BIN_DIR/safexec-aarch64-linux-musl"
  SHIM_GLIBC="$BIN_DIR/libnpp_norm-aarch64-glibc.so"
fi

say "Validating prebuilt inputs…"
[[ -s "$SAFEEXEC_BIN" ]] || die "Missing safexec binary: $SAFEEXEC_BIN"
[[ -s "$SHIM_GLIBC"  ]] || die "Missing glibc shim:    $SHIM_GLIBC"
ok "Found binaries"

# Output/build dir
PKG_ROOT="$ROOT/pkg-safexec-${ARCH}-single"
rm -rf "$PKG_ROOT"; mkdir -p "$PKG_ROOT"
cd "$PKG_ROOT"

say "Preparing debian/ skeleton…"
mkdir -p debian debian/source
MAINT="Hasan Calisir <hasan.calisir@psauxit.com>"
HOMEPAGE="https://github.com/psauxit/nginx-fastcgi-cache-purge-and-preload"

# -------- quilt vs native: create orig.tar.gz and mirror upstream into tree --------
UPSTREAM_VER="$VERSION"; SOURCE_FORMAT="3.0 (native)"
if [[ "$VERSION" == *-* ]]; then
  SOURCE_FORMAT="3.0 (quilt)"
  UPSTREAM_VER="${VERSION%%-*}"

  # Stage minimal upstream files so dpkg-source is happy
  STAGE_BASE="$PKG_ROOT/.upstream-staging"
  STAGE_DIR="$STAGE_BASE/safexec-$UPSTREAM_VER"
  rm -rf "$STAGE_BASE"; mkdir -p "$STAGE_DIR"
  printf "%s\n" "safexec upstream placeholder for $UPSTREAM_VER" > "$STAGE_DIR/UPSTREAM.md"
  for f in "$SRC_DIR/README.md" "$SRC_DIR/LICENSE" "$ROOT/README.md" "$ROOT/LICENSE"; do
    [[ -f "$f" ]] && cp -a "$f" "$STAGE_DIR/" || true
  done
  (cd "$STAGE_BASE" && tar -czf "$PKG_ROOT/../safexec_${UPSTREAM_VER}.orig.tar.gz" "safexec-$UPSTREAM_VER")
  cp -a "$STAGE_DIR/." "$PKG_ROOT/"
  rm -rf "$STAGE_BASE"
fi
echo "$SOURCE_FORMAT" > debian/source/format

# Ignore internal scratch dirs
cat > debian/source/options <<'EOF'
extend-diff-ignore = ^(?:\.upstream-staging/|debian/\.debhelper/|debian/files)$
EOF

ok "debian/source/format = $SOURCE_FORMAT"

# debian/control
cat > debian/control <<EOF
Source: safexec
Section: utils
Priority: optional
Maintainer: $MAINT
Build-Depends: debhelper-compat (= 13)
Standards-Version: 4.7.0
Homepage: $HOMEPAGE
Rules-Requires-Root: no

Package: safexec
Architecture: amd64 arm64
Depends: \${misc:Depends}, \${shlibs:Depends}
Description: Secure wrapper for allowlisted tools with LD_PRELOAD shim (binary-only)
 safexec executes a restricted set of tools (wget, curl, …) with an
 allowlist, early env sanitization, cgroup/rlimits isolation, and a
 safe privilege drop (never exec as root). Optionally injects a tiny
 LD_PRELOAD shim (libnpp_norm.so) to normalize or preserve percent
 triplet case (%xx) on the HTTP request-target for cache-key stability.
 .
 This package ships:
  - /usr/bin/safexec (prebuilt, static musl for portability)
  - /usr/lib/${CPU_TRIPLET_GLIBC}/npp/libnpp_norm.so   (LD_PRELOAD shim)
 .
 A convenience symlink is created on install:
  - /usr/lib/npp/libnpp_norm.so  -> ../${CPU_TRIPLET_GLIBC}/npp/libnpp_norm.so
EOF

# changelog
DATE_RFC2822="$(LC_ALL=C date -R)"
cat > debian/changelog <<EOF
safexec (${VERSION}) unstable; urgency=medium

  * Binary-only release for Debian/Ubuntu (glibc shim only).
    - Installs safexec to /usr/bin/safexec (static musl).
    - Installs glibc shim to multiarch path.
    - Creates convenience symlink under /usr/lib/npp.
    - Sets SUID via dpkg-statoverride.
    - Adds man page and portability notes.

 -- $MAINT  ${DATE_RFC2822}
EOF

# rules (no shlibdeps override; we want it to run)
cat > debian/rules <<'MAKE'
#!/usr/bin/make -f
export DH_VERBOSE=1
.RECIPEPREFIX := >

SAFEEXEC_BIN := #SAFEEXEC_BIN#
SHIM_GLIBC   := #SHIM_GLIBC#
TRIPLET_GLIBC := #TRIPLET_GLIBC#

%:
> dh $@

override_dh_auto_build:
> : # nothing to build (binary-only packaging)

override_dh_auto_install:
> set -e; \
> install -m 0755 -D "$(SAFEEXEC_BIN)" debian/safexec/usr/bin/safexec; \
> install -m 0644 -D "$(SHIM_GLIBC)" "debian/safexec/usr/lib/$(TRIPLET_GLIBC)/npp/libnpp_norm.so"; \
> install -d -m 0755 debian/safexec/usr/lib/npp

override_dh_fixperms:
> dh_fixperms
> # SUID handled via dpkg-statoverride in postinst
MAKE
sed -i "s|#SAFEEXEC_BIN#|$SAFEEXEC_BIN|g" debian/rules
sed -i "s|#SHIM_GLIBC#|$SHIM_GLIBC|g"   debian/rules
sed -i "s|#TRIPLET_GLIBC#|$CPU_TRIPLET_GLIBC|g" debian/rules
chmod +x debian/rules

# copyright
cat > debian/copyright <<'EOF'
Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/
Upstream-Name: safexec
Source: https://github.com/psauxit/nginx-fastcgi-cache-purge-and-preload

Files: *
Copyright: 2025 Hasan Calisir <hasan.calisir@psauxit.com>
License: GPL-2.0-only
 This package is licensed under the GNU General Public License, version 2 only.
 .
 On Debian systems, the complete text of the GNU General Public License
 version 2 can be found in /usr/share/common-licenses/GPL-2.
EOF

# postinst: SUID + convenience symlink (/usr/lib/npp)
cat > debian/safexec.postinst <<'EOF'
#!/bin/sh
set -e

case "$1" in
  configure)
    # Ensure suid bit via statoverride (policy-compliant)
    if ! dpkg-statoverride --list /usr/bin/safexec >/dev/null 2>&1; then
      dpkg-statoverride --update --add root root 4755 /usr/bin/safexec
    fi

    TRIPLET_GLIBC="#TRIPLET_GLIBC#"

    # Create convenience symlink /usr/lib/npp/libnpp_norm.so -> ../<triplet>/npp/libnpp_norm.so
    mkdir -p /usr/lib/npp
    ln -snf "../${TRIPLET_GLIBC}/npp/libnpp_norm.so" /usr/lib/npp/libnpp_norm.so
    ;;
esac

#DEBHELPER#
exit 0
EOF
sed -i "s|#TRIPLET_GLIBC#|$CPU_TRIPLET_GLIBC|g" debian/safexec.postinst
chmod +x debian/safexec.postinst

# prerm: tidy statoverride
cat > debian/safexec.prerm <<'EOF'
#!/bin/sh
set -e
case "$1" in
  remove|deconfigure)
    if dpkg-statoverride --list /usr/bin/safexec >/dev/null 2>&1; then
      dpkg-statoverride --remove /usr/bin/safexec || true
    fi
    ;;
esac
#DEBHELPER#
exit 0
EOF
chmod +x debian/safexec.prerm

# manpage + list
mkdir -p debian/man
cat > debian/man/safexec.1 <<'EOF'
.TH SAFEXEC 1 "safexec" "1.0" "User Commands"
.SH NAME
safexec \- secure wrapper for allowlisted tools with optional LD_PRELOAD shim
.SH SYNOPSIS
.B safexec
[\fIprogram\fR] [\fIargs...\fR]
.SH DESCRIPTION
\fBsafexec\fR executes a restricted set of tools (wget, curl, ...) with an
allowlist, early environment sanitization, cgroup/rlimits isolation, and safe
privilege drop. It can optionally inject an LD_PRELOAD shim (libnpp_norm.so)
to normalize or preserve percent-encoding hex case in HTTP request-targets.
.SH ENVIRONMENT
.TP
.B PCTNORM_CASE
upper | lower | off
.TP
.B SAFEXEC_PCTNORM_SO
Absolute path to libnpp_norm.so.
Recommended path:
 /usr/lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)/npp/libnpp_norm.so
A convenience symlink is also provided:
 /usr/lib/npp/libnpp_norm.so
.SH AUTHOR
Hasan Calisir
EOF
echo "debian/man/safexec.1" > debian/safexec.manpages

# README.Debian
cat > debian/README.Debian <<EOF
safexec installs:
  - /usr/bin/safexec (prebuilt, static musl for portability)
  - /usr/lib/${CPU_TRIPLET_GLIBC}/npp/libnpp_norm.so   (glibc shim)

On install, a convenience symlink is created:
  - /usr/lib/npp/libnpp_norm.so -> ../${CPU_TRIPLET_GLIBC}/npp/libnpp_norm.so

At runtime you may override explicitly:
  SAFEXEC_PCTNORM_SO=/usr/lib/\$(dpkg-architecture -qDEB_HOST_MULTIARCH)/npp/libnpp_norm.so

SUID is applied with dpkg-statoverride in postinst (policy-compliant).
To change locally:
  sudo dpkg-statoverride --update --add root root 0755 /usr/bin/safexec
EOF

# lintian overrides
cat > debian/safexec.lintian-overrides <<'EOF'
safexec: setuid-binary usr/bin/safexec
# SUID is set via dpkg-statoverride (policy-compliant)

safexec: statically-linked-binary
# safexec is intentionally a static musl binary for portability/isolation.
EOF

# deps
say "Installing build deps (if needed)…"
sudo apt-get update -y >/dev/null 2>&1 || true
sudo apt-get install -y debhelper devscripts dpkg-dev lintian debhelper-compat fakeroot build-essential >/dev/null
ok "Build deps present"

say "Building .deb…"
debuild -us -uc

ART="../safexec_${VERSION}_${ARCH}.deb"
if [[ -f "$ART" ]]; then
  ok "Built: $(basename "$ART")"
  say "Artifact: $ART"
  echo
  ok "Done."
else
  echo
  warn "Expected artifact not found; listing ../ for clues:"
  ls -lah ../
  die "Build finished without expected .deb"
fi
