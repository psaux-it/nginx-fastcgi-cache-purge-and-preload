<?php
/**
 * Related URL purge helpers for Nginx Cache Purge Preload
 * Description: Purges and optionally preloads related archives when singular content is purged.
 *              whenever a single post/page is purged (via auto purge, front-end action, or Advanced tab).
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Return related URLs for a primary URL, based on plugin options.
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

        // 3) Category and tag archives (posts => category + post_tag, products => product_cat + product_tag)
        if ( $include_cat ) {
            $taxonomy = ( 'product' === $post_type ) ? 'product_cat' : 'category';

            // Categories
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

            // Product tag archives — WooCommerce products only.
            if ( 'product' === $post_type ) {
                $tag_obj = get_taxonomy( 'product_tag' );
                if ( $tag_obj && ! empty( $tag_obj->public ) && false !== $tag_obj->rewrite ) {
                    $tags = get_the_terms( $post_id, 'product_tag' );
                    if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
                        foreach ( $tags as $tag ) {
                            $link = get_term_link( $tag, 'product_tag' );
                            if ( ! is_wp_error( $link ) && ! empty( $link ) ) {
                                $urls[] = $link;
                            }
                        }
                    }
                }
            }

            // Post tag archives — standard WordPress posts only.
            if ( 'post' === $post_type ) {
                $tag_obj = get_taxonomy( 'post_tag' );
                if ( $tag_obj && ! empty( $tag_obj->public ) && false !== $tag_obj->rewrite ) {
                    $tags = get_the_terms( $post_id, 'post_tag' );
                    if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
                        foreach ( $tags as $tag ) {
                            $link = get_term_link( $tag, 'post_tag' );
                            if ( ! is_wp_error( $link ) && ! empty( $link ) ) {
                                $urls[] = $link;
                            }
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

// Purge multiple related URLs from Nginx cache, called from Advanced tab Purge
// Mirrors nppp_purge_single's FP1→FP2→FP3→FP4 architecture — but primary-free:
// no lock, no preload chain, no post-purge hook. Caller owns those concerns.
//
// FP1 — HTTP purge module
// FP2 — Persistent URL→filepath index
// FP3 — ripgrep combined-pattern scan
// FP4 — PHP recursive iterator
function nppp_purge_urls_silent( string $nginx_cache_path, array $urls ): array {
    // Bootstrap
    $results      = [];
    $pending      = [];
    $write_back   = [];

    $wp_filesystem = nppp_initialize_wp_filesystem();

    foreach ( $urls as $url ) {
        $results[ $url ] = [ 'found' => false, 'deleted' => false ];

        if ( $wp_filesystem === false ) continue;
        if ( filter_var( $url, FILTER_VALIDATE_URL ) === false ) continue;

        $url_key             = preg_replace( '#^https?://#', '', $url );
        $pending[ $url_key ] = [
            'original' => $url,
            'decoded'  => rawurldecode( $url ),
        ];
    }

    if ( $wp_filesystem === false || empty( $pending ) ) {
        return $results;
    }

    // Single index fetch — reused across FP1 (bypass check) and FP2 (path lookup).
    $nppp_index = get_option( 'nppp_url_filepath_index' );
    $nppp_index = is_array( $nppp_index ) ? $nppp_index : [];

    // FAST-PATH 1 — HTTP purge module
    $fp1_http_resolved = 0;
    $fp1_http_miss     = 0;

    if ( nppp_http_purge_enabled() ) {
        foreach ( array_keys( $pending ) as $rel_key ) {
            $rel = $pending[ $rel_key ];

            // Bypass: >1 cached variants → let FP2 handle atomically.
            $http_bypass = isset( $nppp_index[ $rel_key ] )
                && is_array( $nppp_index[ $rel_key ] )
                && count( $nppp_index[ $rel_key ] ) > 1;

            if ( $http_bypass ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: related page URL */
                    __( 'INFO HTTP PURGE BYPASS: Multiple cache variants detected for related page %s — delegating to index-based purge to ensure all variants are removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
                continue;
            }

            $http_result = nppp_http_purge_try_first( $rel['original'] );

            if ( $http_result === true ) {
                // HTTP 200 — purged atomically.
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: related page URL */
                    __( 'SUCCESS HTTP PURGE: Nginx cache purged for related page %s (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
                $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => true ];
                $fp1_http_resolved++;
                unset( $pending[ $rel_key ] );

            } elseif ( $http_result === 'miss' ) {
                // HTTP 412 — Nginx confirmed not in cache.
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: related page URL */
                    __( 'INFO HTTP PURGE: Nginx cache purge attempted, but the related page %s is not currently found in the cache. (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
                $results[ $rel['original'] ] = [ 'found' => false, 'deleted' => false ];
                $fp1_http_miss++;
                unset( $pending[ $rel_key ] );
            }
        }

        if ( empty( $pending ) ) {
            return $results;
        }
    }

    // FAST-PATH 2 — Persistent URL→filepath index
    foreach ( array_keys( $pending ) as $rel_key ) {
        if ( ! isset( $nppp_index[ $rel_key ] ) ) {
            continue;
        }

        $rel       = $pending[ $rel_key ];
        $rel_paths = $nppp_index[ $rel_key ];

        $rel_any_valid     = false;
        $rel_deleted       = false;
        $rel_deleted_count = 0;
        $rel_delete_error  = false;

        foreach ( $rel_paths as $rp ) {
            if ( ! $wp_filesystem->exists( $rp )
                || ! $wp_filesystem->is_readable( $rp )
                || ! $wp_filesystem->is_writable( $rp )
                || nppp_validate_path( $rp, true ) !== true
            ) {
                continue;
            }
            $rel_any_valid = true;
            if ( $wp_filesystem->delete( $rp ) ) {
                $rel_deleted = true;
                $rel_deleted_count++;
            } else {
                $rel_delete_error = true;
            }
        }

        // All index paths are stale — fall through to FP3/FP4 for a fresh scan.
        if ( ! $rel_any_valid ) {
            continue;
        }

        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: related page URL */
            __( 'INFO INDEX HIT: Ready to purge related page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $rel['decoded']
        ), true, false );

        if ( $rel_deleted_count > 1 ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %1$d: variant count, %2$s: related page URL */
                __( 'INFO MULTI-VARIANT: %1$d cache variants deleted for related page %2$s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel_deleted_count,
                $rel['decoded']
            ), true, false );
        }

        if ( $rel_deleted && ! $rel_delete_error ) {
            $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => true ];
            nppp_display_admin_notice( 'success', sprintf(
                /* translators: %s: related page URL */
                __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (index hit — no scan)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel['decoded']
            ), true, false );
        } elseif ( $rel_deleted && $rel_delete_error ) {
            $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => true ];
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %1$d: deleted count, %2$d: total count, %3$s: related page URL */
                __( 'INFO PARTIAL: %1$d of %2$d cache variants deleted for related page %3$s (INDEX). One or more variants could not be removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel_deleted_count,
                count( $rel_paths ),
                $rel['decoded']
            ), true, false );
        } else {
            $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => false ];
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: %s: related page URL */
                __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for related page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel['decoded']
            ), true, false );
        }

        unset( $pending[ $rel_key ] );
    }
    unset( $nppp_index );

    nppp_display_admin_notice( 'info', sprintf(
        /* translators: %1$d: HTTP 200 resolved, %2$d: HTTP 412 miss, %3$d: index resolved, %4$d: scan fallback */
        __( 'INFO INDEX: Related purge — %1$d via HTTP (200), %2$d HTTP miss (412), %3$d via index, %4$d falling back to scan', 'fastcgi-cache-purge-and-preload-nginx' ),
        $fp1_http_resolved,
        $fp1_http_miss,
        count( $urls ) - count( $pending ) - $fp1_http_resolved - $fp1_http_miss,
        count( $pending )
    ), true, false );

    if ( empty( $pending ) ) {
        return $results;
    }

    // Lazy-fetch settings and build shared regex — used by both FP3 and FP4.
    $settings = get_option( 'nginx_cache_settings' );
    $regex    = isset( $settings['nginx_cache_key_custom_regex'] )
                ? base64_decode( $settings['nginx_cache_key_custom_regex'] )
                : nppp_fetch_default_regex_for_cache_key();

    $head_bytes_primary  = (int) apply_filters( 'nppp_locate_head_bytes',          4096  );
    $head_bytes_fallback = (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 );

    // FAST-PATH 3 — ripgrep
    $rg_ran = false;

    if ( isset( $settings['nppp_rg_purge_enabled'] ) && $settings['nppp_rg_purge_enabled'] === 'yes' ) {
        nppp_prepare_request_env();
        $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );

        if ( $rg_bin !== '' ) {
            $rg_ran = true;

            // Combined alternation: one rg call for ALL pending related URLs.
            $url_alts = implode( '|', array_map(
                fn( string $u ): string => preg_quote( $u, '/' ) . '$',
                array_keys( $pending )
            ) );

            $rg_cmd = sprintf(
                '%s -m 1 --text -E none --no-unicode --no-messages --no-ignore --no-config %s %s',
                escapeshellarg( $rg_bin ),
                escapeshellarg( '^KEY: .*(' . $url_alts . ')' ),
                escapeshellarg( $nginx_cache_path )
            );

            $rg_out  = [];
            $rg_exit = 0;
            exec( $rg_cmd, $rg_out, $rg_exit );

            // Exit 2 — rg I/O or permission error inside the cache path.
            if ( $rg_exit === 2 ) {
                foreach ( $pending as $entry ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: related page URL */
                        __( 'ERROR RG PURGE: Nginx cache purge for related page %s was aborted due to a permission or I/O error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $entry['decoded']
                    ), true, false );
                }

                // Flush any write-back accumulated so far
                if ( ! empty( $write_back ) ) {
                    $wb_idx = get_option( 'nppp_url_filepath_index' );
                    $wb_idx = is_array( $wb_idx ) ? $wb_idx : [];
                    foreach ( $write_back as $uk => $paths ) {
                        $existing = $wb_idx[ $uk ] ?? [];
                        foreach ( $paths as $p ) {
                            if ( ! in_array( $p, $existing, true ) ) { $existing[] = $p; }
                        }
                        $wb_idx[ $uk ] = $existing;
                    }
                    update_option( 'nppp_url_filepath_index', $wb_idx, false );
                }
                return $results;
            }

            // Exit 0 — at least one candidate line returned.
            if ( ! empty( $rg_out ) ) {
                nppp_display_admin_notice( 'info',
                    __( 'INFO RG HIT: Candidates found, verifying and purging related URLs (RG)', 'fastcgi-cache-purge-and-preload-nginx' )
                , true, false );

                $rg_candidates  = [];
                $rg_path_errors = [];
                $rg_regex_ok    = false;

                foreach ( array_filter( $rg_out, 'strlen' ) as $rg_raw ) {
                    $rg_raw = trim( $rg_raw );
                    if ( $rg_raw === '' ) continue;

                    $rg_parts = explode( ':', $rg_raw, 2 );
                    if ( count( $rg_parts ) < 2 ) continue;

                    $rg_candidate = trim( $rg_parts[0] );
                    $rg_key_line  = trim( $rg_parts[1] );
                    if ( $rg_candidate === '' || $rg_key_line === '' ) continue;

                    // Non-GET filter.
                    foreach ( [ 'POST', 'HEAD', 'PUT', 'DELETE', 'PATCH', 'OPTIONS' ] as $m ) {
                        if ( strpos( $rg_key_line, $m ) !== false ) continue 2;
                    }

                    // Validate regex once against the first viable line.
                    $rg_rx = [];
                    if ( ! $rg_regex_ok ) {
                        if ( preg_match( $regex, $rg_key_line, $rg_rx ) && isset( $rg_rx[1], $rg_rx[2] ) ) {
                            $test = 'https://' . trim( $rg_rx[1] ) . trim( $rg_rx[2] );
                            if ( filter_var( $test, FILTER_VALIDATE_URL ) ) {
                                $rg_regex_ok = true;
                            } else {
                                nppp_display_admin_notice( 'error',
                                    __( 'ERROR REGEX: Related purge (RG) failed — Cache Key Regex does not parse $host$request_uri correctly. Check the Advanced options.', 'fastcgi-cache-purge-and-preload-nginx' )
                                , true, false );
                                break;
                            }
                        } else {
                            nppp_display_admin_notice( 'error',
                                __( 'ERROR REGEX: Related purge (RG) failed — Cache Key Regex is not configured correctly. Check the Advanced options.', 'fastcgi-cache-purge-and-preload-nginx' )
                            , true, false );
                            break;
                        }
                    } elseif ( ! ( preg_match( $regex, $rg_key_line, $rg_rx ) && isset( $rg_rx[1], $rg_rx[2] ) ) ) {
                        continue;
                    }

                    $constructed = trim( $rg_rx[1] ) . trim( $rg_rx[2] );
                    if ( ! isset( $pending[ $constructed ] ) ) continue;

                    $rel = $pending[ $constructed ];

                    // Permission check.
                    if ( ! $wp_filesystem->is_writable( $rg_candidate ) ) {
                        nppp_display_admin_notice( 'error', sprintf(
                            /* translators: %s: related page URL */
                            __( 'ERROR PERMISSION: Nginx cache purge for related page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $rel['decoded']
                        ), true, false );
                        $rg_path_errors[ $constructed ] = true;
                        continue;
                    }

                    // Path validation.
                    if ( nppp_validate_path( $rg_candidate, true ) !== true ) {
                        nppp_display_admin_notice( 'error', sprintf(
                            /* translators: %s: related page URL */
                            __( 'ERROR PATH: A cache variant for related page %s was skipped (RG) — invalid path detected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $rel['decoded']
                        ), true, false );
                        $rg_path_errors[ $constructed ] = true;
                        continue;
                    }

                    // Accumulate — delete after loop to collect all variants per URL.
                    $rg_candidates[ $constructed ][] = $rg_candidate;
                }

                // Delete all accumulated RG candidates (bulk, after loop).
                foreach ( $rg_candidates as $rel_key => $rel_paths ) {
                    $rel         = $pending[ $rel_key ];
                    $rel_deleted = 0;

                    foreach ( $rel_paths as $rp ) {
                        if ( $wp_filesystem->delete( $rp ) ) {
                            $rel_deleted++;
                            $write_back[ $rel_key ][] = $rp;
                        } else {
                            nppp_display_admin_notice( 'error', sprintf(
                                /* translators: %s: related page URL */
                                __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for related page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                                $rel['decoded']
                            ), true, false );
                        }
                    }

                    if ( $rel_deleted > 1 ) {
                        nppp_display_admin_notice( 'info', sprintf(
                            /* translators: %1$d: variant count, %2$s: related page URL */
                            __( 'INFO MULTI-VARIANT: %1$d cache variants deleted for related page %2$s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $rel_deleted,
                            $rel['decoded']
                        ), true, false );
                    }
                    if ( $rel_deleted > 0 ) {
                        $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => true ];
                        nppp_display_admin_notice( 'success', sprintf(
                            /* translators: %s: related page URL */
                            __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $rel['decoded']
                        ), true, false );
                    }
                    unset( $pending[ $rel_key ] );
                }

                // Path errors — mark result and remove from pending so FP4 won't retry.
                foreach ( array_keys( $rg_path_errors ) as $rel_key ) {
                    if ( isset( $pending[ $rel_key ] ) ) {
                        $results[ $pending[ $rel_key ]['original'] ] = [ 'found' => true, 'deleted' => false ];
                        unset( $pending[ $rel_key ] );
                    }
                }
            }

            // Remaining pending after FP3 — rg scanned the full directory and
            // found nothing; no point running FP4 over the same files.
            foreach ( array_keys( $pending ) as $rel_key ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: related page URL */
                    __( 'INFO ADMIN: Nginx cache purge attempted, but the related page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $pending[ $rel_key ]['decoded']
                ), true, false );
                unset( $pending[ $rel_key ] );
            }

            // Flush FP3 write-backs — single DB round-trip.
            if ( ! empty( $write_back ) ) {
                $wb_idx = get_option( 'nppp_url_filepath_index' );
                $wb_idx = is_array( $wb_idx ) ? $wb_idx : [];
                foreach ( $write_back as $uk => $paths ) {
                    $existing = $wb_idx[ $uk ] ?? [];
                    foreach ( $paths as $p ) {
                        if ( ! in_array( $p, $existing, true ) ) { $existing[] = $p; }
                    }
                    $wb_idx[ $uk ] = $existing;
                }
                $wb_count = count( $write_back );
                update_option( 'nppp_url_filepath_index', $wb_idx, false );
                $write_back = [];
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %d: number of URLs */
                    __( 'INFO INDEX WRITE-BACK: %d related URL(s) flushed to index (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $wb_count
                ), true, false );
            }

            // FP3 handled everything.
            if ( empty( $pending ) ) {
                return $results;
            }
        }
    }

    // FAST-PATH 4 — PHP recursive iterator

    $fp4_candidates  = [];
    $fp4_path_errors = [];
    $fp4_regex_ok    = false;

    try {
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $cache_iterator as $file ) {

            // Early exit: every pending URL accumulated or permanently errored.
            if ( empty( array_diff_key( $pending, $fp4_candidates + $fp4_path_errors ) ) ) {
                break;
            }

            // Any unreadable/unwritable file = cache directory integrity failure.
            if ( ! $file->isReadable() || ! $file->isWritable() ) {
                foreach ( $pending as $entry ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: related page URL */
                        __( 'ERROR PERMISSION: Nginx cache purge for related page %s was aborted — a permission issue was detected in the cache directory. This may indicate a cache path integrity problem (e.g. broken bindfs sync). Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $entry['decoded']
                    ), true, false );
                    $results[ $entry['original'] ] = [ 'found' => false, 'deleted' => false ];
                }
                $pending = [];
                break;
            }

            $pathname = $file->getPathname();
            $content  = nppp_read_head( $wp_filesystem, $pathname, $head_bytes_primary );
            if ( $content === '' ) continue;

            // Locate the KEY header; retry with a larger window if not found.
            $match = [];
            if ( ! preg_match( '/^KEY:\s([^\r\n]*)/m', $content, $match ) ) {
                if ( strlen( $content ) >= $head_bytes_primary ) {
                    $content = nppp_read_head( $wp_filesystem, $pathname, $head_bytes_fallback );
                    if ( $content === '' || ! preg_match( '/^KEY:\s([^\r\n]*)/m', $content, $match ) ) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            // Skip redirect responses.
            if ( strpos( $content, 'Status: 301 Moved Permanently' ) !== false ||
                 strpos( $content, 'Status: 302 Found' ) !== false ) {
                continue;
            }

            // Non-GET filter.
            $key_line = $match[1];
            foreach ( [ 'POST', 'HEAD', 'PUT', 'DELETE', 'PATCH', 'OPTIONS' ] as $m ) {
                if ( strpos( $key_line, $m ) !== false ) continue 2;
            }

            // Validate regex once against the first viable file; abort with notice on failure.
            if ( ! $fp4_regex_ok ) {
                $tmp = [];
                if ( preg_match( $regex, $content, $tmp ) && isset( $tmp[1], $tmp[2] ) ) {
                    $test = 'https://' . trim( $tmp[1] ) . trim( $tmp[2] );
                    if ( filter_var( $test, FILTER_VALIDATE_URL ) ) {
                        $fp4_regex_ok = true;
                    } else {
                        nppp_display_admin_notice( 'error',
                            __( 'ERROR REGEX: Related purge (SCAN) failed — Cache Key Regex does not parse $host$request_uri correctly. Check the Advanced options.', 'fastcgi-cache-purge-and-preload-nginx' )
                        , true, false );
                        foreach ( $pending as $entry ) {
                            $results[ $entry['original'] ] = [ 'found' => false, 'deleted' => false ];
                        }
                        $pending = [];
                        break;
                    }
                } else {
                    nppp_display_admin_notice( 'error',
                        __( 'ERROR REGEX: Related purge (SCAN) failed — Cache Key Regex is not configured correctly. Check the Advanced options.', 'fastcgi-cache-purge-and-preload-nginx' )
                    , true, false );
                    foreach ( $pending as $entry ) {
                        $results[ $entry['original'] ] = [ 'found' => false, 'deleted' => false ];
                    }
                    $pending = [];
                    break;
                }
            }

            $matches = [];
            if ( ! ( preg_match( $regex, $content, $matches ) && isset( $matches[1], $matches[2] ) ) ) {
                continue;
            }

            $constructed = trim( $matches[1] ) . trim( $matches[2] );
            if ( ! isset( $pending[ $constructed ] ) ) continue;

            $cache_path = $file->getPathname();

            if ( nppp_validate_path( $cache_path, true ) !== true ) {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: related page URL */
                    __( 'ERROR PATH: A cache variant for related page %s was skipped (SCAN) — invalid path detected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $pending[ $constructed ]['decoded']
                ), true, false );
                $fp4_path_errors[ $constructed ] = true;
                continue;
            }

            // Accumulate — keep scanning for further variants of the same URL.
            $fp4_candidates[ $constructed ][] = $cache_path;
        }

    } catch ( Exception $e ) {
        nppp_display_admin_notice( 'error',
            __( 'ERROR PERMISSION: Nginx cache purge for related URLs failed due to a permission issue (SCAN). Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' )
        , true, false );
        // Flush whatever write-back we have, then bail out.
        if ( ! empty( $write_back ) ) {
            $wb_idx = get_option( 'nppp_url_filepath_index' );
            $wb_idx = is_array( $wb_idx ) ? $wb_idx : [];
            foreach ( $write_back as $uk => $paths ) {
                $existing = $wb_idx[ $uk ] ?? [];
                foreach ( $paths as $p ) {
                    if ( ! in_array( $p, $existing, true ) ) { $existing[] = $p; }
                }
                $wb_idx[ $uk ] = $existing;
            }
            $wb_count = count( $write_back );
            update_option( 'nppp_url_filepath_index', $wb_idx, false );
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %d: number of URLs */
                __( 'INFO INDEX WRITE-BACK: %d related URL(s) flushed to index (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $wb_count
            ), true, false );
        }
        return $results;
    }

    // Post-scan: delete accumulated candidates (bulk delete after the iterator).
    foreach ( $fp4_candidates as $rel_key => $rel_paths ) {
        if ( ! isset( $pending[ $rel_key ] ) ) continue;
        $rel         = $pending[ $rel_key ];
        $rel_deleted = 0;

        foreach ( $rel_paths as $rp ) {
            if ( $wp_filesystem->delete( $rp ) ) {
                $rel_deleted++;
                $write_back[ $rel_key ][] = $rp;
            } else {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: related page URL */
                    __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for related page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
            }
        }

        if ( $rel_deleted > 1 ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %1$d: variant count, %2$s: related page URL */
                __( 'INFO MULTI-VARIANT: %1$d cache variants deleted for related page %2$s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel_deleted,
                $rel['decoded']
            ), true, false );
        }
        if ( $rel_deleted > 0 ) {
            $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => true ];
            nppp_display_admin_notice( 'success', sprintf(
                /* translators: %s: related page URL */
                __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel['decoded']
            ), true, false );
        } else {
            $results[ $rel['original'] ] = [ 'found' => true, 'deleted' => false ];
        }

        unset( $pending[ $rel_key ] );
    }

    // Path errors — found in cache but could not be deleted safely.
    foreach ( array_keys( $fp4_path_errors ) as $rel_key ) {
        if ( isset( $pending[ $rel_key ] ) ) {
            $results[ $pending[ $rel_key ]['original'] ] = [ 'found' => true, 'deleted' => false ];
            unset( $pending[ $rel_key ] );
        }
    }

    // Remaining pending — not found anywhere in the directory.
    foreach ( $pending as $entry ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: related page URL */
            __( 'INFO ADMIN: Nginx cache purge attempted, but the related page %s is not currently found in the cache (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $entry['decoded']
        ), true, false );
    }

    // Write-back flush single update_option() for the entire operation
    if ( ! empty( $write_back ) ) {
        $wb_idx = get_option( 'nppp_url_filepath_index' );
        $wb_idx = is_array( $wb_idx ) ? $wb_idx : [];
        foreach ( $write_back as $uk => $paths ) {
            $existing = $wb_idx[ $uk ] ?? [];
            foreach ( $paths as $p ) {
                if ( ! in_array( $p, $existing, true ) ) { $existing[] = $p; }
            }
            $wb_idx[ $uk ] = $existing;
        }
        $wb_count = count( $write_back );
        update_option( 'nppp_url_filepath_index', $wb_idx, false );
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %d: number of URLs */
            __( 'INFO INDEX WRITE-BACK: %d related URL(s) flushed to index (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $wb_count
        ), true, false );
    }

    return $results;
}

// Fire-and-forget
function nppp_preload_urls_fire_and_forget(array $urls): void {
    if (empty($urls)) return;

    $settings                 = get_option('nginx_cache_settings');
    $preload_mobile           = !empty($settings['nginx_cache_auto_preload_mobile']) && $settings['nginx_cache_auto_preload_mobile'] === 'yes';
    $nginx_cache_path         = isset($settings['nginx_cache_path']) ? $settings['nginx_cache_path'] : '/dev/shm/change-me-now';
    $nginx_cache_limit_rate   = isset($settings['nginx_cache_limit_rate']) ? (int)$settings['nginx_cache_limit_rate'] : 5120;
    $nginx_cache_read_timeout = isset($settings['nginx_cache_read_timeout']) ? (int)$settings['nginx_cache_read_timeout'] : 60;
    $tmp_path                 = rtrim($nginx_cache_path, '/') . '/tmp';

    // Proxy settings
    $proxy_settings = nppp_get_proxy_settings();
    $use_proxy      = $proxy_settings['use_proxy'];
    $http_proxy     = $proxy_settings['http_proxy'];
    $https_proxy    = $http_proxy;

    // safexec
    $safexec_path = nppp_find_safexec_path();
    $use_safexec  = nppp_is_safexec_usable($safexec_path ?: '', false);

    foreach ($urls as $u) {
        if (false === wp_http_validate_url($u)) {
            continue;
        }

        // Build per-URL domain allowlist
        $parsed    = wp_parse_url($u);
        $host      = strtolower($parsed['host'] ?? '');
        if ($host === '') continue;
        $base_host   = preg_replace('/^www\./i', '', $host);
        $domain_list = escapeshellarg(implode(',', array_unique([$base_host, 'www.' . $base_host])));

        $common =
            '--quiet --no-config --no-cookies --no-directories --delete-after ' .
            '--no-dns-cache --no-check-certificate --prefer-family=IPv4 ' .
            '--dns-timeout=10 --connect-timeout=5 --read-timeout=' . $nginx_cache_read_timeout . ' --tries=1 ' .
            '-e robots=off ' .
            '-e ' . escapeshellarg('use_proxy=' . $use_proxy) . ' ' .
            '-e ' . escapeshellarg('http_proxy='  . $http_proxy) . ' ' .
            '-e ' . escapeshellarg('https_proxy=' . $https_proxy) . ' ' .
            '-P ' . escapeshellarg($use_safexec ? '/tmp' : $tmp_path) . ' ' .
            '--limit-rate=' . $nginx_cache_limit_rate . 'k ' .
            '--domains=' . $domain_list . ' ' .
            '--header=' . escapeshellarg(NPPP_HEADER_ACCEPT) . ' ';

        $safexec_prefix = $use_safexec ? escapeshellarg($safexec_path) . ' ' : '';
        $url_arg        = escapeshellarg($u);

        // Desktop
        $cmd_desktop = $safexec_prefix .
            'nohup wget ' . $common .
            '--user-agent=' . escapeshellarg(NPPP_USER_AGENT) . ' ' .
            '-- ' . $url_arg . ' >/dev/null 2>&1 &';
        shell_exec($cmd_desktop);

        // Mobile (if enabled)
        if ($preload_mobile) {
            $cmd_mobile = $safexec_prefix .
                'nohup wget ' . $common .
                '--user-agent=' . escapeshellarg(NPPP_USER_AGENT_MOBILE) . ' ' .
                '-- ' . $url_arg . ' >/dev/null 2>&1 &';
            shell_exec($cmd_mobile);
        }
    }
}
