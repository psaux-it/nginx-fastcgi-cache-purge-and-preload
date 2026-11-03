<?php
/**
 * Admin asset loading for Nginx Cache Purge Preload
 * Description: Enqueues plugin CSS and JavaScript assets required by admin pages and tabs.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue CSS and JavaScript files only plugin settings page load
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS file for jQuery UI manually (Not bundled with WP)
    wp_enqueue_style('nppp_jquery-ui-css', plugins_url('../admin/css/jquery-ui.min.css', __FILE__), array(), '1.14.2');

    // Enqueue CSS files for dataTables
    wp_enqueue_style('nppp_datatables-css', plugins_url('../admin/css/dataTables.min.css', __FILE__), array(), '3.0.3');

    // Enqueue CSS files for Tempus Dominus Date/Time Picker
    wp_enqueue_style('nppp_tempus-dominus-css', plugins_url('../admin/css/tempus-dominus.min.css', __FILE__), array(), '6.10.4');

    // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
    wp_enqueue_style('nppp_admin-css', plugins_url('../admin/css/fastcgi-cache-purge-and-preload-nginx.css', __FILE__), array(), '2.1.8');

    // Flag Icons
    // https://github.com/lipis/flag-icons
    // Licensed under the MIT License.
    // Enqueue CSS files for Flag Icons (Fail2Ban Tab)
    wp_enqueue_style('nppp-flag-icons', plugins_url('../admin/css/flag-icons.css', __FILE__), array(), '7.5.0');

    // jsVectorMap
    // https://www.npmjs.com/package/jsvectormap
    // Licensed under the MIT License.
    // Enqueue CSS + JS for the Fail2Ban Tab "Top Attack Countries" world map.
    wp_enqueue_style('nppp-jsvectormap-css', plugins_url('../admin/css/jsvectormap.min.css', __FILE__), array(), '1.7.0');
    wp_enqueue_script('nppp-jsvectormap-js', plugins_url('../admin/js/jsvectormap.min.js', __FILE__), array(), '1.7.0', true);
    // World map dataset. Must load after jsvectormap.min.js.
    wp_enqueue_script('nppp-jsvectormap-world-js', plugins_url('../admin/js/world.js', __FILE__), array('nppp-jsvectormap-js'), '1.7.0', true);
    // Bubble-marker init logic for the Top Attack Countries panel.
    // Must load after jsVectorMap + world map.
    wp_enqueue_script('nppp-f2b-country-map-js', plugins_url('../admin/js/nppp-f2b-country-map.js', __FILE__), array('nppp-jsvectormap-world-js'), '2.1.8', true);

    // Bar chart for the Repeat Offenders panel in Fail2Ban Jail Monitoring Tab
    wp_enqueue_script('nppp-f2b-offenders-chart-js', plugins_url('../admin/js/nppp-f2b-offenders-chart.js', __FILE__), array(), '2.1.8', true);

    // Enqueue jQuery UI core, jQuery UI Tabs, jQuery UI Accordion
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-accordion');

    /*!
    * DataTables v3.0.3
    * https://datatables.net/
    * Copyright 2008-2024, SpryMedia Ltd.
    * License: MIT (https://datatables.net/license/mit)
    */
    // Enqueue JavaScript files for dataTables
    wp_enqueue_script('nppp_datatables-js', plugins_url('../admin/js/dataTables.min.js', __FILE__), array('jquery'), '3.0.3', true);

    /*!
    * Tempus Dominus Date Time Picker v6.10.4
    * https://getdatepicker.com/
    * Copyright 2021 Tempus Dominus
    * License: MIT (https://github.com/Eonasdan/tempus-dominus/blob/master/LICENSE)
    */
    // Enqueue JavaScript files for Tempus Dominus Date/Time Picker
    wp_enqueue_script('nppp_popper-js', plugins_url('../admin/js/popper.min.js', __FILE__), array(), '2.11.8', true);
    wp_enqueue_script('nppp_tempus-dominus-js', plugins_url('../admin/js/tempus-dominus.min.js', __FILE__), array('nppp_popper-js'), '6.10.4', true);

    // Enqueue JavaScript (main) file for Nginx Cache Purge Preload Plugin
    wp_enqueue_script('nppp_admin-js', plugins_url('../admin/js/fastcgi-cache-purge-and-preload-nginx.js', __FILE__), array('jquery', 'jquery-ui-core', 'jquery-ui-tabs', 'jquery-ui-accordion', 'nppp_datatables-js', 'nppp_tempus-dominus-js', 'wp-i18n'), '2.1.8', true);

    // Enqueue JavaScript (header effect) file for Nginx Cache Purge Preload Plugin
    wp_enqueue_script('nppp_aurora-canvas', plugins_url('../admin/js/nppp-header.js', __FILE__), array(), '2.1.8', true);

    // Set script i18n translations
    wp_set_script_translations('nppp_admin-js', 'fastcgi-cache-purge-and-preload-nginx');

    // Advanced tab in Varnish-only mode: live HIT / MISS from Varnish. Inert unless the table carries
    // data-nppp-vo (the mode can be switched without a page reload, so the file is always loaded).
    wp_enqueue_script('nppp-vo-advanced-js', plugins_url('../admin/js/nppp-vo-advanced.js', __FILE__), array('jquery', 'nppp_admin-js'), '2.1.9', true);
    wp_localize_script('nppp-vo-advanced-js', 'nppp_vo_data', array(
        'probe_nonce' => wp_create_nonce('nppp_vo_probe_nonce'),
        'i18n'        => array(
            'unsupported' => __( 'Varnish does not answer the live status lookup, so the Status column is empty. Add the probe block of the Example VCL (Help tab), then press Check all.', 'fastcgi-cache-purge-and-preload-nginx' ),
            'denied'      => __( 'Varnish refused the live status lookup. Check the VCL purge ACL, the control header and the probe block (Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ),
            'unreachable' => __( 'Varnish is not reachable for the live status lookup.', 'fastcgi-cache-purge-and-preload-nginx' ),
            'parked'      => __( 'The Varnish endpoint is parked after a failure. Try again in a few seconds.', 'fastcgi-cache-purge-and-preload-nginx' ),
            'error'       => __( 'The live status lookup failed.', 'fastcgi-cache-purge-and-preload-nginx' ),
            'stillCached' => __( 'Varnish still serves this URL after the purge. A visitor may have re-cached it; if it persists, check the VCL purge handler (Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ),
            'checking'    => __( 'Checking Varnish…', 'fastcgi-cache-purge-and-preload-nginx' ),
            'progress'    => __( 'Preload sent, waiting for Varnish to cache it', 'fastcgi-cache-purge-and-preload-nginx' ),
            'unknown'     => __( 'Status unknown', 'fastcgi-cache-purge-and-preload-nginx' ),
            'hit'         => __( 'Cached in Varnish', 'fastcgi-cache-purge-and-preload-nginx' ),
            'stale'       => __( 'Cached in Varnish, past its TTL (served stale while it is refreshed)', 'fastcgi-cache-purge-and-preload-nginx' ),
            'miss'        => __( 'Not cached in Varnish (or the page is not cacheable)', 'fastcgi-cache-purge-and-preload-nginx' ),
            'pass'        => __( 'Varnish passes this URL to the origin (hit-for-pass)', 'fastcgi-cache-purge-and-preload-nginx' ),
            /* translators: 1: checked URLs, 2: total URLs */
            'checkedOf'   => __( 'Checked %1$s of %2$s URLs…', 'fastcgi-cache-purge-and-preload-nginx' ),
            'checkedAll'  => __( 'Checked all URLs.', 'fastcgi-cache-purge-and-preload-nginx' ),
            /* translators: 1: URLs with a known status, 2: URLs in the table */
            'missPartial' => __( '%1$s of %2$s URLs checked so far. Press Check all for the full count.', 'fastcgi-cache-purge-and-preload-nginx' ),
        ),
    ));

    // Status tab card in Varnish-only mode: the "Check now" button of the Live cache check. Inert unless the
    // button exists (the tab is loaded by AJAX and the mode can be switched without a page reload).
    wp_enqueue_script('nppp-vo-status-js', plugins_url('../admin/js/nppp-vo-status.js', __FILE__), array('jquery', 'nppp_admin-js'), '2.1.9', true);
    wp_localize_script('nppp-vo-status-js', 'nppp_vo_status_data', array(
        'nonce' => wp_create_nonce('nppp_vo_status_nonce'),
        'i18n'  => array(
            'checking' => __( 'Checking…', 'fastcgi-cache-purge-and-preload-nginx' ),
            'error'    => __( 'The live check failed.', 'fastcgi-cache-purge-and-preload-nginx' ),
        ),
    ));

    // Create a nonce for clearing nginx cache logs
    $clear_nginx_cache_logs_nonce = wp_create_nonce('nppp-clear-nginx-cache-logs');
    // Create a nonce for updating send mail option
    $update_send_mail_option_nonce = wp_create_nonce('nppp-update-send-mail-option');
    // Create a nonce for the status tab
    $cache_status_nonce = wp_create_nonce('cache-status');
    // Create a nonce for the Fail2Ban Tab (fail2ban monitor)
    $security_tab_nonce = wp_create_nonce('nppp-security-tab');
    // Create a nonce for auto preload option
    $update_auto_preload_option_nonce = wp_create_nonce('nppp-update-auto-preload-option');
    // Create a nonce for auto purge option
    $update_auto_purge_option_nonce = wp_create_nonce('nppp-update-auto-purge-option');
    // Create a nonce for Cloudflare APO sync option
    $update_cloudflare_apo_sync_option_nonce = wp_create_nonce('nppp-update-cloudflare-apo-sync-option');
    // Create a nonce for Redis Object Cache sync option
    $update_redis_cache_sync_option_nonce = wp_create_nonce('nppp-update-redis-cache-sync-option');
    // Create a nonce for api key option
    $update_api_key_option_nonce = wp_create_nonce('nppp-update-api-key-option');
    // Create a nonce for default reject regex
    $update_default_reject_regex_option_nonce = wp_create_nonce('nppp-update-default-reject-regex-option');
    // Create a nonce for rest api option
    $update_api_option_nonce = wp_create_nonce('nppp-update-api-option');
    // Create a nonce for api key copy
    $update_api_key_copy_value_nonce = wp_create_nonce('nppp-update-api-key-copy-value');
    // Create a nonce for api purge url copy
    $update_rest_api_purge_url_copy_nonce = wp_create_nonce('nppp-rest-api-purge-url-copy');
    // Create a nonce for api preload url copy
    $update_rest_api_preload_url_copy_nonce = wp_create_nonce('nppp-rest-api-preload-url-copy');
    // Create a nonce for get and save cron expression
    $update_cron_expression_option_nonce = wp_create_nonce('nppp-get-save-cron-expression');
    // Create a nonce for cache schedule option
    $update_cache_schedule_option_nonce = wp_create_nonce('nppp-update-cache-schedule-option');
    // Create a nonce for cancel scheduled event
    $cancel_scheduled_event_nonce = wp_create_nonce('nppp-cancel-scheduled-event');
    // Create a nonce for plugin cache clear
    $clear_plugin_cache_nonce = wp_create_nonce('nppp-clear-plugin-cache-action');
    // Create a nonce for URL index clear
    $clear_url_index_nonce = wp_create_nonce('nppp-clear-url-index');
    // Create a nonce for default reject extension
    $update_default_reject_extension_option_nonce = wp_create_nonce('nppp-update-default-reject-extension-option');
    // Create a nonce for default cache key regex
    $update_default_cache_key_regex_option_nonce = wp_create_nonce('nppp-update-default-cache-key-regex-option');
    // Create a nonce for preload mobile option
    $update_auto_preload_mobile_option_nonce = wp_create_nonce('nppp-update-auto-preload-mobile-option');
    // Create a nonce for preload feeds option
    $update_preload_feeds_option_nonce = wp_create_nonce('nppp-update-preload-feeds-option');
    // Create a nonce for mobile user agent reset
    $update_default_mobile_user_agent_option_nonce = wp_create_nonce('nppp-update-default-mobile-user-agent-option');
    // Create a nonce for watchdog option
    $update_watchdog_option_nonce = wp_create_nonce('nppp-update-watchdog-option');
    // Create a nonce for enable proxy option
    $update_enable_proxy_option_nonce = wp_create_nonce('nppp-update-enable-proxy-option');
    // Create a nonce for HTTP purge fast-path toggle
    $update_http_purge_option_nonce = wp_create_nonce('nppp-update-http-purge-option');
    // Create a nonce for Varnish / Vinyl Cache Sync toggle
    $update_varnish_sync_option_nonce = wp_create_nonce('nppp-update-varnish-sync-option');
    $update_varnish_only_option_nonce = wp_create_nonce('nppp-update-varnish-only-option');
    // Create a nonce for Ripgrep Turbo Purge toggle
    $update_rg_purge_option_nonce = wp_create_nonce('nppp-update-rg-purge-option');

    // Localize nonce values for plugin main js
    wp_localize_script('nppp_admin-js', 'nppp_admin_data', array(
        'ajaxurl'                      => admin_url('admin-ajax.php'),
        'clear_logs_nonce'             => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce'              => $update_send_mail_option_nonce,
        'auto_preload_nonce'           => $update_auto_preload_option_nonce,
        'auto_preload_mobile_nonce'    => $update_auto_preload_mobile_option_nonce,
        'preload_feeds_nonce'          => $update_preload_feeds_option_nonce,
        'watchdog_nonce'               => $update_watchdog_option_nonce,
        'auto_purge_nonce'             => $update_auto_purge_option_nonce,
        'cloudflare_apo_sync_nonce'    => $update_cloudflare_apo_sync_option_nonce,
        'redis_cache_sync_nonce'       => $update_redis_cache_sync_option_nonce,
        'api_key_nonce'                => $update_api_key_option_nonce,
        'api_key_copy_nonce'           => $update_api_key_copy_value_nonce,
        'api_preload_url_copy_nonce'   => $update_rest_api_preload_url_copy_nonce,
        'api_purge_url_copy_nonce'     => $update_rest_api_purge_url_copy_nonce,
        'api_nonce'                    => $update_api_option_nonce,
        'reject_regex_nonce'           => $update_default_reject_regex_option_nonce,
        'reject_extension_nonce'       => $update_default_reject_extension_option_nonce,
        'cache_status_nonce'           => $cache_status_nonce,
        'security_tab_nonce'           => $security_tab_nonce,
        'premium_nonce_purge'          => wp_create_nonce('purge_cache_premium_nonce'),
        'premium_nonce_preload'        => wp_create_nonce('preload_cache_premium_nonce'),
        'premium_content_nonce'        => wp_create_nonce('load_premium_content_nonce'),
        'get_save_cron_nonce'          => $update_cron_expression_option_nonce,
        'cache_schedule_nonce'         => $update_cache_schedule_option_nonce,
        'cancel_scheduled_event_nonce' => $cancel_scheduled_event_nonce,
        'plugin_cache_nonce'           => $clear_plugin_cache_nonce,
        'clear_url_index_nonce'        => $clear_url_index_nonce,
        'cache_key_regex_nonce'        => $update_default_cache_key_regex_option_nonce,
        'mobile_ua_nonce'              => $update_default_mobile_user_agent_option_nonce,
        'enable_proxy_nonce'           => $update_enable_proxy_option_nonce,
        'wget_progress_api'            => esc_url(rest_url('nppp_nginx_cache/v2/preload-progress')),
        'preload_progress_nonce'       => wp_create_nonce('wp_rest'),
        'related_purge_nonce'          => wp_create_nonce('nppp-related-posts-purge'),
        'autopurge_triggers_nonce'     => wp_create_nonce('nppp-autopurge-triggers'),
        'col_cache_status'             => __( 'Status', 'fastcgi-cache-purge-and-preload-nginx' ),
        'pctnorm_nonce'                => wp_create_nonce('nppp-update-pctnorm-mode'),
        'http_purge_nonce'             => $update_http_purge_option_nonce,
        'varnish_sync_nonce'           => $update_varnish_sync_option_nonce,
        'varnish_only_nonce'           => $update_varnish_only_option_nonce,
        'rg_purge_nonce'               => $update_rg_purge_option_nonce,
        'bypass_pr_nonce'              => wp_create_nonce( 'nppp-update-bypass-path-restriction' ),
        'dismiss_vary_nonce'           => wp_create_nonce( 'nppp-dismiss-vary-notice' ),
        'check_vary_nonce'             => wp_create_nonce( 'nppp-check-vary-issue' ),
        'dismiss_cron_nonce'           => wp_create_nonce( 'nppp-dismiss-cron-notice' ),
        'check_cron_nonce'             => wp_create_nonce( 'nppp-check-cron-issue' ),
        'clear_cache_btn_label'        => __( 'Clear Plugin Cache', 'fastcgi-cache-purge-and-preload-nginx' ),
        'test_regex_nonce'             => wp_create_nonce( 'nppp_test_regex_nonce' ),
        'preload_miss_batch_nonce'     => wp_create_nonce( 'nppp_preload_miss_batch_nonce' ),
    ));
}

// Enqueue only the aurora header script on the setup page.
function nppp_enqueue_setup_page_assets() {
    wp_enqueue_script(
        'nppp_aurora-canvas',
        plugins_url( '../admin/js/nppp-header.js', __FILE__ ),
        array(),
        '2.1.8',
        true
    );
}

// Checks if the plugin requirements are met, specifically if the server
// is running on a Linux environment.
function nppp_is_linux() {
    // Check using PHP_OS constant
    if (PHP_OS === 'Linux') {
        return true;
    }

    // Check using PHP_OS_FAMILY (available in PHP 7.2+)
    if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux') {
        return true;
    }

    // Fallback check using php_uname() for edge cases or older PHP versions
    if (stripos(php_uname(), 'Linux') !== false) {
        return true;
    }

    // If none of the checks pass
    return false;
}

// Run the whole shell toolset probe in ONE shell: which of $commands are missing,
// plus the raw `wget --version` text when wget exists. Returns null when the script
// did not really run (sentinel absent): unknown is never reported as "missing".
function nppp_run_toolset_probe(array $commands) {
    $sentinel  = '__NPPP_CMD_CHECK_OK__';
    $wget_mark = '__NPPP_WGET_VERSION__';

    // The command names are hardcoded by the caller, so nothing here needs escaping.
    $script = 'for c in ' . implode(' ', $commands)
            . '; do command -v "$c" >/dev/null 2>&1 || echo "$c"; done; '
            . 'echo ' . $wget_mark . '; '
            . 'if command -v wget >/dev/null 2>&1; then wget --version 2>&1; fi; '
            . 'echo ' . $sentinel;

    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $lines = explode("\n", (string) shell_exec($script));
    $lines = array_map('rtrim', $lines);

    $mark_at = array_search($wget_mark, $lines, true);
    $end_at  = array_search($sentinel, $lines, true);
    if ($mark_at === false || $end_at === false || $end_at < $mark_at) {
        return null;
    }

    return [
        'missing'     => array_values(array_intersect($commands, array_slice($lines, 0, $mark_at))),
        'wget_output' => implode("\n", array_slice($lines, $mark_at + 1, $end_at - $mark_at - 1)),
    ];
}

// Shell toolset state: which core commands are missing and whether wget is
// compatible, from ONE shell and ONE 60s cache. Returns null only when the shell
// could not be measured at all (not cached, the next request measures again).
function nppp_shell_toolset_state() {
    // Static key base for transient
    $static_key_base = 'nppp';
    $transient_key   = 'nppp_missing_commands_' . md5($static_key_base);
    $compat_key      = 'nppp_wget_compatibility_' . md5($static_key_base);

    // Check if transient exists and is valid. The wget compatibility verdict is
    // produced by the same probe, so the two caches live and die together.
    $missing_commands = get_transient($transient_key);
    $wget_compat      = get_transient($compat_key);

    if (false === $missing_commands || false === $wget_compat) {
        // Set env
        nppp_prepare_request_env(true);

        // List of commands to check
        $commands_to_check = ['ps', 'grep', 'awk', 'sort', 'uniq', 'sed', 'nohup', 'wget'];

        if (!function_exists('shell_exec')) {
            // No shell at all: every command is unusable.
            $missing_commands = $commands_to_check;
            $wget_compat      = ['ok' => false, 'reason' => 'missing'];
        } else {
            // One shell for the whole list AND the wget version, instead of one fork per command.
            $probe = nppp_run_toolset_probe($commands_to_check);

            if ($probe === null) {
                // Could not measure. Unknown is not "missing": do not cache and do not
                // report anything, the next request measures again.
                return null;
            }

            $missing_commands = $probe['missing'];
            $wget_compat      = in_array('wget', $missing_commands, true)
                ? ['ok' => false, 'reason' => 'missing']
                : nppp_classify_wget_version_output($probe['wget_output']);
        }

        // Cache for 60 seconds: short enough to follow the real system state
        // (a removed or newly installed command is noticed within a minute),
        // long enough that the shell check does not run on every request.
        set_transient($transient_key, $missing_commands, MINUTE_IN_SECONDS);
        set_transient($compat_key, $wget_compat, MINUTE_IN_SECONDS);
    }

    return ['missing' => $missing_commands, 'wget' => $wget_compat];
}

// Checks if the specified shell commands are available in the environment, indicating
// specially whether the system is likely Dockerized
function nppp_is_dockerized() {
    $state = nppp_shell_toolset_state();

    // Return the list of missing commands (or an empty array if none are missing
    // or the shell could not be measured: unknown is not "missing").
    return $state === null ? [] : $state['missing'];
}

// Conditionally disable Nginx Cache features
function nppp_disable_features($unsupported, $preload) {
    $unsupported = (bool) $unsupported;
    $preload     = (bool) $preload;

    // Retrieve the current settings
    $options = get_option( 'nginx_cache_settings', array() );

    // Determine which features to disable
    if ( $unsupported === true ) {
        // If plugin completely unsupported
        $features = array(
            'nginx_cache_purge_on_update'         => 'no',
            'nginx_cache_auto_preload'            => 'no',
            'nginx_cache_auto_preload_mobile'     => 'no',
            'nginx_cache_watchdog'                => 'no',
            'nginx_cache_schedule'                => 'no',
            'nginx_cache_send_mail'               => 'no',
            'nginx_cache_api'                     => 'no',
            'nginx_cache_preload_enable_proxy'    => 'no',
            'nppp_cloudflare_apo_sync'            => 'no',
            'nppp_redis_cache_sync'               => 'no',
            'nppp_related_include_home'           => 'no',
            'nppp_related_include_category'       => 'no',
            'nppp_related_apply_manual'           => 'no',
            'nppp_related_preload_after_manual'   => 'no',
            'nginx_cache_pctnorm_mode'            => 'off',
            'nppp_http_purge_enabled'             => 'no',
            'nppp_rg_purge_enabled'               => 'no',
            'nppp_autopurge_posts'                => 'no',
            'nppp_autopurge_terms'                => 'no',
            'nppp_autopurge_plugins'              => 'no',
            'nppp_autopurge_themes'               => 'no',
            'nppp_autopurge_3rdparty'             => 'no',
            'nginx_cache_preload_feeds'           => 'no',
        );
    } elseif ( $preload === true ) {
        // If preload feature not useable
        $features = array(
            'nginx_cache_auto_preload'            => 'no',
            'nginx_cache_auto_preload_mobile'     => 'no',
            'nginx_cache_watchdog'                => 'no',
            'nginx_cache_schedule'                => 'no',
            'nginx_cache_preload_enable_proxy'    => 'no',
            'nginx_cache_pctnorm_mode'            => 'off',
            'nginx_cache_send_mail'               => 'no',
            'nginx_cache_preload_feeds'           => 'no',
            'nppp_related_preload_after_manual'   => 'no',
        );
    } else {
        return;
    }

    // Set selected features to their disabled values by option type.
    foreach ( $features as $feature => $disabled_value ) {
        $options[ $feature ] = $disabled_value;
    }

    // Update the option in the database.
    update_option( 'nginx_cache_settings', $options );

    // Preload schedule and watchdog are dead in both disable modes — tear
    // out of the cron queue so they cannot fire.
    wp_clear_scheduled_hook( 'npp_cache_preload_event' );
    wp_clear_scheduled_hook( 'npp_cache_preload_status_event' );

    // The URL→filepath index updater only serves single-URL purge, which is
    // still functional in preload-only-disabled mode — only cancel it on full disable.
    if ( $unsupported ) {
        wp_clear_scheduled_hook( 'nppp_index_updater_event' );
    }
}

// Check NPP required shell toolset for plugin and preload action
function nppp_shell_toolset_check($global_, $preload) {
    // Define the toolsets based on the arguments
    if ($global_) {
        $commands = ['ps', 'grep', 'awk', 'sort', 'uniq', 'sed'];
    } elseif ($preload) {
        $commands = ['wget', 'nohup'];
    } else {
        return true;
    }

    // Get the missing commands from the existing transient
    $missing_commands = nppp_is_dockerized();

    // Find the commands from the selected toolset that are missing
    $missing_toolset_commands = array_intersect($missing_commands, $commands);

    // If no commands from the selected toolset are missing
    if (!empty($missing_toolset_commands)) {
        return false;
    }

    // Preload requires GNU Wget 1.x compatibility (>=1.16).
    if ($preload && function_exists('nppp_get_wget_compatibility')) {
        $compat = nppp_get_wget_compatibility();
        if (empty($compat['ok'])) {
            return false;
        }
    }

    return true;
}

// Check plugin requirements
//
// Hot path: called on every wp-admin page load (admin_enqueue_scripts) and
// every front-end page view by a logged-in manage_options user
// (wp_enqueue_scripts) — unlike the Setup/Advanced-tab diagnostics, this is
// a capability gate, not a live UI row, so a short-TTL cache is safe here.
function nppp_plugin_requirements_met(bool $bypass_cache = false) {
    $nppp_req_tk = 'nppp_requirements_met_' . md5('nppp');

    if (!$bypass_cache) {
        $nppp_req_cached = get_transient($nppp_req_tk);
        if ($nppp_req_cached !== false) {
            return ($nppp_req_cached === '1');
        }
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        // Runs on every admin AND front-end page load (asset-enqueue gate), never
        // as the result of a user click — log only, don't rely on the screen
        // allowlist alone to keep it off unrelated pages.
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        return;
    }

    // NPP is ready to go only when no plugin-wide requirement fails. The ordered
    // checks live in nppp_requirements_first_failure() (pre-checks.php), the same
    // chain nppp_pre_checks_critical() builds its notice text from.
    $nppp_met = (nppp_requirements_first_failure() === null);

    if (!$bypass_cache) {
        set_transient($nppp_req_tk, $nppp_met ? '1' : '0', 60);
    }

    return $nppp_met;
}

// Enqueue CSS and JavaScript files on globally admin side
// Check plugin requirements and limit the functionality accordingly on admin side
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_requisite_assets() {
    // Bail for any admin user who cannot manage site settings (editors, authors, etc.).
    if (! current_user_can('manage_options')) {
        return;
    }

    $nppp_met = nppp_plugin_requirements_met();
    $current_screen = get_current_screen();

    // Handle assets while switching wp admin area and NPP plugin settings page to prevent conflicts.
    if ($current_screen->base === 'dashboard') {
        // Enqueue the NPP WP admin dashboard JS
        if (!wp_script_is('nppp-dashboard-widget-js', 'enqueued')) {
            wp_enqueue_script('nppp-dashboard-widget-js', plugins_url('../admin/js/nppp-dashboard-widget.js', __FILE__), array('jquery', 'wp-i18n'), '2.1.8', true);
            wp_set_script_translations('nppp-dashboard-widget-js', 'fastcgi-cache-purge-and-preload-nginx');
            wp_localize_script('nppp-dashboard-widget-js', 'nppp_widget_data', array(
                'ajaxurl'             => admin_url('admin-ajax.php'),
                'ratio_refresh_nonce' => wp_create_nonce('nppp_refresh_cache_ratio'),
                // Only when the status endpoint is configured: the JS skips the live traffic request otherwise.
                'traffic_nonce'       => (function_exists('nppp_http_purge_traffic_sampling_wanted') && nppp_http_purge_traffic_sampling_wanted())
                                         ? wp_create_nonce('nppp_traffic_refresh') : '',
            ));
        }

        // Enqueue the NPP WP admin dashboard CSS
        if (!wp_style_is('nppp-dashboard-widget-css', 'enqueued')) {
            wp_enqueue_style('nppp-dashboard-widget-css', plugins_url('../admin/css/nppp-dashboard-widget.css', __FILE__), array(), '2.1.8');
        }
    } elseif ($current_screen->id === 'settings_page_nginx_cache_settings') {
        // Dequeue the NPP WP admin dashboard JS
        if (wp_script_is('nppp-dashboard-widget-js', 'enqueued')) {
            wp_dequeue_script('nppp-dashboard-widget-js');
        }

        // Dequeue the NPP WP admin dashboard CSS
        if (wp_style_is('nppp-dashboard-widget-css', 'enqueued')) {
            wp_dequeue_style('nppp-dashboard-widget-css');
        }
    }

    // Disable/limit plugin functionality to prevent unexpected behaviors
    if ($nppp_met) {
        if (!nppp_shell_toolset_check(false, true)) {
            wp_enqueue_script('nppp-disable-preload', plugins_url('../admin/js/nppp-disable-preload.js', __FILE__), array('jquery'), '2.1.8', true);
            nppp_disable_features(false, true);
        } else {
            wp_dequeue_script('nppp-disable-preload');
        }
        wp_dequeue_script('nppp-disable-functionality');
    } else {
        wp_enqueue_script('nppp-disable-functionality', plugins_url('../admin/js/nppp-disable-functionality.js', __FILE__), array('jquery'), '2.1.8', true);
        nppp_disable_features(true, false);
    }
}

// Enqueue CSS and JavaScript files for admin, logged-in, front-end
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets() {
    // Gate entire function — nothing here is relevant to anonymous visitors.
    if (! is_user_logged_in() || ! current_user_can('manage_options')) {
        return;
    }

    // Enqueue mobile FAB assets for front-end.
    // The CSS itself uses @media (max-width:600px) so it is a no-op on desktop.
    if (! is_admin()) {
        wp_enqueue_style(
            'nppp-mobile-fab-css',
            plugins_url('../frontend/css/nppp-mobile-fab.css', __FILE__),
            array(),
            '2.1.8'
        );
        wp_enqueue_script(
            'nppp-mobile-fab-js',
            plugins_url('../frontend/js/nppp-mobile-fab.js', __FILE__),
            array(),
            '2.1.8',
            true
        );
    }

    // Toast block — logged-in admin redirected back from a purge/preload action.
    if (isset($_GET['nppp_front']) && ! is_admin()) {
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            // Keep legacy frontend notice assets untouched for compatibility.
            wp_enqueue_style('nppp_admin-front-css', plugins_url('../frontend/css/fastcgi-cache-purge-and-preload-nginx-front.css', __FILE__), array(), '2.1.8');
            wp_enqueue_script('nppp_admin-front-js', plugins_url('../frontend/js/fastcgi-cache-purge-and-preload-nginx-front.js', __FILE__), array(), '2.1.8', true);

            // Enqueue isolated frontend toast assets for single-page action results.
            wp_enqueue_style('nppp-front-toast-css', plugins_url('../frontend/css/nppp-front-toast.css', __FILE__), array(), '2.1.8');
            wp_enqueue_script('nppp-front-toast-js', plugins_url('../frontend/js/nppp-front-toast.js', __FILE__), array(), '2.1.8', true);

            $status_message_key = sanitize_text_field(wp_unslash($_GET['nppp_front']));
            $status_message_data = get_transient($status_message_key);

            if (is_array($status_message_data) && isset($status_message_data['message'], $status_message_data['type'])) {
                $type = sanitize_key((string) $status_message_data['type']);
                if (!in_array($type, array('success', 'error', 'info'), true)) {
                    $type = 'info';
                }

                wp_localize_script('nppp-front-toast-js', 'nppp_front_data', array(
                    'message' => sanitize_text_field((string) $status_message_data['message']),
                    'type' => $type,
                ));

                // Keep transient for a short fallback window (legacy/no-JS rendering path).
            }
        }
    }

    // Check plugin requirements and limit the functionality accordingly on front-end
    $nppp_met = nppp_plugin_requirements_met();
    if (!$nppp_met) {
        wp_enqueue_script('nppp-disable-functionality-front', plugins_url('../frontend/js/nppp-disable-functionality-front.js', __FILE__), array('jquery'), '2.1.8', true);
        // Make sure partial-preload disable is not also active
        wp_dequeue_script('nppp-disable-preload-front');
    } else {
        wp_dequeue_script('nppp-disable-functionality-front');
        // Extra gate: if shell/toolset missing, disable only preload actions on front-end
        $has_shell = function_exists('nppp_shell_toolset_check') ? nppp_shell_toolset_check(false, true) : false;
        if (! $has_shell) {
            wp_enqueue_script('nppp-disable-preload-front', plugins_url('../frontend/js/nppp-disable-preload-front.js', __FILE__), array('jquery'), '2.1.8', true);
        } else {
            wp_dequeue_script('nppp-disable-preload-front');
        }
    }
}

// This function adds inline CSS to hide notices from other plugins on the
// settings page of the NPP plugin. It ensures that only notices from NPP
// are visible while on the plugin settings page.
function nppp_manage_admin_notices() {
    // Register a dummy stylesheet
    wp_register_style('nppp-manage-notices', false, array(), '2.1.8');

    // Enqueue the dummy stylesheet
    wp_enqueue_style('nppp-manage-notices');

    // Add inline CSS to hide all admin notices except those with the class 'notice-nppp'
    wp_add_inline_style('nppp-manage-notices', '
        /* Hide all admin notices except those with the class \'notice-nppp\' */
        .notice,
        .update-nag,
        .notice-success,
        .notice-warning,
        .notice-error,
        .updated,
        .error,
        .is-dismissible,
        .vc_license-activation-notice {
            display: none !important;
        }

        .notice.notice-nppp,
        .updated.notice-nppp,
        .error.notice-nppp,
        .notice.notice-nppp.inline {
            display: block !important;
        }
    ');
}
