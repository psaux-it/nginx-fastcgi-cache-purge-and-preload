<?php
/*
 * Plugin Name:       Nginx Cache Purge Preload
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       The most comprehensive free solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.
 * Version:           2.1.7
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       fastcgi-cache-purge-and-preload-nginx
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      7.4
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// NPP is designed for administrators — direct UI interaction and limited
// remote access — but also supports non-admin users (e.g. Editors) who
// hold the nppp_purge_cache capability for auto-purge on content saves.
// Bootstrap loads ~40 files including shell_exec/proc_open (preload),
// cache-tree scanning and WP_Filesystem file deletion (purge), and external
// binary detection (pre-checks). Loading this stack unconditionally is a
// performance and security liability — so the plugin stays dormant on
// anonymous and non-privileged requests.
//
// SECURITY MODEL
// Each entry point below is a narrow gate. A credential is verified BEFORE
// nppp_load_bootstrap() runs, so unauthenticated traffic can never force the
// plugin stack to load. Everything else bails before a single plugin file loads.
//
//   Session-based (logged-in user, capability checked):
//     EP1  wp-admin UI            manage_options, or nppp_purge_cache when
//                                 auto-purge is enabled
//     EP4  Frontend admin bar/FAB manage_options
//     EP5  Setup wizard           manage_options
//     EP6  Remote WP/WC REST      logged-in + manage_options or nppp_purge_cache,
//                                 content-modifying methods only
//
//   Trusted internal triggers (no user session):
//     EP2  WP-Cron               trusted hook names only
//     EP7  Background updates    only when auto-purge options are enabled
//     EP9  WP-CLI                only for `wp npp ...` invocations
//
//   Token/key-gated remote endpoints (credential verified pre-bootstrap):
//     EP3  REST API (nppp_nginx_cache/v2)  API key, feature toggle required
//     EP8  Watchdog AJAX (nppp_cron_wake)  ping token, feature toggle required
//     EP10 Fail2Ban webhook (nppp_f2b/v1)  bearer token, POST only, optional
//                                          IP allow-list checked AFTER the token
//   Each of these is a two-layer gate: a lightweight pre-bootstrap check here,
//   then the handler validates again as defense-in-depth.
//
// ABUSE HANDLING (EP3 / EP8 / EP10)
//   - Failed credentials add a per-IP strike; at the lockout threshold the IP
//     is refused early (429) without touching the plugin stack. EP10 refuses
//     a locked-out IP only when it also presents a bad token, so a valid token
//     is never locked out.
//   - Only real credential failures count as attacks: they feed the strike
//     counter and the Fail2Ban dashboard's "Endpoint Attacks" card.
//     Configuration problems (e.g. a valid EP10 token from an IP missing in
//     nppp_f2b_trusted_ips) are logged but never counted as attacks.
//   - Log lines are throttled (attempt #1, then every 5th) and client IPs are
//     masked in the plugin log. Dashboard recording is opt-in (only after
//     Fail2Ban has sent a ban/unban) and stores public IPs only.
//   - Client IP comes from REMOTE_ADDR unless the site owner explicitly sets
//     NPPP_PROXY_IP_HEADER to one of a fixed set of trusted proxy headers.
//
// Admin users (manage_options): full UI access via EP1, EP4, EP6.
// Capability holders (nppp_purge_cache): bootstrap loaded only when
// auto-purge is enabled — solely to register the cache-purge hook on
// content saves. All settings and AJAX handlers remain admin-only.
// ---------------------------------------------------------------------------

// Compatibility mode for environments where Nginx cannot be auto-detected
// during setup. Activated via the Setup wizard (stored as a DB option) or
// by manually adding define('NPPP_ASSUME_NGINX', true) to wp-config.php.
function nppp_maybe_define_assume_nginx(): void {
    if (! defined('NPPP_ASSUME_NGINX') && get_option('nppp_assume_nginx_runtime')) {
        define('NPPP_ASSUME_NGINX', true);
    }
}
add_action('plugins_loaded', 'nppp_maybe_define_assume_nginx', 0);

// Provides the main plugin file path to included files — used by
// get_plugin_data() (update, tracking, settings) and runtime-paths.php.
if (!defined('NPPP_PLUGIN_FILE')) {
    define('NPPP_PLUGIN_FILE', __FILE__);
}

// Register custom cron schedules unconditionally.
add_filter( 'cron_schedules', static function ( array $schedules ): array {
    if ( ! isset( $schedules['every_3hours_npp'] ) ) {
        $schedules['every_3hours_npp'] = [
            'interval' => 3 * HOUR_IN_SECONDS,
            'display'  => 'Every 3 Hours-NPP',
        ];
    }
    if ( ! isset( $schedules['monthly_npp'] ) ) {
        $schedules['monthly_npp'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => 'Monthly-NPP',
        ];
    }
    if ( ! isset( $schedules['every_min_npp'] ) ) {
        $schedules['every_min_npp'] = [
            'interval' => 60,
            'display'  => 'Every Minute-NPP',
        ];
    }
    if ( ! isset( $schedules['every_5min_npp'] ) ) {
        $schedules['every_5min_npp'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes-NPP',
        ];
    }
    return $schedules;
} );

// Single source of truth for the runtime directory
if (!defined('NPPP_RUNTIME_SUBDIR')) {
    define('NPPP_RUNTIME_SUBDIR', 'nginx-cache-purge-preload-runtime');
}

// Single source of truth for the plugin version
if (!defined('NPPP_PLUGIN_VERSION')) {
    define('NPPP_PLUGIN_VERSION', '2.1.7');
}

// Single source of truth for the safexec version
if (!defined('NPPP_SAFEXEC_VERSION')) {
    define('NPPP_SAFEXEC_VERSION', '1.9.6');
}

// Loads runtime paths → SPL class autoloader → full admin bootstrap.
// require_once deduplicates — safe to call from multiple entry points.
function nppp_load_bootstrap(): void {
    // Load runtime path helpers
    require_once plugin_dir_path(__FILE__) . 'includes/runtime-paths.php';
    // Auto class loader
    require_once plugin_dir_path(__FILE__) . 'includes/autoload.php';
    // Main admin bootstrap
    require_once plugin_dir_path(__FILE__) . 'admin/fastcgi-cache-purge-and-preload-nginx-admin.php';
}

// ---------------------------------------------------------------------------
// EP1 — Direct UI admin interaction
// Covers all admin UI operations: post saves, plugin/theme installs,
// settings changes, admin bar cache actions.
// Also covers non-admin users who hold the nppp_purge_cache
// capability — they get bootstrap only when auto-purge is active, solely to
// register the transition_post_status hook. All UI/settings handlers inside
// the bootstrap check manage_options individually, so no admin surface is
// exposed to non-admin users.
// ---------------------------------------------------------------------------
add_action('init', function (): void {
    if (!is_admin()) return;
    if (!is_user_logged_in()) return;

    // Admin — full UI access, load bootstrap unconditionally.
    if (current_user_can('manage_options')) {
        nppp_load_bootstrap();
        return;
    }

    // Non-admin with the custom purge capability.
    // Load bootstrap only when auto-purge is enabled.
    if (!current_user_can('nppp_purge_cache')) return;

    $opts = get_option('nginx_cache_settings', []);
    if (($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes') return;

    nppp_load_bootstrap();
}, 1);

// ---------------------------------------------------------------------------
// EP2 — WP-Cron: NPP scheduler events + scheduled post publishing
// Handles cache operations with no admin session — cron runs headless,
// EP1 and EP4 never fire. Auth by trusted hook name only.
// ---------------------------------------------------------------------------
foreach ([
    'npp_cache_preload_event',
    'npp_cache_preload_status_event',
    'nppp_index_updater_event',
    'publish_future_post',
    'nppp_f2b_cleanup_event',
    'nppp_f2b_enrich_event',
    'nppp_f2b_worker_event',
] as $nppp_cron_event) {
    add_action($nppp_cron_event, 'nppp_load_bootstrap', 0);
}
unset($nppp_cron_event);

// ---------------------------------------------------------------------------
// Shared pre-bootstrap abuse logger — used by EP3, EP8 and EP10 gates.
// Increments the per-IP counter and writes to the plugin log at
// attempt #1 and every 5th attempt thereafter to avoid log flooding.
//
// $is_attack = true  (default): real abuse. Uses the _fail_ strike counter
//                    (feeds the gate lockout) and records the rejection for
//                    the Fail2Ban dashboard's "Endpoint Attacks" card.
// $is_attack = false: config problem, not abuse (e.g. EP10 valid token from
//                    an IP missing in nppp_f2b_trusted_ips). Uses a separate
//                    _cfg_ counter, so no strikes and no dashboard record.
//                    Only the throttled plugin log line is written.
// ---------------------------------------------------------------------------
function nppp_ep_gate_log(
    string $masked,
    string $raw,
    string $ep,
    string $action,
    string $status,
    bool $is_attack = true
): void {
    // Real abuse uses the _fail_ strike counter. Config problems (e.g. valid
    // token from a non-allow-listed IP) use a separate _cfg_ key, so they
    // never add strikes.
    $rate_key   = 'nppp_' . $ep . ( $is_attack ? '_fail_' : '_cfg_' ) . hash( 'sha256', $raw );
    $fail_count = (int) get_transient($rate_key);
    $fail_count++;
    set_transient($rate_key, $fail_count, HOUR_IN_SECONDS);

    if ($fail_count !== 1 && $fail_count % 5 !== 0) {
        return;
    }

    if ($is_attack) {
        nppp_ep_gate_record($ep, $raw);
    }

    if (!function_exists('nppp_get_runtime_file')) {
        require_once plugin_dir_path(NPPP_PLUGIN_FILE) . 'includes/runtime-paths.php';
    }

    $entry = PHP_EOL . '[' . current_time('Y-m-d H:i:s') . '] ERROR ' . strtoupper($ep) . ':'
           . ' IP: '     . $masked
           . ' | Action: ' . $action
           . ' | Status: ' . $status
           . ' | Attempt: #' . $fail_count
           . PHP_EOL;

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    file_put_contents(NGINX_CACHE_LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------------
// Records a gate rejection for the Fail2Ban dashboard's "Endpoint Attacks"
// card: ONE prepared single-row INSERT into the existing events table
// (event_type = 'gate', jail = gate name). Runs only after the throttle in
// nppp_ep_gate_log() above (attempt #1 and every 5th) and every gate stops
// counting at its lockout, so the volume per IP is bounded. Gate attacks are
// never RDAP-enriched and never touch the fail2ban worker; nothing else
// happens in this request.
//
// Opt-in: nothing is recorded until Fail2Ban has pushed at least one ban or
// unban to the webhook (the same "configured" test the Fail2Ban tab uses), so a
// site that never set Fail2Ban up stores no client IPs and sends none to RIPEstat.
// ---------------------------------------------------------------------------
function nppp_ep_gate_record(string $ep, string $raw_ip): void {
    // Public addresses only. A private/loopback IP is a proxy misconfiguration
    // or a LAN client, not an attacker, and must never be sent on to RIPEstat.
    if (!filter_var($raw_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return;
    }

    global $wpdb;

    // The table may not exist yet (Fail2Ban tab never opened): a failed insert
    // must never disturb the gate itself.
    $nppp_prev = $wpdb->suppress_errors(true);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO %i (jail, ip, event_type, created_at)
             SELECT %s, %s, 'gate', %s FROM DUAL
             WHERE EXISTS ( SELECT 1 FROM %i WHERE event_type IN ('ban','unban') LIMIT 1 )",
            $wpdb->prefix . 'nppp_f2b_events',
            sanitize_key($ep),
            $raw_ip,
            gmdate('Y-m-d H:i:s'),
            $wpdb->prefix . 'nppp_f2b_events'
        )
    );
    $wpdb->suppress_errors($nppp_prev);
}

// ---------------------------------------------------------------------------
// Shared pre-bootstrap Client IP resolver — used by EP3 and EP8 gates.
// Resolves the real client IP, with optional trusted proxy header support.
// Fallback is always REMOTE_ADDR — the plugin never trusts arbitrary headers.
//
// To enable proxy header support, define in wp-config.php:
// define( 'NPPP_PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );
// ---------------------------------------------------------------------------
function nppp_resolve_ip(): string {
    $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
        : '';

    if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
        return 'unknown';
    }

    // No proxy header configured — safe default.
    if ( ! defined( 'NPPP_PROXY_IP_HEADER' ) ) {
        return $remote_addr;
    }

    // Strict whitelist — no arbitrary header injection.
    $allowed = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
    ];

    $header = (string) NPPP_PROXY_IP_HEADER;

    if ( ! in_array( $header, $allowed, true ) ) {
        return $remote_addr;
    }

    $value = isset( $_SERVER[ $header ] )
        ? sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) )
        : '';

    if ( $value === '' ) {
        return $remote_addr;
    }

    // XFF can be comma-separated; leftmost entry is the client
    // as seen by the first (outermost) trusted proxy.
    $candidate = trim( explode( ',', $value )[0] );

    return filter_var( $candidate, FILTER_VALIDATE_IP )
        ? $candidate
        : $remote_addr;
}

// ---------------------------------------------------------------------------
// Shared pre-bootstrap IP masker — used by EP3 and EP8 gates.
// Masks the client IP before writing to the abuse log.
// IPv4: masks last octet (x.x.x.**)
// IPv6: zeros last 80 bits
// ---------------------------------------------------------------------------
function nppp_mask_ip( string $raw ): string {
    if ( filter_var( $raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
        $parts    = explode( '.', $raw );
        $parts[3] = '**';
        return implode( '.', $parts );
    }

    if ( filter_var( $raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
        $bin = inet_pton( $raw );
        return inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\x00", 10 ) );
    }

    return 'unknown';
}

// ---------------------------------------------------------------------------
// EP3 — NPP REST namespace (/nppp_nginx_cache/v2/)
// Pre-screens all /nppp_nginx_cache/ traffic before bootstrap loads.
// Invalid or missing keys bail immediately — zero plugin files load.
// Valid keys proceed to bootstrap, which registers real endpoints (when the
// REST API feature is enabled) backed by a second validation + rate-limit layer.
// ---------------------------------------------------------------------------
add_action('rest_api_init', function (): void {
    $rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
    if (strpos($rest_route, 'nppp_nginx_cache') === false) {
        return;
    }

    // Gate on REST API feature status before doing any further work.
    $opts = get_option('nginx_cache_settings', []);
    if (($opts['nginx_cache_api'] ?? 'no') !== 'yes') {
        return;
    }

    // Get IP
    $nppp_ep3_raw_ip = nppp_resolve_ip();

    // Early block abusing IP
    $nppp_ep3_rate_key = 'nppp_ep3_fail_' . hash( 'sha256', $nppp_ep3_raw_ip );
    if ((int) get_transient($nppp_ep3_rate_key) >= 10) {
        wp_die('', '', ['response' => 429]);
    }

    // Mask IP
    $nppp_ep3_masked = nppp_mask_ip( $nppp_ep3_raw_ip );

    // Extract the authorization header
    $api_key = '';
    $auth_header = sanitize_text_field(
        wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' )
    );

    // Last resort for Apache/mod_php: recover Authorization and X-Api-Key via
    // apache_request_headers()/getallheaders() when they are missing from $_SERVER.
    // This works only with mod_php, not Apache→PHP-FPM (mod_proxy_fcgi/fcgid),
    // where the headers are dropped before reaching PHP.
    if ( $auth_header === '' || empty( $_SERVER['HTTP_X_API_KEY'] ) ) {
        $nppp_ep3_headers = [];
        if (function_exists('getallheaders')) {
            $nppp_ep3_headers = getallheaders() ?: [];
        } elseif (function_exists('apache_request_headers')) {
            $nppp_ep3_headers = apache_request_headers() ?: [];
        }
        foreach ($nppp_ep3_headers as $nppp_ep3_h_name => $nppp_ep3_h_val) {
            if ($auth_header === '' && strcasecmp($nppp_ep3_h_name, 'Authorization') === 0) {
                $auth_header = sanitize_text_field(wp_unslash((string) $nppp_ep3_h_val));
                if ($auth_header !== '') {
                    // Keep layer 2 in sync -- it re-reads
                    // Authorization via $request->get_header(), which WordPress
                    // populates from $_SERVER, not from getallheaders().
                    $_SERVER['HTTP_AUTHORIZATION'] = $auth_header;
                }
            }
            if (empty($_SERVER['HTTP_X_API_KEY']) && strcasecmp($nppp_ep3_h_name, 'X-Api-Key') === 0) {
                $_SERVER['HTTP_X_API_KEY'] = sanitize_text_field(wp_unslash((string) $nppp_ep3_h_val));
            }
        }
        unset($nppp_ep3_headers, $nppp_ep3_h_name, $nppp_ep3_h_val);
    }

    // Get the key from the 'Authorization: Bearer <key>' header.
    if (strpos($auth_header, 'Bearer ') === 0) $api_key = substr($auth_header, 7);

    // Check for a custom 'X-API-KEY' header.
    if (empty($api_key)) {
        $api_key = sanitize_text_field(
            wp_unslash( $_SERVER['HTTP_X_API_KEY'] ?? '' )
        );
    }

    // Final sanitization
    $api_key = sanitize_text_field($api_key);

    // Empty key — silent.
    if (empty($api_key)) return;

    // Wrong format — log and penalise.
    if (!preg_match('/^[a-f0-9]{64}$/i', $api_key)) {
        nppp_ep_gate_log($nppp_ep3_masked, $nppp_ep3_raw_ip, 'ep3', 'nppp_nginx_cache', 'ERROR 403 MALFORMED API KEY');
        return;
    }

    // Validation - log and penalise.
    $stored_key = isset($opts['nginx_cache_api_key']) ? $opts['nginx_cache_api_key'] : '';
    if (!is_string($stored_key) || !hash_equals($stored_key, $api_key)) {
        nppp_ep_gate_log($nppp_ep3_masked, $nppp_ep3_raw_ip, 'ep3', 'nppp_nginx_cache', 'ERROR 403 API KEY MISMATCH OR INVALID');
        return;
    }

    nppp_load_bootstrap();
}, 1);

// ---------------------------------------------------------------------------
// EP4 — Frontend, direct UI admin interaction
// Covers admin bar cache actions and mobile FAB toolbar on frontend pages.
// Separate from EP1 — is_admin()=false on frontend even for logged-in admins.
// ---------------------------------------------------------------------------
add_action('init', function (): void {
    if (is_admin()) return;
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    nppp_load_bootstrap();

    // Main frontend bootstrap
    require_once plugin_dir_path(__FILE__) . 'frontend/fastcgi-cache-purge-and-preload-nginx-front.php';
}, 1);

// ---------------------------------------------------------------------------
// EP5 — Setup
// Not a cache operation entry point — drives first-run configuration and
// Nginx compatibility detection only. Priority 2 runs after EP1 so the
// NPPP\Setup class is already autoloaded before Setup::init() is called.
// ---------------------------------------------------------------------------
add_action('init', function (): void {
    if (!is_admin()) {
        return;
    }
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }
    if (class_exists('\NPPP\Setup')) {
        \NPPP\Setup::init();
    }
}, 2);

// ---------------------------------------------------------------------------
// EP6 — Remote WP REST (/wp/v2/) and WC REST (/wc/)
// Ensures remote content changes trigger the same auto-purge hooks as
// wp-admin saves. rest_pre_dispatch required — WP and WC authentication
// resolves after rest_api_init. Return $result unchanged or route handler
// is bypassed.
// Also covers non-admin users (e.g. Editors saving via Gutenberg) who hold
// the nppp_purge_cache capability.
// ---------------------------------------------------------------------------
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    if (!is_null($result)) return $result;

    $route = $request->get_route();
    if (strpos($route, '/wc/') !== 0 &&
        strpos($route, '/wp/v2/') !== 0) return $result;

    // Only load bootstrap for content-modifying requests
    $method = $request->get_method();
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return $result;

    if (!is_user_logged_in()) return $result;

    $opts = get_option('nginx_cache_settings', []);
    if (($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes') return $result;

    // Admin or any user granted the custom purge capability (e.g. Editor).
    if (!current_user_can('manage_options') && !current_user_can('nppp_purge_cache')) return $result;

    nppp_load_bootstrap();

    return $result;
}, 10, 3);

// ---------------------------------------------------------------------------
// EP7 — WP background auto-updates, no user session
// Ensures core, plugin, and theme background updates trigger auto-purge.
// Priority 1 — bootstrap must load before admin bootstrap registers its
// automatic_updates_complete callback at priority 10.
// ---------------------------------------------------------------------------
add_action('automatic_updates_complete', function ( $results ): void {
    $opts = get_option('nginx_cache_settings', []);
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( ($opts['nppp_autopurge_3rdparty'] ?? 'no') !== 'yes' ) return;

    nppp_load_bootstrap();
}, 1);

// ---------------------------------------------------------------------------
// EP8 — Watchdog AJAX (nopriv — token-gated)
// The watchdog process POSTs after preload finishes.
// EP1 never fires for nopriv requests so bootstrap must be loaded explicitly
// for this one action.
//
// Two-layer gate — bootstrap only loads when the token is genuinely valid:
//   Layer 1 (here)   : format check + transient ext + hash_equals + abuse logs
//   Layer 2 (handler): rate limit + same checks again as defense-in-depth
// ---------------------------------------------------------------------------
add_action('init', function(): void {
    if (!wp_doing_ajax()) return;

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- EP8 watchdog
    $action = isset($_POST['action'])
        ? sanitize_key(wp_unslash($_POST['action']))
        : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing -- EP8 watchdog
    if ($action !== 'nppp_cron_wake') return;

    // Watchdog feature disabled — endpoint intentionally unreachable.
    $opts = get_option('nginx_cache_settings', []);
    if (($opts['nginx_cache_watchdog'] ?? 'no') !== 'yes') {
        wp_die('', '', ['response' => 403]);
    }

    // Get IP
    $nppp_ep8_raw_ip = nppp_resolve_ip();

    // Early block abusing IP
    $nppp_ep8_rate_key = 'nppp_ep8_fail_' . hash( 'sha256', $nppp_ep8_raw_ip );
    if ((int)get_transient($nppp_ep8_rate_key) >= 10) {
        wp_die('', '', ['response' => 429]);
    }

    // Mask IP
    $nppp_ep8_masked = nppp_mask_ip( $nppp_ep8_raw_ip );

    // Layer 1a
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- EP8 watchdog
    $submitted = isset($_POST['token'])
        ? sanitize_text_field(wp_unslash($_POST['token']))
        : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    if (empty($submitted) || !preg_match('/^[a-f0-9]{32}$/i', $submitted)) {
        nppp_ep_gate_log($nppp_ep8_masked, $nppp_ep8_raw_ip, 'ep8', 'nppp_cron_wake', 'ERROR 403 MALFORMED OR MISSING TOKEN');
        wp_die('', '', ['response' => 403]);
    }

    // Layer 1b
    $stored = get_transient('nppp_ping_token_' . md5('nppp'));
    if ( empty( $stored ) ) {
        $stored = get_option( 'nppp_ping_token_db', '' );
    }
    if (empty($stored) || !hash_equals((string) $stored, $submitted)) {
        nppp_ep_gate_log($nppp_ep8_masked, $nppp_ep8_raw_ip, 'ep8', 'nppp_cron_wake', 'ERROR 403 TOKEN MISMATCH OR EXPIRED');
        wp_die('', '', ['response' => 403]);
    }

    // Token verified
    nppp_load_bootstrap();
}, 1);

// ---------------------------------------------------------------------------
// EP9 — WP-CLI (`wp npp …`)
// Exposes cache purge, preload, status, log, settings, and scheduler to the
// `wp npp` command group.
//
//   1. Command REGISTRATION (require_once wp-cli.php) — always runs on any
//      WP-CLI invocation so that `wp help npp`, tab-completion, and
//      `--prompt` all work without the heavy bootstrap.
//   2. BOOTSTRAP (nppp_load_bootstrap) — loaded only when the user is
//      actually running `wp npp …`, so every other WP-CLI command pays
//      zero cost. Still a no-op on all normal web requests.
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    $nppp_cli_args      = WP_CLI::get_runner()->arguments;
    $nppp_is_npp_invoke = ! empty( $nppp_cli_args ) && $nppp_cli_args[0] === 'npp';

    add_action( 'plugins_loaded', function () use ( $nppp_is_npp_invoke ): void {
        // Heavy bootstrap: only when actually running `wp npp …`
        if ( $nppp_is_npp_invoke ) {
            nppp_load_bootstrap();
        }
        // Always register the command (cheap — just defines the class
        // and calls WP_CLI::add_command). Required for help, completions,
        // and --prompt on any WP-CLI invocation.
        require_once plugin_dir_path( __FILE__ ) . 'includes/wp-cli.php';
    }, 10 );

    unset( $nppp_cli_args, $nppp_is_npp_invoke );
}

// ---------------------------------------------------------------------------
// EP10 — Fail2ban webhook (/nppp_f2b/v1/event)
//
// Deliberately its own REST namespace. It shares nothing with EP3's
// nppp_nginx_cache/v2: not the "Enable REST API" master toggle, not the
// dummy-endpoint fallback in rest-api-helper.php, not the EP3 abuse counter.
// The two gates can never see each other's traffic — EP3 matches on the
// substring 'nppp_nginx_cache', which 'nppp_f2b' does not contain — so a
// single request is never logged or rate-limited by both.
//
// Same shape as EP3/EP8: the token is verified BEFORE nppp_load_bootstrap(),
// so an unauthenticated flood can never force the plugin stack to
// load on every request.
// ---------------------------------------------------------------------------
add_action('rest_api_init', function (): void {
    // Load the plugin bootstrap only for the exact Fail2Ban webhook route.
    $nppp_ep10_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';

    if ( ! is_string( $nppp_ep10_route ) ) {
        return;
    }

    if ( ! preg_match( '#^/nppp_f2b/v1/event/?$#i', $nppp_ep10_route ) ) {
        return;
    }

    // POST is the only method this route ever accepts.
    $nppp_ep10_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
    if ( 'POST' !== $nppp_ep10_method ) {
        return;
    }

    // Every exit below this point is a hard stop. Force the JSON die handler.
    add_filter('wp_die_handler', static function (): string {
        return '_json_wp_die_handler';
    }, 99);

    // Get IP
    $nppp_ep10_raw_ip = nppp_resolve_ip();

    // Mask IP for logging.
    $nppp_ep10_masked = nppp_mask_ip($nppp_ep10_raw_ip);

    // Strike counter, its own namespace (nppp_ep10_fail_*) so EP3 and EP10
    // never share a penalty budget. A locked-out IP is refused only if it
    // also presents a bad token (see the three failure exits below), so a
    // valid token always gets through and stale fail2ban configs can't lock
    // out the correct one.
    $nppp_ep10_rate_key = 'nppp_ep10_fail_' . hash('sha256', $nppp_ep10_raw_ip);
    $nppp_ep10_locked   = (int) get_transient($nppp_ep10_rate_key) >= 20;

    // Extract the bearer token.
    $nppp_ep10_auth = sanitize_text_field(
        wp_unslash($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')
    );

    // Last resort: Apache/mod_php without CGIPassAuth exposes the header via
    // apache_request_headers() but puts it in NEITHER $_SERVER key.
    if ($nppp_ep10_auth === '') {
        $nppp_ep10_headers = [];
        if (function_exists('getallheaders')) {
            $nppp_ep10_headers = getallheaders() ?: [];
        } elseif (function_exists('apache_request_headers')) {
            $nppp_ep10_headers = apache_request_headers() ?: [];
        }
        foreach ($nppp_ep10_headers as $nppp_ep10_h_name => $nppp_ep10_h_val) {
            if (strcasecmp($nppp_ep10_h_name, 'Authorization') === 0) {
                $nppp_ep10_auth = sanitize_text_field(wp_unslash((string) $nppp_ep10_h_val));
                if ($nppp_ep10_auth !== '') {
                    // Keep layer 2 in sync — see comment above.
                    $_SERVER['HTTP_AUTHORIZATION'] = $nppp_ep10_auth;
                }
                break;
            }
        }
        unset($nppp_ep10_headers, $nppp_ep10_h_name, $nppp_ep10_h_val);
    }

    $nppp_ep10_token = '';
    if (strpos($nppp_ep10_auth, 'Bearer ') === 0) {
        $nppp_ep10_token = substr($nppp_ep10_auth, 7);
    }
    $nppp_ep10_token = sanitize_text_field($nppp_ep10_token);

    // Missing token — logged and answered 403 rather
    // than left to fall through to a misleading core 404.
    if (empty($nppp_ep10_token)) {
        if ($nppp_ep10_locked) {
            wp_die('', '', ['response' => 429]);
        }
        nppp_ep_gate_log($nppp_ep10_masked, $nppp_ep10_raw_ip, 'ep10', 'nppp_f2b_event', 'ERROR 403 MISSING TOKEN (web server may not be forwarding the Authorization header to PHP)');
        wp_die('', '', ['response' => 403]);
    }

    // Wrong format — log and penalise.
    if (!preg_match('/^[a-f0-9]{64}$/i', $nppp_ep10_token)) {
        if ($nppp_ep10_locked) {
            wp_die('', '', ['response' => 429]);
        }
        nppp_ep_gate_log($nppp_ep10_masked, $nppp_ep10_raw_ip, 'ep10', 'nppp_f2b_event', 'ERROR 403 MALFORMED TOKEN');
        wp_die('', '', ['response' => 403]);
    }

    // Validate against the stored token — log and penalise on mismatch.
    $nppp_ep10_stored = get_option('nppp_f2b_token', '');
    if (!is_string($nppp_ep10_stored) || $nppp_ep10_stored === '' || !hash_equals($nppp_ep10_stored, $nppp_ep10_token)) {
        if ($nppp_ep10_locked) {
            wp_die('', '', ['response' => 429]);
        }
        nppp_ep_gate_log($nppp_ep10_masked, $nppp_ep10_raw_ip, 'ep10', 'nppp_f2b_event', 'ERROR 403 TOKEN MISMATCH');
        wp_die('', '', ['response' => 403]);
    }

    // Optional hard allow-list — empty by default, which accepts a
    // connection from any IP as long as it carries a valid token.
    //
    // Checked ONLY after the token above has already been verified. An
    // IP with no valid token never reaches this check -- it already got
    // rejected above with the generic "token rejected" response. So if
    // you see the allow-list error below, it means: right token, wrong
    // IP. That's a strong sign the caller really is your fail2ban, from
    // an IP you simply haven't added to the allow-list yet -- or a
    // misconfiguration of that allow-list.
    //
    // To turn this on, add the IP(s) fail2ban actually connects from to
    // a child theme's functions.php -- REPLACE_WITH_YOUR_IP below is not
    // a real address, it must be replaced with the value fail2ban's
    // requests actually show (see the Fail2Ban tab for how to find it):
    //   add_filter('nppp_f2b_trusted_ips', fn() => ['REPLACE_WITH_YOUR_IP']);
    $nppp_ep10_trusted = apply_filters('nppp_f2b_trusted_ips', []);
    if (!empty($nppp_ep10_trusted) && is_array($nppp_ep10_trusted)
        && !in_array($nppp_ep10_raw_ip, $nppp_ep10_trusted, true)) {
        // Log only (no strike/lockout penalty here) -- the caller already
        // proved it holds a valid token, so this isn't abuse, just a
        // config gap/issue.
        nppp_ep_gate_log($nppp_ep10_masked, $nppp_ep10_raw_ip, 'ep10', 'nppp_f2b_event', 'ERROR 403 IP NOT IN TRUSTED ALLOW-LIST (valid token -- possible misconfiguration in the nppp_f2b_trusted_ips allow-list)', false);
        // Hand the caller back its own observed source IP. That discloses
        // nothing it doesn't already know (it's the IP it connected
        // from), and since the token already checked out, it's safe to
        // suggest adding this exact IP to the allow-list.
        wp_send_json_error(
            array(
                'code'        => 'nppp_f2b_ip_not_trusted',
                'message'     => 'Token accepted, but the source IP is not in the nppp_f2b_trusted_ips allow-list.',
                'observed_ip' => $nppp_ep10_raw_ip,
            ),
            403
        );
    }

    // Token verified pre-bootstrap — safe to load the full plugin stack now.
    nppp_load_bootstrap();
}, 1);

// ---------------------------------------------------------------------------
// Front-end footprint — optional HTML comment in the page source.
// On by default. Disable in wp-config.php: define('NPPP_DISABLE_FOOTPRINT', true);
// The timestamp shows when this copy was generated; an old value means the
// page is being served from cache.
// ---------------------------------------------------------------------------
add_action('wp_head', static function (): void {
    // On by default. Opt out in wp-config.php: define('NPPP_DISABLE_FOOTPRINT', true);
    if (defined('NPPP_DISABLE_FOOTPRINT') && NPPP_DISABLE_FOOTPRINT) {
        return;
    }

    // Non-page contexts where comments have leaked in other plugins
    // (AJAX, CLI, cron, REST, JSON, XML-RPC, iframes, embeds).
    if (
        (defined('WP_CLI') && WP_CLI) ||
        (defined('DOING_CRON') && DOING_CRON) ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
        (defined('IFRAME_REQUEST') && IFRAME_REQUEST) ||
        wp_doing_ajax() ||
        (function_exists('wp_is_json_request') && wp_is_json_request()) ||
        is_embed() || is_feed() || is_customize_preview()
    ) {
        return;
    }

    // Arm only once per request, even if a theme calls wp_head() twice.
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;

    // Priority 0 runs before WordPress flushes its output buffers (shutdown
    // priority 1), so the comment lands inside any buffer, after </html>.
    add_action('shutdown', static function (): void {
        // Bail out if something switched the response to a non-HTML type.
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'html') === false) {
                return;
            }
        }

        $text = sprintf(
            'Performance optimized by Nginx Cache Purge Preload. Generated: %s GMT. Learn more: %s',
            gmdate('Y-m-d H:i:s'),
            'https://npp.psauxit.com/'
        );

        // Let sites customize or suppress it (return '' to disable).
        $text = apply_filters('nppp_footprint_comment', $text);
        if (!is_string($text) || $text === '') {
            return;
        }

        // A comment must never contain "--" (it could close the comment early).
        $text = preg_replace('/-{2,}/', '-', $text);

        printf("\n<!-- %s -->\n", esc_html((string) $text));
    }, 0);
}, PHP_INT_MAX);

// ---------------------------------------------------------------------------
// ACTIVATION — generates API key, writes default settings, triggers setup wizard.
// DEACTIVATION — clears scheduled cron events, terminates active preload process.
// ---------------------------------------------------------------------------
function nppp_on_activation() {
    nppp_maybe_define_assume_nginx();
    nppp_load_bootstrap();

    // Update-in-place installs are covered separately by migration 2.1.8 in includes/update.php
    if ( function_exists( 'nppp_f2b_install_table' ) ) {
        nppp_f2b_install_table();
    }

    // Grant the custom purge capability to Administrators on activation.
    $admin_role = get_role( 'administrator' );
    if ( $admin_role && ! isset( $admin_role->capabilities['nppp_purge_cache'] ) ) {
        $admin_role->add_cap( 'nppp_purge_cache' );
    }

    // Set setup redirect flag
    if (class_exists('\NPPP\Setup')) {
        \NPPP\Setup::nppp_set_activation_redirect_flag();
    } else {
        update_option('nppp_redirect_to_setup_once', 1, false);
    }

    if (function_exists('nppp_defaults_on_plugin_activation')) {
        nppp_defaults_on_plugin_activation();
    }

    if (function_exists('nppp_schedule_index_updater')) {
        nppp_schedule_index_updater();
    }
}

register_activation_hook(__FILE__, 'nppp_on_activation');
register_deactivation_hook(__FILE__, function() {
    nppp_load_bootstrap();
    nppp_reset_plugin_settings_on_deactivation();

    if (function_exists('nppp_unschedule_index_updater')) {
        nppp_unschedule_index_updater();
    }

    // Remove the custom purge capability from every role that holds it.
    foreach ( wp_roles()->role_objects as $role ) {
        if ( isset( $role->capabilities['nppp_purge_cache'] ) ) {
            $role->remove_cap( 'nppp_purge_cache' );
        }
    }
});
