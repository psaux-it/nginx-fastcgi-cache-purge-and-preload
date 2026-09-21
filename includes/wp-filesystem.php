<?php
/**
 * WordPress filesystem helpers for Nginx Cache Purge Preload
 * Description: Wraps WP_Filesystem operations, logging, and permission-safe file access utilities.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Defensive fallback: FS_CHMOD_FILE/DIR are defined by WP_Filesystem() internals.
// Guard here prevents fatal errors if filesystem init is skipped or delayed (e.g. WP-CLI).
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
    define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
    define( 'FS_CHMOD_DIR', 0755 );
}

// Custom logger function
function nppp_custom_error_log($message, $error_type = E_USER_WARNING) {
    $sanitized_message = wp_strip_all_tags($message);

    $caller = '';

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
    $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    if (!empty($trace[1]['function'])) {
        $caller = $trace[1]['function'];
    }

    if ($error_type === E_USER_ERROR) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[NPPP] ' . ($caller ? $caller . '(): ' : '') . $sanitized_message);
        return;
    }

    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        wp_trigger_error($caller, $sanitized_message, $error_type);
    }
}

// Read up to $max bytes from $path using C-level file_get_contents length arg.
function nppp_head_fast(string $path, int $max = 16384): string {
    $data = @file_get_contents($path, false, null, 0, $max);
    return ($data === false) ? '' : $data;
}

// Read up to $max bytes from $path, falling back to WP_Filesystem for FTP/SSH.
function nppp_read_head($wp_filesystem, string $path, int $max = 16384): string {
    $buf = nppp_head_fast($path, $max);
    if ($buf !== '') return $buf;

    // Fallback: WP_Filesystem may read via FTP/SSH; trim to $max
    $all = $wp_filesystem->get_contents($path);
    return ($all === false || $all === '') ? '' : substr($all, 0, $max);
}

// Initialize WP_Filesystem
function nppp_initialize_wp_filesystem() {
    global $wp_filesystem;

    // Return existing WP_Filesystem instance if already initialized
    if (is_object($wp_filesystem)) {
        return $wp_filesystem;
    }

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Direct init — works in all contexts on the vast majority of hosts
    if (WP_Filesystem()) {
        if (is_object($wp_filesystem)) {
            return $wp_filesystem;
        }

        nppp_custom_error_log(__('Filesystem object is not set.', 'fastcgi-cache-purge-and-preload-nginx'));
        return false;
    }

    // Detect every non-interactive context — credential prompt must never run here
    // Also prevent cron output, REST output corruption
    $doing_rest = defined('REST_REQUEST') && REST_REQUEST;
    $doing_cli  = defined('WP_CLI') && WP_CLI;

    if (is_admin() && !wp_doing_cron() && !wp_doing_ajax() && !$doing_rest && !$doing_cli) {
        $credentials = request_filesystem_credentials(admin_url(''), '', false, false, array());

        if (!empty($credentials) && !is_wp_error($credentials) && WP_Filesystem($credentials)) {
            if (is_object($wp_filesystem)) {
                return $wp_filesystem;
            }

            nppp_custom_error_log(__('Filesystem object is not set.', 'fastcgi-cache-purge-and-preload-nginx'));
            return false;
        }

        nppp_custom_error_log(__('Unable to obtain filesystem credentials.', 'fastcgi-cache-purge-and-preload-nginx'));
        return false;
    }

    nppp_custom_error_log(__('Could not initialize the filesystem in this request context.', 'fastcgi-cache-purge-and-preload-nginx'));
    return false;
}

// Perform file operations (delete, read, write, create, append)
function nppp_perform_file_operation($file_path, $operation, $data = null) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_custom_error_log(
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ),
            E_USER_ERROR
        );
        return false;
    }

    switch ($operation) {
        case 'delete':
            return $wp_filesystem->delete($file_path);
        case 'read':
            return $wp_filesystem->get_contents($file_path);
        case 'write':
            return $wp_filesystem->put_contents($file_path, $data);
        case 'create':
            if (!$wp_filesystem->exists($file_path)) {
                $wp_filesystem->touch($file_path);
                $wp_filesystem->chmod($file_path, 0644);
                return true;
            }
            return false;
        case 'append':
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return file_put_contents( $file_path, $data . "\n", FILE_APPEND | LOCK_EX );
        default:
            return false;
    }
}

// Validate purge path to avoid deleting webroot or content directories in extreme situations.
function nppp_validate_purge_path($directory_path) {
    $real_path = realpath($directory_path);

    if ($real_path === false) {
        return new WP_Error('directory_traversal', __('Directory traversal detected or invalid path.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    $unsafe_roots = array();
    if (defined('ABSPATH')) {
        $unsafe_roots[] = ABSPATH;
    }
    if (defined('WP_CONTENT_DIR')) {
        $unsafe_roots[] = WP_CONTENT_DIR;
    }
    if (defined('WP_PLUGIN_DIR')) {
        $unsafe_roots[] = WP_PLUGIN_DIR;
    }
    if (defined('WPMU_PLUGIN_DIR')) {
        $unsafe_roots[] = WPMU_PLUGIN_DIR;
    }

    foreach ($unsafe_roots as $root) {
        $root_real = realpath($root);
        if (!$root_real) {
            continue;
        }

        $real_path_with_slash = trailingslashit($real_path);
        $root_real_with_slash = trailingslashit($root_real);

        // Block purges inside WordPress directories.
        if (strpos($real_path_with_slash, $root_real_with_slash) === 0) {
            return new WP_Error(
                'unsafe_cache_path',
                __('Unsafe cache path: refusing to purge inside WordPress directories. Please set the Nginx cache path outside the WordPress installation.', 'fastcgi-cache-purge-and-preload-nginx')
            );
        }

        // Also block parent directories that contain a WordPress path.
        if (strpos($root_real_with_slash, $real_path_with_slash) === 0) {
            return new WP_Error(
                'unsafe_cache_path',
                __('Unsafe cache path: refusing to purge a parent directory that contains WordPress files. Please set the Nginx cache path to a dedicated cache-only location.', 'fastcgi-cache-purge-and-preload-nginx')
            );
        }
    }

    return $real_path;
}

/**
 * Symlink-safe recursive delete for the cache tree.
 *
 * WP_Filesystem_Direct::delete($path, true) recurses through dirlist(), which uses
 * is_dir() and therefore FOLLOWS symlinks. A link planted anywhere inside the cache
 * tree made the purge delete the link target's contents (outside the cache).
 * This helper never follows links: a link is unlinked itself, its target is untouched.
 *
 * Tolerates concurrent removal (Nginx cache manager): a vanished path counts as deleted.
 *
 * Why raw PHP (is_link/scandir/unlink/rmdir) instead of WP_Filesystem or wp_delete_file():
 * - WP_Filesystem has no symlink-aware primitive. Its recursive delete() lists children via
 *   dirlist(), which types entries with is_dir() and therefore follows links. Core has tracked
 *   this for years without changing it (Trac #15134, #34067, #36710), so the API cannot be used
 *   to walk a tree that may contain planted links. The is_link() decision must be made on the
 *   exact entry being removed, which the API cannot express.
 * - wp_delete_file() runs the 'wp_delete_file' filter, which any plugin can use to rewrite or
 *   veto the path, and it only returns a status since WP 6.7 (this plugin supports 6.5+).
 *   A purge removes tens of thousands of entries; the safety of this routine depends on deleting
 *   exactly the entry it was given and on reliable success reporting.
 * Single known files elsewhere in the plugin still go through WP_Filesystem.
 *
 * @param string $path File, symlink or directory.
 * @return bool True when $path no longer exists afterwards.
 */
function nppp_delete_tree(string $path): bool {
    // Trailing slash would make is_link() resolve the link target. Never operate on "/".
    $path = rtrim($path, '/');
    if ($path === '') {
        return false;
    }

    // Anything that is not a real directory (symlink to file/dir/dangling, regular file,
    // FIFO, socket, or an already-vanished path) is removed as an entry, never followed.
    if (is_link($path) || !is_dir($path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Intentional, see nppp_delete_tree() docblock: wp_delete_file() applies a path filter and has no return value before WP 6.7.
        if (@unlink($path)) {
            return true;
        }
        clearstatcache(true, $path);
        return !is_link($path) && !file_exists($path);
    }

    $entries = @scandir($path);
    if ($entries !== false) {
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            nppp_delete_tree($path . '/' . $name);
        }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Intentional, see nppp_delete_tree() docblock: WP_Filesystem has no symlink-safe recursive delete (Trac #36710).
    if (@rmdir($path)) {
        return true;
    }
    clearstatcache(true, $path);
    return !file_exists($path);
}

// Purge cache with WP_Filesystem
function nppp_wp_purge($directory_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    // Check directory exists first — also prevents realpath() inside
    // nppp_validate_purge_path() returning false for a missing directory,
    // which would incorrectly surface as 'directory_traversal' instead of
    // 'directory_not_found' to the caller.
    if (!$wp_filesystem->is_dir($directory_path)) {
        return new WP_Error('directory_not_found', __('Directory not found', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Resolve and validate the absolute path — realpath() safe now
    $validation = nppp_validate_purge_path($directory_path);
    if (is_wp_error($validation)) {
        return $validation;
    }

    // Protected Nginx cache runtime temp folders
    // Deleting them on runtime will break Nginx cache completely
    $protected_folders = ['client_temp', 'scgi_temp', 'uwsgi_temp', 'fastcgi_temp', 'proxy_temp'];

    // Step 1: Confirm cache exists.
    // LEAVES_ONLY     → SPL yields only files, skips directory entries entirely.
    // nppp_read_head  → reads only first 4096 bytes (sufficient to reach KEY: line
    //                   which sits after the nginx binary file header).
    // break           → stops after the very first match, minimises I/O.
    $has_cache = false;
    try {
        $scan = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory_path, RecursiveDirectoryIterator::SKIP_DOTS),
                function ($entry) use ($protected_folders) {
                    if ($entry->isDir() && in_array($entry->getFilename(), $protected_folders, true)) {
                        return false;
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($scan as $file) {
            $head = nppp_read_head($wp_filesystem, $file->getPathname(), 4096);
            if ($head !== '' && preg_match('/^KEY:\s/m', $head)) {
                $has_cache = true;
                break;
            }
        }
    } catch (UnexpectedValueException $e) {
        // Directory or subdirectory is unreadable
        return new WP_Error(
            'permission_error',
            /* translators: %s: cache directory path */
            sprintf(__('Permission denied while reading cache directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $directory_path)
        );
    }

    if (!$has_cache) {
        return new WP_Error('empty_directory', __('Directory is empty', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Step 2: Purge cache. nppp_delete_tree() handles recursion (symlink-safe),
    // so we only need to iterate one level here.
    // SPL caches type info from the directory read so getPathname() costs nothing extra.
    try {
        $dir = new DirectoryIterator($directory_path);
        foreach ($dir as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            // Bare filename comparison — no path construction, no strpos prefix bug
            if (in_array($entry->getFilename(), $protected_folders, true)) {
                continue;
            }

            $entry_path = $entry->getPathname();

            // nppp_delete_tree() instead of $wp_filesystem->delete($entry_path, true):
            // core's recursive delete follows symlinks out of the cache tree.
            $deleted = nppp_delete_tree($entry_path);

            if (!$deleted) {
                // Re-check after failure, the cache may have been deleted by Nginx's
                // cache manager or an external process between our detect and delete passes.
                // Only surface permission_error if the target genuinely still exists.
                if (is_link($entry_path) || $wp_filesystem->is_file($entry_path) || $wp_filesystem->is_dir($entry_path)) {
                    return new WP_Error(
                        'permission_error',
                        /* translators: %s: file or directory path */
                        sprintf(__('Permission denied while deleting file or directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $entry_path)
                    );
                }
            }
        }
    } catch (UnexpectedValueException $e) {
        return new WP_Error(
            'permission_error',
            /* translators: %s: cache directory path */
            sprintf(__('Permission denied while reading cache directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $directory_path)
        );
    }

    return true;
}

// Remove a directory using WP_Filesystem
function nppp_wp_remove_directory($directory_path, $recursive = true) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Check if the directory exists before attempting to remove it
    if ($wp_filesystem->is_dir($directory_path)) {
        // Validate the purge path
        $validation = nppp_validate_purge_path($directory_path);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Attempt to remove the directory
        // Symlink-safe: WP_Filesystem_Direct::delete() would follow links out of the tree.
        $result = $recursive
            ? nppp_delete_tree($directory_path)
            : $wp_filesystem->delete($directory_path, false);

        if ($result === false) {
            // Error occurred while removing directory
            return new WP_Error('remove_directory_error', __('Error removing directory.', 'fastcgi-cache-purge-and-preload-nginx'));
        }

        // Directory removed successfully
        return true;
    } else {
        // Directory does not exist
        return new WP_Error('directory_not_found', __('Directory not found.', 'fastcgi-cache-purge-and-preload-nginx'));
    }
}

// Check target path  subfolders and files for read permisson
function nppp_is_directory_readable($directory_path) {
    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Check if the directory is readable
    if (!$wp_filesystem->is_readable($directory_path)) {
        return false;
    }

    // Get all files and directories within the target directory
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory_path),
        RecursiveIteratorIterator::SELF_FIRST
    );

    // Check the readability of each file and directory
    foreach ($items as $item) {
        // Skip "." and ".." directories
        if (in_array($item->getBasename(), array('.', '..'))) {
            continue;
        }

        // Check if the file or directory is readable
        if (!$wp_filesystem->is_readable($item->getPathname())) {
            return false;
        }
    }

    // All files and directories are readable
    return true;
}

// Optimized in v2.1.6: Replaced expensive recursive iterator scanning with a
// lightweight write/delete probe. The function name is retained for
// backward compatibility, but it now performs a targeted permission test.
function nppp_check_permissions_recursive($path) {
    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // FP1: First check if the main path is readable and writable
    if (!$wp_filesystem->is_readable($path) || !$wp_filesystem->is_writable($path)) {
        return false;
    }

    // FP2 — Probe delete: write + unlink a sentinel file inside the deepest
    // reachable cache subdirectory.
    $probe_dir  = rtrim( $path, '/' );
    $probe_ok   = false;

    try {
        $top = new DirectoryIterator( $probe_dir );
        foreach ( $top as $d1 ) {
            if ( $d1->isDot() || ! $d1->isDir() ) { continue; }
            $d1_path = $d1->getPathname();
            $mid     = new DirectoryIterator( $d1_path );
            foreach ( $mid as $d2 ) {
                if ( $d2->isDot() || ! $d2->isDir() ) { continue; }
                $probe_dir = $d2->getPathname();
                break 2;
            }
            $probe_dir = $d1_path;
            break;
        }
    } catch ( Exception $e ) {
        // Still perm issue
        return false;
    }

    // Unpredictable name: a pre-planted ".nppp_probe_<pid>" symlink in the cache tree
    // would otherwise make put_contents() truncate and chmod its target.
    $probe_path = rtrim( $probe_dir, '/' ) . '/.nppp_probe_' . getmypid() . '_' . wp_generate_password( 12, false );

    if ( $wp_filesystem->put_contents( $probe_path, '', FS_CHMOD_FILE ) ) {
        $probe_ok = $wp_filesystem->delete( $probe_path );
        if ( $wp_filesystem->exists( $probe_path ) ) {
            $wp_filesystem->delete( $probe_path );
        }
    }

    return $probe_ok;
}
