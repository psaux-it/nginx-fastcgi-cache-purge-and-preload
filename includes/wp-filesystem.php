<?php
/**
 * WP_Filesytem functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains WP_Filesytem functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Verify WP file-system credentials and initialize WP_Filesystem
function nppp_initialize_wp_filesystem() {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Verify WP file-system credentials.
    $verified_credentials = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);

    if (is_wp_error($verified_credentials)) {
        return $verified_credentials;
    }

    // Initialize WP_Filesystem
    if (WP_Filesystem($verified_credentials)) {
        global $wp_filesystem;
        return $wp_filesystem;
    }

    return false;
}

// Perform file operations (delete, read, write, create, append)
function nppp_perform_file_operation($file_path, $operation, $data = null) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_die('Failed to initialize WP Filesystem.');
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
        return new WP_Error('filesystem_error', 'WP_Filesystem initialization failed');
    }

    // Check if the directory exists before attempting to remove its contents
    if ($wp_filesystem->is_dir($directory_path)) {
        // Get directory contents
        $contents = $wp_filesystem->dirlist($directory_path);

        // Check permission errors first
        if ($contents === false) {
            return new WP_Error('permission_error', 'Permission denied while deleting file or directory: ' . $directory_path);
        // If we have permisson to list directory and it is not empty
        // try to delete files and directories in the directory.
        } elseif (!empty($contents)) {
            foreach ($contents as $file) {
                $file_path = trailingslashit($directory_path) . $file['name'];
                if ($wp_filesystem->is_file($file_path) || $wp_filesystem->is_dir($file_path)) {
                    // Attempt to delete file or directory
                    $deleted = $wp_filesystem->delete($file_path, true);
                    // Check we throw in permisson errors
                    if (!$deleted) {
                        return new WP_Error('permission_error', 'Permission denied while deleting file or directory: ' . $file_path);
                    }
                }
            }
        } else {
            return new WP_Error('empty_directory', 'Directory is empty');
        }

        // Contents removed successfully
        return true;
    } else {
        return new WP_Error('directory_not_found', 'Directory not found');
    }
}

// Remove a directory using WP_Filesystem
function nppp_wp_remove_directory($directory_path, $recursive = true) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_die('Failed to initialize WP Filesystem.');
    }

    // Check if the directory exists before attempting to remove it
    if ($wp_filesystem->is_dir($directory_path)) {
        // Attempt to remove the directory
        $result = $wp_filesystem->delete($directory_path, $recursive);

        if ($result === false) {
            // Error occurred while removing directory
            return new WP_Error('remove_directory_error', 'Error removing directory.');
        }

        return true; // Directory removed successfully
    } else {
        // Directory does not exist
        return new WP_Error('directory_not_found', 'Directory not found.');
    }
}

// Check target path  subfolders and files for read permisson
function nppp_is_directory_readable($directory_path) {
    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
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
        return false;
    }

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
    } catch (UnexpectedValueException $e) {
        // Handle the directory access issue
        return false;
    }
}
