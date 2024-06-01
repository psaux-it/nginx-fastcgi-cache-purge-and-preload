<?php
/**
 * Nginx config parser functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Nginx config parser functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Function to parse the Nginx configuration file
function nppp_parse_nginx_config($file) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
    }

    $config = $wp_filesystem->get_contents($file);
    $cache_paths = [];

    // Regex to match cache path directives
    preg_match_all('/(proxy_cache_path|fastcgi_cache_path|scgi_cache_path|uwsgi_cache_path)\s+([^;]+);/', $config, $cache_directives, PREG_SET_ORDER);

    foreach ($cache_directives as $cache_directive) {
        $directive = $cache_directive[1];
        $value = trim(preg_replace('/\s.*$/', '', $cache_directive[2]));
        if (!isset($cache_paths[$directive])) {
            $cache_paths[$directive] = [];
        }
        $cache_paths[$directive][] = $value;
    }

    return ['cache_paths' => $cache_paths];
}

// Function to get Nginx version, OpenSSL version, and modules
function nppp_get_nginx_info() {
    $output = shell_exec('nginx -V 2>&1');

    // Extract Nginx version
    if (preg_match('/nginx\/([\d.]+)/', $output, $matches)) {
        $nginx_version = $matches[1];
    } else {
        $nginx_version = 'Unknown';
    }

    // Extract OpenSSL version
    if (preg_match('/OpenSSL ([\d.]+)/', $output, $matches)) {
        $openssl_version = $matches[1];
    } else {
        $openssl_version = 'Unknown';
    }

    return [
        'nginx_version' => $nginx_version,
        'openssl_version' => $openssl_version,
    ];
}

// Function to generate HTML output
function nppp_generate_html($cache_paths, $nginx_info) {
    ob_start();
    //img url's
    $image_url_bar = plugins_url('/admin/img/bar.png', dirname(__FILE__));
    $image_url_ad = plugins_url('/admin/img/logo_ad.png', dirname(__FILE__));
    ?>
    <header></header>
    <main>
        <section class="nginx-status">
            <h2>NGINX STATUS</h2>
            <table>
                <tbody>
                    <tr>
                        <td class="action">Nginx Version</td>
                        <td class="status" id="npppNginxVersion">
                            <span class="dashicons dashicons-yes"></span>
                            <span><?php echo esc_html($nginx_info['nginx_version']); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="action">OpenSSL Version</td>
                        <td class="status" id="npppOpenSSLVersion">
                            <span class="dashicons dashicons-yes"></span>
                            <span><?php echo esc_html($nginx_info['openssl_version']); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="action">Found Active Cache Paths</td>
                        <td class="status">
                            <table class="nginx-config-table">
                                <tbody>
                                    <?php foreach ($cache_paths as $values): ?>
                                        <?php foreach ($values as $value): ?>
                                            <tr>
                                                <td><span class=""></span><?php echo esc_html($value); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
    <div class="nppp-premium-widget">
        <div id="nppp-ad" style="margin-top: 20px; margin-bottom: 0; margin-left: 0; margin-right: 0;">
          <div class="textcenter">
            <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell-top" data-pro-ad="sidebar-logo">
              <img src="<?php echo esc_url($image_url_bar); ?>" alt="Nginx Cache Purge & Preload PRO" title="Nginx Cache Purge & Preload PRO" style="width: 60px !important;">
            </a>
          </div>
          <h3 class="textcenter">Hope you are enjoying NPP! Do you still need assistance with the server side integration? Get our server integration service now and optimize your website's caching performance!</h3>
          <p class="textcenter">
            <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell" data-pro-ad="sidebar-logo">
              <img src="<?php echo esc_url($image_url_ad); ?>" alt="Nginx Cache Purge & Preload PRO" title="Nginx Cache Purge & Preload Pro">
            </a>
          </p>
          <p class="textcenter"><a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="button button-primary button-large open-nppp-upsell" data-pro-ad="sidebar-button">Get Service</a></p>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode function to display the Nginx configuration
function nppp_nginx_config_shortcode() {
    $config_file = '/etc/nginx/nginx.conf';
    if (!file_exists($config_file)) {
        return '<p>Nginx configuration file not found.</p>';
    }

    $config_data = nppp_parse_nginx_config($config_file);
    $nginx_info = nppp_get_nginx_info();

    return nppp_generate_html($config_data['cache_paths'], $nginx_info);
}
