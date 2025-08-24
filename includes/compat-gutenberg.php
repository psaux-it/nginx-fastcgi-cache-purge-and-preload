<?php
/**
 * Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: Gutenberg integration — purge Nginx cache when posts are saved via REST (block editor).
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined('ABSPATH') ) exit;

// Hook rest_after_insert_* for all public post types that expose REST.
add_action('init', function () {
    foreach ( get_post_types(['public' => true], 'objects') as $obj ) {
        if ( empty($obj->show_in_rest) ) continue;
        add_action("rest_after_insert_{$obj->name}", 'nppp__rest_after_insert', 10, 3);
    }
}, 20);

// Purge the single URL after a REST save if the post is published.
function nppp__rest_after_insert( $post, $request, $creating ) {
    if ( ! ($post instanceof WP_Post) ) return;

    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( $post->post_status !== 'publish' ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $url = get_permalink($post->ID);
    if ($url) {
        nppp_purge_single($cache_path, $url, true);
    }
}
