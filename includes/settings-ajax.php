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

// Shared AJAX auth guard: nonce + capability check.
function nppp_ajax_auth( string $nonce_action ): void {
    check_ajax_referer( $nonce_action, '_wpnonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            __( 'You do not have permission to update this option.', 'fastcgi-cache-purge-and-preload-nginx' ),
            403
        );
    }
}

// Shared helper: sanitize POST field, persist it into settings, return success.
function nppp_save_toggle_option( string $nonce_action, string $post_key, string $option_key, string $default = '' ): void {
    nppp_ajax_auth( $nonce_action );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
    $value = isset( $_POST[ $post_key ] )
        ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        : $default;
    $opts = get_option( 'nginx_cache_settings', [] );
    if ( ! is_array( $opts ) ) {
        $opts = [];
    }
    $opts[ $option_key ] = $value;
    update_option( 'nginx_cache_settings', $opts );
    wp_send_json_success( __( 'Option updated successfully.', 'fastcgi-cache-purge-and-preload-nginx' ) );
}

// Shared helper: curl url copy
function nppp_rest_api_url_copy( string $nonce_action, string $endpoint ): void {
    nppp_ajax_auth( $nonce_action );
    $options         = get_option( 'nginx_cache_settings', [] );
    $default_api_key = bin2hex( random_bytes( 32 ) );
    $api_key         = isset( $options['nginx_cache_api_key'] ) ? $options['nginx_cache_api_key'] : $default_api_key;
    $curl_command    = sprintf(
        'curl -k -X POST -H "Authorization: Bearer %s" -H "Accept: application/json" "%s"',
        $api_key,
        get_rest_url( null, $endpoint )
    );
    wp_send_json_success( $curl_command );
}

// Shared helper: default fetch
function nppp_reset_default_option( string $nonce_action, string $option_key, callable $fetcher ): void {
    nppp_ajax_auth( $nonce_action );
    $value           = $fetcher();
    $current_options = get_option( 'nginx_cache_settings', [] );
    $current_options[ $option_key ] = $value;
    update_option( 'nginx_cache_settings', $current_options );
    wp_send_json_success( $value );
}

// AJAX callback function to clear logs
function nppp_clear_nginx_cache_logs() {
    // Nonce check
    nppp_ajax_auth( 'nppp-clear-nginx-cache-logs' );

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        wp_send_json_error( __( 'Failed to initialize WP Filesystem.', 'fastcgi-cache-purge-and-preload-nginx' ), 500 );
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    if ( ! $wp_filesystem->exists( $log_file_path ) ) {
        wp_send_json_error( __( 'Log file not found.', 'fastcgi-cache-purge-and-preload-nginx' ), 404 );
    }

    nppp_perform_file_operation( $log_file_path, 'write', '' );
    wp_send_json_success( __( 'Logs cleared successfully.', 'fastcgi-cache-purge-and-preload-nginx' ) );
}

// Child AJAX callback function to retrieve log content after clear
function nppp_get_nginx_cache_logs() {
    // Nonce check
    nppp_ajax_auth( 'nppp-clear-nginx-cache-logs' );

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        wp_send_json_error( __( 'Failed to initialize WP Filesystem.', 'fastcgi-cache-purge-and-preload-nginx' ), 500 );
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    if ( ! $wp_filesystem->exists( $log_file_path ) ) {
        wp_send_json_error( __( 'Log file not found.', 'fastcgi-cache-purge-and-preload-nginx' ), 404 );
    }

    $logs = nppp_perform_file_operation( $log_file_path, 'read' );
    wp_send_json_success( $logs );
}

// AJAX callback function to update send mail option
function nppp_update_send_mail_option(): void {
    nppp_save_toggle_option( 'nppp-update-send-mail-option', 'send_mail', 'nginx_cache_send_mail' );
}

// AJAX callback function to update related pages
function nppp_update_related_fields() {
    // Nonce check
    nppp_ajax_auth( 'nppp-related-posts-purge' );

    $allowed_keys = [
        'nppp_related_include_home',
        'nppp_related_include_category',
        'nppp_related_apply_manual',
        'nppp_related_preload_after_manual',
    ];

    // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in nppp_ajax_auth(); value whitelisted and sanitized below.
    $posted = ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) )
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in nppp_ajax_auth(); value whitelisted and sanitized below.
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
    // Nonce check
    nppp_ajax_auth( 'nppp-update-pctnorm-mode' );

    $val = isset($_POST['mode']) ? sanitize_text_field( wp_unslash($_POST['mode']) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
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
        /* translators: %s: selected percent-encoding mode label (OFF, PRESERVE, UPPER, or LOWER) */
        'message' => sprintf( __( 'Percent-encoding: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $label ),
    ));
}

// AJAX callback function to update auto preload option
function nppp_update_auto_preload_option(): void {
    nppp_save_toggle_option( 'nppp-update-auto-preload-option', 'auto_preload', 'nginx_cache_auto_preload' );
}

// AJAX callback function to update enable proxy option
function nppp_update_enable_proxy_option(): void {
    nppp_save_toggle_option( 'nppp-update-enable-proxy-option', 'enable_proxy', 'nginx_cache_preload_enable_proxy' );
}

// AJAX callback function to update preload mobile option
function nppp_update_auto_preload_mobile_option(): void {
    nppp_save_toggle_option( 'nppp-update-auto-preload-mobile-option', 'preload_mobile', 'nginx_cache_auto_preload_mobile' );
}

// AJAX callback function to update watchdog option
function nppp_update_watchdog_option(): void {
    nppp_save_toggle_option( 'nppp-update-watchdog-option', 'watchdog', 'nginx_cache_watchdog' );
}

// AJAX callback to save all Auto Purge Trigger sub-options as a group.
function nppp_update_autopurge_triggers() {
    // Verify nonce
    nppp_ajax_auth( 'nppp-autopurge-triggers' );

    $allowed_keys = array(
        'nppp_autopurge_posts',
        'nppp_autopurge_terms',
        'nppp_autopurge_plugins',
        'nppp_autopurge_themes',
        'nppp_autopurge_3rdparty',
    );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in nppp_ajax_auth(); value whitelisted and sanitized below.
    $posted = ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) )
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in nppp_ajax_auth(); value whitelisted and sanitized below.
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
    nppp_ajax_auth( 'nppp-update-auto-purge-option' );

    // Get the posted option value and sanitize it
    $auto_purge = isset($_POST['auto_purge']) ? sanitize_text_field(wp_unslash($_POST['auto_purge'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()

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

    // Update option
    update_option( 'nginx_cache_settings', $current_options );
    wp_send_json_success( __( 'Option updated successfully.', 'fastcgi-cache-purge-and-preload-nginx' ) );
}

// AJAX callback function to update Cloudflare APO sync option
function nppp_update_cloudflare_apo_sync_option(): void {
    nppp_save_toggle_option( 'nppp-update-cloudflare-apo-sync-option', 'cloudflare_sync', 'nppp_cloudflare_apo_sync' );
}

// AJAX handler HTTP Purge
function nppp_update_http_purge_option(): void {
    nppp_save_toggle_option( 'nppp-update-http-purge-option', 'http_purge', 'nppp_http_purge_enabled' );
}

// AJAX handler RG Purge
function nppp_update_rg_purge_option(): void {
    // Verify nonce
    nppp_ajax_auth( 'nppp-update-rg-purge-option' );

    $raw      = sanitize_text_field( wp_unslash( $_POST['rg_purge'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
    $rg_purge = ( $raw === 'yes' ) ? 'yes' : 'no';

    // If rg is not available, refuse to enable.
    if ( $rg_purge === 'yes' ) {
        $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
        if ( $rg_bin === '' || ! is_executable( $rg_bin ) ) {
            wp_send_json_error( __( 'ripgrep (rg) binary not found. Install it to enable RG Purge.', 'fastcgi-cache-purge-and-preload-nginx' ), 400 );
        }
    }

    $current_options = get_option( 'nginx_cache_settings', [] );
    $current_options['nppp_rg_purge_enabled'] = $rg_purge;

    // Update option
    update_option( 'nginx_cache_settings', $current_options );
    wp_send_json_success( __( 'Option updated successfully.', 'fastcgi-cache-purge-and-preload-nginx' ) );
}

// AJAX handler — Redis Object Cache sync toggle
function nppp_update_redis_cache_sync_option(): void {
    nppp_save_toggle_option( 'nppp-update-redis-cache-sync-option', 'redis_cache_sync', 'nppp_redis_cache_sync' );
}

// AJAX callback function to update cache schedule option
function nppp_update_cache_schedule_option() {
    // Verify nonce
    nppp_ajax_auth( 'nppp-update-cache-schedule-option' );

    // Get the posted option value and sanitize it
    $cache_schedule = isset($_POST['cache_schedule']) ? sanitize_text_field(wp_unslash($_POST['cache_schedule'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
    $unscheduled    = false;

    // When the schedule is being disabled, clear any existing cron event.
    if ( $cache_schedule === 'no' || $cache_schedule === '' ) {
        $existing_timestamp = wp_next_scheduled( 'npp_cache_preload_event' );
        if ( $existing_timestamp ) {
            wp_clear_scheduled_hook( 'npp_cache_preload_event' );
            $unscheduled = true;
        }
    }

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);
    $current_options['nginx_cache_schedule'] = $cache_schedule;
    update_option( 'nginx_cache_settings', $current_options );

    wp_send_json_success( array( 'unscheduled' => $unscheduled ) );
}

// AJAX callback function to update api key option
function nppp_update_api_key_option() {
    // Verify nonce
    nppp_ajax_auth( 'nppp-update-api-key-option' );

    // Generate new API key
    $new_api_key = bin2hex(random_bytes(32));

    // Get the current options
    $current_options = get_option('nginx_cache_settings', []);
    $current_options['nginx_cache_api_key'] = $new_api_key;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success($new_api_key);
    } else {
        wp_send_json_error( __( 'Error updating option.', 'fastcgi-cache-purge-and-preload-nginx' ), 500 );
    }
}

// AJAX callback function to copy api key
function nppp_update_api_key_copy_value() {
    // Verify nonce
    nppp_ajax_auth( 'nppp-update-api-key-copy-value' );

    // Get the current options
    $options = get_option('nginx_cache_settings', []);

    // Check if the retrieval was successful
    if ( ! is_array( $options ) ) {
        wp_send_json_error( __( 'Failed to retrieve the API key.', 'fastcgi-cache-purge-and-preload-nginx' ), 500 );
    }

    // Get the API key option value
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : '';

    // Return the API key in the AJAX response
    wp_send_json_success(array('api_key' => $api_key));
}

// AJAX callback function to update REST API option
function nppp_update_api_option(): void {
    nppp_save_toggle_option( 'nppp-update-api-option', 'nppp_api', 'nginx_cache_api' );
}

// AJAX callback function to update default reject regex option
function nppp_update_default_reject_regex_option(): void {
    nppp_reset_default_option( 'nppp-update-default-reject-regex-option', 'nginx_cache_reject_regex', 'nppp_fetch_default_reject_regex' );
}

// AJAX callback function to update default reject extension option
function nppp_update_default_reject_extension_option(): void {
    nppp_reset_default_option( 'nppp-update-default-reject-extension-option', 'nginx_cache_reject_extension', 'nppp_fetch_default_reject_extension' );
}

// AJAX callback function to update default cache key regex option
function nppp_update_default_cache_key_regex_option(): void {
    nppp_reset_default_option( 'nppp-update-default-cache-key-regex-option', 'nginx_cache_key_custom_regex', 'nppp_fetch_default_regex_for_cache_key' );
}

// AJAX callback function to copy rest api curl purge url
function nppp_rest_api_purge_url_copy(): void {
    nppp_rest_api_url_copy( 'nppp-rest-api-purge-url-copy', '/nppp_nginx_cache/v2/purge' );
}

// AJAX callback function to copy rest api curl preload url
function nppp_rest_api_preload_url_copy(): void {
    nppp_rest_api_url_copy( 'nppp-rest-api-preload-url-copy', '/nppp_nginx_cache/v2/preload' );
}

// Define the AJAX handler function to save the cron expression
function nppp_get_save_cron_expression() {
    // Verify nonce
    nppp_ajax_auth( 'nppp-get-save-cron-expression' );

    // Get the cron frequency and time from the AJAX request and sanitize them
    $cron_freq = isset($_POST['nppp_cron_freq']) ? sanitize_text_field(wp_unslash($_POST['nppp_cron_freq'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
    $time      = isset($_POST['nppp_time'])      ? sanitize_text_field(wp_unslash($_POST['nppp_time'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()

    // Validate the cron frequency value before saving the option
    if (!in_array($cron_freq, array('daily', 'weekly', 'monthly'))) {
        wp_send_json_error( __( 'Invalid cron frequency value.', 'fastcgi-cache-purge-and-preload-nginx' ), 400 );
    }

    // Validate the time format (HH:mm)
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
        wp_send_json_error( __( 'Invalid time format.', 'fastcgi-cache-purge-and-preload-nginx' ), 400 );
    }

    // Timezone must be set before any cron event can be meaningful.
    $timezone_string = wp_timezone_string();
    if ( empty( $timezone_string ) ) {
        wp_send_json_error( __( 'Timezone not set in WordPress options!', 'fastcgi-cache-purge-and-preload-nginx' ), 500 );
    }

    // Save the cron frequency and time as needed
    $cron_expression = $cron_freq . '|' . $time;

    // Update option
    update_option( 'nginx_cache_schedule_value', $cron_expression );
    nppp_create_scheduled_events( $cron_expression );

    wp_send_json_success( __( 'New cron event scheduled successfully.', 'fastcgi-cache-purge-and-preload-nginx' ) );
}
