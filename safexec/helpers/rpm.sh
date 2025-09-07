#!/usr/bin/env bash
set -euo pipefail

# =========================================================
# safexec RPM packager (binary-only; glibc shim only)
#   - Builds one RPM: safexec-<Version>-<Release>.<arch>.rpm
#   - Installs:
#       /usr/bin/safexec                       (static musl binary)
#       %{_libdir}/libnpp_norm.so              (glibc shim; _libdir=/usr/lib64 or /usr/lib)
#       /usr/lib/npp/libnpp_norm.so ->         %{_libdir}/libnpp_norm.so (packaged symlink)
#   - SUID: %attr(4755,root,root) in %files
#   - No compilation; uses prebuilt artifacts from safexec/bin/
#   - Debuginfo disabled for prebuilt static payloads
# =========================================================

c_red=$'\e[31m'; c_grn=$'\e[32m'; c_ylw=$'\e[33m'; c_cya=$'\e[36m'; c_rst=$'\e[0m'
say()   { printf '%s>>%s %s\n' "$c_cya" "$c_rst" "$*"; }
ok()    { printf '%s✓%s  %s\n' "$c_grn" "$c_rst" "$*"; }
warn()  { printf '%s!%s  %s\n' "$c_ylw" "$c_rst" "$*"; }
die()   { printf '%s✗ ERROR:%s %s\n' "$c_red" "$c_rst" "$*" >&2; exit 1; }

ARCH=""; VERSION=""
DISTTAG="${DISTTAG:-%{?dist}}"
LICENSE_ID="${LICENSE_ID:-GPL-2.0-only}"
URL_HOMEPAGE="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload"
MAINT="Hasan Calisir <hasan.calisir@psauxit.com>"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --arch)    ARCH="${2:-}"; shift 2 ;;
    --version) VERSION="${2:-}"; shift 2 ;;
    -h|--help)
      cat <<EOF
Usage: $0 --arch <amd64|arm64> --version <VERSION|VERSION-REL>

Examples:
  $0 --arch amd64 --version 1.9.2-3
  $0 --arch arm64 --version 1.9.2
EOF
      exit 0 ;;
    *) die "Unknown arg: $1" ;;
  esac
done

[[ -n "${ARCH:-}"    ]] || die "--arch is required (amd64|arm64)"
[[ -n "${VERSION:-}" ]] || die "--version is required (e.g., 1.9.2-1)"

case "$ARCH" in
  amd64)  RPMARCH="x86_64"  ;;
  arm64)  RPMARCH="aarch64" ;;
  *) die "Unsupported --arch: $ARCH (use amd64 or arm64)" ;;
esac

RPM_VERSION="$VERSION"; RPM_RELEASE="1"
if [[ "$VERSION" == *-* ]]; then
  RPM_VERSION="${VERSION%%-*}"
  RPM_RELEASE="${VERSION#*-}"
fi

script_dir="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
ROOT="$(cd "$script_dir/../.." && pwd)"
SRC_DIR="$ROOT/safexec"
BIN_DIR="$SRC_DIR/bin"

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

PKG_ROOT="$ROOT/pkg-safexec-${ARCH}-rpm"
RPMTOP="$PKG_ROOT/rpmbuild"
rm -rf "$PKG_ROOT"
mkdir -p "$RPMTOP"/{BUILD,BUILDROOT,RPMS,SOURCES,SPECS,SRPMS}

# --- Stage docs/man (man is gzip-compressed by rpmbuild automatically) ---
MANPAGE_SRC="$RPMTOP/SOURCES/safexec.1"
cat > "$MANPAGE_SRC" <<'EOF'
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
Config variables are split between \fIsafexec\fR and the \fIshim\fR:

.TP
.B SAFEXEC_PCTNORM=1|0  (safexec)
Enable/disable LD_PRELOAD injection for wget/curl. Default: \fB1\fR (enabled).

.TP
.B SAFEXEC_PCTNORM_SO=\fI/path/to/libnpp_norm.so\fR  (safexec)
Path to the shim. Default: \fB/usr/lib/npp/libnpp_norm.so\fR (a stable symlink
to the canonical file under \fB%{_libdir}/libnpp_norm.so\fR).

.TP
.B SAFEXEC_PCTNORM_CASE=upper|lower|off  (safexec)
Value passed through to the shim as \fBPCTNORM_CASE\fR. Default: \fBupper\fR.

.TP
.B PCTNORM_CASE=upper|lower|off  (shim)
What the shim actually reads to decide percent-triplet hex case. Set by
safexec when injection occurs. Default: \fBupper\fR.

.SH AUTHOR
Hasan Calisir
EOF

README_RPM="$RPMTOP/SOURCES/README.RPM"
cat > "$README_RPM" <<'EOF'
safexec installs:
  - /usr/bin/safexec (prebuilt, static musl for portability)
  - %{_libdir}/libnpp_norm.so   (glibc shim; %{_libdir} is /usr/lib64 or /usr/lib)

A stable entrypoint symlink is packaged:
  - /usr/lib/npp/libnpp_norm.so -> %{_libdir}/libnpp_norm.so

Env (quick ref):
  - SAFEXEC_PCTNORM=1|0             (default 1; safexec)
  - SAFEXEC_PCTNORM_SO=/path/to/so  (default /usr/lib/npp/libnpp_norm.so; safexec)
  - SAFEXEC_PCTNORM_CASE=upper|lower|off (default upper; safexec -> shim)
  - PCTNORM_CASE=upper|lower|off    (read by shim)

If you must drop SUID locally:
  sudo chmod 0755 /usr/bin/safexec
EOF

cp -a "$SAFEEXEC_BIN" "$RPMTOP/SOURCES/safexec.bin"
cp -a "$SHIM_GLIBC"   "$RPMTOP/SOURCES/libnpp_norm.so"

# --- GLIBC requirement probe (add this block) ---
say "Checking required GLIBC version for the shim…"

GLIBC_REQ=""
if command -v readelf >/dev/null 2>&1; then
  GLIBC_REQ="$(readelf -V "$RPMTOP/SOURCES/libnpp_norm.so" 2>/dev/null \
    | grep -o 'GLIBC_[0-9.]\+' \
    | sed 's/^GLIBC_//' \
    | sort -V \
    | tail -n1 || true)"
elif command -v objdump >/dev/null 2>&1; then
  # Fallback if readelf is not present
  GLIBC_REQ="$(objdump -T "$RPMTOP/SOURCES/libnpp_norm.so" 2>/dev/null \
    | grep -o 'GLIBC_[0-9.]\+' \
    | sed 's/^GLIBC_//' \
    | sort -V \
    | tail -n1 || true)"
else
  warn "No readelf/objdump; skipping GLIBC requirement detection."
fi

if [[ -n "${GLIBC_REQ:-}" ]]; then
  warn "libnpp_norm.so requires GLIBC >= ${GLIBC_REQ}"
  warn "Ensure this is <= the oldest distro you target (rough guide: EL7≈2.17, EL8≈2.28, EL9≈2.34)."
fi

# License: prefer upstream; otherwise a minimal marker
if [[ -f "$SRC_DIR/LICENSE" ]]; then
  cp -a "$SRC_DIR/LICENSE" "$RPMTOP/SOURCES/LICENSE"
elif [[ -f "$ROOT/LICENSE" ]]; then
  cp -a "$ROOT/LICENSE" "$RPMTOP/SOURCES/LICENSE"
else
  printf "GPL-2.0-only\n" > "$RPMTOP/SOURCES/LICENSE"
fi

SPEC="$RPMTOP/SPECS/safexec.spec"
say "Writing spec: $SPEC"
cat > "$SPEC" <<'EOSPEC'
# Prebuilt, static payload; disable debuginfo subpkgs and disable strip.
# See: serverfault/unix threads recommending %global debug_package %{nil}
# and Fedora Debuginfo notes about stripping. We also neutralize __strip.
# (We are not compiling sources here.)
%global debug_package %{nil}
%undefine _enable_debug_packages
%global __strip /bin/true
%global __objdump /bin/true
%global _missing_build_ids_terminate_build 0
%global _build_id_links none

Name:           safexec
Version:        __RPM_VERSION__
Release:        __RPM_RELEASE____DISTTAG__
Summary:        Secure wrapper for allowlisted tools with LD_PRELOAD shim (binary-only)
License:        __LICENSE_ID__
URL:            __URL_HOMEPAGE__
ExclusiveArch:  x86_64 aarch64

Source0:        safexec.bin
Source1:        libnpp_norm.so
Source2:        safexec.1
Source3:        README.RPM
Source4:        LICENSE

# We are not building anything; no BuildRequires necessary.

%description
safexec executes a restricted set of tools (wget, curl, …) with an
allowlist, early env sanitization, cgroup/rlimits isolation, and a
safe privilege drop (never exec as root). Optionally injects a tiny
LD_PRELOAD shim (libnpp_norm.so) to normalize or preserve percent
triplet case (%xx) on the HTTP request-target for cache-key stability.

This package ships:
  - /usr/bin/safexec (prebuilt, static musl for portability)
  - %{_libdir}/libnpp_norm.so       (LD_PRELOAD shim)
  - /usr/lib/npp/libnpp_norm.so -> %{_libdir}/libnpp_norm.so

%prep
# nothing

%build
# nothing

%install
rm -rf "%{buildroot}"

# binaries
install -Dm0755 "%{SOURCE0}" "%{buildroot}/usr/bin/safexec"

# shim in canonical libdir
install -Dm0644 "%{SOURCE1}" "%{buildroot}%{_libdir}/libnpp_norm.so"

# convenience symlink (package it directly; no need for %post)
install -d "%{buildroot}/usr/lib/npp"
ln -s "%{_libdir}/libnpp_norm.so" "%{buildroot}/usr/lib/npp/libnpp_norm.so"

# manpage
install -Dm0644 "%{SOURCE2}" "%{buildroot}%{_mandir}/man1/safexec.1"

# docs & license
install -Dm0644 "%{SOURCE3}" "%{buildroot}/usr/share/doc/%{name}/README.RPM"
install -Dm0644 "%{SOURCE4}" "%{buildroot}/usr/share/licenses/%{name}/LICENSE"

%files
%license /usr/share/licenses/%{name}/LICENSE
%doc     /usr/share/doc/%{name}/README.RPM
%attr(4755,root,root) /usr/bin/safexec
%{_libdir}/libnpp_norm.so
%dir /usr/lib/npp
/usr/lib/npp/libnpp_norm.so
%{_mandir}/man1/safexec.1*

%changelog
* __DATE_RPM__ __MAINT__ - __RPM_VERSION__-__RPM_RELEASE__
- Install real shim at %{_libdir}/libnpp_norm.so
- Provide stable symlink /usr/lib/npp/libnpp_norm.so -> %{_libdir}/libnpp_norm.so
- Keep safexec static (SUID) and ship man/docs
EOSPEC

DATE_RPM="$(LC_ALL=C date '+%a %b %d %Y')"
sed -i \
  -e "s|__RPM_VERSION__|$RPM_VERSION|g" \
  -e "s|__RPM_RELEASE__|$RPM_RELEASE|g" \
  -e "s|__LICENSE_ID__|$LICENSE_ID|g" \
  -e "s|__URL_HOMEPAGE__|$URL_HOMEPAGE|g" \
  -e "s|__MAINT__|$MAINT|g" \
  -e "s|__DATE_RPM__|$DATE_RPM|g" \
  "$SPEC"

if [[ -n "${DISTTAG:-}" ]]; then
  sed -i -e "s|__DISTTAG__|${DISTTAG}|g" "$SPEC"
else
  sed -i -e "s|__DISTTAG__|%{?dist}|g" "$SPEC"
fi

say "Installing RPM tooling (if available)…"

as_root() {
  if [[ $EUID -eq 0 ]]; then
    "$@"
  else
    sudo "$@"
  fi
}

set +e
if command -v dnf >/dev/null 2>&1; then
  # make sure metadata is fresh
  as_root dnf -y clean all
  as_root dnf -y makecache

  # enable CRB if plugin exists (Rocky/RHEL 9)
  if as_root dnf -y install 'dnf-command(config-manager)' >/dev/null 2>&1; then
    as_root dnf config-manager --set-enabled crb >/dev/null 2>&1 || true
  fi

  # core for rpmbuild + toolchain
  as_root dnf -y install rpm-build redhat-rpm-config rpmdevtools binutils || die "Failed to install core RPM tooling"

  # helpful extras
  as_root dnf -y install createrepo_c rpm-sign || true

  # rpmlint needs EPEL on Rocky
  as_root dnf -y install epel-release || true
  as_root dnf -y install rpmlint || true

elif command -v yum >/dev/null 2>&1; then
  as_root yum -y clean all
  as_root yum -y makecache || true
  as_root yum -y install rpm-build redhat-rpm-config rpmdevtools binutils || die "Failed to install core RPM tooling"
  as_root yum -y install createrepo rpm-sign || true
  # EPEL for rpmlint on EL7/EL8
  as_root yum -y install epel-release || true
  as_root yum -y install rpmlint || true

elif command -v zypper >/dev/null 2>&1; then
  as_root zypper --non-interactive refresh
  as_root zypper --non-interactive install rpm-build rpmlint rpm-sign createrepo_c binutils || die "Failed to install RPM tooling via zypper"
else
  warn "No supported package manager (dnf/yum/zypper) detected; skipping tool installation."
fi
set -e

# Hard checks so we fail early if installs didn’t happen
need_tool() { command -v "$1" >/dev/null 2>&1 || die "Required tool not found: $1"; }
need_tool rpmbuild
# readelf/objdump come from binutils; at least one must exist
if ! command -v readelf >/dev/null 2>&1 && ! command -v objdump >/dev/null 2>&1; then
  die "Neither readelf nor objdump found; install binutils"
fi

ok "RPM tooling present"

say "Building RPM…"
rpmbuild \
  --define "_topdir $RPMTOP" \
  --target "$RPMARCH" \
  -bb "$SPEC"

ART="$(find "$RPMTOP/RPMS/$RPMARCH" -maxdepth 1 -type f -name 'safexec-*.rpm' | sort | tail -n1 || true)"
if [[ -f "${ART:-}" ]]; then
  ok "Built: $(basename "$ART")"
  say "Artifact: $ART"
  echo
  ok "Done."
else
  echo
  warn "Expected artifact not found; listing $RPMTOP/RPMS/$RPMARCH:"
  ls -lah "$RPMTOP/RPMS/$RPMARCH" || true
  die "Build finished without expected .rpm"
fi
