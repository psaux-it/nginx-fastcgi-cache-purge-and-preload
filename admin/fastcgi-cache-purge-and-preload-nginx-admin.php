<?php
/*
 * NPP admin bootstrap
 * Version:           2.1.4
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define a constant for the log file path
if (! defined('NGINX_CACHE_LOG_FILE')) {
    define('NGINX_CACHE_LOG_FILE', nppp_get_runtime_file('fastcgi_ops.log'));
}

// Define a constant for the desktop user agent
if (!defined('NPPP_USER_AGENT')) {
    define('NPPP_USER_AGENT', 'NPP/2.1.4 (NginxCacheWarm; device=desktop; Desktop)');
}

// Define a constant for the mobile user agent
if (!defined('NPPP_USER_AGENT_MOBILE')) {
    define('NPPP_USER_AGENT_MOBILE', 'NPP/2.1.4 (NginxCacheWarm; device=mobile; Mobile)');
}

// Define an Accept header constant that mimics a real browser request.
if (!defined('NPPP_HEADER_ACCEPT')) {
    define('NPPP_HEADER_ACCEPT', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,' . '*' . '/' . '*;q=0.8');
}

// Prepare PATH and SAFEXEC related env
function nppp_prepare_request_env(bool $force = false): void {
    static $done = false;

    // Allow re-run when explicitly forced.
    if ($done && !$force) {
        return;
    }

    if (!function_exists('getenv') || !function_exists('putenv')) {
        $done = true;
        return;
    }

    // register the shutdown restore ONCE
    if (empty($GLOBALS['NPPP__RESTORE_REGISTERED'])) {
        register_shutdown_function(static function () {
            // PATH
            if (array_key_exists('NPPP__ORIG_PATH', $GLOBALS)) {
                $orig = $GLOBALS['NPPP__ORIG_PATH'];
                if ($orig === null) putenv('PATH'); else putenv("PATH={$orig}");
            }

            // Always unset safexec related envs
            putenv('SAFEXEC_SAFE_CWD');
            putenv('SAFEXEC_QUIET');
            putenv('SAFEXEC_PCTNORM');
            putenv('SAFEXEC_PCTNORM_CASE');
            putenv('SAFEXEC_DETACH');
        });
        $GLOBALS['NPPP__RESTORE_REGISTERED'] = true;
    }

    // PATH
    $need = ['/usr/local/sbin','/usr/local/bin','/usr/sbin','/usr/bin','/sbin','/bin'];
    $orig_path = getenv('PATH');
    $parts = $orig_path ? array_filter(explode(':', (string)$orig_path), fn($p) => $p !== '' && $p !== '.') : [];
    $merged = array_values(array_unique(array_merge($need, $parts)));
    $new_path = implode(':', $merged);

    // Save only once so $force runs don't clobber the true original
    if (!array_key_exists('NPPP__ORIG_PATH', $GLOBALS)) {
        $GLOBALS['NPPP__ORIG_PATH'] = ($orig_path === false) ? null : $orig_path;
    }

    if ($new_path !== $orig_path) {
        putenv("PATH={$new_path}");
    }

    // Always quiet safexec
    putenv('SAFEXEC_QUIET=1');

    // Force hop to a safe CWD if current dir isn’t traversable
    putenv('SAFEXEC_SAFE_CWD=1');

    // safexec mode (off|upper|lower|preserve)
    $opts = get_option('nginx_cache_settings', []);
    $mode = isset($opts['nginx_cache_pctnorm_mode']) ? strtolower((string)$opts['nginx_cache_pctnorm_mode']) : 'off';
    if (!in_array($mode, ['off','upper','lower','preserve'], true)) $mode = 'off';

    switch ($mode) {
        case 'upper':
            putenv('SAFEXEC_PCTNORM=1');
            putenv('SAFEXEC_PCTNORM_CASE=upper');
            break;
        case 'lower':
            putenv('SAFEXEC_PCTNORM=1');
            putenv('SAFEXEC_PCTNORM_CASE=lower');
            break;
        case 'preserve':
            putenv('SAFEXEC_PCTNORM=1');
            putenv('SAFEXEC_PCTNORM_CASE=off');
            break;
        case 'off':
        default:
            putenv('SAFEXEC_PCTNORM=0');
            putenv('SAFEXEC_PCTNORM_CASE');
            break;
    }

    // Detach behavior for this request
    putenv('SAFEXEC_DETACH=auto');

    $done = true;
}

// Include plugin files
require_once dirname(__DIR__) . '/includes/enqueue-assets.php';
require_once dirname(__DIR__) . '/includes/wp-filesystem.php';
require_once dirname(__DIR__) . '/includes/purge-lock.php';
require_once dirname(__DIR__) . '/includes/pre-checks.php';
require_once dirname(__DIR__) . '/includes/admin-bar.php';
require_once dirname(__DIR__) . '/includes/log.php';
require_once dirname(__DIR__) . '/includes/svg.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/related.php';
require_once dirname(__DIR__) . '/includes/purge.php';
require_once dirname(__DIR__) . '/includes/purge-http.php';
require_once dirname(__DIR__) . '/includes/preload.php';
require_once dirname(__DIR__) . '/includes/help.php';
require_once dirname(__DIR__) . '/includes/configuration-parser.php';
require_once dirname(__DIR__) . '/includes/status.php';
require_once dirname(__DIR__) . '/includes/advanced.php';
require_once dirname(__DIR__) . '/includes/send-mail.php';
require_once dirname(__DIR__) . '/includes/schedule.php';
require_once dirname(__DIR__) . '/includes/watchdog.php';
require_once dirname(__DIR__) . '/includes/rest-api-helper.php';
require_once dirname(__DIR__) . '/includes/update.php';
require_once dirname(__DIR__) . '/includes/dashboard-widget.php';

// Get the status of Auto Purge
$nppp_options = get_option('nginx_cache_settings');
$nppp_auto_purge = isset($nppp_options['nginx_cache_purge_on_update'])
                   && $nppp_options['nginx_cache_purge_on_update'] === 'yes';

require_once dirname(__DIR__) . '/includes/compat-cloudflare.php';
require_once dirname(__DIR__) . '/includes/compat-elementor.php';
require_once dirname(__DIR__) . '/includes/compat-gutenberg.php';
require_once dirname(__DIR__) . '/includes/compat-redis-cache.php';
require_once dirname(__DIR__) . '/includes/compat-woocommerce.php';

// Hook into well-known cache plugin purge events.
$nppp_page_cache_purge_actions = array(
    'after_rocket_clean_domain',                // WP Rocket
    'hyper_cache_purged',                       // Hyper Cache
    'w3tc_flush_all',                           // W3 Total Cache
    'ce_action_cache_cleared',                  // Cache Enabler
    'comet_cache_wipe_cache',                   // Comet Cache
    'wp_cache_cleared',                         // WP Super Cache
    'wpfc_delete_cache',                        // WP Fastest Cache
    'swift_performance_after_clear_all_cache',  // Swift Performance
    'litespeed_purged_all'                      // LiteSpeed Cache
);

// Add actions and filters
add_action('load-settings_page_nginx_cache_settings', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets');
add_action('load-settings_page_nginx_cache_settings', 'nppp_check_for_plugin_update');
add_action('admin_enqueue_scripts', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_requisite_assets');
add_action('admin_bar_menu', 'nppp_add_fastcgi_cache_buttons_admin_bar', 100);
add_action('admin_init', 'nppp_handle_fastcgi_cache_actions_admin_bar');
add_action('admin_init', 'nppp_nginx_cache_settings_init');
add_action('admin_menu', 'nppp_add_nginx_cache_settings_page');
add_action('load-settings_page_nginx_cache_settings', 'nppp_pre_checks');
add_action('load-settings_page_nginx_cache_settings', 'nppp_manage_admin_notices');
add_action('wp_ajax_nppp_clear_nginx_cache_logs', 'nppp_clear_nginx_cache_logs');
add_action('wp_ajax_nppp_get_nginx_cache_logs', 'nppp_get_nginx_cache_logs');
add_action('wp_ajax_nppp_update_send_mail_option', 'nppp_update_send_mail_option');
add_action('wp_ajax_nppp_update_auto_preload_option', 'nppp_update_auto_preload_option');
add_action('wp_ajax_nppp_update_auto_purge_option', 'nppp_update_auto_purge_option');
add_action('wp_ajax_nppp_update_cloudflare_apo_sync_option', 'nppp_update_cloudflare_apo_sync_option');
add_action('wp_ajax_nppp_update_redis_cache_sync_option', 'nppp_update_redis_cache_sync_option');
add_action('wp_ajax_nppp_cache_status', 'nppp_cache_status_callback');
add_action('wp_ajax_nppp_load_premium_content', 'nppp_load_premium_content_callback');
add_action('wp_ajax_nppp_purge_cache_premium', 'nppp_purge_cache_premium_callback');
add_action('wp_ajax_nppp_preload_cache_premium', 'nppp_preload_cache_premium_callback');
add_action('wp_ajax_nppp_update_api_key_option', 'nppp_update_api_key_option');
add_action('wp_ajax_nppp_update_default_reject_regex_option', 'nppp_update_default_reject_regex_option');
add_action('wp_ajax_nppp_update_default_reject_extension_option', 'nppp_update_default_reject_extension_option');
add_action('wp_ajax_nppp_update_api_option', 'nppp_update_api_option');
add_action('wp_ajax_nppp_update_api_key_copy_value', 'nppp_update_api_key_copy_value');
add_action('wp_ajax_nppp_rest_api_purge_url_copy', 'nppp_rest_api_purge_url_copy');
add_action('wp_ajax_nppp_rest_api_preload_url_copy', 'nppp_rest_api_preload_url_copy');
add_action('wp_ajax_nppp_get_save_cron_expression', 'nppp_get_save_cron_expression');
add_action('wp_ajax_nppp_update_cache_schedule_option', 'nppp_update_cache_schedule_option');
add_action('wp_ajax_nppp_cancel_scheduled_event', 'nppp_cancel_scheduled_event_callback');
add_filter('cron_schedules', 'nppp_custom_monthly_schedule');
add_filter('cron_schedules', 'nppp_custom_every_min_schedule');
add_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback');
add_action('npp_cache_preload_status_event', 'nppp_create_scheduled_event_preload_status_callback');
add_action('wp_ajax_nppp_get_active_cron_events_ajax', 'nppp_get_active_cron_events_ajax');
add_action('wp_ajax_nppp_clear_plugin_cache', 'nppp_clear_plugin_cache_callback');
add_action('admin_post_save_nginx_cache_settings', 'nppp_handle_nginx_cache_settings_submission');
add_action('wp_ajax_nppp_update_default_cache_key_regex_option', 'nppp_update_default_cache_key_regex_option');
if ($nppp_auto_purge) {
    add_action('transition_post_status', 'nppp_purge_cache_on_update', 10, 3);
    add_action('delete_post', 'nppp_purge_cache_on_delete_post', 10, 2);
    add_action('wp_update_comment_count', 'nppp_purge_cache_on_comment_count', 10, 3);
    add_action('upgrader_process_complete', 'nppp_purge_cache_on_theme_plugin_update', 10, 2);
    add_action('automatic_updates_complete', 'nppp_purge_cache_on_auto_update');
    add_action('switch_theme', 'nppp_purge_cache_on_theme_switch', 10, 3);
    add_action('activated_plugin', 'nppp_purge_cache_plugin_activation_deactivation');
    add_action('deactivated_plugin', 'nppp_purge_cache_plugin_activation_deactivation');
}
add_action('wp_ajax_nppp_update_auto_preload_mobile_option', 'nppp_update_auto_preload_mobile_option');
add_action('wp_ajax_nppp_update_watchdog_option', 'nppp_update_watchdog_option');
add_action('wp_dashboard_setup', 'nppp_add_dashboard_widget');
add_action('wp_ajax_nppp_update_enable_proxy_option', 'nppp_update_enable_proxy_option');
add_action('wp_ajax_nppp_update_related_fields', 'nppp_update_related_fields');
add_action('wp_ajax_nppp_locate_cache_file', 'nppp_locate_cache_file_ajax');
add_action('wp_ajax_nppp_update_pctnorm_mode', 'nppp_update_pctnorm_mode');
add_action('wp_ajax_nppp_refresh_cache_ratio', 'nppp_refresh_cache_ratio_callback');
$nppp_auto_purge
    ? array_map(function($purge_action) { add_action($purge_action, 'nppp_purge_callback'); }, $nppp_page_cache_purge_actions)
    : array_map(function($purge_action) { remove_action($purge_action, 'nppp_purge_callback'); }, $nppp_page_cache_purge_actions);
$nppp_auto_purge
    ? (class_exists('autoptimizeCache') && add_action('autoptimize_action_cachepurged', 'nppp_purge_callback'))
    : (class_exists('autoptimizeCache') && remove_action('autoptimize_action_cachepurged', 'nppp_purge_callback'));
add_action('nppp_plugin_admin_notices', function($type, $message, $log_message, $display_notice) {
    // Check if admin notice should be displayed
    if (!$display_notice) {
        return;
    }

    // Define allowed notice types to prevent unexpected classes
    $allowed_types = array('success', 'error', 'warning', 'info');

    // Validate and sanitize the notice type
    if (!in_array($type, $allowed_types, true)) {
        $type = 'info';
    } else {
        $type = sanitize_key($type);
    }

    // Sanitize the message
    $sanitized_message = sanitize_text_field($message);

    // Output the notice directly with proper escaping
    ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible notice-nppp">
        <p><?php echo esc_html($sanitized_message); ?></p>
    </div>
    <?php
}, 10, 4);

// Register shortcodes
add_shortcode('nppp_svg_icon', 'nppp_svg_icon_shortcode');
add_shortcode('nppp_my_status', 'nppp_my_status_shortcode');
add_shortcode('nppp_my_faq', 'nppp_my_faq_shortcode');
add_shortcode('nppp_nginx_config', 'nppp_nginx_config_shortcode');
