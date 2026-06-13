#!/usr/bin/env bash
# =============================================================================
#  BETA: npp-aapanel.sh — NPP Infrastructure Setup for aaPanel
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
#        releases (DEB for Debian/Ubuntu; static musl tar.gz for RPM/Alpine)
#    5.  Installs safexec via the official one-liner installer
#    6.  Ensures GNU Wget >= 1.16 is present (not wget2 / busybox)
#    7.  Downloads WP-CLI, renames it to `wp`, installs to /usr/local/bin/wp
#    8.  Installs and activates the NPP plugin
#    9.  Sets Nginx Cache Path for NPP (FastCGI or Proxy)
#
#  Usage:
#    sudo bash npp-aapanel.sh /www/wwwroot/your-wordpress-site
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
#    Alpine Linux 3.x
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
    printf "${DIM}%-${WIDTH}s${RST}\n"        "  Nginx Cache Purge & Preload · github.com/psaux-it"
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

_progress() {
    # _progress "message" (single-line spinner for long ops)
    echo "  ${YLW}◌${RST}  $1"
}

_done_summary() {
    _blank
    echo "${GRN}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    printf "${GRN}${BOLD}  %-$((WIDTH-2))s${RST}\n" "✔  Setup complete — NPP is ready to use!"
    echo "${GRN}${BOLD}$(printf '═%.0s' $(seq 1 $WIDTH))${RST}"
    _blank
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

# Minimum versions for sanity checks
readonly SCRIPT_VERSION="1.0.0"

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
    if [[ -f /etc/alpine-release ]]; then
        echo "alpine"
    elif [[ -f /etc/debian_version ]]; then
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
    if [[ $# -lt 1 ]]; then
        _fail "Missing argument. Usage: sudo bash npp-aapanel.sh /path/to/wordpress"
        exit 1
    fi

    WP_PATH="${1%/}"   # strip trailing slash for consistency
    _ok "WordPress path argument: ${WHT}${WP_PATH}${RST}"

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
            debian) apt-get install -y -q sqlite3 ;;
            rhel)   yum install -y sqlite 2>/dev/null || dnf install -y sqlite ;;
            alpine) apk add --no-cache sqlite ;;
            *)
                _fail "Cannot auto-install sqlite3 on this OS. Install it manually."
                exit 1
                ;;
        esac
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

    # 1.3 Discover the sites table schema once — find the PHP version column
    _step "Inspecting aaPanel database schema …"
    local schema
    schema="$(sqlite_q "${AAPANEL_DB}" '.schema sites' 2>/dev/null || true)"

    if [[ -z "${schema}" ]]; then
        _fail "Could not read 'sites' table from ${AAPANEL_DB}"
        _info "Table dump: $(sqlite_q "${AAPANEL_DB}" '.tables' 2>/dev/null || echo '<none>')"
        exit 1
    fi

    # Determine the PHP version column name (aaPanel versions differ)
    PHP_VER_COL=""
    if echo "${schema}" | grep -qi "phpversion"; then
        PHP_VER_COL="phpversion"
    elif echo "${schema}" | grep -qi "php_version"; then
        PHP_VER_COL="php_version"
    else
        # Fallback: list all column names and find the one containing 'php'
        PHP_VER_COL="$(sqlite_q "${AAPANEL_DB}" \
            "PRAGMA table_info(sites);" 2>/dev/null \
            | awk -F'|' '{print $2}' \
            | grep -i 'php' | head -1 || true)"
    fi

    if [[ -z "${PHP_VER_COL}" ]]; then
        : # no PHP version column — nginx vhost detection will be used instead
    else
        _ok "PHP version column in DB: ${WHT}${PHP_VER_COL}${RST}"
    fi

    # 1.4 Cross-reference WP_PATH against aaPanel's sites table
    #     Build SELECT list conditionally — only include PHP col if it exists
    local query row
    if [[ -n "${PHP_VER_COL}" ]]; then
        query="SELECT name, path, ${PHP_VER_COL}
               FROM sites
               WHERE rtrim(path, '/') = '${WP_PATH}'
                  OR rtrim(path, '/') = '${WP_PATH%/}'
               LIMIT 1;"
    else
        query="SELECT name, path
               FROM sites
               WHERE rtrim(path, '/') = '${WP_PATH}'
                  OR rtrim(path, '/') = '${WP_PATH%/}'
               LIMIT 1;"
    fi

    row="$(sqlite_q "${AAPANEL_DB}" "${query}" 2>/dev/null || true)"

    if [[ -z "${row}" ]]; then
        _warn "Path '${WP_PATH}' not found in aaPanel sites table."
        _info "Registered sites:"
        sqlite_q "${AAPANEL_DB}" \
            "SELECT name, path FROM sites ORDER BY id;" 2>/dev/null \
            | awk -F'|' '{printf "     %-30s %s\n", $1, $2}' || true
        _blank
        _info "Attempting to continue with filesystem-detected PHP version …"
        SITE_DOMAIN="<unknown>"
        SITE_PATH="${WP_PATH}"
        PHP_VERSION_RAW=""
    else
        SITE_DOMAIN="$(echo "${row}" | cut -d'|' -f1)"
        SITE_PATH="$(echo "${row}"   | cut -d'|' -f2)"
        PHP_VERSION_RAW=""
        if [[ -n "${PHP_VER_COL}" ]]; then
            PHP_VERSION_RAW="$(echo "${row}" | cut -d'|' -f3)"
        fi
        _ok "aaPanel site record matched"
        _info "  Domain : ${WHT}${SITE_DOMAIN}${RST}"
        _info "  Path   : ${WHT}${SITE_PATH}${RST}"
        [[ -n "${PHP_VERSION_RAW}" ]] && _info "  PHP    : ${WHT}${PHP_VERSION_RAW}${RST}  (raw DB value)"
    fi

    # 1.5 Verify webserver is nginx via aaPanel config table
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
# STEP 2 — DETECT ACTIVE PHP VERSION
# =============================================================================
detect_php_version() {
    _section "PHP Version Detection"

    PHP_VERSION=""   # e.g. "82"  (used to build /www/server/php/82/etc/php.ini)
    PHP_VER_DOT=""   # e.g. "8.2"

    # 2.1 Prefer the DB value
    if [[ -n "${PHP_VERSION_RAW:-}" ]]; then
        # Raw value may be "82", "8.2", "php82", "PHP-8.2" — normalise
        PHP_VERSION="$(echo "${PHP_VERSION_RAW}" | tr -d '.' | tr -dc '0-9')"
        PHP_VER_DOT="$(echo "${PHP_VERSION}" | sed 's/\(.\)/\1./')"
        _ok "PHP version from aaPanel DB: ${WHT}${PHP_VER_DOT}${RST} (internal: ${PHP_VERSION})"
    fi

    # 2.2 Validate that the PHP dir actually exists
    if [[ -n "${PHP_VERSION}" ]] && [[ ! -d "${AAPANEL_PHP_BASE}/${PHP_VERSION}" ]]; then
        _warn "PHP dir ${AAPANEL_PHP_BASE}/${PHP_VERSION} does not exist — re-detecting …"
        PHP_VERSION=""
    fi

    # 2.3a Read PHP version from nginx vhost config — aaPanel's own method
    #       Pattern 1: include enable-php-83-xxx.conf  (modern aaPanel)
    #       Pattern 2: unix:/tmp/php-cgi-83.sock       (classic aaPanel)
    if [[ -z "${PHP_VERSION}" ]]; then
        _step "Reading PHP version from nginx vhost config …"
        local vhost_conf="/www/server/panel/vhost/nginx/${SITE_DOMAIN}.conf"
        if [[ -f "${vhost_conf}" ]]; then
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
        else
            _warn "Nginx vhost not found: ${vhost_conf}"
        fi
    fi

    # 2.3 Last resort: scan installed PHP versions and pick the newest
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
        _warn "Vhost parse failed; using newest installed: PHP ${PHP_VER_DOT}"
    fi

    # 2.4 Verify php.ini exists
    PHP_INI="${AAPANEL_PHP_BASE}/${PHP_VERSION}/etc/php.ini"
    if [[ ! -f "${PHP_INI}" ]]; then
        _fail "php.ini not found: ${PHP_INI}"
        exit 1
    fi
    _ok "php.ini path: ${WHT}${PHP_INI}${RST}"

    # 2.5 Verify PHP binary exists
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

    if [[ -z "${current_df}" ]]; then
        _info "Current disable_functions: ${DIM}(empty / not set)${RST}"
    else
        _info "Current disable_functions: ${DIM}${current_df}${RST}"
    fi

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
        local verify
        verify="$(grep -E '^\s*disable_functions\s*=' "${PHP_INI}" | tail -1)"
        _info "Updated: ${DIM}${verify}${RST}"
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

        # -- Debian / Ubuntu --------------------------------------------------
        debian)
            # Map arch to deb arch name
            local deb_arch
            case "${arch}" in
                x86_64)  deb_arch="amd64" ;;
                aarch64) deb_arch="arm64" ;;
            esac

            # First: try native package manager (Ubuntu 19+ / Debian 12+ have a new enough rg)
            if have apt-get; then
                local repo_ver
                repo_ver="$(apt-cache show ripgrep 2>/dev/null \
                    | grep '^Version:' | head -1 \
                    | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "0.0.0")"

                if ver_gte "${repo_ver}" "${RG_MIN_VERSION}"; then
                    _step "Installing ripgrep from apt (repo version: ${repo_ver}) …"
                    apt-get install -y -q ripgrep &>/dev/null && installed=true
                    _ok "Installed via apt"
                else
                    _step "Repo version ${repo_ver} < ${RG_MIN_VERSION} — downloading from GitHub …"
                fi
            fi

            # Fallback: download .deb from GitHub
            if [[ "${installed}" == false ]]; then
                local deb_file="ripgrep_${RG_INSTALL_VERSION}-1_${deb_arch}.deb"
                local deb_url="https://github.com/BurntSushi/ripgrep/releases/download/${RG_INSTALL_VERSION}/${deb_file}"
                local tmp_deb="/tmp/${deb_file}"

                _step "Downloading ${deb_file} from GitHub …"
                if curl -fsSL --retry 3 --retry-delay 2 -o "${tmp_deb}" "${deb_url}"; then
                    dpkg -i "${tmp_deb}" &>/dev/null && installed=true
                    rm -f "${tmp_deb}"
                    _ok "Installed via .deb package"
                else
                    _warn "deb download failed — falling back to static musl binary …"
                fi
            fi
            ;;

        # -- RHEL / CentOS / AlmaLinux / Rocky / Fedora ----------------------
        rhel)
            # ripgrep is in EPEL for CentOS/RHEL and in fedora repos for Fedora
            _step "Attempting to install ripgrep via package manager …"

            if have dnf; then
                # Try direct install first (Fedora 37+)
                if dnf install -y ripgrep &>/dev/null 2>&1; then
                    installed=true
                    _ok "Installed via dnf"
                else
                    # Enable EPEL and try again (RHEL / Rocky / AlmaLinux)
                    _step "Enabling EPEL repository …"
                    dnf install -y epel-release &>/dev/null 2>&1 || true
                    if dnf install -y ripgrep &>/dev/null 2>&1; then
                        installed=true
                        _ok "Installed via dnf + EPEL"
                    fi
                fi
            elif have yum; then
                yum install -y epel-release &>/dev/null 2>&1 || true
                if yum install -y ripgrep &>/dev/null 2>&1; then
                    installed=true
                    _ok "Installed via yum + EPEL"
                fi
            fi

            # Check version if installed via package manager
            if [[ "${installed}" == true ]]; then
                local pm_ver
                pm_ver="$(rg --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "0.0.0")"
                if ! ver_gte "${pm_ver}" "${RG_MIN_VERSION}"; then
                    _warn "Package manager installed rg ${pm_ver} < ${RG_MIN_VERSION} — upgrading via static binary …"
                    installed=false
                fi
            fi
            # Note: no .rpm on GitHub releases → use musl static binary as fallback
            ;;
        # -- Alpine -----------------------------------------------------------
        alpine)
            _step "Installing ripgrep via apk …"
            if apk add --no-cache ripgrep &>/dev/null 2>&1; then
                installed=true
                _ok "Installed via apk"
            fi
            # Check version — apk may supply < RG_MIN_VERSION
            if [[ "${installed}" == true ]]; then
                local apk_ver
                apk_ver="$(rg --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "0.0.0")"
                if ! ver_gte "${apk_ver}" "${RG_MIN_VERSION}"; then
                    _warn "apk installed rg ${apk_ver} < ${RG_MIN_VERSION} — upgrading via static binary …"
                    installed=false
                fi
            fi
            # Note: no .apk on GitHub releases → use musl static binary as fallback
            ;;
        *)
            _fail "Cannot auto-install wget on this OS. Install GNU Wget ${WGET_MIN_VERSION}+ manually."
            exit 1
            ;;
    esac

    # -------------------------------------------------------------------------
    # 4.3 Universal fallback: musl static binary tar.gz from GitHub
    # -------------------------------------------------------------------------
    if [[ "${installed}" == false ]]; then
        _step "Downloading ripgrep ${RG_INSTALL_VERSION} musl static binary …"

        # GitHub releases provide musl static tarballs for x86_64 and aarch64
        local tarball_arch
        case "${arch}" in
            x86_64)  tarball_arch="x86_64-unknown-linux-musl" ;;
            aarch64) tarball_arch="aarch64-unknown-linux-musl" ;;
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
            _ok "Installed musl static binary → /usr/local/bin/rg"
        else
            _fail "All ripgrep installation methods failed."
            _info "Please install ripgrep ${RG_MIN_VERSION}+ manually and re-run."
            exit 1
        fi
    fi

    # 4.4 Final verification
    if have rg; then
        local final_ver
        final_ver="$(rg --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo "?")"
        _ok "ripgrep ${final_ver} installed at: $(command -v rg)"
        _track "ripgrep: installed v${final_ver}"
    else
        _fail "ripgrep binary not found in PATH after installation."
        exit 1
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
        _fail "safexec installation failed."
        _info "Run manually: curl -fsSL ${SAFEXEC_INSTALL_URL} | sudo sh"
        exit 1
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
                # On Debian/Ubuntu, install wget (package is GNU Wget 1.x)
                apt-get install -y -q wget &>/dev/null
                ;;
            rhel)
                have dnf && dnf install -y wget &>/dev/null || yum install -y wget &>/dev/null
                ;;
            alpine)
                # Alpine wget is busybox; we need gnu wget
                apk add --no-cache wget &>/dev/null
                # Alpine's wget package IS a compatible wget (not busybox default)
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
        cur_wpcli_ver="$(${WP_CLI_BIN} --version 2>/dev/null | head -1 || echo "?")"
        _ok "WP-CLI already installed: ${WHT}${cur_wpcli_ver}${RST}"
        _track "WP-CLI: already installed ${cur_wpcli_ver}"
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
    if ! php -r "echo 'ok';" &>/dev/null; then
        _warn "System php not found — using aaPanel php for verification"
        PHP_VERIFY_CMD="${PHP_BIN}"
    else
        PHP_VERIFY_CMD="php"
    fi

    if ! "${PHP_VERIFY_CMD}" "${tmp_phar}" --version &>/dev/null; then
        _fail "Downloaded WP-CLI phar is invalid or corrupt."
        rm -f "${tmp_phar}"
        exit 1
    fi

    # 7.4 Install: rename to `wp` and place in /usr/local/bin
    install -m 755 "${tmp_phar}" "${WP_CLI_BIN}"
    rm -f "${tmp_phar}"

    # 7.5 Ensure shebang points to the correct PHP for this server.
    #     WP-CLI's phar shebang is #!/usr/bin/env php — which picks up the
    #     system PHP.  On aaPanel the "active CLI php" symlink is at
    #     /www/server/php/xx/bin/php but env php might resolve elsewhere.
    #     We patch the shebang to use the aaPanel PHP binary explicitly.
    local shebang_line; shebang_line="$(head -1 "${WP_CLI_BIN}")"
    if echo "${shebang_line}" | grep -q '^#!'; then
        # Replace shebang
        sed -i "1s|.*|#!${PHP_BIN}|" "${WP_CLI_BIN}"
        _ok "Shebang patched to: #!${PHP_BIN}"
    fi

    # 7.6 Verify
    local installed_ver
    installed_ver="$("${WP_CLI_BIN}" --version 2>/dev/null | head -1 || echo "?")"
    _ok "WP-CLI installed: ${WHT}${installed_ver}${RST} → ${WP_CLI_BIN}"
    _track "WP-CLI: installed ${installed_ver}"

    # 7.7 Quick sanity — run as www user
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
        run_as_www "${WP_CLI_BIN}" --path="${WP_PATH}" --allow-root "$@" 2>&1
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

    check_bin() {
        local name="$1"
        if have "${name}"; then
            local ver_str="$(${name} --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo '?')"
            _ok "${name}: $(command -v ${name})  ${DIM}(${ver_str})${RST}"
        else
            _fail "${name}: NOT FOUND"
            all_ok=false
        fi
    }

    check_bin rg "${RG_MIN_VERSION}"
    check_bin safexec || true    # safexec version flag may differ
    if have safexec; then
        _ok "safexec: $(command -v safexec)  ${DIM}(SUID: $(stat -c '%a' "$(command -v safexec)" 2>/dev/null))${RST}"
    fi
    check_bin wp

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
    local php_func_check
    php_func_check="$("${PHP_BIN}" -r "
        \$funcs = ['shell_exec','exec','proc_open','putenv','posix_kill'];
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

    # 9.3 Set nginx cache path to aaPanel default fastcgi cache directory
    #     Source: plugin wp-cli.php → settings_set() validates path existence
    #     aaPanel default fastcgi_cache_path: /www/server/fastcgi_cache
    local npp_cache_path="/www/server/fastcgi_cache"
    _step "Configuring NPP nginx cache path: ${WHT}${npp_cache_path}${RST}"

    # Create cache directory + set ownership before plugin validation runs
    if [[ ! -d "${npp_cache_path}" ]]; then
        _step "Cache directory not found — creating …"
        mkdir -p "${npp_cache_path}"
        chown "${AAPANEL_WEB_USER}:${AAPANEL_WEB_USER}" "${npp_cache_path}"
        chmod 755 "${npp_cache_path}"
        _ok "Cache directory created: ${WHT}${npp_cache_path}${RST}"
    else
        _ok "Cache directory already exists: ${WHT}${npp_cache_path}${RST}"
    fi

    # Bypass path restriction must be enabled before plugin validates the cache path,
    # otherwise settings_set() rejects paths outside the WordPress root.
    _step "Checking nginx_cache_bypass_path_restriction …"
    local bypass_val
    bypass_val="$(run_as_www "${WP_CLI_BIN}" \
        --path="${WP_PATH}" \
        --allow-root \
        npp settings get nginx_cache_bypass_path_restriction 2>/dev/null \
        | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')" || bypass_val=""

    if [[ "${bypass_val}" != "yes" ]]; then
        _step "bypass_path_restriction=${bypass_val:-<unset>} → forcing yes …"
        local bypass_out
        if bypass_out="$(run_as_www "${WP_CLI_BIN}" \
                --path="${WP_PATH}" \
                --allow-root \
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

    # Set nginx_cache_path via WP-CLI (plugin validates path, sanitizes, saves to DB)
    _step "Running: wp npp settings set nginx_cache_path ${npp_cache_path} …"
    local set_path_out
    if set_path_out="$(run_as_www "${WP_CLI_BIN}" \
            --path="${WP_PATH}" \
            --allow-root \
            npp settings set nginx_cache_path "${npp_cache_path}" 2>&1)"; then
        _ok "nginx_cache_path → ${WHT}${npp_cache_path}${RST}"
        _info "  ${set_path_out}"
        _track "nginx_cache_path: ${npp_cache_path}"
    else
        _warn "Could not set nginx_cache_path automatically."
        _info "  ${set_path_out}"
        _info "  Set manually after setup:"
        _info "  sudo -u ${AAPANEL_WEB_USER} ${WP_CLI_BIN} --path=${WP_PATH} npp settings set nginx_cache_path ${npp_cache_path}"
        _track "nginx_cache_path: set manually required"
    fi

    # Flush plugin transients so new path is picked up immediately
    _step "Flushing NPP transient caches …"
    run_as_www "${WP_CLI_BIN}" \
        --path="${WP_PATH}" \
        --allow-root \
        npp flush &>/dev/null || true
    _ok "NPP transients cleared"
}

# =============================================================================
# STEP 10 — FINAL SUMMARY
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
    echo "  ${BLU}3.${RST} If open_basedir is active, add these paths:"
    echo "     ${DIM}/www/server:/tmp:/usr/bin:/usr/local/bin:/proc:/dev/null${RST}"
    echo "     Modify per-site PHP-FPM pool or .user.ini as needed."
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
    PHP_VERSION_RAW=""
    PHP_VER_COL=""
    PHP_VERSION=""
    PHP_VER_DOT=""
    PHP_INI=""
    PHP_BIN=""

    # 0. Pre-flight
    preflight_checks "$@"

    # 1. Validate WordPress path + aaPanel DB cross-reference
    validate_wp_path

    # 2. Detect PHP version from DB / filesystem
    detect_php_version

    # 3. Enable required PHP functions
    enable_php_functions

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

    # 9. Post-install verification
    post_install_verify

    # 10. Summary
    print_summary
}

# ---------------------------------------------------------------------------
# Guard: ensure the script is not sourced
# ---------------------------------------------------------------------------
if [[ "${BASH_SOURCE[0]}" != "${0}" ]]; then
    echo "ERROR: Do not source this script. Run it directly: sudo bash npp-aapanel.sh <wp-path>" >&2
    return 1
fi

main "$@"
