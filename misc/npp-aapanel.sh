#!/usr/bin/env bash
# =============================================================================
#  BETA-3: NPP Infrastructure Setup for aaPanel
# =============================================================================
#  Nginx Cache Purge Preload (NPP) — Complete environment bootstrap
#  for aaPanel-managed WordPress installations.
#
#  What this script does:
#    1.  Validates the aaPanel environment and the supplied WordPress path
#    2.  Cross-references the WP path with aaPanel's SQLite DB to confirm the
#        site is registered and to auto-detect the active PHP version
#    3.  Removes all NPP-required functions from PHP disable_functions in the
#        matching php.ini and reloads PHP-FPM
#    4.  Checks / installs ripgrep >= 14.0.0 (latest available) from GitHub
#        releases (DEB for Debian/Ubuntu; static musl tar.gz for RPM distros)
#    5.  Installs safexec via the official one-liner installer
#    6.  Ensures GNU Wget >= 1.16 is present (not wget2 / busybox)
#    7.  Downloads WP-CLI, renames it to `wp`, installs to /usr/local/bin/wp
#    8.  Installs and activates the NPP plugin
#    9.  Verifies all binaries and PHP functions are operational
#   10.  Configures NPP nginx cache path and flushes plugin transients
#
#  Usage:
#    sudo bash npp-aapanel.sh                                     # interactive site picker
#    sudo bash npp-aapanel.sh /www/wwwroot/your-wordpress-site    # direct path (non-interactive)
#
#  Requirements:
#    • Must be run as root
#    • aaPanel must be installed
#    • sqlite3 CLI must be present
#    • Internet access for downloads
#
#  Supported distros
#    Ubuntu 18.04 / 20.04 / 22.04 / 24.04
#    Debian 10 / 11 / 12
#    CentOS 7 / 8 · AlmaLinux 8/9 · Rocky Linux 8/9 · Fedora 38+
#
# =============================================================================

set -euo pipefail
IFS=$'\n\t'

# ---------------------------------------------------------------------------
# Strict error trap — print line number on unexpected exit
# ---------------------------------------------------------------------------
trap 'on_error $LINENO' ERR
on_error() {
    echo ""
    echo "${RED}${BOLD}✗ Script aborted at line $1 — see error above.${RST}"
    echo ""
    exit 1
}

# =============================================================================
# COLOR PALETTE & OUTPUT HELPERS
# =============================================================================
if [[ -t 1 ]] && [[ -z "${NO_COLOR:-}" ]]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    RED=$'\033[31m'
    GRN=$'\033[32m'
    YLW=$'\033[33m'
    BLU=$'\033[34m'
    MAG=$'\033[35m'
    CYN=$'\033[36m'
    WHT=$'\033[97m'
    RST=$'\033[0m'
else
    BOLD='' DIM='' RED='' GRN='' YLW='' BLU='' MAG='' CYN='' WHT='' RST=''
fi

# Banner width
WIDTH=72

_line() { printf '%s\n' "${DIM}$(printf '─%.0s' $(seq 1 $WIDTH))${RST}"; }
_blank() { echo ""; }

_banner() {
    _blank
    echo "${MAG}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    printf "${MAG}${BOLD}%-${WIDTH}s${RST}\n" "  NPP × aaPanel — Infrastructure Setup"
    printf "${DIM}%-${WIDTH}s${RST}\n"        "  Nginx Cache Purge Preload · for WordPress · v${SCRIPT_VERSION}"
    echo "${MAG}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    _blank
}

_section() {
    _blank
    echo "${CYN}${BOLD}▶ $1${RST}"
    _line
}

_ok()   { echo "  ${GRN}${BOLD}✔${RST}  $1"; }
_info() { echo "  ${BLU}ℹ${RST}  $1"; }
_warn() { echo "  ${YLW}${BOLD}⚠${RST}  $1"; }
_fail() { echo "  ${RED}${BOLD}✗${RST}  $1"; }
_step() { echo "  ${MAG}→${RST}  $1"; }

_done_summary() {
    _blank
    echo "${GRN}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    printf "${GRN}${BOLD}  %-$((WIDTH-2))s${RST}\n" "✔  Setup complete — NPP is ready to use!"
    echo "${GRN}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    _blank
}

_print_csv_table() {
    # Render a comma-separated list as a multi-column table within WIDTH.
    # Usage: _print_csv_table "Label" "comma,separated,values"
    local label="$1"
    local csv="${2:-}"
    local indent="     "                       # 5 spaces — aligns under icon
    local avail=$(( WIDTH - ${#indent} ))
    local -a items=()
    local count=0
    if [[ -n "${csv}" ]]; then
        local -a _raw=()
        IFS=',' read -ra _raw <<< "${csv}" || true
        local _it
        for _it in "${_raw[@]}"; do
            _it="${_it//[[:space:]]/}"         # strip all internal whitespace
            if [[ -n "${_it}" ]]; then
                items+=("${_it}")
                count=$(( count + 1 ))
            fi
        done
    fi
    if [[ ${count} -eq 0 ]]; then
        echo "  ${BLU}ℹ${RST}  ${label}: ${DIM}(empty / not set)${RST}"
        return 0
    fi
    echo "  ${BLU}ℹ${RST}  ${label} ${DIM}(${count})${RST}:"
    local max_len=0 _it
    for _it in "${items[@]}"; do
        if [[ ${#_it} -gt ${max_len} ]]; then max_len=${#_it}; fi
    done
    local col_w=$(( max_len + 2 ))             # +2 gutter between columns
    local cols=$(( avail / col_w ))
    if [[ ${cols} -lt 1 ]]; then cols=1; fi
    local col_idx=0 row_buf=""
    for _it in "${items[@]}"; do
        row_buf+="$(printf "%-${col_w}s" "${_it}")"
        col_idx=$(( col_idx + 1 ))
        if [[ $(( col_idx % cols )) -eq 0 ]]; then
            echo "${indent}${DIM}${row_buf}${RST}"
            row_buf=""
        fi
    done
    if [[ -n "${row_buf}" ]]; then
        echo "${indent}${DIM}${row_buf}${RST}"
    fi
}

# =============================================================================
# GLOBAL STATE TRACKING (for final summary)
# =============================================================================
declare -a SUMMARY_LINES=()
_track() { SUMMARY_LINES+=("$1"); }

# =============================================================================
# CONSTANTS
# =============================================================================
readonly AAPANEL_DB="/www/server/panel/data/default.db"
readonly AAPANEL_PHP_BASE="/www/server/php"
readonly AAPANEL_WEB_USER="www"
readonly WP_CLI_URL="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
readonly WP_CLI_BIN="/usr/local/bin/wp"
readonly SAFEXEC_INSTALL_URL="https://psaux-it.github.io/install-safexec.sh"
readonly NPP_PLUGIN_SLUG="fastcgi-cache-purge-and-preload-nginx"
readonly RG_MIN_VERSION="14.0.0"
readonly RG_INSTALL_VERSION="15.1.0"    # pinned latest; update as new versions release
readonly WGET_MIN_VERSION="1.16"

# Functions that MUST be removed from disable_functions for NPP to work:
readonly -a NPP_REQUIRED_FUNCS=(
    "shell_exec"      # preload/purge/watchdog — primary execution path
    "exec"            # ripgrep invocation, kill fallback
    "proc_open"       # preload process spawning + status detection
    "proc_close"      # paired with every proc_open
    "proc_get_status" # live process status check in preload
    "putenv"          # safexec env vars (SAFEXEC_QUIET, SAFEXEC_DETACH, PATH)
    "getenv"          # PATH read in nppp_setup_safexec_env(); nginx include parser
)

# Shell tool sets:
#   global  (true,false) : ps grep awk sort uniq sed   — ALL plugin actions fail if missing
#   preload (false,true) : nohup wget                  — Preload fails if missing
# ripgrep + safexec are OPTIONAL; handled in their own install steps.
# wget version/type validation is handled entirely in Step 6 (ensure_gnu_wget).
readonly -a NPP_GLOBAL_TOOLS=( "ps" "grep" "awk" "sort" "uniq" "sed" )
readonly -a NPP_PRELOAD_TOOLS=( "nohup" "wget" )

# Static paths NPP needs reachable under open_basedir
readonly -a NPP_OPEN_BASEDIR_STATIC_PATHS=(
    "/tmp/"
    "/proc/"
    "/dev/null"
    "/www/server/nginx/conf/"
    "/www/server/panel/vhost/nginx"
    "/usr/bin/"
    "/usr/sbin/"
    "/usr/local/bin/"
    "/usr/local/sbin/"
    "/bin/"
    "/sbin/"
)

# Minimum versions for sanity checks
readonly SCRIPT_VERSION="2.1.7"

# =============================================================================
# HELPERS — GENERAL UTILITIES
# =============================================================================

# Check if a command exists in PATH
have() { command -v "$1" &>/dev/null; }

# Run a command as the aaPanel web user (www)
run_as_www() {
    sudo -u "${AAPANEL_WEB_USER}" -- "$@"
}

# Version comparison: returns 0 if $1 >= $2
ver_gte() {
    # Sort both versions; if the higher one is $1 (or equal), return true
    [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" == "$2" ]]
}

# Detect CPU architecture → ripgrep/safexec naming convention
detect_arch() {
    local raw; raw="$(uname -m)"
    case "${raw}" in
        x86_64|amd64)  echo "x86_64" ;;
        aarch64|arm64) echo "aarch64" ;;
        *)
            _fail "Unsupported architecture: ${raw}"
            exit 1
            ;;
    esac
}

# Detect OS family
detect_os_family() {
    if [[ -f /etc/debian_version ]]; then
        echo "debian"
    elif [[ -f /etc/redhat-release ]] || [[ -f /etc/centos-release ]] || [[ -f /etc/fedora-release ]]; then
        echo "rhel"
    else
        echo "unknown"
    fi
}

# Safe sqlite3 query — exits with clear error if sqlite3 is not installed
sqlite_q() {
    local db="$1"; shift
    sqlite3 -separator '|' "${db}" "$@"
}

# =============================================================================
# INTERACTIVE SITE PICKER — populated from aaPanel's sites table
# =============================================================================
select_site_interactive() {
    _section "WordPress Site Selection"

    _step "Querying aaPanel for registered sites …"
    local sites_raw
    sites_raw="$(sqlite_q "${AAPANEL_DB}" "SELECT name, path FROM sites ORDER BY id;" 2>/dev/null || true)"

    if [[ -z "${sites_raw}" ]]; then
        _fail "No sites found in aaPanel database (${AAPANEL_DB})."
        _info "Add a site in aaPanel first, or pass the WordPress path directly:"
        _info "  sudo bash npp-aapanel.sh /www/wwwroot/your-site"
        exit 1
    fi

    local -a site_names=()
    local -a site_paths=()
    local -a site_wp=()
    local name path

    while IFS='|' read -r name path; do
        [[ -z "${name}" ]] && continue
        site_names+=("${name}")
        site_paths+=("${path}")
        if [[ -f "${path}/wp-config.php" ]]; then
            site_wp+=("yes")
        else
            site_wp+=("no")
        fi
    done <<< "${sites_raw}"

    local count=${#site_names[@]}
    if [[ ${count} -eq 0 ]]; then
        _fail "No sites found in aaPanel database."
        exit 1
    fi

    _blank
    echo "  ${WHT}${BOLD}Select the WordPress site to configure for NPP:${RST}"
    _blank

    local i marker
    for ((i=0; i<count; i++)); do
        if [[ "${site_wp[$i]}" == "yes" ]]; then
            marker="${GRN}●${RST}"
        else
            marker="${DIM}○${RST}"
        fi
        printf "    ${MAG}${BOLD}%2d)${RST} %b  ${WHT}%-32s${RST} ${DIM}%s${RST}\n" \
            "$((i+1))" "${marker}" "${site_names[$i]}" "${site_paths[$i]}"
    done

    _blank
    echo "  ${DIM}● = WordPress detected (wp-config.php found)    ○ = not detected${RST}"
    _blank

    local choice
    while true; do
        read -r -p "  ${CYN}${BOLD}Enter the number of the site to set up [1-${count}]: ${RST}" choice </dev/tty
        if [[ "${choice}" =~ ^[0-9]+$ ]] && (( choice >= 1 && choice <= count )); then
            break
        fi
        _warn "Invalid selection. Enter a number between 1 and ${count}."
    done

    local sel=$((choice - 1))
    WP_PATH="${site_paths[${sel}]}"
    SITE_DOMAIN="${site_names[${sel}]}"

    # Strip trailing slashes for consistency with the rest of the script
    while [[ "${WP_PATH}" == */ ]]; do
        WP_PATH="${WP_PATH%/}"
    done
    [[ -z "${WP_PATH}" ]] && WP_PATH="/"

    _blank
    _ok "Selected: ${WHT}${SITE_DOMAIN}${RST} → ${WHT}${WP_PATH}${RST}"
    _track "Site selected interactively: ${SITE_DOMAIN} (${WP_PATH})"
}

# =============================================================================
# STEP 0 — PRE-FLIGHT CHECKS
# =============================================================================
preflight_checks() {
    _section "Pre-flight Checks"

    # 0.1 Must be root
    if [[ ${EUID} -ne 0 ]]; then
        _fail "This script must be run as root. Use: sudo bash npp-aapanel.sh <wp-path>"
        exit 1
    fi
    _ok "Running as root"

    # 0.2 Argument provided
    WP_PATH=""
    if [[ $# -ge 1 ]]; then
        # Strip ALL trailing slashes (handles /path, /path/, /path//, etc.)
        # so SQL lookups, vhost-root grep matching, and path concatenation
        # throughout the script all see one canonical form.
        WP_PATH="${1}"
        while [[ "${WP_PATH}" == */ ]]; do
            WP_PATH="${WP_PATH%/}"
        done
        # Guard against a root-only input ("/" or "//") collapsing to empty
        if [[ -z "${WP_PATH}" ]]; then
            WP_PATH="/"
        fi
        _ok "WordPress path argument: ${WHT}${WP_PATH}${RST}"
    fi

    # 0.3 aaPanel detection
    local bt_found=false
    if [[ -f /usr/bin/bt ]] || [[ -f /etc/init.d/bt ]]; then
        bt_found=true
    elif have bt; then
        bt_found=true
    fi

    if [[ "${bt_found}" == false ]]; then
        _fail "aaPanel (bt) not detected on this server."
        _info "Expected: /usr/bin/bt  or  /etc/init.d/bt"
        exit 1
    fi
    _ok "aaPanel installation detected"

    # 0.4 aaPanel SQLite DB exists
    if [[ ! -f "${AAPANEL_DB}" ]]; then
        _fail "aaPanel database not found: ${AAPANEL_DB}"
        exit 1
    fi
    _ok "aaPanel database present: ${DIM}${AAPANEL_DB}${RST}"

    # 0.5 Ensure sqlite3 CLI is available
    if ! have sqlite3; then
        _step "sqlite3 not found — installing …"
        local os_fam; os_fam="$(detect_os_family)"
        case "${os_fam}" in
            debian) apt-get install -y -q sqlite3 &>/dev/null || true ;;
            rhel)
                if have dnf; then
                    dnf install -y sqlite &>/dev/null || true
                else
                    yum install -y sqlite &>/dev/null || true
                fi
                ;;
            *)
                _fail "Cannot auto-install sqlite3 on this OS. Install it manually."
                exit 1
                ;;
        esac

        # Re-check after install attempt — give a clear error rather than
        # failing later with a confusing "command not found".
        if ! have sqlite3; then
            _fail "Failed to install sqlite3. Install it manually and re-run."
            exit 1
        fi
    fi
    _ok "sqlite3 CLI available: $(sqlite3 --version | head -1)"

    # 0.6 Internet connectivity check (non-fatal warning)
    if ! curl -fsS --max-time 5 https://api.github.com/zen &>/dev/null; then
        _warn "Cannot reach api.github.com — downloads may fail."
    else
        _ok "Internet connectivity confirmed"
    fi

    # 0.7 curl and tar must be available (used for downloads)
    for bin in curl tar; do
        if ! have "${bin}"; then
            _fail "Required tool not found: ${bin}. Install it before running this script."
            exit 1
        fi
    done
    _ok "Required tools (curl, tar) present"

    # 0.8 No path/domain argument given — show interactive site picker now
    # that aaPanel's DB (0.4) and sqlite3 (0.5) are confirmed available.
    if [[ -z "${WP_PATH}" ]]; then
        select_site_interactive
    fi
}

# =============================================================================
# STEP 1 — VALIDATE WORDPRESS PATH + AAPANEL SQLite CROSS-REFERENCE
# =============================================================================
validate_wp_path() {
    _section "WordPress Path Validation"

    # 1.1 Directory must exist
    if [[ ! -d "${WP_PATH}" ]]; then
        _fail "WordPress path does not exist: ${WP_PATH}"
        exit 1
    fi

    # 1.2 wp-config.php must exist
    if [[ ! -f "${WP_PATH}/wp-config.php" ]]; then
        _fail "wp-config.php not found in: ${WP_PATH}"
        _info "Make sure the path points to the WordPress root directory."
        exit 1
    fi
    _ok "WordPress installation found (wp-config.php present)"

    # 1.3 Cross-reference WP_PATH against aaPanel's sites table.
    _step "Looking up site record in aaPanel database …"
    local row

    # SQLite-side rtrim() on BOTH sides — handles any combination of
    # trailing slashes (single, double, none) from either the user-supplied
    # path or the value stored in aaPanel's DB, without relying on bash's
    # ${1%/} which only strips one trailing slash.
    row="$(sqlite_q "${AAPANEL_DB}" \
        "SELECT name, path FROM sites WHERE rtrim(path, '/') = rtrim('${WP_PATH}', '/') LIMIT 1;" \
        2>/dev/null || true)"

    if [[ -z "${row}" ]]; then
        _warn "Path '${WP_PATH}' not found in aaPanel sites table."
        _info "Registered sites:"
        sqlite_q "${AAPANEL_DB}" \
            "SELECT name, path FROM sites ORDER BY id;" 2>/dev/null \
            | awk -F'|' '{printf "     %-30s %s\n", $1, $2}' || true
        _blank
        _info "Attempting to continue via nginx vhost auto-detection …"
        SITE_DOMAIN="<unknown>"
        SITE_PATH="${WP_PATH}"
    else
        SITE_DOMAIN="$(echo "${row}" | cut -d'|' -f1)"
        SITE_PATH="$(echo "${row}"   | cut -d'|' -f2)"
        _ok "aaPanel site record matched"
        _info "  Domain : ${WHT}${SITE_DOMAIN}${RST}"
        _info "  Path   : ${WHT}${SITE_PATH}${RST}"
    fi

    # 1.4 Verify webserver is nginx via aaPanel config table
    _step "Verifying webserver type via aaPanel config table …"
    local webserver_type
    webserver_type="$(sqlite_q "${AAPANEL_DB}" \
        "SELECT webserver FROM config WHERE id=1;" 2>/dev/null | head -1 || true)"
    if [[ "${webserver_type,,}" == "nginx" ]]; then
        _ok "Webserver confirmed: ${WHT}nginx${RST} (aaPanel config)"
    elif [[ -z "${webserver_type}" ]]; then
        _warn "Could not read webserver type from config table — assuming nginx"
    else
        _fail "aaPanel webserver is '${webserver_type}' — NPP requires nginx."
        _info "Switch to nginx in aaPanel before using this plugin."
        exit 1
    fi

    _track "WordPress path validated: ${WP_PATH} (domain: ${SITE_DOMAIN:-?})"
}

# =============================================================================
# STEP 1B — WEBSERVER ARCHITECTURE DETECTION
# =============================================================================
detect_webserver_arch() {
    _section "Webserver Architecture Detection"

    # Defaults — assume the simplest, most common aaPanel setup until proven otherwise
    WEBSERVER_ARCH="single"
    NPP_CACHE_PATH="/www/server/fastcgi_cache"

    if [[ "${SITE_DOMAIN}" == "<unknown>" ]]; then
        _warn "Site domain unresolved — cannot query aaPanel 'service_type' column"
        _warn "Assuming single-webserver architecture (nginx only)"
        _info "  Nginx cache path: ${WHT}${NPP_CACHE_PATH}${RST}"
        _track "Webserver architecture: single (assumed) | cache path: ${NPP_CACHE_PATH}"
        return 0
    fi

    # 1B.1 Read service_type for the matched site:
    #        - empty / "nginx" → single webserver  (nginx only, FastCGI)
    #        - "apache"        → multi webserver   (nginx proxy + apache backend),
    #                            nginx caches the proxied response per-site
    _step "Querying service_type for site '${SITE_DOMAIN}' …"
    local service_type
    service_type="$(sqlite_q "${AAPANEL_DB}" \
        "SELECT service_type FROM sites WHERE name='${SITE_DOMAIN}' LIMIT 1;" \
        2>/dev/null | head -1 || true)"
    service_type="${service_type,,}"

    case "${service_type}" in
        apache)
            WEBSERVER_ARCH="multi-apache"
            NPP_CACHE_PATH="/www/server/fastcgi_cache/${SITE_DOMAIN}"
            _ok "Multi-webserver architecture detected: ${WHT}nginx (proxy/cache) + apache (backend)${RST}"
            _info "  aaPanel runs this site on Apache, reverse-proxied through Nginx"
            _info "  Nginx caches the proxied response in a per-site subdirectory"
            ;;
        openlitespeed)
            WEBSERVER_ARCH="multi-ols"
            NPP_CACHE_PATH="/www/server/fastcgi_cache/${SITE_DOMAIN}"
            _ok "Multi-webserver architecture detected: ${WHT}nginx (proxy/cache) + OpenLiteSpeed (backend)${RST}"
            _info "  aaPanel runs this site on OpenLiteSpeed, reverse-proxied through Nginx"
            _info "  Nginx caches the proxied response in a per-site subdirectory"
            ;;
        ""|nginx)
            # service_type is empty both for a plain single-nginx (FastCGI) site
            # AND for a multi-webserver nginx+nginx (proxy + backend) setup —
            # aaPanel does not record a distinct service_type for the latter.
            # Fortunately the cache path is identical in both cases, so we
            # don't need to fully disambiguate them here.
            WEBSERVER_ARCH="single-or-multi-nginx"
            NPP_CACHE_PATH="/www/server/fastcgi_cache"
            _ok "Nginx-fronted architecture detected: ${WHT}nginx only, or nginx + nginx (proxy/cache)${RST}"
            _info "  aaPanel does not distinguish single-nginx from nginx+nginx in 'service_type'"
            _info "  Cache path is identical for both — no further action needed"
            ;;
        *)
            WEBSERVER_ARCH="single-or-multi-nginx"
            NPP_CACHE_PATH="/www/server/fastcgi_cache"
            _warn "Unrecognized service_type '${service_type}' for site '${SITE_DOMAIN}'"
            _warn "Falling back to nginx-only cache path assumption"
            ;;
    esac

    _info "  Nginx cache path: ${WHT}${NPP_CACHE_PATH}${RST}"
    _track "Webserver architecture: ${WEBSERVER_ARCH} | cache path: ${NPP_CACHE_PATH}"
}

# =============================================================================
# STEP 2 — DETECT ACTIVE PHP VERSION
# =============================================================================
detect_php_version() {
    _section "PHP Version Detection"

    PHP_VERSION=""   # e.g. "82"  (used to build /www/server/php/82/etc/php.ini)
    PHP_VER_DOT=""   # e.g. "8.2"

    # 2.1 Locate the site's nginx vhost config. This is the ONLY place aaPanel
    #     records a site's PHP version (the sqlite 'sites' table has no such
    #     column). Since each domain can run its own PHP-FPM pool/version,
    #     we must resolve THIS site's vhost — not just grab whatever PHP
    #     happens to be installed on the box.
    local vhost_dir="/www/server/panel/vhost/nginx"
    local vhost_conf=""

    if [[ "${SITE_DOMAIN}" != "<unknown>" ]] && [[ -f "${vhost_dir}/${SITE_DOMAIN}.conf" ]]; then
        vhost_conf="${vhost_dir}/${SITE_DOMAIN}.conf"
        _ok "Vhost config matched via aaPanel DB domain: ${WHT}${SITE_DOMAIN}.conf${RST}"
    else
        # 2.2 Fallback: site not in aaPanel's DB (or its vhost file is
        #     missing/renamed). Search every vhost conf for one whose
        #     document root matches our WordPress path — keeps per-domain
        #     PHP detection correct on multi-site boxes.
        _step "Searching nginx vhost configs for root matching ${WP_PATH} …"
        if [[ -d "${vhost_dir}" ]]; then
            vhost_conf="$(grep -lRE --include='*.conf' \
                "root[[:space:]]+${WP_PATH}(/|;|[[:space:]])" "${vhost_dir}" 2>/dev/null \
                | head -1 || true)"
        fi

        if [[ -n "${vhost_conf}" ]]; then
            _ok "Vhost config matched by root path: ${WHT}$(basename "${vhost_conf}")${RST}"
            [[ "${SITE_DOMAIN}" == "<unknown>" ]] && SITE_DOMAIN="$(basename "${vhost_conf}" .conf)"
        else
            _warn "No nginx vhost config found for ${WP_PATH}"
        fi
    fi

    # 2.3 Parse PHP version from the resolved vhost config.
    #       Pattern 1: include enable-php-83-xxx.conf  (modern aaPanel)
    #       Pattern 2: unix:/tmp/php-cgi-83.sock       (classic aaPanel)
    if [[ -n "${vhost_conf}" ]]; then
        local vhost_ver=""
        vhost_ver="$(grep -oP 'enable-php-\K[0-9]+' "${vhost_conf}" \
            | head -1 || true)"
        if [[ -z "${vhost_ver}" ]]; then
            vhost_ver="$(grep -oP 'php-cgi-\K[0-9]+(?=\.sock)' "${vhost_conf}" \
                | head -1 || true)"
        fi
        if [[ -n "${vhost_ver}" ]]; then
            PHP_VERSION="${vhost_ver}"
            PHP_VER_DOT="$(echo "${PHP_VERSION}" | sed 's/\(.\)/\1./')"
            _ok "PHP version from nginx vhost: ${WHT}${PHP_VER_DOT}${RST}"
        else
            _warn "Could not parse PHP version from: ${vhost_conf}"
        fi
    fi

    # 2.4 Last resort: scan installed PHP versions and pick the newest.
    #     WARNING: on multi-PHP servers this may not match the version the
    #     target site actually runs — only used if vhost detection fails.
    if [[ -z "${PHP_VERSION}" ]]; then
        _step "Scanning installed PHP versions in ${AAPANEL_PHP_BASE} …"
        local detected_ver=""
        if [[ -d "${AAPANEL_PHP_BASE}" ]]; then
            detected_ver="$(ls -1 "${AAPANEL_PHP_BASE}" 2>/dev/null \
                | grep -E '^[0-9]+$' \
                | sort -rn \
                | head -1 || true)"
        fi

        if [[ -z "${detected_ver}" ]]; then
            _fail "No PHP installation found under ${AAPANEL_PHP_BASE}"
            _info "Please install PHP via the aaPanel App Store first."
            exit 1
        fi

        PHP_VERSION="${detected_ver}"
        PHP_VER_DOT="$(echo "${PHP_VERSION}" | sed 's/\(.\)/\1./')"
        _warn "Vhost detection failed; using newest installed PHP: ${PHP_VER_DOT}"
        _warn "If this site uses a different PHP version, verify the nginx vhost config manually."
    fi

    # 2.5 Verify php.ini exists
    PHP_INI="${AAPANEL_PHP_BASE}/${PHP_VERSION}/etc/php.ini"
    if [[ ! -f "${PHP_INI}" ]]; then
        _fail "php.ini not found: ${PHP_INI}"
        exit 1
    fi
    _ok "php.ini path: ${WHT}${PHP_INI}${RST}"

    # 2.6 Verify PHP binary exists
    PHP_BIN="${AAPANEL_PHP_BASE}/${PHP_VERSION}/bin/php"
    if [[ ! -x "${PHP_BIN}" ]]; then
        _warn "PHP binary not found at ${PHP_BIN}"
        # Try to locate it
        PHP_BIN="$(find "${AAPANEL_PHP_BASE}/${PHP_VERSION}" -name 'php' -type f -executable 2>/dev/null | head -1 || true)"
        if [[ -z "${PHP_BIN}" ]]; then
            _fail "Cannot locate PHP binary for version ${PHP_VER_DOT}"
            exit 1
        fi
        _warn "Using alternate PHP binary: ${PHP_BIN}"
    fi
    _ok "PHP binary: ${WHT}${PHP_BIN}${RST}"

    _track "PHP version: ${PHP_VER_DOT} | ini: ${PHP_INI}"
}

# =============================================================================
# STEP 3 — ENABLE REQUIRED PHP FUNCTIONS
# =============================================================================
enable_php_functions() {
    _section "PHP Function Enablement (disable_functions)"

    # 3.1 Read current disable_functions value
    local current_df
    current_df="$(grep -E '^\s*disable_functions\s*=' "${PHP_INI}" 2>/dev/null \
        | tail -1 \
        | sed 's/^\s*disable_functions\s*=\s*//' \
        | tr -d '"' \
        || true)"

    _print_csv_table "Current disable_functions" "${current_df}"

    # 3.2 Convert to array, remove NPP-required functions
    local -a df_array=()
    IFS=',' read -ra df_array <<< "${current_df}"

    local -a new_df_array=()
    local -a removed=()

    for fn in "${df_array[@]}"; do
        fn="$(echo "${fn}" | tr -d ' ')"
        [[ -z "${fn}" ]] && continue

        local keep=true
        for required_fn in "${NPP_REQUIRED_FUNCS[@]}"; do
            if [[ "${fn,,}" == "${required_fn,,}" ]]; then
                keep=false
                removed+=("${fn}")
                break
            fi
        done

        if [[ "${keep}" == true ]]; then
            new_df_array+=("${fn}")
        fi
    done

    # 3.3 Report what was removed and apply changes only when needed
    if [[ ${#removed[@]} -eq 0 ]]; then
        _ok "All required functions are already enabled (none in disable_functions)"
        _track "PHP disable_functions: no changes needed"
    else
        _step "Removing from disable_functions: ${WHT}$(IFS=', '; echo "${removed[*]}")${RST}"

        # 3.4 Build new disable_functions string
        local new_df
        new_df="$(IFS=','; echo "${new_df_array[*]}")"

        # 3.5 Backup php.ini before modifying
        local backup="${PHP_INI}.npp-bak-$(date +%Y%m%d-%H%M%S)"
        cp "${PHP_INI}" "${backup}"
        _ok "php.ini backed up → ${DIM}${backup}${RST}"

        # 3.6 Apply change using sed (handles both blank and populated df lines)
        if grep -qE '^\s*disable_functions\s*=' "${PHP_INI}"; then
            # Replace existing line
            sed -i "s|^\s*disable_functions\s*=.*|disable_functions = ${new_df}|" "${PHP_INI}"
        else
            # Append to [PHP] section or end of file
            echo "disable_functions = ${new_df}" >> "${PHP_INI}"
        fi
        _ok "php.ini updated"

        # 3.7 Verify the change took effect
        local new_df_val
        new_df_val="$(grep -E '^\s*disable_functions\s*=' "${PHP_INI}" | tail -1 \
            | sed 's/^\s*disable_functions\s*=\s*//' | tr -d '"')"
        _print_csv_table "Updated disable_functions" "${new_df_val}"
    fi

    # 3.8 Check POSIX extension availability
    _step "Checking POSIX extension (required for posix_kill, posix_geteuid) …"
    local posix_ok=false
    if "${PHP_BIN}" -m 2>/dev/null | grep -qi 'posix'; then
        posix_ok=true
        _ok "POSIX extension is loaded"
    else
        _warn "POSIX extension NOT detected in PHP ${PHP_VER_DOT}"
        _warn "NPP requires posix_kill / posix_geteuid. Install via aaPanel:"
        _warn "  App Store → PHP ${PHP_VER_DOT} → Extensions → posix"
        _warn "  OR: ${PHP_BIN%/bin/php}/bin/pecl install posix"
    fi

    # 3.9 Restart PHP-FPM to apply changes
    _step "Reloading PHP-FPM ${PHP_VER_DOT} …"
    local fpm_reloaded=false

    # Try multiple init systems / service wrappers that aaPanel uses
    for cmd in \
        "service php-fpm-${PHP_VERSION} reload" \
        "/etc/init.d/php-fpm-${PHP_VERSION} reload" \
        "systemctl reload php-fpm-${PHP_VERSION}" \
        "service php-fpm-${PHP_VERSION} restart" \
        "/etc/init.d/php-fpm-${PHP_VERSION} restart" \
        "systemctl restart php-fpm-${PHP_VERSION}"
    do
        if eval "${cmd}" &>/dev/null 2>&1; then
            fpm_reloaded=true
            _ok "PHP-FPM reloaded via: ${DIM}${cmd}${RST}"
            break
        fi
    done

    if [[ "${fpm_reloaded}" == false ]]; then
        _warn "Could not auto-reload PHP-FPM. Restart it manually:"
        _warn "  service php-fpm-${PHP_VERSION} restart"
    fi

    _track "PHP functions enabled: $(IFS=', '; echo "${removed[*]:-none needed}") | POSIX: ${posix_ok}"
}

# =============================================================================
# STEP 3B — OPEN_BASEDIR CONFIGURATION CHECK
# =============================================================================
configure_open_basedir() {
    _section "open_basedir Configuration Check"

    local user_ini="${WP_PATH}/.user.ini"

    # If .user.ini doesn't exist in the WP document root, open_basedir is not
    # restricted for this site via aaPanel's per-site mechanism — nothing to do.
    if [[ ! -f "${user_ini}" ]]; then
        _ok "No .user.ini in document root — open_basedir is not restricted for this site"
        _track "open_basedir: not restricted (.user.ini absent)"
        return 0
    fi

    _ok ".user.ini found: ${WHT}${user_ini}${RST}"

    # Must actually contain an open_basedir directive to be relevant
    if ! grep -qE '^\s*open_basedir\s*=' "${user_ini}" 2>/dev/null; then
        _ok ".user.ini exists but sets no open_basedir — not restricted"
        _track "open_basedir: not restricted (.user.ini has no open_basedir)"
        return 0
    fi

    # Paths NPP requires PHP-FPM to access
    # Use cache path resolved by detect_webserver_arch()
    # Ensure trailing slash for open_basedir directory matching.
    # NPP_CACHE_PATH is set to:
    #   /www/server/fastcgi_cache             (single-nginx / nginx+nginx)
    #   /www/server/fastcgi_cache/<domain>    (nginx + apache / ols backend)
    local npp_cache_dir="${NPP_CACHE_PATH%/}/"
    local -a required_paths=(
        "${WP_PATH}/"
        "${npp_cache_dir}"
    )
    required_paths+=( "${NPP_OPEN_BASEDIR_STATIC_PATHS[@]}" )

    _print_csv_table "NPP-required open_basedir paths" "$(IFS=','; echo "${required_paths[*]}")"

    # 3B.1 Read current open_basedir value from .user.ini
    local current_uini
    current_uini="$(grep -E '^\s*open_basedir\s*=' "${user_ini}" | tail -1 \
        | sed 's/^\s*open_basedir\s*=\s*//')"
    _print_csv_table "Current .user.ini open_basedir" "$(echo "${current_uini}" | tr ':' ',')"

    # 3B.2 Merge existing colon-separated paths with required_paths,
    #      preserving any custom paths the user already has and de-duplicating.
    local -A seen=()
    local -a merged=()
    local p

    local -a existing_arr=()
    IFS=':' read -ra existing_arr <<< "${current_uini}"
    for p in "${existing_arr[@]}"; do
        p="$(echo "${p}" | tr -d '[:space:]')"
        [[ -z "${p}" ]] && continue
        if [[ -z "${seen[${p}]:-}" ]]; then
            merged+=("${p}")
            seen["${p}"]=1
        fi
    done

    for p in "${required_paths[@]}"; do
        if [[ -z "${seen[${p}]:-}" ]]; then
            merged+=("${p}")
            seen["${p}"]=1
        fi
    done

    local new_uini
    new_uini="$(IFS=':'; echo "${merged[*]}")"

    # 3B.3 Apply if changed
    if [[ "${new_uini}" == "${current_uini}" ]]; then
        _ok ".user.ini open_basedir already includes all NPP-required paths"
        _track "open_basedir: already satisfied (.user.ini)"
        return 0
    fi

    local backup="${user_ini}.npp-bak-$(date +%Y%m%d-%H%M%S)"
    cp "${user_ini}" "${backup}"
    _ok "Backed up → ${DIM}${backup}${RST}"

    chattr -i "${user_ini}"
    sed -i "s|^\s*open_basedir\s*=.*|open_basedir=${new_uini}|" "${user_ini}"
    chown "${AAPANEL_WEB_USER}:${AAPANEL_WEB_USER}" "${user_ini}" 2>/dev/null || true
    chattr +i "${user_ini}"

    _ok "Updated open_basedir in .user.ini"
    _print_csv_table "Updated .user.ini open_basedir" "$(echo "${new_uini}" | tr ':' ',')"

    # 3B.4 Reload PHP-FPM so the .user.ini change is picked up immediately
    #      (otherwise it applies after user_ini.cache_ttl, default 300s)
    _step "Reloading PHP-FPM ${PHP_VER_DOT} to refresh .user.ini cache …"
    local fpm_reloaded=false
    for cmd in \
        "service php-fpm-${PHP_VERSION} reload" \
        "/etc/init.d/php-fpm-${PHP_VERSION} reload" \
        "systemctl reload php-fpm-${PHP_VERSION}"
    do
        if eval "${cmd}" &>/dev/null 2>&1; then
            fpm_reloaded=true
            _ok "PHP-FPM reloaded via: ${DIM}${cmd}${RST}"
            break
        fi
    done
    [[ "${fpm_reloaded}" == false ]] && _warn "Could not auto-reload PHP-FPM — .user.ini changes apply within user_ini.cache_ttl (default 300s)."

    _track "open_basedir: updated for NPP (.user.ini)"
}

# =============================================================================
# STEP 3C — SYSTEM SHELL TOOLSET CHECK
# =============================================================================
check_system_toolset() {
    _section "System Shell Toolset Check"

    # Mirrors the two toolsets in nppp_shell_toolset_check() / nppp_is_dockerized():
    #   • Global  (ps grep awk sort uniq sed) — hard dep; ALL plugin actions
    #   • Preload (nohup)                     — hard dep; Preload action only
    #   • wget is a preload hard dep but gets its own install step (Step 6)
    #   • rg / safexec are optional — handled in Steps 4 / 5 without exit on failure

    # -------------------------------------------------------------------------
    # 3C.1  Global toolset — hard dependency for ALL plugin functionality
    # -------------------------------------------------------------------------
    _step "Checking global shell toolset: ${WHT}$(IFS=' '; echo "${NPP_GLOBAL_TOOLS[*]}")${RST} …"

    local -a missing_global=()
    local _gt
    for _gt in "${NPP_GLOBAL_TOOLS[@]}"; do
        if have "${_gt}"; then
            _ok "${_gt}: ${DIM}$(command -v "${_gt}")${RST}"
        else
            _warn "${_gt}: NOT FOUND"
            missing_global+=("${_gt}")
        fi
    done

    if [[ ${#missing_global[@]} -gt 0 ]]; then
        _blank
        _step "Attempting to install missing global tools: ${WHT}$(IFS=' '; echo "${missing_global[*]}")${RST} …"
        # procps    → ps
        # grep      → grep
        # gawk/mawk → awk
        # coreutils → sort uniq sed
        local os_fam; os_fam="$(detect_os_family)"
        case "${os_fam}" in
            debian)
                apt-get install -y -q procps grep gawk coreutils &>/dev/null || true
                ;;
            rhel)
                if have dnf; then
                    dnf install -y procps-ng grep gawk coreutils &>/dev/null || true
                else
                    yum install -y procps-ng grep gawk coreutils &>/dev/null || true
                fi
                ;;
            *)
                _warn "Unrecognised OS family — cannot auto-install; please install manually."
                ;;
        esac

        # Re-verify
        local -a still_missing_global=()
        for _gt in "${missing_global[@]}"; do
            if have "${_gt}"; then
                _ok "${_gt}: installed → ${DIM}$(command -v "${_gt}")${RST}"
            else
                _fail "${_gt}: STILL MISSING"
                still_missing_global+=("${_gt}")
            fi
        done

        if [[ ${#still_missing_global[@]} -gt 0 ]]; then
            _blank
            _fail "Hard dependency missing: ${RED}$(IFS=', '; echo "${still_missing_global[*]}")${RST}"
            _info "These tools are required for ALL NPP plugin functionality."
            _info "Install them manually and re-run this script."
            exit 1
        fi
    fi
    _ok "Global shell toolset satisfied"

    # -------------------------------------------------------------------------
    # 3C.2  Preload toolset — nohup is a hard dependency for the Preload feature
    #       (wget is also required but validated separately in Step 6)
    # -------------------------------------------------------------------------
    _step "Checking preload toolset: ${WHT}nohup${RST} (wget handled in Step 6) …"

    if have nohup; then
        _ok "nohup: ${DIM}$(command -v nohup)${RST}"
    else
        _step "nohup not found — attempting to install coreutils …"
        local os_fam2; os_fam2="$(detect_os_family)"
        case "${os_fam2}" in
            debian) apt-get install -y -q coreutils &>/dev/null || true ;;
            rhel)
                if have dnf; then
                    dnf install -y coreutils &>/dev/null || true
                else
                    yum install -y coreutils &>/dev/null || true
                fi
                ;;
            *) _warn "Unrecognised OS family — install nohup (coreutils) manually." ;;
        esac

        if have nohup; then
            _ok "nohup: installed → ${DIM}$(command -v nohup)${RST}"
        else
            _fail "nohup not found after install attempt."
            _info "nohup is required by the NPP Preload action."
            _info "Install coreutils manually and re-run this script."
            exit 1
        fi
    fi
    _ok "Preload shell toolset satisfied"

    _track "System toolset: global ($(IFS=','; echo "${NPP_GLOBAL_TOOLS[*]}")) ✔ | preload (nohup,wget) ✔"
}

# =============================================================================
# STEP 4 — INSTALL RIPGREP >= 14.0.0
# =============================================================================
install_ripgrep() {
    _section "ripgrep Installation (min v${RG_MIN_VERSION})"

    # 4.1 Check if rg already installed and meets version requirement
    if have rg; then
        local current_rg_ver
        current_rg_ver="$(rg --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "0.0.0")"
        if ver_gte "${current_rg_ver}" "${RG_MIN_VERSION}"; then
            _ok "ripgrep already installed: ${WHT}${current_rg_ver}${RST} (>= ${RG_MIN_VERSION})"
            _track "ripgrep: already installed v${current_rg_ver}"
            return 0
        else
            _warn "ripgrep ${current_rg_ver} found but < ${RG_MIN_VERSION} — upgrading …"
        fi
    else
        _step "ripgrep not found — installing v${RG_INSTALL_VERSION} …"
    fi

    local arch; arch="$(detect_arch)"
    local os_fam; os_fam="$(detect_os_family)"

    _info "Architecture: ${arch} | OS family: ${os_fam}"

    local installed=false

    # -------------------------------------------------------------------------
    # 4.2 Strategy per OS family
    # -------------------------------------------------------------------------
    case "${os_fam}" in

        # Debian / Ubuntu
        debian)
            # First: try native package manager (Ubuntu 19+ / Debian 12+ have a new enough rg)
            if have apt-get; then
                local repo_ver
                repo_ver="$(apt-cache show ripgrep 2>/dev/null \
                    | grep '^Version:' | head -1 \
                    | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "0.0.0")"

                if ver_gte "${repo_ver}" "${RG_MIN_VERSION}"; then
                    _step "Installing ripgrep from apt (repo version: ${repo_ver}) …"
                    if apt-get install -y -q ripgrep &>/dev/null; then
                        installed=true
                        _ok "Installed via apt"
                    else
                        _warn "apt install failed — falling back to .deb download …"
                    fi
                else
                    _step "Repo version ${repo_ver} < ${RG_MIN_VERSION} — downloading from GitHub …"
                fi
            fi

            # Fallback: download .deb from GitHub
            # Only amd64 .deb is published — arm64 falls through to the binary fallback (step 4.3)
            if [[ "${installed}" == false ]] && [[ "${arch}" == "x86_64" ]]; then
                local deb_file="ripgrep_${RG_INSTALL_VERSION}-1_amd64.deb"
                local deb_url="https://github.com/BurntSushi/ripgrep/releases/download/${RG_INSTALL_VERSION}/${deb_file}"
                local tmp_deb="/tmp/${deb_file}"

                _step "Downloading ${deb_file} from GitHub …"
                if curl -fsSL --retry 3 --retry-delay 2 -o "${tmp_deb}" "${deb_url}"; then
                    if dpkg -i "${tmp_deb}" &>/dev/null; then
                        installed=true
                        _ok "Installed via .deb package"
                    else
                        _warn "dpkg install failed — falling back to binary …"
                    fi
                    rm -f "${tmp_deb}"
                else
                    _warn "deb download failed — falling back to binary …"
                fi
            fi
            ;;

        # RHEL / CentOS / AlmaLinux / Rocky / Fedora
        rhel)
            # Check existing repos only (no EPEL enablement — avoids conflicts with aaPanel-managed repos).
            # Fedora base repos often have a recent enough ripgrep; RHEL/CentOS/Rocky/Alma
            # typically won't unless EPEL is already enabled, in which case we use the static binary fallback.
            _step "Checking ripgrep version in package manager repos …"

            if have dnf; then
                local repo_ver dnf_ver_raw
                # Query existing repos only — do NOT enable EPEL on aaPanel
                dnf_ver_raw="$(dnf info --available ripgrep 2>/dev/null \
                    | grep -i '^Version' | head -1 | awk '{print $NF}' || echo "")"
                repo_ver="${dnf_ver_raw:-0.0.0}"

                if [[ "${repo_ver}" != "0.0.0" ]] && ver_gte "${repo_ver}" "${RG_MIN_VERSION}"; then
                    _step "Installing ripgrep from dnf (repo version: ${repo_ver}) …"
                    if dnf install -y ripgrep &>/dev/null 2>&1; then
                        installed=true
                        _ok "Installed via dnf (v${repo_ver})"
                    else
                        _warn "dnf install failed — falling back to static binary …"
                    fi
                elif [[ "${repo_ver}" != "0.0.0" ]]; then
                    _step "Repo version ${repo_ver} < ${RG_MIN_VERSION} — skipping dnf, using static binary …"
                else
                    _step "ripgrep not found in existing dnf repos (EPEL not enabled on aaPanel) — using static binary …"
                fi

            elif have yum; then
                local repo_ver yum_ver_raw
                # Query existing repos only — do NOT enable EPEL on aaPanel
                yum_ver_raw="$(yum info available ripgrep 2>/dev/null \
                    | grep -i '^Version' | head -1 | awk '{print $NF}' || echo "")"
                repo_ver="${yum_ver_raw:-0.0.0}"

                if [[ "${repo_ver}" != "0.0.0" ]] && ver_gte "${repo_ver}" "${RG_MIN_VERSION}"; then
                    _step "Installing ripgrep from yum (repo version: ${repo_ver}) …"
                    if yum install -y ripgrep &>/dev/null 2>&1; then
                        installed=true
                        _ok "Installed via yum (v${repo_ver})"
                    else
                        _warn "yum install failed — falling back to static binary …"
                    fi
                elif [[ "${repo_ver}" != "0.0.0" ]]; then
                    _step "Repo version ${repo_ver} < ${RG_MIN_VERSION} — skipping yum, using static binary …"
                else
                    _step "ripgrep not found in existing yum repos (EPEL not enabled on aaPanel) — using static binary …"
                fi
            fi
            ;;
        *)
            # Unknown/unsupported OS family (not Debian/RHEL)
            _warn "Unrecognized OS family — skipping package manager, trying static binary …"
            ;;
    esac

    # -------------------------------------------------------------------------
    # 4.3 Universal fallback: static binary tar.gz from GitHub
    #     x86_64  → musl static  (ripgrep-X.Y.Z-x86_64-unknown-linux-musl)
    #     aarch64 → GNU binary   (ripgrep-X.Y.Z-aarch64-unknown-linux-gnu)
    #     Note: no musl build is published for aarch64 on GitHub releases.
    # -------------------------------------------------------------------------
    if [[ "${installed}" == false ]]; then
        local tarball_arch
        case "${arch}" in
            x86_64)  tarball_arch="x86_64-unknown-linux-musl" ;;
            aarch64) tarball_arch="aarch64-unknown-linux-gnu"  ;;
        esac

        local tar_name="ripgrep-${RG_INSTALL_VERSION}-${tarball_arch}.tar.gz"
        local tar_url="https://github.com/BurntSushi/ripgrep/releases/download/${RG_INSTALL_VERSION}/${tar_name}"
        local tmp_tar="/tmp/${tar_name}"

        _step "Fetching: ${DIM}${tar_url}${RST}"
        if curl -fsSL --retry 3 --retry-delay 2 -o "${tmp_tar}" "${tar_url}"; then
            tar -xzf "${tmp_tar}" -C /tmp/
            local extracted_dir="/tmp/ripgrep-${RG_INSTALL_VERSION}-${tarball_arch}"
            install -m 755 "${extracted_dir}/rg" /usr/local/bin/rg
            rm -rf "${tmp_tar}" "${extracted_dir}"
            installed=true
            _ok "Installed static binary → /usr/local/bin/rg"
        else
            _warn "Static binary download failed — ripgrep could not be installed."
            _info "NPP will fall back to PHP-based cache scanning (slower but functional)."
            _info "Install ripgrep ${RG_MIN_VERSION}+ manually when convenient: https://github.com/BurntSushi/ripgrep/releases"
            _track "ripgrep: SKIPPED (all install methods failed — optional dep)"
            return 0
        fi
    fi

    # 4.4 Final verification
    if have rg; then
        local final_ver
        final_ver="$(rg --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "?")"
        _ok "ripgrep ${final_ver} installed at: $(command -v rg)"
        _track "ripgrep: installed v${final_ver}"
    else
        _warn "ripgrep binary not found in PATH after installation."
        _info "NPP will fall back to PHP-based cache scanning — this is OK."
        _info "Install ripgrep ${RG_MIN_VERSION}+ manually: https://github.com/BurntSushi/ripgrep/releases"
        _track "ripgrep: SKIPPED (not in PATH after install — optional dep)"
    fi
}

# =============================================================================
# STEP 5 — INSTALL SAFEXEC
# =============================================================================
install_safexec() {
    _section "safexec Installation"

    # 5.1 Check if safexec already installed and functional
    if have safexec; then
        local sfx_path; sfx_path="$(command -v safexec)"
        _ok "safexec already installed: ${WHT}${sfx_path}${RST}"

        # Verify SUID bit (safexec requires setuid root)
        local sfx_mode
        sfx_mode="$(stat -c '%a' "${sfx_path}" 2>/dev/null || echo '000')"
        if [[ "${sfx_mode}" == "4755" ]] || [[ "${sfx_mode}" == "4750" ]]; then
            _ok "SUID bit is set correctly (${sfx_mode})"
            _track "safexec: already installed (SUID ${sfx_mode})"
            return 0
        else
            _warn "safexec found but SUID bit may be wrong (mode: ${sfx_mode}) — reinstalling …"
        fi
    else
        _step "safexec not found — installing via official one-liner …"
    fi

    # 5.2 Run the official installer
    _step "Running: curl -fsSL ${SAFEXEC_INSTALL_URL} | sh"
    _info "This will install safexec + libnpp_norm.so with setuid root …"
    _blank

    if curl -fsSL --retry 3 "${SAFEXEC_INSTALL_URL}" | sh 2>&1 | sed 's/^/     /'; then
        _blank
        _ok "safexec installer completed"
    else
        _blank
        _warn "safexec installation failed — this is an optional dependency."
        _info "NPP will run preload as the PHP-FPM user instead (fully functional)."
        _info "Install manually when convenient: curl -fsSL ${SAFEXEC_INSTALL_URL} | sudo sh"
        _track "safexec: SKIPPED (installer failed — optional dep)"
        return 0
    fi

    # 5.3 Verify installation
    if have safexec; then
        local sfx_path; sfx_path="$(command -v safexec)"
        local sfx_mode; sfx_mode="$(stat -c '%a' "${sfx_path}" 2>/dev/null || echo '000')"
        _ok "safexec installed: ${WHT}${sfx_path}${RST} (mode: ${sfx_mode})"
        _track "safexec: installed at ${sfx_path}"
    else
        _warn "safexec binary not in PATH after installation."
        _warn "It may have been installed to /usr/bin/safexec — check manually."
        _track "safexec: installed (verify manually)"
    fi
}

# =============================================================================
# STEP 6 — ENSURE GNU WGET >= 1.16 IS AVAILABLE
# =============================================================================
ensure_gnu_wget() {
    _section "GNU Wget Availability Check"

    local wget_ok=false
    local wget_reason=""

    if have wget; then
        local wget_ver_raw
        wget_ver_raw="$(wget --version 2>&1 | head -1 || true)"

        # Detect wget2
        if echo "${wget_ver_raw}" | grep -qi 'GNU Wget2'; then
            wget_reason="wget2"
        # Detect busybox/toybox
        elif echo "${wget_ver_raw}" | grep -qi 'busybox\|toybox'; then
            wget_reason="busybox_toybox"
        # Detect non-GNU
        elif ! echo "${wget_ver_raw}" | grep -qi 'GNU Wget'; then
            wget_reason="non_gnu"
        else
            # It's GNU Wget 1.x — check version
            local wget_ver
            wget_ver="$(echo "${wget_ver_raw}" | grep -oP 'GNU\s+Wget\s+\K[0-9]+(?:\.[0-9]+)+' || echo "0.0")"
            if ver_gte "${wget_ver}" "${WGET_MIN_VERSION}"; then
                wget_ok=true
                _ok "GNU Wget ${wget_ver} is present and compatible"
                _track "wget: GNU Wget ${wget_ver} (OK)"
            else
                wget_reason="unsupported_version (${wget_ver})"
            fi
        fi
    else
        wget_reason="missing"
    fi

    if [[ "${wget_ok}" == false ]]; then
        _warn "wget issue: ${wget_reason} — installing GNU Wget 1.x …"
        local os_fam; os_fam="$(detect_os_family)"

        case "${os_fam}" in
            debian)
                # On Debian/Ubuntu, install wget (package is GNU Wget 1.x).
                apt-get install -y -q wget &>/dev/null || true
                ;;
            rhel)
                if have dnf; then
                    dnf install -y wget &>/dev/null || true
                else
                    yum install -y wget &>/dev/null || true
                fi
                ;;
            *)
                _warn "Unrecognized OS family — cannot auto-install wget; re-checking …"
                ;;
        esac

        # Re-check
        if have wget; then
            local new_ver
            new_ver="$(wget --version 2>&1 | head -1 | grep -oP 'GNU\s+Wget\s+\K[0-9]+(?:\.[0-9]+)+' || echo "?")"
            _ok "GNU Wget installed: ${new_ver}"
            _track "wget: installed GNU Wget ${new_ver}"
        else
            _fail "Could not install GNU Wget."
            exit 1
        fi
    fi
}

# =============================================================================
# STEP 7 — INSTALL WP-CLI
# =============================================================================
install_wpcli() {
    _section "WP-CLI Installation"

    # 7.1 Check if already installed and working
    if [[ -x "${WP_CLI_BIN}" ]]; then
        local cur_wpcli_ver
        # Extract semver only — avoids "?" when phar exits non-zero after printing
        cur_wpcli_ver="$(${WP_CLI_BIN} --allow-root --version 2>/dev/null \
            | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
        [[ -z "${cur_wpcli_ver}" ]] && cur_wpcli_ver="?"
        _ok "WP-CLI already installed: ${WHT}WP-CLI ${cur_wpcli_ver}${RST}"
        _track "WP-CLI: already installed v${cur_wpcli_ver}"
        return 0
    fi

    # 7.2 Download wp-cli.phar
    _step "Downloading WP-CLI from: ${DIM}${WP_CLI_URL}${RST}"
    local tmp_phar="/tmp/wp-cli.phar"

    if ! curl -fsSL --retry 3 --retry-delay 2 -o "${tmp_phar}" "${WP_CLI_URL}"; then
        _fail "Failed to download WP-CLI."
        exit 1
    fi

    # 7.3 Verify it's a valid phar
    local PHP_VERIFY_CMD
    if ! php -r "echo 'ok';" &>/dev/null; then
        _warn "System php not found — using aaPanel php for verification"
        PHP_VERIFY_CMD="${PHP_BIN}"
    else
        PHP_VERIFY_CMD="php"
    fi

    if ! "${PHP_VERIFY_CMD}" "${tmp_phar}" --version --allow-root &>/dev/null; then
        _fail "Downloaded WP-CLI phar is invalid or corrupt."
        rm -f "${tmp_phar}"
        exit 1
    fi

    # 7.4 Install: rename to `wp` and place in /usr/local/bin
    install -m 755 "${tmp_phar}" "${WP_CLI_BIN}"
    rm -f "${tmp_phar}"

    # 7.5 Verify
    local installed_ver
    installed_ver="$("${WP_CLI_BIN}" --allow-root --version 2>/dev/null \
        | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || echo "?")"
    _ok "WP-CLI installed: ${WHT}WP-CLI ${installed_ver}${RST} → ${WP_CLI_BIN}"
    _track "WP-CLI: installed v${installed_ver}"

    # 7.6 Quick sanity — run as www user
    _step "Verifying WP-CLI works as user '${AAPANEL_WEB_USER}' …"
    if run_as_www "${WP_CLI_BIN}" --version &>/dev/null; then
        _ok "WP-CLI works as ${AAPANEL_WEB_USER}"
    else
        _warn "WP-CLI ran as root but failed as ${AAPANEL_WEB_USER} — check /home/www permissions."
    fi
}

# =============================================================================
# STEP 8 — INSTALL & ACTIVATE NPP PLUGIN VIA WP-CLI
# =============================================================================
install_npp_plugin() {
    _section "NPP Plugin Installation"

    _info "Plugin slug: ${WHT}${NPP_PLUGIN_SLUG}${RST}"
    _info "WordPress path: ${WHT}${WP_PATH}${RST}"
    _info "WP-CLI user: ${WHT}${AAPANEL_WEB_USER}${RST}"
    _blank

    # Helper: run wp-cli as www against the target WP install
    wp() {
        run_as_www "${WP_CLI_BIN}" --path="${WP_PATH}" "$@" 2>&1
    }

    # 8.1 Verify WP-CLI can bootstrap WordPress from the target path
    _step "Verifying WP-CLI can connect to WordPress …"
    local wp_info
    if ! wp_info="$(wp core version 2>&1)"; then
        _fail "WP-CLI failed to bootstrap WordPress at ${WP_PATH}"
        _info "wp core version output: ${wp_info}"
        _info "Possible causes:"
        _info "  • wp-config.php has incorrect DB credentials"
        _info "  • Database service is not running"
        _info "  • open_basedir restrictions block access (check .user.ini)"
        exit 1
    fi
    _ok "WordPress core version: ${WHT}${wp_info}${RST}"

    # 8.2 Check if plugin is already installed
    _step "Checking plugin installation status …"
    local plugin_status
    plugin_status="$(wp plugin status "${NPP_PLUGIN_SLUG}" 2>/dev/null || echo "NOT_INSTALLED")"

    if echo "${plugin_status}" | grep -qi 'Status: Active'; then
        _ok "NPP plugin is already installed and active"
        _track "NPP plugin: already active"
        return 0
    elif echo "${plugin_status}" | grep -qi 'Status: Inactive'; then
        _step "Plugin is installed but inactive — activating …"
        local act_out
        if act_out="$(wp plugin activate "${NPP_PLUGIN_SLUG}" 2>&1)"; then
            _ok "Plugin activated"
            _track "NPP plugin: activated (was inactive)"
        else
            _fail "Failed to activate plugin: ${act_out}"
            exit 1
        fi
        return 0
    fi

    # 8.3 Install the plugin from WordPress.org
    _step "Installing ${NPP_PLUGIN_SLUG} from WordPress.org …"
    local install_out
    if install_out="$(wp plugin install "${NPP_PLUGIN_SLUG}" --activate 2>&1)"; then
        _ok "Plugin installed and activated successfully"
        echo "${install_out}" | grep -E 'Success|Plugin|installed' \
            | while IFS= read -r line; do _info "  ${line}"; done
        _track "NPP plugin: installed and activated"
    else
        _fail "Plugin installation failed."
        echo "${install_out}" | while IFS= read -r line; do _info "  ${line}"; done
        _info ""
        _info "You can also install manually: wp plugin install ${NPP_PLUGIN_SLUG} --activate --path=${WP_PATH}"
        exit 1
    fi

    # Unset the local wp() function so it doesn't shadow the binary later
    unset -f wp
}

# =============================================================================
# STEP 9 — POST-INSTALL VERIFICATION
# =============================================================================
post_install_verify() {
    _section "Post-Install Verification"

    # 9.1 Verify all binaries are in place
    _step "Binary checks …"

    local all_ok=true

    # rg is OPTIONAL — NPP falls back to PHP-based cache scanning when absent
    if have rg; then
        local rg_ver; rg_ver="$(rg --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo '?')"
        _ok "rg: $(command -v rg)  ${DIM}(${rg_ver})${RST}"
    else
        _info "rg: not installed — NPP will use PHP-based cache scanning (this is OK)"
    fi

    # System shell toolset — re-confirm after full install sequence
    # (validated + installed in Step 3B; re-check here in case PATH changed)
    _step "Re-verifying system shell toolset …"
    for _vtool in "${NPP_GLOBAL_TOOLS[@]}" nohup; do
        if have "${_vtool}"; then
            _ok "${_vtool}: ${DIM}$(command -v "${_vtool}")${RST}"
        else
            _fail "${_vtool}: NOT FOUND — PATH may have shifted during install"
            all_ok=false
        fi
    done

    # safexec is OPTIONAL — NPP gracefully runs preload as the PHP-FPM user when it's absent
    if have safexec; then
        _ok "safexec: $(command -v safexec)  ${DIM}(SUID: $(stat -c '%a' "$(command -v safexec)" 2>/dev/null))${RST}"
    else
        _info "safexec: not installed — NPP will run preload as the PHP-FPM user (this is OK)"
    fi

    # WP-CLI — use explicit install path so display is consistent with rg/safexec/wget
    if [[ -x "${WP_CLI_BIN}" ]]; then
        local _wp_ver
        _wp_ver="$("${WP_CLI_BIN}" --allow-root --version 2>/dev/null \
            | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
        [[ -z "${_wp_ver}" ]] && _wp_ver="?"
        _ok "wp: ${WP_CLI_BIN}  ${DIM}(WP-CLI ${_wp_ver})${RST}"
    else
        _fail "wp: NOT FOUND (expected: ${WP_CLI_BIN})"
        all_ok=false
    fi

    # Wget check (special — must be GNU Wget)
    if have wget; then
        local wget_v; wget_v="$(wget --version 2>&1 | head -1 | grep -oP 'GNU\s+Wget\s+\K[0-9]+(?:\.[0-9]+)+' || echo '?')"
        _ok "wget: $(command -v wget)  ${DIM}(GNU Wget ${wget_v})${RST}"
    else
        _fail "wget: NOT FOUND"
        all_ok=false
    fi

    # 9.1 result gate
    if [[ "${all_ok}" == false ]]; then
        _fail "One or more required binaries are missing — see above."
        exit 1
    fi

    # 9.2 PHP function check
    _step "PHP function availability check …"
    local php_func_check php_funcs_literal
    # Join NPP_REQUIRED_FUNCS + posix_kill into a PHP array literal:
    php_funcs_literal="$(printf "'%s'," "${NPP_REQUIRED_FUNCS[@]}" "posix_kill")"
    php_funcs_literal="${php_funcs_literal%,}"
    php_func_check="$("${PHP_BIN}" -r "
        \$funcs = [${php_funcs_literal}];
        \$ok = [];
        \$fail = [];
        foreach (\$funcs as \$f) {
            if (function_exists(\$f)) \$ok[] = \$f;
            else \$fail[] = \$f;
        }
        echo 'OK:' . implode(',', \$ok) . '|FAIL:' . implode(',', \$fail);
    " 2>/dev/null || echo 'OK:|FAIL:check-failed')"

    local php_ok_list; php_ok_list="$(echo "${php_func_check}" | cut -d'|' -f1 | sed 's/OK://')"
    local php_fail_list; php_fail_list="$(echo "${php_func_check}" | cut -d'|' -f2 | sed 's/FAIL://')"

    if [[ -n "${php_ok_list}" ]]; then
        _ok "PHP functions enabled: ${WHT}${php_ok_list}${RST}"
    fi
    if [[ -n "${php_fail_list}" ]]; then
        _warn "PHP functions still unavailable: ${YLW}${php_fail_list}${RST}"
        _warn "Restart PHP-FPM manually: service php-fpm-${PHP_VERSION} restart"
    fi

    # 9.3 open_basedir status — read live value back from .user.ini
    _step "open_basedir status …"
    local user_ini="${WP_PATH}/.user.ini"
    if [[ ! -f "${user_ini}" ]]; then
        _ok "open_basedir: not restricted (.user.ini absent)"
    elif ! grep -qE '^\s*open_basedir\s*=' "${user_ini}" 2>/dev/null; then
        _ok "open_basedir: not restricted (.user.ini has no open_basedir directive)"
    else
        local live_obd
        live_obd="$(grep -E '^\s*open_basedir\s*=' "${user_ini}" | tail -1 \
            | sed 's/^\s*open_basedir\s*=\s*//')"
        _print_csv_table "open_basedir (live .user.ini)" "$(echo "${live_obd}" | tr ':' ',')"
    fi
}

# =============================================================================
# STEP 10 — NPP PLUGIN CONFIGURATION (CACHE PATH)
# =============================================================================
configure_npp_plugin() {
    _section "NPP Plugin Configuration"

    local npp_cache_path="${NPP_CACHE_PATH}"

    _info "Webserver architecture : ${WHT}${WEBSERVER_ARCH}${RST}"
    _info "Nginx cache path       : ${WHT}${npp_cache_path}${RST}"
    _blank

    # 10.1 Create cache directory + set ownership before plugin validation runs
    if [[ ! -d "${npp_cache_path}" ]]; then
        _step "Cache directory not found — creating …"
        mkdir -p "${npp_cache_path}"
        chown "${AAPANEL_WEB_USER}:${AAPANEL_WEB_USER}" "${npp_cache_path}"
        chmod 755 "${npp_cache_path}"
        _ok "Cache directory created: ${WHT}${npp_cache_path}${RST}"
    else
        _ok "Cache directory already exists: ${WHT}${npp_cache_path}${RST}"
    fi

    # 10.2 Bypass path restriction must be enabled before plugin validates the
    #      cache path, otherwise settings_set() rejects paths outside WP root.
    _step "Checking nginx_cache_bypass_path_restriction …"
    local bypass_val
    bypass_val="$(run_as_www "${WP_CLI_BIN}" \
        --path="${WP_PATH}" \
        npp settings get nginx_cache_bypass_path_restriction 2>/dev/null \
        | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')" || bypass_val=""

    if [[ "${bypass_val}" != "yes" ]]; then
        _step "bypass_path_restriction=${bypass_val:-<unset>} → forcing yes …"
        local bypass_out
        if bypass_out="$(run_as_www "${WP_CLI_BIN}" \
                --path="${WP_PATH}" \
                npp settings set nginx_cache_bypass_path_restriction yes 2>&1)"; then
            _ok "nginx_cache_bypass_path_restriction → yes"
            _info "  ${bypass_out}"
        else
            _warn "Could not enable bypass_path_restriction — cache path set may fail."
            _info "  ${bypass_out}"
        fi
    else
        _ok "nginx_cache_bypass_path_restriction already yes — no change needed"
    fi

    # 10.3 Set nginx_cache_path via WP-CLI (plugin validates, sanitizes, saves to DB)
    _step "Running: wp npp settings set nginx_cache_path ${npp_cache_path} …"
    local set_path_out
    if set_path_out="$(run_as_www "${WP_CLI_BIN}" \
            --path="${WP_PATH}" \
            npp settings set nginx_cache_path "${npp_cache_path}" 2>&1)"; then
        _ok "nginx_cache_path → ${WHT}${npp_cache_path}${RST}"
        _info "  ${set_path_out}"
        _track "nginx_cache_path: ${npp_cache_path}"
    else
        _warn "Could not set nginx_cache_path automatically."
        _info "  ${set_path_out}"
        _info "  Set manually:"
        _info "  sudo -u ${AAPANEL_WEB_USER} ${WP_CLI_BIN} --path=${WP_PATH} npp settings set nginx_cache_path ${npp_cache_path}"
        _track "nginx_cache_path: set manually required"
    fi

    # 10.4 Flush plugin transients so new path is picked up immediately
    _step "Flushing NPP transient caches …"
    run_as_www "${WP_CLI_BIN}" \
        --path="${WP_PATH}" \
        npp flush &>/dev/null || true
    _ok "NPP transients cleared"
}

# =============================================================================
# STEP 11 — FINAL SUMMARY
# =============================================================================
print_summary() {
    _blank
    echo "${CYN}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    printf "${CYN}${BOLD}  %-$((WIDTH-2))s${RST}\n" "Setup Summary"
    echo "${CYN}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    _blank

    for line in "${SUMMARY_LINES[@]}"; do
        echo "  ${GRN}✔${RST}  ${line}"
    done

    _blank
    _line
    echo "  ${WHT}${BOLD}Next steps:${RST}"
    _blank
    echo "  ${BLU}1.${RST} Open WordPress Admin → Settings → Nginx Cache"
    echo "     Configure your Nginx cache path and cache key."
    _blank
    echo "  ${BLU}2.${RST} Ensure Nginx fastcgi_cache_path is set in nginx.conf."
    echo "     Example: /www/server/nginx/conf/nginx.conf"
    _blank
    echo "  ${BLU}3.${RST} open_basedir was handled automatically by this script."
    echo "     If you add new cache paths later, re-run or update .user.ini manually."
    _blank
    echo "  ${BLU}4.${RST} Run the NPP self-test to verify full functionality:"
    echo "     ${DIM}sudo -u www ${WP_CLI_BIN} --path=${WP_PATH} npp status --format=json${RST}"
    _blank
    _line
    echo "  ${DIM}Docs: https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload${RST}"
    _blank

    _done_summary
}

# =============================================================================
# MAIN ENTRY POINT
# =============================================================================
main() {
    _banner

    # Bind WP_PATH early so steps can reference it as global
    WP_PATH=""
    SITE_DOMAIN=""
    SITE_PATH=""
    WEBSERVER_ARCH=""
    NPP_CACHE_PATH=""
    PHP_VERSION=""
    PHP_VER_DOT=""
    PHP_INI=""
    PHP_BIN=""

    # 0. Pre-flight
    preflight_checks "$@"

    # 1. Validate WordPress path + aaPanel DB cross-reference
    validate_wp_path

    # 1B. Detect webserver architecture (single nginx vs nginx + apache)
    detect_webserver_arch

    # 2. Detect PHP version from DB / filesystem
    detect_php_version

    # 3. Enable required PHP functions
    enable_php_functions

    # 3B. Configure open_basedir restrictions (if active)
    configure_open_basedir

    # 3C. Check required system shell toolset (global + preload)
    check_system_toolset

    # 4. Install ripgrep
    install_ripgrep

    # 5. Install safexec
    install_safexec

    # 6. Ensure GNU Wget
    ensure_gnu_wget

    # 7. Install WP-CLI
    install_wpcli

    # 8. Install + activate NPP plugin
    install_npp_plugin

    # 9. Post-install verification (binaries + PHP functions)
    post_install_verify

    # 10. NPP plugin configuration (cache path + transient flush)
    configure_npp_plugin

    # 11. Summary
    print_summary
}

# ---------------------------------------------------------------------------
# Guard: ensure the script is not sourced
# ---------------------------------------------------------------------------
if [[ "${BASH_SOURCE[0]:-${0}}" != "${0}" ]]; then
    echo "ERROR: Do not source this script. Run it directly: sudo bash npp-aapanel.sh <wp-path>" >&2
    return 1
fi

main "$@"
