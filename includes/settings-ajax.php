<?php
/**
 * AJAX option handlers for Nginx Cache Purge Preload
 * Description: Handles all wp_ajax_* callbacks for toggle switches and live option updates.
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

// AJAX callback function to clear logs
function nppp_clear_nginx_cache_logs() {
    check_ajax_referer('nppp-clear-nginx-cache-logs', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem.');
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    if ($wp_filesystem->exists($log_file_path)) {
        nppp_perform_file_operation($log_file_path, 'write', '');
        nppp_display_admin_notice('success', __('SUCCESS LOGS: Logs cleared successfully.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
    } else {
        nppp_display_admin_notice('error', __('ERROR LOGS: Log file not found.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
    }
}

// Child AJAX callback function to retrieve log content after clear
function nppp_get_nginx_cache_logs() {
    check_ajax_referer('nppp-clear-nginx-cache-logs', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem.');
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    if ($wp_filesystem->exists($log_file_path)) {
        // Read and return the content of the log file after clear
        $logs = nppp_perform_file_operation($log_file_path, 'read');
        wp_send_json_success($logs);
    } else {
        wp_send_json_error('ERROR LOGS: Log file not found.');
    }
}

// AJAX callback function to update send mail option
function nppp_update_send_mail_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-send-mail-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Sanitize the posted option value
    $send_mail = isset($_POST['send_mail']) ? sanitize_text_field(wp_unslash($_POST['send_mail'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nginx_cache_send_mail'] = $send_mail;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Settings saved successfully!');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update related pages
function nppp_update_related_fields() {
    // Nonce & capability
    if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['_wpnonce']) ), 'nppp-related-posts-purge' ) ) {
        wp_send_json_error( ['message' => __('Security check failed.', 'fastcgi-cache-purge-and-preload-nginx')], 403 );
    }
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => __('You do not have permission to update this option.', 'fastcgi-cache-purge-and-preload-nginx')], 403 );
    }

    $allowed_keys = [
        'nppp_related_include_home',
        'nppp_related_include_category',
        'nppp_related_apply_manual',
        'nppp_related_preload_after_manual',
    ];

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is immediately unslashed, whitelisted by $allowed_keys, then sanitized below.
    $posted = ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) )
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is immediately unslashed, whitelisted by $allowed_keys, then sanitized below.
        ? array_intersect_key( wp_unslash( $_POST['fields'] ), array_flip( $allowed_keys ) )
        : [];

    // sanitize incoming values
    foreach ($posted as $k => $v) {
        $posted[$k] = is_string($v) ? sanitize_text_field($v) : $v;
    }

    $normalized = [];
    foreach ($allowed_keys as $key) {
        $raw = isset($posted[$key]) ? $posted[$key] : null;
        $normalized[$key] = in_array($raw, ['yes','1',1,'true',true,'on'], true) ? 'yes' : 'no';
    }

    // Enforce dependency — if none of the three are ON, force preload to NO
    $any_related = (
        ($normalized['nppp_related_include_home'] ?? 'no') === 'yes' ||
        ($normalized['nppp_related_include_category'] ?? 'no') === 'yes' ||
        ($normalized['nppp_related_apply_manual'] ?? 'no') === 'yes'
    );
    if ( ! $any_related ) {
        $normalized['nppp_related_preload_after_manual'] = 'no';
    }

    // Merge into existing options
    $opts = get_option('nginx_cache_settings', []);
    if ( ! is_array($opts) ) {
        $opts = [];
    }
    $opts = array_merge($opts, $normalized);
    update_option('nginx_cache_settings', $opts);

    wp_send_json_success([
        'message' => __('Related pages preferences saved.', 'fastcgi-cache-purge-and-preload-nginx'),
        'data'    => $normalized,
    ]);
}

// AJAX callback function to update percent-encode case
function nppp_update_pctnorm_mode() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( __( 'Permission denied.', 'fastcgi-cache-purge-and-preload-nginx' ), 403 );
    }
    check_ajax_referer( 'nppp-update-pctnorm-mode', '_wpnonce' );

    $val = isset($_POST['mode']) ? sanitize_text_field( wp_unslash($_POST['mode']) ) : '';
    $allowed = array( 'off', 'upper', 'lower', 'preserve' );
    if ( ! in_array( $val, $allowed, true ) ) {
        wp_send_json_error( __( 'Invalid mode.', 'fastcgi-cache-purge-and-preload-nginx' ), 400 );
    }

    $opts = get_option( 'nginx_cache_settings', array() );
    $opts['nginx_cache_pctnorm_mode'] = $val;
    update_option( 'nginx_cache_settings', $opts );

    $label = strtoupper( $val );
    wp_send_json_success( array(
        'saved'   => $val,
        'label'   => $label,
        // Translators: %s: selected percent-encoding mode label (OFF, PRESERVE, UPPER, or LOWER)
        'message' => sprintf( __( 'Percent-encoding: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $label ),
    ));
}

// AJAX callback function to update auto preload option
function nppp_update_auto_preload_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-auto-preload-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $auto_preload = isset($_POST['auto_preload']) ? sanitize_text_field(wp_unslash($_POST['auto_preload'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nginx_cache_auto_preload'] = $auto_preload;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Option updated successfully.');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update enable proxy option
function nppp_update_enable_proxy_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-enable-proxy-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $enable_proxy = isset($_POST['enable_proxy']) ? sanitize_text_field(wp_unslash($_POST['enable_proxy'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nginx_cache_preload_enable_proxy'] = $enable_proxy;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Option updated successfully.');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update preload mobile option
function nppp_update_auto_preload_mobile_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-auto-preload-mobile-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $preload_mobile = isset($_POST['preload_mobile']) ? sanitize_text_field(wp_unslash($_POST['preload_mobile'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nginx_cache_auto_preload_mobile'] = $preload_mobile;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Option updated successfully.');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update watchdog option
function nppp_update_watchdog_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-watchdog-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $watchdog = isset($_POST['watchdog']) ? sanitize_text_field(wp_unslash($_POST['watchdog'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nginx_cache_watchdog'] = $watchdog;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Option updated successfully.');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback to save all Auto Purge Trigger sub-options as a group.
function nppp_update_autopurge_triggers() {
    // Nonce & capability
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nppp-autopurge-triggers' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed.', 'fastcgi-cache-purge-and-preload-nginx' ) ), 403 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to update this option.', 'fastcgi-cache-purge-and-preload-nginx' ) ), 403 );
    }

    $allowed_keys = array(
        'nppp_autopurge_posts',
        'nppp_autopurge_terms',
        'nppp_autopurge_plugins',
        'nppp_autopurge_themes',
        'nppp_autopurge_3rdparty',
    );

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intersected against allowed_keys, sanitized below
    $posted = ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) )
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intersected against allowed_keys, sanitized below
        ? array_intersect_key( wp_unslash( $_POST['fields'] ), array_flip( $allowed_keys ) )
        : array();

    foreach ( $posted as $k => $v ) {
        $posted[ $k ] = is_string( $v ) ? sanitize_text_field( $v ) : $v;
    }

    $normalized = array();
    foreach ( $allowed_keys as $key ) {
        $raw                = isset( $posted[ $key ] ) ? $posted[ $key ] : null;
        $normalized[ $key ] = in_array( $raw, array( 'yes', '1', 1, 'true', true, 'on' ), true ) ? 'yes' : 'no';
    }

    // No enforcement needed – master toggle handler already ensures sub‑options are 'no' when master is OFF.
    $opts = get_option( 'nginx_cache_settings', array() );
    if ( ! is_array( $opts ) ) {
        $opts = array();
    }

    $opts = array_merge( $opts, $normalized );
    update_option( 'nginx_cache_settings', $opts );

    wp_send_json_success( array(
        'message' => __( 'Auto Purge Triggers saved.', 'fastcgi-cache-purge-and-preload-nginx' ),
        'data'    => $normalized,
    ) );
}

// AJAX callback function to update auto purge option
function nppp_update_auto_purge_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-auto-purge-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $auto_purge = isset($_POST['auto_purge']) ? sanitize_text_field(wp_unslash($_POST['auto_purge'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nginx_cache_purge_on_update'] = $auto_purge;

    // When master is turned OFF, also set all five sub‑options to 'no' in the database
    if ($auto_purge !== 'yes') {
        $sub_option_keys = array(
            'nppp_autopurge_posts',
            'nppp_autopurge_terms',
            'nppp_autopurge_plugins',
            'nppp_autopurge_themes',
            'nppp_autopurge_3rdparty',
        );
        foreach ($sub_option_keys as $key) {
            $current_options[$key] = 'no';
        }
    }

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Option updated successfully.');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update Cloudflare APO sync option
function nppp_update_cloudflare_apo_sync_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-cloudflare-apo-sync-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $cloudflare_sync = isset($_POST['cloudflare_sync']) ? sanitize_text_field(wp_unslash($_POST['cloudflare_sync'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', array());

    // Update the specific option within the array
    $current_options['nppp_cloudflare_apo_sync'] = $cloudflare_sync;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('Option updated successfully.');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX handler HTTP Purge
function nppp_update_http_purge_option(): void {
    if ( isset( $_POST['_wpnonce'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'nppp-update-http-purge-option' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
    } else {
        wp_send_json_error( 'Nonce is missing.' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'You do not have permission to update this option.' );
    }

    // Whitelist to exactly 'yes' or 'no'
    $raw        = sanitize_text_field( wp_unslash( $_POST['http_purge'] ?? '' ) );
    $http_purge = ( $raw === 'yes' ) ? 'yes' : 'no';

    $current_options = get_option( 'nginx_cache_settings', [] );
    $current_options['nppp_http_purge_enabled'] = $http_purge;

    $updated = update_option( 'nginx_cache_settings', $current_options );

    if ( $updated ) {
        wp_send_json_success( 'Option updated successfully.' );
    } else {
        wp_send_json_error( 'Error updating option.' );
    }
}

// AJAX handler RG Purge
function nppp_update_rg_purge_option(): void {
    if ( isset( $_POST['_wpnonce'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'nppp-update-rg-purge-option' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
    } else {
        wp_send_json_error( 'Nonce is missing.' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'You do not have permission to update this option.' );
    }

    $raw      = sanitize_text_field( wp_unslash( $_POST['rg_purge'] ?? '' ) );
    $rg_purge = ( $raw === 'yes' ) ? 'yes' : 'no';

    $current_options = get_option( 'nginx_cache_settings', [] );

    // If rg is not available, refuse to enable.
    if ( $rg_purge === 'yes' ) {
        $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
        if ( $rg_bin === '' || ! is_executable( $rg_bin ) ) {
            wp_send_json_error( 'ripgrep (rg) binary not found. Install it to enable RG Purge.' );
            return;
        }
    }

    $current_options['nppp_rg_purge_enabled'] = $rg_purge;
    $updated = update_option( 'nginx_cache_settings', $current_options );

    if ( $updated ) {
        wp_send_json_success( 'Option updated successfully.' );
    } else {
        wp_send_json_error( 'Error updating option.' );
    }
}

// AJAX handler — Redis Object Cache sync toggle
function nppp_update_redis_cache_sync_option() {
    if ( isset( $_POST['_wpnonce'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'nppp-update-redis-cache-sync-option' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
    } else {
        wp_send_json_error( 'Nonce is missing.' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'You do not have permission to update this option.' );
    }

    $redis_sync = isset( $_POST['redis_cache_sync'] )
        ? sanitize_text_field( wp_unslash( $_POST['redis_cache_sync'] ) )
        : 'no';

    $current_options = get_option( 'nginx_cache_settings', [] );
    $current_options['nppp_redis_cache_sync'] = $redis_sync;

    $updated = update_option( 'nginx_cache_settings', $current_options );

    if ( $updated ) {
        wp_send_json_success( 'Option updated successfully.' );
    } else {
        wp_send_json_error( 'Error updating option.' );
    }
}

// AJAX callback function to update cache schedule option
function nppp_update_cache_schedule_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-cache-schedule-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $cache_schedule = isset($_POST['cache_schedule']) ? sanitize_text_field(wp_unslash($_POST['cache_schedule'])) : '';

    // Initialize variables to track the operation status
    $unscheduled_successfully = false;
    $no_existing_event_found = false;

    // Check if the schedule option is set to 'no' or the feature is disabled
    if ($cache_schedule === 'no' || $cache_schedule === '') {
        // Check if there's already a scheduled event with the same hook
        $existing_timestamp = wp_next_scheduled('npp_cache_preload_event');

        // If there's an existing scheduled event, clear it
        if ($existing_timestamp) {
            $cleared = wp_clear_scheduled_hook('npp_cache_preload_event');
            if ($cleared) {
                $unscheduled_successfully = true;
            }
        } else {
            $no_existing_event_found = true;
        }
    }

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);

    // Update the specific option within the array
    $current_options['nginx_cache_schedule'] = $cache_schedule;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        if ($unscheduled_successfully) {
            wp_send_json_success('Option updated successfully. Unschedule success.');
        } elseif ($no_existing_event_found) {
            wp_send_json_success('Option updated successfully. No event found.');
        } else {
            wp_send_json_success('Option updated successfully.');
        }
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update api key option
function nppp_update_api_key_option() {
    // Verify nonce
    check_ajax_referer('nppp-update-api-key-option', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Generate new API key
    $new_api_key = bin2hex(random_bytes(32));
    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);

    // Update the specific option within the array
    $current_options['nginx_cache_api_key'] = $new_api_key;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        // Return the new key as the AJAX response
        wp_send_json_success($new_api_key);
    } else {
        // Return an error response if updating the option fails
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to copy api key
function nppp_update_api_key_copy_value() {
    // Verify nonce
    check_ajax_referer('nppp-update-api-key-copy-value', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the current options
    $options = get_option('nginx_cache_settings', []);

    // Check if the retrieval was successful
    if ($options === false || !is_array($options)) {
        // Error handling if the option retrieval fails
        wp_send_json_error('Failed to retrieve the API key.');
    }

    // Get the API key option value
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : '';

    // Return the API key in the AJAX response
    wp_send_json_success(array('api_key' => $api_key));
}

// AJAX callback function to update REST API option
function nppp_update_api_option() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-update-api-option')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value and sanitize it
    $nppp_api = isset($_POST['nppp_api']) ? sanitize_text_field(wp_unslash($_POST['nppp_api'])) : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);

    // Update the specific option within the array
    $current_options['nginx_cache_api'] = $nppp_api;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('success');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// AJAX callback function to update default reject regex option
function nppp_update_default_reject_regex_option() {
    // Verify nonce
    check_ajax_referer('nppp-update-default-reject-regex-option', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get default reject regex
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);

    // Update the specific option within the array
    $current_options['nginx_cache_reject_regex'] = $default_reject_regex;

    // Save the option
    update_option('nginx_cache_settings', $current_options);

    // Return the new reject pattern as the AJAX response
    wp_send_json_success($default_reject_regex);
}

// AJAX callback function to update default reject extension option
function nppp_update_default_reject_extension_option() {
    // Verify nonce
    check_ajax_referer('nppp-update-default-reject-extension-option', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get default reject extension
    $default_reject_extension = nppp_fetch_default_reject_extension();

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);

    // Update the specific option within the array
    $current_options['nginx_cache_reject_extension'] = $default_reject_extension;

    // Save the option
    update_option('nginx_cache_settings', $current_options);

    // Return the new extension set as the AJAX response
    wp_send_json_success($default_reject_extension);
}

// AJAX callback function to update default cache key regex option
function nppp_update_default_cache_key_regex_option() {
    // Verify nonce
    check_ajax_referer('nppp-update-default-cache-key-regex-option', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get default reject extension
    $default_cache_key_regex = nppp_fetch_default_regex_for_cache_key();

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);

    // Update the specific option within the array
    $current_options['nginx_cache_key_custom_regex'] = $default_cache_key_regex;

    // Save the option
    update_option('nginx_cache_settings', $current_options);

    // Return the new extension set as the AJAX response
    wp_send_json_success($default_cache_key_regex);
}

// AJAX callback function to copy rest api curl purge url
function nppp_rest_api_purge_url_copy() {
    // Verify nonce
    check_ajax_referer('nppp-rest-api-purge-url-copy', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get API Key
    $options = get_option('nginx_cache_settings', []);
    $default_api_key = bin2hex(random_bytes(32));
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;

    // Construct the REST API purge URL
    $rest_url = get_rest_url(null, '/nppp_nginx_cache/v2/purge');

    // Construct the CURL command
    $curl_command = sprintf(
        'curl -k -X POST -H "Authorization: Bearer %s" -H "Accept: application/json" "%s"',
        $api_key,
        $rest_url
    );

    // Return the command to the AJAX requester
    wp_send_json_success($curl_command);
}

// AJAX callback function to copy rest api curl preload url
function nppp_rest_api_preload_url_copy() {
    // Verify nonce
    check_ajax_referer('nppp-rest-api-preload-url-copy', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get API Key
    $options = get_option('nginx_cache_settings', []);
    $default_api_key = bin2hex(random_bytes(32));
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;

    // Construct the REST API purge URL
    $rest_url = get_rest_url(null, '/nppp_nginx_cache/v2/preload');

    // Construct the CURL command
    $curl_command = sprintf(
        'curl -k -X POST -H "Authorization: Bearer %s" -H "Accept: application/json" "%s"',
        $api_key,
        $rest_url
    );

    // Return the command to the AJAX requester
    wp_send_json_success($curl_command);
}

// Define the AJAX handler function to save the cron expression
function nppp_get_save_cron_expression() {
    // Verify nonce to ensure the request is coming from a trusted source
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-get-save-cron-expression')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // Get the cron frequency and time from the AJAX request and sanitize them
    $cron_freq = isset($_POST['nppp_cron_freq']) ? sanitize_text_field(wp_unslash($_POST['nppp_cron_freq'])) : '';
    $time = isset($_POST['nppp_time']) ? sanitize_text_field(wp_unslash($_POST['nppp_time'])) : '';

    // Validate the cron frequency value before saving the option
    if (!in_array($cron_freq, array('daily', 'weekly', 'monthly'))) {
        // If the cron frequency is not one of the allowed values, return an error response
        wp_send_json_error('Invalid cron frequency value.');
    }

    // Validate the time format (HH:mm)
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
        // If the time format is invalid, return an error response
        wp_send_json_error('Invalid time format.');
    }

    // Save the cron frequency and time as needed
    $cron_expression = $cron_freq . '|' . $time;
    update_option('nginx_cache_schedule_value', $cron_expression);

    // Check if option update was successful
    $updated_value = get_option('nginx_cache_schedule_value');
    if ($updated_value === $cron_expression) {
        // Get the WordPress timezone string
        $timezone_string = wp_timezone_string();

        // Get all scheduled events
        $events = _get_cron_array();

        // Check if timezone string is set
        if (empty($timezone_string)) {
            wp_send_json_error('Timezone not set in WordPress options!');
        }

        // Check events are empty
        if (empty($events)) {
            wp_send_json_error('No active scheduled events found!');
        }

        // If the option was successfully updated, the timezone is set,
        // and there are no active scheduled events, create a new schedule event
        nppp_create_scheduled_events($cron_expression);

        // If the option was successfully updated and a new WP cron is scheduled
        // without error, then send a success response
        wp_send_json_success('New cron event scheduled successfully.');
    } else {
        // If there was an issue updating the option, send an error response
        wp_send_json_error('Error saving cron expression.');
    }
}
