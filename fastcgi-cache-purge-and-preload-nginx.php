<?php
/*
 * Plugin Name:       Nginx Cache Purge Preload
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       The most comprehensive solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.
 * Version:           2.1.5
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
    define('NPPP_PLUGIN_VERSION', '2.1.5');
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

    // Extract API key — Bearer header → X-Api-Key header → request body.
    $api_key = '';
    $auth_header = sanitize_text_field(
        wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' )
    );

    if (strpos($auth_header, 'Bearer ') === 0) $api_key = substr($auth_header, 7);

    if (empty($api_key)) {
        $api_key = sanitize_text_field(
            wp_unslash( $_SERVER['HTTP_X_API_KEY'] ?? '' )
        );
    }

    if (empty($api_key)) {
        $content_type = sanitize_text_field(
            wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' )
        );

        if (strpos($content_type, 'application/json') !== false) {
            $body    = json_decode(file_get_contents('php://input'), true);
            $api_key = isset($body['api_key']) && is_string($body['api_key']) ? $body['api_key'] : '';
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST API bearer token prescreen, not a form submission
            $api_key = isset($_POST['api_key']) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        }
    }

    // Reject invalid format before touching the database.
    $api_key = sanitize_text_field($api_key);
    if (empty($api_key) || !preg_match('/^[a-f0-9]{64}$/i', $api_key)) return;

    // Validate against stored key.
    $options    = get_option('nginx_cache_settings');
    $stored_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : '';
    if (!is_string($stored_key) || !hash_equals($stored_key, $api_key)) return;

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

    $opts = get_option('nginx_cache_settings');
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
    $opts = get_option('nginx_cache_settings');
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;

    nppp_load_bootstrap();
}, 1);

// ---------------------------------------------------------------------------
// EP8 — Watchdog AJAX (nopriv — token-gated)
// The watchdog process POSTs after preload finishes.
// EP1 never fires for nopriv requests so bootstrap must be loaded explicitly
// for this one action.
//
// Two-layer gate — bootstrap only loads when the token is genuinely valid:
//   Layer 1 (here)   : format check + transient existence + hash_equals
//   Layer 2 (handler): rate limit + same checks again as defense-in-depth
// ---------------------------------------------------------------------------
add_action('init', function(): void {
    if (!wp_doing_ajax()) return;

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- EP8 watchdog
    $action = isset($_POST['action'])
        ? sanitize_key(wp_unslash($_POST['action']))
        : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    if ($action !== 'nppp_cron_wake') return;

    // Watchdog feature disabled — endpoint intentionally unreachable.
    $opts = get_option('nginx_cache_settings', []);
    if (($opts['nginx_cache_watchdog'] ?? 'no') !== 'yes') {
        wp_die('', '', ['response' => 403]);
    }

    // Layer 1a
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- EP8 watchdog
    $submitted = isset($_POST['token'])
        ? sanitize_text_field(wp_unslash($_POST['token']))
        : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    if (empty($submitted) || !preg_match('/^[a-f0-9]{32}$/i', $submitted)) {
        wp_die('', '', ['response' => 403]);
    }

    // Layer 1b
    $stored = get_transient('nppp_ping_token_' . md5('nppp'));
    if (empty($stored) || !hash_equals((string) $stored, $submitted)) {
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
