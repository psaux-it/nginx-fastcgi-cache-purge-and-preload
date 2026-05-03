<?php
/**
 * Settings page renderer and form submission handler for Nginx Cache Purge Preload
 * Description: Outputs the full admin settings page HTML and processes the settings form POST.
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
        <?php
        // Pre-compute all badges
        $nppp_show_assume = nppp_is_assume_nginx_mode();
        $nppp_badge_opts  = get_option( 'nginx_cache_settings', [] );
        $nppp_badge_path  = isset( $nppp_badge_opts['nginx_cache_path'] ) ? $nppp_badge_opts['nginx_cache_path'] : '';
        $nppp_badge_disk  = ( ! empty( $nppp_badge_path ) && function_exists( 'nppp_get_cache_disk_size' ) )
                              ? nppp_get_cache_disk_size( $nppp_badge_path )
                              : null;

        // Show cache disk warning over %90
        $nppp_show_cache_warn = (
            $nppp_badge_disk !== null &&
            $nppp_badge_disk['dedicated'] &&
            $nppp_badge_disk['total'] > 0 &&
            ( $nppp_badge_disk['used'] / $nppp_badge_disk['total'] ) >= 0.90
        );

        // safexec version update detection
        $nppp_show_safexec_warn = false;
        $nppp_safexec_latest_ver = '';
        if ( function_exists( 'nppp_check_safexec_version' ) ) {
            $nppp_safexec_str = nppp_check_safexec_version();
            if ( ! in_array( $nppp_safexec_str, [ 'Not Installed', 'Unknown' ], true ) ) {
                if ( preg_match( '/^(\S+)\s+\((\S+)\)$/', trim( $nppp_safexec_str ), $nppp_ver_parts ) ) {
                    $nppp_show_safexec_warn = version_compare( $nppp_ver_parts[1], $nppp_ver_parts[2], '!=' );
                    $nppp_safexec_latest_ver = $nppp_ver_parts[2];
                }
            }
        }

        // ripgrep binary check
        $nppp_rg_installed = false;
        if ( function_exists( 'shell_exec' ) ) {
            nppp_prepare_request_env();
            $nppp_rg_bin       = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
            $nppp_rg_installed = $nppp_rg_bin !== '';
        }

        // FUSE mount detection
        $nppp_fuse_active = false;
        if ( ! empty( $nppp_badge_path ) && function_exists( 'nppp_fuse_source_path' ) ) {
            $nppp_fuse_active = nppp_fuse_source_path( $nppp_badge_path ) !== null;
        }

        // safexec usability for rg (only meaningful when FUSE is active).
        $nppp_safexec_rg_ok = false;
        if ( $nppp_fuse_active && function_exists( 'nppp_find_safexec_path' ) && function_exists( 'nppp_is_safexec_usable' ) ) {
            $nppp_sfx_path      = nppp_find_safexec_path();
            $nppp_safexec_rg_ok = $nppp_sfx_path && nppp_is_safexec_usable( $nppp_sfx_path, false );
        }

        // Display RG badge when missing. Providing a Resource Group significantly reduces
        // load times for the Advanced tab and other expensive recursive operations.
        // Highly recommended for performance optimization.
        $nppp_show_rg_warn = ! $nppp_rg_installed;

        // Show FUSE+safexec badge when rg is present but safexec is absent on a FUSE mount.
        // rg alone on FUSE scans the slow mount path — safexec unlocks the original source path
        $nppp_show_fuse_safexec_warn = $nppp_rg_installed && $nppp_fuse_active && ! $nppp_safexec_rg_ok;

        // Only renders when at least one badge is visible
        if ( $nppp_show_assume || $nppp_show_cache_warn || $nppp_show_safexec_warn || $nppp_show_rg_warn || $nppp_show_fuse_safexec_warn ) : ?>
            <div id="nppp-badge-bar">
                <?php if ( $nppp_show_assume ) : ?>
                    <div id="nppp-assume">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <strong><?php echo esc_html__( 'Assume-Nginx Mode Active', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                        <?php if ( class_exists( '\NPPP\Setup' ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \NPPP\Setup::PAGE_SLUG ) ); ?>" class="button button-small">
                                <?php echo esc_html__( 'Setup', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ( $nppp_show_cache_warn ) : ?>
                    <div id="nppp-cache-warn">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <strong><?php echo esc_html__( 'Cache Storage Above 90%', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                        <a href="#status" class="button button-small">
                            <?php echo esc_html__( 'Status', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <?php if ( $nppp_show_safexec_warn ) : ?>
                    <div id="nppp-safexec-warn">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <strong>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: safexec version number */
                                    __( 'Update safexec to %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                                    $nppp_safexec_latest_ver
                                )
                            );
                            ?>
                        </strong>
                        <a href="#help" class="button button-small">
                            <?php echo esc_html__( 'Help', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <?php if ( $nppp_show_rg_warn ) : ?>
                    <div id="nppp-rg-warn">
                        <span class="dashicons dashicons-performance" aria-hidden="true"></span>
                        <strong>
                            <?php
                            echo esc_html(
                                $nppp_fuse_active
                                    ? __( 'Install ripgrep + safexec (FUSE)', 'fastcgi-cache-purge-and-preload-nginx' )
                                    : __( 'Install ripgrep for performance!', 'fastcgi-cache-purge-and-preload-nginx' )
                            );
                            ?>
                        </strong>
                        <a href="#help" class="button button-small">
                            <?php echo esc_html__( 'Help', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <?php if ( $nppp_show_fuse_safexec_warn ) : ?>
                    <div id="nppp-fuse-safexec-warn">
                        <span class="dashicons dashicons-performance" aria-hidden="true"></span>
                        <strong><?php echo esc_html__( 'Install safexec for performance (FUSE)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                        <a href="#help" class="button button-small">
                            <?php echo esc_html__( 'Help', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </a>
                    </div>
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
                                <p class="description"><?php echo esc_html__( 'Enter the full NGINX cache directory path for the plugin to operate.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'This directory must be configured in NGINX and be accessible by the PHP process.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Read and write permissions are required for purge and preload to function properly.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <div class="cache-paths-info" id="nppp-allowed-paths-info"
                                    style="<?php echo ( isset( $nppp_badge_opts['nginx_cache_bypass_path_restriction'] ) && $nppp_badge_opts['nginx_cache_bypass_path_restriction'] === 'yes' ) ? 'display:none;' : ''; ?>">
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
                                <?php nppp_nginx_cache_bypass_path_restriction_callback(); ?>
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
                                    <?php echo esc_html__( 'Automatically purges cache when content or site changes occur.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Single-item events auto purge the page itself. Under Purge Scope, selected Related Pages are also auto purged.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Site-wide events purge the entire cache.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'This setting does not warm cache by itself. To warm the cache after Auto Purge, enable Auto Preload below.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <div class="cache-paths-info">
                                    <p>
                                        <?php echo esc_html__( 'If Auto Preload is ON, any single-item purge (automatic or manual) will immediately preload the page and—if Related Pages are enabled—the Homepage, Shop Page and/or Category archives. Site-wide purges will trigger a global preload.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                    </p>
                                </div>
                                <?php nppp_nginx_cache_autopurge_triggers_callback(); ?>
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
                                    <?php echo esc_html__( 'Synchronize the Cloudflare cache purges to keep both caches aligned.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Independent of "Auto Purge". When ON, the Cloudflare cache is purged whenever the Nginx cache is purged (whether for single URLs or the entire cache).', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Requires the official Cloudflare WordPress plugin with APO or PSC enabled and proper authentication.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
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
                                    <?php echo esc_html__( 'Synchronize the Redis Object Cache with the Nginx cache to keep both layers aligned.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Independent of "Auto Purge". When ON: "Purge All" (Admin, REST) also flushes the Redis object cache, but only if "Auto Preload" is ON.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'When ON: "Preload All" (Admin, REST, Cron) always flushes the Redis object cache.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Flushing Redis cache only makes sense as part of the Nginx Purge+Preload chain or direct Cache Preload actions.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'A purge-only operation (without Preload) should leave the Redis cache warm.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__( 'Requires the Redis Object Cache plugin to be installed, configured, and connected.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
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
                                <div class="cache-paths-info"><?php echo esc_html__( 'Extends single-URL purges only — has no effect on full cache purge (Purge All, REST API, or Scheduled Purge). Fires on every single-URL purge: automatically via Auto Purge events and manually via the Admin Bar on-page button, the Advanced Tab, or the front-end purge button.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></div>
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
                                <span class="dashicons dashicons-warning" style="color:#e6a817;"></span>
                                <?php esc_html_e( 'Vary: Accept-Encoding', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php
                                $nppp_vary = function_exists('nppp_detect_vary_issue') ? nppp_detect_vary_issue() : null;
                                if ($nppp_vary !== null && $nppp_vary['issue']) :
                                ?>
                                <div style="background:#fef2f2; border-left:4px solid #dc2626; padding:10px 14px; max-width:500px;">
                                    <strong style="color:#991b1b;"><?php esc_html_e( '⚠ Active: Double Cache Issue Detected', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                                    <span style="font-size:13px; color:#7f1d1d;">
                                        <?php
                                        if (!empty($nppp_vary['zlib_on'])) {
                                            esc_html_e('PHP zlib.output_compression is On and emitting Vary: Accept-Encoding for compressed requests. Nginx is creating per-client variant cache files.', 'fastcgi-cache-purge-and-preload-nginx');
                                        } else {
                                            esc_html_e('An upstream plugin or middleware is emitting Vary: Accept-Encoding only for compressed requests. Nginx is creating per-client variant cache files.', 'fastcgi-cache-purge-and-preload-nginx');
                                        }
                                        ?>
                                        <a href="?page=fastcgi-cache-purge-and-preload-nginx&nppp_tab=help#vary-issue" style="font-size:13px; color:#991b1b; font-weight:600; text-decoration:none; display:block; margin-top:4px;">
                                            <?php esc_html_e( '→ See Help tab for the required two-step fix', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                        </a>
                                    </span>
                                </div>
                                <?php elseif ($nppp_vary !== null && !$nppp_vary['issue']) : ?>
                                <div style="background:#f0fdf4; border-left:4px solid #16a34a; padding:10px 14px; max-width:500px;">
                                    <strong style="color:#14532d;"><?php esc_html_e( '✔ Not Affected', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                                    <span style="font-size:13px; color:#166534;"><?php esc_html_e( 'No upstream Vary: Accept-Encoding source detected. Single cache file per URL confirmed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                                </div>
                                <?php else : ?>
                                <div style="background:#fff8e1; border-left:4px solid #f0ad4e; padding:10px 14px; max-width:500px;">
                                    <strong style="color:#7a4f00;"><?php esc_html_e( 'Double Cache Issue', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                                    <span style="font-size:13px; color:#5a3800;">
                                        <?php esc_html_e( 'Could not verify. ', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                        <a href="?page=fastcgi-cache-purge-and-preload-nginx&nppp_tab=help#vary-issue" style="font-size:13px; color:#7a4f00; font-weight:600; text-decoration:none;">
                                            <?php esc_html_e( '→ See Help tab for fix and full explanation', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                        </a>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </td>
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
                                <p class="description"><?php echo esc_html__( 'Enable this feature to automatically preload the cache after a purge. This ensures fast load times by proactively caching content before the first visitor arrives.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'When enabled, your website will automatically rebuild the cache with the latest content, ensuring a high cache hit rate even for frequently changing pages.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'This process is triggered after any purge: automatically via the Auto Purge feature, manually via "Purge All", or via a single-URL purge from the Admin Bar or Advanced Tab.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'To trigger Preload Related Pages (Purge Scope) when Auto Purge is ON, this setting must be turned ON.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
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
                                    <p class="description"><?php echo esc_html__( 'If only Auto Preload is enabled, it also triggers automatically after any Purge action: REST API, Admin Bar, Advanced Tab, or Auto Purge events.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'When both Auto Purge and Auto Preload are enabled, it triggers automatically whenever cache is purged — whether by Auto Purge events, Purge All, single-URL purges from the Admin Bar or Advanced Tab, or via REST.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                                <div class="cache-paths-info">
                                    <h4><strong><?php echo esc_html__( 'Note:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong></h4>
                                    <p><?php echo esc_html__( 'The Mobile Preload action will begin after the main Preload process completes via the WordPress Cron job. As a result, the Mobile Preload action may start with a delay. To track the status of this process, please refer to the log section of the plugin.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-admin-users"></span>
                                <?php echo esc_html__( 'Mobile User Agent', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <?php nppp_nginx_cache_mobile_user_agent_callback(); ?>
                                <div class="key-regex-info">
                                    <p class="description"><?php echo esc_html__( 'Define the User-Agent sent during the mobile preload pass. Must match the User-Agent your Nginx cache key uses to distinguish mobile from desktop variants.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                    <p class="description"><?php echo esc_html__( 'Only relevant when Preload Mobile is enabled. If your cache key does not vary on User-Agent, leave this at the default value.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                </div>
                                <button type="button" id="nginx-mobile-ua-reset-defaults" class="button nginx-reset-mobile-ua-button">
                                    <?php echo esc_html__( 'Reset Default', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                                </button>
                                <div class="cache-paths-info">
                                    <p class="description"><?php echo esc_html__( 'Click the button to restore the built-in mobile User-Agent string. After plugin updates, reset first to pick up any changes, then reapply your custom value.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
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
                                 <p class="description"><?php echo esc_html__( 'If you are getting 502 Bad Gateway errors or service crashes, try setting it to 1 first and take small steps with each adjustment.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'On low-resource servers (limited RAM/CPU), setting this to 0 can trigger a Self-DDoS effect, potentially crashing your server or causing 502/504 errors', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                 <p class="description"><?php echo esc_html__( 'Default: 0 second, Disabled', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-shield"></span>
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
                                <br><p class="description"><strong><?php echo esc_html__( 'Allowed API Authentication Headers:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong></p>
                                <ul class="description" style="color: #646970; font-size: 14px;">
                                    <li>
                                        <strong><?php echo esc_html__( 'Authorization Header:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <code><?php echo esc_html__( 'Authorization: Bearer YOUR_API_KEY', 'fastcgi-cache-purge-and-preload-nginx' ); ?></code>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'X-Api-Key Header:', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong>
                                        <code><?php echo esc_html__( 'X-Api-Key: YOUR_API_KEY', 'fastcgi-cache-purge-and-preload-nginx' ); ?></code>
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
                                    <br><p class="description">⚡ <?php echo esc_html__('The default regex pattern parses the \'$host\' and \'$request_uri\' portions from nginx cache key lines and correctly handles the three most common standard formats used in WordPress/PHP-FPM stacks:', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">✅ <code>$scheme$request_method$host$request_uri</code> &mdash; <?php echo esc_html__('most widely used fastcgi format', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                    <p class="description">✅ <code>$scheme$proxy_host$request_uri</code> &mdash; <?php echo esc_html__('nginx default for proxy_cache setups, no request method in key', 'fastcgi-cache-purge-and-preload-nginx'); ?></p>
                                    <p class="description">✅ <code>$scheme$host$request_uri</code> &mdash; <?php echo esc_html__('scheme-only variant with no request method', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">⚡ <?php echo esc_html__('If you use a non-standard or complex \'_cache_key\' format, you must define a custom regex pattern to correctly parse \'$host\' and \'$request_uri\' portions in order to ensure proper plugin functionality.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">⚡ <?php echo esc_html__('For example, if your custom key format is \'$scheme$request_method$host$mobile_device_type$request_uri$is_args$args\', you will need to provide a corresponding regex pattern that accurately captures the \'$host\' and \'$request_uri\' parts.', 'fastcgi-cache-purge-and-preload-nginx'); ?></p><br>
                                    <p class="description">📌 <strong><?php echo esc_html__('Guidelines for creating a compatible regex:', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong></p>
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
                                <p class="description"><?php echo esc_html__( 'Delegates single-URL purging to Nginx itself via the ngx_cache_purge module instead of NPP touching the filesystem. Applies to both manual and auto purge triggers.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Recommended module: nginx-modules fork v2.5.x', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Broadly compatible with managed hosting and control panels where ngx_cache_purge is pre-compiled.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Purge All always uses filesystem operations. HTTP Purge applies only to single-URL and related single-URL purges.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Falls back to filesystem purge automatically if the module is unavailable, existing workflow is fully preserved.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'When NPP\'s URL index already holds more than one cache path for a URL (e.g. Vary or mobile variants), HTTP Purge is bypassed and the index is used directly.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'After making changes, always Clear Plugin Cache in the Status tab for them to take effect.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
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
                        <!-- RG Purge -->
                        <tr valign="top">
                            <th scope="row">
                                <span class="dashicons dashicons-superhero"></span>
                                <?php echo esc_html__( 'RG Purge', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </th>
                            <td>
                                <div class="nppp-auto-preload-container">
                                    <div class="nppp-onoffswitch-rgpurge">
                                        <?php nppp_rg_purge_enabled_callback(); ?>
                                    </div>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Accelerates single-URL cache purge by using ripgrep (rg) to locate cache files — up to 60× faster than recursive filesystem scan on large caches.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Applies only to single-URL purges. Purge All always uses filesystem operations.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'HIGHLY Recommended for large cache sites with over 1,000 URLs.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Requirements: ripgrep (rg) linux binary installed and available in PATH.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
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

// Processes the form submission
function nppp_handle_nginx_cache_settings_submission() {
    // Verify nonce and check capability
    check_admin_referer('nginx_cache_settings_nonce', 'nginx_cache_settings_nonce');

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Check if 'nginx_cache_settings' is set in the POST data
    if (isset($_POST['nginx_cache_settings'])) {
        $existing_options = get_option('nginx_cache_settings', []);

        // Ignored PCP warning because we use custom sanitization via 'nppp_nginx_cache_settings_sanitize()'.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Already sanitized
        $nginx_cache_settings = wp_unslash($_POST['nginx_cache_settings']);

        // This is a pre-check to catch sanitization errors early, before calling update_option, to ensure proper redirection
        $new_settings = nppp_nginx_cache_settings_sanitize($nginx_cache_settings);

        // Check if there are any settings errors
        $errors = get_settings_errors('nppp_nginx_cache_settings_group');

        // If there are no sanitize errors, proceed to update the settings
        if (empty($errors)) {
            // PRESERVE UNTOUCHED KEYS — merge sanitized with existing
            $existing_options = (array) $existing_options;
            $merged = wp_parse_args($new_settings, $existing_options);

            // Always delete the permission cache
            $static_key_base = 'nppp';
            $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
            delete_transient($transient_key_permissions_check);

            // Delete cache related binary checks
            delete_transient('nppp_safexec_ok');
            delete_transient('nppp_rg_ok');

            // Update the settings
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
}
