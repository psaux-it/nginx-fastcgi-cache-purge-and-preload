<?php
/**
 * Rest API for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains rest api preload progress functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Protect preload progress endpoint.
function nppp_nginx_cache_progress_permission_check() {
    return current_user_can('manage_options');
}

// Register the REST API endpoint for preload progress tracking.
function nppp_nginx_cache_register_preload_progress_endpoint() {
    register_rest_route('nppp_nginx_cache/v2', '/preload-progress', array(
        'methods' => 'GET',
        'callback' => 'nppp_nginx_cache_preload_progress',
        'permission_callback' => 'nppp_nginx_cache_progress_permission_check',
    ));
}

// Tracks and returns Nginx cache preload progress.
function nppp_nginx_cache_preload_progress($request) {
    $plugin_path = dirname(plugin_dir_path(__FILE__));
    $log_path = rtrim($plugin_path, '/') . '/nppp-wget.log';
    $pid_path = rtrim($plugin_path, '/') . '/cache_preload.pid';

    $checked = 0;
    $errors = 0;
    $last_url = '';
    $time_info = '';
    $last_preload_time = '';
    $is_running = false;
    $log_found = false;

    // Check if preload process is alive
    if (file_exists($pid_path)) {
        $pid = intval(trim(file_get_contents($pid_path)));
        if ($pid > 0 && nppp_is_process_alive($pid)) {
            $is_running = true;
        }
    }

    // Parse log file
    if (file_exists($log_path)) {
        $log_found = true;
        $lines = @file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                if (preg_match('/URL:(https?:\/\/[^\s]+).*?->/', $line, $match)) {
                    $checked++;
                    $last_url = $match[1];
                }

                if (stripos($line, 'ERROR 404') !== false) {
                    $errors++;
                }

                if (stripos($line, 'Total wall clock time:') !== false) {
                    if (preg_match('/Total wall clock time:\s*((?:[0-9]+m\s*)?[0-9]+s)/i', $line, $match)) {
                        $time_info = trim($match[1]);
                    }
                }

                if (preg_match('/^FINISHED\s+--([\d\-]+\s+[\d:]+)--$/', $line, $match)) {
                    $last_preload_time = trim($match[1]);
                }
            }
        }
    }

    // Get URL count
    $est_total = nppp_get_estimated_url_count();

    return new WP_REST_Response([
        'status' => $is_running ? 'running' : 'done',
        'checked' => $checked,
        'errors' => $errors,
        'last_url' => $last_url,
        'total' => $est_total,
        'time' => $time_info,
        'last_preload_time' => $last_preload_time,
        'log_found' => $log_found
    ]);
}

// Estimates the total number of URLs by parsing the site's XML sitemaps.
function nppp_get_estimated_url_count() {
    $static_key_base = 'nppp';
    $transient_key = 'nppp_est_url_counts_' . md5($static_key_base);

    // Check if cached
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    // Check if SimpleXML is available
    if (!extension_loaded('SimpleXML')) {
        set_transient($transient_key, 500, YEAR_IN_SECONDS);
        return 500;
    }

    $total = 0;
    $sitemap_url = home_url('/sitemap.xml');
    libxml_use_internal_errors(true);

    $xml = @simplexml_load_file($sitemap_url);
    if (!$xml) {
        set_transient($transient_key, 500, YEAR_IN_SECONDS);
        return 500;
    }

    foreach ($xml->sitemap as $sitemap) {
        $submap_url = (string) $sitemap->loc;
        $submap_xml = @simplexml_load_file($submap_url);
        if (!$submap_xml) continue;

        // Register namespaces if available
        $namespaces = $submap_xml->getNamespaces(true);
        if (isset($namespaces[''])) {
            $submap_xml->registerXPathNamespace('ns', $namespaces['']);
            $urls = $submap_xml->xpath('//ns:url');
        } else {
            $urls = $submap_xml->xpath('//url');
        }

        $total += count($urls);
    }

    $final_total = $total > 0 ? $total + 100 : 500;
    set_transient($transient_key, $final_total, YEAR_IN_SECONDS);
    return $final_total;
}
