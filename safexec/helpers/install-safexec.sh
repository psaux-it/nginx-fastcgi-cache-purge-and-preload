#!/usr/bin/env sh
# SPDX-License-Identifier: GPL-2.0-only
#
# install-safexec.sh — Universal installer for safexec + libnpp_norm shim
#
# Supports: any Linux distribution (Arch, Gentoo, Void, NixOS, Alpine,
#           Debian/Ubuntu, RHEL/Rocky/Fedora, and anything else POSIX)
#
# Detection order:
#   1. CPU architecture (uname -m)
#   2. C runtime: musl vs glibc (7 probes in order, musl wins on any hit,
#      glibc is default; override with FORCE_LIBC=musl|glibc)
#   3. Distro family: Debian / RPM / Alpine / generic
#   4. Library path: per-family canonical location + stable symlink
#
# Install layout:
#   /usr/bin/safexec                    — setuid 4755 root:root static binary
#   <canonical_lib>/libnpp_norm.so      — LD_PRELOAD shim (arch+libc matched)
#   /usr/lib/npp/libnpp_norm.so         — stable symlink (safexec default path)
#
# Environment overrides:
#   BASE_URL        Raw base URL for bin/ directory
#   INSTALL_BIN     safexec destination      (default /usr/bin/safexec)
#   INSTALL_LIB     shim canonical dir       (default: auto-detected)
#   ALLOW_NOSUID    1 = install even on nosuid mount (reduced isolation)
#   SKIP_CHECKSUM   1 = skip sha256 verification
#   FORCE_LIBC      "musl" or "glibc" — override libc autodetect
#                   (useful on Alpine+gcompat or any mixed-libc environment)
#   FORCE_COLOR     1 = always use color
#   NO_COLOR        set = disable color
#
# Usage:
#   sudo sh install-safexec.sh
#   sudo ALLOW_NOSUID=1 sh install-safexec.sh
#   sudo INSTALL_BIN=/opt/bin/safexec sh install-safexec.sh
#
set -eu

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
BASE_URL="${BASE_URL:-https://raw.githubusercontent.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/main/safexec/bin}"
INSTALL_BIN="${INSTALL_BIN:-/usr/bin/safexec}"
ALLOW_NOSUID="${ALLOW_NOSUID:-0}"
SKIP_CHECKSUM="${SKIP_CHECKSUM:-0}"
FORCE_LIBC="${FORCE_LIBC:-}"
NPP_SYMLINK_DIR="/usr/lib/npp"
NPP_SYMLINK="${NPP_SYMLINK_DIR}/libnpp_norm.so"

# ---------------------------------------------------------------------------
# Colors (auto-detected; NO_COLOR / FORCE_COLOR honoured)
# ---------------------------------------------------------------------------
if { [ -t 1 ] || [ "${FORCE_COLOR:-0}" = "1" ]; } \
   && [ -z "${NO_COLOR:-}" ] \
   && [ "${TERM:-dumb}" != "dumb" ]; then
    BOLD="$(printf '\033[1m')"
    RED="$(printf '\033[31m')"
    GRN="$(printf '\033[32m')"
    YLW="$(printf '\033[33m')"
    CYN="$(printf '\033[36m')"
    RST="$(printf '\033[0m')"
else
    BOLD=""; RED=""; GRN=""; YLW=""; CYN=""; RST=""
fi

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
die()  { printf >&2 "%s✗ Error:%s %s\n" "$RED" "$RST" "$*"; exit 1; }
warn() { printf >&2 "%s! Warning:%s %s\n" "$YLW" "$RST" "$*"; }
info() { printf "%s>%s %s\n" "$CYN" "$RST" "$*"; }
ok()   { printf "%s✓%s %s\n" "$GRN" "$RST" "$*"; }
step() { printf "\n%s==>%s %s%s%s\n" "$BOLD" "$RST" "$BOLD" "$*" "$RST"; }

# ---------------------------------------------------------------------------
# Utility helpers
# ---------------------------------------------------------------------------
need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

have_cmd() {
    command -v "$1" >/dev/null 2>&1
}

# Absolute path of a directory
dir_abs() {
    _d="${1:-.}"
    ( cd "$_d" 2>/dev/null && pwd -P ) || printf '%s' "$_d"
}

# Parse mount options for the filesystem containing $1
mount_opts_for() {
    awk -v p="$1" '
        BEGIN { L=0; O="" }
        {
            mp=$2; gsub("\\\\040"," ",mp)
            if (index(p, mp)==1 && length(mp)>L) { L=length(mp); O=$4 }
        }
        END { print O }
    ' /proc/self/mounts 2>/dev/null || true
}

fs_has_flag() {
    _opts="$(mount_opts_for "$1")"
    [ -n "$_opts" ] && printf '%s' "$_opts" | tr ',' '\n' | grep -qx "$2"
}

# ---------------------------------------------------------------------------
# Architecture detection
# ---------------------------------------------------------------------------
detect_arch() {
    _m="$(uname -m 2>/dev/null || true)"
    case "$_m" in
        x86_64|amd64)  echo "x86_64"  ;;
        aarch64|arm64) echo "aarch64" ;;
        *) die "unsupported CPU architecture: ${_m:-unknown}" ;;
    esac
}

# ---------------------------------------------------------------------------
# C runtime detection — returns "musl" or "glibc"
#
# Probe order (any hit → musl):
#   1. /lib/libc.musl-<arch>.so.* — Alpine/Void/musl-libc
#   2. /lib/<arch>-linux-musl/ directory — musl cross installs
#   3. ldd --version output contains "musl"
#   4. readelf on /bin/sh | /usr/bin/sh for musl interpreter path
#   5. /proc/1/exe → ldd → "musl" (when readelf unavailable)
# Fallback: glibc
# ---------------------------------------------------------------------------
detect_libc() {
    _arch="$(uname -m 2>/dev/null || true)"

    # Probe 1: musl dynamic linker on disk — most authoritative
    # musl reference manual: "$(syslibdir)/ld-musl-$(ARCH).so.1"
    for _f in /lib/ld-musl-*.so.1 /usr/lib/ld-musl-*.so.1; do
        [ -e "$_f" ] && { echo "musl"; return; }
    done

    # Probe 2: musl libc shared object — Alpine/Void canonical location
    for _f in /lib/libc.musl-*.so.* /usr/lib/libc.musl-*.so.*; do
        [ -e "$_f" ] && { echo "musl"; return; }
    done

    # Probe 3: read ldd as text file — fastest method, no subprocess needed
    # On musl, /bin/ldd or /usr/bin/ldd is a shell script containing "musl"
    # On glibc, ldd is either a binary or a script that does NOT contain "musl"
    for _ldd in /bin/ldd /usr/bin/ldd; do
        [ -r "$_ldd" ] || continue
        if grep -q musl "$_ldd" 2>/dev/null; then
            echo "musl"; return
        fi
    done

    # Probe 4: ldd --version — must capture BOTH stdout and stderr
    # musl outputs version info to stderr; glibc outputs to stdout
    # Both say "musl" or "GNU" in their output
    if have_cmd ldd; then
        if ldd --version 2>&1 | grep -qi musl; then
            echo "musl"; return
        fi
    fi

    # Probe 5: getconf GNU_LIBC_VERSION — succeeds only on glibc
    # On musl, getconf either doesn't exist or doesn't know GNU_LIBC_VERSION
    if have_cmd getconf; then
        if getconf GNU_LIBC_VERSION >/dev/null 2>&1; then
            echo "glibc"; return
        fi
    fi

    # Probe 6: musl library search path config file
    # musl reference: /etc/ld-musl-$ARCH.path controls library search
    [ -e "/etc/ld-musl-${_arch}.path" ] && { echo "musl"; return; }

    # Probe 7: ELF interpreter of a real dynamic binary (not the shell itself)
    # Use a known dynamically linked binary, not /bin/sh which may be static
    for _bin in /usr/bin/env /usr/bin/id /bin/cat; do
        [ -x "$_bin" ] || continue
        if have_cmd readelf; then
            _interp="$(readelf -l "$_bin" 2>/dev/null | grep 'interpreter' | head -1 || true)"
            if printf '%s' "$_interp" | grep -qi musl; then
                echo "musl"; return
            fi
            if printf '%s' "$_interp" | grep -qi 'ld-linux\|ld-2\|glibc'; then
                echo "glibc"; return
            fi
        fi
        break  # only try first found binary
    done

    # Default: glibc (most common Linux libc by far)
    echo "glibc"
}

# ---------------------------------------------------------------------------
# Distro family detection — returns: debian | rpm | alpine | generic
# ---------------------------------------------------------------------------
detect_distro_family() {
    # Alpine — /etc/alpine-release is definitive
    [ -f /etc/alpine-release ] && { echo "alpine"; return; }

    # Debian/Ubuntu/Mint/Raspbian etc.
    if [ -f /etc/debian_version ]; then
        echo "debian"; return
    fi

    # RPM family: RHEL, Rocky, AlmaLinux, Fedora, CentOS, SUSE
    if [ -f /etc/redhat-release ] || [ -f /etc/fedora-release ] \
       || [ -f /etc/centos-release ] || [ -f /etc/rocky-release ] \
       || [ -f /etc/almalinux-release ] || [ -f /etc/SuSE-release ] \
       || [ -f /etc/suse-brand ]; then
        echo "rpm"; return
    fi

    # /etc/os-release fallback (covers openSUSE, Arch, Gentoo, Void, NixOS …)
    if [ -r /etc/os-release ]; then
        _id="$(. /etc/os-release 2>/dev/null; printf '%s' "${ID_LIKE:-${ID:-}}")"
        case "$_id" in
            *debian*|*ubuntu*) echo "debian"; return ;;
            *rhel*|*fedora*|*suse*|*centos*)  echo "rpm";    return ;;
            *alpine*)          echo "alpine"; return ;;
        esac
    fi

    echo "generic"
}

# ---------------------------------------------------------------------------
# Library directory selection
#
# Rules:
#   debian  — /usr/lib/<multiarch>/npp   e.g. /usr/lib/x86_64-linux-gnu/npp
#   rpm     — /usr/lib64 (both x86_64 and aarch64; RPM %_lib macro is lib64 on all 64-bit)
#   alpine  — /usr/lib/npp  (same as stable symlink dir, so shim IS the symlink)
#   generic — /usr/lib
# ---------------------------------------------------------------------------
detect_lib_dir() {
    _arch="$1"
    _libc="$2"
    _family="$3"

    case "$_family" in
        debian)
            # Debian/Ubuntu multiarch: /usr/lib/<gnu-triplet>/
            case "$_arch" in
                x86_64)  echo "/usr/lib/x86_64-linux-gnu/npp" ;;
                aarch64) echo "/usr/lib/aarch64-linux-gnu/npp" ;;
            esac
            ;;
        rpm)
            # RHEL/Rocky/Fedora/AlmaLinux/openSUSE/SUSE:
            # %{_libdir} is /usr/lib64 on BOTH x86_64 AND aarch64
            # (RPM %_lib macro → "lib64" on all 64-bit RPM platforms)
            echo "/usr/lib64"
            ;;
        alpine)
            # Alpine musl-only; shim goes directly at the stable symlink location
            echo "/usr/lib/npp"
            ;;
        *)
            # Arch, Void, generic glibc distros → /usr/lib
            # Gentoo: multilib uses /usr/lib64, no-multilib uses /usr/lib
            # Probe at runtime: if /usr/lib64 exists and is a real directory
            # (not a symlink like on Arch), use it; otherwise /usr/lib
            if [ -d /usr/lib64 ] && [ ! -L /usr/lib64 ]; then
                echo "/usr/lib64"
            else
                echo "/usr/lib"
            fi
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
step "Preflight checks"

[ "$(uname -s 2>/dev/null)" = "Linux" ] || die "this installer is Linux-only"
[ "$(id -u)" -eq 0 ] || die "must run as root (try: sudo sh $0)"
[ -r /proc/self/mounts ] || die "/proc not mounted or unreadable"

for _c in uname id awk grep mv mkdir chmod chown; do
    need_cmd "$_c"
done

# Prefer curl, fall back to wget
if have_cmd curl; then
    _fetch() { curl -fsSL --retry 3 --retry-delay 2 "$1" -o "$2"; }
elif have_cmd wget; then
    _fetch() { wget -q --tries=3 -O "$2" "$1"; }
else
    die "neither curl nor wget found — install one to continue"
fi

# sha256 verification
if have_cmd sha256sum; then
    HAVE_SHA=1
elif have_cmd shasum; then
    HAVE_SHA=1
    sha256sum() { shasum -a 256 "$@"; }
else
    HAVE_SHA=0
    warn "sha256sum/shasum not found — checksum verification will be skipped"
fi

# ELF inspection
HAVE_FILE=0
have_cmd file && HAVE_FILE=1 || warn "'file' not found — skipping ELF sanity check"

ok "Preflight passed"

# ---------------------------------------------------------------------------
# Detection
# ---------------------------------------------------------------------------
step "Detecting environment"

ARCH="$(detect_arch)"
FAMILY="$(detect_distro_family)"

if [ -n "${FORCE_LIBC:-}" ]; then
    case "$FORCE_LIBC" in
        musl|glibc) LIBC="$FORCE_LIBC" ;;
        *) die "FORCE_LIBC must be 'musl' or 'glibc', got: ${FORCE_LIBC}" ;;
    esac
else
    LIBC="$(detect_libc)"
fi

info "CPU arch:       ${BOLD}${ARCH}${RST}"
if [ -n "${FORCE_LIBC:-}" ]; then
    info "C runtime:      ${BOLD}${LIBC}${RST} (forced)"
else
    info "C runtime:      ${BOLD}${LIBC}${RST} (auto-detected)"
fi
info "Distro family:  ${BOLD}${FAMILY}${RST}"

# Derive artifact names
SAFEXEC_BIN="safexec-${ARCH}-linux-musl"
SHIM_NAME="libnpp_norm-${ARCH}-${LIBC}.so"

info "safexec binary: ${BOLD}${SAFEXEC_BIN}${RST}"
info "shim artifact:  ${BOLD}${SHIM_NAME}${RST}"

# Library install directory
if [ -n "${INSTALL_LIB:-}" ]; then
    LIB_DIR="$INSTALL_LIB"
    info "shim lib dir:   ${BOLD}${LIB_DIR}${RST} (override)"
else
    LIB_DIR="$(detect_lib_dir "$ARCH" "$LIBC" "$FAMILY")"
    info "shim lib dir:   ${BOLD}${LIB_DIR}${RST} (auto)"
fi

SHIM_DEST="${LIB_DIR}/libnpp_norm.so"

# ---------------------------------------------------------------------------
# Filesystem checks on target directories
# ---------------------------------------------------------------------------
step "Filesystem checks"

BIN_DIR="$(dir_abs "$(dirname "$INSTALL_BIN")")"
mkdir -p "$BIN_DIR" 2>/dev/null || true

if fs_has_flag "$BIN_DIR" nosuid; then
    if [ "$ALLOW_NOSUID" = "1" ]; then
        warn "nosuid detected on ${BIN_DIR} — safexec will run without setuid (reduced isolation)"
    else
        die "filesystem ${BIN_DIR} is mounted with 'nosuid' (setuid will not work).
  Fix: remount without nosuid, or set ALLOW_NOSUID=1 to proceed anyway."
    fi
fi

if fs_has_flag "$BIN_DIR" noexec; then
    die "filesystem ${BIN_DIR} is mounted with 'noexec' — cannot execute binaries here"
fi

ok "Filesystem checks passed"

# ---------------------------------------------------------------------------
# Environment info (non-fatal)
# ---------------------------------------------------------------------------
step "Environment info"

if [ -r /sys/fs/cgroup/cgroup.controllers ]; then
    ok "cgroup v2 detected"
else
    info "cgroup v2 not found — isolation will fall back to rlimits"
fi

if getent passwd nobody >/dev/null 2>&1 \
   || id -u nobody >/dev/null 2>&1; then
    ok "'nobody' user exists"
else
    warn "'nobody' user not found — safexec will fall back to the PHP-FPM worker user"
fi

if [ -L "$INSTALL_BIN" ]; then
    warn "${INSTALL_BIN} is currently a symlink — it will be replaced"
fi

# ---------------------------------------------------------------------------
# Download
# ---------------------------------------------------------------------------
step "Downloading artifacts"

TMPDIR_WORK="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_WORK"' EXIT INT TERM

TMP_BIN="${TMPDIR_WORK}/${SAFEXEC_BIN}"
TMP_SHIM="${TMPDIR_WORK}/${SHIM_NAME}"

# --- safexec binary ---
URL_BIN="${BASE_URL}/${SAFEXEC_BIN}"
URL_BIN_SHA="${BASE_URL}/${SAFEXEC_BIN}.sha256"
info "Fetching ${BOLD}${SAFEXEC_BIN}${RST}"
_fetch "$URL_BIN" "$TMP_BIN" || die "download failed: ${URL_BIN}"

# --- shim ---
URL_SHIM="${BASE_URL}/${SHIM_NAME}"
URL_SHIM_SHA="${BASE_URL}/${SHIM_NAME}.sha256"
info "Fetching ${BOLD}${SHIM_NAME}${RST}"
_fetch "$URL_SHIM" "$TMP_SHIM" || die "download failed: ${URL_SHIM}"

ok "Downloads complete"

# ---------------------------------------------------------------------------
# Checksum verification
# ---------------------------------------------------------------------------
step "Verifying checksums"

_verify() {
    _file="$1"
    _url_sha="$2"
    _name="$(basename "$_file")"
    _sha_file="${TMPDIR_WORK}/${_name}.sha256"

    if [ "$SKIP_CHECKSUM" = "1" ]; then
        warn "checksum verification skipped (SKIP_CHECKSUM=1)"
        return 0
    fi

    if [ "$HAVE_SHA" -eq 0 ]; then
        warn "no sha256sum available — skipping verification for ${_name}"
        return 0
    fi

    if _fetch "$_url_sha" "$_sha_file" 2>/dev/null; then
        # .sha256 files contain: "<hash>  <filename>" or just "<hash>"
        _expected="$(awk '{print $1}' "$_sha_file")"
        _got="$(sha256sum "$_file" | awk '{print $1}')"
        if [ "$_expected" = "$_got" ]; then
            ok "${_name}: sha256 OK"
        else
            die "checksum MISMATCH for ${_name}
  expected: ${_expected}
  got:      ${_got}"
        fi
    else
        warn "no checksum file at ${_url_sha} — skipping verification"
    fi
}

_verify "$TMP_BIN"  "$URL_BIN_SHA"
_verify "$TMP_SHIM" "$URL_SHIM_SHA"

# ---------------------------------------------------------------------------
# ELF sanity check
# ---------------------------------------------------------------------------
step "ELF sanity check"

if [ "$HAVE_FILE" -eq 1 ]; then
    _elf_check() {
        _f="$1"; _label="$2"; _pat="$3"
        _desc="$(file -b "$_f" 2>/dev/null || true)"
        info "${_label}: ${_desc}"
        case "$ARCH" in
            x86_64)
                printf '%s' "$_desc" | grep -qi 'ELF 64-bit.*x86-64' \
                    || die "${_label} is not an x86_64 ELF binary"
                ;;
            aarch64)
                printf '%s' "$_desc" | grep -qi 'ELF 64-bit.*aarch64\|ARM aarch64' \
                    || die "${_label} is not an aarch64 ELF binary"
                ;;
        esac
        # Verify safexec is statically linked
        if [ "$_pat" = "static" ]; then
            printf '%s' "$_desc" | grep -qi 'statically linked\|static-pie linked' \
                || warn "${_label} does not appear to be statically linked"
        fi
        # Verify shim is a shared object
        if [ "$_pat" = "shared" ]; then
            printf '%s' "$_desc" | grep -qi 'shared object\|dynamically linked' \
                || warn "${_label} does not appear to be a shared object"
        fi
    }
    _elf_check "$TMP_BIN"  "safexec" "static"
    _elf_check "$TMP_SHIM" "shim"    "shared"
    ok "ELF checks passed"
else
    info "Skipped (no 'file' command)"
fi

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------
step "Installing"

# Atomic install helper: stage to tmp file in same dir, then mv
_atomic_install() {
    _src="$1"; _dst="$2"; _mode="$3"
    _dst_dir="$(dirname "$_dst")"
    mkdir -p "$_dst_dir"
    _stage="${_dst_dir}/.$(basename "$_dst").new.$$"

    if have_cmd install; then
        # install(1) sets owner+perms atomically before we mv
        install -m "$_mode" -o root -g root "$_src" "$_stage"
    else
        cp "$_src" "$_stage"
        chown root:root "$_stage"
        chmod "$_mode" "$_stage"
    fi
    mv -f "$_stage" "$_dst"
}

# --- safexec binary ---
if fs_has_flag "$BIN_DIR" nosuid; then
    _safexec_mode="0755"
    info "Installing safexec without setuid (nosuid mount)"
else
    _safexec_mode="4755"
fi
_atomic_install "$TMP_BIN" "$INSTALL_BIN" "$_safexec_mode"
ok "safexec → ${INSTALL_BIN} (mode ${_safexec_mode})"

# --- shim ---
mkdir -p "$LIB_DIR"
_atomic_install "$TMP_SHIM" "$SHIM_DEST" "0755"
ok "shim    → ${SHIM_DEST}"

# --- stable symlink /usr/lib/npp/libnpp_norm.so ---
# Alpine: LIB_DIR is already /usr/lib/npp so the shim IS the target;
# no separate symlink needed. For all other families, create the symlink.
if [ "$SHIM_DEST" != "$NPP_SYMLINK" ]; then
    mkdir -p "$NPP_SYMLINK_DIR"
    ln -snf "$SHIM_DEST" "$NPP_SYMLINK"
    ok "symlink → ${NPP_SYMLINK} → ${SHIM_DEST}"
else
    ok "symlink not needed (shim already at ${NPP_SYMLINK})"
fi

# ---------------------------------------------------------------------------
# Smoke test
# ---------------------------------------------------------------------------
step "Smoke test"

if "$INSTALL_BIN" --version >/dev/null 2>&1; then
    _ver="$("$INSTALL_BIN" --version 2>/dev/null | head -1 || true)"
    ok "safexec --version: ${_ver}"
elif "$INSTALL_BIN" --help >/dev/null 2>&1; then
    ok "safexec --help: OK"
else
    warn "basic self-test failed — binary installed but may not be functional"
    warn "run manually: ${INSTALL_BIN} --version"
fi

# Verify shim is readable and looks like a shared library
if [ -r "$NPP_SYMLINK" ]; then
    ok "shim symlink readable: ${NPP_SYMLINK}"
else
    warn "shim symlink not readable: ${NPP_SYMLINK}"
fi

# Verify setuid bit stuck (only meaningful if not nosuid)
if ! fs_has_flag "$BIN_DIR" nosuid; then
    _perms="$(ls -la "$INSTALL_BIN" 2>/dev/null | awk '{print $1}' || true)"
    case "$_perms" in
        *s*) ok "setuid bit confirmed: ${_perms}" ;;
        *)   warn "setuid bit may not be set (${_perms}) — check mount options and filesystem" ;;
    esac
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf "\n"
printf "%s══════════════════ Installation Summary ══════════════════%s\n" "$BOLD" "$RST"
printf "  %-20s %s\n" "Architecture:"   "${ARCH}"
printf "  %-20s %s\n" "C runtime:"      "${LIBC}"
printf "  %-20s %s\n" "Distro family:"  "${FAMILY}"
printf "  %-20s %s\n" "safexec:"        "${INSTALL_BIN} (${_safexec_mode})"
printf "  %-20s %s\n" "shim (canonical):" "${SHIM_DEST}"
printf "  %-20s %s\n" "shim (symlink):"  "${NPP_SYMLINK}"

if fs_has_flag "$BIN_DIR" nosuid; then
    printf "  %-20s %s\n" "nosuid:"  "YES — setuid disabled, reduced isolation"
else
    printf "  %-20s %s\n" "nosuid:"  "no"
fi

if [ -r /sys/fs/cgroup/cgroup.controllers ]; then
    printf "  %-20s %s\n" "cgroup v2:"  "available"
else
    printf "  %-20s %s\n" "cgroup v2:"  "not available (rlimit fallback)"
fi

if getent passwd nobody >/dev/null 2>&1 || id -u nobody >/dev/null 2>&1; then
    printf "  %-20s %s\n" "nobody user:"  "present"
else
    printf "  %-20s %s\n" "nobody user:"  "MISSING (FPM user fallback)"
fi

printf "%s═══════════════════════════════════════════════════════════%s\n" "$BOLD" "$RST"
printf "\n"

ok "Done. safexec installed successfully."
printf "\n"
