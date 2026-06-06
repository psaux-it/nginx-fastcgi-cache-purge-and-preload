<?php
/**
 * Settings registration for Nginx Cache Purge Preload
 * Description: Registers the settings group, settings section, and all settings fields.
 * Version: 2.1.6
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
    add_settings_field('nginx_cache_bypass_path_restriction', 'Bypass Path Restriction', 'nppp_nginx_cache_bypass_path_restriction_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
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
    add_settings_field('nginx_cache_preload_feeds', 'Preload Feeds', 'nppp_nginx_cache_preload_feeds_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
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

/**
 * Fires before every update_option( 'nginx_cache_settings', ... ) call,
 * an AJAX, and WP-CLI handler. Flushes state that depends on the cache path
 * or cache key regex when either value changes.
 *
 * Covers the eight direct update_option() calls in settings-ajax.php that
 * bypass nppp_nginx_cache_settings_sanitize() and would otherwise leave the
 * URL index and regex probe transient stale.
 *
 * @param mixed $new_value  The new option value about to be saved.
 * @param mixed $old_value  The current stored option value.
 * @return mixed            $new_value unchanged.
 */
function nppp_before_settings_option_update( $new_value, $old_value ) {
    if ( ! is_array( $new_value ) || ! is_array( $old_value ) ) {
        return $new_value;
    }

    $old_path = isset( $old_value['nginx_cache_path'] )
        ? rtrim( $old_value['nginx_cache_path'], '/' )
        : '';
    $new_path = isset( $new_value['nginx_cache_path'] )
        ? rtrim( $new_value['nginx_cache_path'], '/' )
        : '';

    if ( $old_path !== '' && $new_path !== '' && $old_path !== $new_path ) {
        delete_option( 'nppp_url_filepath_index' );
        nppp_display_admin_notice(
            'info',
            sprintf(
                /* translators: 1: old cache path 2: new cache path */
                __( 'INFO INDEX CLEARED: URL→filepath index flushed — cache path changed from %1$s to %2$s (pre-update option filter).', 'fastcgi-cache-purge-and-preload-nginx' ),
                $old_path,
                $new_path
            ),
            true,
            false
        );
        $static_key_base = 'nppp';
        $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
        delete_transient($transient_key_permissions_check);
        delete_transient( 'nppp_cache_key_regex_probe' );
    }

    $old_regex = $old_value['nginx_cache_key_custom_regex'] ?? '';
    $new_regex = $new_value['nginx_cache_key_custom_regex'] ?? '';
    if ( $old_regex !== $new_regex ) {
        delete_transient( 'nppp_cache_key_regex_probe' );
    }

    // Enforce feed exclusion rules in reject_regex whenever:
    //   (a) the preload_feeds toggle itself changed, OR
    //   (b) reject_regex was edited while preload_feeds stayed the same.
    $preload_feeds_changed = isset( $new_value['nginx_cache_preload_feeds'], $old_value['nginx_cache_preload_feeds'] )
        && $new_value['nginx_cache_preload_feeds'] !== $old_value['nginx_cache_preload_feeds'];

    $reject_regex_changed = ( $old_value['nginx_cache_reject_regex'] ?? '' )
        !== ( $new_value['nginx_cache_reject_regex'] ?? '' );

    if ( $preload_feeds_changed || $reject_regex_changed ) {
        $reject_regex  = $new_value['nginx_cache_reject_regex'] ?? nppp_fetch_default_reject_regex();
        $feeds_enabled = ( $new_value['nginx_cache_preload_feeds'] ?? 'no' ) === 'yes';

        if ( $feeds_enabled ) {
            // Remove both feed tokens in every possible position
            foreach ( [ '|/feed/', '/feed/|', '/feed/', '|[?&]feed=', '[?&]feed=|', '[?&]feed=' ] as $token ) {
                $reject_regex = str_replace( $token, '', $reject_regex );
            }
            // Collapse any double or orphan pipes left behind
            $reject_regex = preg_replace( '/\|{2,}/', '|', $reject_regex );
            $reject_regex = trim( $reject_regex, '|' );
        } else {
            if ( strpos( $reject_regex, '/feed/' ) === false ) {
                $reject_regex .= '|/feed/';
            }
            if ( strpos( $reject_regex, 'feed=' ) === false ) {
                $reject_regex .= '|[?&]feed=';
            }
        }

        $new_value['nginx_cache_reject_regex'] = $reject_regex;
    }

    return $new_value;
}

/**
 * Flushes the URL→filepath index and cache-key regex probe transient when
 * the WordPress permalink structure changes.
 *
 * Nginx derives cache file paths from an MD5 of the full cache key string
 * ($scheme$request_method$host$request_uri). A permalink structure change
 * modifies $request_uri for every post, producing entirely new MD5 hashes
 * and filesystem paths. Without this flush, FP2 finds old-path entries that
 * pass the prefix check (any_prefix_match=true) but whose files have been
 * evicted by nginx (any_valid=false), concludes "confirmed miss", removes
 * the URL from pending, and never reaches FP3/FP4 — leaving new-permalink
 * cached content permanently unpurged.
 *
 * @param string $old_permalink_structure  Previous permalink format string.
 * @param string $new_permalink_structure  Newly saved permalink format string.
 */
function nppp_on_permalink_structure_changed( string $old_permalink_structure, string $new_permalink_structure ): void {
    if ( $old_permalink_structure === $new_permalink_structure ) {
        return;
    }

    delete_option( 'nppp_url_filepath_index' );
    nppp_display_admin_notice(
        'info',
        sprintf(
            /* translators: 1: old permalink structure 2: new permalink structure */
            __( 'INFO INDEX CLEARED: URL→filepath index flushed — permalink structure changed from %1$s to %2$s. Cache entries have new filesystem paths.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $old_permalink_structure !== '' ? $old_permalink_structure : __( '(plain)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $new_permalink_structure !== '' ? $new_permalink_structure : __( '(plain)', 'fastcgi-cache-purge-and-preload-nginx' )
        ),
        true,
        false
    );
}
