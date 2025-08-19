#!/usr/bin/env sh
set -eu

# ---- Config ----
BASE_URL="${BASE_URL:-https://raw.githubusercontent.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/main/safexec}"
INSTALL_PATH="${INSTALL_PATH:-/usr/local/bin/safexec}"
ALLOW_NOSUID="${ALLOW_NOSUID:-0}"        # set to 1 to allow install on nosuid fs (will run without setuid)
SKIP_CHECKSUM="${SKIP_CHECKSUM:-0}"      # set to 1 to skip checksum verification even if available

# ---- Colors & UI (auto-disable on non-TTY or NO_COLOR; FORCE_COLOR=1 to force) ----
if { [ -t 1 ] || [ "${FORCE_COLOR:-0}" = "1" ]; } \
   && [ -z "${NO_COLOR:-}" ] \
   && [ "${TERM:-dumb}" != "dumb" ]; then
  BOLD="$(printf '\033[1m')"; CYAN="$(printf '\033[36m')"; RESET="$(printf '\033[0m')"
else
  BOLD=; CYAN=; RESET=
fi

# ---- Helpers ----
die()  { printf >&2 "Error: %s\n" "$*"; exit 1; }
warn() { printf >&2 "Warning: %s\n" "$*"; }

need_cmd() { command -v "$1" >/dev/null 2>&1 || die "missing required command: $1"; }
is_linux() { [ "$(uname -s 2>/dev/null)" = "Linux" ]; }

arch_triplet() {
  m="$(uname -m 2>/dev/null || true)"
  case "$m" in
    x86_64|amd64)   echo "x86_64-linux-musl"  ;;
    aarch64|arm64)  echo "aarch64-linux-musl" ;;
    *) die "unsupported arch: $m" ;;
  esac
}

# Find mount options for the filesystem containing $1 (prefix-longest match)
mount_opts_for() {
  tgt="$1"
  awk -v p="$tgt" '
    BEGIN{L=0;O=""}
    {
      mp=$2; gsub("\\\\040"," ",mp);
      if (index(p, mp)==1 && length(mp)>L) { L=length(mp); O=$4; }
    }
    END{print O}
  ' /proc/self/mounts 2>/dev/null
}

fs_has_nosuid() {
  opts="$(mount_opts_for "$1")"
  [ -n "$opts" ] && printf "%s" "$opts" | tr ',' '\n' | grep -qx nosuid
}

# Portable dirname->abs path
dir_abs() {
  _d="$1"
  [ -z "$_d" ] && _d=.
  ( cd "$_d" 2>/dev/null && pwd -P ) || echo "$_d"
}

# ---- Preflight ----
is_linux || die "this installer is Linux-only"

for c in uname curl chmod chown awk grep sed stat mv mkdir; do
  need_cmd "$c"
done

# arch sanity check:
if command -v file >/dev/null 2>&1; then
  HAVE_FILE=1
else
  HAVE_FILE=0
  warn "'file' command not found; skipping ELF/arch sanity check"
fi

# sha256 verification if checksum file is present upstream
if command -v sha256sum >/dev/null 2>&1; then
  HAVE_SHA=1
else
  HAVE_SHA=0
fi

[ -r /proc/self/status ] || die "/proc not mounted or unreadable (needed for --kill mode)"

TRIPLET="$(arch_triplet)"
FILE="safexec-${TRIPLET}"
URL_BIN="${BASE_URL}/${FILE}"
URL_SHA="${URL_BIN}.sha256"

# nosuid check on the target filesystem
TARGET_DIR="$(dir_abs "$(dirname "$INSTALL_PATH")")"
if fs_has_nosuid "$TARGET_DIR"; then
  if [ "$ALLOW_NOSUID" != "1" ]; then
    die "filesystem for ${TARGET_DIR} is mounted with 'nosuid' (setuid will NOT work).
Hint: re-mount without nosuid or set ALLOW_NOSUID=1 to install anyway (reduced isolation)."
  else
    warn "nosuid detected on ${TARGET_DIR}; safexec will run without setuid privileges (reduced isolation)."
  fi
fi

# cgroup v2 info (just informational)
if [ -r /sys/fs/cgroup/cgroup.controllers ]; then
  echo "Info: cgroup v2 detected."
else
  echo "Info: cgroup v2 not detected (feature will be a no-op)."
fi

# 'nobody' user info
if ! (getent passwd nobody >/dev/null 2>&1 || id -u nobody >/dev/null 2>&1); then
  warn "no 'nobody' user found; safexec will fall back to the FPM user."
fi

# must be root to install setuid root
[ "$(id -u)" -eq 0 ] || die "please run as root (or with sudo) to install to ${INSTALL_PATH}"

# ---- download ----
tmp="$(mktemp)"
tmp_sha=""
trap 'rm -f "$tmp" "$tmp_sha"' EXIT

echo "Fetching ${BOLD}${CYAN}safexec${RESET} ..."
curl -fsSL "$URL_BIN" -o "$tmp" || die "download failed: ${URL_BIN}"

# Optional checksum verify (only if available and not skipped)
if [ "$SKIP_CHECKSUM" != "1" ] && [ "$HAVE_SHA" -eq 1 ]; then
  if tmp_sha="$(mktemp)"; curl -fsSL "$URL_SHA" -o "$tmp_sha"; then
    echo "Verifying SHA256 ..."
    want="$(cut -d ' ' -f1 "$tmp_sha" | tr -d '\r\n')"
    have="$(sha256sum "$tmp" | awk '{print $1}')"
    [ -n "$want" ] || die "empty checksum file at ${URL_SHA}"
    [ "$want" = "$have" ] || die "checksum mismatch for ${FILE}"
  else
    warn "no checksum file found at ${URL_SHA}; continuing without verification"
  fi
elif [ "$SKIP_CHECKSUM" != "1" ]; then
  warn "sha256sum not found; skipping checksum verification"
fi

# Basic sanity: ELF + arch (if 'file' exists)
if [ "$HAVE_FILE" -eq 1 ]; then
  desc="$(file -b "$tmp" || true)"
  printf "Binary looks like: %s\n" "$desc"
  case "$TRIPLET" in
    x86_64-*)  echo "$desc" | grep -qi 'ELF 64-bit .*x86-64'  || die "downloaded binary is not x86_64 ELF" ;;
    aarch64-*) echo "$desc" | grep -qi 'ELF 64-bit .*aarch64' || die "downloaded binary is not aarch64 ELF" ;;
  esac
fi

# ---- install atomically ----
mkdir -p "$TARGET_DIR"
tmp_in_place="${TARGET_DIR}/.$(basename "$INSTALL_PATH").new.$$"

# copy into target fs, then set owner+mode, then rename atomically
cp "$tmp" "$tmp_in_place"
chown root:root "$tmp_in_place"

# set mode (setuid only useful if filesystem allows it)
if fs_has_nosuid "$TARGET_DIR"; then
  chmod 0755 "$tmp_in_place"
else
  chmod 4755 "$tmp_in_place"
fi

mv -f "$tmp_in_place" "$INSTALL_PATH"

# ---- quick smoke test ----
if "$INSTALL_PATH" --version >/dev/null 2>&1; then
  echo "Smoke test: --version OK"
elif "$INSTALL_PATH" --help >/dev/null 2>&1; then
  echo "Smoke test: --help OK"
else
  warn "binary installed but a basic self-test failed; please run '$INSTALL_PATH --help' manually."
fi

# ---- summary ----
echo "Summary:"
echo "  Arch:        ${TRIPLET}"
echo "  Installed:   ${INSTALL_PATH}"
if fs_has_nosuid "$TARGET_DIR"; then
  echo "  nosuid:      yes (setuid disabled)"
else
  echo "  nosuid:      no (setuid enabled)"
fi
[ -r /sys/fs/cgroup/cgroup.controllers ] && echo "  cgroup v2:   yes" || echo "  cgroup v2:   no"
( getent passwd nobody >/dev/null 2>&1 || id -u nobody >/dev/null 2>&1 ) && echo "  nobody user: yes" || echo "  nobody user: no"
echo "Installed."
