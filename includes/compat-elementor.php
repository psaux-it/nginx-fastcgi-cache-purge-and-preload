<?php
/**
 * Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: Elementor integration — purge Nginx cache when Elementor saves content or regenerates CSS.
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined('ABSPATH') ) { exit; }

// simple per-request de-dupe so we don't purge twice if both hooks run.
function nppp__el_mark_purged( $set = null ) {
        static $did = false;
        if ( $set === true ) { $did = true; }
        return $did;
}

// Elementor-specific purge triggers
add_action('plugins_loaded', function () {
    if ( ! defined('ELEMENTOR_VERSION') ) return;

    // When an Elementor document is saved
    add_action('elementor/editor/after_save', 'nppp__el_after_save', 10, 2);
    add_action('elementor/document/after_save', 'nppp__el_document_after_save', 10, 2);

    // When Elementor clears its own files/CSS
    add_action('elementor/core/files/clear_cache', 'nppp__el_clear_files');
});

function nppp__el_after_save( $post_id, $editor_data ) {
    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( nppp__el_mark_purged() ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $pidfile    = rtrim(dirname(plugin_dir_path(__FILE__)), '/') . '/cache_preload.pid';
    $tmp        = rtrim($cache_path, '/') . '/tmp';

    if ( get_post_status( $post_id ) !== 'publish' ) {
        return;
    }

    // Elementor template type (header/footer/etc)
    $tpl_type = get_post_meta($post_id, '_elementor_template_type', true);

    // Global parts change => purge all
    if ( in_array($tpl_type, ['header','footer','single','archive','popup'], true) ) {
        nppp_purge($cache_path, $pidfile, $tmp, false, false, true);
        nppp__el_mark_purged(true);
        return;
    }

    // Regular page => purge just that URL
    $url = get_permalink($post_id);
    if ($url) {
        nppp_purge_single($cache_path, $url, true);
        nppp__el_mark_purged(true);
    }
}

function nppp__el_document_after_save( $document, $data ) {
    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( nppp__el_mark_purged() ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $pidfile    = rtrim(dirname(plugin_dir_path(__FILE__)), '/') . '/cache_preload.pid';
    $tmp        = rtrim($cache_path, '/') . '/tmp';

    $post_id = method_exists($document, 'get_main_id') ? $document->get_main_id() : 0;

    // Theme parts (Header/Footer/Single/Archive…) => purge all
    if ( class_exists('\Elementor\Core\Theme\Documents\Theme_Document')
         && $document instanceof \Elementor\Core\Theme\Documents\Theme_Document ) {
        nppp_purge($cache_path, $pidfile, $tmp, false, false, true);
        nppp__el_mark_purged(true);
        return;
    }

    if ($post_id) {
        $url = get_permalink($post_id);
        if ($url) {
            nppp_purge_single($cache_path, $url, true);
            nppp__el_mark_purged(true);
        }
    }
}

function nppp__el_clear_files() {
    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $pidfile    = rtrim(dirname(plugin_dir_path(__FILE__)), '/') . '/cache_preload.pid';
    $tmp        = rtrim($cache_path, '/') . '/tmp';

    // Elementor regenerated CSS → purge all (site-wide impact)
    nppp_purge($cache_path, $pidfile, $tmp, false, false, true);
    nppp__el_mark_purged(true);
}
