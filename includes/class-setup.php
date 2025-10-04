<?php
/**
 * Setup controller for FastCGI Cache Purge and Preload for Nginx
 * Description: Handles activation redirect, gates Settings until Nginx is detected or Assume-Nginx is enabled, renders the hidden Setup admin page.
 * Version: 2.1.4
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

        // Run once-per-while OPcache refresh ONLY on this page
        add_action('load-' . $hook, [__CLASS__, 'nppp_maybe_reset_opcache']);
    }

    public static function nppp_render_setup_page(): void {
        if (! current_user_can('manage_options')) wp_die( esc_html__( 'Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx' ) );

        // Single source of truth for gating
        $needs_setup        = self::nppp_needs_setup();

        // Detection signals for UI
        $strict_detected    = self::nppp_is_nginx_detected_strict(); // real, ignores Assume
        $assume_enabled     = self::nppp_assume_nginx_enabled();     // current Assume state
        $effective_detected = self::nppp_is_nginx_detected();        // effective detection (honors Assume for heuristics)
        $nonce              = wp_create_nonce('nppp_setup_actions');

        // Get signals
        self::nppp_is_nginx_detected();
        $signals_detected   = !empty($GLOBALS['NPPP__LAST_SIGNAL_HIT']);

        // Minor inline styles for layout
        echo '<style>
            .nppp-grid{display:grid;gap:16px;grid-template-columns:1fr;max-width:980px}
            @media (min-width:960px){.nppp-grid{grid-template-columns:2fr 1fr}}
            .nppp-card{background:#fff;border:1px solid #dcdcde;border-radius:4px}
            .nppp-card .inside{padding:16px}
            .nppp-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
            .nppp-muted{color:#646970}
            .nppp-kbd{background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;padding:2px 6px;font-family:monospace}
        </style>';

        echo '<div class="wrap">';
        $page_title = ($strict_detected || $assume_enabled)
            ? __('NPP • Setup Completed', 'fastcgi-cache-purge-and-preload-nginx')
            : __('NPP • Complete Setup', 'fastcgi-cache-purge-and-preload-nginx');

        // Logo
        $plugin_slug = basename( dirname( dirname( __FILE__ ) ) );
        $logo_url    = trailingslashit( content_url( 'plugins/' . $plugin_slug ) ) . 'admin/img/logo.png';

        // Header
        echo '<h1 class="wp-heading-inline">'
            . '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr__('NPP logo', 'fastcgi-cache-purge-and-preload-nginx') . '" class="nppp-logo" width="90" height="90" />'
            . '<span class="nppp-title">' . esc_html($page_title) . '</span>'
            . '</h1>';

        echo '<hr class="wp-header-end">';

        // Minimal styles for sizing/alignment
        echo '<style>
            .nppp-logo{
                width:90px;height:90px;vertical-align:middle;margin-right:12px;
                object-fit:contain;border-radius:0px;
            }
            .nppp-title{vertical-align:middle}
            @media (max-width: 782px){
                .nppp-logo{width:60px;height:60px;margin-right:10px}
            }
        </style>';

        // Top notice: success vs. action needed
        if ($strict_detected) {
            echo '<div class="notice notice-success"><p>'
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
            echo '<div class="notice notice-info"><p>'
               . esc_html__('Assume-Nginx mode is enabled. You can proceed to Settings. If you later bind the real nginx.conf, this mode will be disabled automatically.', 'fastcgi-cache-purge-and-preload-nginx')
               . '</p></div>';
        } elseif ($signals_detected) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Nginx likely detected (via headers/server signature), but the real nginx.conf is not visible. Bind your real nginx.conf (recommended) or enable Assume-Nginx mode to proceed.', 'fastcgi-cache-purge-and-preload-nginx')
                . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>'
               . esc_html__('Nginx was not detected. This can be a false positive in proxy, Docker, or chrooted environments.', 'fastcgi-cache-purge-and-preload-nginx')
               . '</p></div>';
        }

        // Why am I seeing this?
        echo '<div class="notice notice-info"><p><strong>'
            . esc_html__('Why am I seeing this page?', 'fastcgi-cache-purge-and-preload-nginx')
            . '</strong> '
            . esc_html__(
                'We couldn’t reliably confirm Nginx from within WordPress. This often happens when your site runs behind a proxy (Cloudflare, CDN, load balancer), in containers/VMs, in chroot/jail setups, on Plesk or cPanel, or when nginx.conf is not found.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . ' '
            . esc_html__(
                'NPP does not directly interact with Nginx. For informational metrics (especially the Status tab), it only reads nginx.conf (read-only).',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . '</p></div>';

        echo '<div class="metabox-holder nppp-grid">';

        // LEFT column (main choices)
        echo '<div>';

        // Recommended path (bind/sync nginx.conf)
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

        // Quick enable (Assume-Nginx) card
        echo '<div class="postbox nppp-card">';
        echo '  <h2 class="hndle"><span>' . esc_html__('Quick Enable: Assume-Nginx Mode', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '  <div class="inside">';
        echo '    <p>'
            . esc_html__(
                'Turn on Assume-Nginx mode to enable all plugin features immediately in non-standard or opaque environments.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . ' '
            . esc_html__(
                'This sets a runtime option; you can also persist it to wp-config.php for extra safety.',
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

        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    // Small helper to display what we currently know about detection.
    private static function nppp_detection_debug_html(bool $nginx_detected, bool $assume_enabled): string {
        // $nginx_detected here is "strict"
        $effective = self::nppp_is_nginx_detected();

        // Get signals
        self::nppp_is_nginx_detected();
        $signals   = !empty($GLOBALS['NPPP__LAST_SIGNAL_HIT']);

        $bits = [];
        $bits[] = sprintf('<p><strong>%s</strong> %s</p>',
            esc_html__('nginx.conf detected (strict):', 'fastcgi-cache-purge-and-preload-nginx'),
            $nginx_detected ? '<span class="dashicons dashicons-yes"></span> ' . esc_html__('Yes', 'fastcgi-cache-purge-and-preload-nginx')
                            : '<span class="dashicons dashicons-warning"></span> ' . esc_html__('No', 'fastcgi-cache-purge-and-preload-nginx')
        );
        $bits[] = sprintf('<p><strong>%s</strong> %s</p>',
            esc_html__('Signals suggest Nginx:', 'fastcgi-cache-purge-and-preload-nginx'),
            $signals ? '<span class="dashicons dashicons-yes"></span> ' . esc_html__('Yes', 'fastcgi-cache-purge-and-preload-nginx')
                     : '<span class="dashicons dashicons-no"></span> ' . esc_html__('No', 'fastcgi-cache-purge-and-preload-nginx')
        );
        $bits[] = sprintf('<p><strong>%s</strong> %s</p>',
            esc_html__('Assume-Nginx Mode:', 'fastcgi-cache-purge-and-preload-nginx'),
            $assume_enabled ? '<span class="dashicons dashicons-yes"></span> ' . esc_html__('Enabled', 'fastcgi-cache-purge-and-preload-nginx')
                            : '<span class="dashicons dashicons-no"></span> ' . esc_html__('Disabled', 'fastcgi-cache-purge-and-preload-nginx')
        );

        // Quick hints the detector uses (keep generic to avoid leaking env specifics)
        $hints  = '<ul style="margin-left:18px">';
        $hints .= '<li>' . esc_html__('Server signature (SERVER_SOFTWARE)', 'fastcgi-cache-purge-and-preload-nginx') . '</li>';
        $hints .= '<li>' . esc_html__('HTTP headers (server / PHP hints)', 'fastcgi-cache-purge-and-preload-nginx') . '</li>';
        $hints .= '<li>' . esc_html__('nginx.conf found at standard paths', 'fastcgi-cache-purge-and-preload-nginx') . '</li>';
        $hints .= '</ul>';

        $bits[] = '<p class="nppp-muted"><strong>' . esc_html__('Signals checked:', 'fastcgi-cache-purge-and-preload-nginx') . '</strong></p>' . $hints;

        if (! $nginx_detected && ! $assume_enabled) {
            $bits[] = '<p class="nppp-muted">'
                . esc_html__('If your stack uses a proxy/CDN or containers, direct detection can fail even though Nginx is actually in front. Use the Recommended or Quick Enable options on the left.', 'fastcgi-cache-purge-and-preload-nginx')
                . '</p>';
        }

        return implode('', $bits);
    }

    public static function nppp_handle_setup_post(): void {
        if (! current_user_can('manage_options')) wp_die( esc_html__( 'Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        check_admin_referer('nppp_setup_actions');

        $action = isset($_POST['nppp_action']) ? sanitize_key($_POST['nppp_action']) : '';

        if ($action === 'assume_on') {
            update_option(self::RUNTIME_OPTION, 1, false);

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
                \nppp_clear_plugin_cache();
            }

            wp_safe_redirect(admin_url('admin.php?page=' . self::SETTINGS_SLUG));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    // Do we need to block settings and run setup?
    public static function nppp_needs_setup(): bool {
        // memoize per request
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        // If Assume-Nginx is enabled, never gate.
        if (self::nppp_assume_nginx_enabled()) {
            $memo = false;
            return $memo;
        }

        // Linux check
        $linux_ok =
            (PHP_OS === 'Linux') ||
            (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux') ||
            (stripos(php_uname(), 'Linux') !== false);

        // Require function availability
        $shell_ok = function_exists('shell_exec');
        $exec_ok  = function_exists('exec');
        $posix_ok = function_exists('posix_kill');

        // Commands check
        $tools_ok = false;
        if ($shell_ok) {
            // Ensure PATH etc. is sane for shell lookups
            if (function_exists('\\nppp_prepare_request_env')) {
                \nppp_prepare_request_env(true);
            }

            $missing = [];
            foreach (['ps','grep','awk','sort','uniq','sed','nohup','wget'] as $cmd) {
                // "command -v <cmd>" -> non-empty output if present
                $out = @shell_exec('command -v ' . escapeshellarg($cmd));
                if (empty($out)) {
                    $missing[] = $cmd;
                }
            }
            $tools_ok = empty($missing);
        }

        // All minimal criticals must be true
        $env_ok = ($linux_ok && $shell_ok && $exec_ok && $posix_ok && $tools_ok);

        // Gate to Setup only when criticals are OK AND strict nginx.conf is missing AND Assume is off
        $memo = $env_ok && (! self::nppp_is_nginx_detected_strict()) && (! self::nppp_assume_nginx_enabled());
        return $memo;
    }

    private static function nppp_assume_nginx_enabled(): bool {
        if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) return true;
        return (bool) get_option(self::RUNTIME_OPTION);
    }

    // Detect nginx
    private static function nppp_is_nginx_detected_strict(): bool {
        if (function_exists('\\nppp_precheck_nginx_detected')) {
            // ask precheck to IGNORE assume mode
            return (bool) \nppp_precheck_nginx_detected(false);
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

    // Insert of the define into wp-config.php
    private static function nppp_try_write_wp_config_define(): void {
        if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) {
            // still try to persist to file so future requests have it early.
        }

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

        if (! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $creds = request_filesystem_credentials(admin_url(''), '', false, dirname($wp_config_path), null);
            if ($creds && WP_Filesystem($creds)) {
                global $wp_filesystem;
            }
        }

        // Re-check after the JIT re-init
        if (! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            return;
        }

        $contents = $wp_filesystem->get_contents($wp_config_path);
        if (! $contents) return;

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

        $wp_filesystem->put_contents($wp_config_path, $contents, FS_CHMOD_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($wp_config_path, true);
        }
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
                \nppp_clear_plugin_cache();
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

        // If not writable, JIT re-init with a context
        if (! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $creds = request_filesystem_credentials(admin_url(''), '', false, dirname($wp_config_path), null);
            if ($creds && WP_Filesystem($creds)) {
                global $wp_filesystem;
            }
        }

        // Re-check
        if (! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            return;
        }

        $contents = $wp_filesystem->get_contents($wp_config_path);
        if (! $contents) return;

        // Match lines that define NPPP_ASSUME_NGINX as true
        $pattern = "/^[ \t]*define\(\s*['\"]NPPP_ASSUME_NGINX['\"]\s*,\s*true\s*\)\s*;[^\r\n]*\r?\n?/mi";
        $new = preg_replace($pattern, '', $contents);

        if ($new !== null && $new !== $contents) {
            $wp_filesystem->put_contents($wp_config_path, $new, FS_CHMOD_FILE);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($wp_config_path, true);
            }
        }
    }

    /**
     * OPcache: lightly and safely refresh when the Setup page loads.
     * - Runs at most once per minute (transient gate).
     * - Only executes on admin.php?page=nppp-setup (via load-$hook above).
     * - No fatal if OPcache is disabled.
    */
    public static function nppp_maybe_reset_opcache(): void {
        // Optional manual trigger: ?nppp_force_opcache=1 bypasses the cooldown
        $force = isset($_GET['nppp_force_opcache']) && $_GET['nppp_force_opcache'] === '1';

        if ( ! $force && get_transient('nppp_opcache_reset_done') ) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_opcache_reset
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        } elseif (function_exists('opcache_invalidate')) {
            // Minimal noop if reset is unavailable; invalidating this file ensures at least the Setup class is fresh.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_opcache_invalidate
            @opcache_invalidate(__FILE__, true);
        }

        // Cooldown so we don’t reset on every refresh
        set_transient('nppp_opcache_reset_done', 1, MINUTE_IN_SECONDS);
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
