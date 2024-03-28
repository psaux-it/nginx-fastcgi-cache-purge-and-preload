<?php
/**
 * WP_Filesytem functions for Nginx FastCGI Cache Purge and Preload
 * Description: This file contains WP_Filesytem functions for purge and preload actions.
 * Version: 1.0.3
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Verify WP file-system credentials and initialize WP_Filesystem
function initialize_wp_filesystem() {
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

    return false; // Return false if initialization failed
}

// Perform file operations (delete, read, write, create, append)
function perform_file_operation($file_path, $operation, $data = null) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
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
            return false; // Return false if file already exists
        case 'append':
            $current_content = $wp_filesystem->get_contents($file_path);
            $updated_content = $current_content . "\n" . $data; // Append with newline
            return $wp_filesystem->put_contents($file_path, $updated_content, FS_CHMOD_FILE);
        default:
            return false; // Return false for unsupported operation
    }
}

// Purge cache with WP_Filesystem
function wp_purge($directory_path) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return new WP_Error('filesystem_error', 'WP_Filesystem initialization failed');
    }

    // Check if the directory exists before attempting to remove its contents
    if ($wp_filesystem->is_dir($directory_path)) {
        // Get directory contents
        $contents = $wp_filesystem->dirlist($directory_path);

        // Delete files and directories in the directory
        if (!empty($contents)) {
            foreach ($contents as $file) {
                $file_path = trailingslashit($directory_path) . $file['name'];
                if ($wp_filesystem->is_file($file_path) || $wp_filesystem->is_dir($file_path)) {
                    // Attempt to delete file or directory
                    $deleted = $wp_filesystem->delete($file_path, true);
                    if (!$deleted) {
                        return new WP_Error('permission_error', 'Permission denied while deleting file or directory: ' . $file_path);
                    }
                }
            }
        } else {
            return new WP_Error('empty_directory', 'Directory is empty');
        }

        return true; // Contents removed successfully
    } else {
        return new WP_Error('directory_not_found', 'Directory not found');
    }
}

// Remove a directory using WP_Filesystem
function wp_remove_directory($directory_path, $recursive = true) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
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
