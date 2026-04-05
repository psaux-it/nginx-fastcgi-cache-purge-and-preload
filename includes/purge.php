<?php
/**
 * Cache purge handlers for Nginx Cache Purge Preload
 * Description: Executes full and targeted purge operations for supported Nginx cache backends.
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

// Purge cache operation helper
function nppp_purge_helper($nginx_cache_path, $tmp_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    if ($wp_filesystem->is_dir($tmp_path)) {
        nppp_wp_remove_directory($tmp_path, true);
    }

    // Check if the cache path exists and is a directory
    if ($wp_filesystem->is_dir($nginx_cache_path)) {
        // Recursively remove the cache directory contents.
        $result = nppp_wp_purge($nginx_cache_path);

        // Check cache purge status
        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            if ($error_code === 'permission_error') {
                return 1;
            } elseif ($error_code === 'empty_directory') {
                return 2;
            } elseif ($error_code === 'directory_not_found') {
                return 3;
            } elseif ($error_code === 'directory_traversal') {
                return 5;
            } elseif ($error_code === 'unsafe_cache_path') {
                return 6;
            } else {
                return 4;
            }
        } else {
            // Cache successfully purged — stored hit count is now stale (cache is empty).
            update_option( 'nppp_last_known_hits',      0,      false );
            update_option( 'nppp_last_hits_scanned_at', time(), false );
            return 0;
        }
    } else {
        return 3;
    }
}

/**
 * Purge a single page from the Nginx cache.
 *
 * Tries four fast-paths in order, stopping at the first one that resolves
 * both the primary URL and all related URLs.
 */
function nppp_purge_single( $nginx_cache_path, $current_page_url, $nppp_auto_purge = false ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }
    if ( function_exists( 'ignore_user_abort' ) ) {
        ignore_user_abort( true );
    }

    // Bootstrap: validate inputs, build context, acquire lock.
    $ctx = nppp_purge_single_init( $nginx_cache_path, $current_page_url, $nppp_auto_purge );
    if ( $ctx === false ) {
        return;
    }

    try {
        // FP1: HTTP purge
        if ( nppp_purge_fp1_http( $ctx ) ) {
            return;
        }

        // FP2: Persistent index lookup
        if ( nppp_purge_fp2_index( $ctx ) ) {
            return;
        }

        // FP3: ripgrep scan.
        $fp3_result = nppp_purge_fp3_rg( $ctx );
        if ( $fp3_result !== 'skip' ) {
            return;
        }

        // FP4: PHP recursive iterator scan.
        nppp_purge_fp4_scan( $ctx );
    } finally {
        nppp_release_purge_lock();
    }
}

/**
 * Get Index once.
 */
function nppp_purge_get_index(): array {
    $idx = get_option( 'nppp_url_filepath_index' );
    return is_array( $idx ) ? $idx : [];
}

/**
 * Initialise the filesystem, validate the URL, check for a running preload,
 * build the related-URL pending map, and acquire the purge lock.
 */
function nppp_purge_single_init( $nginx_cache_path, $current_page_url, $nppp_auto_purge ) {
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false ) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return false;
    }

    $current_page_url_decoded = rawurldecode( $current_page_url );
    $PIDFILE                  = nppp_get_runtime_file( 'cache_preload.pid' );

    // Single settings fetch
    $nginx_cache_settings = get_option( 'nginx_cache_settings' );
    $nppp_auto_preload    = isset( $nginx_cache_settings['nginx_cache_auto_preload'] )
                            && $nginx_cache_settings['nginx_cache_auto_preload'] === 'yes';
    $chain_autopreload    = ( $nppp_auto_purge && $nppp_auto_preload );
    $regex                = isset( $nginx_cache_settings['nginx_cache_key_custom_regex'] )
                            ? base64_decode( $nginx_cache_settings['nginx_cache_key_custom_regex'] )
                            : nppp_fetch_default_regex_for_cache_key();

    // Bail if a full preload is already running.
    if ( $wp_filesystem->exists( $PIDFILE ) ) {
        $pid = intval( nppp_perform_file_operation( $PIDFILE, 'read' ) );
        if ( $pid > 0 && nppp_is_process_alive( $pid ) ) {
            nppp_display_admin_notice( 'info', sprintf(
                __( 'INFO: Single-page purge for %s skipped — Nginx cache preloading is in progress. Check the Status tab to monitor; wait for completion or use "Purge All" to cancel.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $current_page_url_decoded
            ) );
            return false;
        }
    }

    // Validate URL and derive the scheme-stripped form used for cache-key matching.
    if ( filter_var( $current_page_url, FILTER_VALIDATE_URL ) === false ) {
        nppp_display_admin_notice( 'error', __( 'ERROR URL: URL can not validated.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        return false;
    }

    $url_to_search_exact = preg_replace( '#^https?://#', '', $current_page_url );
    $head_bytes_primary  = (int) apply_filters( 'nppp_locate_head_bytes', 4096 );
    $head_bytes_fallback = (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 );

    // Pre-compute related URLs BEFORE lock acquisition.
    $related_urls    = nppp_get_related_urls_for_single( $current_page_url );
    $related_pending = [];
    foreach ( $related_urls as $rel_url ) {
        if ( filter_var( $rel_url, FILTER_VALIDATE_URL ) !== false ) {
            $rel_key                   = preg_replace( '#^https?://#', '', $rel_url );
            $related_pending[ $rel_key ] = [
                'original' => $rel_url,
                'decoded'  => rawurldecode( $rel_url ),
            ];
        }
    }

    // Serialize concurrent single-page purges and prevent collision with Purge All.
    if ( ! nppp_acquire_purge_lock( 'single' ) ) {
        nppp_display_admin_notice( 'info', sprintf(
            __( 'INFO: Single-page purge for %s skipped — another cache purge operation is already in progress. Please try again shortly.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ) );
        return false;
    }

    return [
        // Immutable
        'wp_filesystem'            => $wp_filesystem,
        'nppp_index'               => nppp_purge_get_index(),
        'nginx_cache_path'         => $nginx_cache_path,
        'current_page_url'         => $current_page_url,
        'current_page_url_decoded' => $current_page_url_decoded,
        'url_to_search_exact'      => $url_to_search_exact,
        'nppp_auto_purge'          => $nppp_auto_purge,
        'chain_autopreload'        => $chain_autopreload,
        'regex'                    => $regex,
        'head_bytes_primary'       => $head_bytes_primary,
        'head_bytes_fallback'      => $head_bytes_fallback,
        'nginx_cache_settings'     => $nginx_cache_settings,
        'related_urls'             => $related_urls,
        // Mutable
        'related_pending'          => $related_pending,
        'primary_resolved'         => false,
        'primary_found'            => false,
        'write_back'               => [],
        'fp2_trigger_preload'      => true,
        'deleted_urls'             => [],
    ];
}

/**
 * Flush all accumulated URL→path write-backs in exactly ONE DB round-trip.
 * Resets $ctx['write_back'] to [] after writing so subsequent calls are no-ops.
 */
function nppp_purge_flush_write_back( array &$ctx ): void {
    if ( empty( $ctx['write_back'] ) ) {
        return;
    }

    $idx = get_option( 'nppp_url_filepath_index' );
    $idx = is_array( $idx ) ? $idx : [];

    foreach ( $ctx['write_back'] as $uk => $paths ) {
        $existing = $idx[ $uk ] ?? [];
        foreach ( $paths as $p ) {
            if ( ! in_array( $p, $existing, true ) ) {
                $existing[] = $p;
            }
        }
        $idx[ $uk ] = $existing;
    }

    update_option( 'nppp_url_filepath_index', $idx, false );
    $ctx['write_back'] = [];
}

/**
 * Post-purge side-effects: trigger auto-preload, related-preload and fire action hook.
 * Lock is released in the finally block of nppp_purge_single().
 * Returns early without side-effects when no URLs were actually deleted.
 */
function nppp_purge_post_purge( array &$ctx, bool $pf, bool $trigger_preload = true ): void {
    // No cache purged
    if ( empty( $ctx['deleted_urls'] ) ) {
        return;
    }

    $is_manual       = ! $ctx['nppp_auto_purge'];
    $deleted_unique  = array_unique( $ctx['deleted_urls'] );
    $primary_deleted = in_array( $ctx['current_page_url'], $deleted_unique, true );

    // Auto-preload primary only if it was actually deleted.
    if ( $primary_deleted && $ctx['chain_autopreload'] && $trigger_preload ) {
        nppp_preload_cache_on_update( $ctx['current_page_url'], $pf );
    }

    // Determine whether to also preload related URLs.
    $settings               = get_option( 'nginx_cache_settings' );
    $should_preload_related =
        ( $is_manual
            && ! empty( $settings['nppp_related_preload_after_manual'] )
            && $settings['nppp_related_preload_after_manual'] === 'yes' )
        || ( ! $is_manual
            && ! empty( $settings['nginx_cache_auto_preload'] )
            && $settings['nginx_cache_auto_preload'] === 'yes' );

    // Trigger Preload related
    if ( $should_preload_related ) {
        $deleted_related = array_values( array_filter(
            $ctx['deleted_urls'],
            fn( string $u ) => $u !== $ctx['current_page_url']
        ) );
        if ( ! empty( $deleted_related ) ) {
            nppp_preload_urls_fire_and_forget( $deleted_related );
        }
    }

    // Fire the nppp_purged_urls action hook
    $post_id = (int) url_to_postid( $ctx['current_page_url'] );
    do_action(
        'nppp_purged_urls',
        array_unique( $ctx['deleted_urls'] ),
        $ctx['current_page_url'],
        $post_id,
        (bool) $ctx['nppp_auto_purge']
    );
}

/**
 * FP1: Attempt purge via the Nginx HTTP purge endpoint.
 *
 * Bypass when the index holds multiple variants — HTTP can only address one
 * entry at a time, so those are delegated to FP2 to cover all cache variants atomically.
 * If primary resolves via HTTP, also tries HTTP for every pending related URL.
 */
function nppp_purge_fp1_http( array &$ctx ): bool {
    $nginx_cache_settings = $ctx['nginx_cache_settings'];
    if ( ! isset( $nginx_cache_settings['nppp_http_purge_enabled'] )
        || $nginx_cache_settings['nppp_http_purge_enabled'] !== 'yes'
    ) {
        return false;
    }

    // Get index
    $nppp_index = $ctx['nppp_index'];

    // Primary
    $nppp_http_bypass = isset( $nppp_index[ $ctx['url_to_search_exact'] ] )
        && is_array( $nppp_index[ $ctx['url_to_search_exact'] ] )
        && count( $nppp_index[ $ctx['url_to_search_exact'] ] ) > 1;

    if ( $nppp_http_bypass ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: full page URL */
            __( 'INFO HTTP PURGE BYPASS: Multiple cache variants detected for page %s — delegating to index-based purge to ensure all variants are removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $ctx['current_page_url_decoded']
        ), true, false );
    }

    $nppp_http_result = $nppp_http_bypass ? false : nppp_http_purge_try_first( $ctx['current_page_url'] );

    // HTTP 200 — entry deleted from shmem + disk atomically.
    if ( $nppp_http_result === true ) {
        $ctx['primary_resolved'] = true;
        $ctx['primary_found']    = true;
        $ctx['deleted_urls'][]   = $ctx['current_page_url'];
        if ( ! $ctx['chain_autopreload'] ) {
            nppp_display_admin_notice( 'success', sprintf(
                /* translators: %s: full page URL */
                __( 'SUCCESS HTTP PURGE: Nginx cache purged for page %s (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $ctx['current_page_url_decoded']
            ) );
        }
    // HTTP false (not true and not 'miss') — request failed or endpoint unreachable;
    // primary_resolved stays false and execution falls through to FP2/FP3/FP4.
    // HTTP 412 — Nginx confirmed the URL is not in cache (nginx-modules v2.5.x).
    } elseif ( $nppp_http_result === 'miss' ) {
        $ctx['primary_resolved'] = true;
        $ctx['primary_found']    = false;
        if ( ! $ctx['chain_autopreload'] ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: full page URL */
                __( 'INFO HTTP PURGE: Nginx cache purge attempted, but the page %s is not currently found in the cache. (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $ctx['current_page_url_decoded']
            ) );
        }
    }

    // Related — attempt HTTP purge for each pending related URL independently,
    // regardless of whether the primary was resolved. Each related URL is its
    // own cache entry and may succeed even if the primary HTTP purge failed.
    if ( ! empty( $ctx['related_pending'] ) ) {
        foreach ( array_keys( $ctx['related_pending'] ) as $rel_key ) {
            $rel = $ctx['related_pending'][ $rel_key ];

            $rel_http_bypass = isset( $nppp_index[ $rel_key ] )
                && is_array( $nppp_index[ $rel_key ] )
                && count( $nppp_index[ $rel_key ] ) > 1;

            if ( $rel_http_bypass ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: related page URL */
                    __( 'INFO HTTP PURGE BYPASS: Multiple cache variants detected for related page %s — delegating to index-based purge to ensure all variants are removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
                continue;
            }

            $rel_http = nppp_http_purge_try_first( $rel['original'] );
            if ( $rel_http === true ) {
                $ctx['deleted_urls'][] = $rel['original'];
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: related page URL */
                    __( 'SUCCESS HTTP PURGE: Nginx cache purged for related page %s (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
                unset( $ctx['related_pending'][ $rel_key ] );
            } elseif ( $rel_http === 'miss' ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: related page URL */
                    __( 'INFO HTTP PURGE: Nginx cache purge attempted, but the related page %s is not currently found in the cache. (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
                unset( $ctx['related_pending'][ $rel_key ] );
            }
        }
    }

    // Everything handled by HTTP.
    if ( $ctx['primary_resolved'] && empty( $ctx['related_pending'] ) ) {
        nppp_purge_post_purge( $ctx, $ctx['primary_found'] );
        return true;
    }

    return false;
}

/**
 * FP2: Purge via the persistent URL→filepath index.
 *
 * Each URL maps to one or more filesystem paths (one per Nginx cache variant).
 * Hit + all valid paths → delete directly, no directory walk needed.
 * All stale paths → emit notice and fall through to FP3/FP4.
 *
 * Handles both primary (if not yet resolved by FP1) and all remaining related URLs.
 */
function nppp_purge_fp2_index( array &$ctx ): bool {
    $wp_filesystem = $ctx['wp_filesystem'];

    // Get index
    $nppp_index = $ctx['nppp_index'];

    // Primary
    if ( ! $ctx['primary_resolved'] ) {
        if ( isset( $nppp_index[ $ctx['url_to_search_exact'] ] ) ) {
            $nppp_index_paths = $nppp_index[ $ctx['url_to_search_exact'] ];
            $deleted          = false;
            $delete_error     = false;
            $any_valid        = false;
            $deleted_count    = 0;

            foreach ( $nppp_index_paths as $nppp_index_path ) {
                if ( ! $wp_filesystem->exists( $nppp_index_path )
                    || ! $wp_filesystem->is_readable( $nppp_index_path )
                    || ! $wp_filesystem->is_writable( $nppp_index_path )
                    || nppp_validate_path( $nppp_index_path, true ) !== true
                ) {
                    continue;
                }

                $any_valid = true;
                if ( $wp_filesystem->delete( $nppp_index_path ) ) {
                    $deleted = true;
                    $deleted_count++;
                } else {
                    $delete_error               = true;
                    $ctx['fp2_trigger_preload'] = false;
                }
            }

            // All index paths are stale — do not mark primary as resolved so
            // execution falls through to FP3/FP4 for a fresh directory scan.
            if ( ! $any_valid ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: full page URL */
                    __( 'INFO INDEX STALE: Index paths no longer valid, running full scan for %s (RG - PHP Iterative)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $ctx['current_page_url_decoded']
                ), true, false );
            } else {
                $ctx['primary_resolved'] = true;
                $ctx['primary_found']    = $deleted && ! $delete_error;

                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: full page URL */
                    __( 'INFO INDEX HIT: Ready to purge %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $ctx['current_page_url_decoded']
                ), true, false );

                if ( $deleted_count > 1 ) {
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %1$d: variant count, %2$s: full page URL */
                        __( 'INFO MULTI-VARIANT: %1$d cache variants deleted for page %2$s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $deleted_count,
                        $ctx['current_page_url_decoded']
                    ), true, false );
                }

                if ( $deleted && ! $delete_error ) {
                    $ctx['deleted_urls'][] = $ctx['current_page_url'];
                    if ( ! $ctx['chain_autopreload'] ) {
                        nppp_display_admin_notice( 'success', sprintf(
                            /* translators: %s: full page URL */
                            __( 'SUCCESS ADMIN: Nginx cache purged for page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $ctx['current_page_url_decoded']
                        ) );
                    }
                } elseif ( $deleted && $delete_error ) {
                    $ctx['deleted_urls'][] = $ctx['current_page_url'];
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %1$d: deleted count, %2$d: total count, %3$s: full page URL */
                        __( 'INFO ADMIN PARTIAL: %1$d of %2$d cache variants deleted for page %3$s (INDEX). One or more variants could not be removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $deleted_count,
                        count( $nppp_index_paths ),
                        $ctx['current_page_url_decoded']
                    ) );
                } else {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: full page URL */
                        __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                        $ctx['current_page_url_decoded']
                    ) );
                }
            }
        } else {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: full page URL */
                __( 'INFO INDEX ABSENT: Running full recursive scan for %s (RG - PHP Iterative)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $ctx['current_page_url_decoded']
            ), true, false );
        }
    }

    // Related
    if ( ! empty( $ctx['related_pending'] ) ) {
        foreach ( array_keys( $ctx['related_pending'] ) as $rel_key ) {
            if ( ! isset( $nppp_index[ $rel_key ] ) ) {
                continue;
            }

            $rel               = $ctx['related_pending'][ $rel_key ];
            $rel_paths         = $nppp_index[ $rel_key ];
            $rel_del           = false;
            $rel_del_error     = false;
            $rel_valid         = false;
            $rel_deleted_count = 0;

            foreach ( $rel_paths as $rp ) {
                if ( ! $wp_filesystem->exists( $rp )
                    || ! $wp_filesystem->is_readable( $rp )
                    || ! $wp_filesystem->is_writable( $rp )
                    || nppp_validate_path( $rp, true ) !== true
                ) {
                    continue;
                }

                $rel_valid = true;
                if ( $wp_filesystem->delete( $rp ) ) {
                    $rel_del = true;
                    $rel_deleted_count++;
                } else {
                    $rel_del_error = true;
                }
            }

            if ( ! $rel_valid ) {
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

            if ( $rel_del && ! $rel_del_error ) {
                $ctx['deleted_urls'][] = $rel['original'];
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: related page URL */
                    __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
            } elseif ( $rel_del && $rel_del_error ) {
                $ctx['deleted_urls'][] = $rel['original'];
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %1$d: deleted count, %2$d: total count, %3$s: related page URL */
                    __( 'INFO PARTIAL: %1$d of %2$d cache variants deleted for related page %3$s (INDEX). One or more variants could not be removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel_deleted_count,
                    count( $rel_paths ),
                    $rel['decoded']
                ), true, false );
            } else {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: related page URL */
                    __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for related page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
            }
            unset( $ctx['related_pending'][ $rel_key ] );
        }
    }
    unset( $nppp_index );

    // Everything resolved via FP1 and/or FP2
    if ( $ctx['primary_resolved'] && empty( $ctx['related_pending'] ) ) {
        nppp_purge_post_purge( $ctx, $ctx['primary_found'], $ctx['fp2_trigger_preload'] );
        return true;
    }

    return false;
}

/**
 * FP3: Scan the cache directory with ripgrep.
 *
 * Walks the cache ONCE for ALL remaining targets (primary if unresolved + all
 * pending related URLs). Accumulates related-URL candidate paths and deletes
 * them in bulk after the line-processing loop.
 */
function nppp_purge_fp3_rg( array &$ctx ): string {
    $nginx_cache_settings = $ctx['nginx_cache_settings'];
    if ( ! isset( $nginx_cache_settings['nppp_rg_purge_enabled'] )
        || $nginx_cache_settings['nppp_rg_purge_enabled'] !== 'yes'
    ) {
        return 'skip';
    }

    nppp_prepare_request_env();
    $nppp_rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
    if ( $nppp_rg_bin === '' ) {
        return 'skip';
    }

    $wp_filesystem            = $ctx['wp_filesystem'];
    $current_page_url_decoded = $ctx['current_page_url_decoded'];
    $url_to_search_exact      = $ctx['url_to_search_exact'];
    $regex                    = $ctx['regex'];

    // Build a combined pattern for all unresolved targets.
    $rg_target_keys = [];
    if ( ! $ctx['primary_resolved'] ) {
        $rg_target_keys[] = $url_to_search_exact;
    }
    foreach ( array_keys( $ctx['related_pending'] ) as $rk ) {
        $rg_target_keys[] = $rk;
    }

    $url_alts = implode( '|', array_map(
        fn( string $u ): string => preg_quote( $u, '/' ) . '$',
        $rg_target_keys
    ));

    $nppp_rg_cmd = sprintf(
        '%s -m 1 --text -E none --no-unicode --no-messages --no-ignore --no-config %s %s',
        escapeshellarg( $nppp_rg_bin ),
        escapeshellarg( '^KEY: .*(' . $url_alts . ')' ),
        escapeshellarg( $ctx['nginx_cache_path'] )
    );

    $nppp_rg_out  = [];
    $nppp_rg_exit = 0;
    exec( $nppp_rg_cmd, $nppp_rg_out, $nppp_rg_exit );

    // Exit code 2 = rg I/O or permission error inside the cache path.
    if ( $nppp_rg_exit === 2 ) {
        nppp_display_admin_notice( 'error', sprintf(
            /* translators: %s: full page URL */
            __( 'ERROR RG PURGE: Nginx cache purge for page %s was aborted due to a permission or I/O error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ) );
        nppp_purge_post_purge( $ctx, false, false );
        return 'error';
    }

    $nppp_fp3_found         = false;
    $nppp_fp3_any_deleted   = false;
    $nppp_fp3_regex_tested  = false;
    $nppp_fp3_deleted_paths = [];

    // Collect ALL candidate paths per related URL before deleting.
    $rg_related_candidates  = [];
    $rg_related_path_errors = [];

    // Branch A: rg exit 0 — at least one candidate line returned.
    if ( ! empty( $nppp_rg_out ) ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: full page URL */
            __( 'INFO RG HIT: Candidates found verifying and purging for %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ), true, false );

        $nppp_rg_lines = array_filter( $nppp_rg_out, 'strlen' );

        foreach ( $nppp_rg_lines as $nppp_rg_raw_line ) {
            $nppp_rg_raw_line = trim( $nppp_rg_raw_line );
            if ( $nppp_rg_raw_line === '' ) {
                continue;
            }

            // Limit splits so the KEY value is never truncated.
            $nppp_rg_parts = explode( ':', $nppp_rg_raw_line, 2 );
            if ( count( $nppp_rg_parts ) < 2 ) {
                continue;
            }

            $nppp_rg_candidate = trim( $nppp_rg_parts[0] );
            $nppp_rg_key_line  = trim( $nppp_rg_parts[1] );

            if ( $nppp_rg_candidate === '' || $nppp_rg_key_line === '' ) {
                continue;
            }

            // 1. Non-GET filter.
            foreach ( [ 'POST', 'HEAD', 'PUT', 'DELETE', 'PATCH', 'OPTIONS' ] as $nppp_method ) {
                if ( strpos( $nppp_rg_key_line, $nppp_method ) !== false ) {
                    continue 2;
                }
            }

            // 2. Regex extract + URL match.
            $nppp_fp3_rx = [];
            if ( ! $nppp_fp3_regex_tested ) {
                if ( preg_match( $regex, $nppp_rg_key_line, $nppp_fp3_rx )
                    && isset( $nppp_fp3_rx[1], $nppp_fp3_rx[2] )
                ) {
                    $nppp_fp3_test_url = 'https://' . trim( $nppp_fp3_rx[1] ) . trim( $nppp_fp3_rx[2] );
                    if ( filter_var( $nppp_fp3_test_url, FILTER_VALIDATE_URL ) ) {
                        $nppp_fp3_regex_tested = true;
                    } else {
                        nppp_display_admin_notice( 'error', sprintf(
                            /* translators: %s: full page URL */
                            __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is parsing <strong>\$host\$request_uri</strong> portion correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $current_page_url_decoded
                        ) );
                        nppp_purge_post_purge( $ctx, false, false );
                        return 'error';
                    }
                } else {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: full page URL */
                        __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is configured correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $current_page_url_decoded
                    ) );
                    nppp_purge_post_purge( $ctx, false, false );
                    return 'error';
                }
            } elseif ( ! ( preg_match( $regex, $nppp_rg_key_line, $nppp_fp3_rx )
                && isset( $nppp_fp3_rx[1], $nppp_fp3_rx[2] ) )
            ) {
                continue;
            }

            $constructed = trim( $nppp_fp3_rx[1] ) . trim( $nppp_fp3_rx[2] );

            // Primary match — note: $ctx['primary_resolved'] is intentionally NOT set
            // here inside the loop. It is set after the loop once deletion is confirmed,
            // so that multiple cache variants of the same URL can all be collected first.
            if ( ! $ctx['primary_resolved'] && $constructed === $url_to_search_exact ) {

                // 3. Permission check.
                if ( ! $wp_filesystem->is_writable( $nppp_rg_candidate ) ) {
                    nppp_purge_flush_write_back( $ctx );
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: full page URL */
                        __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $current_page_url_decoded
                    ) );
                    foreach ( $ctx['related_pending'] as $rel_abandoned ) {
                        nppp_display_admin_notice( 'info', sprintf(
                            /* translators: %s: related page URL */
                            __( 'INFO ADMIN: Related page %s purge skipped — aborted due to a permission error on the primary page scan (RG).', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $rel_abandoned['decoded']
                        ), true, false );
                    }
                    nppp_purge_post_purge( $ctx, false, false );
                    return 'error';
                }

                // 4. Path validation.
                $nppp_rg_validation = nppp_validate_path( $nppp_rg_candidate, true );
                if ( $nppp_rg_validation !== true ) {
                    switch ( $nppp_rg_validation ) {
                        case 'critical_path':
                            $error_message = sprintf(
                                /* translators: %s: full page URL */
                                __( 'ERROR PATH: A cache variant for page %s was skipped (RG) — its path points outside the allowed cache roots or resolves via a symlink to a restricted location.', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $current_page_url_decoded
                            );
                            break;
                        case 'file_not_found_or_not_readable':
                            $error_message = sprintf(
                                /* translators: %s: full page URL */
                                __( 'ERROR PATH: A cache variant for page %s was skipped (RG) — the file no longer exists (possibly removed by a concurrent operation).', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $current_page_url_decoded
                            );
                            break;
                        default:
                            $error_message = sprintf(
                                /* translators: %s: full page URL */
                                __( 'ERROR PATH: A cache variant for page %s was skipped (RG) — invalid path detected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $current_page_url_decoded
                            );
                    }
                    nppp_display_admin_notice( 'error', $error_message, true, false );
                    continue;
                }

                // 5. Delete.
                $nppp_fp3_found = true;
                if ( $wp_filesystem->delete( $nppp_rg_candidate ) ) {
                    $nppp_fp3_any_deleted                         = true;
                    $nppp_fp3_deleted_paths[]                     = $nppp_rg_candidate;
                    $ctx['write_back'][ $url_to_search_exact ][]  = $nppp_rg_candidate;
                    $ctx['deleted_urls'][]                        = $ctx['current_page_url'];
                } else {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: full page URL */
                        __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                        $current_page_url_decoded
                    ) );
                }
                continue;
            }

            // Related match
            if ( isset( $ctx['related_pending'][ $constructed ] ) ) {
                $rel = $ctx['related_pending'][ $constructed ];

                if ( ! $wp_filesystem->is_writable( $nppp_rg_candidate ) ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: related page URL */
                        __( 'ERROR PERMISSION: Nginx cache purge for related page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $rel['decoded']
                    ), true, false );
                    // Mark as handled (permission failure) — no retry.
                    $rg_related_path_errors[ $constructed ] = true;
                    continue;
                }

                $rel_rg_validation = nppp_validate_path( $nppp_rg_candidate, true );
                if ( $rel_rg_validation !== true ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: related page URL */
                        __( 'ERROR PATH: A cache variant for related page %s was skipped (RG) — invalid path detected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $rel['decoded']
                    ), true, false );
                    $rg_related_path_errors[ $constructed ] = true;
                    continue;
                }

                // Accumulate — delete after collecting all variants.
                $rg_related_candidates[ $constructed ][] = $nppp_rg_candidate;
                continue;
            }
        }

        // Delete all accumulated related variants
        foreach ( $rg_related_candidates as $rel_key => $rel_paths ) {
            $rel         = $ctx['related_pending'][ $rel_key ];
            $rel_deleted = 0;

            foreach ( $rel_paths as $rp ) {
                if ( $wp_filesystem->delete( $rp ) ) {
                    $rel_deleted++;
                    $ctx['write_back'][ $rel_key ][] = $rp;
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
                $ctx['deleted_urls'][] = $rel['original'];
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: related page URL */
                    __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rel['decoded']
                ), true, false );
            }
            unset( $ctx['related_pending'][ $rel_key ] );
        }

        // Any related still in $related_pending
        foreach ( array_keys( $ctx['related_pending'] ) as $rel_key ) {
            if ( isset( $rg_related_path_errors[ $rel_key ] ) ) {
                unset( $ctx['related_pending'][ $rel_key ] );
                continue;
            }
            if ( ! $ctx['chain_autopreload'] ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: related page URL */
                    __( 'INFO ADMIN: Nginx cache purge attempted, but the related page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $ctx['related_pending'][ $rel_key ]['decoded']
                ), true, false );
            }
            unset( $ctx['related_pending'][ $rel_key ] );
        }

        // Primary multi-variant + write-back notices.
        if ( ! empty( $nppp_fp3_deleted_paths ) ) {
            if ( count( $nppp_fp3_deleted_paths ) > 1 ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %1$d: variant count, %2$s: full page URL */
                    __( 'INFO MULTI-VARIANT: %1$d cache variants deleted for page %2$s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    count( $nppp_fp3_deleted_paths ),
                    $current_page_url_decoded
                ), true, false );
            }
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: full page URL */
                __( 'INFO INDEX WRITE-BACK: Index updated after (RG) scan for: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $current_page_url_decoded
            ), true, false );
        }

        nppp_purge_flush_write_back( $ctx );

        // Primary result notices + update context.
        // primary_resolved is set here after the loop (not inside it) because rg
        // may return multiple lines for the same URL (cache variants). All must be
        // processed before the URL is considered resolved.
        if ( ! $ctx['primary_resolved'] ) {
            $ctx['primary_found'] = $nppp_fp3_any_deleted;
            if ( $nppp_fp3_found && $nppp_fp3_any_deleted ) {
                if ( ! $ctx['chain_autopreload'] ) {
                    nppp_display_admin_notice( 'success', sprintf(
                        /* translators: %s: full page URL */
                        __( 'SUCCESS ADMIN: Nginx cache purged for page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $current_page_url_decoded
                    ) );
                }
            } elseif ( $nppp_fp3_found && ! $nppp_fp3_any_deleted ) {
                // Found in cache but all variants failed deletion or path validation.
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: full page URL */
                    __( "ERROR UNKNOWN: Page %s was found in cache but could not be deleted (RG). Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                    $current_page_url_decoded
                ) );
            } elseif ( ! $nppp_fp3_found && ! $ctx['chain_autopreload'] ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: full page URL */
                    __( 'INFO ADMIN: Nginx cache purge attempted, but the page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $current_page_url_decoded
                ) );
            }
        }

        nppp_purge_post_purge( $ctx, $ctx['primary_found'] );
        return 'done';

    // Branch B: rg exit 1 — no lines matched any target URL in the cache directory.
    } else {
        if ( ! $ctx['primary_resolved'] && ! $ctx['chain_autopreload'] ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: full page URL */
                __( 'INFO ADMIN: Nginx cache purge attempted, but the page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $current_page_url_decoded
            ) );
        }
        foreach ( array_keys( $ctx['related_pending'] ) as $rel_key ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: related page URL */
                __( 'INFO ADMIN: Nginx cache purge attempted, but the related page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $ctx['related_pending'][ $rel_key ]['decoded']
            ), true, false );
            unset( $ctx['related_pending'][ $rel_key ] );
        }
        nppp_purge_flush_write_back( $ctx );
        nppp_purge_post_purge( $ctx, $ctx['primary_resolved'] ? $ctx['primary_found'] : false );
        return 'done';
    }
}

/**
 * FP4: Recursive PHP iterator scan — unified, single directory walk.
 *
 * Matches primary (if not yet resolved) AND all remaining related URLs in one
 * pass. Early exit fires as soon as every target is accounted for — avoids
 * scanning the entire cache when targets are found in the first fraction of files.
 * Related-URL candidates are accumulated during the walk and deleted in bulk
 * after the loop completes (same pattern as FP3).
 */
function nppp_purge_fp4_scan( array &$ctx ): string {
    $wp_filesystem            = $ctx['wp_filesystem'];
    $current_page_url_decoded = $ctx['current_page_url_decoded'];
    $url_to_search_exact      = $ctx['url_to_search_exact'];
    $regex                    = $ctx['regex'];
    $head_bytes_primary       = $ctx['head_bytes_primary'];
    $head_bytes_fallback      = $ctx['head_bytes_fallback'];

    $fp4_found               = false;
    $fp4_any_deleted         = false;
    $regex_tested            = false;
    $fp4_deleted_paths       = [];
    $fp4_primary_candidates  = [];
    $fp4_related_candidates  = [];
    $fp4_related_path_errors = [];

    try {
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $ctx['nginx_cache_path'], RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $cache_iterator as $file ) {

            // Early exit: primary accounted for AND every related URL is either
            // accumulated in $fp4_related_candidates or permanently errored.
            // array_diff_key finds related_pending keys not yet handled.
            // + is array union (not merge): error keys shadow candidate keys,
            // giving a combined "already handled" set to diff against.
            if ( $ctx['primary_resolved']
                && empty( array_diff_key( $ctx['related_pending'], $fp4_related_candidates + $fp4_related_path_errors ) )
            ) {
                break;
            }

            // Any unreadable/unwritable file = cache directory integrity failure.
            if ( ! $file->isReadable() || ! $file->isWritable() ) {
                nppp_purge_flush_write_back( $ctx );
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: full page URL */
                    __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted — a permission issue was detected in the cache directory. This may indicate a cache path integrity problem (e.g. broken bindfs sync). Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $current_page_url_decoded
                ) );
                nppp_purge_post_purge( $ctx, false, false );
                return 'error';
            }

            $pathname = $file->getPathname();
            $content  = nppp_read_head( $wp_filesystem, $pathname, $head_bytes_primary );
            if ( $content === '' ) {
                continue;
            }

            // Locate the KEY header; retry with a larger read window if not found.
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

            // Skip redirect responses — they are not cacheable GET responses.
            if ( strpos( $content, 'Status: 301 Moved Permanently' ) !== false ||
                 strpos( $content, 'Status: 302 Found' ) !== false
            ) {
                continue;
            }

            // Skip non-GET cache entries.
            $key_line = $match[1];
            foreach ( [ 'POST', 'HEAD', 'PUT', 'DELETE', 'PATCH', 'OPTIONS' ] as $nppp_method ) {
                if ( strpos( $key_line, $nppp_method ) !== false ) {
                    continue 2;
                }
            }

            // Validate the regex once against the first viable file.
            if ( ! $regex_tested ) {
                if ( preg_match( $regex, $content, $matches ) && isset( $matches[1], $matches[2] ) ) {
                    $host            = trim( $matches[1] );
                    $request_uri     = trim( $matches[2] );
                    $constructed_url = $host . $request_uri;
                    if ( $constructed_url !== '' && filter_var( 'https://' . $constructed_url, FILTER_VALIDATE_URL ) ) {
                        $regex_tested = true;
                    } else {
                        nppp_display_admin_notice( 'error', sprintf(
                            /* translators: %s: full page URL */
                            __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is parsing <strong>\$host\$request_uri</strong> portion correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $current_page_url_decoded
                        ) );
                        nppp_purge_post_purge( $ctx, false, false );
                        return 'error';
                    }
                } else {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: full page URL */
                        __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is configured correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $current_page_url_decoded
                    ) );
                    nppp_purge_post_purge( $ctx, false, false );
                    return 'error';
                }
            }

            // Extract host + request_uri from the KEY header.
            $matches = [];
            if ( ! ( preg_match( $regex, $content, $matches ) && isset( $matches[1], $matches[2] ) ) ) {
                continue;
            }

            $host        = trim( $matches[1] );
            $request_uri = trim( $matches[2] );
            $constructed = $host . $request_uri;

            // Primary URL match
            if ( ! $ctx['primary_resolved'] && $constructed === $url_to_search_exact ) {
                $cache_path = $file->getPathname();
                $fp4_found  = true;

                $validation_result = nppp_validate_path( $cache_path, true );
                if ( $validation_result !== true ) {
                    switch ( $validation_result ) {
                        case 'critical_path':
                            $error_message = sprintf(
                                /* translators: %s: full page URL */
                                __( 'ERROR PATH: A cache variant for page %s was skipped (SCAN) — its path points outside the allowed cache roots or resolves via a symlink to a restricted location.', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $current_page_url_decoded
                            );
                            break;
                        case 'file_not_found_or_not_readable':
                            $error_message = sprintf(
                                /* translators: %s: full page URL */
                                __( 'ERROR PATH: A cache variant for page %s was skipped (SCAN) — the file no longer exists (possibly removed by a concurrent operation).', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $current_page_url_decoded
                            );
                            break;
                        default:
                            $error_message = sprintf(
                                /* translators: %s: full page URL */
                                __( 'ERROR PATH: A cache variant for page %s was skipped (SCAN) — invalid path detected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $current_page_url_decoded
                            );
                    }
                    nppp_display_admin_notice( 'error', $error_message, true, false );
                    continue;
                }

                $fp4_primary_candidates[] = $cache_path;
                continue;
            }

            // Related URL match
            if ( isset( $ctx['related_pending'][ $constructed ] ) ) {
                $cache_path = $file->getPathname();

                $rel_validation = nppp_validate_path( $cache_path, true );
                if ( $rel_validation !== true ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: related page URL */
                        __( 'ERROR PATH: A cache variant for related page %s was skipped (SCAN) — invalid path detected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $ctx['related_pending'][ $constructed ]['decoded']
                    ), true, false );
                    $fp4_related_path_errors[ $constructed ] = true;
                    continue;
                }

                $fp4_related_candidates[ $constructed ][] = $cache_path;
                continue;
            }
        }

    } catch ( Exception $e ) {
        nppp_purge_flush_write_back( $ctx );
        nppp_display_admin_notice( 'error', sprintf(
            /* translators: %s: full page URL */
            __( 'ERROR PERMISSION: Nginx cache purge failed for page %s due to permission issue (SCAN). Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ) );
        nppp_purge_post_purge( $ctx, false, false );
        return 'error';
    }

    // Post-scan: set primary_resolved and delete accumulated primary candidates.
    // Split into two steps intentionally: primary_resolved must be true before
    // the deletion loop so the early-exit in the iterator above works correctly
    // in any future re-entrant scenario, and to keep state updates explicit.
    if ( ! empty( $fp4_primary_candidates ) ) {
        $ctx['primary_resolved'] = true;
    }

    if ( ! empty( $fp4_primary_candidates ) ) {
        foreach ( $fp4_primary_candidates as $cache_path ) {
            if ( $wp_filesystem->delete( $cache_path ) ) {
                $fp4_any_deleted                             = true;
                $fp4_deleted_paths[]                         = $cache_path;
                $ctx['write_back'][ $url_to_search_exact ][] = $cache_path;
                $ctx['deleted_urls'][]                       = $ctx['current_page_url'];
            } else {
                nppp_display_admin_notice( 'error', sprintf(
                    __( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
                    $current_page_url_decoded
                ) );
            }
        }
    }

    // Post-scan: delete accumulated related candidates
    foreach ( $fp4_related_candidates as $rel_key => $rel_paths ) {
        $rel         = $ctx['related_pending'][ $rel_key ];
        $rel_deleted = 0;

        foreach ( $rel_paths as $rp ) {
            if ( $wp_filesystem->delete( $rp ) ) {
                $rel_deleted++;
                $ctx['write_back'][ $rel_key ][] = $rp;
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
            $ctx['deleted_urls'][] = $rel['original'];
            nppp_display_admin_notice( 'success', sprintf(
                /* translators: %s: related page URL */
                __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rel['decoded']
            ), true, false );
        }
        unset( $ctx['related_pending'][ $rel_key ] );
    }

    // Post-scan: primary multi-variant + write-back notices
    if ( ! empty( $fp4_deleted_paths ) ) {
        if ( count( $fp4_deleted_paths ) > 1 ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %1$d: variant count, %2$s: full page URL */
                __( 'INFO MULTI-VARIANT: %1$d cache variants deleted for page %2$s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                count( $fp4_deleted_paths ),
                $current_page_url_decoded
            ), true, false );
        }
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: full page URL */
            __( 'INFO INDEX WRITE-BACK: Index updated for %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ), true, false );
    }

    // Any related still pending
    foreach ( array_keys( $ctx['related_pending'] ) as $rel_key ) {
        if ( isset( $fp4_related_path_errors[ $rel_key ] ) ) {
            unset( $ctx['related_pending'][ $rel_key ] );
            continue;
        }
        if ( ! $ctx['chain_autopreload'] ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: related page URL */
                __( 'INFO ADMIN: Nginx cache purge attempted, but the related page %s is not currently found in the cache (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $ctx['related_pending'][ $rel_key ]['decoded']
            ), true, false );
        }
        unset( $ctx['related_pending'][ $rel_key ] );
    }

    nppp_purge_flush_write_back( $ctx );

    // Primary result notices
    $ctx['primary_found'] = $fp4_any_deleted;
    if ( $fp4_found && $fp4_any_deleted ) {
        if ( ! $ctx['chain_autopreload'] ) {
            nppp_display_admin_notice( 'success', sprintf(
                /* translators: %s: full page URL */
                __( 'SUCCESS ADMIN: Nginx cache purged for page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $current_page_url_decoded
            ) );
        }
    } elseif ( $fp4_found && ! $fp4_any_deleted ) {
        // Found in cache but all variants failed deletion or path validation.
        nppp_display_admin_notice( 'error', sprintf(
            /* translators: %s: full page URL */
            __( "ERROR UNKNOWN: Page %s was found in cache but could not be deleted (SCAN). Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ) );
    } elseif ( ! $fp4_found && ! $ctx['primary_resolved'] && ! $ctx['chain_autopreload'] ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: full page URL */
            __( 'INFO ADMIN: Nginx cache purge attempted, but the page %s is not currently found in the cache (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $current_page_url_decoded
        ) );
    }

    nppp_purge_post_purge( $ctx, $ctx['primary_found'] );
    return 'done';
}

// Returns the pretty permalink for a post regardless of its current post_status.
// get_permalink() calls wp_force_plain_post_permalink() internally. For any status
// that is not publicly viewable (draft, pending, trash), that function returns true
// and get_permalink() falls back to home_url('?p='.$post->ID)
function nppp__get_published_permalink( WP_Post $post ) {
    if ( $post->post_status === 'publish' ) {
        return get_permalink( $post->ID );
    }
    $clone              = clone $post;
    $clone->post_status = 'publish';

    // WP renames post_name to slug__trashed via wp_add_trashed_suffix_to_post_name()
    if ( $post->post_status === 'trash' ) {
        $clone->post_name = preg_replace( '/__trashed$/', '', $post->post_name );
    }

    return get_permalink( $clone );
}

// Auto Purge (Single)
// Purges the Nginx FastCGI cache for a single post/page URL when its content
// or status changes in WordPress.
//
// This function hooks into 'transition_post_status' which fires for EVERY post
// type on EVERY status change — including private WooCommerce orders, cron jobs,
// autosaves, and REST API requests. The guard chain below filters out all
// non-cacheable events before any filesystem work is done.
//
// Execution flow:
//   1. Bail early for invalid objects, disabled auto-purge, and private post types.
//   2. Skip REST requests already handled by compat-gutenberg.php
//      (only when show_in_rest=true + wp/v2 namespace + default controller).
//   3. Skip AJAX requests unless they are known page-builder save actions.
//   4. Skip WP-Cron runs unless a scheduled post is going live (future→publish).
//   5. Skip revisions, autosaves, and per-request duplicates.
//   6. Resolve the public-facing URL, then purge the matching cache file.
//
// Purge triggers (after all guards pass):
//   - publish → draft/trash/pending/private  (post taken offline)
//   - future/draft/pending/trash → publish   (post going live)
//   - publish → publish                      (content updated)
//
// Related compat files that handle their own purge paths independently:
//   - compat-gutenberg.php   REST saves via Gutenberg block editor (rest_after_insert_{type})
//   - compat-elementor.php   Elementor editor saves (elementor/document/after_save)
//   - compat-woocommerce.php WooCommerce stock changes (woocommerce_product_set_stock etc.)
function nppp_purge_cache_on_update($new_status, $old_status, $post) {
    static $did_purge = [];

    // Bail on invalid post objects.
    if (! ($post instanceof WP_Post)) {
        return;
    }

    // Early quit if auto purge disabled
    $nginx_cache_settings = get_option('nginx_cache_settings');
    if (($nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no') !== 'yes') {
        return;
    }

    // Skip non-publicly-viewable post types entirely.
    // is_post_type_viewable() checks publicly_queryable (custom types) or
    // public (built-in types). Returns false automatically for ALL private CPTs:
    // shop_order, shop_order_refund, shop_order_placehold, wc_order,
    // shop_coupon, shop_webhook, shop_subscription, scheduled-action,
    // edd_*, gravity forms entries, and any future private CPT
    if ( ! is_post_type_viewable( $post->post_type ) ) {
        return;
    }

    // Avoid duplicate purge with Gutenberg + metabox second request.
    // When block editor saves legacy metaboxes it posts to post.php with this flag.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_REQUEST['meta-box-loader'])) {
        return;
    }

    // Skip REST only when compat-gutenberg actually covers this post type.
    // Requires show_in_rest=true AND the default wp/v2 namespace (which fires
    // rest_after_insert_{type}). WooCommerce wc/v3 and custom controllers
    // do NOT fire that hook — fall through and purge here instead.
    if (
        ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST )
    ) {
        $post_type_obj      = get_post_type_object( $post->post_type );
        $rest_namespace     = $post_type_obj->rest_namespace ?? false;
        $rest_controller    = $post_type_obj->rest_controller_class ?? false;

        // Only bail if compat-gutenberg actually covers this:
        // 1. show_in_rest=true
        // 2. namespace is wp/v2
        // 3. using the DEFAULT controller (or none set) — custom controllers
        //    do NOT fire rest_after_insert_{type}
        $using_default_controller = (
            ! $rest_controller ||
            $rest_controller === 'WP_REST_Posts_Controller'
        );

        if (
            ! empty( $post_type_obj->show_in_rest ) &&
            $rest_namespace === 'wp/v2' &&
            $using_default_controller
        ) {
            return;
        }
    }

    // Allow only specific AJAX save routes (Quick/Bulk Edit).
    $allowed_ajax_actions = [
        'inline-save',             // Quick Edit
        'vc_save',                 // WPBakery
        'et_fb_ajax_save',         // Divi Builder
        'bricks_save_post',        // Bricks Builder
        'ct_save_components_tree', // Oxygen Builder
    ];

    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $current_ajax_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

    if (
        ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) &&
        ! in_array( $current_ajax_action, $allowed_ajax_actions, true )
    ) {
        return;
    }

    // Skip WP-Cron runs — except when a scheduled post is going live.
    // future→publish during cron is legitimate and must purge.
    // All other cron transitions (e.g. wp_scheduled_delete) are skipped.
    if (
        ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ||
        ( defined( 'DOING_CRON' ) && DOING_CRON )
    ) {
        if ( 'publish' !== $new_status ) {
            return;
        }
    }

    // Per-request guard
    if (isset($did_purge[$post->ID])) {
        return;
    }

    // Sanity checks: no revisions, autosaves, or auto-drafts
    if (wp_is_post_revision($post)
      || wp_is_post_autosave($post)
      || $new_status === 'auto-draft'
      || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
    ) {
        return;
    }

    // Build permalink
    $post_url = nppp__get_published_permalink( $post );

    // Guard: bail if URL cannot be resolved.
    if ( ! $post_url ) {
        return;
    }

    // Prep cache path
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : '/dev/shm/change-me-now';

    // Published post taken offline (trash / draft / pending / private).
    if ( 'publish' === $old_status && 'publish' !== $new_status ) {
        nppp_purge_single( $nginx_cache_path, $post_url, true );
        $did_purge[ $post->ID ] = true;
        return;
    }

    // Handle Status Changes (post going live — from trash, draft, or pending).
    if ('publish' === $new_status) {
        // If the post was moved from trash to publish, purge the cache
        if ('trash' === $old_status) {
            nppp_purge_single($nginx_cache_path, $post_url, true);
            $did_purge[ $post->ID ] = true;
            return;
        }

        // If the post is published from draft, pending, or any other state, purge the cache
        if ('publish' !== $old_status) {
            nppp_purge_single($nginx_cache_path, $post_url, true);
            $did_purge[ $post->ID ] = true;
            return;
        }
    }

    // Handle Content Updates (publish to publish).
    // Quick Edit (inline-save AJAX) always purges — slug/category changes don't bump modified time.
    // Standard editor saves: post_modified_gmt > post_date_gmt for any post saved after initial
    // publish, which is effectively always true. Every publish→publish save purges — correct
    if ('publish' === $new_status && 'publish' === $old_status) {
        $is_quick_edit = ( $current_ajax_action === 'inline-save' );

        // Quick Edit often adjust slug/taxonomies only; always purge.
        if ($is_quick_edit) {
            nppp_purge_single( $nginx_cache_path, $post_url, true );
            $did_purge[ $post->ID ] = true;
            return;
        }

        // Check if the content was updated (modified time differs from the original post time)
        if (get_post_modified_time('U', true, $post) > get_post_time('U', true, $post)) {
            nppp_purge_single($nginx_cache_path, $post_url, true);
            $did_purge[ $post->ID ] = true;
            return;
        }
    }
}

// Auto Purge (Entire)
// Purge entire cache automatically for plugin or theme (active) updates.
// This function hooks into the 'upgrader_process_complete' action
function nppp_purge_cache_on_theme_plugin_update($upgrader, $hook_extra) {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Retrieve necessary options for purge actions
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Check for the theme update
        if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === 'theme' ) {
            $active_theme   = wp_get_theme()->get_stylesheet();
            $updated_themes = $hook_extra['themes']                                        // bulk
                ?? ( isset( $hook_extra['theme'] ) ? [ $hook_extra['theme'] ] : [] );      // single

            if ( ! empty( $updated_themes ) && in_array( $active_theme, $updated_themes, true ) ) {
                nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
            }
        }

        // Check for the plugin update
        if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === 'plugin' ) {
            $updated_plugins = $hook_extra['plugins']                                      // bulk
                ?? ( isset( $hook_extra['plugin'] ) ? [ $hook_extra['plugin'] ] : [] );    // single

            if ( ! empty( $updated_plugins ) ) {
                nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
            }
        }
    }
}

// Auto Purge (Entire)
// Purge entire cache when WordPress finishes a background automatic update.
// This function hooks into the 'automatic_updates_complete' action.
function nppp_purge_cache_on_auto_update( $results = [] ) {
    $nginx_cache_settings = get_option( 'nginx_cache_settings' );
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    $nginx_cache_path = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $PIDFILE          = nppp_get_runtime_file( 'cache_preload.pid' );
    $tmp_path         = rtrim( $nginx_cache_path, '/' ) . '/tmp';
    nppp_purge( $nginx_cache_path, $PIDFILE, $tmp_path, false, true, true );
}

// Auto Purge (Entire)
// Purge entire cache automatically for plugin activation & deactivation.
// This function hooks into the 'activated_plugin-deactivated_plugin' action
function nppp_purge_cache_plugin_activation_deactivation() {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Purge cache for plugin activation - deactivation
        nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
    }
}

// Auto Purge (Entire)
// Purge entire cache automatically for THEME switchs.
// This function hooks into the 'switch_theme' action
function nppp_purge_cache_on_theme_switch($new_name, $new_theme, $old_theme) {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Trigger the purge action
        nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
    }
}

// Auto Purge (Single)
// Purge cache when a post is permanently deleted from the classic admin.
// This function hooks into the 'delete_post' action.
function nppp_purge_cache_on_delete_post( $post_id, $post ) {
    if ( ! ( $post instanceof WP_Post ) ) {
        return;
    }

    // Skip revisions and auto-drafts — they are never cached.
    if ( wp_is_post_revision( $post ) || $post->post_status === 'auto-draft' ) {
        return;
    }

    // Skip private/internal post types — shop_order, wc_order, shop_coupon,
    // scheduled-action, etc. are never publicly cached. Without this guard,
    // permanently deleting a WooCommerce order triggers a full recursive scan.
    if ( ! is_post_type_viewable( $post->post_type ) ) {
        return;
    }

    // Only care about previously-public posts (publish) or trashed posts whose
    // pre-trash status was publish (cache was purged on trash but may still be
    // warm if auto-purge was toggled). For draft/pending there is nothing cached.
    $relevant_statuses = [ 'publish', 'trash' ];
    if ( ! in_array( $post->post_status, $relevant_statuses, true ) ) {
        return;
    }

    $nginx_cache_settings = get_option( 'nginx_cache_settings' );
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    // For trashed posts, restore the clean slug (WordPress appends __trashed).
    $clone = clone $post;
    if ( $post->post_status === 'trash' ) {
        $clone->post_status = 'publish';
        $clone->post_name   = preg_replace( '/__trashed$/', '', $post->post_name );
        $post_url = get_permalink( $clone );
    } else {
        $post_url = get_permalink( $post->ID );
    }

    if ( ! $post_url ) {
        return;
    }

    $nginx_cache_path = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    nppp_purge_single( $nginx_cache_path, $post_url, true );
}

// Auto Purge (Single)
// Purges the post page when its approved comment count changes.
//
// Hooked to: wp_update_comment_count (passes post_id, new_count, old_count)
//
// This hook only fires when the APPROVED comment count on a post actually
// increments or decrements. It never fires for spam / unapproved / trash
// transitions alone — those do not change the public comment count.
function nppp_purge_cache_on_comment_count( $post_id, $new_count, $old_count ) {
    // No actual count change — nothing visible changed for visitors.
    if ( (int) $new_count === (int) $old_count ) {
        return;
    }

    $nginx_cache_settings = get_option( 'nginx_cache_settings' );
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    // Skip private/internal post types.
    // Covers shop_order, wc_order, shop_coupon, scheduled-action, and any
    // future private CPT
    if ( ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
        return;
    }

    $post_url = get_permalink( $post_id );
    if ( ! $post_url ) {
        return;
    }

    $nginx_cache_path = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    nppp_purge_single( $nginx_cache_path, $post_url, true );
}

// Purge cache operation
function nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, $nppp_is_rest_api = false, $nppp_is_admin_bar = false, $nppp_is_auto_purge = false) {
    // On a large cache (100 k+ files)
    // on slow or network-attached storage this can easily exceed the default
    // 30-second ceiling that most PHP-FPM pools ship with, killing the process
    // mid-operation and leaving the purge lock (stored as a wp_options row via
    // WP_Upgrader::create_lock()) permanently orphaned until its TTL expires.

    // set_time_limit(0) resets the countdown to "unlimited" for this request
    // only — it has no effect on other processes or future requests.
    // The @ suppressor silences the E_WARNING that some hardened hosts emit
    // when the function appears in disable_functions; the call is otherwise
    // a safe no-op in that environment.

    // Note: this only disables PHP's own timer. PHP-FPM's independent
    // request_terminate_timeout and Nginx's fastcgi_read_timeout are enforced
    // by the FPM master process and the upstream proxy respectively and cannot
    // be overridden from PHP at runtime.
    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $options = get_option('nginx_cache_settings');

    // Set env
    nppp_prepare_request_env(true);

    $auto_preload = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes';

    // Prevent concurrent Purge All or single-page purge from another admin
    // session racing against this operation. Released via finally on all exits.
    if ( ! nppp_acquire_purge_lock( 'all' ) ) {
        nppp_display_admin_notice( 'info',
            __( 'INFO: Purge All skipped — another cache purge operation is already in progress. Please try again shortly.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Tracks whether the lock has been released inside the try block on
    // the success path. Prevents the finally block from double-releasing.
    $nppp_lock_released = false;

    try {

    // Phase key used in multiple branches below — declared once here.
    // Actual hook/transient cleanup is deferred until kill success is confirmed
    // so that if kill fails and we return early, the tick monitor keeps running.
    $nppp_phase_key = 'nppp_preload_phase_' . md5('nppp');

    // Initialize variables for messages
    $message_type = '';
    $message_content = '';

    // Check if the PID file exists
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        // Check if the preload process is alive
        if ($pid > 0 && nppp_is_process_alive($pid)) {
            $process_user = trim(shell_exec("ps -o user= -p " . escapeshellarg($pid)));
            $killed_by_safexec = false;

            if ($process_user === 'nobody') {
                $safexec_path = '/usr/bin/safexec';

                // If not present at default location, try to discover via system path
                if (!$wp_filesystem->exists($safexec_path)) {
                    $detected = trim(shell_exec('command -v safexec 2>/dev/null'));
                    if (!empty($detected)) {
                        $safexec_path = $detected;
                    } else {
                        $safexec_path = false;
                    }
                }

                // Check for safexec binary and SUID
                if ($safexec_path && function_exists('stat')) {
                    $is_root_owner = false;
                    $has_suid      = false;

                    $info = @stat($safexec_path);
                    if ($info && isset($info['uid'], $info['mode'])) {
                        $is_root_owner = ($info['uid'] === 0);
                        $has_suid      = ($info['mode'] & 04000) === 04000;
                    }

                    if ($is_root_owner && $has_suid) {
                        $output = shell_exec(escapeshellarg($safexec_path) . ' --kill=' . (int) $pid . ' 2>&1');

                        if (strpos($output, 'Killed PID') !== false) {
                            usleep(250000);

                            if (!nppp_is_process_alive($pid)) {
                                // Translators: %s is the process PID
                                nppp_display_admin_notice('success', sprintf( __( 'SUCCESS PROCESS: The ongoing Nginx cache Preload process (PID: %s) terminated using safexec', 'fastcgi-cache-purge-and-preload-nginx' ), $pid ), true, false);
                                $killed_by_safexec = true;
                            } else {
                                // Translators: %s is the process PID
                                nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: Failed to terminate using safexec, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                            }
                        }
                    } else {
                        // Translators: %s is the process PID
                        nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: safexec not privileged, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                    }
                } else {
                    // Translators: %s is the process PID
                    nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: safexec not found, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                }
            }

            if (!$killed_by_safexec) {
                $signal_sent = false;

                if (defined('SIGTERM')) {
                    @posix_kill($pid, SIGTERM);
                    $signal_sent = true;
                    usleep(300000);
                }

                if (nppp_is_process_alive($pid)) {
                    // Process still alive, try kill -9
                    $kill_path = trim(shell_exec('command -v kill'));
                    if (!empty($kill_path)) {
                        shell_exec(escapeshellarg($kill_path) . ' -9 ' . (int) $pid);
                        usleep(300000);

                        if (!nppp_is_process_alive($pid)) {
                            // Translators: %s is the process PID
                            nppp_display_admin_notice('success', sprintf(__('SUCCESS PROCESS: The ongoing Nginx cache Preload process (PID: %s) forcefully terminated (SIGKILL)', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                        } else {
                            nppp_display_admin_notice('error', __('ERROR PROCESS: Failed to stop the ongoing Nginx cache Preload process. Please wait for the Preload process to finish and try Purge All again.', 'fastcgi-cache-purge-and-preload-nginx'));
                            return;
                        }
                    } else {
                        nppp_display_admin_notice('error', __('ERROR PROCESS: "kill" command not available. Failed to stop the ongoing Nginx cache Preload process. Please wait for the Preload process to finish and try Purge All again.', 'fastcgi-cache-purge-and-preload-nginx'));
                        return;
                    }
                } else {
                    $method = $signal_sent ? 'SIGTERM' : 'check';
                    // Translators: %1$s is the PID, %2$s is the termination method (e.g., posix_kill, kill -9).
                    nppp_display_admin_notice('success', sprintf(__('SUCCESS PROCESS: Preload process (PID: %1$s) terminated using %2$s.', 'fastcgi-cache-purge-and-preload-nginx'), $pid, $method), true, false);
                }
            }

            // Kill confirmed — now safe to clear the tick monitor hook and phase state.
            // This is intentionally placed here and NOT at the top of the function,
            // because the two early-return paths above (SIGKILL failed, kill not found)
            // leave the process running. In those cases we must NOT destroy monitoring.
            wp_clear_scheduled_hook('npp_cache_preload_status_event');
            delete_transient($nppp_phase_key);
            delete_transient('nppp_preload_cycle_start_' . md5('nppp'));

            // Kill the watchdog after purge has already killed the preload
            nppp_kill_preload_watcher();
            nppp_watcher_delete_token();

            // If on-going preload action halted via purge
            // that means user restrictly wants to purge cache
            // If auto preload feature enabled this will cause recursive preload action
            // So if ongoing preload action halted by purge action set auto-reload false
            // to prevent recursive preload loop
            // v2.0.9: CAUTION
            // If triggered by auto-purge,
            // always rely on the actual status of the option to prevent
            // stopping auto-preloading actions during concurrent auto-purge actions.
            if (!$nppp_is_auto_purge) {
                $auto_preload = false;
            }

            // Call purge_helper to delete cache contents and get status
            $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    if ($nppp_is_rest_api) {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS REST: The ongoing Nginx cache preloading process has been halted. All Nginx cache has been purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } elseif ($nppp_is_admin_bar){
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS ADMIN: The ongoing Nginx cache preloading process has been halted. All Nginx cache has been purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } else {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS: The ongoing Nginx cache preloading process has been halted. All Nginx cache has been purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = __( 'ERROR PERMISSION: The ongoing Nginx cache preloading process was halted, but Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 3:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 5:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 6:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __('ERROR SECURITY: The Nginx cache path (%s) is inside or is a parent of the WordPress installation. Cache purge aborted. Please set the Nginx cache path to a dedicated cache-only location outside WordPress.', 'fastcgi-cache-purge-and-preload-nginx'), $nginx_cache_path);
                    break;
            }

            // Remove the PID file
            nppp_perform_file_operation($PIDFILE, 'delete');
        } else {
            // PIDFILE exists but PID is dead/stale — no live process to protect.
            // Safe to clear monitoring hook and phase state immediately.
            wp_clear_scheduled_hook('npp_cache_preload_status_event');
            delete_transient($nppp_phase_key);
            delete_transient('nppp_preload_cycle_start_' . md5('nppp'));

            // Kill the watchdog after purge has already killed the preload
            nppp_kill_preload_watcher();
            nppp_watcher_delete_token();

            // Call purge_helper to delete cache contents and get status
            $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    // Check auto preload status and defer message accordingly
                    if (!$auto_preload) {
                        if ($nppp_is_rest_api) {
                            $message_type = 'success';
                            $message_content = __( 'SUCCESS REST: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                        } elseif ($nppp_is_admin_bar){
                            $message_type = 'success';
                            $message_content = __( 'SUCCESS ADMIN: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                        } else {
                            $message_type = 'success';
                            $message_content = __( 'SUCCESS: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                        }
                    }
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = __( 'ERROR PERMISSION: The Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 2:
                    // Check auto preload status and defer message accordingly
                    if (!$auto_preload) {
                        $message_type = 'info';
                        $message_content = __( 'INFO: Nginx cache purge attempted, but no cache found.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                    break;
                case 3:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 5:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 6:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __('ERROR SECURITY: The Nginx cache path (%s) is inside or is a parent of the WordPress installation. Cache purge aborted. Please set the Nginx cache path to a dedicated cache-only location outside WordPress.', 'fastcgi-cache-purge-and-preload-nginx'), $nginx_cache_path);
                    break;
            }

            // Remove the PID file
            nppp_perform_file_operation($PIDFILE, 'delete');
        }
    } else {
        // No PIDFILE — no preload was running at all.
        // Safe to clear monitoring hook and phase state immediately.
        wp_clear_scheduled_hook('npp_cache_preload_status_event');
        delete_transient($nppp_phase_key);
        delete_transient('nppp_preload_cycle_start_' . md5('nppp'));

        // Kill the watchdog after purge has already killed the preload
        nppp_kill_preload_watcher();
        nppp_watcher_delete_token();

        // Call purge_helper to delete cache contents and get status
        $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

        // Determine message based on status
        switch ($status) {
            case 0:
                // Check auto preload status and defer message accordingly
                if (!$auto_preload) {
                    if ($nppp_is_rest_api) {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS REST: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } elseif ($nppp_is_admin_bar){
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS ADMIN: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } else {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                }
                break;
            case 1:
                $message_type = 'error';
                $message_content = __( 'ERROR PERMISSION: The Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 2:
                // Check auto preload status and defer message accordingly
                if (!$auto_preload) {
                    $message_type = 'info';
                    $message_content = __( 'INFO: Nginx cache purge attempted, but no cache found.', 'fastcgi-cache-purge-and-preload-nginx' );
                }
                break;
            case 3:
                $message_type = 'error';
                // Translators: %s is the Nginx cache path
                $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                break;
            case 4:
                $message_type = 'error';
                $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 5:
                $message_type = 'error';
                // Translators: %s is the Nginx cache path
                $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                break;
            case 6:
                $message_type = 'error';
                // Translators: %s is the Nginx cache path
                $message_content = sprintf( __('ERROR SECURITY: The Nginx cache path (%s) is inside or is a parent of the WordPress installation. Cache purge aborted. Please set the Nginx cache path to a dedicated cache-only location outside WordPress.', 'fastcgi-cache-purge-and-preload-nginx'), $nginx_cache_path);
                break;
        }
    }

    // All cache filesystem work done — release lock before post-purge
    // side effects (do_action hooks, preload spawn) so other admins
    // are not blocked during external or background operations.
    nppp_release_purge_lock();
    $nppp_lock_released = true;

    // Display the admin notice
    if (!empty($message_type) && !empty($message_content)) {
        if ($nppp_is_auto_purge) {
            nppp_display_admin_notice($message_type, $message_content, true, false);
        } else {
            nppp_display_admin_notice($message_type, $message_content);
        }
    }

    // Check if there was an error during the cache purge process
    if ($message_type === 'error') {
        return;
    }

    // Fire the 'nppp_purged' action, triggering any other plugin actions that are hooked into this event
    // If auto preload is enabled this hook will create both NPP cache and compatible plugin cache at the same time
    do_action('nppp_purged_all');

    // If set call preload immediately after purge
    if ($auto_preload) {
        // Get the plugin options
        $nginx_cache_settings = get_option('nginx_cache_settings');

        // Set default options to prevent any error
        $default_cache_path = '/dev/shm/change-me-now';
        $default_limit_rate = 1280;
        $default_cpu_limit = 100;
        $default_reject_regex = nppp_fetch_default_reject_regex();

        // Get the necessary data for preload action from plugin options
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
        $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
        $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

        // Extra data for preload action
        $fdomain = get_site_url();
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Check route of request
        $preload_is_rest_api = $nppp_is_rest_api ? true : false;
        $preload_is_admin_bar = $nppp_is_admin_bar ? true : false;

        // Start the preload action with auto preload on flag
        // This is the only route that auto preload passes "true" to preload action
        nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, true, $preload_is_rest_api, false, $preload_is_admin_bar);
    }

    } finally {
        if ( ! $nppp_lock_released ) {
            nppp_release_purge_lock();
        }
    }
}

// Callback function to trigger Purge All
function nppp_purge_callback() {
    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Get the necessary data for purge action from plugin options
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Call the main purge function
    nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, false, true);
}
