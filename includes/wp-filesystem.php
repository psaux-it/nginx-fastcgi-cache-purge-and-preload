<?php
/**
 * WP_Filesytem functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains WP_Filesytem functions for FastCGI Cache Purge and Preload for Nginx
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

// Custom logger function
function nppp_custom_error_log($message, $error_type = E_USER_WARNING) {
    if (defined('WP_DEBUG' ) && WP_DEBUG) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // Log to WordPress debug.log
            $sanitized_message = wp_strip_all_tags(wp_unslash($message));
            wp_trigger_error($sanitized_message, $error_type);
        }
    }
}

// Verify WP file-system credentials and initialize WP_Filesystem
function nppp_initialize_wp_filesystem() {
    global $wp_filesystem;

    // Return existing WP_Filesystem instance if already initialized
    if (!empty($wp_filesystem)) {
        return $wp_filesystem;
    }

    // Include the necessary file if WP_Filesystem doesn't exist
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Request filesystem credentials
    $credentials = request_filesystem_credentials(admin_url(''), '', false, false, null);

    // Handle credential request failure
    if (!$credentials || is_wp_error($credentials)) {
        nppp_custom_error_log(__('Unable to obtain filesystem credentials.', 'fastcgi-cache-purge-and-preload-nginx'));
        return false;
    }

    // Initialize the WP_Filesystem
    if (WP_Filesystem($credentials)) {
        global $wp_filesystem;
        if (!empty($wp_filesystem)) {
            return $wp_filesystem;
        } else {
            nppp_custom_error_log(__('Filesystem object is not set.', 'fastcgi-cache-purge-and-preload-nginx'));
            return false;
        }
    }

    nppp_custom_error_log(__('Could not initialize the filesystem.', 'fastcgi-cache-purge-and-preload-nginx'));
    return false;
}

// Perform file operations (delete, read, write, create, append)
function nppp_perform_file_operation($file_path, $operation, $data = null) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
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
            $current_content = $wp_filesystem->get_contents($file_path);
            $updated_content = $current_content . "\n" . $data;
            return $wp_filesystem->put_contents($file_path, $updated_content, FS_CHMOD_FILE);
        default:
            return false;
    }
}

// Purge cache with WP_Filesystem
function nppp_wp_purge($directory_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Resolve the absolute path
    $real_path = realpath($directory_path);

    // Check if the realpath is valid
    if ($real_path === false) {
        return new WP_Error('directory_traversal', __('Directory traversal detected or invalid path.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Ensure the resolved path doesn't traverse outside the intended directory structure
    if (strpos(rtrim($real_path, DIRECTORY_SEPARATOR), rtrim($directory_path, DIRECTORY_SEPARATOR)) !== 0) {
        return new WP_Error('directory_traversal', __('Directory traversal detected or invalid path.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Check for read and write permissions softly and recursive
    if (!$wp_filesystem->is_readable($directory_path) || !$wp_filesystem->is_writable($directory_path)) {
        // Translators: %s is the Nginx cache path
        return new WP_Error('permission_error', sprintf(__('Permission denied while reading or writing to the cache directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $directory_path));
    } elseif (!nppp_check_permissions_recursive($directory_path)) {
        // Translators: %s is the Nginx cache path
        return new WP_Error('permission_error', sprintf(__('Permission denied during recursive check of the cache directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $directory_path));
    }

    // Protected folders to be excluded, recursively
    $protected_folders = ['client_temp', 'scgi_temp', 'uwsgi_temp', 'fastcgi_temp', 'proxy_temp'];
    $protected_paths = array_map(function ($folder) use ($real_path) {
        return trailingslashit($real_path) . $folder;
    }, $protected_folders);

    // Recursive function to check if a path is protected
    $is_protected = function ($path) use ($protected_paths) {
        foreach ($protected_paths as $protected_path) {
            if (strpos(rtrim($path, DIRECTORY_SEPARATOR), rtrim($protected_path, DIRECTORY_SEPARATOR)) === 0) {
                return true;
            }
        }
        return false;
    };

    // Check if the cache directory exist before trying to purge cache
    if ($wp_filesystem->is_dir($directory_path)) {
        // Get cache directory contents
        $contents = $wp_filesystem->dirlist($directory_path);

        // Check permission errors
        if ($contents === false) {
            // Translators: %s is the Nginx cache path
            return new WP_Error('permission_error', sprintf(__('Permission denied while deleting file or directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $directory_path));
        // Ok, try to purge cache
        } elseif (!empty($contents)) {
            // First check purge needed
            try {
                $cache_iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory_path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                $has_files = '';
                foreach ($cache_iterator as $file) {
                    if ($wp_filesystem->is_file($file->getPathname())) {
                        // Read cache content
                        $file_content = $wp_filesystem->get_contents($file->getPathname());

                        // Validate cache exists
                        if (preg_match('/^KEY:/m', $file_content)) {
                            $has_files = 'found';
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                $has_files = 'error';
            }

            // No cache found, no need to purge
            if ($has_files !== 'found' && $has_files !== 'error') {
                return new WP_Error('empty_directory', __('Directory is empty', 'fastcgi-cache-purge-and-preload-nginx'));
            }

            // Cache found, purge now
            foreach ($contents as $file) {
                $file_path = trailingslashit($directory_path) . $file['name'];

                // Skip protected paths (recursively)
                if ($is_protected($file_path)) {
                    continue;
                }

                if ($wp_filesystem->is_file($file_path) || $wp_filesystem->is_dir($file_path)) {
                    // Attempt to purge cache
                    $deleted = $wp_filesystem->delete($file_path, true);
                    // Check we throw in permisson errors
                    if (!$deleted) {
                        // Translators: %s is the Nginx cache path
                        return new WP_Error('permission_error', sprintf(__('Permission denied while deleting file or directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $file_path));
                    }
                }
            }
        } else {
            // No cache found, no need to purge
            return new WP_Error('empty_directory', __('Directory is empty', 'fastcgi-cache-purge-and-preload-nginx'));
        }

        // Cache purged
        return true;
    } else {
        // No cache directory found
        return new WP_Error('directory_not_found', __('Directory not found', 'fastcgi-cache-purge-and-preload-nginx'));
    }
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
        // Attempt to remove the directory
        $result = $wp_filesystem->delete($directory_path, $recursive);

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

// Function to recursively check read and write permissions
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

    // First check if the main path is readable and writable
    if (!$wp_filesystem->is_readable($path) || !$wp_filesystem->is_writable($path)) {
        return false;
    }

    // Recursively check permission for all files in nginx cache path
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$wp_filesystem->is_readable($item) || !$wp_filesystem->is_writable($item)) {
                return false;
            }
        }
        return true;
    } catch (Exception $e) {
        // Handle the directory access issue
        return false;
    }
}
