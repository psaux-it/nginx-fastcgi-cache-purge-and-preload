<?php
/**
 * Gutenberg cache purge integration for Nginx Cache Purge Preload
 * Description: Purges related Nginx cache entries when block editor content is saved, updated, unpublished, or trashed via REST.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined('ABSPATH') ) exit;

// Hook rest_after_insert_* and rest_delete_* for all public post types that expose REST.
// Only registered when auto-purge is enabled
//
// Two separate hooks are required because Gutenberg uses different HTTP methods:
//   - Publish / Update / Switch-to-Draft / Set-to-Private   →  PATCH  →  update_item()  →  rest_after_insert_{type}
//   - Move to Trash / Force Delete                          →  DELETE →  delete_item()  →  rest_delete_{type}

if ( $nppp_auto_purge ) {
    // Capture the pre-transition post status at priority 0 so it is always
    // available when rest_after_insert fires later in the same request.
    // This allows nppp__rest_after_insert to detect first-time publishes
    // and skip the unnecessary purge (no cache entry exists yet).
    add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
        nppp__gut_old_status( $post->ID, $old_status );
    }, 0, 3 );

    $nppp_register_rest_hooks = function () {
        foreach ( get_post_types(['public' => true], 'objects') as $obj ) {
            if ( empty($obj->show_in_rest) ) continue;
            add_action("rest_after_insert_{$obj->name}", 'nppp__rest_after_insert', 10, 3);
            add_action("rest_delete_{$obj->name}",       'nppp__rest_delete',       10, 3);
        }
    };

    if ( did_action('init') ) {
        // Bootstrap was loaded after init (e.g. via rest_pre_dispatch for
        // Application Password or WC consumer key requests). init has already
        // fired so add_action('init') would never run — register hooks directly.
        $nppp_register_rest_hooks();
    } else {
        // Normal execution path — init has not fired yet.
        // Wait for init so get_post_types() returns all registered post types.
        add_action('init', $nppp_register_rest_hooks, 20);
    }
}

/**
 * Capture / retrieve the pre-transition post status for a given post ID.
 *
 * Call with both args to set (via the transition_post_status hook at priority 0).
 * Call with only $post_id to retrieve. Set-once per post ID per request so a
 * double-transition (e.g. future → pending → publish via cron) does not
 * overwrite the truly original status.
 *
 * @param int         $post_id    Post ID.
 * @param string|null $old_status Old status to store, or null to retrieve only.
 * @return string|null Stored old status, or null if not yet captured.
 */
function nppp__gut_old_status( int $post_id, ?string $old_status = null ): ?string {
    static $store = [];
    if ( $old_status !== null && ! isset( $store[ $post_id ] ) ) {
        $store[ $post_id ] = $old_status;
    }
    return $store[ $post_id ] ?? null;
}

/**
 * Get the correct cached URL for a post that may have a non-publish status.
 *
 * @param WP_Post $post Post object (may have any status).
 * @return string|false The pretty permalink, or false on failure.
 */
function nppp__get_published_permalink_gut( WP_Post $post ) {
    if ( $post->post_status === 'publish' ) {
        return get_permalink( $post->ID );
    }

    // For draft / pending / trash / any non-publish non-private status:
    // Clone post in-memory and temporarily set status to 'publish' so
    // get_permalink() computes the pretty URL instead of falling back to ?p=ID.
    $published_clone               = clone $post;
    $published_clone->post_status  = 'publish';

    // WP renames post_name to slug__trashed via wp_add_trashed_suffix_to_post_name()
    if ( $post->post_status === 'trash' ) {
        $published_clone->post_name = preg_replace( '/__trashed$/', '', $post->post_name );
    }

    return get_permalink( $published_clone );
}

/**
 * Handles: Gutenberg publish, update, switch-to-draft, set-to-private.
 * Fired via: REST PATCH → WP_REST_Posts_Controller::update_item() → rest_after_insert_{type}
 * Fired via: REST POST  → WP_REST_Posts_Controller::create_item() → rest_after_insert_{type}
 *
 * @param WP_Post         $post     Post object with new/current status.
 * @param WP_REST_Request $request  Full REST request.
 * @param bool            $creating True when creating, false when updating.
 */
function nppp__rest_after_insert( $post, $request, $creating ) {
    if ( ! ($post instanceof WP_Post) ) return;

    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( ($opts['nppp_autopurge_posts'] ?? 'no') !== 'yes' ) return;

    $is_published = ( $post->post_status === 'publish' );

    // Skip purge when the post is being published for the very first time.
    // No cache entry can exist yet for a URL that was never publicly served,
    // so calling nppp_purge_single would be a no-op with unnecessary overhead.
    //
    // Two sub-cases handled:
    //   $creating === true  → REST POST created the post directly as 'publish'.
    //   $creating === false → REST PATCH changed status from a non-publish state
    //                         (e.g. auto-draft → publish on the first "Publish" click
    //                         in the Gutenberg editor).
    //
    // The pre-transition old_status is captured by the transition_post_status hook
    // at priority 0, which fires inside wp_insert_post / wp_update_post — always
    // before rest_after_insert in the same PHP request.
    if ( $is_published ) {
        if ( $creating ) {
            // REST POST: brand-new post created directly as published. Nothing cached.
            return;
        }
        $old_status = nppp__gut_old_status( $post->ID );
        if ( $old_status !== null && $old_status !== 'publish' ) {
            // First-time publish via PATCH (was auto-draft / draft / pending / future).
            // The URL has never been served from cache. Nothing to purge.
            return;
        }
    }

    if ( ! $is_published ) {
        // Purge when Gutenberg sends PATCH with status=draft/private/pending.
        // $post->post_status is already the NEW status at this point, so we cannot
        // check if it was previously published via $post alone. We use $request['status']
        // which contains the status value the client explicitly requested to change to.
        $requested_status  = $request['status'] ?? '';
        $being_unpublished = in_array(
            $requested_status,
            ['draft', 'private', 'pending'],
            true
        );
        if ( ! $being_unpublished ) return;
    }

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';

    // Use the helper to get the pretty URL regardless of current post_status.
    // For publish: returns get_permalink($post->ID) directly.
    // For draft/pending: clones post with status='publish' to bypass
    // For private: get_permalink works without clone
    $url = nppp__get_published_permalink_gut( $post );

    if ( $url ) {
        nppp_purge_single( $cache_path, $url, true );
    }
}

/**
 * Handles: Gutenberg "Move to Trash" and permanent force-delete.
 * Fired via: REST DELETE → WP_REST_Posts_Controller::delete_item() → rest_delete_{type}
 *
 * @param WP_Post          $post     Trashed or deleted post object.
 * @param WP_REST_Response $response REST response (for force-delete: contains pre-delete snapshot).
 * @param WP_REST_Request  $request  Full REST request.
 */
function nppp__rest_delete( $post, $response, $request ) {
    if ( ! ($post instanceof WP_Post) ) return;

    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( ($opts['nppp_autopurge_posts'] ?? 'no') !== 'yes' ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';

    if ( $post->post_status === 'trash' ) {
        // PATH A: Normal "Move to Trash" (Gutenberg's default DELETE request).
        // Verify the post was published before being trashed.
        $pre_trash_status = get_post_meta( $post->ID, '_wp_trash_meta_status', true );
        if ( $pre_trash_status !== 'publish' ) return;

        // Post still exists in DB with status='trash' — use clone trick to get pretty URL.
        // Direct get_permalink() on a trashed post returns ?p=ID because 'trash' is an
        // internal status and wp_force_plain_post_permalink() returns true for it.
        $url = nppp__get_published_permalink_gut( $post );

    } else {
        // PATH B: Permanent force-delete (DELETE?force=true).
        if ( $post->post_status !== 'publish' ) return;

        // Post is gone from DB — get_permalink($post->ID) returns false.
        // Use the 'link' field from the pre-delete response snapshot instead.
        $data = ( $response instanceof WP_REST_Response ) ? $response->get_data() : [];
        $url  = $data['previous']['link'] ?? '';
    }

    if ( $url ) {
        nppp_purge_single( $cache_path, $url, true );
    }
}
