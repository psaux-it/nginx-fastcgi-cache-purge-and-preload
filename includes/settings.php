<?php
/**
 * Settings page for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains settings page functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.2
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
    add_settings_field('nginx_cache_cpu_limit', 'CPU Usage Limit for Cache Preloading (0-100)', 'nppp_nginx_cache_cpu_limit_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
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
    add_settings_field('nginx_cache_wait_request', 'Per Request Wait Time', 'nppp_nginx_cache_wait_request_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_tracking_opt_in', 'Enable Tracking', 'nppp_nginx_cache_tracking_opt_in_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_key_custom_regex', 'Enable Custom regex', 'nppp_nginx_cache_key_custom_regex_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_auto_preload_mobile', 'Auto Preload Mobile', 'nppp_nginx_cache_auto_preload_mobile_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_enable_proxy', 'Enable Proxy', 'nppp_nginx_cache_enable_proxy_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_host', 'Proxy Host', 'nppp_nginx_cache_proxy_host_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_port', 'Proxy Port', 'nppp_nginx_cache_proxy_port_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
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

// Displays the NPP Nginx Cache Settings page in the WordPress admin dashboard
function nppp_nginx_cache_settings_page() {
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
    <div class="wrap">
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
        <div class="nppp-header-content">
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
                                        <?php echo esc_html__( 'Use directories under', 'fastcgi-cache-purge-and-preload-nginx' ); ?> <code>/dev/</code> | <code>/tmp/</code> | <code>/var/</code>
                                    </p>
                                    <p>
                                        <strong><?php echo esc_html__( 'For persistent disk:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Use directories under', 'fastcgi-cache-purge-and-preload-nginx' ); ?> <code>/opt/</code>
                                    </p>
                                    <p>
                                        <strong><?php echo esc_html__( 'Important:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <?php echo esc_html__( 'Paths must be at least one level deeper (e.g. /var/cache).', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                        <br class="line-break">
                                        <?php echo esc_html__( 'Critical system paths are prohibited in default to ensure accuracy to avoid unintended deletions.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
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
                                <p class="description"><?php echo esc_html__( 'This feature ensures automatic cache purging for both individual posts/pages and', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'the entire site whenever specific changes are made, ensuring up-to-date content.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'It also supports auto preloading of the cache after purging for enhanced performance.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <div class="cache-paths-info">
                                    <h4><?php echo esc_html__( 'The entire cache is automatically purged when:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h4>
                                    <p>
                                        <strong><?php echo esc_html__( 'THEME', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong> (<?php echo esc_html__( 'active', 'fastcgi-cache-purge-and-preload-nginx' ); ?>) <?php echo esc_html__( 'is switched or updated.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <strong><?php echo esc_html__( 'PLUGIN', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong> <?php echo esc_html__( 'is activated, updated, or deactivated.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <?php echo esc_html__( 'Compatible caching plugins trigger a cache purge.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                    <br>
                                    <h4><?php echo esc_html__( 'The cache for a POST/PAGE is automatically purged when:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h4>
                                    <p>
                                        <?php echo esc_html__( 'Changes are made to the content of the POST/PAGE.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                                        <?php echo esc_html__( 'A new COMMENT is approved or its status is changed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p><br>
                                    <p>
                                        <?php echo esc_html__( 'If Auto Preload is enabled, the cache for the single POST/PAGE or the entire cache will be automatically preloaded after the cache is purged.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                </div>
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
                                <p class="description"><?php echo esc_html__( 'Enable this feature to route preload requests through a local proxy mitmproxy.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'This helps unify percent-encoding (uppercase vs lowercase) in URLs, matching browser behavior', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Without this feature, Nginx may generate separate cache keys for uppercase/lowercase percent-encoded URLs, leading to cache misses.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Only use this if you encounter such a problem. Please see the Help tab for instructions.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
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
                                <span class="dashicons dashicons-randomize"></span>
                                <?php echo esc_html__('Enable Proxy', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            </th>
                            <td>
                                <div class="nppp-onoffswitch-proxy">
                                    <?php nppp_nginx_cache_enable_proxy_callback(); ?>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Enable this feature to route preload requests through a local proxy with mitmproxy.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'If your site uses URLs with non-ASCII characters (e.g., Chinese), they are percent-encoded in uppercase during Preload, but lowercase in browser requests.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Without this feature, Nginx may generate separate cache keys for uppercase/lowercase percent-encoded URLs, leading to cache misses.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Setup mitmproxy, use pre-made python script to convert percent-encodings to lowercase on the fly before requests reach Nginx. Please check Help tab.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                <?php echo esc_html__('Proxy Host', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_proxy_host_callback(); ?>
                                <p class="description"><?php echo esc_html__('Enter the proxy IP (e.g., 127.0.0.1).', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
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
                                <button id="nginx-regex-reset-defaults" class="button nginx-reset-regex-button">
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
                                <button id="nginx-extension-reset-defaults" class="button nginx-reset-extension-button">
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
                                                <button id="nginx-cache-schedule-set" class="button nginx-cache-schedule-set-button">
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
                                <button id="nginx-key-regex-reset-defaults" class="button nginx-reset-key-regex-button">
                                    <?php echo esc_html__('Reset Default', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                                </button>
                                <div class="cache-paths-info">
                                    <p class="description"><?php echo esc_html__('Click the button to reset defaults. After plugin updates, it\'s best to reset first to apply the latest changes, then reapply your custom rules.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                </div>
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
                                <button id="clear-logs-button" class="button nginx-clear-logs-button">
                                    <?php echo esc_html__( 'Clear Logs', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </button>
                                <p class="description">
                                    <?php echo esc_html__( 'Click the button to clear logs.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-users"></span>
                                <?php echo esc_html__( 'Opt-in', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_tracking_opt_in_callback(); ?>
                                <p class="description"><?php echo esc_html__( 'Please check the GDPR Compliance and Data Collection section in the Help tab to get more info.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
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
                    wp_die('You do not have sufficient permissions to access this page.');
                }

                // Check if 'nginx_cache_settings' is set in the POST data
                if (isset($_POST['nginx_cache_settings'])) {
                    // Retrieve existing options before sanitizing the input
                    $existing_options = get_option('nginx_cache_settings');

                    // Ignored PCP warning because we use custom sanitization via 'nppp_nginx_cache_settings_sanitize()'.
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
                        // Get the old and new opt-in values
                        $old_opt_in = isset($existing_options['nginx_cache_tracking_opt_in']) ? $existing_options['nginx_cache_tracking_opt_in'] : '1';
                        $new_opt_in = isset($new_settings['nginx_cache_tracking_opt_in']) ? $new_settings['nginx_cache_tracking_opt_in'] : '1';

                        // Always delete the plugin permission cache when the form is submitted
                        $static_key_base = 'nppp';
                        $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
                        delete_transient($transient_key_permissions_check);

                        // Update the settings
                        // Note: This will re-encode 'nginx_cache_key_custom_regex' via sanitization
                        update_option('nginx_cache_settings', $new_settings);

                        // Compare old and new opt-in values
                        if ($old_opt_in !== $new_opt_in) {
                            // Opt-in status has changed, handle accordingly
                            nppp_handle_opt_in_change($new_opt_in);
                        }

                        // Redirect with success message
                        wp_redirect(add_query_arg(array(
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

                        wp_redirect(add_query_arg(array(
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
    $default_cpu_limit = 80;
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='10' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit'] ?? $default_cpu_limit) . "' class='small-text' />";
}

// Callback function to display the input field for Per Request Wait Time setting
function nppp_nginx_cache_wait_request_callback() {
    $options = get_option('nginx_cache_settings');
    $default_wait_time = 0;
    echo "<input type='number' id='nginx_cache_wait_request' name='nginx_cache_settings[nginx_cache_wait_request]' min='0' max='60' value='" . esc_attr($options['nginx_cache_wait_request'] ?? $default_wait_time) . "' class='small-text' />";
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

// Callback to display the tracking opt-in checkbox
function nppp_nginx_cache_tracking_opt_in_callback() {
    // Retrieve all plugin settings
    $options = get_option('nginx_cache_settings');
    // Get the value for tracking opt-in, default to '1' if not set
    $value = isset($options['nginx_cache_tracking_opt_in']) ? $options['nginx_cache_tracking_opt_in'] : '1';
    ?>
    <input type="checkbox" id="nginx_cache_tracking_opt_in" name="nginx_cache_settings[nginx_cache_tracking_opt_in]" value="1" <?php checked('1', $value); ?> />
    <label for="nginx_cache_tracking_opt_in"><?php echo esc_html__('Opt-in to help improve plugin development.', 'fastcgi-cache-purge-and-preload-nginx'); ?></label>
    <?php
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

// Fetch default reject regex
function nppp_fetch_default_reject_regex() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $rr_txt_file = dirname(__FILE__) . '/reject_regex.txt';
    if ($wp_filesystem->exists($rr_txt_file)) {
        $file_content = nppp_perform_file_operation($rr_txt_file, 'read');
        $regex_match = preg_match('/\$reject_regex\s*=\s*[\'"](.+?)[\'"];/i', $file_content, $matches);
        if ($regex_match && isset($matches[1])) {
            return $matches[1];
        }
    } else {
        wp_die(esc_html__( 'File does not exist:', 'fastcgi-cache-purge-and-preload-nginx' ) . ' ' . esc_html($rr_txt_file) );
    }
    return '';
}

// Fetch default regex for fastcgi cache key
function nppp_fetch_default_regex_for_cache_key() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $rr_txt_file = dirname(__FILE__) . '/reject_regex.txt';
    if ($wp_filesystem->exists($rr_txt_file)) {
        $file_content = nppp_perform_file_operation($rr_txt_file, 'read');
        $regex_match = preg_match('/\$regex_for_cache_key\s*=\s*[\'"](.+?)[\'"];/i', $file_content, $matches);
        if ($regex_match && isset($matches[1])) {
            return $matches[1];
        }
    } else {
        wp_die(esc_html__( 'File does not exist:', 'fastcgi-cache-purge-and-preload-nginx' ) . ' ' . esc_html($rr_txt_file) );
    }
    return '';
}

// Fetch default reject file extensions
function nppp_fetch_default_reject_extension() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $rr_txt_file = dirname(__FILE__) . '/reject_regex.txt';
    if ($wp_filesystem->exists($rr_txt_file)) {
        $file_content = nppp_perform_file_operation($rr_txt_file, 'read');
        $regex_match = preg_match('/\$reject_extension\s*=\s*"([^"]+)"/', $file_content, $matches);
        if ($regex_match && isset($matches[1])) {
            return $matches[1];
        }
    } else {
        wp_die(esc_html__( 'File does not exist:', 'fastcgi-cache-purge-and-preload-nginx' ) . ' ' . esc_html($rr_txt_file) );
    }
    return '';
}

// Callback function for REST API Key
function nppp_nginx_cache_api_key_callback() {
    $options = get_option('nginx_cache_settings');
    $default_api_key = bin2hex(random_bytes(32));
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;
    echo "<input type='text' id='nginx_cache_api_key' name='nginx_cache_settings[nginx_cache_api_key]' value='" . esc_attr($api_key) . "' class='regular-text' />";

    echo "<div style='display: block; align-items: baseline;'>";
    echo "<button id='api-key-button' class='button nginx-api-key-button'>" . esc_html__( 'Generate API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "</button>";
    echo "<div style='display: flex; align-items: baseline; margin-top: 8px; margin-bottom: 8px;'>";
    echo "<p class='description' id='nppp-api-key' style='margin-right: 10px;'><span class='nppp-tooltip'>" . esc_html__( 'API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy REST API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "<p class='description' id='nppp-purge-url' style='margin-right: 10px;'><span class='nppp-tooltip'>" . esc_html__( 'Purge URL', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy full REST API CURL URL for Purge', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "<p class='description' id='nppp-preload-url'><span class='nppp-tooltip'>" . esc_html__( 'Preload URL', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy full REST API CURL URL for Preload', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "</div>";
    echo "</div>";
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
            // Check one-liner automatiion bash script used in initial setup
            $service_name = 'npp-wordpress.service';
            $service_path = '/etc/systemd/system/' . $service_name;
            if (file_exists($service_path)) {
                if (substr($input['nginx_cache_path'], -strlen('-npp')) !== '-npp') {
                    // Change original Nginx cache path with FUSE mounted automatically
                    $input['nginx_cache_path'] .= '-npp';
                }
            }
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

    // Sanitize Reject Regex field
    if (!empty($input['nginx_cache_reject_regex'])) {
        $sanitized_input['nginx_cache_reject_regex'] = preg_replace('/\\\\+/', '\\', $input['nginx_cache_reject_regex']);
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
        $sanitized_input['nginx_cache_reject_extension'] = preg_replace('/\\\\+/', '\\', $input['nginx_cache_reject_extension']);
    }

    // Sanitize Send Mail
    $sanitized_input['nginx_cache_send_mail'] = isset($input['nginx_cache_send_mail']) && $input['nginx_cache_send_mail'] === 'yes' ? 'yes' : 'no';

    // Sanitize Auto Preload
    $sanitized_input['nginx_cache_auto_preload'] = isset($input['nginx_cache_auto_preload']) && $input['nginx_cache_auto_preload'] === 'yes' ? 'yes' : 'no';

    // Sanitize Auto Preload Mobile
    $sanitized_input['nginx_cache_auto_preload_mobile'] = isset($input['nginx_cache_auto_preload_mobile']) && $input['nginx_cache_auto_preload_mobile'] === 'yes' ? 'yes' : 'no';

    // Sanitize Auto Purge
    $sanitized_input['nginx_cache_purge_on_update'] = isset($input['nginx_cache_purge_on_update']) && $input['nginx_cache_purge_on_update'] === 'yes' ? 'yes' : 'no';

     // Sanitize Cache Schedule
    $sanitized_input['nginx_cache_schedule'] = isset($input['nginx_cache_schedule']) && $input['nginx_cache_schedule'] === 'yes' ? 'yes' : 'no';

    // Sanitize REST API
    $sanitized_input['nginx_cache_api'] = isset($input['nginx_cache_api']) && $input['nginx_cache_api'] === 'yes' ? 'yes' : 'no';

    // Sanitize Opt-in
    $sanitized_input['nginx_cache_tracking_opt_in'] = isset($input['nginx_cache_tracking_opt_in']) && $input['nginx_cache_tracking_opt_in'] == '1' ? '1' : '0';

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
        $proxy_host = sanitize_text_field($input['nginx_cache_preload_proxy_host']);

        // Validate IP address format (IPv4 only)
        if (filter_var($proxy_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $sanitized_input['nginx_cache_preload_proxy_host'] = $proxy_host;
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-proxy-host',
                __('ERROR OPTION: Please enter a valid IPv4 address for the Proxy Host.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Please enter a valid IPv4 address for the Proxy Host.', 'fastcgi-cache-purge-and-preload-nginx'));
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

// Validate the fastcgi cache path to prevent bad inputs as much as possible
function nppp_validate_path($path, $nppp_is_premium_purge = false) {
    // Initialize WP filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Define the default path for whitelist
    $default_path = '/dev/shm/change-me-now';

    // Check if the path is the default path and whitelist it
    if ($path === $default_path) {
        return true;
    }

    // Validate the path
    $pattern = '/^\/(?:[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)*)\/?$/';

    // Define critical system directories
    $critical_directories = array('/bin','/boot','/etc','/lib','/lib64','/media','/proc','/root','/sbin','/srv','/sys','/usr','/home','/mnt','/var/log','/var/spool','/libexec','/run','/var/run');

    // Check if the path starts with any critical directory
    foreach ($critical_directories as $dir) {
        if (strpos($path, $dir) === 0) {
            return 'critical_path';
        }
    }

    // Check if the path matches correct format
    if (!preg_match($pattern, $path)) {
        return 'critical_path';
    }

    // Also supported paths (/dev /var /opt /tmp) can not be first level directory
    $path_parts = explode('/', trim($path, '/'));
    if (in_array($path_parts[0], ['dev', 'var', 'opt', 'tmp']) && count($path_parts) < 2) {
        return 'critical_path';
    }

    // Now check if the path points to a file
    // For premium purge validation
    if ($nppp_is_premium_purge) {
        if (!$wp_filesystem->is_file($path)) {
            return 'file_not_found_or_not_readable';
        }
    } else {
        // Now check if the directory exists
        if (!$wp_filesystem->is_dir($path)) {
            // Assign necessary variables
            $service_name = 'npp-wordpress.service';
            $service_path = '/etc/systemd/system/' . $service_name;
            $nginx_path = trim(shell_exec('command -v nginx'));
            $sudo_path = trim(shell_exec('command -v sudo'));
            $systemctl_path = trim(shell_exec('command -v systemctl'));

            // Force to create the nginx cache path that if defined in conf already
            // This code block will only run if the plugin's initial setup
            // was done using the following one-liner script:
            // [ bash <(curl -Ss https://psaux-it.github.io/install.sh) ]
            if (function_exists('exec') && function_exists('shell_exec')) {
                if ($wp_filesystem->exists($service_path)) {
                    if (!empty($nginx_path) && !empty($sudo_path)) {
                        if (substr($path, -strlen('-npp')) !== '-npp') {
                            // Construct and execute the 'nginx -T' command using 'echo "" | sudo -S' to prevent hang during password prompt
                            $nginx_command = "echo '' | sudo -S " . escapeshellcmd($nginx_path) . " -T > /dev/null 2>&1";
                            exec($nginx_command, $output, $return_var);
                            usleep(300000);
                        }
                    }
                }
            }

            // Re-check if directory exists
            if (!$wp_filesystem->is_dir($path)) {
                if (substr($path, -strlen('-npp')) === '-npp') {
                    if (!empty($systemctl_path) && !empty($sudo_path)) {
                        if ($wp_filesystem->exists($service_path)) {
                            // Construct and execute the restart command
                            $restart_command = "echo '' | sudo -S " . escapeshellcmd($systemctl_path) . " restart " . escapeshellcmd($service_name);
                            exec($restart_command . ' 2>&1', $output, $return_var);

                            if ($return_var === 0) {
                                $static_key_base = 'nppp';
                                $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
                                delete_transient($transient_key_permissions_check);
                                sleep(1);
                                return true;
                            } else {
                                return 'directory_not_exist_or_readable';
                            }
                        }
                    }
                }

                // Display error message for non-existent directory
                return 'directory_not_exist_or_readable';
            }
        }
    }

    return true;
}

// Function to reset plugin settings on deactivation
function nppp_reset_plugin_settings_on_deactivation() {
    // Stop preload process status inspector event
    wp_clear_scheduled_hook('npp_cache_preload_status_event');

    // Check if the preload status event action exists and remove it
    if (has_action('npp_cache_preload_status_event', 'nppp_create_scheduled_event_preload_status_callback')) {
        remove_action('npp_cache_preload_status_event', 'nppp_create_scheduled_event_preload_status_callback');
    }

    // Clear all instances of 'npp_cache_preload_event'
    wp_clear_scheduled_hook('npp_cache_preload_event');

    // Check if the action exists and remove it
    if (has_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback')) {
        remove_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback');
    }

    // Retrieve existing options to check opt-in status
    $existing_options = get_option('nginx_cache_settings');

    // Check if the user has opted in
    if (isset($existing_options['nginx_cache_tracking_opt_in']) && $existing_options['nginx_cache_tracking_opt_in'] === '1') {
        // Send plugin status to API
        nppp_plugin_tracking('inactive');

        // Remove scheduled cron for plugin status check
        nppp_schedule_plugin_tracking_event(true);
    }
}

// Automatically update the default options when the plugin is activated or reactivated
function nppp_defaults_on_plugin_activation() {
    $new_api_key = bin2hex(random_bytes(32));

    // Define default options
    $default_options = array(
        'nginx_cache_path' => '/dev/shm/change-me-now',
        'nginx_cache_email' => 'your-email@example.com',
        'nginx_cache_cpu_limit' => 100,
        'nginx_cache_reject_extension' => nppp_fetch_default_reject_extension(),
        'nginx_cache_reject_regex' => nppp_fetch_default_reject_regex(),
        'nginx_cache_key_custom_regex' => base64_encode(nppp_fetch_default_regex_for_cache_key()),
        'nginx_cache_wait_request' => 0,
        'nginx_cache_limit_rate' => 5120,
        'nginx_cache_tracking_opt_in' => '1',
        'nginx_cache_api_key' => $new_api_key,
        'nginx_cache_preload_proxy_host' => '127.0.0.1',
        'nginx_cache_preload_proxy_port' => 3434,
    );

    // Retrieve existing options (if any)
    $existing_options = get_option('nginx_cache_settings', array());

    // Merge existing options with default options
    // Existing options overwrite default options
    $updated_options = array_merge($default_options, $existing_options);

    // Update options in the database
    update_option('nginx_cache_settings', $updated_options);

    // Get the current plugin version dynamically
    $plugin_data = get_plugin_data(NPPP_PLUGIN_FILE);
    $current_version = $plugin_data['Version'];

    // Save the current version
    update_option('nppp_plugin_version', $current_version);

    // Create the log file if it doesn't exist
    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (!file_exists($log_file_path)) {
        $log_file_created = nppp_perform_file_operation($log_file_path, 'create');
        if (!$log_file_created) {
            // Log file creation failed, handle error accordingly
            nppp_custom_error_log('Failed to create log file: ' . $log_file_path);
        }
    }

    // Check if user has opted in
    if (isset($updated_options['nginx_cache_tracking_opt_in']) && $updated_options['nginx_cache_tracking_opt_in'] === '1') {
        // Send plugin status to API
        nppp_plugin_tracking('active');

        // Schedule cron for plugin status check
        nppp_schedule_plugin_tracking_event(false);
    }
}
