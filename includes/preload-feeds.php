<?php
/**
 * Feed Preload control for Nginx Cache Purge and Preload
 * Description: Modifies the reject regex to include or exclude /feed endpoint based on the "Preload Feeds" toggle.
 * Version: 2.1.6
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 *
 * When the Preload Feeds feature is enabled, the /feed/ pattern is removed from the reject regex,
 * allowing the main site preload process to cache feeds. When disabled, /feed/ is re-added.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filter the reject regex based on the Preload Feeds option on the fly.
 */
function nppp_filter_reject_regex_for_feeds( string $reject_regex ): string {
    $options = get_option( 'nginx_cache_settings', [] );
    $preload_feeds_enabled = !empty( $options['nginx_cache_preload_feeds'] ) && $options['nginx_cache_preload_feeds'] === 'yes';

    // 1. Split the regex string into an array
    $rules = explode( '|', $reject_regex );

    if ( $preload_feeds_enabled ) {
        // 2a. If enabled, remove exactly '/feed/' from the array
        $rules = array_filter( $rules, function( $rule ) {
            return $rule !== '/feed/';
        });
    } else {
        // 2b. If disabled, add '/feed/' to the array (if it doesn't already exist)
        if ( ! in_array( '/feed/', $rules, true ) ) {
            $rules[] = '/feed/';
        }
    }

    // 3. Reassemble the array back into a pipe-separated
    return implode( '|', $rules );
}

/**
 * Dynamically discover associated feed URLs for a given post/page URL.
 * Designed to be called during single-URL preloading events.
 *
 * @param string $url The primary URL being preloaded.
 * @return array An array of related feed URLs.
 */
function nppp_get_related_feed_urls_for_preload( string $url ): array {
    $feed_urls = [];
    $post_id   = url_to_postid( $url );
    $is_home   = ( untrailingslashit( $url ) === untrailingslashit( home_url() ) );

    if ( $is_home ) {
        // Homepage gets the main RSS feed
        $feed_urls[] = get_feed_link( 'rss2' );
    } elseif ( $post_id > 0 ) {
        $post_type = (string) get_post_type( $post_id );

        // Main site feed (if the updated item is a standard post)
        if ( 'post' === $post_type ) {
            $feed_urls[] = get_feed_link( 'rss2' );
        }

        // Per-post comments feed
        if ( comments_open( $post_id ) || get_comments_number( $post_id ) > 0 ) {
            $comment_feed = get_post_comments_feed_link( $post_id );
            if ( ! empty( $comment_feed ) ) {
                $feed_urls[] = $comment_feed;
            }
        }

        // Taxonomy RSS feeds related strictly to this single post
        $tax_objects = get_object_taxonomies( $post_type, 'objects' );
        foreach ( $tax_objects as $tax_obj ) {
            if ( empty( $tax_obj->public ) || false === $tax_obj->rewrite ) {
                continue;
            }
            $terms = get_the_terms( $post_id, $tax_obj->name );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $feed_link = get_term_feed_link( $term->term_id, $tax_obj->name );
                if ( $feed_link && ! is_wp_error( $feed_link ) ) {
                    $feed_urls[] = $feed_link;
                }
            }
        }
    }

    // Return clean, unique URLs
    return array_unique( array_filter( $feed_urls ) );
}
