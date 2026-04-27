<?php
/**
 * NPP frontend bootstrap
 * Version:           2.1.6
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue frontend assets for logged-in admins (toast notices, disable-scripts, mobile FAB).
add_action('wp_enqueue_scripts', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets');

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
add_action('wp_footer',    'nppp_render_front_notice_fallback', 1);

/**
 * Render a mobile-only Floating Action Button (FAB) in wp_footer.
 *
 * WordPress core hides #wpadminbar entirely on screens ≤ 600px
 * (`display:none !important`), making admin-bar cache actions unreachable
 * on mobile. This FAB replicates "Purge This Page" and "Preload This Page"
 * at the same breakpoint, using the identical nonce URL logic as the bar.
 *
 * Visibility is controlled purely via CSS (shown only at max-width:600px),
 * so desktop users never see it.
 */
function nppp_render_mobile_fab(): void {
    // Gate: admin area, unauthenticated, or insufficient capability.
    if (is_admin() || ! is_user_logged_in() || ! current_user_can('manage_options')) {
        return;
    }

    // Gate: bail inside wp-admin even if is_admin() somehow missed it.
    if (isset($_SERVER['REQUEST_URI'])) {
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        if (strpos($request_uri, 'wp-admin') !== false) {
            return;
        }
    }

    // Determine whether initial setup is still pending.
    $needs_setup = class_exists('\\NPPP\\Setup') && \NPPP\Setup::nppp_needs_setup();
    $setup_url   = admin_url('admin.php?page=' . ( defined('\\NPPP\\Setup::PAGE_SLUG') ? \NPPP\Setup::PAGE_SLUG : 'nppp-setup' ));

    // Build the current front-end URL (helper defined in includes/admin-bar.php).
    $from_url = function_exists('nppp_get_current_front_url')
        ? nppp_get_current_front_url()
        : home_url('/');

    // Build action URLs — mirrors nppp_add_fastcgi_cache_buttons_admin_bar() exactly.
    if ($needs_setup) {
        $purge_url   = $setup_url;
        $preload_url = $setup_url;
    } else {
        $purge_url = wp_nonce_url(
            add_query_arg('from', $from_url, admin_url('admin.php?action=nppp_purge_cache_single')),
            'purge_cache_nonce'
        );
        $preload_url = wp_nonce_url(
            add_query_arg('from', $from_url, admin_url('admin.php?action=nppp_preload_cache_single')),
            'preload_cache_nonce'
        );
    }

    // Determine if preload action is available (shell toolset check).
    $preload_disabled = ! (
        function_exists('nppp_shell_toolset_check') &&
        nppp_shell_toolset_check(false, true)
    );

    // Icon path — same image used by the admin bar entry.
    $icon_url = esc_url(plugin_dir_url(dirname(__FILE__)) . 'admin/img/bar.png');

    ?>
    <div id="nppp-mobile-fab"
         role="navigation"
         aria-label="<?php esc_attr_e('Nginx Cache', 'fastcgi-cache-purge-and-preload-nginx'); ?>">

        <div id="nppp-mobile-fab-menu" role="menu" aria-hidden="true">

            <a href="<?php echo esc_url($preload_url); ?>"
               class="nppp-fab-item nppp-fab-preload<?php echo $preload_disabled ? ' nppp-fab-disabled' : ''; ?>"
               role="menuitem"
               <?php if ($preload_disabled): ?>aria-disabled="true"<?php endif; ?>>
                <i class="nppp-fab-item-icon" aria-hidden="true">&#9654;</i>
                <?php esc_html_e('Preload This Page', 'fastcgi-cache-purge-and-preload-nginx'); ?>
            </a>

            <a href="<?php echo esc_url($purge_url); ?>"
               class="nppp-fab-item nppp-fab-purge"
               role="menuitem">
                <i class="nppp-fab-item-icon" aria-hidden="true">&#10005;</i>
                <?php esc_html_e('Purge This Page', 'fastcgi-cache-purge-and-preload-nginx'); ?>
            </a>

        </div>

        <button id="nppp-mobile-fab-toggle"
                type="button"
                aria-expanded="false"
                aria-controls="nppp-mobile-fab-menu"
                aria-label="<?php esc_attr_e('Nginx Cache actions', 'fastcgi-cache-purge-and-preload-nginx'); ?>">
            <img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="22" height="22" aria-hidden="true">
        </button>

    </div>
    <?php
}

// Hook FAB after body content but before </body>.
// Priority 5 keeps it well before other footer scripts that might interfere.
add_action('wp_footer', 'nppp_render_mobile_fab', 5);
