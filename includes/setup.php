<?php
/**
 * Setup controller for FastCGI Cache Purge and Preload for Nginx
 * Description: Handles activation redirect, gates Settings until Nginx is detected or Assume-Nginx is enabled, renders the hidden Setup admin page.
 * Version: 2.1.3
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

    public function hooks(): void {
        // Activation redirect flag is set in main file via register_activation_hook
        add_action('admin_init', [$this, 'nppp_auto_disable_assume_when_detected'], 99);
        add_action('admin_init', [$this, 'nppp_maybe_redirect_to_setup']);
        add_action('admin_menu', [$this, 'nppp_register_setup_page']);
        add_action('admin_init', [$this, 'nppp_gate_settings_until_setup']);
        add_action('admin_post_nppp_setup_actions', [$this, 'nppp_handle_setup_post']);
    }

    // One-time redirect after activation
    public static function nppp_set_activation_redirect_flag(): void {
        update_option(self::REDIRECT_FLAG, 1, false);
    }

    public function nppp_maybe_redirect_to_setup(): void {
        if (! current_user_can('manage_options')) return;

        if (get_option(self::REDIRECT_FLAG)) {
            delete_option(self::REDIRECT_FLAG);
            if ($this->nppp_needs_setup()) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
                exit;
            }
        }
    }

    // Hide/redirect Settings if detection failed and assume-mode not enabled
    public function nppp_gate_settings_until_setup(): void {
        if (! current_user_can('manage_options')) return;
        if (! $this->nppp_needs_setup()) return;

        // If admin tries to access Settings, bounce to Setup.
        $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($current_page === self::SETTINGS_SLUG) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
            exit;
        }
    }

    public function nppp_register_setup_page(): void {
        // Hidden page (no menu item)
        add_submenu_page(
            null,
            __('NPP • Need Nginx Setup', 'fastcgi-cache-purge-and-preload-nginx'),
            __('NPP • Need Nginx Setup', 'fastcgi-cache-purge-and-preload-nginx'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'nppp_render_setup_page']
        );
    }

    public function nppp_render_setup_page(): void {
        if (! current_user_can('manage_options')) wp_die(__('Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx'));

        // Single source of truth for gating
        $needs_setup        = $this->nppp_needs_setup();

        // Detection signals for UI
        $strict_detected    = $this->nppp_is_nginx_detected_strict(); // real, ignores Assume
        $assume_enabled     = $this->nppp_assume_nginx_enabled();     // current Assume state
        $effective_detected = $this->nppp_is_nginx_detected();        // effective detection (honors Assume for heuristics)
        $nonce              = wp_create_nonce('nppp_setup_actions');

        // Get signals
        $this->nppp_is_nginx_detected();
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
        echo '<h1>' . esc_html__('NPP • Setup', 'fastcgi-cache-purge-and-preload-nginx') . '</h1>';

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
                'We couldn’t reliably confirm Nginx from within WordPress. This often happens when your site runs behind a proxy (Cloudflare, CDN, load balancer), inside a container, PLESK|cPanel or with a chroot/jail where /etc/nginx/nginx.conf is not found.',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . '</p></div>';

        echo '<div class="metabox-holder nppp-grid">';

        // LEFT column (main choices)
        echo '<div>';

        // Recommended path (bind/sync nginx.conf)
        echo '<div class="postbox nppp-card">';
        echo '  <h2 class="hndle"><span>' . esc_html__('Recommended: Use your real nginx.conf', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '  <div class="inside">';
        echo '    <p>'
            . esc_html__(
                'For maximum accuracy, bind-mount or sync your actual nginx.conf into the WordPress environment at',
                'fastcgi-cache-purge-and-preload-nginx'
              )
            . ' <code>/etc/nginx/nginx.conf</code>. '
            . esc_html__(
                'This lets the plugin fully functional and parse live cache paths, users, and keys.',
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
        echo          $this->nppp_detection_debug_html($strict_detected, $assume_enabled);
        echo '    </div>';
        echo '  </div>';

        // Dummy nginx.conf viewer
        echo '  <div class="postbox nppp-card">';
        echo '    <h2 class="hndle"><span>' . esc_html__('Dummy nginx.conf (fallback)', 'fastcgi-cache-purge-and-preload-nginx') . '</span></h2>';
        echo '    <div class="inside">';
        echo '      <p class="nppp-muted">'
             . esc_html__('Used only when Assume-Nginx mode is enabled and the real nginx.conf is not found.', 'fastcgi-cache-purge-and-preload-nginx')
             . '</p>';
        $show_dummy = isset($_GET['nppp_show_dummy']) && sanitize_text_field($_GET['nppp_show_dummy']) === '1';
        echo '      <p class="nppp-actions">';
        echo '        <a class="button" href="' . esc_url( add_query_arg(['nppp_show_dummy' => $show_dummy ? '0' : '1']) ) . '">'
               . ($show_dummy ? esc_html__('Hide dummy nginx.conf', 'fastcgi-cache-purge-and-preload-nginx') : esc_html__('Show dummy nginx.conf', 'fastcgi-cache-purge-and-preload-nginx'))
               . '</a>';
        echo '      </p>';
        if ($show_dummy) {
            echo '      <textarea readonly rows="14" style="width:100%;font-family:monospace;">'
                . esc_textarea($this->nppp_dummy_nginx_conf())
                . '</textarea>';
        }
        echo '    </div>';
        echo '  </div>';

        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    // Small helper to display what we currently know about detection.
    private function nppp_detection_debug_html(bool $nginx_detected, bool $assume_enabled): string {
        // $nginx_detected here is "strict"
        $effective = $this->nppp_is_nginx_detected();

        // Get signals
        $this->nppp_is_nginx_detected();
        $signals   = !empty($GLOBALS['NPPP__LAST_SIGNAL_HIT']);

        $bits = [];
        $bits[] = sprintf('<p><strong>%s</strong> %s</p>',
            esc_html__('Nginx detected (strict):', 'fastcgi-cache-purge-and-preload-nginx'),
            $nginx_detected ? '<span class="dashicons dashicons-yes"></span> ' . esc_html__('Yes', 'fastcgi-cache-purge-and-preload-nginx')
                            : '<span class="dashicons dashicons-warning"></span> ' . esc_html__('No', 'fastcgi-cache-purge-and-preload-nginx')
        );
        $bits[] = sprintf('<p><strong>%s</strong> %s</p>',
            esc_html__('Signals suggest Nginx:', 'fastcgi-cache-purge-and-preload-nginx'),
            $signals ? '<span class="dashicons dashicons-yes"></span> ' . esc_html__('Yes', 'fastcgi-cache-purge-and-preload-nginx')
                     : '<span class="dashicons dashicons-no"></span> ' . esc_html__('No', 'fastcgi-cache-purge-and-preload-nginx')
        );
        $bits[] = sprintf('<p><strong>%s</strong> %s</p>',
            esc_html__('Assume-Nginx mode:', 'fastcgi-cache-purge-and-preload-nginx'),
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

    public function nppp_handle_setup_post(): void {
        if (! current_user_can('manage_options')) wp_die(__('Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx'));
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
                $this->nppp_try_write_wp_config_define();
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
    public function nppp_needs_setup(): bool {
        return (! $this->nppp_is_nginx_detected_strict()) && (! $this->nppp_assume_nginx_enabled());
    }

    private function nppp_assume_nginx_enabled(): bool {
        if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) return true;
        return (bool) get_option(self::RUNTIME_OPTION);
    }

    // Detect nginx
    private function nppp_is_nginx_detected_strict(): bool {
        if (function_exists('\\nppp_precheck_nginx_detected')) {
            // ask precheck to IGNORE assume mode
            return (bool) \nppp_precheck_nginx_detected(false);
        }

        // fallback (same as your current fallback)
        if (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false) {
            return true;
        }
        return false;
    }

    private function nppp_is_nginx_detected(): bool {
        if (function_exists('\\nppp_precheck_nginx_detected')) {
            return (bool) \nppp_precheck_nginx_detected(true);
        }

        // fallback if pre-checks wasn't loaded for some reason
        if (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false) {
            return true;
        }

       return false;
    }

    // Insert of the define into wp-config.php
    private function nppp_try_write_wp_config_define(): void {
        if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) {
            // still try to persist to file so future requests have it early.
        }
        if (! function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $creds = request_filesystem_credentials('', '', false, false, []);
        if (! WP_Filesystem($creds)) return;
        global $wp_filesystem;

        $wp_config_path = $this->nppp_locate_wp_config_path();
        if (! $wp_config_path || ! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            return;
        }

        $contents = $wp_filesystem->get_contents($wp_config_path);
        if (! $contents || strpos($contents, 'NPPP_ASSUME_NGINX') !== false) {
            return;
        }

        $define_line = "define('NPPP_ASSUME_NGINX', true);\n";

        // Insert before "That's all, stop editing!"
        $marker = "That's all, stop editing!";
        $pos = strpos($contents, $marker);

        if ($pos !== false) {
            $contents = substr_replace($contents, $define_line, $pos, 0);
        } else {
            $contents .= "\n" . $define_line;
        }

        $wp_filesystem->put_contents($wp_config_path, $contents, FS_CHMOD_FILE);
    }

    private function nppp_locate_wp_config_path(): ?string {
        // Standard location
        if (file_exists(ABSPATH . 'wp-config.php') ) return ABSPATH . 'wp-config.php';
        // One level up
        $up = dirname(ABSPATH) . '/wp-config.php';
        return file_exists($up) ? $up : null;
    }

    // Auto-disable Assume-Nginx when real detection passes
    public function nppp_auto_disable_assume_when_detected(): void {
        if (! current_user_can('manage_options')) return;

        // skip immediately after enabling
        if (get_transient('nppp_assume_recently_enabled')) return;

        $detected = $this->nppp_is_nginx_detected_strict();
        $assume_enabled = $this->nppp_assume_nginx_enabled();

        if ($detected && $assume_enabled) {
            delete_option(self::RUNTIME_OPTION);
            $this->nppp_try_remove_wp_config_define();
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
                        __('SUCCESS ADMIN: Nginx was detected. Assume-Nginx mode has been disabled automatically.', 'fastcgi-cache-purge-and-preload-nginx'),
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
    private function nppp_try_remove_wp_config_define(): void {
        if (! function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $creds = request_filesystem_credentials('', '', false, false, []);
        if (! WP_Filesystem($creds)) return;
        global $wp_filesystem;

        $wp_config_path = $this->nppp_locate_wp_config_path();
        if (! $wp_config_path || ! $wp_filesystem->exists($wp_config_path) || ! $wp_filesystem->is_writable($wp_config_path)) {
            return;
        }

        $contents = $wp_filesystem->get_contents($wp_config_path);
        if (! $contents) return;

        // Match lines that define NPPP_ASSUME_NGINX as true
        $pattern = "/^[ \\t]*define\\([\\\"']NPPP_ASSUME_NGINX[\\\"'][ \\t]*,[ \\t]*true[ \\t]*\\);[ \\t]*\r?\n?/mi";
        $new = preg_replace($pattern, '', $contents);

        if ($new !== null && $new !== $contents) {
            $wp_filesystem->put_contents($wp_config_path, $new, FS_CHMOD_FILE);
        }
    }

    private function nppp_dummy_nginx_conf(): string {
        return <<<NGINX
# Dummy nginx.conf for NPP assume-Nginx mode (fallback)
# See plugin setup page for context and recommended real bind-mount of /etc/nginx/nginx.conf
user dummy;
worker_processes auto;
events { worker_connections 1024; }
http {
    include       mime.types;
    default_type  application/octet-stream;
    # These stanzas are placeholders so the plugin can parse keys/paths in opaque environments.
    proxy_cache_path /var/run/nginx-cache levels=1:2 keys_zone=npp:10m inactive=60m use_temp_path=off;
    log_format  main  '\$remote_addr - \$remote_user [\$time_local] "\$request" '
                      '\$status \$body_bytes_sent "\$http_referer" '
                      '"\$http_user_agent"';
    access_log  /var/log/nginx/access.log  main;
    sendfile        on;
    keepalive_timeout  65;
    server { listen 80; server_name _; location / { return 200 "NPP dummy"; } }
}
NGINX;
    }
}
