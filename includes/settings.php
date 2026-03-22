<?php
/**
 * Settings page handlers for Nginx Cache Purge Preload
 * Description: Registers, sanitizes, and renders plugin configuration fields in the WordPress admin.
 * Version: 2.1.4
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
    add_settings_field('nginx_cache_watchdog', 'Preload Watchdog', 'nppp_nginx_cache_watchdog_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_enable_proxy', 'Enable Proxy', 'nppp_nginx_cache_enable_proxy_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_host', 'Proxy Host', 'nppp_nginx_cache_proxy_host_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_port', 'Proxy Port', 'nppp_nginx_cache_proxy_port_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_pctnorm_mode', 'Percent-encoding Case', 'nppp_nginx_cache_pctnorm_mode_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_enabled', 'HTTP Purge', 'nppp_http_purge_enabled_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_suffix', 'Purge URL Suffix', 'nppp_http_purge_suffix_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_custom_url', 'Purge Custom Base URL', 'nppp_http_purge_custom_url_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
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

// Displays the NPP Nginx Cache Settings page in the WordPress admin dashboard
function nppp_nginx_cache_settings_page() {
    // Redirect to setup if setup has not been completed yet.
    if (class_exists('\NPPP\Setup') && \NPPP\Setup::nppp_needs_setup()) {
        wp_safe_redirect( admin_url('admin.php?page=' . \NPPP\Setup::PAGE_SLUG) );
        exit;
    }

    if (isset($_GET['status_message']) && isset($_GET['message_type'])) {
        // Sanitize and validate the nonce
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';

        if (empty($nonce) || !wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            wp_die('Nonce verification failed');
        }

        // Sanitize the status message and message type
        $status_message = sanitize_text_field(wp_unslash($_GET['status_message']));
        $message_type = sanitize_text_field(wp_unslash($_GET['message_type']));

        // Validate the message type against a set of allowed values
        $allowed_message_types = ['success', 'error', 'info', 'warning'];
        if (!in_array($message_type, $allowed_message_types)) {
            $message_type = 'info';
        }

        // Display the status message as an admin notice
        nppp_display_admin_notice($message_type, $status_message, false, true);
    }

    ?>
    <div id="nppp-admin" class="wrap">
        <div id="nppp-loader-overlay" aria-live="assertive" aria-busy="true">
            <div class="nppp-spinner-container">
                <div class="nppp-loader"></div>
                <div class="nppp-fill-mask">
                    <div class="nppp-loader-fill"></div>
                </div>
                <span class="nppp-loader-text"><?php echo esc_html__( 'NPP', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
           </div>
           <p class="nppp-loader-message"><?php echo esc_html__( 'Processing, please wait...', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
        </div>
        <div class="nppp-header-content" data-theme="aurora">
            <div class="nppp-img-container">
                <img
                    src="<?php echo esc_url(plugins_url('../admin/img/logo.png', __FILE__)); ?>"
                    width="90"
                    height="90"
                    alt="<?php echo esc_attr__('Plugin Logo', 'fastcgi-cache-purge-and-preload-nginx'); ?>">
            </div>
            <div class="nppp-buttons-wrapper">
                <div class="nppp-cache-buttons">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=nppp_purge_cache'), 'purge_cache_nonce')); ?>" class="nppp-button nppp-button-primary" id="nppp-purge-button">
                        <span class="dashicons dashicons-trash" style="font-size: 18px;"></span>
                        <?php esc_html_e( 'Purge All', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </a>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=nppp_preload_cache'), 'preload_cache_nonce')); ?>" class="nppp-button nppp-button-primary" id="nppp-preload-button">
                        <span class="dashicons dashicons-update" style="font-size: 18px;"></span>
                        <?php esc_html_e( 'Preload All', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </a>
                </div>
                <p class="nppp-cache-tip">
                    <span class="dashicons dashicons-info"></span>
                    <?php esc_html_e( 'Use Purge All to stop Preload All', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </p>
            </div>
        </div>
        <?php if (nppp_is_assume_nginx_mode()) : ?>
        <div id="nppp-assume">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <strong><?php echo esc_html__('Assume-Nginx Mode Active', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
            <?php if (class_exists('\NPPP\Setup')): ?>
                <a href="<?php echo esc_url( admin_url('admin.php?page=' . \NPPP\Setup::PAGE_SLUG) ); ?>" class="button button-small" style="margin-left:auto;">
                    <?php echo esc_html__('Setup', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <h2></h2>
        <div id="nppp-nginx-tabs">
            <div class="tab-header-container">
                <ul>
                    <li>
                        <a href="#settings">
                            <?php echo do_shortcode('[nppp_svg_icon icon="settings" class="tab-icon" size="24px"]'); ?>
                            <span class="tab-text"><?php echo esc_html__( 'Settings', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="#status">
                            <?php echo do_shortcode('[nppp_svg_icon icon="status" class="tab-icon" size="24px"]'); ?>
                            <span class="tab-text"><?php echo esc_html__( 'Status', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="#premium">
                            <?php echo do_shortcode('[nppp_svg_icon icon="advanced" class="tab-icon" size="24px"]'); ?>
                            <span class="tab-text"><?php echo esc_html__( 'Advanced', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="#help">
                            <?php echo do_shortcode('[nppp_svg_icon icon="help" class="tab-icon" size="24px"]'); ?>
                            <span class="tab-text"><?php echo esc_html__( 'Help', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        </a>
                    </li>
                </ul>
            </div>
            <div id="settings" class="tab-content">
                <div id="settings-content-placeholder" style="display: none;">
                <div class="nppp-submenu">
                    <ul>
                        <li><a href="#purge-options"><?php echo esc_html__( 'Purge Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></a></li>
                        <li><a href="#preload-options"><?php echo esc_html__( 'Preload Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></a></li>
                        <li><a href="#schedule-options"><?php echo esc_html__( 'Schedule Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></a></li>
                        <li><a href="#advanced-options"><?php echo esc_html__( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></a></li>
                        <li><a href="#mail-options"><?php echo esc_html__( 'Mail Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></a></li>
                        <li><a href="#logging-options"><?php echo esc_html__( 'Logging Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></a></li>
                    </ul>
                </div>
                <form id="nppp-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php
                    wp_nonce_field('nginx_cache_settings_nonce', 'nginx_cache_settings_nonce');
                    ?>
                    <input type="hidden" name="action" value="save_nginx_cache_settings">
                    <table class="form-table">
                        <!-- Start Purge Options Section -->
                        <tr valign="top">
                            <th scope="row" style="padding: 0; padding-top: 15px;">
                                <h3 id="purge-options" style="margin: 0; padding: 0;">
                                    <?php echo esc_html__( 'Purge Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </h3>
                            </th>
                            <td style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2" style="padding-left: 0; margin: 0;"><hr class="nppp-separator" style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-site"></span>
                                <?php echo esc_html__( 'Nginx Cache Directory', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_path_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Provide the full NGINX cache directory path for plugin operation.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'The directory must be configured in NGINX and accessible by the PHP process.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'With read and write permissions for cache purge and preload to function properly.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <div class="cache-paths-info">
                                    <h4><?php echo esc_html__( 'Allowed Cache Paths', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h4>
                                    <p>
                                        <strong><?php echo esc_html__( 'For RAM-based:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Use directories under', 'fastcgi-cache-purge-and-preload-nginx' ); ?> <code>/dev/shm/</code> | <code>/tmp/</code> | <code>/var/run/</code>
                                    </p>
                                    <p>
                                        <strong><?php echo esc_html__( 'For persistent disk:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Use directories under', 'fastcgi-cache-purge-and-preload-nginx' ); ?> <code>/cache/</code> | <code>/var/</code>
                                    </p>
                                    <p>
                                        <strong><?php echo esc_html__( 'Important:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Paths must be at least one level deeper (e.g. /tmp/cache | /dev/shm/nginx-cache | /var/cache/nginx | /var/nginx-cache | /var/run/nginx-cache | /cache/mysite).', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                        <br class="line-break">
                                        <?php echo esc_html__( 'Critical system paths are prohibited by default to ensure accuracy to avoid unintended deletions.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <!-- Auto Purge Options Section -->
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-trash"></span>
                                <?php echo esc_html__( 'Auto Purge', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-autopurge">
                                        <?php nppp_nginx_cache_purge_on_update_callback(); ?>
                                    </div>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__( 'Automatically purges cache when content or site changes occurs.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Single-item events purge the page and, if enabled under Related Pages, also purge the Homepage, Shop Page and/or Category. Site-wide events purge the entire cache.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'This setting does not warm cache by itself. To warm automatically after automatic purges, enable Auto Preload below.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <div class="cache-paths-info">
                                    <h4><?php echo esc_html__( 'The entire cache is automatically purged when:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h4>
                                    <p>
                                        <strong><?php echo esc_html__( 'Theme', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong> (<?php echo esc_html__( 'active', 'fastcgi-cache-purge-and-preload-nginx' ); ?>) <?php echo esc_html__( 'is switched or updated.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <strong><?php echo esc_html__( 'Plugin', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong> <?php echo esc_html__( 'is activated, updated, or deactivated.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <strong><?php echo esc_html__( 'Compatible Caching Plugins', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'trigger a cache purge.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <strong><?php echo esc_html__( 'Elementor Theme Templates', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        (<?php echo esc_html__( 'Header / Footer / Single / Archive / Popup', 'fastcgi-cache-purge-and-preload-nginx' ); ?>)
                                        <?php echo esc_html__( 'are saved.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <strong><?php echo esc_html__( 'Elementor Files / CSS', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'are regenerated or cleared.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                    <br>
                                    <h4><?php echo esc_html__( 'The cache for a single URL is automatically purged when:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h4>
                                    <p>
                                        <strong><?php echo esc_html__( 'Post/Page', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'content is changed (publish/update).', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <strong><?php echo esc_html__( 'Comment', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'is approved or its status is changed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p><br>
                                    <p>
                                        <?php echo esc_html__( 'If Auto Preload is ON, single-item automatic purges will preload the page and—if Related Pages are enabled—the Homepage, Shop Page and/or Category archives. Site-wide automatic purges will start a global preload.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-cloud"></span>
                                <?php echo esc_html__( 'Cloudflare Cache Sync', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-cloudflare">
                                        <?php nppp_nginx_cache_cloudflare_apo_sync_callback(); ?>
                                    </div>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__( 'Sync Cloudflare cache purges to keep both caches aligned.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Independent from “Auto Purge”. When ON, Cloudflare cache is purged whenever Nginx cache purges (URLs or purge-all).', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Requires the official Cloudflare WordPress plugin with APO or PSC enabled and authentication completed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                            </td>
                        </tr>
                        <!-- Redis Object Cache Sync Section -->
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-database"></span>
                                <?php echo esc_html__( 'Redis Object Cache Sync', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-redis">
                                        <?php nppp_nginx_cache_redis_cache_sync_callback(); ?>
                                    </div>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__( 'Sync Redis Object Cache with Nginx cache to keep both caches aligned.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'When ON: Nginx purge-all also flushes Redis object cache. Redis flush also purges Nginx cache (requires Auto Purge enabled).', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Requires the Redis Object Cache plugin installed, configured, and connected.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                            </td>
                        </tr>
                        <!-- Related post/page purge Options Section -->
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php echo esc_html__( 'Purge Scope', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_related_pages_callback(); ?>
                            </td>
                        </tr>
                        <!-- Start Preload Options Section -->
                        <tr valign="top">
                            <th scope="row" style="padding: 0; padding-top: 15px;">
                                <h3 id="preload-options" style="margin: 0; padding: 0;">
                                    <?php echo esc_html__( 'Preload Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </h3>
                            </th>
                            <td style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2" style="padding-left: 0; margin: 0;"><hr class="nppp-separator" style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-update"></span>
                                <?php echo esc_html__( 'Auto Preload', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-preload">
                                        <?php nppp_nginx_cache_auto_preload_callback(); ?>
                                    </div>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Enable this feature to automatically preload the cache after purging. This ensures fast page load times for visitors by proactively caching content.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'When enabled, your website\'s cache will preload with the latest content automatically after purge, ensuring quick loading times even for uncached pages.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'This feature is particularly useful for dynamic websites with frequently changing content.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'This feature triggers when either Auto Purge feature is enabled or when the Purge All cache action is used manually.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-smartphone"></span>
                                <?php echo esc_html__( 'Preload Mobile', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-preload-mobile">
                                        <?php nppp_nginx_cache_auto_preload_mobile_callback(); ?>
                                    </div>
                                </div>
                                <div class="key-regex-info">
                                    <p class="description"><?php echo esc_html__( 'Preload also Nginx cache for Mobile devices separately. This feature supports for both entire and single POST/PAGE cache events.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'Only enable if you have different content, themes or configurations for Mobile and Desktop devices and need to warm the cache for both.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'If enabled, this feature always triggers automatically when Preload actions are called via Rest, Cron or Admin, regardless of whether Auto Preload or Auto Purge are enabled.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'If only Auto Preload is enabled, it also triggers automatically after Purge actions are called via Rest, Admin.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'When both Auto Purge and Auto Preload are enabled, it triggers automatically when the cache is purged through Auto Purge conditions or when Purge actions are called via Rest or Admin.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                                <div class="cache-paths-info">
                                    <h4><strong><?php echo esc_html__( 'Note:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong></h4>
                                    <p><?php echo esc_html__( 'The Mobile Preload action will begin after the main Preload process completes via the WordPress Cron job. As a result, the Mobile Preload action may start with a delay. To track the status of this process, please refer to the log section of the plugin.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-backup"></span>
                                <?php echo esc_html__( 'Preload Watchdog', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-watchdog">
                                        <?php nppp_nginx_cache_watchdog_callback(); ?>
                                    </div>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Enable the preload watchdog. When active, watchdog monitors the preload and fires post-preload tasks immediately when preload finishes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Especially useful on zero-traffic or fully-cached sites where WP-Cron may be delayed. If disabled, post-preload tasks are handled entirely by WP-Cron.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Recommended for users have cron delay issues. The watchdog is lightweight and exits automatically once preload completes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-randomize"></span>
                                <?php echo esc_html__('Use Proxy', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            </th>
                            <td>
                                <div class="nppp-onoffswitch-proxy">
                                    <?php nppp_nginx_cache_enable_proxy_callback(); ?>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Routes preload requests through your HTTP proxy (e.g. mitmproxy) so the cache is warmed via the proxy.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Enable if you need proxy features (debugging/inspection, custom headers, mTLS, fixed egress IP, corporate proxy, DNS overrides).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'If enabling, follow the Help tab for example use cases, keep in mind to set the proxy URL/PORT below.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Note: A proxy adds latency to cache preload.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                <?php echo esc_html__('Proxy Host', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_proxy_host_callback(); ?>
                                <p class="description"><?php echo esc_html__('Enter the proxy IP, hostname, or container hostname (e.g., 127.0.0.1, localhost, or my-proxy).', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-links"></span>
                                <?php echo esc_html__('Proxy Port', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_proxy_port_callback(); ?>
                                <p class="description"><?php echo esc_html__('Enter the proxy port (e.g., 8080).', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-editor-code"></span>
                                <?php echo esc_html__( 'URL Normalization', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_pctnorm_mode_callback(); ?>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-dashboard"></span>
                                <?php echo esc_html__( 'CPU Usage Limit (%)', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_cpu_limit_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Enter the CPU usage limit for wget (%).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'wget can cause high CPU usage; if you encounter this problem, install cpulimit via package manager to manage it (10-100%).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-no"></span>
                                <?php echo esc_html__( 'Exclude Endpoints', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_reject_regex_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Enter a regex pattern to exclude endpoints from being cached while Preloading. Use | as a delimiter for multiple patterns.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'The default regex patterns exclude dynamic endpoints to prevent caching of user-specific content such as wp-admin|my-account.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'These exclusions are better handled server-side using _cache_bypass, _no_cache, and skip_cache rules in your Nginx configuration.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Here, these patterns are used to prevent wget from making requests to these endpoints during the Preloading process to avoid unnecessary server load.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <button type="button" id="nginx-regex-reset-defaults" class="button nginx-reset-regex-button">
                                    <?php echo esc_html__( 'Reset Default', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </button>
                                <div class="cache-paths-info">
                                    <p class="description"><?php echo esc_html__( 'Click the button to reset defaults. After plugin updates, it\'s best to reset first to apply the latest changes, then reapply your custom rules.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-no"></span>
                                <?php echo esc_html__( 'Exclude File Extensions', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_reject_extension_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Enter file extensions to exclude from being downloaded during Preloading. Use commas to separate each extension.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Nginx cache is designed to cache dynamic content, such as PHP-generated pages. Static assets like CSS, JS, and images are not cached by Nginx.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Nginx efficiently serves static assets from the disk, and headers like expires help reduce frequent requests for these files.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'By excluding static files, Preload operation are accelerated by avoiding unnecessary requests via wget for static assets.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <button type="button" id="nginx-extension-reset-defaults" class="button nginx-reset-extension-button">
                                    <?php echo esc_html__( 'Reset Default', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </button>
                                <div class="cache-paths-info">
                                     <p class="description"><?php echo esc_html__( 'Click the button to reset defaults. After plugin updates, it\'s best to reset first to apply the latest changes, then reapply your custom rules.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <?php echo esc_html__( 'Limit Rate', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                 <?php nppp_nginx_cache_limit_rate_callback(); ?>
                                 <p class="description"><?php echo esc_html__( 'Enter a limit rate for preload action in KB/Sec.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Preventing excessive bandwidth usage and avoiding overwhelming the server.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-hourglass"></span>
                                <?php echo esc_html__( 'Wait Time', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                 <?php nppp_nginx_cache_wait_request_callback(); ?>
                                 <p class="description"><?php echo esc_html__( 'Wait the specified number of seconds between the retrievals.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Use of this option is recommended, as it lightens the server load by making the requests less frequent.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Higher values dramatically increase cache preload times, while lowering the value can increase server load (CPU, Memory, Network).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Adjust the values to find the optimal balance based on your desired server resource allocation.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'If you encounter unexpected permission issues or risk overwhelming your server, try setting it to 1 first and take small steps with each adjustment.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Default: 0 second, Disabled', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-clock"></span>
                                <?php echo esc_html__( 'PHP Response Timeout', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                 <?php nppp_nginx_cache_read_timeout_callback(); ?>
                                 <p class="description"><?php echo esc_html__( 'Maximum seconds preload process will wait for PHP-FPM to start sending a response before abandoning a URL.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'This is not a total page download limit — it measures idle time with no data received (Time To First Byte).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Simple sites or blogs can use lower values (15–30s). Complex setups such as WooCommerce stores, membership sites, or pages with heavy plugins may need higher values (60–120s).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'If pages are being skipped during preload and staying uncached, increase this value first before investigating other causes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Default: 60 seconds', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <!-- Start Schedule Options Section -->
                        <tr valign="top">
                            <th scope="row" style="padding: 0; padding-top: 15px;">
                                <h3 id="schedule-options" style="margin: 0; padding: 0;">
                                    <?php echo esc_html__( 'Schedule Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </h3>
                            </th>
                            <td style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2" style="padding-left: 0; margin: 0;"><hr class="nppp-separator" style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-clock"></span>
                                <?php echo esc_html__( 'WP Schedule Cache', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-preload">
                                        <?php nppp_nginx_cache_schedule_callback(); ?>
                                    </div>
                                </div>
                                <div class="nppp-select-wrapper">
                                    <div class="nppp-cron-event-select-container">
                                        <select name="cron_event" id="nppp_cron_event" class="nppp-cron-event-select">
                                            <option value="" disabled selected><?php echo esc_html__( 'On Every', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                                            <option value="daily"><?php echo esc_html__( 'Every Day', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                                            <option value="weekly"><?php echo esc_html__( 'Every Week', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                                            <option value="monthly"><?php echo esc_html__( 'Every Month', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="nppp-time-select-container">
                                        <div class="nppp-input-group">
                                            <input id="nppp_datetimepicker1Input" type="text" placeholder="<?php echo esc_attr__( 'Time', 'fastcgi-cache-purge-and-preload-nginx' ); ?>"/>
                                            <div class="nppp-input-group-append">
                                                <button type="button" id="nginx-cache-schedule-set" class="button nginx-cache-schedule-set-button">
                                                    <span class="nppp-tooltip">
                                                        <?php echo esc_html__( 'SET CRON', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                                        <span class="nppp-tooltiptext"><?php echo esc_html__( 'Click to set cron schedule', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <ul class="scheduled-events-list">
                                    <?php nppp_get_active_cron_events(); ?>
                                </ul>
                                <div class="key-regex-info">
                                    <p class="description"><?php echo esc_html__( 'Enable this feature to automatically schedule cache preloading task at specified intervals.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'This ensures that your website’s cache is consistently updated, optimizing performance and reducing server load.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'When enabled, your website will automatically refresh its cache according to the configured schedule, keeping content up-to-date and reducing load times for visitors.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'This feature is particularly useful for maintaining peak performance on dynamic websites with content that changes periodically.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'By scheduling caching tasks, you can ensure that your site remains fast and responsive, even during peak traffic periods.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php echo esc_html__( 'REST API', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-preload">
                                        <?php nppp_nginx_cache_api_callback(); ?>
                                    </div>
                                </div>
                                <?php nppp_nginx_cache_api_key_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Enable this feature to for remote triggering of Purge and Preload actions.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'This functionality streamlines cache management, enhancing website performance and efficiency through seamless integration with external systems.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'The REST API capability ensures effortless cache control from anywhere, facilitating automated maintenance and optimization.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <br>
                                <p class="description"><strong><?php echo esc_html__( 'API Key Management and Usage:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong></p>
                                <ul class="description" style="color: #646970; font-size: 14px;">
                                    <li>
                                        <strong><?php echo esc_html__( 'Generate API Key:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Click to generate a new API Key. Also you can create your own 64-char API Key and Update Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'API Key:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Click to copy your API Key to the clipboard.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'Purge URL:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Click to copy a pre-configured curl command for cache purging.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'Preload URL:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Click to copy a pre-configured curl command for cache preloading.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </li>
                                </ul>
                                <p class="description"><strong><?php echo esc_html__( 'Allowed API Authentication Headers:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong></p>
                                <ul class="description" style="color: #646970; font-size: 14px;">
                                    <li>
                                        <strong><?php echo esc_html__( 'Authorization Header:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <code><?php echo esc_html__( 'Authorization: Bearer YOUR_API_KEY', 'fastcgi-cache-purge-and-preload-nginx' ); ?></code>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'X-Api-Key Header:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <code><?php echo esc_html__( 'X-Api-Key: YOUR_API_KEY', 'fastcgi-cache-purge-and-preload-nginx' ); ?></code>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'Request Body or Query String Parameter:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <code><?php echo esc_html__( 'api_key=YOUR_API_KEY', 'fastcgi-cache-purge-and-preload-nginx' ); ?></code>
                                    </li>
                                </ul>
                            </td>
                        </tr>
                        <!-- Start Advanced Options Section -->
                        <tr valign="top">
                            <th scope="row" style="padding: 0; padding-top: 15px;">
                                <h3 id="advanced-options" style="margin: 0; padding: 0;">
                                    <?php echo esc_html__( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </h3>
                            </th>
                            <td style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2" style="padding-left: 0; margin: 0;"><hr class="nppp-separator" style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-edit"></span>
                                <?php echo esc_html__( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_key_custom_regex_callback(); ?>
                                <div class="key-regex-info">
                                    <p class="description">⚡ <?php echo esc_html__('The default regex pattern is designed to parse the \'$host\' and \'$request_uri\' portions from the only standard cache key format supported by the plugin: \'$scheme$request_method$host$request_uri\'', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">⚡ <?php echo esc_html__('If you use a non-standard or complex \'_cache_key\' format, you must define a custom regex pattern to correctly parse \'$host\' and \'$request_uri\' portions in order to ensure proper plugin functionality.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">⚡ <?php echo esc_html__('For example, if your custom key format is \'$scheme$request_method$host$mobile_device_type$request_uri$is_args$args\', you will need to provide a corresponding regex pattern that accurately captures the \'$host\' and \'$request_uri\' parts.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">📌 <strong><?php echo esc_html__('Guidelines for creating a compatible regex:', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong></p>
                                    <p class="description">📣 <?php echo esc_html__('Ensure your regex pattern targets only GET requests, as HEAD or any other request methods do not represent cached content and cause duplicates.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                    <p class="description">📣 <?php echo esc_html__('Ensure that your regex pattern is entered with delimiters. (e.g., /your-regex/ - #your-regex#)', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                    <p class="description">📣 <?php echo esc_html__('The regex must capture the \'$host\' in capture group 1 as matches[1] and \'$request_uri\' in capture group 2 as matches[2] from your custom _cache_key', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                </div>
                                <div class="cache-paths-info">
                                    <h4><?php echo esc_html__('Example', 'fastcgi-cache-purge-and-preload-nginx'); ?></h4>
                                    <p><?php echo esc_html__('fastcgi_cache_key "$scheme$request_method$host$device$request_uri"', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                    <p><?php echo esc_html__('KEY: httpsGETpsauxit.comMOBILE/category/nginx-cache/2025', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p><?php echo esc_html__('This example demonstrates how the regex must capture the $host and $request_uri in two separate groups.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <div>
                                        <h4><?php echo esc_html__('Matches', 'fastcgi-cache-purge-and-preload-nginx'); ?></h4>
                                        <p><?php echo esc_html__('0  =>  KEY: httpsGETpsauxit.comMOBILE/category/nginx-cache/2025', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                        <p><?php echo esc_html__('1  =>  psauxit.com', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                        <p><?php echo esc_html__('2  =>  /category/nginx-cache/2025', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                    </div>
                                </div>
                                <br>
                                <p class="description">🚨 <strong><?php echo esc_html__('You need to follow these security guidelines for your regex pattern:', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong></p>
                                <p class="description">📣 <?php echo esc_html__('Checks for excessive lookaheads, catastrophic backtracking. (limit to 3).', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                <p class="description">📣 <?php echo esc_html__('Don\'t use greedy quantifiers inside lookaheads.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                <p class="description">📣 <?php echo esc_html__('Checks .* quantifiers. (limit to 1).', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                <p class="description">📣 <?php echo esc_html__('Checks for excessively long regex patterns. (limit length to 300 characters).', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                <button type="button" id="nginx-key-regex-reset-defaults" class="button nginx-reset-key-regex-button">
                                    <?php echo esc_html__('Reset Default', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                                </button>
                                <div class="cache-paths-info">
                                    <p class="description"><?php echo esc_html__('Click the button to reset defaults. After plugin updates, it\'s best to reset first to apply the latest changes, then reapply your custom rules.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                </div>
                            </td>
                        </tr>
                        <!-- HTTP Purge Fast-Path Section -->
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-rest-api"></span>
                                <?php echo esc_html__( 'HTTP Purge', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-httppurge">
                                        <?php nppp_http_purge_enabled_callback(); ?>
                                    </div>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Delegates single-URL purging to Nginx itself via the ngx_cache_purge module instead of NPP touching the filesystem — applies to both manual and auto purge triggers.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Broadly compatible with managed hosting and control panels where ngx_cache_purge is pre-compiled.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Purge All always uses filesystem operations — HTTP Purge applies only to single-URL and related URL purges.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Falls back to filesystem purge automatically if the module is unavailable — existing workflow is fully preserved.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top" id="nppp-http-purge-suffix-row">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-links"></span>
                                <?php echo esc_html__( 'Purge URL Suffix', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_http_purge_suffix_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'URL prefix for the purge endpoint. Matches the location block in nginx.conf. Default: purge.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top" id="nppp-http-purge-custom-url-row">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                <?php echo esc_html__( 'Purge Custom Base URL', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_http_purge_custom_url_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Leave blank to auto-build the HTTP purge URL from your site URL and the suffix above.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Set this when the purge endpoint differs from your public site URL — Docker networks, separate Nginx server, non-standard port, or cPanel/Plesk environments with a custom Nginx layer.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <!-- Start Mail Options Section -->
                        <tr valign="top">
                            <th scope="row" style="padding: 0; padding-top: 15px;">
                                <h3 id="mail-options" style="margin: 0; padding: 0;">
                                    <?php echo esc_html__( 'Mail Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </h3>
                            </th>
                            <td style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2" style="padding-left: 0; margin: 0;"><hr class="nppp-separator" style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-email-alt"></span>
                                <?php echo esc_html__( 'Send Email Notification', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-onoffswitch">
                                    <?php nppp_nginx_cache_send_mail_callback(); ?>
                                </div>
                                <div class="key-regex-info">
                                    <p class="description"><?php echo esc_html__( 'Enable this feature to receive email notifications about essential plugin activities, ensuring you stay informed about preload actions, cron task statuses, and general plugin updates.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-email"></span>
                                <?php echo esc_html__( 'Email Address', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_email_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Enter an email address to get Nginx Cache operation notifications.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <!-- Start Logging Options Section -->
                        <tr valign="top">
                            <th scope="row" style="padding: 0; padding-top: 15px;">
                                <h3 id="logging-options" style="margin: 0; padding: 0;">
                                    <?php echo esc_html__( 'Logging Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </h3>
                            </th>
                            <td style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2" style="padding-left: 0; margin: 0;"><hr class="nppp-separator" style="margin: 0; padding: 0;"></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-archive"></span>
                                <?php echo esc_html__( 'Logs', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_logs_callback(); ?>
                                <button type="button" id="clear-logs-button" class="button nginx-clear-logs-button">
                                    <?php echo esc_html__( 'Clear Logs', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </button>
                                <p class="description">
                                    <?php echo esc_html__( 'Click the button to clear logs.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="nppp_submit" class="button-primary" value="<?php echo esc_attr__( 'Update Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">
                    </p>
                </form>
                </div>
            </div>

            <div id="status" class="tab-content">
                <div id="status-content-placeholder" style="display: none;"></div>
            </div>

            <div id="premium" class="tab-content">
                <div id="premium-content-placeholder" style="display: none;"></div>
            </div>

            <div id="help" class="tab-content">
                <?php echo do_shortcode('[nppp_my_faq]'); ?>
            </div>
        </div>
    </div>
    <?php
}

// Processes the form submission, validates the nonce,
// checks user permissions, sanitize & validate and save the plugin settings,
// clear plugin cache if Nginx Cache Path updated,
// redirects the user back to the settings page with a message
// This function hooks into the 'admin_post'
function nppp_handle_nginx_cache_settings_submission() {
    // Check if the form has been submitted
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_nginx_cache_settings') {
        // Check nonce exists
        if (isset($_POST['nginx_cache_settings_nonce'])) {
            // Sanitize the nonce
            $nonce = sanitize_text_field(wp_unslash($_POST['nginx_cache_settings_nonce']));

            // Verify the nonce
            if (wp_verify_nonce($nonce, 'nginx_cache_settings_nonce')) {
                // Capability check
                if (!current_user_can('manage_options')) {
                    wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'fastcgi-cache-purge-and-preload-nginx'));
                }

                // Check if 'nginx_cache_settings' is set in the POST data
                if (isset($_POST['nginx_cache_settings'])) {
                    // Retrieve existing options before sanitizing the input
                    $existing_options = get_option('nginx_cache_settings');

                    // Ignored PCP warning because we use custom sanitization via 'nppp_nginx_cache_settings_sanitize()'.
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Already sanitized
                    $nginx_cache_settings = wp_unslash($_POST['nginx_cache_settings']);

                    // This is a pre-check to catch sanitization errors early, before calling update_option, to ensure proper redirection
                    // 'nppp_nginx_cache_settings_sanitize' already registered for 'update_option' action via 'register_setting'
                    // Note: If validation and sanitization are successful for the 'nginx_cache_key_custom_regex'
                    // It menas we have a base_64 encoded 'nginx_cache_key_custom_regex' option now
                    $new_settings = nppp_nginx_cache_settings_sanitize($nginx_cache_settings);

                    // Check if there are any settings errors
                    $errors = get_settings_errors('nppp_nginx_cache_settings_group');

                    // If there are no sanitize errors, proceed to update the settings
                    if (empty($errors)) {
                        // PRESERVE UNTOUCHED KEYS — merge sanitized with existing
                        $existing_options = (array) $existing_options;
                        $merged = wp_parse_args($new_settings, $existing_options);

                        // Always delete the plugin permission cache when the form is submitted
                        $static_key_base = 'nppp';
                        $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
                        delete_transient($transient_key_permissions_check);
                        delete_transient('nppp_safexec_ok');

                        // Update the settings
                        // Note: This will re-encode 'nginx_cache_key_custom_regex' via sanitization
                        update_option('nginx_cache_settings', $merged);

                        // Redirect with success message
                        wp_safe_redirect(add_query_arg(array(
                            'status_message' => urlencode(__('Plugin cache (permission) cleared, settings saved successfully!', 'fastcgi-cache-purge-and-preload-nginx')),
                            'message_type' => 'success',
                            'redirect_nonce' => wp_create_nonce('nppp_redirect_nonce')
                        ), admin_url('options-general.php?page=nginx_cache_settings')));
                        exit;
                    } else {
                        // Redirect with error messages
                        $error_messages = array();
                        foreach ($errors as $error) {
                            $error_messages[] = esc_html($error['message']);
                        }

                        wp_safe_redirect(add_query_arg(array(
                            'status_message' => urlencode(implode(', ', $error_messages)),
                            'message_type' => 'error',
                            'redirect_nonce' => wp_create_nonce('nppp_redirect_nonce')
                        ), admin_url('options-general.php?page=nginx_cache_settings')));
                        exit;
                    }
                } else {
                    // No settings submitted
                    wp_die(esc_html__('No settings to save.', 'fastcgi-cache-purge-and-preload-nginx'));
                }
            } else {
                // Nonce verification failed
                wp_die(esc_html__('Nonce verification failed', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            // Nonce verification failed
            wp_die(esc_html__('Nonce not found', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }
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
    $current_options = get_option('nginx_cache_settings');

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
    $current_options = get_option('nginx_cache_settings');
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
    $options = get_option('nginx_cache_settings');

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
    $current_options = get_option('nginx_cache_settings');

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
    $current_options = get_option('nginx_cache_settings');
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
    $current_options = get_option('nginx_cache_settings');
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
    $current_options = get_option('nginx_cache_settings');
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

    // Get Plugin settings and set default API Key
    $options = get_option('nginx_cache_settings');
    $default_api_key = bin2hex(random_bytes(32));

    // Construct the REST API purge URL
    $fdomain = get_site_url();
    $rest_api_route_purge = 'wp-json/nppp_nginx_cache/v2/purge';
    $rest_api_purge_url = $fdomain . '/' . $rest_api_route_purge;
    // Create the JSON data string with the API key
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;
    $json_data = '{"api_key": "' . $api_key . '"}';
    // Construct the CURL command string
    $curl_command = 'curl -X POST -H "Content-Type: application/json" -d \'' . $json_data . '\' ' . $rest_api_purge_url;
    // Return the rest api purge curl url the AJAX response
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

    // Get Plugin settings and set default API Key
    $options = get_option('nginx_cache_settings');
    $default_api_key = bin2hex(random_bytes(32));

    // Construct the REST API preload URL
    $fdomain = get_site_url();
    $rest_api_route_preload = 'wp-json/nppp_nginx_cache/v2/preload';
    $rest_api_preload_url = $fdomain . '/' . $rest_api_route_preload;
    // Create the JSON data string with the API key
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;
    $json_data = '{"api_key": "' . $api_key . '"}';
    // Construct the CURL command string
    $curl_command = 'curl -X POST -H "Content-Type: application/json" -d \'' . $json_data . '\' ' . $rest_api_preload_url;
    // Return the rest api preload curl url the AJAX response
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

// Callback function to display the settings section description.
function nppp_nginx_cache_settings_section_callback() {
    echo 'Configure the settings for FastCGI Cache.';
}

// Callback function to display the input field for Nginx Cache Path setting
function nppp_nginx_cache_path_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    echo "<input type='text' id='nginx_cache_path' name='nginx_cache_settings[nginx_cache_path]' value='" . esc_attr($options['nginx_cache_path'] ?? $default_cache_path) . "' class='regular-text' />";
}

// Callback function to display the input field for Email Address setting
function nppp_nginx_cache_email_callback() {
    $options = get_option('nginx_cache_settings');
    $default_email = 'your-email@example.com';
    echo "<input type='text' id='nginx_cache_email' name='nginx_cache_settings[nginx_cache_email]' value='" . esc_attr($options['nginx_cache_email'] ?? $default_email) . "' class='regular-text' />";
}

// Callback function to display the input field for CPU Usage Limit setting
function nppp_nginx_cache_cpu_limit_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cpu_limit = 100;
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='10' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit'] ?? $default_cpu_limit) . "' class='small-text' />";
}

// Callback function to display the input field for Per Request Wait Time setting
function nppp_nginx_cache_wait_request_callback() {
    $options = get_option('nginx_cache_settings');
    $default_wait_time = 0;
    echo "<input type='number' id='nginx_cache_wait_request' name='nginx_cache_settings[nginx_cache_wait_request]' min='0' max='60' value='" . esc_attr($options['nginx_cache_wait_request'] ?? $default_wait_time) . "' class='small-text' />";
}

// Callback function to display the input field for PHP Response Timeout setting
function nppp_nginx_cache_read_timeout_callback() {
    $options = get_option('nginx_cache_settings');
    $default_read_timeout = 60;
    echo "<input type='number' id='nginx_cache_read_timeout' name='nginx_cache_settings[nginx_cache_read_timeout]' min='10' max='300' value='" . esc_attr($options['nginx_cache_read_timeout'] ?? $default_read_timeout) . "' class='small-text' />";
}

// Callback function to display the checkbox for Send Email Notification setting
function nppp_nginx_cache_send_mail_callback() {
    $options = get_option('nginx_cache_settings');
    $send_mail_checked = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes' ? 'checked="checked"' : '';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_send_mail]" class="nppp-onoffswitch-checkbox" value='yes' id="nginx_cache_send_mail" <?php echo esc_attr($send_mail_checked); ?>>
    <label class="nppp-onoffswitch-label" for="nginx_cache_send_mail">
        <span class="nppp-onoffswitch-inner">
            <span class="nppp-off"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_auto_preload field
function nppp_nginx_cache_auto_preload_callback() {
    $options = get_option('nginx_cache_settings');
    $auto_preload_checked = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_auto_preload]" class="nppp-onoffswitch-checkbox-preload" value="yes" id="nginx_cache_auto_preload" <?php echo esc_attr($auto_preload_checked); ?>>
    <label class="nppp-onoffswitch-label-preload" for="nginx_cache_auto_preload">
        <span class="nppp-onoffswitch-inner-preload">
            <span class="nppp-off-preload"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-preload"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-preload"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_schedule field
function nppp_nginx_cache_schedule_callback() {
    $options = get_option('nginx_cache_settings');
    $cache_schedule_checked = isset($options['nginx_cache_schedule']) && $options['nginx_cache_schedule'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_schedule]" class="nppp-onoffswitch-checkbox-schedule" value="yes" id="nginx_cache_schedule" <?php echo esc_attr($cache_schedule_checked); ?>>
    <label class="nppp-onoffswitch-label-schedule" for="nginx_cache_schedule">
        <span class="nppp-onoffswitch-inner-schedule">
            <span class="nppp-off-schedule"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-schedule"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-schedule"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_purge_on_update field
function nppp_nginx_cache_purge_on_update_callback() {
    $options = get_option('nginx_cache_settings');
    $auto_purge_checked = isset($options['nginx_cache_purge_on_update']) && $options['nginx_cache_purge_on_update'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_purge_on_update]" class="nppp-onoffswitch-checkbox-autopurge" value="yes" id="nginx_cache_purge_on_update" <?php echo esc_attr($auto_purge_checked); ?>>
    <label class="nppp-onoffswitch-label-autopurge" for="nginx_cache_purge_on_update">
        <span class="nppp-onoffswitch-inner-autopurge">
            <span class="nppp-off-autopurge"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-autopurge"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-autopurge"></span>
    </label>
    <?php
}

// Callback function for the Cloudflare APO sync field.
function nppp_nginx_cache_cloudflare_apo_sync_callback() {
    $options = get_option('nginx_cache_settings');
    $is_checked = isset($options['nppp_cloudflare_apo_sync']) && $options['nppp_cloudflare_apo_sync'] === 'yes';
    $cloudflare_checked = $is_checked ? 'checked="checked"' : '';
    $is_available = function_exists('nppp_cloudflare_apo_is_available') && nppp_cloudflare_apo_is_available();
    if ( ! $is_available && isset($options['nppp_cloudflare_apo_sync']) && $options['nppp_cloudflare_apo_sync'] !== 'no' ) {
        $options['nppp_cloudflare_apo_sync'] = 'no';
        update_option('nginx_cache_settings', $options);
        $cloudflare_checked = '';
    }
    $disabled = $is_available ? '' : 'disabled="disabled"';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nppp_cloudflare_apo_sync]" class="nppp-onoffswitch-checkbox-cloudflare" value="yes" id="nppp_cloudflare_apo_sync" <?php echo esc_attr($cloudflare_checked); ?> <?php echo esc_attr($disabled); ?>>
    <label class="nppp-onoffswitch-label-cloudflare" for="nppp_cloudflare_apo_sync">
        <span class="nppp-onoffswitch-inner-cloudflare">
            <span class="nppp-off-cloudflare"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-cloudflare"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-cloudflare"></span>
    </label>
    <?php if ( ! $is_available ) : ?>
        <div class="nppp-related-pages" aria-live="polite">
            <em class="nppp-hint" role="note" style="max-width:max-content; opacity: 0.5;">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <?php echo esc_html__( 'Cloudflare APO plugin not detected. Install and configure it to enable sync.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </em>
        </div>
    <?php endif; ?>
    <?php
}

// Callback function for the Redis Cache field.
function nppp_nginx_cache_redis_cache_sync_callback() {
    $options     = get_option( 'nginx_cache_settings' );
    $is_checked  = isset( $options['nppp_redis_cache_sync'] ) && $options['nppp_redis_cache_sync'] === 'yes';
    $checked_attr = $is_checked ? 'checked="checked"' : '';
    $is_available = function_exists( 'nppp_redis_cache_is_available' ) && nppp_redis_cache_is_available();

    // If the toggle is on but Redis disappeared, clear the stored value.
    if ( ! $is_available && isset( $options['nppp_redis_cache_sync'] ) && $options['nppp_redis_cache_sync'] !== 'no' ) {
        $options['nppp_redis_cache_sync'] = 'no';
        update_option( 'nginx_cache_settings', $options );
        $checked_attr = '';
    }

    $disabled = $is_available ? '' : 'disabled="disabled"';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nppp_redis_cache_sync]" class="nppp-onoffswitch-checkbox-redis" value="yes" id="nppp_redis_cache_sync" <?php echo esc_attr( $checked_attr ); ?> <?php echo esc_attr( $disabled ); ?>>
    <label class="nppp-onoffswitch-label-redis" for="nppp_redis_cache_sync">
        <span class="nppp-onoffswitch-inner-redis">
            <span class="nppp-off-redis"><?php echo esc_html__( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-redis"><?php echo esc_html__( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-redis"></span>
    </label>
    <?php if ( ! $is_available ) : ?>
        <div class="nppp-related-pages" aria-live="polite">
            <em class="nppp-hint" role="note" style="max-width:max-content; opacity: 0.5;">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <?php echo esc_html__( 'Redis Object Cache plugin not detected or not connected. Install and configure it to enable sync.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </em>
        </div>
    <?php endif; ?>
    <?php
}

// Callback function for the nginx_cache_auto_preload_mobile field
function nppp_nginx_cache_auto_preload_mobile_callback() {
    $options = get_option('nginx_cache_settings');
    $auto_preload_mobile_checked = isset($options['nginx_cache_auto_preload_mobile']) && $options['nginx_cache_auto_preload_mobile'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_auto_preload_mobile]" class="nppp-onoffswitch-checkbox-preload-mobile" value="yes" id="nginx_cache_auto_preload_mobile" <?php echo esc_attr($auto_preload_mobile_checked); ?>>
    <label class="nppp-onoffswitch-label-preload-mobile" for="nginx_cache_auto_preload_mobile">
        <span class="nppp-onoffswitch-inner-preload-mobile">
            <span class="nppp-off-preload-mobile"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-preload-mobile"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-preload-mobile"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_watchdog field
function nppp_nginx_cache_watchdog_callback() {
    $options = get_option('nginx_cache_settings');
    $watchdog_checked = isset($options['nginx_cache_watchdog']) && $options['nginx_cache_watchdog'] === 'yes' ? 'checked="checked"' : '';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_watchdog]" class="nppp-onoffswitch-checkbox-watchdog" value="yes" id="nginx_cache_watchdog" <?php echo esc_attr($watchdog_checked); ?>>
    <label class="nppp-onoffswitch-label-watchdog" for="nginx_cache_watchdog">
        <span class="nppp-onoffswitch-inner-watchdog">
            <span class="nppp-off-watchdog"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-watchdog"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-watchdog"></span>
    </label>
    <?php
}

// Callback function to display the Reject Regex field
function nppp_nginx_cache_reject_regex_callback() {
    $options = get_option('nginx_cache_settings');
    $default_reject_regex = nppp_fetch_default_reject_regex();
    $default_reject_regex = isset($options['nginx_cache_reject_regex']) ? $options['nginx_cache_reject_regex'] : $default_reject_regex;
    $reject_regex = preg_replace('/\\\\+/', '\\', $default_reject_regex);
    echo "<textarea id='nginx_cache_reject_regex' name='nginx_cache_settings[nginx_cache_reject_regex]' rows='3' cols='50' class='large-text'>" . esc_textarea($reject_regex) . "</textarea>";
}

// Callback function to display the custom Regex field for fastcgi_cache_key
function nppp_nginx_cache_key_custom_regex_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cache_key_regex = nppp_fetch_default_regex_for_cache_key();
    $cache_key_regex = isset($options['nginx_cache_key_custom_regex']) ? base64_decode($options['nginx_cache_key_custom_regex']) : $default_cache_key_regex;
    // Use wp_kses() with an empty array to allow raw text without HTML sanitization
    echo "<textarea id='nginx_cache_key_custom_regex' name='nginx_cache_settings[nginx_cache_key_custom_regex]' rows='1' cols='50' class='large-text'>" . esc_textarea($cache_key_regex) . "</textarea>";
}

// Callback function to display the Reject extension field
function nppp_nginx_cache_reject_extension_callback() {
    $options = get_option('nginx_cache_settings');
    $default_reject_extension = nppp_fetch_default_reject_extension();
    $default_reject_extension = isset($options['nginx_cache_reject_extension']) ? $options['nginx_cache_reject_extension'] : $default_reject_extension;
    $reject_extension = preg_replace('/\\\\+/', '\\', $default_reject_extension);
    echo "<textarea id='nginx_cache_reject_extension' name='nginx_cache_settings[nginx_cache_reject_extension]' rows='3' cols='50' class='large-text'>" . esc_textarea($reject_extension) . "</textarea>";
}

// Callback function to display the Logs field
function nppp_nginx_cache_logs_callback() {
    $log_file_path = NGINX_CACHE_LOG_FILE;
    nppp_perform_file_operation($log_file_path, 'create');
    if (file_exists($log_file_path) && is_readable($log_file_path)) {
        // Read the log file into an array of lines
        $lines = file($log_file_path);
        // Get the latest 5 lines
        if (is_array($lines)) {
            $latest_lines = array_slice($lines, -5);

            // Remove leading tab spaces and spaces from each line
            $cleaned_lines = array_map(function($line) {
                return trim($line);
            }, $latest_lines);
            ?>
            <div class="logs-container">
                <?php
                // Output the latest 5 lines
                foreach ($cleaned_lines as $line) {
                    if (!empty($line)) {
                        // Extract timestamp and message
                        preg_match('/^\[(.*?)\]\s*(.*?)$/', $line, $matches);
                        $timestamp = isset($matches[1]) ? $matches[1] : '';
                        $message = isset($matches[2]) ? $matches[2] : '';

                        // Apply different CSS classes based on whether it's an error line or not
                        $class = strpos($message, 'ERROR') !== false ? 'error-line' : (strpos($message, 'SUCCESS') !== false ? 'success-line' : 'normal-line');

                        // Output the line with the appropriate CSS class
                        echo '<div class="' . esc_attr($class) . '"><span class="timestamp">' . esc_html($timestamp) . '</span> ' . esc_html($message) . '</div>';
                    }
                }
                ?>
                <div class="cursor blink">#</div>
            </div>
            <?php
        } else {
            echo '<p>' . esc_html__('Unable to read log file. Please check file permissions.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
        }
    } else {
        echo '<p>' . esc_html__('Log file not found or is not readable.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
    }
}

// Callback function to display the input field for Limit Rate setting.
function nppp_nginx_cache_limit_rate_callback() {
    $options = get_option('nginx_cache_settings');
    $default_limit_rate = 5120;
    echo "<input type='number' id='nginx_cache_limit_rate' name='nginx_cache_settings[nginx_cache_limit_rate]' min='1' max='102400' value='" . esc_attr($options['nginx_cache_limit_rate'] ?? $default_limit_rate) . "' class='small-text' />";
}

// Callback function to display the input field for Proxy Port setting.
function nppp_nginx_cache_proxy_port_callback() {
    $options = get_option('nginx_cache_settings');
    $default_port = 3434;
    echo "<input type='number' id='nginx_cache_preload_proxy_port' name='nginx_cache_settings[nginx_cache_preload_proxy_port]' value='" . esc_attr($options['nginx_cache_preload_proxy_port'] ?? $default_port) . "' class='small-text' />";
}

// Callback function to display the input field for Proxy Host setting (IP field).
function nppp_nginx_cache_proxy_host_callback() {
    $options = get_option('nginx_cache_settings');
    $default_host = '127.0.0.1';
    echo "<input type='text' id='nginx_cache_preload_proxy_host' name='nginx_cache_settings[nginx_cache_preload_proxy_host]' value='" . esc_attr($options['nginx_cache_preload_proxy_host'] ?? $default_host) . "' class='regular-text' />";
}

// Callback function for the nginx_cache_preload_enable_proxy field
function nppp_nginx_cache_enable_proxy_callback() {
    $options = get_option('nginx_cache_settings');
    $enable_proxy_checked = isset($options['nginx_cache_preload_enable_proxy']) && $options['nginx_cache_preload_enable_proxy'] === 'yes' ? 'checked="checked"' : '';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_preload_enable_proxy]" class="nppp-onoffswitch-checkbox-proxy" value="yes" id="nginx_cache_preload_enable_proxy" <?php echo esc_attr($enable_proxy_checked); ?>>
    <label class="nppp-onoffswitch-label-proxy" for="nginx_cache_preload_enable_proxy">
        <span class="nppp-onoffswitch-inner-proxy">
            <span class="nppp-off-proxy"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-proxy"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-proxy"></span>
    </label>
    <?php
}

// Include preload defaults
function nppp_get_preload_defaults(): array {
    static $defaults = null;
    if ( $defaults === null ) {
        $file     = dirname( __FILE__ ) . '/preload-defaults.php';
        $loaded   = is_readable( $file ) ? ( include $file ) : [];
        $defaults = is_array( $loaded ) ? $loaded : [];
    }
    return $defaults;
}

// Get default url reject rules for preload
function nppp_fetch_default_reject_regex(): string {
    return nppp_get_preload_defaults()['reject_regex'] ?? '';
}

// Get default regex for nginx cache key
function nppp_fetch_default_regex_for_cache_key(): string {
    return nppp_get_preload_defaults()['cache_key_regex'] ?? '';
}

// Get default reject file extension rules for preload
function nppp_fetch_default_reject_extension(): string {
    return nppp_get_preload_defaults()['reject_extension'] ?? '';
}

// Callback function for REST API Key
function nppp_nginx_cache_api_key_callback() {
    $options = get_option('nginx_cache_settings');
    $default_api_key = bin2hex(random_bytes(32));
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;
    echo "<input type='text' id='nginx_cache_api_key' name='nginx_cache_settings[nginx_cache_api_key]' value='" . esc_attr($api_key) . "' class='regular-text' />";

    echo "<div style='display: block; align-items: baseline;'>";
    echo "<button type='button' id='api-key-button' class='button nginx-api-key-button'>" . esc_html__( 'Generate API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "</button>";
    echo "<div style='display: flex; align-items: baseline; margin-top: 8px; margin-bottom: 8px;'>";
    echo "<p class='description' id='nppp-api-key' style='margin-right: 10px;'><span class='nppp-tooltip'>" . esc_html__( 'API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy REST API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "<p class='description' id='nppp-purge-url' style='margin-right: 10px;'><span class='nppp-tooltip'>" . esc_html__( 'Purge URL', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy full REST API CURL URL for Purge', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "<p class='description' id='nppp-preload-url'><span class='nppp-tooltip'>" . esc_html__( 'Preload URL', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy full REST API CURL URL for Preload', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "</div>";
    echo "</div>";
}

// Related Pages (single-URL purge only) callback
function nppp_nginx_cache_related_pages_callback() {
    $options = get_option( 'nginx_cache_settings', array() );

    $home = $options['nppp_related_include_home'] ?? 'no';
    $cat  = $options['nppp_related_include_category'] ?? 'no';
    $shop = $options['nppp_related_apply_manual'] ?? 'no';
    $pre  = $options['nppp_related_preload_after_manual'] ?? 'no';

    // UI gating
    $has_related = ($home === 'yes' || $cat === 'yes' || $shop === 'yes');
    if (!$has_related) {
        $pre = 'no';
    }
    ?>
    <fieldset class="nppp-related-pages nppp-ui">

        <div class="nppp-switch">
            <input id="nppp_rel_home" type="checkbox"
                   name="nginx_cache_settings[nppp_related_include_home]"
                   value="yes" <?php checked( 'yes', $home ); ?> />
            <label for="nppp_rel_home">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Always Purge the Homepage', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When any single URL is purged (manual or auto), also purge the homepage.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br>
                </span>
            </label>
        </div>

        <div class="nppp-switch">
            <input id="nppp_rel_apply_manual" type="checkbox"
                   name="nginx_cache_settings[nppp_related_apply_manual]"
                   value="yes" <?php checked( 'yes', $shop ); ?> />
            <label for="nppp_rel_apply_manual">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Always Purge the Shop Page (WooCommerce)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When a product page is purged (manual or auto), also purge the WooCommerce shop page.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br>
                </span>
            </label>
        </div>

        <div class="nppp-switch">
            <input id="nppp_rel_cat" type="checkbox"
                   name="nginx_cache_settings[nppp_related_include_category]"
                   value="yes" <?php checked( 'yes', $cat ); ?> />
            <label for="nppp_rel_cat">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Always Purge Category & Tag (WordPress + WooCommerce)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When a post or product is purged (manual or auto), also purge its category and tag archives.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br>
                </span>
            </label>
        </div>

        <div class="nppp-switch">
            <input id="nppp_rel_preload" type="checkbox"
                   name="nginx_cache_settings[nppp_related_preload_after_manual]"
                   value="yes"
                   <?php
                       checked( 'yes', $pre );
                       echo $has_related ? '' : ' disabled="disabled" aria-disabled="true"';
                   ?> />
            <label for="nppp_rel_preload">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Also preload all included pages above', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc">
                        <?php esc_html_e( 'Manual Single Purge (On-Page or Advanced Tab): turn this ON to also preload related pages above. Auto Purge ON: related pages are preloaded automatically only when Auto Preload is ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </span>
                </span>
            </label>
        </div>

    </fieldset>
    <?php
}

// Percent encode URL Normalization callback
function nppp_nginx_cache_pctnorm_mode_callback() {
    $opts    = get_option('nginx_cache_settings', array());
    $current = isset($opts['nginx_cache_pctnorm_mode']) ? $opts['nginx_cache_pctnorm_mode'] : 'off';

    $cached = get_transient('nppp_safexec_ok');
    if ($cached === false) {
        $safexec_path = nppp_find_safexec_path();
        $safexec_ok = $safexec_path && nppp_is_safexec_usable($safexec_path, false);
        set_transient('nppp_safexec_ok', array('path' => $safexec_path, 'ok' => $safexec_ok), HOUR_IN_SECONDS);
    } else {
        $safexec_path = $cached['path'];
        $safexec_ok   = $cached['ok'];
    }
    $is_disabled = ! $safexec_ok;

    if ($is_disabled && $current !== 'off') {
        $opts['nginx_cache_pctnorm_mode'] = 'off';
        update_option('nginx_cache_settings', $opts);
        $current = 'off';
    }

    // Shown as native tooltip
    if (!$safexec_path) {
        $status_note = esc_html__( 'Unavailable: safexec not found. Install it to enable URL Normalization (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif (!$safexec_ok) {
        // Distinguish: SUID failure vs SHA256 integrity failure
        $p         = @realpath($safexec_path) ?: $safexec_path;
        $stat_info = function_exists('stat') ? @stat($p) : false;
        $suid_ok   = $stat_info
                     && ($stat_info['uid'] === 0)
                     && (($stat_info['mode'] & 04000) === 04000);

        if ($suid_ok) {
            $status_note = esc_html__( 'Unavailable: safexec integrity check failed. Reinstall the correct version (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
        } else {
            $status_note = esc_html__( 'Unavailable: safexec is not SUID/root-owned. Fix permissions (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
        }
    } else {
        $status_note = '';
    }

    $fieldset_aria    = $is_disabled ? ' aria-disabled="true"' : '';
    $fieldset_title   = $is_disabled ? ' title="' . esc_attr( $status_note ) . '"' : '';
    $fieldset_class   = 'nppp-segcontrol nppp-segcontrol--sm nppp-segcontrol--flat' . ( $is_disabled ? ' nppp-is-disabled' : '' );
    ?>
    <fieldset id="nppp-pctnorm"
              class="<?php echo esc_attr($fieldset_class); ?>"
              role="radiogroup"
              <?php echo wp_kses_post( $fieldset_aria . $fieldset_title ); ?>
              <?php if ( $is_disabled ) : ?>
                  data-note="<?php echo esc_attr($status_note); ?>"
              <?php endif; ?>
              aria-label="<?php echo esc_attr_x( 'Percent-encoding case', 'settings field label', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-off"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="off"
               <?php checked( $current, 'off' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-off">
                <?php echo esc_html_x( 'OFF', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-preserve"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="preserve"
               <?php checked( $current, 'preserve' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-preserve">
                <?php echo esc_html_x( 'PRESERVE', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-upper"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="upper"
               <?php checked( $current, 'upper' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-upper">
                <?php echo esc_html_x( 'UPPER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-lower"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="lower"
               <?php checked( $current, 'lower' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-lower">
               <?php echo esc_html_x( 'LOWER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>
        <span class="nppp-segcontrol-thumb nppp-pctnorm__thumb" aria-hidden="true"></span>
    </fieldset>

    <?php if ($is_disabled) : ?>
        <div class="nppp-related-pages" aria-live="polite">
            <div class="nppp-hint" role="note" style="max-width:max-content;">
                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                <?php echo esc_html( $status_note ); ?>
           </div>
        </div>
    <?php endif; ?>

    <p class="description" style="margin-top:6px;"><?php echo esc_html__( 'Fix cache misses caused by mixed-case percent-encoding during cache preloading (on-the-fly).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Different environments may send xx hex in different cases during cache preloading; Nginx treats these as different cache keys, which can cause misses.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Enable this if your URLs contain non-ASCII characters (Japanese/Chinese) or if you see xx-encoded bytes in paths.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Normalizing the hex case during cache preloading makes cache keys consistent and prevents Nginx cache misses after preloading completes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Requirements: safexec installed (see the Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p><br>
    <p class="description">
        <strong><?php echo esc_html_x('OFF', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php // Translators: %xx is a literal percent-encoded byte pattern (e.g., %2F). Keep it as-is. ?>
          <?php echo esc_html__( 'Use when your URLs are ASCII-only and you never see %xx bytes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <p class="description">
        <strong><?php echo esc_html_x('PRESERVE', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php echo esc_html__( 'Normalize percent-encoding without changing hex case (keeps original upper/lower). Good default when encoded bytes appear but case consistency is unknown.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <p class="description">
        <strong><?php echo esc_html_x('UPPER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php // Translators: %xx is a literal percent-encoded byte pattern (e.g., %2F). Keep it as-is. ?>
          <?php echo esc_html__( 'Force %xx hex to uppercase during preloading to stay consistent with browser behaviour.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <p class="description">
        <strong><?php echo esc_html_x('LOWER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php // Translators: %xx is a literal percent-encoded byte pattern (e.g., %2F). Keep it as-is. ?>
          <?php echo esc_html__( 'Force %xx hex to lowercase during preloading to stay consistent with browser behaviour.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <?php
}

// Callback function for REST API
function nppp_nginx_cache_api_callback() {
    $options = get_option('nginx_cache_settings');
    $api_checked = isset($options['nginx_cache_api']) && $options['nginx_cache_api'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_api]" class="nppp-onoffswitch-checkbox-api" value='yes' id="nginx_cache_api" <?php echo esc_attr($api_checked); ?>>
    <label class="nppp-onoffswitch-label-api" for="nginx_cache_api">
        <span class="nppp-onoffswitch-inner-api">
            <span class="nppp-off-api"><?php echo esc_html__( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-api"><?php echo esc_html__( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-api"></span>
    </label>
    <?php
}

// Callback function for HTTP Purge
function nppp_http_purge_enabled_callback(): void {
    $options      = get_option( 'nginx_cache_settings', [] );
    $is_checked   = isset( $options['nppp_http_purge_enabled'] ) && $options['nppp_http_purge_enabled'] === 'yes';
    $checked      = $is_checked ? 'checked="checked"' : '';

    ?>
    <input type="checkbox"
           name="nginx_cache_settings[nppp_http_purge_enabled]"
           class="nppp-onoffswitch-checkbox-httppurge"
           value="yes"
           id="nppp_http_purge_enabled"
           <?php echo esc_attr( $checked ); ?>>
    <label class="nppp-onoffswitch-label-httppurge" for="nppp_http_purge_enabled">
        <span class="nppp-onoffswitch-inner-httppurge">
            <span class="nppp-off-httppurge"><?php esc_html_e( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-httppurge"><?php esc_html_e( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-httppurge"></span>
    </label>
    <?php
}

// Callback function for HTTP Purge Suffix
function nppp_http_purge_suffix_callback(): void {
    $options = get_option( 'nginx_cache_settings', [] );
    echo "<input type='text' id='nppp_http_purge_suffix' name='nginx_cache_settings[nppp_http_purge_suffix]' value='" . esc_attr( $options['nppp_http_purge_suffix'] ?? 'purge' ) . "' class='regular-text' placeholder='purge' />";
}

// Callback function for HTTP Purge Custom URL
function nppp_http_purge_custom_url_callback(): void {
    $options = get_option( 'nginx_cache_settings', [] );
    echo "<input type='text' id='nppp_http_purge_custom_url' name='nginx_cache_settings[nppp_http_purge_custom_url]' value='" . esc_attr( $options['nppp_http_purge_custom_url'] ?? '' ) . "' class='regular-text' placeholder='https://docker/purge' />";
}

// Log error messages
function nppp_log_error_message($message) {
    $log_message = esc_html($message);
    $log_file_path = NGINX_CACHE_LOG_FILE;

    // Create log file if not exist
    nppp_perform_file_operation($log_file_path, 'create');

    // Check if the log path is valid and writable
    if (!empty($log_file_path)) {
        nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $log_message);
    }
}

// Prevent command injections
function nppp_single_line(string $s): string {
    $s = str_replace("\0", '', $s);
    $s = str_replace(["\r","\n","\t"], ' ', $s);
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x80-\x9F]/', '', $s);
    $s = trim($s);
    if (strlen($s) > 4000) $s = substr($s, 0, 4000);
    return $s;
}
function nppp_sanitize_reject_regex(string $rx): string {
    return nppp_single_line($rx);
}
function nppp_sanitize_reject_extension_globs(string $s): string {
    $s = nppp_single_line($s);
    $parts = preg_split('/[,\s]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $p = strtolower(trim($p));
        if (preg_match('/^(?:\*\.)?[a-z0-9]+(?:\.[a-z0-9]+)*$/', $p) || preg_match('/^\.[a-z0-9]+(?:\.[a-z0-9]+)*$/', $p)) {
            if ($p[0] === '.')      $p = '*'.$p;
            elseif ($p[0] !== '*')  $p = '*.' . $p;
            $out[$p] = true;
        }
    }
    $out = array_slice(array_keys($out), 0, 200);
    return implode(',', $out);
}
function nppp_forbidden_shell_bytes_reason(string $s): ?string {
    if (strpos($s, "\0") !== false) {
        return __('ERROR OPTION: NUL byte is not allowed. (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    if (preg_match('/[\r\n]/', $s)) {
        return __('ERROR OPTION: Newline characters are not allowed. (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    if (preg_match('/[\x00-\x08\x0B-\x1F\x7F\x80-\x9F]/', $s)) {
        return __('ERROR OPTION: Control characters are not allowed. (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    if (strlen($s) > 4000) {
        return __('ERROR OPTION: Value too long (max 4000 chars). (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    return null;
}

// Sanitize + validate proxy host input.
function nppp_is_valid_hostname(string $h): bool {
    if ($h === '' || strlen($h) > 253) return false;
    $labels = explode('.', $h);
    foreach ($labels as $lab) {
        $len = strlen($lab);
        if ($len === 0 || $len > 63) return false;
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $lab)) return false;
    }
    return true;
}
function nppp_idn_to_ascii_host(string $host): ?string {
    // If non-ASCII chars present, try converting.
    if (preg_match('/[^\x00-\x7F]/', $host)) {
        if (function_exists('idn_to_ascii')) {
            if (defined('INTL_IDNA_VARIANT_UTS46')) {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            } else {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT);
            }
            if ($ascii === false || $ascii === null) {
                return null;
            }
            return $ascii;
        }
        return null;
    }
    return $host;
}
function nppp_is_valid_hostname_with_reason(string $h, ?string &$reason = null): bool {
    if ($h === '') { $reason = 'empty'; return false; }
    if (strlen($h) > 253) { $reason = 'too_long'; return false; }
    $labels = explode('.', $h);
    foreach ($labels as $lab) {
        $len = strlen($lab);
        if ($len === 0) { $reason = 'empty_label'; return false; }
        if ($len > 63) { $reason = 'label_too_long'; return false; }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $lab)) {
            $reason = 'bad_chars'; return false;
        }
    }
    return true;
}
function nppp_is_ipv4_like_hostname(string $h): bool {
    return (bool) preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $h);
}
function nppp_sanitize_validate_proxy_host(string $raw, ?string &$err = null, ?string &$notice = null): ?string {
    $s = nppp_single_line($raw);

    // Strip scheme if pasted like http://host:port
    $s = preg_replace('#^[a-z][a-z0-9+\-.]*://#i', '', $s);
    $s = trim($s);

    // Reject userinfo, path, query, fragments, spaces
    if (preg_match('/[@\/?#\s]/', $s)) {
        $err = __('ERROR OPTION: Proxy Host must not include credentials, path, query, fragments, or spaces.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // Trailing dot (FQDN.) -> normalize
    $s = rtrim($s, '.');

    // [IPv6] or [IPv6]:port — allow;
    if (preg_match('/^\[([0-9A-Fa-f:.%]+)\](?::(\d{1,5}))?$/', $s, $m)) {
        $ip6_raw = $m[1];
        $port    = $m[2] ?? null;

        $ip6_plain = preg_replace('/%.*/', '', $ip6_raw);
        if (!filter_var($ip6_plain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $err = __('ERROR OPTION: Invalid IPv6 address.', 'fastcgi-cache-purge-and-preload-nginx');
            return null;
        }
        if ($port !== null) {
            $notice = __('NOTICE: Port ignored in Proxy Host. Use the Proxy Port field.', 'fastcgi-cache-purge-and-preload-nginx');
        }
        return '[' . $ip6_raw . ']';
    }

    // host:port (non-IPv6) — allow but ignore port, nudge user
    if (preg_match('/^([^:]+):(\d{1,5})$/', $s, $m) && strpos($m[1], ':') === false) {
        $s = $m[1];
        $notice = __('NOTICE: Port ignored in Proxy Host. Use the Proxy Port field.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Any remaining ":" means it's trying to be IPv6 but failed
    if (strpos($s, ':') !== false) {
        $plain = preg_replace('/%.*/', '', $s);
        if (filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '[' . $s . ']';
        }
        $err = __('ERROR OPTION: Unexpected ":" in host. Use [IPv6] or a valid hostname/IPv4; do not include ports or paths here.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // IPv4?
    if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $s;
    }

    // Looks like IPv4 but invalid?
    if (nppp_is_ipv4_like_hostname($s)) {
        $err = __('ERROR OPTION: Value looks like an IPv4 address but is invalid.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // Normalize case and accept IDNs via Punycode
    $host_input = strtolower($s);
    $host_ascii = nppp_idn_to_ascii_host($host_input);
    if ($host_ascii === null) {
        $err = __('ERROR OPTION: Invalid internationalized domain name.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // Validate hostname with specific reasons
    $why = null;
    if (nppp_is_valid_hostname_with_reason($host_ascii, $why)) {
        return $host_ascii;
    }

    switch ($why) {
        case 'too_long':
            $err = __('ERROR OPTION: Hostname too long (max 253 chars).', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        case 'label_too_long':
            $err = __('ERROR OPTION: A hostname label exceeds 63 characters.', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        case 'empty_label':
            $err = __('ERROR OPTION: Hostname contains empty labels (e.g., consecutive dots).', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        case 'bad_chars':
            $err = __('ERROR OPTION: Hostname contains invalid characters. Use letters, digits, and hyphens; labels must start/end alphanumeric.', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        default:
            $err = __('ERROR OPTION: Please enter a valid IP address or hostname for the Proxy Host.', 'fastcgi-cache-purge-and-preload-nginx');
    }
    return null;
}

// Sanitize inputs
function nppp_nginx_cache_settings_sanitize($input) {
    $sanitized_input = array();

    // Ensure input is an array
    if (!is_array($input)) {
        return $sanitized_input;
    }

    // Sanitize and validate cache path
    if (!empty($input['nginx_cache_path'])) {
        // Validate the path
        $validation_result = nppp_validate_path($input['nginx_cache_path'], false);

        // Check the validation result
        if ($validation_result === true) {
            $sanitized_input['nginx_cache_path'] = sanitize_text_field($input['nginx_cache_path']);
        } else {
            // Handle different validation outcomes
            switch ($validation_result) {
                case 'critical_path':
                    $error_message = __('ERROR PATH: The specified Nginx Cache Directory is either a critical system directory or a top-level directory and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
                    break;
                case 'directory_not_exist_or_readable':
                    $error_message = __('ERROR PATH: The specified Nginx Cache Directory does not exist. Please verify the Nginx Cache Directory.', 'fastcgi-cache-purge-and-preload-nginx');
                    break;
                default:
                    $error_message = __('ERROR PATH: An invalid path was provided for the Nginx Cache Directory. Please provide a valid directory path.', 'fastcgi-cache-purge-and-preload-nginx');
            }

            // Add settings error
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid_path',
                $error_message,
                'error'
            );

            // Log the error message
            nppp_log_error_message($error_message);
        }
    } else {
        $error_message = __('ERROR PATH: Nginx Cache Directory cannot be empty. Please provide a valid directory path.', 'fastcgi-cache-purge-and-preload-nginx');
        add_settings_error('nppp_nginx_cache_settings_group', 'empty_path', $error_message, 'error');
        nppp_log_error_message($error_message);
    }

    // Sanitize and validate email
    if (!empty($input['nginx_cache_email'])) {
        // Validate email format
        $email = sanitize_email($input['nginx_cache_email']);
        if (is_email($email)) {
            $sanitized_input['nginx_cache_email'] = $email;
        } else {
            // Email is not valid, add error message
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-email',
                __('ERROR OPTION: Please enter a valid email address.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Please enter a valid email address.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize and validate CPU limit
    if (!empty($input['nginx_cache_cpu_limit'])) {
        // Check if the input is numeric to prevent non-numeric strings from passing through
        if (is_numeric($input['nginx_cache_cpu_limit'])) {
            // Convert to integer
            $cpu_limit = intval($input['nginx_cache_cpu_limit']);
            // Validate range
            if ($cpu_limit >= 10 && $cpu_limit <= 100) {
                $sanitized_input['nginx_cache_cpu_limit'] = $cpu_limit;
            } else {
                // CPU limit is not within range, add error message
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-cpu-limit',
                    __('Please enter a CPU limit between 10 and 100.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a CPU limit between 10 and 100.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-cpu-limit-format',
                __('CPU limit must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: CPU limit must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize and validate Per Request Wait Time
    if (isset($input['nginx_cache_wait_request'])) {
        // Check if the input is numeric to prevent non-numeric strings from passing through
        if (is_numeric($input['nginx_cache_wait_request'])) {
            // Convert to integer
            $wait_time = intval($input['nginx_cache_wait_request']);
            // Validate range
            if ($wait_time >= 0 && $wait_time <= 60) {
                $sanitized_input['nginx_cache_wait_request'] = $wait_time;
            } else {
                // Wait Time is not within range, add error message
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-wait-time',
                    __('Please enter a Per Request Wait Time between 0 and 60 seconds.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a per request wait time between 0 and 60 seconds.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-wait-time-format',
                __('Wait time must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Wait time must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize and validate PHP Response Timeout
    if (isset($input['nginx_cache_read_timeout'])) {
        if (is_numeric($input['nginx_cache_read_timeout'])) {
            $read_timeout = intval($input['nginx_cache_read_timeout']);
            if ($read_timeout >= 10 && $read_timeout <= 300) {
                $sanitized_input['nginx_cache_read_timeout'] = $read_timeout;
            } else {
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-read-timeout',
                    __('Please enter a PHP Response Timeout between 10 and 300 seconds.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );
                nppp_log_error_message(__('ERROR OPTION: Please enter a PHP response timeout between 10 and 300 seconds.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-read-timeout-format',
                __('PHP Response Timeout must be a numeric value in seconds.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );
            nppp_log_error_message(__('ERROR OPTION: PHP response timeout must be a numeric value in seconds.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize Reject Regex field
    if (!empty($input['nginx_cache_reject_regex'])) {
        $raw = $input['nginx_cache_reject_regex'];
        if ($reason = nppp_forbidden_shell_bytes_reason($raw)) {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-reject-regex',
                $reason,
                'error'
            );
            nppp_log_error_message($reason);
        } else {
            $sanitized_input['nginx_cache_reject_regex'] = nppp_sanitize_reject_regex($raw);
        }
    }

    // Sanitize & validate custom cache key regex
    if (!empty($input['nginx_cache_key_custom_regex'])) {
        // Decode the base64-encoded regex if it's being passed encoded
        $decoded_regex = base64_decode($input['nginx_cache_key_custom_regex'], true);
        if ($decoded_regex !== false) {
            // Retrieve the decoded regex
            $regex = $decoded_regex;
        } else {
            // If decoding fails, use the input directly
            $regex = $input['nginx_cache_key_custom_regex'];
        }

        // ####################################################################
        // Validate & Sanitize the regex
        // Limit catastrophic backtracking, greedy quantifiers inside lookaheads
        // excessively long regex patterns to prevent ReDoS attacks
        // ####################################################################

        // Validate the regex
        if (@preg_match($regex, "") === false) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex is invalid. Check the syntax and test it before use.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Check for excessive lookaheads (limit to 3)
        $lookahead_count = preg_match_all('/(\(\?=.*\))/i', $regex);
        if ($lookahead_count > 3) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex contains more than 3 lookaheads and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Check for greedy quantifiers inside lookaheads
        if (preg_match('/\(\?=.*\.\*\)/', $regex)) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex contains a greedy quantifier inside a lookahead and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Allow only a single ".*" in the regex
        $greedy_count = preg_match_all('/\.\*/', $regex);
        if ($greedy_count > 1) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex contains more than one ".*" quantifier and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Check for excessively long regex patterns (limit length to 300 characters)
        if (strlen($regex) > 300) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex exceeds the allowed length of 300 characters.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // If an error message was set, trigger the error and log it
        if (isset($error_message_regex)) {
            // Add settings error
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid_regex',
                $error_message_regex,
                'error'
            );
            // Log the error message
            nppp_log_error_message($error_message_regex);
        } else {
            // Sanitization & validation the regex completed, safely store regex in db
            $sanitized_input['nginx_cache_key_custom_regex'] = base64_encode($regex);
        }
    }

    // Sanitize Reject extension field
    if (!empty($input['nginx_cache_reject_extension'])) {
        $raw = nppp_single_line($input['nginx_cache_reject_extension']);

        // Tokenize by comma/whitespace only
        $tokens = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        $bad = [];
        foreach ($tokens as $tok) {
            // Only forms like: *.css  | .css  | css  | css.min.js
            $ok = preg_match('/^(?:\*\.)?[a-z0-9]+(?:\.[a-z0-9]+)*$/i', $tok)
                || preg_match('/^\.[a-z0-9]+(?:\.[a-z0-9]+)*$/i', $tok);
            if (!$ok) $bad[] = $tok;
        }

        if ($bad) {
            $preview = implode(', ', array_slice($bad, 0, 3));
            $msg = sprintf(
                // Translators: %s: a short, comma-separated preview (max 3) of invalid extension patterns.
                __('ERROR OPTION: Invalid extension pattern(s): %s. Allowed examples: *.css, .css, css', 'fastcgi-cache-purge-and-preload-nginx'),
                esc_html($preview) . (count($bad) > 3 ? '…' : '')
            );
            add_settings_error('nppp_nginx_cache_settings_group', 'invalid-reject-ext', $msg, 'error');
            nppp_log_error_message($msg);
        } else {
            $sanitized_input['nginx_cache_reject_extension'] = nppp_sanitize_reject_extension_globs($raw);
        }
    }

    // Sanitize Send Mail, Auto Preload, Auto Preload Mobile, Auto Purge, Cache Schedule, REST API, Opt-in, Related Pages
    $sanitized_input['nginx_cache_send_mail']              = isset($input['nginx_cache_send_mail'])               && $input['nginx_cache_send_mail'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_auto_preload']           = isset($input['nginx_cache_auto_preload'])            && $input['nginx_cache_auto_preload'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_auto_preload_mobile']    = isset($input['nginx_cache_auto_preload_mobile'])     && $input['nginx_cache_auto_preload_mobile'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_watchdog']               = isset($input['nginx_cache_watchdog'])                && $input['nginx_cache_watchdog'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_purge_on_update']        = isset($input['nginx_cache_purge_on_update'])         && $input['nginx_cache_purge_on_update'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nppp_cloudflare_apo_sync']           = isset($input['nppp_cloudflare_apo_sync'])            && $input['nppp_cloudflare_apo_sync'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nppp_redis_cache_sync']              = isset($input['nppp_redis_cache_sync'])               && $input['nppp_redis_cache_sync'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_schedule']               = isset($input['nginx_cache_schedule'])                && $input['nginx_cache_schedule'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_api']                    = isset($input['nginx_cache_api'])                     && $input['nginx_cache_api'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nppp_related_include_home']          = (isset($input['nppp_related_include_home'])          && $input['nppp_related_include_home'] === 'yes') ? 'yes' : 'no';
    $sanitized_input['nppp_related_include_category']      = (isset($input['nppp_related_include_category'])      && $input['nppp_related_include_category'] === 'yes') ? 'yes' : 'no';
    $sanitized_input['nppp_related_apply_manual']          = (isset($input['nppp_related_apply_manual'])          && $input['nppp_related_apply_manual'] === 'yes') ? 'yes' : 'no';
    $sanitized_input['nppp_related_preload_after_manual']  = (isset($input['nppp_related_preload_after_manual'])  && $input['nppp_related_preload_after_manual'] === 'yes') ? 'yes' : 'no';

    // HTTP Purge
    // Toggle
    $sanitized_input['nppp_http_purge_enabled'] =
        ( isset( $input['nppp_http_purge_enabled'] ) && $input['nppp_http_purge_enabled'] === 'yes' )
        ? 'yes' : 'no';

    // URL suffix
    $raw_suffix = isset( $input['nppp_http_purge_suffix'] )
                  ? trim( sanitize_text_field( $input['nppp_http_purge_suffix'] ), '/' )
                  : '';
    if ( $raw_suffix === '' ) {
        // Empty = user cleared the field. Reset to default and tell them.
        $sanitized_input['nppp_http_purge_suffix'] = 'purge';
        add_settings_error(
            'nppp_nginx_cache_settings_group',
            'nppp-http-purge-suffix',
            __( 'ERROR OPTION: HTTP Purge Suffix cannot be empty. Reset to "purge".', 'fastcgi-cache-purge-and-preload-nginx' ),
            'error'
        );
    } elseif ( preg_match( '/^[a-zA-Z0-9_\-]+$/', $raw_suffix ) ) {
        $sanitized_input['nppp_http_purge_suffix'] = $raw_suffix;
    } else {
        $sanitized_input['nppp_http_purge_suffix'] = 'purge';
        add_settings_error(
            'nppp_nginx_cache_settings_group',
            'nppp-http-purge-suffix',
            __( 'ERROR OPTION: HTTP Purge Suffix must contain only letters, numbers, hyphens, or underscores. Reset to "purge".', 'fastcgi-cache-purge-and-preload-nginx' ),
            'error'
        );
    }

    // Custom base URL
    $raw_custom = isset( $input['nppp_http_purge_custom_url'] )
                  ? untrailingslashit( esc_url_raw( trim( $input['nppp_http_purge_custom_url'] ) ) )
                  : '';
    if ( $raw_custom !== '' ) {
        $scheme = strtolower( (string) wp_parse_url( $raw_custom, PHP_URL_SCHEME ) );
        if ( in_array( $scheme, [ 'http', 'https' ], true ) && filter_var( $raw_custom, FILTER_VALIDATE_URL ) ) {
            $sanitized_input['nppp_http_purge_custom_url'] = $raw_custom;
        } else {
            $sanitized_input['nppp_http_purge_custom_url'] = '';
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'nppp-http-purge-custom-url',
                __( 'ERROR OPTION: HTTP Purge Custom Base URL must be a valid http:// or https:// URL. It has been cleared.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'error'
            );
        }
    } else {
        $sanitized_input['nppp_http_purge_custom_url'] = '';
    }

    // Sanitize pctnorm
    if (!empty($input['nginx_cache_pctnorm_mode']) ) {
        $mode = sanitize_text_field($input['nginx_cache_pctnorm_mode']);
        $allowed = array('off','upper','lower','preserve');
        $sanitized_input['nginx_cache_pctnorm_mode'] = in_array($mode, $allowed, true) ? $mode : 'off';
    }

    // Sanitize and validate cache limit rate
    if (!empty($input['nginx_cache_limit_rate'])) {
        // Check if the input is numeric to prevent non-numeric strings from passing through
        if (is_numeric($input['nginx_cache_limit_rate'])) {
            // Convert to integer
            $limit_rate = intval($input['nginx_cache_limit_rate']);
            // Validate range: 1 KB to 102400 KB (100 MB)
            if ($limit_rate >= 1 && $limit_rate <= 102400) {
                $sanitized_input['nginx_cache_limit_rate'] = $limit_rate;
            } else {
                // Limit rate is not within range, add error message
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-limit-rate',
                    __('Please enter a limit rate between 1 KB/sec and 100 MB/sec.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a limit rate between 1 KB/sec and 100 MB/sec.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-limit-rate-format',
                __('Limit rate must be a numeric value in KB/sec.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Limit rate must be a numeric value in KB/sec.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize REST API Key
    if (!empty($input['nginx_cache_api_key'])) {
        $api_key = $input['nginx_cache_api_key'];

        // Validate if the input is a 32-character hexadecimal string
        if (preg_match('/^[0-9a-fA-F]{64}$/', $api_key)) {
            // Input is a valid 32-character hexadecimal string, sanitize and store it
            $sanitized_input['nginx_cache_api_key'] = $api_key;
        } else {
            // API key is not valid, add error message
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-api-key',
                __('ERROR API KEY: Please enter a valid 64-character hexadecimal string for the API key.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR API KEY: Please enter a valid 64-character hexadecimal string for the API key.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize Enable Proxy
    $sanitized_input['nginx_cache_preload_enable_proxy'] = isset($input['nginx_cache_preload_enable_proxy']) && $input['nginx_cache_preload_enable_proxy'] === 'yes' ? 'yes' : 'no';

    // Sanitize and validate Proxy Host
    if (!empty($input['nginx_cache_preload_proxy_host'])) {
        $notice = $err = null;
        $host = nppp_sanitize_validate_proxy_host($input['nginx_cache_preload_proxy_host'], $err, $notice);

        if ($err) {
            add_settings_error('nppp_nginx_cache_settings_group','invalid-proxy-host',$err,'error');
            nppp_log_error_message($err);
        } else {
            if ($notice) {
                add_settings_error('nppp_nginx_cache_settings_group','proxy-host-port-ignored',$notice,'notice');
                nppp_log_error_message($notice);
            }
            $sanitized_input['nginx_cache_preload_proxy_host'] = $host;
        }
    }

    // Sanitize and validate Proxy Port
    if (!empty($input['nginx_cache_preload_proxy_port'])) {
        // Check if numeric
        if (is_numeric($input['nginx_cache_preload_proxy_port'])) {
            $proxy_port = intval($input['nginx_cache_preload_proxy_port']);
            // Validate valid port range
            if ($proxy_port >= 1 && $proxy_port <= 65535) {
                $sanitized_input['nginx_cache_preload_proxy_port'] = $proxy_port;
            } else {
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-proxy-port',
                    __('ERROR OPTION: Please enter a valid Proxy Port between 1 and 65535.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a valid Proxy Port between 1 and 65535.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-proxy-port-format',
                __('ERROR OPTION: Proxy Port must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Proxy Port must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    return $sanitized_input;
}

// Validate the cache path to prevent bad inputs
function nppp_validate_path($path, $nppp_is_premium_purge = false) {
    // Whitelist the default placeholder — directory mode only.
    if (!$nppp_is_premium_purge && $path === '/dev/shm/change-me-now') {
        return true;
    }

    // 1. Format check — rejects malformed input before any string ops.
    $pattern = '/^\/(?:[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)*)\/?$/';
    if (!preg_match($pattern, $path)) {
        return 'critical_path';
    }

    // 2. Dotdot check — the regex character class permits dots so '..'
    //    passes the format check. Block traversal sequences explicitly.
    if (strpos($path, '..') !== false) {
        return 'critical_path';
    }

    // 3. Normalise — strip trailing slash before prefix comparisons.
    $normalised = rtrim($path, '/');

    // 4. Allowlist of safe cache roots.
    $allowed_roots = ['/dev/shm/', '/tmp/', '/var/', '/cache/'];

    $allowed = false;
    foreach ($allowed_roots as $root) {
        if (str_starts_with($normalised, $root)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        return 'critical_path';
    }

    // 5. Blocklist of dangerous subtrees within the allowed roots.
    //    Exact match + trailing slash prevents false positives:
    $blocked_subdirs = [
        '/var/log',
        '/var/spool',
        '/var/lib',
        '/var/www',
        '/var/mail',
        '/var/lock',
        '/var/backups',
        '/var/snap',
    ];

    foreach ($blocked_subdirs as $blocked) {
        if ($normalised === $blocked ||
            str_starts_with($normalised, $blocked . '/')) {
            return 'critical_path';
        }
    }

    // 5b. Block /var/cache root only — subdirectories are allowed.
    //     Using /var/cache directly would wipe all system cache data.
    if ($normalised === '/var/cache' || $normalised === '/var/run' || $normalised === '/cache') {
        return 'critical_path';
    }

    // 6. Existence check — also required before realpath() is safe to call,
    //    since realpath() returns false for non-existent paths.
    if ($nppp_is_premium_purge) {
        if (!is_file($path)) {
            return 'file_not_found_or_not_readable';
        }
    } else {
        if (!is_dir($path)) {
            return 'directory_not_exist_or_readable';
        }
    }

    // 7. Symlink resolution — resolve the real path and re-run allowlist +
    //    blocklist on the destination.
    //    all checks above, since is_dir() follows symlinks silently.
    $resolved = realpath($path);
    if ($resolved === false) {
        return $nppp_is_premium_purge
            ? 'file_not_found_or_not_readable'
            : 'directory_not_exist_or_readable';
    }

    $resolved_normalised = rtrim($resolved, '/');

    $resolved_allowed = false;
    foreach ($allowed_roots as $root) {
        if (str_starts_with($resolved_normalised, $root)) {
            $resolved_allowed = true;
            break;
        }
    }

    if (!$resolved_allowed) {
        return 'critical_path';
    }

    foreach ($blocked_subdirs as $blocked) {
        if ($resolved_normalised === $blocked ||
            str_starts_with($resolved_normalised, $blocked . '/')) {
            return 'critical_path';
        }
    }

    // 5b repeated on resolved path — catches symlinks pointing at /var/cache root
    if ($resolved_normalised === '/var/cache' || $resolved_normalised === '/var/run' || $resolved_normalised === '/cache') {
        return 'critical_path';
    }

    return true;
}

// Function to reset plugin settings on deactivation
function nppp_reset_plugin_settings_on_deactivation() {
    // Always clear preload related cron hooks unconditionally.
    wp_clear_scheduled_hook('npp_cache_preload_status_event');
    wp_clear_scheduled_hook('npp_cache_preload_event');

    // Kill the watchdog.
    if (function_exists('nppp_kill_preload_watcher')) {
        nppp_kill_preload_watcher();
    }

    // Clear all plugin transients silently — server state may change
    if (function_exists('nppp_clear_plugin_cache')) {
        nppp_clear_plugin_cache(true);
    }

    // Preload runs as a detached nohup process that survives deactivation.
    // Terminate it gracefully so it does not keep crawling after the plugin
    // is gone, then clean up the stale PID file.
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem !== false && $wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            // Try SIGTERM first (graceful).
            if (function_exists('posix_kill') && defined('SIGTERM')) {
                posix_kill($pid, SIGTERM);
                usleep(300000);
            }

            // Fall back to SIGKILL if still alive.
            if (nppp_is_process_alive($pid)) {
                $kill_path = trim((string) shell_exec('command -v kill'));
                if (!empty($kill_path)) {
                    shell_exec(escapeshellarg($kill_path) . ' -9 ' . (int) $pid);
                    usleep(300000);
                }
            }
        }

        // Remove PID file regardless of whether the process was alive,
        // so a stale file from a previously crashed preload is also cleaned up.
        nppp_perform_file_operation($PIDFILE, 'delete');
    }
}

// Automatically update the default options when the plugin is activated or reactivated
function nppp_defaults_on_plugin_activation() {
    // Clear all plugin transients on activation/reactivation.
    // Ensures no stale cached state from a previous activation
    if (function_exists('nppp_clear_plugin_cache')) {
        nppp_clear_plugin_cache(true);
    }

    $new_api_key = bin2hex(random_bytes(32));

    // Define default options
    $default_options = array(
        'nginx_cache_path'                  => '/dev/shm/change-me-now',
        'nginx_cache_email'                 => 'your-email@example.com',
        'nginx_cache_cpu_limit'             => 100,
        'nginx_cache_reject_extension'      => nppp_fetch_default_reject_extension(),
        'nginx_cache_reject_regex'          => nppp_fetch_default_reject_regex(),
        'nginx_cache_key_custom_regex'      => base64_encode(nppp_fetch_default_regex_for_cache_key()),
        'nginx_cache_wait_request'          => 0,
        'nginx_cache_read_timeout'          => 60,
        'nginx_cache_limit_rate'            => 5120,
        'nginx_cache_api_key'               => $new_api_key,
        'nginx_cache_preload_proxy_host'    => '127.0.0.1',
        'nginx_cache_preload_proxy_port'    => 3434,
        'nppp_related_include_home'         => 'no',
        'nppp_related_include_category'     => 'no',
        'nppp_related_apply_manual'         => 'no',
        'nppp_related_preload_after_manual' => 'no',
        'nppp_cloudflare_apo_sync'          => 'no',
        'nppp_redis_cache_sync'             => 'no',
        'nginx_cache_purge_on_update'       => 'no',
        'nginx_cache_auto_preload'          => 'no',
        'nginx_cache_auto_preload_mobile'   => 'no',
        'nginx_cache_watchdog'              => 'no',
        'nginx_cache_send_mail'             => 'no',
        'nginx_cache_preload_enable_proxy'  => 'no',
        'nginx_cache_schedule'              => 'no',
        'nginx_cache_pctnorm_mode'          => 'off',
        'nppp_http_purge_enabled'           => 'no',
        'nppp_http_purge_suffix'            => 'purge',
        'nppp_http_purge_custom_url'        => '',
    );

    // Retrieve existing options (if any)
    $existing_options = get_option('nginx_cache_settings', array());

    // Merge existing options with default options
    // Existing options overwrite default options
    $updated_options = array_merge($default_options, $existing_options);

    // Update options in the database
    update_option('nginx_cache_settings', $updated_options);

    // Save the current version using the compile-time constant
    update_option('nppp_plugin_version', defined('NPPP_PLUGIN_VERSION') ? NPPP_PLUGIN_VERSION : '');

    // Create the log file if it doesn't exist
    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (!file_exists($log_file_path)) {
        $log_file_created = nppp_perform_file_operation($log_file_path, 'create');
        if (!$log_file_created) {
            // Log file creation failed, handle error accordingly
            nppp_custom_error_log('Failed to create log file: ' . $log_file_path);
        }
    }
}
