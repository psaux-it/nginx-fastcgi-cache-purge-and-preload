<?php
/**
 * Settings registration for Nginx Cache Purge Preload
 * Description: Registers the settings group, settings section, and all settings fields.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Initializes the Nginx Cache settings by registering settings, adding settings section, and fields
function nppp_nginx_cache_settings_init() {
    // Register settings
    register_setting('nppp_nginx_cache_settings_group', 'nginx_cache_settings', 'nppp_nginx_cache_settings_sanitize');

    // Add settings section and fields
    add_settings_section('nppp_nginx_cache_settings_section', 'FastCGI Cache Purge & Preload Settings', 'nppp_nginx_cache_settings_section_callback', 'nppp_nginx_cache_settings_group');
    add_settings_field('nginx_cache_path', 'Nginx FastCGI Cache Path', 'nppp_nginx_cache_path_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_email', 'Email Address', 'nppp_nginx_cache_email_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_cpu_limit', 'CPU Usage Limit for Cache Preloading (10-100)', 'nppp_nginx_cache_cpu_limit_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_reject_regex', 'Excluded endpoints from cache preloading', 'nppp_nginx_cache_reject_regex_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_reject_extension', 'Excluded file extensions from cache preloading', 'nppp_nginx_cache_reject_extension_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_send_mail', 'Send Mail', 'nppp_nginx_cache_send_mail_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_logs', 'Logs', 'nppp_nginx_cache_logs_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_limit_rate', 'Limit Rate Definition', 'nppp_nginx_cache_limit_rate_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_auto_preload', 'Auto Preload', 'nppp_nginx_cache_auto_preload_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_api_key', 'API Key', 'nppp_nginx_cache_api_key_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_api', 'API', 'nppp_nginx_cache_api_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_schedule', 'Scheduled Cache', 'nppp_nginx_cache_schedule_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_purge_on_update', 'Purge Cache on Post/Page Update', 'nppp_nginx_cache_purge_on_update_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_cloudflare_apo_sync', 'Cloudflare APO Sync', 'nppp_nginx_cache_cloudflare_apo_sync_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_redis_cache_sync', 'Redis Object Cache Sync', 'nppp_nginx_cache_redis_cache_sync_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_related_pages', 'Related Pages (single-URL purge only)', 'nppp_nginx_cache_related_pages_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_wait_request', 'Per Request Wait Time', 'nppp_nginx_cache_wait_request_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_read_timeout', 'PHP Response Timeout', 'nppp_nginx_cache_read_timeout_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_key_custom_regex', 'Enable Custom regex', 'nppp_nginx_cache_key_custom_regex_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_auto_preload_mobile', 'Auto Preload Mobile', 'nppp_nginx_cache_auto_preload_mobile_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_mobile_user_agent', 'Mobile User Agent', 'nppp_nginx_cache_mobile_user_agent_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_watchdog', 'Preload Watchdog', 'nppp_nginx_cache_watchdog_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_enable_proxy', 'Enable Proxy', 'nppp_nginx_cache_enable_proxy_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_host', 'Proxy Host', 'nppp_nginx_cache_proxy_host_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_port', 'Proxy Port', 'nppp_nginx_cache_proxy_port_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_pctnorm_mode', 'Percent-encoding Case', 'nppp_nginx_cache_pctnorm_mode_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_enabled', 'HTTP Purge', 'nppp_http_purge_enabled_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_suffix', 'Purge URL Suffix', 'nppp_http_purge_suffix_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_custom_url', 'Purge Custom Base URL', 'nppp_http_purge_custom_url_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_rg_purge_enabled', 'RG Purge', 'nppp_rg_purge_enabled_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
}

// Add settings page
function nppp_add_nginx_cache_settings_page() {
    add_submenu_page(
        'options-general.php',
        'Nginx Cache',
        'Nginx Cache Purge Preload',
        'manage_options',
        'nginx_cache_settings',
        'nppp_nginx_cache_settings_page'
    );
}

// Setup mode
function nppp_is_assume_nginx_mode(): bool {
    // wp-config.php hard override
    if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) {
        return true;
    }

    // Runtime option set by Setup
    if ( (bool) get_option('nppp_assume_nginx_runtime', false) ) {
        return true;
    }

    return false;
}
