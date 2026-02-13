<?php
/**
 * Runtime-path helpers for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains functions to set runtime path for FastCGI Cache Purge and Preload for Nginx.
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

if (!function_exists('nppp_get_runtime_dir')) {
    function nppp_get_runtime_dir(): string {
        static $runtime_dir = null;

        if ($runtime_dir !== null) {
            return $runtime_dir;
        }

        $uploads_base = '';
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (is_array($uploads) && !empty($uploads['basedir'])) {
                $uploads_base = (string) $uploads['basedir'];
            }
        }

        if ($uploads_base === '' && defined('WP_CONTENT_DIR')) {
            $uploads_base = WP_CONTENT_DIR . '/uploads';
        }

        $runtime_dir = rtrim($uploads_base !== '' ? $uploads_base : plugin_dir_path(NPPP_PLUGIN_FILE), '/\\') . '/nginx-cache-purge-preload-runtime';

        if (function_exists('wp_mkdir_p') && !is_dir($runtime_dir)) {
            wp_mkdir_p($runtime_dir);
        }

        return $runtime_dir;
    }
}

if (!function_exists('nppp_get_runtime_file')) {
    function nppp_get_runtime_file(string $filename): string {
        return rtrim(nppp_get_runtime_dir(), '/\\') . '/' . ltrim($filename, '/\\');
    }
}

if (!defined('NGINX_CACHE_LOG_FILE')) {
    define('NGINX_CACHE_LOG_FILE', nppp_get_runtime_file('fastcgi_ops.log'));
}
