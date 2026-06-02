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
