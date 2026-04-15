<?php
/**
 * Runtime path helpers for Nginx Cache Purge Preload
 * Description: Resolves and creates runtime directories and files used by plugin operations.
 * Version: 2.1.5
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

        $runtime_dir = rtrim($uploads_base !== '' ? $uploads_base : plugin_dir_path(NPPP_PLUGIN_FILE), '/\\') . '/' . NPPP_RUNTIME_SUBDIR;

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

/**
 * If $mount_path is a FUSE filesystem mount (e.g. bindfs), return the
 * underlying source directory path. Returns null when $mount_path is not a
 * FUSE mount or when neither /proc/self/mountinfo nor /proc/mounts can be read.
 */
function nppp_fuse_source_path( string $mount_path ): ?string {
    $mount_path = rtrim( $mount_path, '/' );

    // Prefer /proc/self/mountinfo (Linux 2.6.26+): correctly disambiguates
    if ( is_readable( '/proc/self/mountinfo' ) ) {
        $lines = file( '/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( $lines !== false ) {
            foreach ( $lines as $line ) {
                $sep = strpos( $line, ' - ' );
                if ( $sep === false ) {
                    continue;
                }

                $left = preg_split( '/\s+/', substr( $line, 0, $sep ) );
                if ( ! isset( $left[4] ) ) {
                    continue;
                }

                $mountpoint = rtrim( preg_replace_callback(
                    '/\\\\([0-7]{3})/',
                    fn( $m ) => chr( octdec( $m[1] ) ),
                    $left[4]
                ), '/' );

                if ( $mountpoint !== $mount_path ) {
                    continue;
                }

                $right = preg_split( '/\s+/', ltrim( substr( $line, $sep + 3 ) ) );
                if ( ! isset( $right[0], $right[1] ) ) {
                    continue;
                }

                $fstype = $right[0];
                $source = rtrim( preg_replace_callback(
                    '/\\\\([0-7]{3})/',
                    fn( $m ) => chr( octdec( $m[1] ) ),
                    $right[1]
                ), '/' );

                if ( strpos( $fstype, 'fuse' ) === false ) {
                    continue;
                }

                if ( $source !== '' && $source !== $mount_path ) {
                    return $source;
                }
            }
        }
    }

    // Fallback: /proc/mounts (older kernels / containers without /proc/self).
    if ( is_readable( '/proc/mounts' ) ) {
        $lines = file( '/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( $lines !== false ) {
            foreach ( $lines as $line ) {
                $parts = explode( ' ', $line );
                if ( count( $parts ) < 3 ) {
                    continue;
                }

                $source     = rtrim( $parts[0], '/' );
                $mountpoint = rtrim( $parts[1], '/' );
                $fstype     = $parts[2];

                if ( $mountpoint !== $mount_path ) {
                    continue;
                }

                if ( strpos( $fstype, 'fuse' ) === false ) {
                    continue;
                }

                if ( $source !== '' && $source !== $mount_path ) {
                    return $source;
                }
            }
        }
    }

    return null;
}

/**
 * Translate a file path found under $scan_path to its equivalent path under
 * the FUSE mount at $fuse_path.
 *
 * This is the inverse of the FUSE mount: if rg or the PHP iterator returns
 * /source/a/b/file, the deletable path is /fuse-mount/a/b/file.
 *
 * Returns $filepath unchanged when both roots are identical (no FUSE
 * optimisation active) or when the path does not start with $scan_path.
 */
function nppp_translate_path_to_fuse( string $filepath, string $scan_path, string $fuse_path ): ?string {
    $scan_root = rtrim( $scan_path, '/' ) . '/';
    $fuse_root = rtrim( $fuse_path, '/' ) . '/';

    if ( $scan_root === $fuse_root ) {
        return $filepath;
    }

    if ( strpos( $filepath, $scan_root ) === 0 ) {
        return $fuse_root . substr( $filepath, strlen( $scan_root ) );
    }

    return null;
}
