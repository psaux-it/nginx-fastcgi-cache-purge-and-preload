<?php
/*
 * Plugin Name:       Nginx Cache Purge Preload
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       The most comprehensive solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.
 * Version:           2.1.6
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
// Bootstrap loads 26+ files including shell_exec/proc_open (preload),
// WP_Filesystem recursive ops (purge), and binary detection (pre-checks).
// Loading this stack unconditionally is a performance and security
// liability — so the plugin stays dormant on 99% of requests.
//
// Each entry point below is a narrow gate. Only authenticated requests
// from users who can trigger a cache operation pass through. Everything
// else bails before a single plugin file loads.
//
// Admin users (manage_options): full UI access via EP1, EP4, EP6.
// Capability holders (nppp_purge_cache): bootstrap loaded only when
// auto-purge is enabled — solely to register the cache-purge hook on
// content saves. All settings and AJAX handlers remain admin-only.
// ---------------------------------------------------------------------------

// Compatibility mode for environments where Nginx cannot be auto-detected
// during setup. Activated via setup wizard or manually via wp-config.php.
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

// Single source of truth for the runtime directory
if (!defined('NPPP_RUNTIME_SUBDIR')) {
    define('NPPP_RUNTIME_SUBDIR', 'nginx-cache-purge-preload-runtime');
}

// Single source of truth for the plugin version
if (!defined('NPPP_PLUGIN_VERSION')) {
    define('NPPP_PLUGIN_VERSION', '2.1.6');
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
] as $nppp_cron_event) {
    add_action($nppp_cron_event, 'nppp_load_bootstrap', 0);
}
unset($nppp_cron_event);

// ---------------------------------------------------------------------------
// Shared pre-bootstrap abuse logger — used by EP3 and EP8 gates.
// Increments the per-IP fail counter and writes to the plugin log at
// attempt #1 and every 5th attempt thereafter to avoid log flooding.
// ---------------------------------------------------------------------------
function nppp_ep_gate_log(
    string $masked,
    string $raw,
    string $ep,
    string $action,
    string $status
): void {
    $rate_key   = 'nppp_' . $ep . '_fail_' . hash( 'sha256', $raw );
    $fail_count = (int) get_transient($rate_key);
    $fail_count++;
    set_transient($rate_key, $fail_count, HOUR_IN_SECONDS);

    if ($fail_count !== 1 && $fail_count % 5 !== 0) {
        return;
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
// ACTIVATION — generates API key, writes default settings, triggers setup wizard.
// DEACTIVATION — clears scheduled cron events, terminates active preload process.
// ---------------------------------------------------------------------------
function nppp_on_activation() {
    nppp_maybe_define_assume_nginx();
    nppp_load_bootstrap();

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
