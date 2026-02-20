<?php
/**
 * NPP frontend bootstrap
 * Version:           2.1.4
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Render a one-time legacy frontend notice when toast assets are unavailable.
function nppp_render_front_notice_fallback(): void {
    static $rendered = false;

    // Prevent duplicate output when multiple hooks fire.
    if ($rendered) {
        return;
    }

    if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['nppp_front'])) {
        return;
    }

    $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
    if (empty($nonce) || !wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
        return;
    }

    // When isolated toast assets are available, they should handle rendering.
    if (wp_script_is('nppp-front-toast-js', 'enqueued')) {
        return;
    }

    $status_message_key = sanitize_text_field(wp_unslash($_GET['nppp_front']));
    $status_message_data = get_transient($status_message_key);

    if (!is_array($status_message_data) || !isset($status_message_data['message'], $status_message_data['type'])) {
        return;
    }

    $notice_type = sanitize_key((string) $status_message_data['type']);
    $notice_class = 'nppp_notice nppp_front_info';
    if ($notice_type === 'success') {
        $notice_class = 'nppp_notice nppp_front_success';
    } elseif ($notice_type === 'error') {
        $notice_class = 'nppp_notice nppp_front_error';
    }

     echo '<div class="' . esc_attr($notice_class) . '"><p>' . esc_html((string) $status_message_data['message']) . '</p></div>';

    // Consume transient in fallback path to preserve one-time behavior.
    delete_transient($status_message_key);
    $rendered = true;
}

// Try early in body, then fall back to footer for themes missing wp_body_open.
// Naturally does not run in Admin/AJAX/REST/CRON contexts.
add_action('wp_body_open', 'nppp_render_front_notice_fallback', 20);
add_action('wp_footer', 'nppp_render_front_notice_fallback', 1);
