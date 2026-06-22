<?php
/**
 * Setup controller for Nginx Cache Purge Preload
 * Description: Handles activation routing and setup gating until compatible Nginx conditions are met.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

namespace NPPP;

// Exit if accessed directly.
defined('ABSPATH') || exit;

final class Setup {
    const RUNTIME_OPTION = 'nppp_assume_nginx_runtime';
    const REDIRECT_FLAG  = 'nppp_redirect_to_setup_once';
    const PAGE_SLUG      = 'nppp-setup';
    const SETTINGS_SLUG  = 'nginx_cache_settings';

    /**
     * Static bootstrap: register all WP hooks.
     */
    public static function init(): void {
        // Skipping registration here is what stops the HEAD-probe storm on
        // tab switches.
        if (wp_doing_ajax()) {
            return;
        }

        // Activation redirect flag is set in main file via register_activation_hook
        add_action('admin_init', [__CLASS__, 'nppp_auto_disable_assume_when_detected'], 99);
        add_action('admin_init', [__CLASS__, 'nppp_maybe_redirect_to_setup']);
        add_action('admin_menu', [__CLASS__, 'nppp_register_setup_page']);
        add_action('admin_init', [__CLASS__, 'nppp_gate_settings_until_setup']);
        add_action('admin_post_nppp_setup_actions', [__CLASS__, 'nppp_handle_setup_post']);
    }

    // One-time redirect after activation
    public static function nppp_set_activation_redirect_flag(): void {
        update_option(self::REDIRECT_FLAG, 1, false);
    }

    public static function nppp_maybe_redirect_to_setup(): void {
        if (! current_user_can('manage_options')) return;

        if (get_option(self::REDIRECT_FLAG)) {
            delete_option(self::REDIRECT_FLAG);
            if (self::nppp_needs_setup()) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
                exit;
            }
        }
    }

    // Hide/redirect Settings if detection failed and assume-mode not enabled
    public static function nppp_gate_settings_until_setup(): void {
        if (! current_user_can('manage_options')) return;
        if (! self::nppp_needs_setup()) return;

        // If admin tries to access Settings, bounce to Setup.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of current admin page; no state change.
        $current_page = isset($_GET['page']) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ($current_page === self::SETTINGS_SLUG) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
            exit;
        }
    }

    public static function nppp_register_setup_page(): void {
        if ( ! current_user_can('manage_options') ) return;

        // Use a real parent to avoid PHP 8.1+ deprecation warnings.
        $parent = 'admin.php';

        // Hidden page (no menu item)
        $hook = add_submenu_page(
            $parent,
            esc_html__('NPP • Need Nginx Setup', 'fastcgi-cache-purge-and-preload-nginx'),
            esc_html__('NPP • Need Nginx Setup', 'fastcgi-cache-purge-and-preload-nginx'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'nppp_render_setup_page']
        );
    }

    public static function nppp_render_setup_page(): void {
        if (! current_user_can('manage_options')) wp_die( esc_html__( 'Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx' ) );

        // Single source of truth for gating
        $needs_setup        = self::nppp_needs_setup();

        // Detection signals for UI
        $strict_detected    = self::nppp_is_nginx_detected_strict(); // real, ignores Assume
        $assume_enabled     = self::nppp_assume_nginx_enabled();     // current Assume state
        $nonce              = wp_create_nonce('nppp_setup_actions');

        // Get signals
        self::nppp_is_nginx_detected();
        $signals_detected   = !empty($GLOBALS['NPPP__LAST_SIGNAL_HIT']);

        // Consolidated styles — layout, logo, badges, status table.
        echo '<style>
            .nppp-grid{display:grid;gap:16px;grid-template-columns:1fr;max-width:980px}
            @media(min-width:960px){.nppp-grid{grid-template-columns:2fr 1fr}}
            .nppp-card{background:#fff;border:1px solid #dcdcde;border-radius:4px}
            .nppp-card .inside{padding:16px}
            .nppp-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
            .nppp-muted{color:#646970}
            .nppp-kbd{background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;padding:2px 6px;font-family:monospace}
            .nppp-header-content{position:relative;isolation:isolate;background:#000;color:#e6ebf2;display:flex;align-items:center;gap:18px;padding:14px 16px;overflow:hidden;margin-bottom:16px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
            .nppp-aurora-canvas{position:absolute;inset:0;z-index:0;pointer-events:none;display:block;will-change:transform;contain:paint}
            .nppp-aurora-overlay{position:absolute;inset:0;z-index:2;pointer-events:none;display:block;will-change:transform;contain:paint}
            .nppp-aurora-canvas,.nppp-aurora-overlay{background:transparent!important;image-rendering:optimizeQuality;transform:translateZ(0)}
            .wrap .nppp-header-content{background:#000!important}
            .nppp-img-container,.nppp-buttons-wrapper{position:relative;z-index:1}
            .nppp-img-container img{width:90px;height:auto}
            .nppp-header-text{display:flex;flex-direction:column;gap:5px}
            .nppp-header-eyebrow{display:flex;align-items:center;gap:10px;margin:0}
            .nppp-wordmark{font-size:13px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;font-family:monospace;color:#fff;background:rgba(255,255,255,0.10);padding:3px 9px;border-radius:4px;border:1px solid rgba(255,255,255,0.18);line-height:1.6;flex-shrink:0}
            .nppp-header-state{display:inline-block;font-size:18px;font-weight:400;letter-spacing:-0.2px;line-height:1.3;padding:3px 12px;border-radius:6px;background:rgba(255,255,255,0.07)}
            .nppp-header-state--pass{color:#4ade80;background:rgba(74,222,128,0.10)}
            .nppp-header-state--info{color:#93c5fd;background:rgba(99,179,237,0.10)}
            .nppp-header-state--warn{color:#fcd34d;background:rgba(251,191,36,0.10)}
            .nppp-header-subtitle{color:#ffffff;margin:0;font-size:11px;font-weight:500;letter-spacing:1px;text-transform:uppercase}
            .nppp-wordmark--pass{background:rgba(74,222,128,0.15);border-color:rgba(74,222,128,0.35);color:#4ade80}
            .nppp-wordmark--info{background:rgba(99,179,237,0.15);border-color:rgba(99,179,237,0.35);color:#63b3ed}
            .nppp-wordmark--warn{background:rgba(251,191,36,0.15);border-color:rgba(251,191,36,0.35);color:#fbbf24}
            .nppp-header-state--pass{color:#4ade80}
            .nppp-header-state--info{color:#93c5fd}
            .nppp-header-state--warn{color:#fcd34d}
            @media(prefers-reduced-motion:reduce){.nppp-aurora-canvas,.nppp-aurora-overlay{display:none}}
            @media(prefers-color-scheme:dark){.nppp-header-content{background:#0b0e12;color:#e6ebf2}}
            @media(max-width:782px){.nppp-img-container img{width:60px}}
            .nppp-status-table{width:100%;border-collapse:collapse;font-size:13px}
            .nppp-status-table tr+tr td{border-top:1px solid #f0f0f1}
            .nppp-status-table td{padding:9px 4px;vertical-align:middle;color:#1d2327}
            .nppp-status-table td.nppp-st-label{color:#3c434a;width:60%}
            .nppp-status-table td:last-child{text-align:right;white-space:nowrap}
            .nppp-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.3px;text-transform:uppercase}
            .nppp-badge--pass{background:#edfaef;color:#1a7f37}
            .nppp-badge--warn{background:#fff8e5;color:#9a5000}
            .nppp-badge--fail{background:#fce8e8;color:#b91c1c}
            .nppp-badge--info{background:#eef2ff;color:#3730a3}
            .nppp-badge--off{background:#f0f0f1;color:#3c434a}
            .nppp-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
            .nppp-badge--pass .nppp-dot{background:#1a7f37}
            .nppp-badge--warn .nppp-dot{background:#9a5000}
            .nppp-badge--fail .nppp-dot{background:#b91c1c}
            .nppp-badge--info .nppp-dot{background:#3730a3}
            .nppp-badge--off .nppp-dot{background:#a0a0a0}
            .nppp-signals{list-style:none;margin:0;padding:8px 0 0}
            .nppp-signals li{display:flex;align-items:center;gap:7px;padding:4px 0;color:#646970;font-size:12px}
            .nppp-signals li::before{content:"";width:5px;height:5px;border-radius:50%;background:#a7aaad;flex-shrink:0}
            .nppp-signals-label{margin:14px 0 2px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#646970}
        </style>';

        echo '<div class="wrap">';

        $plugin_slug = basename( dirname( dirname( __FILE__ ) ) );
        $logo_url    = trailingslashit( content_url( 'plugins/' . $plugin_slug ) ) . 'admin/img/logo.png';

        // Derive state modifier class for badge + text coloring.
        $state_mod = $strict_detected ? 'pass' : ( $assume_enabled ? 'info' : 'warn' );

        // State-only label
        $state_text = $strict_detected
            ? __( 'Setup Completed',          'fastcgi-cache-purge-and-preload-nginx' )
            : ( $assume_enabled
                ? __( 'Assume-Nginx Mode Active', 'fastcgi-cache-purge-and-preload-nginx' )
                : __( 'Complete Setup',           'fastcgi-cache-purge-and-preload-nginx' )
            );

        // Aurora hero header
        echo '<div class="nppp-header-content" data-theme="aurora">';
        echo '  <div class="nppp-img-container">';
        echo '    <img src="' . esc_url( $logo_url ) . '" width="90" height="90" alt="' . esc_attr__( 'NPP logo', 'fastcgi-cache-purge-and-preload-nginx' ) . '">';
        echo '  </div>';
        echo '  <div class="nppp-buttons-wrapper">';
        echo '    <div class="nppp-header-text">';
        echo '      <div class="nppp-header-eyebrow">';
        echo '        <span class="nppp-wordmark nppp-wordmark--' . esc_attr( $state_mod ) . '">NPP</span>';
        echo '        <span class="nppp-header-state nppp-header-state--' . esc_attr( $state_mod ) . '">' . esc_html( $state_text ) . '</span>';
        echo '      </div>';
        echo '      <p class="nppp-header-subtitle">'
            . esc_html__( 'Nginx Cache Purge Preload', 'fastcgi-cache-purge-and-preload-nginx' )
            . ' <span style="color:#2dd4bf;font-weight:400;letter-spacing:0">v' . esc_html( NPPP_PLUGIN_VERSION ) . '</span>'
            . '</p>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // where to inject admin notices.
        echo '<hr class="wp-header-end">';

        // Top notice: success vs. action needed
        if ($strict_detected) {
            echo '<div class="notice notice-success notice-nppp"><p>'
               . esc_html__('Nginx detected. You’re all set — continue to Settings.', 'fastcgi-cache-purge-and-preload-nginx')
               . '</p>';

            // Show only if the auto-disable notice flag is set by the hook
            if (get_option('nppp_assume_nginx_auto_disabled_notice')) {
                echo '<p class="nppp-muted" style="margin:6px 0 0 0;">'
                   . esc_html__('Assume-Nginx mode was disabled automatically.', 'fastcgi-cache-purge-and-preload-nginx')
                   . '</p>';
            }
            echo '</div>';

        } elseif ($assume_enabled) {
            echo '<div class="notice notice-info notice-nppp"><p>'
               . esc_html__('Assume-Nginx mode is enabled. When you solve the Nginx detection issue, this mode will be disabled automatically.', 'fastcgi-cache-purge-and-preload-nginx')
               . '</p></div>';
        } elseif ($signals_detected) {
            echo '<div class="notice notice-warning notice-nppp"><p>'
                . esc_html__('Nginx likely detected (via headers/server signature), but the real nginx.conf is not found. Enable Assume-Nginx mode to proceed with workaround mode and access Settings, then review the Help tab.', 'fastcgi-cache-purge-and-preload-nginx')
                . '</p></div>';
        } else {
            echo '<div class="notice notice-error notice-nppp"><p>'
               . esc_html__('Nginx could not be confirmed — neither nginx.conf nor any server signature or response header indicated Nginx. If you are certain your server runs Nginx (e.g. behind a strict proxy, CDN, or in a chrooted/containerized environment that strips headers), use Assume-Nginx mode below to proceed with workaround mode and access Settings, then review the Help tab.', 'fastcgi-cache-purge-and-preload-nginx')
               . '</p></div>';
        }

        // Targeted open_basedir root-cause notice — shown only when strict detection failed and
        // open_basedir is provably active, because this is the #1 silent killer of nginx.conf discovery.
        if ( ! $strict_detected && self::nppp_is_open_basedir_active() ) {
            echo '<div class="notice notice-warning notice-nppp"><p>'
               . '<strong>' . esc_html__( 'PHP open_basedir restriction is active.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</strong> '
               . esc_html__(
                   'open_basedir silently prevents PHP from reading nginx.conf at all standard probe paths. This is why NPP cannot confirm Nginx — nginx.conf may exist but PHP cannot see it.',
                   'fastcgi-cache-purge-and-preload-nginx'
               )
               . ' '
               . esc_html__(
                   'Add all required directories to open_basedir in your PHP-FPM pool config. Visit Settings page to see which paths you need to add. ',
                   'fastcgi-cache-purge-and-preload-nginx'
               )
               . '</p></div>';
        }

        // Why am I seeing this?
        if ( $needs_setup || $assume_enabled ) {
        echo '<div class="notice notice-info notice-nppp"><p><strong>'
            . esc_html__('Why am I seeing this page?', 'fastcgi-cache-purge-and-preload-nginx')
            . '</strong> '
            . esc_html__(
                    'NPP is a plugin built exclusively for Nginx-powered servers — it purges and preloads the Nginx cache. Before enabling its features, it must confirm your server is actually running Nginx.',
                    'fastcgi-cache-purge-and-preload-nginx'
                  )
            . ' '
            . esc_html__(
                'NPP could not find nginx.conf at any standard path. This is the only reason you are seeing this page. Common causes: nginx.conf is at a non-standard location, PHP open_basedir restrictions are blocking access to it, or your site runs behind a proxy, CDN, container, or Panel where the config is not directly accessible to PHP.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . ' '
            . esc_html__(
                'NPP reads nginx.conf (read-only) to extract cache paths, cache key, Nginx worker user, and cache zone names. These drive purge operations, preload targeting, cache directory permission checks, duplicate zone detection, and all Status tab metrics. Without nginx.conf, the Status tab cannot render at all.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . '</p></div>';
        }

        echo '<div class="metabox-holder nppp-grid">';

        // LEFT column (main choices)
        echo '<div>';

        // Recommended path (bind/sync nginx.conf)
        if ( $needs_setup || $assume_enabled ) :
        echo '<div class="postbox nppp-card">';
        echo '  <h2 class="hndle"><span>' . esc_html__('Recommended: Bind your live nginx.conf', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '  <div class="inside">';
        echo '    <p>'
            . esc_html__(
                'For maximum accuracy, bind-mount or sync your actual nginx.conf into the WordPress environment at',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . ' <code>/etc/nginx/nginx.conf</code>. '
            . esc_html__(
                'This makes the plugin fully functional and lets it parse live cache paths, users, and keys.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . '</p>';

        echo '    <details><summary class="nppp-muted">'
            . esc_html__('Example (Docker / compose)', 'fastcgi-cache-purge-and-preload-nginx')
            . '</summary>';
        echo '      <pre style="margin-top:8px;white-space:pre-wrap">'
            . esc_html__(
              '# docker-compose.yml
services:
  wordpress:
    volumes:
      - /host/etc/nginx/nginx.conf:/etc/nginx/nginx.conf:ro',
                'fastcgi-cache-purge-and-preload-nginx'
            )
          . '</pre>';
        echo '    </details>';

        echo '    <p class="nppp-muted" style="margin-top:12px">'
            . esc_html__('Once mounted, reload this page — detection should pass automatically.', 'fastcgi-cache-purge-and-preload-nginx')
            . '</p>';
        echo '  </div>';
        echo '</div>';
        endif;

        // Quick enable (Assume-Nginx) card
        echo '<div class="postbox nppp-card">';
        echo '  <h2 class="hndle"><span>' . esc_html__('Quick Enable: Assume-Nginx Mode', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '  <div class="inside">';
        echo '    <p>'
            . esc_html__(
                'Turn on Assume-Nginx mode to enable all plugin features immediately.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . ' '
            . esc_html__(
                'This sets a runtime option; you can also persist it to wp-config.php.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . '</p>';

        echo '    <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '      <input type="hidden" name="action" value="nppp_setup_actions" />';
        echo '      <input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

        if ($needs_setup) {
            echo '      <div class="nppp-actions">';
            echo '        <button class="button button-primary" name="nppp_action" value="assume_on">'
                . esc_html__('Enable Assume-Nginx Mode', 'fastcgi-cache-purge-and-preload-nginx')
                . '</button>';
            echo '        <label>'
                . '<input type="checkbox" name="write_wp_config" value="1" /> '
                . esc_html__('Also write define(\'NPPP_ASSUME_NGINX\', true) to wp-config.php', 'fastcgi-cache-purge-and-preload-nginx')
               . '</label>';
            echo '      </div>';
            echo '      <p class="nppp-muted" style="margin-top:8px">'
                . esc_html__('Tip: Persisting to wp-config.php avoids surprises if options are reset.', 'fastcgi-cache-purge-and-preload-nginx')
                . '</p>';
        } else {
            echo '      <p><em class="nppp-muted">'
                . esc_html__('Assume-Nginx mode is already enabled or Nginx is detected.', 'fastcgi-cache-purge-and-preload-nginx')
                . '</em></p>';
        }

        echo '    </form>';

        if (! $needs_setup) {
            echo '    <p class="nppp-actions" style="margin-top:8px">';
            echo '      <a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::SETTINGS_SLUG)) . '">'
                . esc_html__('Go to Settings', 'fastcgi-cache-purge-and-preload-nginx')
                . '</a>';
            echo '    </p>';
        }

        echo '  </div>';
        echo '</div>';

        echo '</div>';

        // RIGHT column (status)
        echo '<div>';
        echo '  <div class="postbox nppp-card">';
        echo '    <h2 class="hndle"><span>' . esc_html__('Detection Status', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '    <div class="inside">';
        echo          wp_kses_post( self::nppp_detection_debug_html( $strict_detected, $assume_enabled ) );
        echo '    </div>';
        echo '  </div>';

        // Dummy nginx.conf viewer
        if ( $needs_setup || $assume_enabled ) :
        echo '  <div class="postbox nppp-card">';
        echo '    <h2 class="hndle"><span>' . esc_html__('Dummy nginx.conf (fallback)', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '    <div class="inside">';
        echo '      <p class="nppp-muted">'
             . esc_html__('Used only when Assume-Nginx mode is enabled and the real nginx.conf is not found.', 'fastcgi-cache-purge-and-preload-nginx')
             . '</p>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only toggle; no state change
        $show_dummy = isset($_GET['nppp_show_dummy']) && sanitize_text_field( wp_unslash( $_GET['nppp_show_dummy'] ) ) === '1';
        echo '      <p class="nppp-actions">';
        echo '        <a class="button" href="' . esc_url( add_query_arg(['nppp_show_dummy' => $show_dummy ? '0' : '1']) ) . '">'
               . ($show_dummy ? esc_html__('Hide dummy nginx.conf', 'fastcgi-cache-purge-and-preload-nginx') : esc_html__('Show dummy nginx.conf', 'fastcgi-cache-purge-and-preload-nginx'))
               . '</a>';
        echo '      </p>';
        if ($show_dummy) {
            echo '      <textarea readonly rows="14" style="width:100%;font-family:monospace;">'
                . esc_textarea(self::nppp_dummy_nginx_conf())
                . '</textarea>';
        }
        echo '    </div>';
        echo '  </div>';
        endif;

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    // Status card: colored pill-badge table — scannable at a glance.
    private static function nppp_detection_debug_html(bool $nginx_detected, bool $assume_enabled): string {
        $signals    = ! empty( $GLOBALS['NPPP__LAST_SIGNAL_HIT'] );
        $obd_active = self::nppp_is_open_basedir_active();

        /**
         * Render a colored pill badge.
         *
         * @param string $type  One of: pass | warn | fail | info | off
         * @param string $label Already-translated, plain text.
         */
        $badge = static function ( string $type, string $label ): string {
            return '<span class="nppp-badge nppp-badge--' . esc_attr( $type ) . '">'
                 . '<span class="nppp-dot" aria-hidden="true"></span>'
                 . esc_html( $label )
                 . '</span>';
        };

        // Build table rows: label cell + badge cell.
        $rows = '';

        // nginx.conf strict detection.
        $rows .= '<tr>'
            . '<td class="nppp-st-label">' . esc_html__( 'nginx.conf (strict)', 'fastcgi-cache-purge-and-preload-nginx' ) . '</td>'
            . '<td>' . ( $nginx_detected
                ? $badge( 'pass', __( 'Detected',  'fastcgi-cache-purge-and-preload-nginx' ) )
                : $badge( 'fail', __( 'Not found', 'fastcgi-cache-purge-and-preload-nginx' ) ) )
            . '</td></tr>';

        // Heuristic server signals.
        $rows .= '<tr>'
            . '<td class="nppp-st-label">' . esc_html__( 'Nginx signature found', 'fastcgi-cache-purge-and-preload-nginx' ) . '</td>'
            . '<td>' . ( $signals
                ? $badge( 'info', __( 'Yes',  'fastcgi-cache-purge-and-preload-nginx' ) )
                : $badge( 'warn', __( 'None', 'fastcgi-cache-purge-and-preload-nginx' ) ) )
            . '</td></tr>';

        // Assume-Nginx mode state.
        $rows .= '<tr>'
            . '<td class="nppp-st-label">' . esc_html__( 'Assume-Nginx mode', 'fastcgi-cache-purge-and-preload-nginx' ) . '</td>'
            . '<td>' . ( $assume_enabled
                ? $badge( 'info', __( 'Enabled', 'fastcgi-cache-purge-and-preload-nginx' ) )
                : $badge( 'off',  __( 'Off',     'fastcgi-cache-purge-and-preload-nginx' ) ) )
            . '</td></tr>';

        // open_basedir restriction.
        $rows .= '<tr>'
            . '<td class="nppp-st-label">' . esc_html__( 'PHP open_basedir', 'fastcgi-cache-purge-and-preload-nginx' ) . '</td>'
            . '<td>' . ( $obd_active
                ? $badge( 'warn', __( 'Active',  'fastcgi-cache-purge-and-preload-nginx' ) )
                : $badge( 'pass', __( 'Not set', 'fastcgi-cache-purge-and-preload-nginx' ) ) )
            . '</td></tr>';

        $out = '<table class="nppp-status-table" role="table" aria-label="'
             . esc_attr__( 'Nginx detection status', 'fastcgi-cache-purge-and-preload-nginx' )
             . '">' . $rows . '</table>';

        // Signals-checked sub-section.
        $out .= '<p class="nppp-signals-label">'
              . esc_html__( 'Signals checked', 'fastcgi-cache-purge-and-preload-nginx' )
              . '</p>';
        $out .= '<ul class="nppp-signals">'
              . '<li>' . esc_html__( 'SERVER_SOFTWARE signature',     'fastcgi-cache-purge-and-preload-nginx' ) . '</li>'
              . '<li>' . esc_html__( 'HTTP response headers',         'fastcgi-cache-purge-and-preload-nginx' ) . '</li>'
              . '<li>' . esc_html__( 'nginx.conf at standard paths',  'fastcgi-cache-purge-and-preload-nginx' ) . '</li>'
              . '<li>' . esc_html__( 'nginx binary check',            'fastcgi-cache-purge-and-preload-nginx' ) . '</li>'
              . '</ul>';

        // Contextual hint when detection failed and no override is active.
        if ( ! $nginx_detected && ! $assume_enabled ) {
            $out .= '<p class="nppp-muted" style="margin-top:12px;font-size:12px;line-height:1.5">'
                  . esc_html__( 'Proxied, CDN, or containerised stacks can block direct detection. Use the options on the left to proceed.', 'fastcgi-cache-purge-and-preload-nginx' )
                  . '</p>';
        }

        return $out;
    }

    public static function nppp_handle_setup_post(): void {
        if (! current_user_can('manage_options')) wp_die( esc_html__( 'Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        check_admin_referer('nppp_setup_actions');

        $action = isset($_POST['nppp_action']) ? sanitize_key($_POST['nppp_action']) : '';

        if ($action === 'assume_on') {
            update_option(self::RUNTIME_OPTION, 1, true);

            // Define constant for current request lifecycle so subsequent code sees it.
            if (! defined('NPPP_ASSUME_NGINX')) {
                define('NPPP_ASSUME_NGINX', true);
            }

            set_transient('nppp_assume_recently_enabled', 1, 60);

            if (! empty($_POST['write_wp_config'])) {
                self::nppp_try_write_wp_config_define();
            }

            // Clear plugin caches after switching mode
            if (function_exists('\\nppp_clear_plugin_cache')) {
                \nppp_clear_plugin_cache(true);
            }

            wp_safe_redirect(admin_url('admin.php?page=' . self::SETTINGS_SLUG));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    // Do we need to block settings and go into setup
    public static function nppp_needs_setup(): bool {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        // If Assume-Nginx is enabled, never gate.
        if (self::nppp_assume_nginx_enabled()) {
            return $memo = false;
        }

        // Gate to Setup when nginx.conf is not found AND Assume Nginx mode is off
        // We never trust signals and strictly check nginx.conf
        return $memo = ! self::nppp_is_nginx_detected_strict();
    }

    private static function nppp_assume_nginx_enabled(): bool {
        if (function_exists('nppp_is_assume_nginx_mode')) {
            return nppp_is_assume_nginx_mode();
        }
        if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) return true;
        return (bool) get_option(self::RUNTIME_OPTION);
    }

    // Detect nginx
    private static function nppp_is_nginx_detected_strict(): bool {
        if (function_exists('\\nppp_precheck_nginx_detected')) {
            // ask precheck to IGNORE assume mode AND skip the dead signal
            // computation — this is the only call site that reads nothing
            // but the boolean return value.
            return (bool) \nppp_precheck_nginx_detected(false, true);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading server signature only; no state change.
        $server_sw = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        if ( $server_sw && stripos( $server_sw, 'nginx' ) !== false ) {
             return true;
        }
        return false;
    }

    private static function nppp_is_nginx_detected(): bool {
        if (function_exists('\\nppp_precheck_nginx_detected')) {
            return (bool) \nppp_precheck_nginx_detected(true);
        }

        // fallback if pre-checks wasn't loaded for some reason
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading server signature only; no state change.
        $server_sw = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        if ( $server_sw && stripos( $server_sw, 'nginx' ) !== false ) {
            return true;
        }

       return false;
    }

    // Delegates to the real canonical helper in pre-checks.php (loaded by bootstrap
    // before any Setup hook can fire). Inline fallback only if it's somehow absent.
    private static function nppp_is_open_basedir_active(): bool {
        if (function_exists('\\nppp_is_open_basedir_active')) {
            return \nppp_is_open_basedir_active();
        }
        $obd = trim((string) ini_get('open_basedir'));
        return $obd !== '' && strtolower($obd) !== 'none';
    }

    // Insert of the define into wp-config.php
    private static function nppp_try_write_wp_config_define(): void {
        $wp_filesystem = nppp_initialize_wp_filesystem();
        if ($wp_filesystem === false) {
            nppp_display_admin_notice(
                'error',
                __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
            );
            return;
        }

        $wp_config_path = self::nppp_locate_wp_config_path();
        if (! $wp_config_path) return;

        // Bail gracefully if wp-config.php isn't directly writable
        if (! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            return;
        }

        $contents = $wp_filesystem->get_contents($wp_config_path);
        if ($contents === false || $contents === '') return;

        $has_active_define = preg_match("/^[ \t]*define\(\s*['\"]NPPP_ASSUME_NGINX['\"]\s*,\s*true\s*\)\s*;/mi", $contents);
        if ($has_active_define) return;

        $define_line = "define('NPPP_ASSUME_NGINX', true);\n";
        $pos = strpos($contents, "That's all, stop editing!");

        // If not found (localized files), try the require line:
        if ($pos === false) {
            $reqPattern = "/require_once\s*\(\s*ABSPATH\s*\.\s*['\"]wp-settings\.php['\"]\s*\)\s*;\s*/mi";
            if (preg_match($reqPattern, $contents, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
            }
        }

        // If still not found, safest is to prepend (so it runs before includes)
        if ($pos !== false) {
            $contents = substr_replace($contents, $define_line, $pos, 0);
        } else {
            if (preg_match('/\A(\xEF\xBB\xBF)?<\?php(?:\s*declare\s*\([^)]*\)\s*;\s*)?/i', $contents, $m)) {
                $insert_at = strlen($m[0]);
                $contents  = substr_replace($contents, $define_line, $insert_at, 0);
            } else {
                $contents = "<?php\n" . $define_line . $contents;
            }
        }

        self::nppp_write_atomically($wp_filesystem, $wp_config_path, $contents);
    }

    private static function nppp_write_atomically($wp_filesystem, string $target_path, string $new_contents): bool {
        $tmp_path = $target_path . '.nppp-tmp-' . uniqid('', true);

        if (! $wp_filesystem->put_contents($tmp_path, $new_contents, FS_CHMOD_FILE)) {
            $wp_filesystem->delete($tmp_path);
            return false;
        }

        if (! $wp_filesystem->move($tmp_path, $target_path, true)) {
            $wp_filesystem->delete($tmp_path);
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target_path, true);
        }

        return true;
    }

    private static function nppp_locate_wp_config_path(): ?string {
        // Standard location
        if (file_exists(ABSPATH . 'wp-config.php') ) return ABSPATH . 'wp-config.php';
        // One level up
        $up = dirname(ABSPATH) . '/wp-config.php';
        return file_exists($up) ? $up : null;
    }

    // Auto-disable Assume-Nginx when real detection passes
    public static function nppp_auto_disable_assume_when_detected(): void {
        if (! current_user_can('manage_options')) return;

        // skip immediately after enabling
        if (get_transient('nppp_assume_recently_enabled')) return;

        $detected = self::nppp_is_nginx_detected_strict();
        $assume_enabled = self::nppp_assume_nginx_enabled();

        if ($detected && $assume_enabled) {
            delete_option(self::RUNTIME_OPTION);
            self::nppp_try_remove_wp_config_define();
            update_option('nppp_assume_nginx_auto_disabled_notice', 1, false);

            // Clear plugin caches after switching back to detected mode
            if (function_exists('\\nppp_clear_plugin_cache')) {
                \nppp_clear_plugin_cache(true);
            }
        }

        // Only proceed if we actually have a notice pending
        if (! get_option('nppp_assume_nginx_auto_disabled_notice')) {
            return;
        }

        $hook_settings = 'settings_page_' . self::SETTINGS_SLUG;
        $hook_setup    = 'admin_page_'    . self::PAGE_SLUG;

        $attach_printer = static function () {
            // Register a printer for this *same* request
            add_action('admin_notices', static function () {
                if (function_exists('\\nppp_display_admin_notice')) {
                    \nppp_display_admin_notice(
                        'success',
                        esc_html__( 'SUCCESS ADMIN: Nginx was detected. Assume-Nginx mode has been disabled automatically.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        true,
                        true
                    );
                    delete_option('nppp_assume_nginx_auto_disabled_notice');
                }
            }, 1);
        };

        // Attach on the two pages where we want to show it
        add_action('admin_head-' . $hook_settings, $attach_printer, 1);
        add_action('admin_head-' . $hook_setup,    $attach_printer, 1);
    }

    // Remove define('NPPP_ASSUME_NGINX', true); from wp-config.php
    private static function nppp_try_remove_wp_config_define(): void {
        $wp_filesystem = nppp_initialize_wp_filesystem();
        if ($wp_filesystem === false) {
            nppp_display_admin_notice(
                'error',
                __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
            );
            return;
        }

        $wp_config_path = self::nppp_locate_wp_config_path();
        if (! $wp_config_path) return;

        // Bail gracefully if wp-config.php isn't directly writable
        if (! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            return;
        }

        $contents = $wp_filesystem->get_contents($wp_config_path);
        if ($contents === false || $contents === '') return;

        // Match lines that define NPPP_ASSUME_NGINX as true
        $pattern = "/^[ \t]*define\(\s*['\"]NPPP_ASSUME_NGINX['\"]\s*,\s*true\s*\)\s*;[^\r\n]*\r?\n?/mi";
        $new = preg_replace($pattern, '', $contents);

        if ($new !== null && $new !== $contents) {
            self::nppp_write_atomically($wp_filesystem, $wp_config_path, $new);
        }
    }

    private static function nppp_dummy_nginx_conf(): string {
        static $cached = null;
        if ($cached !== null) return $cached;

        // Prefer the shipped dummy file in the plugin root.
        $candidates = [
            dirname(plugin_dir_path(__FILE__)) . '/dummy-nginx.conf',
            plugin_dir_path(__FILE__) . 'dummy-nginx.conf',
        ];

        foreach ($candidates as $path) {
            $real = realpath($path) ?: $path;
            if (is_readable($real)) {
                $buf = @file_get_contents($real);
                if ($buf !== false && $buf !== '') {
                    return $cached = $buf;
                }
            }
        }

        // Last-resort inline fallback
        return $cached = implode( "\n", array(
            'user  dummy;',
            'worker_processes  auto;',
            'events {',
            '    worker_connections 1024;',
            '}',
            'http {',
            '    include       mime.types;',
            '    default_type  application/octet-stream;',
            '    fastcgi_cache_path /var/run/nginx-fastcgi levels=1:2 keys_zone=npp_fcgi:10m inactive=60m use_temp_path=off;',
            '    fastcgi_cache_key "$scheme$request_method$host$request_uri";',
            '    access_log  /var/log/nginx/access.log  main;',
            '    sendfile        on;',
            '    keepalive_timeout  65;',
            '}',
        ));
    }
}
