<?php
/**
 * Related URL purge helpers for Nginx Cache Purge Preload
 * Description: Purges and optionally preloads related archives when singular content is purged.
 *              whenever a single post/page is purged (via auto purge, front-end action, or Advanced tab).
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Return related URLs for a primary single page URL, based on plugin options.
function nppp_get_related_urls_for_single(string $primary_url): array {
    $settings = get_option( 'nginx_cache_settings', array() );
    $urls     = array();

    // Flags (keep existing option keys)
    $include_home = ! empty( $settings['nppp_related_include_home'] ) && $settings['nppp_related_include_home'] === 'yes';
    $include_shop = ! empty( $settings['nppp_related_apply_manual'] ) && $settings['nppp_related_apply_manual'] === 'yes'; // repurposed to "Shop"
    $include_cat  = ! empty( $settings['nppp_related_include_category'] ) && $settings['nppp_related_include_category'] === 'yes';

    // 1) Home page (if enabled)
    if ( $include_home ) {
        $urls[] = home_url( '/' );
    }

    // Resolve post from URL (posts, pages, products, CPTs)
    $post_id = url_to_postid( $primary_url );
    if ( $post_id ) {
        $post_type = get_post_type( $post_id );

        // 2) WooCommerce Shop page (if enabled, and this is a product)
        if ( $include_shop && 'product' === $post_type && function_exists( 'wc_get_page_id' ) ) {
            $shop_id = (int) wc_get_page_id( 'shop' );
            if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
                $shop_url = get_permalink( $shop_id );
                if ( $shop_url ) {
                    $urls[] = $shop_url;
                }
            }
        }

        // 3) Category archives (posts => 'category', products => 'product_cat')
        if ( $include_cat ) {
            $taxonomy = ( 'product' === $post_type ) ? 'product_cat' : 'category';

            // Only act on public taxonomies with archives
            $tax_obj = get_taxonomy( $taxonomy );
            if ( $tax_obj && ! empty( $tax_obj->public ) && false !== $tax_obj->rewrite ) {
                $terms = get_the_terms( $post_id, $taxonomy );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $link = get_term_link( $term, $taxonomy );
                        if ( ! is_wp_error( $link ) && ! empty( $link ) ) {
                            $urls[] = $link;
                        }
                    }
                }
            }
        }
    }

    // Normalization
    $primary_norm = user_trailingslashit($primary_url, 'single');

    // keep only non-empty, valid absolute URLs
    $urls = array_filter($urls, static function ($u) {
        return is_string($u) && $u !== '' && false !== wp_http_validate_url($u);
    } );

    // normalize trailing slashes using site preference
    $urls = array_map(static function ($u) {
        return user_trailingslashit($u, 'single');
    }, $urls);

    // dedupe and remove the primary itself
    $urls = array_values(array_unique(array_diff($urls, array($primary_norm))));

    return apply_filters('nppp_related_urls_for_single', $urls, $primary_url, $settings);
}

// Purge multiple URLs from Nginx cache in ONE directory walk
// Returns array keyed by URL => ['found'=>bool,'deleted'=>bool].
function nppp_purge_urls_silent(string $nginx_cache_path, array $urls): array {
    $results = [];
    $pending = [];

    $wp_filesystem = nppp_initialize_wp_filesystem();

    foreach ($urls as $url) {
        $results[$url] = ['found' => false, 'deleted' => false];

        if ($wp_filesystem === false) continue;
        if (filter_var($url, FILTER_VALIDATE_URL) === false) continue;

        $url_to_search = preg_replace('#^https?://#', '', $url);
        $pending[$url_to_search] = [
            'original' => $url,
            'decoded'  => rawurldecode($url),
            'found'    => false,
            'deleted'  => false,
        ];
    }

    if ($wp_filesystem === false || empty($pending)) {
        return $results;
    }

    // URL→filepath index fast-path for related URLs.
    // Iterate $pending and look each up in the index.
    // Stale entries (file gone / bad perms / failed validation) are left
    // in $pending so the iterator below handles them as a fallback.
    // If all pending targets resolve via index, the iterator never opens.
    $nppp_rel_index = get_option('nppp_url_filepath_index');
    if ( is_array( $nppp_rel_index ) ) {
        foreach ( array_keys( $pending ) as $nppp_rel_key ) {
            if ( ! isset( $nppp_rel_index[ $nppp_rel_key ] ) ) {
                continue;
            }

            $nppp_rel_path  = $nppp_rel_index[ $nppp_rel_key ];
            $nppp_rel_entry = $pending[ $nppp_rel_key ];

            if ( ! $wp_filesystem->exists( $nppp_rel_path )
                || ! $wp_filesystem->is_readable( $nppp_rel_path )
                || ! $wp_filesystem->is_writable( $nppp_rel_path )
                || nppp_validate_path( $nppp_rel_path, true ) !== true
            ) {
                continue; // stale — leave in $pending for iterator fallback
            }

            $nppp_rel_deleted = (bool) $wp_filesystem->delete( $nppp_rel_path );

            if ( $nppp_rel_deleted ) {
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: related page URL */
                    __( 'SUCCESS ADMIN: Nginx cache purged for related page %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $nppp_rel_entry['decoded']
                ), true, false );
            } else {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: related page URL */
                    __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for related page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                    $nppp_rel_entry['decoded']
                ), true, false );
            }

            $results[ $nppp_rel_entry['original'] ] = [
                'found'   => true,
                'deleted' => $nppp_rel_deleted,
            ];

            unset( $pending[ $nppp_rel_key ] );
        }
        unset( $nppp_rel_index, $nppp_rel_key, $nppp_rel_path, $nppp_rel_entry, $nppp_rel_deleted );
    }

    nppp_display_admin_notice( 'info', sprintf(
        /* translators: %1$d: number of URLs resolved via index, %2$d: number falling back to scan */
        __( 'INFO INDEX: Related purge — %1$d resolved via index, %2$d falling back to scan', 'fastcgi-cache-purge-and-preload-nginx' ),
        count( $urls ) - count( $pending ),
        count( $pending )
    ), true, false );

    // All related URLs resolved via index — iterator not needed.
    if ( empty( $pending ) ) {
        return $results;
    }

    $settings = get_option('nginx_cache_settings');
    $regex = isset($settings['nginx_cache_key_custom_regex'])
             ? base64_decode($settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    $head_bytes_primary  = (int) apply_filters('nppp_locate_head_bytes', 4096);
    $head_bytes_fallback = (int) apply_filters('nppp_locate_head_bytes_fallback', 32768);

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $regex_tested = false;
        foreach ($it as $file) {
            if (empty($pending)) break;
            $pathname = $file->getPathname();

            // nppp_purge_urls_silent() is only reached after nppp_purge_single()
            // completed its scan without any permission issues. So if we hit an
            // unreadable/unwritable file here, it means the cache directory permission
            // integrity broke BETWEEN the two scans — most likely a bindfs sync failure
            // between WEBSERVER-USER and PHP-FPM-USER mid-operation.
            // We cannot safely identify or delete remaining targets — abort all pending.

            if (!$file->isReadable() || !$file->isWritable()) {
                foreach ($pending as $entry) {
                    nppp_display_admin_notice('error', sprintf(
                         /* translators: %s: related page URL */
                         __('ERROR PERMISSION: Nginx cache purge for related page %s was aborted — a permission issue was detected in the cache directory. This may indicate a cache path integrity problem (e.g. broken bindfs sync). Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx'),
                         $entry['decoded']
                    ), true, false);
                }
                break;
            }

            $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_primary);
            if ($content === '') { continue; }

            $match = [];
            if (!preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                if (strlen($content) >= $head_bytes_primary) {
                    $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_fallback);
                    if ($content === '' || !preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                strpos($content, 'Status: 302 Found') !== false) {
                continue;
            }

            $key_line = $match[1];
            if (strpos($key_line, 'GET') === false) {
                continue;
            }

            if (!$regex_tested) {
                if (!(preg_match($regex, $content, $tmp) && isset($tmp[1], $tmp[2]))) {
                    break;
                }
                $regex_tested = true;
            }

            if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
                $constructed = trim($matches[1]) . trim($matches[2]);

                if (!isset($pending[$constructed])) {
                    continue;
                }

                $entry = &$pending[$constructed];
                $entry['found'] = true;

                $validation = nppp_validate_path($pathname, true);
                if ($validation !== true) {
                    $results[$entry['original']] = ['found' => true, 'deleted' => false];
                    unset($pending[$constructed]);
                    continue;
                }

                $deleted = (bool) $wp_filesystem->delete($pathname);
                $entry['deleted'] = $deleted;

                if ($deleted) {
                    // Write-back: persist url→path in the permanent index only on
                    // successful delete — confirms path was validly operated on.
                    // nginx re-caches to same deterministic path on next visit.
                    $nppp_wb_index = get_option( 'nppp_url_filepath_index' );
                    $nppp_wb_index = is_array( $nppp_wb_index ) ? $nppp_wb_index : [];
                    $nppp_wb_index[ $constructed ] = $pathname;
                    update_option( 'nppp_url_filepath_index', $nppp_wb_index, false );
                    unset( $nppp_wb_index );

                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %s: related page URL */
                        __( 'INFO INDEX WRITE-BACK: Index updated after scan for related: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $entry['decoded']
                    ), true, false );

                    nppp_display_admin_notice('success', sprintf(
                        /* translators: %s: related page URL */
                        __('SUCCESS ADMIN: Nginx cache purged for related page %s', 'fastcgi-cache-purge-and-preload-nginx'),
                        $entry['decoded']
                    ), true, false);
                } else {
                    nppp_display_admin_notice('error', sprintf(
                        /* translators: %s: related page URL */
                        __('ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for related page %s. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx'),
                        $entry['decoded']
                    ), true, false);
                }

                $results[$entry['original']] = ['found' => $entry['found'], 'deleted' => $entry['deleted']];
                unset($pending[$constructed]);
            }
        }
    } catch (Exception $e) {
        // ignore silently
    }

    return $results;
}

// Fire-and-forget tiny warmups for a few URLs without touching PID/Status flow.
// We use WP HTTP API here to avoid PID collisions with wget-based preloader.
function nppp_preload_urls_fire_and_forget(array $urls): void {
    if (empty($urls)) return;

    $settings = get_option('nginx_cache_settings');
    $preload_mobile = !empty($settings['nginx_cache_auto_preload_mobile']) && $settings['nginx_cache_auto_preload_mobile'] === 'yes';

    // Desktop UA always
    $headers_desktop = array('User-Agent' => NPPP_USER_AGENT);
    // Optional mobile UA
    $headers_mobile  = array('User-Agent' => NPPP_USER_AGENT_MOBILE);

    foreach ($urls as $u) {
        if (false === wp_http_validate_url($u)) {
            continue;
        }

        // Use the canonical value produced upstream.
        wp_remote_get($u, array(
            'timeout'     => 3,
            'redirection' => 1,
            'blocking'    => false,
            'headers'     => $headers_desktop,
        ));

        if ($preload_mobile) {
            wp_remote_get($u, array(
                'timeout'     => 3,
                'redirection' => 1,
                'blocking'    => false,
                'headers'     => $headers_mobile,
            ));
        }
    }
}
