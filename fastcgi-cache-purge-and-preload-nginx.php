<?php
/*
 * Plugin Name:       Nginx Cache Purge Preload
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       The most comprehensive solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.
 * Version:           2.1.4
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       fastcgi-cache-purge-and-preload-nginx
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      7.4
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Enable compatibility mode when explicitly configured.
function nppp_maybe_define_assume_nginx(): void {
    if (! defined('NPPP_ASSUME_NGINX') && get_option('nppp_assume_nginx_runtime')) {
        define('NPPP_ASSUME_NGINX', true);
    }
}
add_action('plugins_loaded', 'nppp_maybe_define_assume_nginx', 0);

// Define the plugin main file
if (!defined('NPPP_PLUGIN_FILE')) {
    define('NPPP_PLUGIN_FILE', __FILE__);
}

// Bootstrap loader
function nppp_load_bootstrap(): void {
    // Load runtime path helpers
    require_once plugin_dir_path(__FILE__) . 'includes/runtime-paths.php';
    // Auto class loader
    require_once plugin_dir_path(__FILE__) . 'includes/autoload.php';
    // Main admin bootstrap
    require_once plugin_dir_path(__FILE__) . 'admin/fastcgi-cache-purge-and-preload-nginx-admin.php';
}

// Entry point 1: Admin bootstrap
add_action('init', function (): void {
    if (!is_admin()) return;
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    nppp_load_bootstrap();
}, 1);

// Entry point 2: WP-Cron — NPP events only
foreach (['npp_cache_preload_event', 'npp_cache_preload_status_event', 'npp_plugin_tracking_event'] as $nppp_cron_event) {
    add_action($nppp_cron_event, 'nppp_load_bootstrap', 0);
}
unset($nppp_cron_event);

// Entry point 3: REST API — NPP namespace only
add_action('rest_api_init', function (): void {
    $rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
    if (strpos($rest_route, 'nppp_nginx_cache') === false) {
        return;
    }

    // Protect preload progress endpoint
    if (strpos($rest_route, 'preload-progress') !== false) {
        if (!is_user_logged_in()) return;
        nppp_load_bootstrap();
        return;
    }

    // Full REST Auth Prescreen:
    // Avoid full plugin bootstrap on unauthenticated or invalid REST requests.
    $api_key = '';
    $auth_header = sanitize_text_field(
        wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' )
    );

    // Check Authorization Header
    if (strpos($auth_header, 'Bearer ') === 0) $api_key = substr($auth_header, 7);

    // Fallback: X-Api-Key Header
    if (empty($api_key)) {
        $api_key = sanitize_text_field(
            wp_unslash( $_SERVER['HTTP_X_API_KEY'] ?? '' )
        );
    }

    // Fallback: Request Body
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

    // Sanitize API Key
    $api_key = sanitize_text_field($api_key);
    if (empty($api_key) || !preg_match('/^[a-f0-9]{64}$/i', $api_key)) return;

    // REST auth
    $options    = get_option('nginx_cache_settings');
    $stored_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : '';
    if (!is_string($stored_key) || !hash_equals($stored_key, $api_key)) return;

    nppp_load_bootstrap();
}, 1);

// Entry point 4: Frontend bootstrap
add_action('init', function (): void {
    if (is_admin()) return;
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    nppp_load_bootstrap();

    // Main frontend bootstrap
    require_once plugin_dir_path(__FILE__) . 'frontend/fastcgi-cache-purge-and-preload-nginx-front.php';
}, 1);

// Entry point 5: Setup flow
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

// Activation handler
function nppp_on_activation() {
    nppp_maybe_define_assume_nginx();
    nppp_load_bootstrap();

    // Set setup redirect flag
    if (class_exists('\NPPP\Setup')) {
        \NPPP\Setup::nppp_set_activation_redirect_flag();
    } else {
        update_option('nppp_redirect_to_setup_once', 1, false);
    }

    if (function_exists('nppp_defaults_on_plugin_activation')) {
        nppp_defaults_on_plugin_activation();
    }
}

// Register activation hook
register_activation_hook(__FILE__, 'nppp_on_activation');

// Register deactivation hook
register_deactivation_hook(__FILE__, function() {
    nppp_load_bootstrap();
    nppp_reset_plugin_settings_on_deactivation();
});
