<?php
/**
 * Cache purge handlers for Nginx Cache Purge Preload
 * Description: Executes full and targeted purge operations for supported Nginx cache backends.
 * Version: 2.1.6
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
 * Purge a single page (and its related URLs) from the Nginx cache.
 * Tries FP1→FP2→FP3→FP4, stopping at the first path that resolves all pending targets.
 */
function nppp_purge_single( $nginx_cache_path, $current_page_url, $nppp_auto_purge = false ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }
    if ( function_exists( 'ignore_user_abort' ) ) {
        ignore_user_abort( true );
    }

    $ctx = nppp_purge_single_init( $nginx_cache_path, $current_page_url, $nppp_auto_purge );
    if ( $ctx === false ) {
        return;
    }

    try {
        if ( nppp_purge_fp1_http( $ctx ) ) return;
        if ( nppp_purge_fp2_index( $ctx ) ) return;
        if ( nppp_purge_fp3_rg( $ctx ) !== 'skip' ) return;
        nppp_purge_fp4_scan( $ctx );
    } finally {
        nppp_release_purge_lock();
    }
}

/**
 * Get index once.
 */
function nppp_purge_get_index(): array {
    $idx = get_option( 'nppp_url_filepath_index' );
    return is_array( $idx ) ? $idx : [];
}

/**
 * Validate inputs, acquire lock, and build the unified targets map.
 *
 * $ctx['targets'] is an immutable snapshot of all URLs to process (primary + related).
 * $ctx['pending'] is a mutable copy — FPs remove keys as targets are resolved.
 * Both are keyed by the scheme-stripped URL form used for cache-key matching.
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

    if ( filter_var( $current_page_url, FILTER_VALIDATE_URL ) === false ) {
        nppp_display_admin_notice( 'error', __( 'ERROR URL: URL can not validated.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        return false;
    }

    $decoded  = rawurldecode( $current_page_url );
    $settings = get_option('nginx_cache_settings', []);
    $PIDFILE  = nppp_get_runtime_file( 'cache_preload.pid' );

    if ( $wp_filesystem->exists( $PIDFILE ) ) {
        $pid = intval( nppp_perform_file_operation( $PIDFILE, 'read' ) );
        if ( $pid > 0 && nppp_is_process_alive( $pid ) ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: Current page URL */
                __( 'INFO: Single-page purge for %s skipped — Nginx cache preloading is in progress. Check the Status tab to monitor; wait for completion or use "Purge All" to cancel.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $decoded
            ) );
            return false;
        }
    }

    if ( ! nppp_acquire_purge_lock( 'single' ) ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: Current page URL */
            __( 'INFO: Single-page purge for %s skipped — another cache purge operation is already in progress. Please try again shortly.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $decoded
        ) );
        return false;
    }

    $auto_preload = ! empty( $settings['nginx_cache_auto_preload'] ) && $settings['nginx_cache_auto_preload'] === 'yes';
    $chain_autopreload = $auto_preload;
    $cache_key_regex_raw = ! empty( $settings['nginx_cache_key_custom_regex'] )
        ? base64_decode( $settings['nginx_cache_key_custom_regex'], true )
        : false;
    $regex = ( $cache_key_regex_raw !== false && $cache_key_regex_raw !== '' )
        ? $cache_key_regex_raw
        : nppp_fetch_default_regex_for_cache_key();

    $primary_key = preg_replace( '#^https?://#', '', $current_page_url );
    $targets     = [
        $primary_key => [
            'original'   => $current_page_url,
            'decoded'    => $decoded,
            'is_primary' => true,
        ],
    ];

    foreach ( nppp_get_related_urls_for_single( $current_page_url ) as $rel_url ) {
        if ( filter_var( $rel_url, FILTER_VALIDATE_URL ) !== false ) {
            $key = preg_replace( '#^https?://#', '', $rel_url );
            if ( ! isset( $targets[ $key ] ) ) {
                $targets[ $key ] = [
                    'original'   => $rel_url,
                    'decoded'    => rawurldecode( $rel_url ),
                    'is_primary' => false,
                ];
            }
        }
    }

    return [
        'wp_filesystem'     => $wp_filesystem,
        'nppp_index'        => nppp_purge_get_index(),
        'nginx_cache_path'  => $nginx_cache_path,
        'primary_url'       => $current_page_url,
        'primary_decoded'   => $decoded,
        'chain_autopreload' => $chain_autopreload,
        'nppp_auto_purge'   => $nppp_auto_purge,
        'regex'             => $regex,
        'head_bytes'        => (int) apply_filters( 'nppp_locate_head_bytes', 4096 ),
        'head_bytes_fb'     => (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 ),
        'settings'          => $settings,
        'targets'           => $targets,
        'pending'           => $targets,
        'write_back'        => [],
        'primary_purged'    => false,
    ];
}

/**
 * Flush all accumulated URL→path write-backs in one DB round-trip.
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

    nppp_display_admin_notice( 'info', sprintf(
        /* translators: 1: Number of URLs written back to index 2: Primary page URL */
        __( 'INFO INDEX WRITE-BACK: Index updated after scan for %1$d URL(s) including: %2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
        count( $ctx['write_back'] ),
        $ctx['primary_decoded']
    ), true, false );

    $ctx['write_back'] = [];
}

/**
 * Post-purge side-effects: auto-preload and action hook.
 *
 * $chain_autopreload is the sole authoritative condition for preload — the cache
 * entry may have been a hit or a miss; either way the page needs warming.
 * Primary is preloaded via nppp_preload_cache_on_update; related URLs are
 * dispatched fire-and-forget. Both use $ctx['targets'] (the full set from init).
 */
function nppp_purge_post_purge( array &$ctx ): void {
    $is_manual = ! $ctx['nppp_auto_purge'];

    // Full set of relateds
    $related_urls = [];
    foreach ( $ctx['targets'] as $target ) {
        if ( ! $target['is_primary'] ) {
            $related_urls[] = $target['original'];
        }
    }

    // Auto-preload
    if ( $ctx['chain_autopreload'] ) {
        nppp_preload_cache_on_update( $ctx['primary_url'], $ctx['primary_purged'], ! $ctx['nppp_auto_purge'] );
    }

    // Determine whether to also preload related URLs.
    $should_preload_related =
        ! empty( $ctx['settings']['nppp_related_preload_after_manual'] )
        && $ctx['settings']['nppp_related_preload_after_manual'] === 'yes'
        && ( $is_manual
            || ( ! empty( $ctx['settings']['nginx_cache_auto_preload'] )
                && $ctx['settings']['nginx_cache_auto_preload'] === 'yes' ) );

    // Trigger Preload related
    if ( $should_preload_related ) {
        if ( ! empty( $related_urls ) ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %d: Number of related pages queued for background preload */
                _n(
                    'INFO RELATED PRELOAD: %d related page queued for background preload.',
                    'INFO RELATED PRELOAD: %d related pages queued for background preload.',
                    count( $related_urls ),
                    'fastcgi-cache-purge-and-preload-nginx'
                ),
                count( $related_urls )
            ), true, false );
            nppp_preload_urls_fire_and_forget( $related_urls );
        }
    }

    // Fire the nppp_purged_urls action hook
    do_action(
        'nppp_purged_urls',
        array_column( $ctx['targets'], 'original' ),
        $ctx['primary_url'],
        (int) url_to_postid( $ctx['primary_url'] ),
        (bool) $ctx['nppp_auto_purge']
    );
}

/**
 * FP1: HTTP purge endpoint.
 *
 * Skips URLs that have multiple index variants (delegated to FP2 to handle all
 * variants atomically). On HTTP failure/unreachable, leaves the target in pending
 * so FP2+ can retry via filesystem.
 */
function nppp_purge_fp1_http( array &$ctx ): bool {
    if ( empty( $ctx['settings']['nppp_http_purge_enabled'] )
        || $ctx['settings']['nppp_http_purge_enabled'] !== 'yes'
    ) {
        return false;
    }

    $index = $ctx['nppp_index'];
    $cache_path_prefix = rtrim( $ctx['nginx_cache_path'], '/' ) . '/';

    foreach ( array_keys( $ctx['pending'] ) as $key ) {
        $target = $ctx['pending'][ $key ];

        // Multiple index variants under current prefix — delegate to FP2 for atomic removal.
        if ( isset( $index[ $key ] ) && is_array( $index[ $key ] ) ) {
            $prefix_count = 0;
            foreach ( $index[ $key ] as $path ) {
                if ( strpos( $path, $cache_path_prefix ) === 0 ) {
                    $prefix_count++;
                }
            }
            if ( $prefix_count > 1 ) {
                if ( $target['is_primary'] ) {
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %s: Page URL */
                        __( 'INFO HTTP PURGE BYPASS: Multiple cache variants detected for page %s — delegating to index-based purge to ensure all variants are removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ), true, false );
                } else {
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %s: Page URL */
                        __( 'INFO HTTP PURGE BYPASS: Multiple cache variants detected for related page %s — delegating to index-based purge to ensure all variants are removed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ), true, false );
                }
                continue;
            }
        }

        $result = nppp_http_purge_try_first( $target['original'] );

        if ( $result === true ) {
            if ( $target['is_primary'] ) {
                $ctx['primary_purged'] = true;
                if ( ! $ctx['chain_autopreload'] ) {
                    nppp_display_admin_notice( 'success', sprintf(
                        /* translators: %s: Page URL */
                        __( 'SUCCESS HTTP PURGE: Nginx cache purged for page %s (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ) );
                }
            } else {
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: Page URL */
                    __( 'SUCCESS HTTP PURGE: Nginx cache purged for related page %s (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
            unset( $ctx['pending'][ $key ] );

        } elseif ( $result === 'miss' ) {
            if ( $target['is_primary'] ) {
                if ( ! $ctx['chain_autopreload'] ) {
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %s: Page URL */
                        __( 'INFO HTTP PURGE: Nginx cache purge attempted, but the page %s is not currently found in the cache. (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ) );
                }
            } else {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Page URL */
                    __( 'INFO HTTP PURGE: Nginx cache purge attempted, but the related page %s is not currently found in the cache. (HTTP)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
            unset( $ctx['pending'][ $key ] );
        }
    }

    // Fatality - Johnny Cage Wins
    if ( empty( $ctx['pending'] ) ) {
        nppp_purge_post_purge( $ctx );
        return true;
    }

    return false;
}

/**
 * FP2: Persistent URL→filepath index lookup.
 *
 * Targets whose index paths are all stale are left in pending so FP3/FP4
 * can find them via a fresh directory scan.
 */
function nppp_purge_fp2_index( array &$ctx ): bool {
    $wp_filesystem     = $ctx['wp_filesystem'];
    $index             = $ctx['nppp_index'];
    $cache_path_prefix = rtrim( $ctx['nginx_cache_path'], '/' ) . '/';

    foreach ( array_keys( $ctx['pending'] ) as $key ) {
        $target = $ctx['pending'][ $key ];

        if ( ! isset( $index[ $key ] ) ) {
            if ( $target['is_primary'] ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Page URL for which no index entry exists */
                    __( 'INFO INDEX ABSENT: Running full recursive scan for %s (RG - PHP Iterative)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
            continue;
        }

        $any_valid            = false;
        $any_prefix_match     = false;
        $deleted              = 0;
        $perm_failure         = 0;

        foreach ( $index[ $key ] as $path ) {
            if ( strpos( $path, $cache_path_prefix ) !== 0 ) {
                continue;
            }
            $any_prefix_match = true;

            if ( ! $wp_filesystem->exists( $path ) ) {
                continue;
            }
            $any_valid = true;

            if ( $wp_filesystem->delete( $path ) ) {
                $deleted++;
            } elseif ( $wp_filesystem->exists( $path ) ) {
                $perm_failure++;
            }
        }

        // No paths belong to the current cache directory at all —
        // index only has entries from a different (old) cache path config.
        // FP2 has no information about the current cache for this URL.
        // Treat identically to key-absent: leave in pending for FP3/FP4.
        if ( ! $any_prefix_match ) {
            continue;
        }

        // Confirmed miss — at least one path matched the current prefix
        // but the file is gone from disk. Path is deterministic so this
        // is authoritative: page is genuinely not in the current cache.
        if ( ! $any_valid ) {
            if ( $target['is_primary'] ) {
                if ( ! $ctx['chain_autopreload'] ) {
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %s: Page URL not found in cache */
                        __( 'INFO ADMIN: Nginx cache purge attempted, but page %s is not currently found in the cache (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ) );
                }
            } else {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Related page URL not found in cache */
                    __( 'INFO ADMIN: Nginx cache purge attempted, but related page %s is not currently found in the cache (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
            unset( $ctx['pending'][ $key ] );
            continue;
        }

        // Any purged
        if ( $deleted > 0 || $perm_failure > 0 ) {
            if ( $deleted > 0 ) {
                if ( $target['is_primary'] ) {
                    $ctx['primary_purged'] = true;
                    if ( ! $ctx['chain_autopreload'] ) {
                        if ( $perm_failure > 0 ) {
                            nppp_display_admin_notice( 'success', sprintf(
                                /* translators: %s: Page URL that was partially purged */
                                __( 'SUCCESS ADMIN: Nginx cache partially purged for page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $target['decoded']
                            ) );
                        } else {
                            nppp_display_admin_notice( 'success', sprintf(
                                /* translators: %s: Page URL */
                                __( 'SUCCESS ADMIN: Nginx cache purged for page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                                $target['decoded']
                            ) );
                        }
                    }
                } else {
                    if ( $perm_failure > 0 ) {
                        nppp_display_admin_notice( 'success', sprintf(
                            /* translators: %s: Related page URL that was partially purged */
                            __( 'SUCCESS ADMIN: Nginx cache partially purged for related page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $target['decoded']
                        ), true, false );
                    } else {
                        nppp_display_admin_notice( 'success', sprintf(
                            /* translators: %s: Page URL */
                            __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (INDEX)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $target['decoded']
                        ), true, false );
                    }
                }
            }

            if ( $perm_failure > 0 ) {
                if ( $target['is_primary'] ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: Page URL */
                        __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ) );
                } else {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: Related page URL whose cache file could not be deleted */
                        __( 'ERROR PERMISSION: Nginx cache purge for related page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ), true, false );
                }
            }

            unset( $ctx['pending'][ $key ] );
        }
    }

    // Fatality - Johnny Cage Wins
    if ( empty( $ctx['pending'] ) ) {
        nppp_purge_post_purge( $ctx );
        return true;
    }

    return false;
}

/**
 * FP3: ripgrep scan — single pass over the cache directory for all pending targets.
 *
 * Builds a combined regex pattern so the directory is walked exactly once
 * regardless of how many targets remain. Candidates are accumulated during
 * line processing and deleted in bulk after the loop.
 */
function nppp_purge_fp3_rg( array &$ctx ): string {
    if ( empty( $ctx['settings']['nppp_rg_purge_enabled'] )
        || $ctx['settings']['nppp_rg_purge_enabled'] !== 'yes'
    ) {
        return 'skip';
    }

    nppp_prepare_request_env();
    $rg_bin          = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
    if ( $rg_bin === '' ) {
        return 'skip';
    }
    $wp_filesystem   = $ctx['wp_filesystem'];
    $primary_decoded = $ctx['primary_decoded'];

    // Scan the real nginx cache dir directly, bypassing FUSE overhead.
    // When PHP-FPM cannot enter the real dir, drop to the cache owner via safexec.
    // Deletion always goes through the FUSE mount so FPM has write access.
    $rg_fuse_path   = $ctx['nginx_cache_path'];
    $rg_source_path = nppp_fuse_source_path( $rg_fuse_path );

    // Mount table may list a FUSE source path that no longer exists on disk
    if ( $rg_source_path !== null && ! $wp_filesystem->is_dir( $rg_source_path ) ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: FUSE source filesystem path that no longer exists on disk. */
            __( 'WARNING RG SCAN: FUSE source path from mount table does not exist on disk, falling back to FUSE mount path: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
            $rg_source_path
        ), true, false );
        $rg_source_path = null;
    }

    $rg_fuse_active = $rg_source_path !== null;
    $rg_use_safexec = false;
    $rg_safexec_bin = '';

    if ( $rg_fuse_active ) {
        $rg_scan_path = rtrim( $rg_source_path, '/' ) . '/';

        // Probe whether rg itself can access the real cache dir.
        $probe_out  = [];
        $probe_exit = 0;
        exec(
            sprintf(
                '%s -q \'.\' --text --no-ignore --no-config -m 1 %s 2>/dev/null',
                escapeshellarg( $rg_bin ),
                escapeshellarg( $rg_scan_path )
            ),
            $probe_out,
            $probe_exit
        );

        if ( $probe_exit === 2 ) {
            $rg_sfx_try = nppp_find_safexec_path();
            if ( $rg_sfx_try && nppp_is_safexec_usable( $rg_sfx_try, false ) ) {
                $rg_use_safexec = true;
                $rg_safexec_bin = $rg_sfx_try;
            } else {
                $rg_scan_path = $rg_fuse_path;
            }
        }
    } else {
        $rg_scan_path = $rg_fuse_path;
    }
    $rg_cmd_prefix = $rg_use_safexec ? escapeshellarg( $rg_safexec_bin ) . ' ' : '';

    // Inform user about decision
    if ( $rg_fuse_active ) {
        if ( $rg_scan_path === $rg_fuse_path ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: Filesystem path being scanned by ripgrep via FUSE mount. */
                __( 'WARNING RG SCAN: FUSE mount detected, scanning FUSE mount path (safexec unavailable, install safexec for better performance): %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rg_scan_path
            ), true, false );
        } elseif ( $rg_use_safexec ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: Original Nginx cache filesystem path being scanned by ripgrep via safexec. */
                __( 'INFO RG SCAN: FUSE mount detected, scanning original Nginx Cache Path (safexec): %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rg_scan_path
            ), true, false );
        }
    }

    $url_alts = implode( '|', array_map(
        fn( string $u ): string => preg_quote( $u, '/' ) . '$',
        array_keys( $ctx['pending'] )
    ) );

    $cmd = sprintf(
        '%s%s -m 1 --text -E none --no-unicode --no-messages --no-ignore --no-config %s %s',
        $rg_cmd_prefix,
        escapeshellarg( $rg_bin ),
        escapeshellarg( '^KEY: .*(' . $url_alts . ')' ),
        escapeshellarg( $rg_scan_path )
    );

    $out  = [];
    $exit = 0;
    exec( $cmd, $out, $exit );

    if ( $exit === 2 ) {
        nppp_display_admin_notice( 'error', sprintf(
            /* translators: %s: Page URL */
            __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $primary_decoded
        ) );
        nppp_purge_post_purge( $ctx );
        return 'error';
    }

    $regex        = $ctx['regex'];
    $regex_tested = false;
    $candidates   = [];

    foreach ( array_filter( $out, 'strlen' ) as $raw_line ) {
        $raw_line = trim( $raw_line );
        if ( $raw_line === '' ) {
            continue;
        }

        $parts = explode( ':', $raw_line, 2 );
        if ( count( $parts ) < 2 ) {
            continue;
        }

        $filepath = trim( $parts[0] );
        $key_line = trim( $parts[1] );
        if ( $filepath === '' || $key_line === '' ) {
            continue;
        }

        if ( ! $regex_tested ) {
            if ( ! preg_match( $regex, $key_line, $rx ) || ! isset( $rx[1], $rx[2] ) ) {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: Page URL */
                    __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $primary_decoded
                ) );
                nppp_purge_post_purge( $ctx );
                return 'error';
            }
            if ( ! filter_var( 'https://' . trim( $rx[1] ) . trim( $rx[2] ), FILTER_VALIDATE_URL ) ) {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: Page URL */
                    __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is parsing <strong>$host$request_uri</strong> portion correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $primary_decoded
                ) );
                nppp_purge_post_purge( $ctx );
                return 'error';
            }
            $regex_tested = true;
        } else {
            if ( ! preg_match( $regex, $key_line, $rx ) || ! isset( $rx[1], $rx[2] ) ) {
                continue;
            }
        }

        $constructed = trim( $rx[1] ) . trim( $rx[2] );
        if ( ! isset( $ctx['pending'][ $constructed ] ) ) {
            continue;
        }

        // Translate the rg output path (under real cache dir) to its FUSE mount
        if ( $rg_fuse_active ) {
            // When FUSE is active, rg scans the real source path (directly or via safexec).
            // Translate the real path to the FUSE mount path for purge (where the php has write access).
            $translated = nppp_translate_path_to_fuse( $filepath, $rg_scan_path, $rg_fuse_path );

            if ( $translated === null ) {
                $failed_url = $ctx['pending'][ $constructed ]['decoded'] ?? 'unknown';
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: 1: Decoded URL 2: RG output path that failed translation 3: Expected FUSE scan path prefix */
                    __( 'ERROR PATH TRANSLATE: Purge failed for "%1$s". Failed path translation - "%2$s" does not start with "%3$s"', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $failed_url,
                    $filepath,
                    $rg_scan_path
                ) );
                continue;
            }
            $candidates[ $constructed ][] = $translated;
        } else {
            $candidates[ $constructed ][] = $filepath;
        }
    }

    // Purge All (primary + related)
    foreach ( $candidates as $key => $paths ) {
        $target       = $ctx['pending'][ $key ];
        $deleted      = 0;
        $perm_failure = 0;

        // Purge+index
        foreach ( $paths as $path ) {
            if ( $wp_filesystem->delete( $path ) ) {
                $deleted++;
                $ctx['write_back'][ $key ][] = $path;
            } elseif ( $wp_filesystem->exists( $path ) ) {
                $perm_failure++;
            }
        }

        // Permission error
        if ( $perm_failure > 0 ) {
            if ( $deleted > 0 ) {
                if ( $target['is_primary'] ) {
                    if ( ! $ctx['chain_autopreload'] ) {
                        nppp_display_admin_notice( 'success', sprintf(
                            /* translators: %s: Page URL that was partially purged before a permission error */
                            __( 'SUCCESS ADMIN: Nginx cache partially purged for page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $target['decoded']
                        ) );
                    }
                } else {
                    nppp_display_admin_notice( 'success', sprintf(
                        /* translators: %s: Related page URL that was partially purged before a permission error */
                        __( 'SUCCESS ADMIN: Nginx cache partially purged for related page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ), true, false );
                }
            }
            if ( $target['is_primary'] ) {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: Page URL */
                    __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ) );
            } else {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: Related page URL whose cache file could not be deleted */
                    __( 'ERROR PERMISSION: Nginx cache purge for related page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
            nppp_purge_flush_write_back( $ctx );
            nppp_purge_post_purge( $ctx );
            return 'error';
        }

        // Any purged
        if ( $deleted > 0 ) {
            if ( $target['is_primary'] ) {
                $ctx['primary_purged'] = true;
                if ( ! $ctx['chain_autopreload'] ) {
                    nppp_display_admin_notice( 'success', sprintf(
                        /* translators: %s: Page URL */
                        __( 'SUCCESS ADMIN: Nginx cache purged for page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ) );
                }
            } else {
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: Page URL */
                    __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
        }

        unset( $ctx['pending'][ $key ] );
    }

    // No cache
    foreach ( array_keys( $ctx['pending'] ) as $key ) {
        $target = $ctx['pending'][ $key ];
        if ( $target['is_primary'] ) {
            if ( ! $ctx['chain_autopreload'] ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Page URL */
                    __( 'INFO ADMIN: Nginx cache purge attempted, but page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ) );
            }
        } else {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: Page URL */
                __( 'INFO ADMIN: Nginx cache purge attempted, but related page %s is not currently found in the cache (RG)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $target['decoded']
            ), true, false );
        }
        unset( $ctx['pending'][ $key ] );
    }

    // Fatality - Johnny Cage Wins
    nppp_purge_flush_write_back( $ctx );
    nppp_purge_post_purge( $ctx );
    return 'done';
}

/**
 * FP4: PHP recursive iterator scan — single directory walk for all pending targets.
 *
 * Candidates are accumulated during the walk (to catch all cache variants per URL)
 * and deleted in bulk after the loop. Early exit fires once a candidate has been
 * found for every pending target, avoiding a full scan when targets are clustered
 * early in the directory tree.
 */
function nppp_purge_fp4_scan( array &$ctx ): string {
    $wp_filesystem   = $ctx['wp_filesystem'];
    $primary_decoded = $ctx['primary_decoded'];
    $regex           = $ctx['regex'];
    $head_bytes      = $ctx['head_bytes'];
    $head_bytes_fb   = $ctx['head_bytes_fb'];
    $candidates      = [];
    $regex_tested    = false;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $ctx['nginx_cache_path'], RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isReadable() ) {
                continue;
            }

            $pathname = $file->getPathname();
            $content  = nppp_read_head( $wp_filesystem, $pathname, $head_bytes );
            if ( $content === '' ) {
                continue;
            }

            if ( ! preg_match( '/^KEY:\s([^\r\n]*)/m', $content, $match ) ) {
                if ( strlen( $content ) >= $head_bytes ) {
                    $content = nppp_read_head( $wp_filesystem, $pathname, $head_bytes_fb );
                    if ( $content === '' || ! preg_match( '/^KEY:\s([^\r\n]*)/m', $content, $match ) ) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            if ( strpos( $content, 'Status: 301 Moved Permanently' ) !== false
                || strpos( $content, 'Status: 302 Found' ) !== false
            ) {
                continue;
            }

            if ( ! $regex_tested ) {
                if ( ! preg_match( $regex, $content, $matches ) || ! isset( $matches[1], $matches[2] ) ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: Page URL */
                        __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $primary_decoded
                    ) );
                    nppp_purge_post_purge( $ctx );
                    return 'error';
                }
                if ( ! filter_var( 'https://' . trim( $matches[1] ) . trim( $matches[2] ), FILTER_VALIDATE_URL ) ) {
                    nppp_display_admin_notice( 'error', sprintf(
                        /* translators: %s: Page URL */
                        __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is parsing <strong>$host$request_uri</strong> portion correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $primary_decoded
                    ) );
                    nppp_purge_post_purge( $ctx );
                    return 'error';
                }
                $regex_tested = true;
            } else {
                $matches = [];
                if ( ! preg_match( $regex, $content, $matches ) || ! isset( $matches[1], $matches[2] ) ) {
                    continue;
                }
            }

            $constructed = trim( $matches[1] ) . trim( $matches[2] );
            if ( ! isset( $ctx['pending'][ $constructed ] ) ) {
                continue;
            }

            $candidates[ $constructed ][] = $pathname;
        }
    } catch ( Exception $e ) {
        nppp_display_admin_notice( 'error', sprintf(
            /* translators: %s: Page URL */
            __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $primary_decoded
        ) );
        return 'error';
    }

    // Purge All (primary + related)
    foreach ( $candidates as $key => $paths ) {
        $target       = $ctx['pending'][ $key ];
        $deleted      = 0;
        $perm_failure = 0;

        // Purge+index
        foreach ( $paths as $path ) {
            if ( $wp_filesystem->delete( $path ) ) {
                $deleted++;
                $ctx['write_back'][ $key ][] = $path;
            } elseif ( $wp_filesystem->exists( $path ) ) {
                $perm_failure++;
            }
        }

        // Permission error
        if ( $perm_failure > 0 ) {
            if ( $deleted > 0 ) {
                if ( $target['is_primary'] ) {
                    if ( ! $ctx['chain_autopreload'] ) {
                        nppp_display_admin_notice( 'success', sprintf(
                            /* translators: %s: page URL that was partially purged before a permission error. */
                            __( 'SUCCESS ADMIN: Nginx cache partially purged for page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $target['decoded']
                        ) );
                    }
                } else {
                    nppp_display_admin_notice( 'success', sprintf(
                        /* translators: %s: Related page URL that was partially purged before a permission error */
                        __( 'SUCCESS ADMIN: Nginx cache partially purged for related page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ), true, false );
                }
            }
            if ( $target['is_primary'] ) {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: Page URL */
                    __( 'ERROR PERMISSION: Nginx cache purge for page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ) );
            } else {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: %s: Related page URL whose cache file could not be deleted */
                    __( 'ERROR PERMISSION: Nginx cache purge for related page %s was aborted due to a permission error. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
            nppp_purge_flush_write_back( $ctx );
            nppp_purge_post_purge( $ctx );
            return 'error';
        }

        // Any Purged
        if ( $deleted > 0 ) {
            if ( $target['is_primary'] ) {
                $ctx['primary_purged'] = true;
                if ( ! $ctx['chain_autopreload'] ) {
                    nppp_display_admin_notice( 'success', sprintf(
                        /* translators: %s: Page URL */
                        __( 'SUCCESS ADMIN: Nginx cache purged for page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $target['decoded']
                    ) );
                }
            } else {
                nppp_display_admin_notice( 'success', sprintf(
                    /* translators: %s: Page URL */
                    __( 'SUCCESS ADMIN: Nginx cache purged for related page %s (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ), true, false );
            }
        }

        unset( $ctx['pending'][ $key ] );
    }

    // No cache
    foreach ( array_keys( $ctx['pending'] ) as $key ) {
        $target = $ctx['pending'][ $key ];
        if ( $target['is_primary'] ) {
            if ( ! $ctx['chain_autopreload'] ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Page URL */
                    __( 'INFO ADMIN: Nginx cache purge attempted, but page %s is not currently found in the cache (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $target['decoded']
                ) );
            }
        } else {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: Page URL */
                __( 'INFO ADMIN: Nginx cache purge attempted, but related page %s is not currently found in the cache (SCAN)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $target['decoded']
            ), true, false );
        }
        unset( $ctx['pending'][ $key ] );
    }

    // Fatality - Johnny Cage Wins
    nppp_purge_flush_write_back( $ctx );
    nppp_purge_post_purge( $ctx );
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

    // Skip non-publicly-viewable post types entirely.
    // is_post_type_viewable() checks publicly_queryable (custom types) or
    // public (built-in types). Returns false automatically for ALL private CPTs:
    // shop_order, shop_order_refund, shop_order_placehold, wc_order,
    // shop_coupon, shop_webhook, shop_subscription, scheduled-action,
    // edd_*, gravity forms entries, and any future private CPT
    if ( ! is_post_type_viewable( $post->post_type ) ) {
        return;
    }

    // Check is_post_type_viewable() before get_option() to avoid unnecessary
    // database reads on non-public post types like WooCommerce shop_order.
    // Reduces load on high‑traffic WooCommerce sites.
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    if (($nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no') !== 'yes') {
        return;
    }
    if ( ($nginx_cache_settings['nppp_autopurge_posts'] ?? 'no') !== 'yes' ) {
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

        // For non-wp/v2 REST (e.g. WooCommerce wc/v3 Product Block Editor):
        // compat-gutenberg does NOT cover this namespace, but compat-woocommerce
        // handles WC products via woocommerce_update_product.  Any transition
        // FROM a non-publish status (new / auto-draft / draft / pending / future)
        // means the page has never been publicly served OR the cache was already
        // cleared when it was un-published — nothing to purge here.
        // publish → publish content updates fall through and purge normally.
        if ( 'publish' !== $old_status ) {
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
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $current_get_action  = isset( $_GET['action'] )  ? sanitize_key( wp_unslash( $_GET['action'] ) )  : '';

    // Elementor: block both the editor page load (GET action=elementor) and
    // all Elementor AJAX calls (POST action=elementor_ajax).
    // Real content saves are handled by compat-elementor.php.
    if ( 'elementor_ajax' === $current_ajax_action || 'elementor' === $current_get_action ) {
        return;
    }
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

    // Also marks $did_purge to prevent any subsequent in-request transition for
    // this post ID (e.g. a programmatic $product->save() via wp_update_post() in
    // non-admin contexts) from slipping through as a spurious publish→publish update.
    if ( in_array( $old_status, [ 'auto-draft', 'new', 'draft', 'pending' ], true ) ) {
        $did_purge[ $post->ID ] = true;
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
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Retrieve necessary options for purge actions
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Check for the theme update — respects themes sub-trigger
        if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === 'theme' ) {
            if ( ( $nginx_cache_settings['nppp_autopurge_themes'] ?? 'no' ) === 'yes' ) {
                $active_theme   = wp_get_theme()->get_stylesheet();
                $updated_themes = $hook_extra['themes']                                        // bulk
                    ?? ( isset( $hook_extra['theme'] ) ? [ $hook_extra['theme'] ] : [] );      // single

                if ( ! empty( $updated_themes ) && in_array( $active_theme, $updated_themes, true ) ) {
                    nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
                }
            }
        }

        // Check for the plugin update
        if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === 'plugin' ) {
            if ( ( $nginx_cache_settings['nppp_autopurge_plugins'] ?? 'no' ) === 'yes' ) {
                $updated_plugins = $hook_extra['plugins']                                      // bulk
                    ?? ( isset( $hook_extra['plugin'] ) ? [ $hook_extra['plugin'] ] : [] );    // single

                if ( ! empty( $updated_plugins ) ) {
                    nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
                }
            }
        }
    }
}

// Auto Purge (Entire)
// Purge entire cache when WordPress finishes a background automatic update.
// This function hooks into the 'automatic_updates_complete' action.
function nppp_purge_cache_on_auto_update( $results = [] ) {
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }
    if ( ( $nginx_cache_settings['nppp_autopurge_3rdparty'] ?? 'no' ) !== 'yes' ) {
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
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        if ( ( $nginx_cache_settings['nppp_autopurge_plugins'] ?? 'no' ) !== 'yes' ) {
            return;
        }
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
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        if ( ( $nginx_cache_settings['nppp_autopurge_themes'] ?? 'no' ) !== 'yes' ) {
            return;
        }
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

    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }
    if ( ( $nginx_cache_settings['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) {
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

    // Skip private/internal post types.
    // Covers shop_order, wc_order, shop_coupon, scheduled-action, and any
    // future private CPT
    if ( ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
        return;
    }

    // Check is_post_type_viewable() before get_option() to avoid unnecessary
    // database reads on non-public post types like WooCommerce shop_order.
    // Reduces load on high‑traffic WooCommerce sites.
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }
    if ( ( $nginx_cache_settings['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    $post_url = get_permalink( $post_id );
    if ( ! $post_url ) {
        return;
    }

    $nginx_cache_path = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    nppp_purge_single( $nginx_cache_path, $post_url, true );
}

// Auto Purge (Single)
// Purges the Nginx cache for a taxonomy term's archive page when the term is
// created or edited directly from the admin (Posts > Categories, Posts > Tags, etc.).
//
// Scope: only public taxonomies that have a rewrite (i.e. they have real archive URLs).
// Private / internal taxonomies (e.g. WooCommerce's product_visibility) are skipped
// because they produce no cacheable frontend page.
//
// Trigger:
//   created_term — new term added  (homepage / nav sidebars may embed category lists)
//   edited_term  — term name, slug, or description changed (archive is now stale)
function nppp_purge_cache_on_term_change( $term_id, $tt_id, $taxonomy ) {
    // Only public taxonomies with a URL-based rewrite (public archives exist).
    $tax_obj = get_taxonomy( $taxonomy );
    if ( ! $tax_obj || empty( $tax_obj->public ) || false === $tax_obj->rewrite ) {
        return;
    }

    // Perform the database read — only for viewable taxonomies.
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }
    if ( ( $nginx_cache_settings['nppp_autopurge_terms'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    $term_link = get_term_link( (int) $term_id, $taxonomy );
    if ( is_wp_error( $term_link ) || ! filter_var( $term_link, FILTER_VALIDATE_URL ) ) {
        return;
    }

    $nginx_cache_path = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    nppp_purge_single( $nginx_cache_path, $term_link, true );
}

// Auto Purge (Single)
// Step 1 of 2 for term deletion: capture the term's archive URL BEFORE WordPress
// removes the term from the database.
//
// `get_term_link()` returns WP_Error once the term row is gone, so it must be
// called in `pre_delete_term` (fires before deletion) and the result cached in
// memory via the WP object cache (non-persistent group — valid for this request only).
//
// The actual purge is performed in nppp_purge_cache_on_term_delete() which hooks
// `delete_term` (fires after deletion).
function nppp_capture_term_url_pre_delete( $term_id, $taxonomy ) {
    $tax_obj = get_taxonomy( $taxonomy );
    if ( ! $tax_obj || empty( $tax_obj->public ) || false === $tax_obj->rewrite ) {
        return;
    }

    // Guard early — only cache the URL if terms sub-trigger is actually enabled.
    // nppp_purge_cache_on_term_delete() has its own guard but this avoids a wasted
    // object-cache write and get_term_link() call when purging is disabled.
    $nginx_cache_settings = get_option( 'nginx_cache_settings', [] );
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }
    if ( ( $nginx_cache_settings['nppp_autopurge_terms'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    $term_link = get_term_link( (int) $term_id, $taxonomy );
    if ( ! is_wp_error( $term_link ) && filter_var( $term_link, FILTER_VALIDATE_URL ) ) {
        // Store in in-memory object cache (single-request lifetime, no DB hit).
        wp_cache_set( 'nppp_del_term_' . (int) $term_id, $term_link, 'nppp_term_purge' );
    }
}

// Auto Purge (Single)
// Step 2 of 2 for term deletion: purge the archive URL that was captured by
// nppp_capture_term_url_pre_delete() before WordPress deleted the term.
//
// Hooked to: delete_term (fires after the term is removed from the database).
function nppp_purge_cache_on_term_delete( $term_id, $tt_id, $taxonomy ) {
    $term_link = wp_cache_get( 'nppp_del_term_' . (int) $term_id, 'nppp_term_purge' );
    wp_cache_delete( 'nppp_del_term_' . (int) $term_id, 'nppp_term_purge' );

    if ( ! $term_link ) {
        return;
    }

    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    if ( ( $nginx_cache_settings['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return;
    }
    if ( ( $nginx_cache_settings['nppp_autopurge_terms'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    $nginx_cache_path = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    nppp_purge_single( $nginx_cache_path, $term_link, true );
}

// Purge cache operation
function nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, $nppp_is_rest_api = false, $nppp_is_admin_bar = false, $nppp_is_auto_purge = false) {
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

    $options = get_option('nginx_cache_settings', []);

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
                                /* translators: %s: PID of the preload process terminated via safexec */
                                nppp_display_admin_notice('success', sprintf( __( 'SUCCESS PROCESS: The ongoing Nginx cache Preload process (PID: %s) terminated using safexec', 'fastcgi-cache-purge-and-preload-nginx' ), $pid ), true, false);
                                $killed_by_safexec = true;
                            } else {
                                /* translators: %s: PID of the preload process that could not be killed via safexec */
                                nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: Failed to terminate using safexec, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                            }
                        }
                    } else {
                        /* translators: %s: PID of the preload process (safexec not privileged) */
                        nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: safexec not privileged, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                    }
                } else {
                    /* translators: %s: PID of the preload process (safexec binary not found) */
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
                            /* translators: %s: PID of the preload process terminated via SIGKILL */
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
                    /* translators: 1: PID of the terminated preload process 2: Termination method used (e.g. SIGTERM, SIGKILL) */
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
                    /* translators: %s: Configured Nginx cache filesystem path that could not be found */
                    $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 5:
                    $message_type = 'error';
                    /* translators: %s: Configured Nginx cache path that triggered the directory traversal check */
                    $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 6:
                    $message_type = 'error';
                    /* translators: %s: Configured Nginx cache path that overlaps the WordPress installation directory */
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
                    /* translators: %s: Configured Nginx cache filesystem path that could not be found */
                    $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 5:
                    $message_type = 'error';
                    /* translators: %s: Configured Nginx cache path that triggered the directory traversal check */
                    $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 6:
                    $message_type = 'error';
                    /* translators: %s: Configured Nginx cache path that overlaps the WordPress installation directory */
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
                /* translators: %s: Configured Nginx cache filesystem path that could not be found */
                $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                break;
            case 4:
                $message_type = 'error';
                $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 5:
                $message_type = 'error';
                /* translators: %s: Configured Nginx cache path that triggered the directory traversal check */
                $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                break;
            case 6:
                $message_type = 'error';
                /* translators: %s: Configured Nginx cache path that overlaps the WordPress installation directory */
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
    do_action('nppp_purged_all', $auto_preload);

    // If set call preload immediately after purge
    if ($auto_preload) {
        // Get the plugin options
        $nginx_cache_settings = get_option('nginx_cache_settings', []);

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
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Get the necessary data for purge action from plugin options
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Call the main purge function
    nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, false, true);
}
