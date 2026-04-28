<?php
/**
 * Advanced tab handlers for Nginx Cache Purge Preload
 * Description: Implements advanced admin actions for targeted URL purge and preload tasks.
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

/* =========================
   Helpers for URL handling
   ========================= */

/**
 * Human display form: decode %XX only in the PATH (segment-by-segment).
 * Query remains as-is (encoded). No fragments.
 */
function nppp_display_human_url( $url, $force_scheme = null ) {
    $p = wp_parse_url( $url );
    if ( ! $p || empty( $p['host'] ) ) {
        return $url;
    }

    $scheme = $force_scheme ?: ( isset($p['scheme']) ? $p['scheme'] : ( wp_is_using_https() ? 'https' : 'http' ) );
    $host   = $p['host'];

    $path = isset( $p['path'] ) ? $p['path'] : '/';
    $segments = explode( '/', $path );
    foreach ( $segments as &$seg ) {
        $seg = rawurldecode( $seg );
    }
    $decoded_path = implode( '/', $segments );
    if ( $decoded_path === '' ) {
        $decoded_path = '/';
    }

    $query = ( isset( $p['query'] ) && $p['query'] !== '' ) ? '?' . $p['query'] : '';

    return $scheme . '://' . $host . $decoded_path . $query;
}

/**
 * Matching key that ignores encoding differences:
 * - scheme normalized to current site scheme
 * - host lowercased
 * - path & query decoded
 */
function nppp_url_match_key( $url ) {
    $p = wp_parse_url( $url );
    if ( ! $p || empty( $p['host'] ) ) {
        return strtolower( (string) $url );
    }
    $scheme = wp_is_using_https() ? 'https' : 'http';
    $host   = strtolower( $p['host'] );
    $path   = isset($p['path']) ? rawurldecode($p['path']) : '/';
    $query  = isset($p['query']) ? rawurldecode($p['query']) : '';
    return $scheme . '|' . $host . '|' . $path . '|' . $query;
}

// Is preload running
function nppp_is_preload_running( $wp_filesystem ) {
    $this_script_path = dirname( plugin_dir_path( __FILE__ ) );
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    if ( ! $wp_filesystem->exists( $PIDFILE ) ) {
        return false;
    }

    $pid = intval( nppp_perform_file_operation( $PIDFILE, 'read' ) );
    return ( $pid > 0 && nppp_is_process_alive( $pid ) );
}

/**
 * Make any reject pattern safe for preg_match:
 * - If it's already delimited properly (like ~...~ or #...#), keep it.
 * - Otherwise, wrap it with ~ ... ~ so literal "/" inside doesn’t need escaping.
 * - Always trims surrounding whitespace and trailing semicolons (from file parses).
 */
function nppp_compile_regex_delimiter_safe( $pattern ) {
    $pattern = trim( (string) $pattern );
    if ( $pattern === '' ) { return ''; }

    // Strip a trailing semicolon if present (e.g., from PHP-like config files).
    if ( substr($pattern, -1) === ';' ) {
        $pattern = rtrim($pattern, ';');
    }

    // If looks like a delimited regex already: first == last and is a non-alnum
    $first = substr($pattern, 0, 1);
    $last  = substr($pattern, -1);
    if ( $first === $last && !ctype_alnum($first) && $first !== '\\' ) {
        // Already delimited. Just return as-is.
        return $pattern;
    }

    // Not delimited → wrap with "~ ... ~" and escape any "~" inside
    $body = str_replace('~', '\~', $pattern);

    // Add 'i' for case-insensitive matches (URLs are case-insensitive in host/path typically)
    return '~' . $body . '~i';
}

// Returns true if the wget log contains a FINISHED marker
function nppp_wget_log_is_complete( $contents ) {
    return (bool) preg_match('~^FINISHED\s+--\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}--~m', (string) $contents);
}

/**
 * Count how many cache files share each url_encoded key and store
 * the total as 'variant_count' on every entry. O(n), no I/O.
 *
 * @param array<int,array<string,mixed>> &$urls
 */
function nppp_annotate_variant_counts( array &$urls ): void {
    $counts = [];
    foreach ( $urls as $entry ) {
        $k = $entry['url_encoded'] ?? '';
        $counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
    }
    foreach ( $urls as &$entry ) {
        $entry['variant_count'] = $counts[ $entry['url_encoded'] ?? '' ] ?? 1;
    }
    unset( $entry );
}

/** Plugin root */
function nppp_get_plugin_root_path() {
    return rtrim( dirname( plugin_dir_path( __FILE__ ) ), '/' );
}

/* ==========================================
   Parse nppp-wget.log → MISS candidates map
   ========================================== */

function nppp_parse_wget_log_urls( $wp_filesystem ) {
    $log_path      = nppp_get_runtime_file('nppp-wget.log');
    $snapshot_path = nppp_get_runtime_file('nppp-wget-snapshot.log');

    // Detect live crawl
    $preload_running = nppp_is_preload_running( $wp_filesystem );

    $urls = [];

    // While preload is running: read the live log for real-time progress.
    // Once preload finishes: read the snapshot which only exists when a
    // run has fully completed. This prevents a partial/interrupted live log
    // from ever replacing a previously complete snapshot.
    $read_path = $preload_running ? $log_path : $snapshot_path;

    if ( ! $wp_filesystem->exists( $read_path ) ) {
        return $urls;
    }

    // Build a transient key tied to the file's actual modification time.
    // When the snapshot is deleted and recreated, or manually removed,
    // the mtime changes → new key → automatic cache miss, no manual
    // delete_transient() calls needed anywhere.
    // During a live preload we never cache (partial data), so the key
    // is only meaningful for the stable snapshot path.
    if ( ! $preload_running ) {
        $mtime         = (int) @filemtime( $read_path );
        $transient_key = 'nppp_wget_urls_cache_' . md5( $read_path . $mtime );

        $cached = get_transient( $transient_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }
    }

    $contents = $wp_filesystem->get_contents( $read_path );
    if ( $contents === false || $contents === '' ) {
        return $urls;
    }

    // The snapshot is always complete by definition (only written on FINISHED).
    // The live log during a running preload is partial by definition — that is
    // expected and fine, so we do not apply the completion guard here.
    // The guard below is kept only as a safety net in case the snapshot file
    // somehow became corrupted (manually edited, partial write, etc.).
    if ( ! $preload_running && ! nppp_wget_log_is_complete( $contents ) ) {
        return $urls;
    }

    $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
    if ( empty( $site_host ) ) {
        return $urls;
    }

    // Load reject regex (DB override or default file), then compile safely
    $settings      = get_option('nginx_cache_settings', []);
    $reject_raw    = isset($settings['nginx_cache_reject_regex'])
        ? $settings['nginx_cache_reject_regex']
        : nppp_fetch_default_reject_regex();
    $reject_regex  = nppp_compile_regex_delimiter_safe( $reject_raw );

    // Regex
    $line_regex = '~^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+URL:(\S+)~m';

    if ( preg_match_all( $line_regex, $contents, $matches, PREG_SET_ORDER ) ) {
        foreach ( $matches as $m ) {
            $ts  = trim( $m[1] );
            $raw = trim( $m[2] );

            // Same-host only
            $host = wp_parse_url( $raw, PHP_URL_HOST );
            if ( ! $host || strcasecmp( $host, $site_host ) !== 0 ) {
                continue;
            }

            // Apply reject pattern (if valid/compiled)
            if ( $reject_regex !== '' && @preg_match( $reject_regex, $raw ) ) {
                continue;
            }

            // Preload must keep the exact logged URL
            $preload_url = $raw;
            if ( filter_var( $preload_url, FILTER_VALIDATE_URL ) === false ) { continue; }

            // Human-friendly display: decode PATH only
            $display_url = nppp_display_human_url( $raw );

            // Category for table
            $category = nppp_categorize_url( $display_url );

            // De-dupe by match key (encoding-insensitive)
            $key = nppp_url_match_key( $raw );
            if ( ! isset( $urls[ $key ] ) ) {
                $urls[ $key ] = [
                    'url'         => $display_url,
                    'url_encoded' => $preload_url,
                    'category'    => $category,
                    'log_date'    => $ts,
                ];
            }
        }
    }

    // Only persist when NOT running (stable snapshot).
    // TTL is orphan cleanup only — actual invalidation is guaranteed by the
    // mtime-based key. When the snapshot changes, the old key is never
    // queried again and expires here naturally.
    if ( ! $preload_running ) {
        // Delete the previous mtime-based key before writing the new one.
        // Each preload completion generates a new key (mtime changes), leaving
        // the old key as an orphan for up to MONTH_IN_SECONDS. On active sites
        // running daily preloads this accumulates 30+ stale rows in wp_options.
        $prev_key_pointer = 'nppp_wget_urls_cache_prev_key';
        $prev_key = get_transient( $prev_key_pointer );
        if ( $prev_key && $prev_key !== $transient_key ) {
            delete_transient( $prev_key );
        }

        set_transient( $transient_key, $urls, MONTH_IN_SECONDS );

        // Store current key so the next run can clean it up.
        set_transient( $prev_key_pointer, $transient_key, MONTH_IN_SECONDS );
    }

    return $urls;
}

/* =====================================================
   Merge HITs (cache scan) with MISSes (wget log result)
   ===================================================== */

function nppp_merge_cached_and_wget( $cached_urls, $wp_filesystem ) {
    $rows = [];

    // Map HITs by encoding-insensitive key
    $hit_map = [];
    foreach ( (array) $cached_urls as $item ) {
        if ( empty( $item['url'] ) && empty( $item['url_encoded'] ) ) { continue; }

        // Prefer url_encoded for key; fall back to url
        $key_src = !empty($item['url_encoded']) ? $item['url_encoded'] : $item['url'];
        $k = nppp_url_match_key( $key_src );
        $hit_map[ $k ] = true;

        // Ensure human display (decoded path)
        $display_url = nppp_display_human_url( $item['url'] );

        $rows[] = [
            'url'           => $display_url,
            'url_encoded'   => $item['url_encoded'],
            'file_path'     => $item['file_path'],
            'category'      => $item['category'],
            'variant_count' => $item['variant_count'] ?? 1,
            'status'        => 'HIT',
        ];
    }

    // Add MISSes from wget
    $wget_map = nppp_parse_wget_log_urls( $wp_filesystem );
    foreach ( $wget_map as $k => $item ) {
        if ( isset( $hit_map[ $k ] ) ) { continue; }
        $rows[] = [
            'url'           => $item['url'],
            'url_encoded'   => $item['url_encoded'],
            'file_path'     => '—',
            'category'      => $item['category'],
            'variant_count' => 0,
            'status'        => 'MISS',
        ];
    }

    return $rows;
}

// Generate HTML
function nppp_premium_html($nginx_cache_path) {
    // initialize WP_Filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR CRITICAL: Please get help from plugin support forum! (ERROR 1070)', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Handle case where option doesn't exist
    if (empty($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR CRITICAL: Please get help from plugin support forum! (ERROR 1071)', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Handle case where cache directory doesn't exist
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR PATH: The specified Nginx cache path was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Check if the directory and its contents are readable softly and recursive
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR PERMISSION: Please ensure proper permissions are set for the Nginx cache directory. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    } elseif (nppp_check_permissions_recursive_with_cache() !== 'true') {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR PERMISSION: Please ensure proper permissions are set for the Nginx cache directory. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Start output buffering for all subsequent content (warnings, notices, table)
    ob_start();

    // Check FastCGI Cache Key
    $config_data = nppp_parse_nginx_cache_key();

    // Get extracted URLs (HITs)
    $extractedUrls = nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path);
    $preload_running = nppp_is_preload_running( $wp_filesystem );

    $hits = [];

    // Check for errors
    if (isset($extractedUrls['error'])) {
        $is_empty_cache = ( $extractedUrls['error'] === 'NPPP_EMPTY_CACHE' );

        if ( $is_empty_cache ) {
            $snapshot_path   = nppp_get_runtime_file('nppp-wget-snapshot.log');

            if ( ! $preload_running && ! $wp_filesystem->exists( $snapshot_path ) ) {
                ob_end_clean();
                // Fresh install: no cache, no snapshot — return just the notice, no table.
                $wget_msg = __(
                    'No completed <strong>crawl</strong> snapshot found. Run <strong>Preload All</strong> once to build the full snapshot — <strong>ALL</strong> URLs will then appear here.',
                    'fastcgi-cache-purge-and-preload-nginx'
                );
                return '<div style="background-color:#f9edbe;border-left:6px solid #f0c36d;padding:10px;margin-bottom:15px;max-width:max-content;">
                    <p style="margin:0;display:flex;align-items:center;">
                        <span class="dashicons dashicons-warning" style="font-size:22px;color:#ffba00;margin-right:8px;"></span>
                        <span style="font-size:14px;">' .
                            wp_kses( $wget_msg, array( 'strong' => array() ) ) .
                        '</span>
                    </p>
                </div>';
            }

            // Snapshot exists but cache is empty (normal post-purge state).
            // Fall through with zero HITs so snapshot MISSes populate the table.
            $hits = [];
        } else {
            ob_end_clean();
            // Real error (permissions, regex, path) — hard stop.
            $error_message = wp_kses( $extractedUrls['error'], array( 'strong' => array() ) );
            return '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                        <p style="margin: 0; display: flex; align-items: center;">
                            <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>
                            <span style="font-size: 14px;">' . $error_message . '</span>
                        </p>
                    </div>';
        }
    } else {
        $hits = $extractedUrls;
    }

    // Advanced tab visit → persist fresh hit count.
    // These metrics used for live cache coverage calculations
    if ( ! empty( $hits ) && is_array( $hits ) ) {
        update_option( 'nppp_last_known_hits',      count( $hits ), false );
        update_option( 'nppp_last_hits_scanned_at', time(),         false );
    }

    // Build the URL→filepath index used by single-page and related purges
    // to skip expensive recursive cache directory scans.
    // Zero extra filesystem I/O — $hits is already the full directory scan
    // that just ran to build the table above, so we reuse it directly.
    // Merge strategy: existing entries are preserved, incoming entries
    // add or update. Never truncate — Preload All only crawls URLs allowed
    // by Exclude Endpoints, so pages outside that ruleset never appear in
    // $hits. Those pages accumulate in the index over time as real visitors
    // or bots hit them and purge operations add them via write-back. They
    // must survive across Purge All + Preload All cycles because nginx will
    // always re-cache them to the same deterministic path on next visit.
    // Single URL can hold multiple PTAH.
    if ( ! empty( $hits ) && is_array( $hits ) && ! $preload_running ) {
        $nppp_index = get_option( 'nppp_url_filepath_index' );
        $nppp_index = is_array( $nppp_index ) ? $nppp_index : [];
        foreach ( $hits as $nppp_entry ) {
            $nppp_key      = preg_replace( '#^https?://#', '', $nppp_entry['url_encoded'] );
            $nppp_existing = $nppp_index[ $nppp_key ] ?? [];
            if ( ! in_array( $nppp_entry['file_path'], $nppp_existing, true ) ) {
                $nppp_existing[] = $nppp_entry['file_path'];
            }
            $nppp_index[ $nppp_key ] = $nppp_existing;
        }
        update_option( 'nppp_url_filepath_index', $nppp_index, false );
        unset( $nppp_index, $nppp_entry, $nppp_key, $nppp_existing );
    }

    // Merge HIT + MISS
    $mergedRows = nppp_merge_cached_and_wget($hits, $wp_filesystem);

    // Warnings - only meaningful when table has data
    if ( ! $preload_running ) {
        if ( ! empty( $mergedRows ) && $config_data === false) {
            echo '<div class="nppp-premium-wrap">
                      <p class="nppp-advanced-error-message">' . wp_kses_post( __( 'INFO: No <span style="color: #f0c36d;">_cache_key</span> directive was found. This may indicate a <span style="color: #f0c36d;">parsing error</span> or a missing <span style="color: #f0c36d;">nginx.conf</span> file.', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</p>
                  </div>';
        } elseif ( ! empty( $mergedRows ) && isset($config_data['cache_keys']) && $config_data['cache_keys'] === ['Not Found']) {
            echo '<div class="nppp-premium-wrap">
                      <p class="nppp-advanced-error-message">' . wp_kses_post( __( 'INFO: No <span style="color: #f0c36d;">_cache_key</span> directive was found. This may indicate a <span style="color: #f0c36d;">parsing error</span> or a missing <span style="color: #f0c36d;">nginx.conf</span> file.', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</p>
                  </div>';
        // Warn about the unsupported cache keys
        } elseif ( ! empty( $mergedRows ) && isset($config_data['cache_keys']) && !empty($config_data['cache_keys'])) {
            echo '<div class="nppp-premium-wrap">
                      <p class="nppp-advanced-error-message">' . wp_kses_post( __( 'INFO: <span style="color: #f0c36d;">Unsupported</span> cache key found!', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</p>
                  </div>';
        }
    }

    // Warn if no complete crawl snapshot exists yet.
    // The snapshot file (nppp-wget-snapshot.log) is only written when a
    // Preload All run finishes successfully. If it does not exist, the admin
    // has never completed a full preload and MISSes cannot be shown.
    $plugin_root      = nppp_get_plugin_root_path();
    $snapshot_path    = nppp_get_runtime_file('nppp-wget-snapshot.log');
    $wget_notice_html = '';

    if ( ! $preload_running && ! $wp_filesystem->exists( $snapshot_path ) ) {
        /* translators: "MISS" is a cache status label. Keep the <strong> tags. */
        $wget_msg = __(
            'No completed <strong>crawl</strong> snapshot found. Run <strong>Preload All</strong> once to build the full snapshot — uncached <strong>MISS</strong> URLs will then appear here.',
            'fastcgi-cache-purge-and-preload-nginx'
        );
        $wget_notice_html = '<div style="background-color:#f9edbe;border-left:6px solid #f0c36d;padding:5px;margin-bottom:15px;max-width:max-content;">
            <p style="margin:0;display:flex;align-items:center;">
                <span class="dashicons dashicons-warning" style="font-size:22px;color:#ffba00;margin-right:8px;"></span>
                <span style="font-size:13px;">' .
                    wp_kses( $wget_msg, array( 'strong' => array() ) ) .
                '</span>
            </p>
        </div>';
    }

    ?>
    <?php
    // Aborted wget/preload notice (may be empty)
    echo wp_kses(
        $wget_notice_html,
        array(
            'div'    => array( 'style' => true, 'class' => true ),
            'p'      => array( 'style' => true, 'class' => true ),
            'span'   => array( 'style' => true, 'class' => true, 'aria-hidden' => true ),
            'strong' => array(),
        )
    );
    ?>
    <?php if ( ! empty( $mergedRows ) && ! $preload_running ) : ?>
    <div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 5px; margin-bottom: 15px; max-width: max-content;">
        <p style="margin: 0; align-items: center;">
            <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>
            <?php echo wp_kses_post( __( 'If the <strong>Cached URL\'s</strong> are incorrect, <strong>Preload</strong> will not work as expected. Please check the <strong>Cache Key Regex</strong> option in plugin <strong>Advanced options</strong> section, ensure the regex is configured correctly, and try again.', 'fastcgi-cache-purge-and-preload-nginx' ) ); ?>
        </p>
    </div>
    <?php endif; ?>
    <h2></h2>
    <?php if ($preload_running) : ?>
    <div class="nppp-table-loading-notice">
        <span class="dashicons dashicons-update-alt spin"></span>
        <?php esc_html_e( 'Preload is running — data reflects crawl progress at tab load. Revisit this tab for updated results or check the Status tab for live progress.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </div>
    <?php endif; ?>
    <table id="nppp-premium-table" class="display<?php if ($preload_running) echo ' nppp-table-loading'; ?>">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Cached URL', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Cache Path', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Content', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Status', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Variants', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Action', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty($mergedRows) ) : ?>
            <tr><td colspan="6"><?php esc_html_e( 'No cacheable URLs found yet.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></td></tr>
        <?php else :
            foreach ( $mergedRows as $row ) :
                $is_hit = ( $row['status'] === 'HIT' );
                $status_text  = $is_hit ? 'HIT' : 'MISS';
                $status_class = $is_hit ? 'is-hit' : 'is-miss';
                ?>
                <tr>
                    <td class="nppp-url">
                        <a href="<?php echo esc_url( !empty($row['url_encoded']) ? $row['url_encoded'] : $row['url'] ); ?>"
                            target="_blank" rel="noopener">
                            <?php echo esc_html( $row['url'] ); ?>
                        </a>
                    </td>
                    <td class="nppp-cache-path"><?php echo esc_html( $row['file_path'] ); ?></td>
                    <td><?php echo esc_html( $row['category'] ); ?></td>
                    <td class="nppp-status <?php echo esc_attr($status_class); ?>">
                        <strong><?php echo esc_html($status_text); ?></strong>
                    </td>
                    <?php
                    $nppp_vc  = (int) ( $row['variant_count'] ?? 0 );
                    $nppp_va  = $nppp_vc > 1 ? 'Multiple' : ( $nppp_vc === 1 ? 'Single' : '—' );
                    $nppp_cls = 'nppp-variant-' . ( $nppp_va === '—' ? 'miss' : strtolower( $nppp_va ) );
                    ?>
                    <td class="nppp-variant-cell" data-variants="<?php echo esc_attr( $nppp_va ); ?>">
                        <span class="nppp-variant-badge <?php echo esc_attr( $nppp_cls ); ?>">
                            <?php echo esc_html( $nppp_va ); ?>
                        </span>
                        <?php if ( $nppp_vc > 1 ) : ?>
                            <span class="nppp-variant-count">(<?php echo (int) $nppp_vc; ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button"
                                class="nppp-purge-btn"
                                <?php echo $is_hit ? '' : 'disabled aria-disabled="true" title="' . esc_attr__('Not cached yet', 'fastcgi-cache-purge-and-preload-nginx') . '"'; ?>
                                data-url="<?php echo esc_attr( $row['url_encoded'] ); ?>">
                            <span class="dashicons dashicons-trash" style="font-size:16px;margin:0;padding:0;"></span>
                            <?php echo esc_html__( 'Purge', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </button>

                        <button type="button"
                                class="nppp-preload-btn"
                                data-url="<?php echo esc_attr( $row['url_encoded'] ); ?>">
                            <span class="dashicons dashicons-update" style="font-size:16px;margin:0;padding:0;"></span>
                            <?php echo esc_html__( 'Preload', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach;
        endif; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Log and send error
function nppp_log_and_send_error($error_message, $log_file_path) {
    if (!empty($log_file_path)) {
        nppp_perform_file_operation(
            $log_file_path,
            'append',
            '[' . current_time('Y-m-d H:i:s') . '] ' . $error_message
        );
    } else {
        nppp_custom_error_log('Log file not found!');
    }
    wp_send_json_error($error_message);
}

// Log and send success
function nppp_log_and_send_success($success_message, $log_file_path) {
    // Make a plain-text version for the log
    $for_log  = preg_replace( '~<br\s*/?>~i', ' — ', (string) $success_message );
    $log_text = trim( wp_strip_all_tags( $for_log, false ) );

    if (!empty($log_file_path)) {
        nppp_perform_file_operation(
            $log_file_path,
            'append',
            '[' . current_time('Y-m-d H:i:s') . '] ' . $log_text
        );
    } else {
        nppp_custom_error_log('Log file not found!');
    }
    wp_send_json_success($success_message);
}

// Send related pages also to update their status on the fly in table
function nppp_log_and_send_success_data($success_message, $log_file_path, $data_array, $skip_log = false) {
    if (!$skip_log) {
        $for_log  = preg_replace('~<br\s*/?>~i', ' — ', (string) $success_message);
        $log_text = trim(wp_strip_all_tags($for_log, false));

        if (!empty($log_file_path)) {
            nppp_perform_file_operation(
                $log_file_path,
                'append',
                '[' . current_time('Y-m-d H:i:s') . '] ' . $log_text
            );
        } else {
            nppp_custom_error_log('Log file not found!');
        }
    }

    $payload = array_merge(array('message' => $success_message), (array) $data_array);
    wp_send_json_success($payload);
}

// AJAX callback to load premium tab content
function nppp_load_premium_content_callback() {
    check_ajax_referer('load_premium_content_nonce', '_wpnonce');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( __( 'Permission denied.', 'fastcgi-cache-purge-and-preload-nginx' ), 403 );
    }

    // On a large cache (100 k+ files)
    // on slow or network-attached storage this can easily exceed the default
    // 30-second ceiling that most PHP-FPM pools ship with, killing the process
    // mid-operation.

    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    // Retrieve plugin settings
    $options = get_option('nginx_cache_settings', []);
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '';

    // Generate the HTML content
    $premium_content = nppp_premium_html($nginx_cache_path);

    // Return the generated HTML to AJAX
    if (!empty($premium_content)) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside nppp_premium_html
        echo $premium_content;
    } else {
        // Send empty string to AJAX to trigger proper error
        echo '';
    }

    // Properly exit to avoid extra output
    wp_die();
}

// Prevent Directory Traversal attacks
function nppp_is_path_in_directory($path, $directory) {
    // Resolve real paths
    $real_path = realpath($path);
    $real_directory = realpath($directory);

    // Check if the real paths are valid
    if ($real_path === false) {
        return 'file_not_found';
    }
    if ($real_directory === false) {
        return 'invalid_cache_directory';
    }

    // Normalize paths
    $real_path = wp_normalize_path($real_path);
    $real_directory = wp_normalize_path($real_directory);

    // Add trailing slashes
    $real_path = trailingslashit($real_path);
    $real_directory = trailingslashit($real_directory);

    // Compare the directory paths
    if (strpos($real_path, $real_directory) === 0) {
        return true;
    } else {
        return 'outside_cache_directory';
    }
}

// Purge triggered from the Advanced tab — delegates entirely to nppp_purge_single.
function nppp_purge_cache_premium_callback() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'purge_cache_premium_nonce')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    nppp_perform_file_operation($log_file_path, 'create');

    // Get URL from POST — keep percent-encoding intact.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $cache_url = isset($_POST['cache_url']) ? esc_url_raw(trim(wp_unslash($_POST['cache_url']))) : '';

    if (!$cache_url || filter_var($cache_url, FILTER_VALIDATE_URL) === false) {
        nppp_log_and_send_error(
            __('ERROR: Invalid or missing URL.', 'fastcgi-cache-purge-and-preload-nginx'),
            $log_file_path
        );
    }

    $options          = get_option('nginx_cache_settings', []);
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '/dev/shm/change-me-now';

    // Reset severity tracker before capturing notices.
    $GLOBALS['nppp_last_notice_type'] = 'success';

    // Tell the screen guard in log.php to skip the screen check
    $GLOBALS['nppp_capturing_for_redirect'] = true;

    // Purge
    ob_start();
    nppp_purge_single($nginx_cache_path, $cache_url, false);
    $html_output = ob_get_clean();

    unset( $GLOBALS['nppp_capturing_for_redirect'] );

    // Yields only primary-URL notices.
    $log_text = trim(wp_strip_all_tags($html_output));

    $decoded_url  = rawurldecode($cache_url);
    $notice_type  = $GLOBALS['nppp_last_notice_type'] ?? 'success';

    // Hard failure
    if ($notice_type === 'error') {
        wp_send_json_error(
            $log_text ?: sprintf(
                /* translators: %s: page URL */
                __('ERROR: Cache purge failed for %s', 'fastcgi-cache-purge-and-preload-nginx'),
                $decoded_url
            )
        );
    }

    // Genuine success
    if ($notice_type === 'success') {
        $success_message = $log_text;

        // Compute related URLs to update sibling rows in the JS table.
        $related_urls = nppp_get_related_urls_for_single($cache_url);

        $affected_urls = array(nppp_display_human_url($cache_url));
        foreach ($related_urls as $rel) {
            $affected_urls[] = nppp_display_human_url($rel);
        }
        $affected_urls = array_values(array_unique($affected_urls));

        $preload_auto = (
            !empty($options['nppp_related_preload_after_manual'])
            && $options['nppp_related_preload_after_manual'] === 'yes'
            && !empty($related_urls)
        );

        $primary_preload = (
            !empty($options['nginx_cache_auto_preload'])
            && $options['nginx_cache_auto_preload'] === 'yes'
        );

        nppp_log_and_send_success_data(
            $success_message,
            $log_file_path,
            array('affected_urls' => $affected_urls, 'preload_auto' => $preload_auto, 'primary_preload' => $primary_preload),
            true
        );
    }

    // Soft outcome
    wp_send_json_error(
        $log_text ?: sprintf(
            /* translators: %s: page URL */
            __('INFO ADMIN: Nginx cache purge attempted for %s', 'fastcgi-cache-purge-and-preload-nginx'),
            $decoded_url
        )
    );
}

// Preload triggered from the Advanced tab
function nppp_preload_cache_premium_callback() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'preload_cache_premium_nonce')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem');
    }

    // Get the file path from the AJAX request
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Source is trusted; nonce/cap checked; keep percent-encoding intact.
    $cache_url = isset($_POST['cache_url']) ? trim( wp_unslash($_POST['cache_url']) ) : '';

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1280;
    $default_cpu_limit = 100;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Preload action options
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;

    // Validate the URL
    if (filter_var($cache_url, FILTER_VALIDATE_URL) === false) {
        wp_send_json_error('Preload Cache URL validation failed.');
    }

    // Reset tracker before buffering
    $GLOBALS['nppp_last_notice_type'] = 'success';

    // Tell the screen guard in log.php to skip the screen check
    $GLOBALS['nppp_capturing_for_redirect'] = true;

    // Start output buffering
    ob_start();

    // Call single preload action
    nppp_preload_single($cache_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path);

    // Capture and clean the buffer
    $output = wp_strip_all_tags(ob_get_clean());

    unset( $GLOBALS['nppp_capturing_for_redirect'] );

    if (($GLOBALS['nppp_last_notice_type'] ?? 'success') === 'error') {
        wp_send_json_error($output);
    }

    // Give enough headroom for PID wait + rg scan
    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    // Try to confirm preload completed using ripgrep (rg).
    // Scan works when:
    // - Direct read access to the cache directory (any user/permission setup), OR
    // - FUSE is active and safexec is available to elevate read privileges.
    // Otherwise, scan is skipped (cached = false, but preload itself still succeeded).
    $cached  = false;
    $rg_used = false;

    nppp_prepare_request_env();
    $rg_bin = trim( (string) shell_exec('command -v rg 2>/dev/null') );

    if ($rg_bin !== '') {
        // Wait for wget to finish before scanning.
        $pid      = intval( nppp_perform_file_operation($PIDFILE, 'read') );
        $deadline = time() + 3;

        while ( time() < $deadline && $pid > 0 && nppp_is_process_alive($pid) ) {
            usleep(100000);
        }

        // Resolve scan path
        $rg_source_path = nppp_fuse_source_path($nginx_cache_path);
        $rg_fuse_active = ($rg_source_path !== null);

        // Mount table may list a source path that no longer exists on disk.
        if ($rg_source_path !== null && !$wp_filesystem->is_dir($rg_source_path)) {
            nppp_display_admin_notice('info', sprintf(
                /* translators: %s: FUSE source filesystem path that no longer exists on disk. */
                __('WARNING RG SCAN: FUSE source path from mount table does not exist on disk, falling back to FUSE mount path: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                $rg_source_path
            ), true, false);
            $rg_source_path = null;
            $rg_fuse_active = false;
        }

        $rg_scan_path   = ($rg_source_path !== null)
            ? rtrim($rg_source_path, '/') . '/'
            : $nginx_cache_path;

        // Cheap Probe: can php process owner read the real source directory
        $probe_out  = [];
        $probe_exit = 0;
        exec(
            sprintf(
                '%s -q \'.\' --text --no-ignore --no-config -m 1 %s 2>/dev/null',
                escapeshellarg($rg_bin),
                escapeshellarg($rg_scan_path)
            ),
            $probe_out,
            $probe_exit
        );

        if ($probe_exit !== 2) {
            $rg_used       = true;
            $rg_cmd_prefix = '';
        } elseif ($rg_fuse_active) {
            $safexec_path = nppp_find_safexec_path();

            if ($safexec_path && nppp_is_safexec_usable($safexec_path, false)) {
                $rg_used       = true;
                $rg_cmd_prefix = escapeshellarg($safexec_path) . ' ';
            }
        }

        // Inform user about rg scan decision
        if ($rg_used) {
            if ($rg_fuse_active) {
                if ($rg_cmd_prefix !== '') {
                    nppp_display_admin_notice('info', sprintf(
                        /* translators: %s: Original Nginx cache filesystem path being scanned by ripgrep via safexec. */
                        __('INFO RG SCAN: FUSE mount detected, scanning original Nginx Cache Path (safexec): %s', 'fastcgi-cache-purge-and-preload-nginx'),
                        $rg_scan_path
                    ), true, false);
                } else {
                    nppp_display_admin_notice('info', sprintf(
                        /* translators: %s: FUSE source directory path being scanned directly. */
                        __('INFO RG SCAN: FUSE mount detected, scanning source dir directly (rg has direct access): %s', 'fastcgi-cache-purge-and-preload-nginx'),
                        $rg_scan_path
                    ), true, false);
                }
            }
        } elseif ($probe_exit === 2) {
            if ($rg_fuse_active) {
                nppp_display_admin_notice('info', sprintf(
                    /* translators: %s: Filesystem path that ripgrep could not scan due to missing safexec. */
                    __('WARNING RG SCAN: FUSE mount detected, rg scan skipped (install safexec to enable scan): %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    $rg_scan_path
                ), true, false);
            } else {
                nppp_display_admin_notice('info', sprintf(
                    /* translators: %s: Filesystem path that ripgrep could not access. */
                    __('WARNING RG SCAN: rg scan skipped (cannot access cache dir and safexec unavailable): %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    $rg_scan_path
                ), true, false);
            }
        }

        if ($rg_used) {
            $url_key = preg_replace('#^https?://#', '', $cache_url);

            $rg_cmd = sprintf(
                '%s%s -l -m 1 --text -E none --no-unicode --no-messages --no-ignore --no-config %s %s',
                $rg_cmd_prefix,
                escapeshellarg($rg_bin),
                escapeshellarg('^KEY: .*' . preg_quote($url_key, '/') . '$'),
                escapeshellarg($rg_scan_path)
            );

            $rg_out  = [];
            $rg_exit = 0;
            exec($rg_cmd, $rg_out, $rg_exit);

            // Cache file found on disk
            $cached = ($rg_exit === 0 && ! empty($rg_out));
        }
    }

    wp_send_json_success([
        'message' => $output,
        'cached'  => $cached,
        'rg_used' => $rg_used,
    ]);
}

// Extract cached URLs from Nginx cache directory using ripgrep.
function nppp_extract_cached_urls_rg(
    $wp_filesystem,
    string $scan_path,
    string $fuse_path,
    string $rg_bin,
    string $regex,
    string $rg_cmd_prefix = '',
    bool   $https_enabled  = false
): ?array {

    $scan_path = rtrim( $scan_path, '/' ) . '/';
    $fuse_path = rtrim( $fuse_path, '/' ) . '/';

    // SCAN 1 — Redirect check with -F
    // Linux Page Cache Warm‑Up (Dentry Cache) for SCAN 2
    $redirect_cmd = sprintf(
        '%s%s -F --text -l -m 1 -E none --no-unicode'
        . ' --no-heading --no-ignore --no-config --no-messages -e %s -e %s %s 2>/dev/null',
        $rg_cmd_prefix,
        escapeshellarg( $rg_bin ),
        escapeshellarg( 'Status: 301' ),
        escapeshellarg( 'Status: 302' ),
        escapeshellarg( $scan_path )
    );

    $redirect_out  = [];
    $redirect_exit = 0;
    exec( $redirect_cmd, $redirect_out, $redirect_exit );

    if ( $redirect_exit === 2 ) {
        return null;
    }

    $redirect_set = array_flip( array_filter( array_map( 'trim', $redirect_out ), 'strlen' ) );
    unset( $redirect_out );

    // SCAN 2 — extract KEY: line from every cache file.
    // Linux dcache already warmed here
    $key_cmd = sprintf(
        '%s%s --text -m 1 -E none --no-unicode'
        . ' --no-heading --no-ignore --no-config --no-messages %s %s 2>/dev/null',
        $rg_cmd_prefix,
        escapeshellarg( $rg_bin ),
        escapeshellarg( '^KEY: [^\r\n]+' ),
        escapeshellarg( $scan_path )
    );

    $key_out  = [];
    $key_exit = 0;
    exec( $key_cmd, $key_out, $key_exit );

    // Permission error
    if ( $key_exit === 2 ) {
        return null;
    }
    if ( $key_exit === 1 || empty( $key_out ) ) {
        return [ 'error' => 'NPPP_EMPTY_CACHE' ];
    }

    // Parse rg output lines: "FILEPATH:KEY: <cache_key_string>"
    $scheme       = $https_enabled ? 'https' : 'http';
    $urls         = [];
    $regex_tested = false;

    foreach ( $key_out as $raw_line ) {
        $raw_line = trim( $raw_line );
        if ( $raw_line === '' ) {
            continue;
        }

        // Split
        $sep = strpos( $raw_line, ':' );
        if ( $sep === false ) {
            continue;
        }

        $scan_filepath = substr( $raw_line, 0, $sep );
        $key_line      = substr( $raw_line, $sep + 1 );
        if ( $scan_filepath === '' || $key_line === '' ) {
            continue;
        }

        // Skip redirect files
        if ( isset( $redirect_set[ $scan_filepath ] ) ) {
            continue;
        }

        // Regex validation
        if ( ! $regex_tested ) {
            if ( ! preg_match( $regex, $key_line, $m ) || ! isset( $m[1], $m[2] ) ) {
                return [
                    'error' => sprintf(
                        /* translators: 1: Cache Key Regex option name. 2: Advanced Options section name. */
                        __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is configured correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx' ),
                        __( 'Advanced Options',  'fastcgi-cache-purge-and-preload-nginx' )
                    ),
                ];
            }
            if ( filter_var( 'https://' . trim( $m[1] ) . trim( $m[2] ), FILTER_VALIDATE_URL ) === false ) {
                return [
                    'error' => sprintf(
                        /* translators: 1: Cache Key Regex option name. 2: Advanced Options section name. */
                        __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is parsing the string <strong>\$host\$request_uri</strong> correctly.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx' ),
                        __( 'Advanced Options',  'fastcgi-cache-purge-and-preload-nginx' )
                    ),
                ];
            }
            $regex_tested = true;
        } else {
            $m = [];
            if ( ! preg_match( $regex, $key_line, $m ) || ! isset( $m[1], $m[2] ) ) {
                continue;
            }
        }

        // Build URL
        $host        = trim( $m[1] );
        $request_uri = trim( $m[2] );
        $url_encoded = $scheme . '://' . $host . $request_uri;

        if ( filter_var( $url_encoded, FILTER_VALIDATE_URL ) === false ) {
            continue;
        }

        // Human-readable decoded URL for display; encoded URL for operations.
        $url_decoded = $scheme . '://' . $host . rawurldecode( $request_uri );

        // FUSE active: translate real source path to writable FUSE mount.
        if ( $scan_path !== $fuse_path ) {
            $translated = nppp_translate_path_to_fuse( $scan_filepath, $scan_path, $fuse_path );
            if ( $translated === null ) {
                nppp_display_admin_notice( 'error', sprintf(
                    /* translators: 1: Decoded page URL. 2: Cache file path that failed translation. 3: Expected scan path prefix. */
                    __( 'WARNING PATH TRANSLATE: Purge failed for "%1$s". Failed path translation - "%2$s" does not start with "%3$s"', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $url_decoded,
                    $scan_filepath,
                    $scan_path
                ) );
                continue;
            }
            $fuse_filepath = $translated;
        } else {
            $fuse_filepath = $scan_filepath;
        }

        // Calculating cache date is expensive with rg
        $urls[] = [
            'file_path'   => $fuse_filepath,
            'url'         => $url_decoded,
            'url_encoded' => $url_encoded,
            'category'    => nppp_categorize_url( $url_decoded ),
        ];
    }

    if ( empty( $urls ) ) {
        return [ 'error' => 'NPPP_EMPTY_CACHE' ];
    }

    // Calculate cache variants
    nppp_annotate_variant_counts( $urls );

    return $urls;
}

// Recursively traverses directories and extracts necessary data from files.
// We always need live stats for HITs and MISS
function nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    $decoded = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'], true)
             : false;

    $regex   = ($decoded !== false && $decoded !== '')
             ? $decoded
             : nppp_fetch_default_regex_for_cache_key();

    $https_enabled = wp_is_using_https();
    $head_bytes    = (int) apply_filters('nppp_locate_head_bytes', 4096);
    $head_bytes_fb = (int) apply_filters('nppp_locate_head_bytes_fallback', 32768);

    // FP1 — ripgrep

    /**
    * Test switch for testing RG vs PHP Iterative
    *
    * $rg_enabled = ! empty( $nginx_cache_settings['nppp_rg_purge_enabled'] )
    *     && $nginx_cache_settings['nppp_rg_purge_enabled'] === 'yes';
    * $rg_bin = $rg_enabled ? trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) ) : '';

    * Always use ripgrep (rg) if the binary is present on the system.
    *
    * PERFORMANCE BENCHMARK | Advanced Tab Load Times | ripgrep + safexec
    * (5,000 cached URLs, containerised environment):
    * ---------------------------------------------------------------------
    * | Method               | Cold dcache | Warm dcache |
    * |----------------------|-------------|-------------|
    * | ripgrep (rg)         | ~10 seconds | ~6 seconds  |
    * | PHP RecursiveIterator| ~52 seconds | ~23 seconds |
    * ---------------------------------------------------------------------
    */

    nppp_prepare_request_env();
    $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
    if ( $rg_bin !== '' ) {

        // Resolve FUSE mount
        $rg_fuse_path   = $nginx_cache_path;
        $rg_source_path = nppp_fuse_source_path( $rg_fuse_path );

        // Mount table may list a FUSE source path that no longer exists on disk.
        if ( $rg_source_path !== null && ! $wp_filesystem->is_dir( $rg_source_path ) ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: %s: FUSE source filesystem path that no longer exists on disk. */
                __( 'WARNING RG SCAN: FUSE source path from mount table does not exist on disk, falling back to FUSE mount path: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $rg_source_path
            ), true, false );
            $rg_source_path = null;
        }

        $rg_fuse_active = ( $rg_source_path !== null );
        $rg_use_safexec = false;
        $rg_safexec_bin = '';

        // FUSE active: try scanning the real source dir (fast).
        // If php lacks read access(probably always), use safexec to elevate read privileges.
        // Fall back to FUSE mount if safexec unavailable (slow).
        // Purge always go through the writable FUSE mount
        if ( $rg_fuse_active ) {
            // FUSE active – try to scan the real source path directly
            $rg_scan_path = rtrim( $rg_source_path, '/' ) . '/';

            // Cheap Probe: can php read the real source directory
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

            // Permission error – try to use safexec (elevated read)
            if ( $probe_exit === 2 ) {
                $sfx = nppp_find_safexec_path();
                if ( $sfx && nppp_is_safexec_usable( $sfx, false ) ) {
                    $rg_use_safexec = true;
                    $rg_safexec_bin = $sfx;
                } else {
                    $rg_scan_path = $rg_fuse_path;
                }
            }
        } else {
            // No safexec – fall back to scanning the FUSE mount path (slow)
            $rg_scan_path = $rg_fuse_path;
        }

        // Prepend safexec to the rg command if needed
        $rg_cmd_prefix = $rg_use_safexec ? escapeshellarg( $rg_safexec_bin ) . ' ' : '';

        // Debug logs for decision taken
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

        // Run ripgrep extraction
        $rg_result = nppp_extract_cached_urls_rg(
            $wp_filesystem,
            $rg_scan_path,
            $rg_fuse_path,
            $rg_bin,
            $regex,
            $rg_cmd_prefix,
            $https_enabled
        );

        // If rg succeeded, return immediately.
        if ( $rg_result !== null ) {
            return $rg_result;
        }

        // Fatal error (permission denied or path not accessible). rg is authoritative here – PHP would fail the same way.
        return [ 'error' => __( 'Failed to scan cache directory with ripgrep: permission denied or path not accessible.', 'fastcgi-cache-purge-and-preload-nginx' ) ];
    }

    $urls = [];

    try {
        // Traverse the cache directory and its subdirectories
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $regex_tested = false;
        foreach ($cache_iterator as $file) {
            $path = $file->getPathname();

            $content = nppp_read_head($wp_filesystem, $path, $head_bytes);
            if ($content === '') { continue; }

            $match = [];
            if (!preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                if (strlen($content) >= $head_bytes) {
                    $content = nppp_read_head($wp_filesystem, $path, $head_bytes_fb);
                    if ($content === '' || !preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            // Ignore redirects
            if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                strpos($content, 'Status: 302 Found') !== false) {
                continue;
            }

            // Test regex
            if (!$regex_tested) {
                if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
                    // Build the URL
                    $host = trim($matches[1]);
                    $request_uri = trim($matches[2]);
                    $constructed_url = $host . $request_uri;

                    // Test parsed URL via regex with FILTER_VALIDATE_URL
                    // We need to add prefix here
                    $constructed_url_test = 'https://' . $constructed_url;

                    // Test if the URL is in the expected format
                    if ($constructed_url !== '' && filter_var($constructed_url_test, FILTER_VALIDATE_URL)) {
                        $regex_tested = true;
                    } else {
                        return [
                            'error' => sprintf(
                                /* translators: 1: Cache Key Regex option name. 2: Advanced Options section name. */
                                __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is parsing the string <strong>\$host\$request_uri</strong> correctly.', 'fastcgi-cache-purge-and-preload-nginx'),
                                __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'),
                                __( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx')
                            )
                        ];
                    }
                } else {
                    return [
                        'error' => sprintf(
                            /* translators: 1: Cache Key Regex option name. 2: Advanced Options section name. */
                            __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is configured correctly.', 'fastcgi-cache-purge-and-preload-nginx'),
                            __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'),
                            __( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx')
                        )
                    ];
                }
            }

            // Extract URLs using regex
            if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
                // Build the URL
                $host = trim($matches[1]);
                $request_uri = trim($matches[2]);

                // Keep the encoded URI for internal consistency
                $constructed_url_encoded = $host . $request_uri;

                // Sanitize and validate the encoded URL
                $final_url_encoded = ($https_enabled ? 'https://' : 'http://') . $constructed_url_encoded;

                if (filter_var($final_url_encoded, FILTER_VALIDATE_URL) !== false) {
                    // Decode URI only for displaying URLs in human-readable form
                    $decoded_uri = rawurldecode($request_uri);
                    $constructed_url_decoded = $host . $decoded_uri;
                    $final_url = $https_enabled ? "https://$constructed_url_decoded" : "http://$constructed_url_decoded";

                    // Categorize URLs
                    $category = nppp_categorize_url($final_url);

                    // Store URL data
                    $urls[] = array(
                        'file_path'   => $file->getPathname(),
                        'url'         => $final_url,
                        'url_encoded' => $final_url_encoded,
                        'category'    => $category,
                    );
                }
            }
        }
    } catch (Exception $e) {
        return [
            'error' => __( 'An error occurred while accessing the Nginx cache directory. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        ];
    }

    // Empty cache
    if (empty($urls)) {
        return [
            'error' => 'NPPP_EMPTY_CACHE',
        ];
    }

    // Calculate cache variants
    nppp_annotate_variant_counts( $urls );

    return $urls;
}

/**
 * Categorize a URL into a human-readable content-type label.
 *
 * @param string $url Absolute URL to classify.
 * @return string     Uppercase label, e.g. 'POST', 'PRODUCT', 'CATEGORY' …
 */
function nppp_categorize_url( string $url ): string {

    // Static caches
    static $url_cache        = [];
    static $bulk_map         = null;
    static $needs_update     = false;
    static $rewrite_rules    = null;
    static $site_host        = null;
    static $tax_qvar_map     = null;
    static $show_on_front    = null;
    static $pfp_id           = null;
    static $pfp_path         = null;

    // L1 hit
    if ( isset( $url_cache[ $url ] ) ) {
        return $url_cache[ $url ];
    }

    // L2 init + shutdown flush
    if ( $bulk_map === null ) {
        $bulk_map = get_transient( 'nppp_category_map' );
        $bulk_map = is_array( $bulk_map ) ? $bulk_map : [];

        // Ring-buffer flush: keep newest 20 k entries, persist weekly.
        add_action( 'shutdown', static function () use ( &$bulk_map, &$needs_update ) {
            if ( ! $needs_update ) {
                return;
            }
            if ( count( $bulk_map ) > 20000 ) {
                $bulk_map = array_slice( $bulk_map, -20000, 20000, true );
            }
            set_transient( 'nppp_category_map', $bulk_map, WEEK_IN_SECONDS );
        } );
    }

    $map_key = md5( $url );

    // L2 hit
    if ( isset( $bulk_map[ $map_key ] ) ) {
        $url_cache[ $url ] = $bulk_map[ $map_key ];
        return $bulk_map[ $map_key ];
    }

    // Shared finalize closure
    $finish = static function ( string $label ) use ( $url, $map_key, &$url_cache, &$bulk_map, &$needs_update ): string {
        $label                = (string) apply_filters( 'nppp_categorize_url_result', $label, $url );
        $url_cache[ $url ]    = $label;
        $bulk_map[ $map_key ] = $label;
        $needs_update         = true;
        return $label;
    };

    // 1. HOST GUARD
    if ( $site_host === null ) {
        $site_host = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
    }
    $url_host = (string) wp_parse_url( $url, PHP_URL_HOST );
    if ( $url_host === '' || strcasecmp( $url_host, $site_host ) !== 0 ) {
        return $finish( 'EXTERNAL' );
    }

    // 2. NORMALIZE PATH  (strip paged segments before any logic)
    $raw_path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '/' );
    $path     = trim( rawurldecode( $raw_path ), '/' );
    $path     = (string) preg_replace( '#(?:^|/)page/\d+(?:/.*)?$#', '', $path );
    $path     = trim( $path, '/' );

    // 3. HOMEPAGE EARLY-EXIT
    if ( $path === '' ) {
        return $finish( 'HOMEPAGE' );
    }

    // 4. FEED / EMBED EARLY-EXIT
    if ( preg_match( '#(?:^|/)feed(?:/[^/]*)?/?$#', $path ) ) {
        return $finish( 'FEED' );
    }

    // 5. REWRITE RULES → QUERY VARS
    global $wp_rewrite;
    if ( $rewrite_rules === null ) {
        $rewrite_rules = (array) $wp_rewrite->wp_rewrite_rules();
    }

    $query_vars = [];
    foreach ( $rewrite_rules as $match => $query ) {
        if ( preg_match( "#^{$match}#", $path, $m )
          || preg_match( "#^{$match}#", rawurlencode( $path ), $m ) ) {
            $q = preg_replace( '!^.+\?!', '', $query );
            if ( class_exists( 'WP_MatchesMapRegex' ) ) {
                $q = WP_MatchesMapRegex::apply( $q, $m );
            }
            parse_str( $q, $query_vars );
            break;
        }
    }

    // Plain permalink / query-string fallback.
    $raw_qs = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?? '' );
    if ( empty( $query_vars ) && $raw_qs !== '' ) {
        parse_str( $raw_qs, $query_vars );
    }

    if ( empty( $query_vars ) ) {
        return $finish( 'UNKNOWN' );
    }

    // Feed / embed via query var (e.g. ?feed=rss2, ?embed=1).
    if ( ! empty( $query_vars['feed'] ) || ! empty( $query_vars['embed'] ) ) {
        return $finish( 'FEED' );
    }

    // 6. WOOCOMMERCE FAST-PATH
    if ( function_exists( 'wc_get_page_id' ) ) {

        // Singular product: ?product=slug
        if ( ! empty( $query_vars['product'] ) ) {
            return $finish( 'PRODUCT' );
        }
        // Product category archive: ?product_cat=slug
        if ( ! empty( $query_vars['product_cat'] ) ) {
            return $finish( 'PRODUCT CATEGORY' );
        }
        // Product tag archive: ?product_tag=slug
        if ( ! empty( $query_vars['product_tag'] ) ) {
            return $finish( 'PRODUCT TAG' );
        }

        // post_type=product without singular identifiers → shop or product.
        if ( isset( $query_vars['post_type'] ) ) {
            $wc_pt = (array) $query_vars['post_type'];
            if ( in_array( 'product', $wc_pt, true ) ) {
                return $finish(
                    ! empty( $query_vars['name'] ) || ! empty( $query_vars['p'] )
                        ? 'PRODUCT'
                        : 'SHOP'
                );
            }
        }

        // Public product attribute archives: ?pa_color=red (str_starts_with PHP 8+)
        foreach ( array_keys( $query_vars ) as $qv ) {
            if ( strpos( $qv, 'pa_' ) === 0 && ! empty( $query_vars[ $qv ] ) ) {
                return $finish( 'PRODUCT ATTR: ' . strtoupper( substr( $qv, 3 ) ) );
            }
        }
    }

    // 7. TAXONOMY QUERY-VAR MAP
    if ( $tax_qvar_map === null ) {
        $tax_qvar_map = [];
        foreach ( get_taxonomies( [], 'objects' ) as $tax_slug => $tax_obj ) {
            // query_var may be a boolean false, 'is_admin()' result, or a string.
            if ( ! $tax_obj->query_var ) {
                continue;
            }
            $tax_qvar_map[ (string) $tax_obj->query_var ] = $tax_slug;
        }
    }

    // 8. FLAG CLASSIFICATION
    $is_attachment = ( '' !== ( (string) ( $query_vars['attachment'] ?? '' ) ) )
                  || ! empty( $query_vars['attachment_id'] );

    $is_single = $is_attachment
              || '' !== ( (string) ( $query_vars['name'] ?? '' ) )
              || ! empty( $query_vars['p'] );

    $is_page = ! $is_single
            && ( '' !== ( (string) ( $query_vars['pagename'] ?? '' ) )
              || ! empty( $query_vars['page_id'] ) );

    $is_category  = false;
    $is_tag       = false;
    $is_tax       = false;
    $detected_tax = '';

    if ( ! $is_single && ! $is_page ) {

        if ( ! empty( $query_vars['category_name'] )
          || ( ! empty( $query_vars['cat'] ) && (int) $query_vars['cat'] > 0 ) ) {
            $is_category  = true;
            $detected_tax = 'category';
        }

        if ( ! $is_category
          && ( ! empty( $query_vars['tag'] ) || ! empty( $query_vars['tag_id'] ) ) ) {
            $is_tag       = true;
            $detected_tax = 'post_tag';
        }

        if ( ! $is_category && ! $is_tag
          && ! empty( $query_vars['taxonomy'] ) && ! empty( $query_vars['term'] ) ) {
            $is_tax       = true;
            $detected_tax = (string) $query_vars['taxonomy'];
        }

        if ( ! $is_category && ! $is_tag && ! $is_tax ) {
            foreach ( $tax_qvar_map as $qvar => $tax_slug ) {
                // category and post_tag already handled above.
                if ( 'category' === $tax_slug || 'post_tag' === $tax_slug ) {
                    continue;
                }
                if ( ! empty( $query_vars[ $qvar ] ) ) {
                    $is_tax       = true;
                    $detected_tax = $tax_slug;
                    break;
                }
            }
        }
    }

    // Author / Date / Search
    $is_author = ! $is_single && ! $is_page
              && ( ! empty( $query_vars['author'] ) || ! empty( $query_vars['author_name'] ) );

    $is_date = ! $is_single && ! $is_page && (
           ( isset( $query_vars['second']   ) && '' !== (string) $query_vars['second'] )
        || ( isset( $query_vars['minute']   ) && '' !== (string) $query_vars['minute'] )
        || ( isset( $query_vars['hour']     ) && '' !== (string) $query_vars['hour'] )
        || ! empty( $query_vars['day'] )
        || ! empty( $query_vars['monthnum'] )
        || ! empty( $query_vars['year'] )
        || ! empty( $query_vars['m'] )
        || ! empty( $query_vars['w'] )
    );

    $is_search = ! $is_single && ! $is_page
              && isset( $query_vars['s'] ) && '' !== $query_vars['s'];

    // Post-type archive
    $is_pta = false;
    $pta_pt  = '';
    if ( ! $is_single && ! $is_page
      && ! empty( $query_vars['post_type'] ) && ! is_array( $query_vars['post_type'] ) ) {
        $pta_obj = get_post_type_object( $query_vars['post_type'] );
        if ( $pta_obj && ! empty( $pta_obj->has_archive ) ) {
            $is_pta = true;
            $pta_pt = (string) $query_vars['post_type'];
        }
    }

    // 9. FLAGS → LABEL
    if ( function_exists( 'wc_get_page_id' ) ) {
        if ( $is_pta && 'product' === $pta_pt ) {
            return $finish( 'SHOP' );
        }
        if ( $is_tax || $is_category || $is_tag ) {
            if ( 'product_cat' === $detected_tax )           { return $finish( 'PRODUCT CATEGORY' ); }
            if ( 'product_tag' === $detected_tax )           { return $finish( 'PRODUCT TAG' ); }
            if ( strpos( $detected_tax, 'pa_' ) === 0 )      { return $finish( 'PRODUCT ATTR: ' . strtoupper( substr( $detected_tax, 3 ) ) ); }
        }
    }

    // Attachment singular
    if ( $is_attachment ) {
        return $finish( 'ATTACHMENT' );
    }

    // Singular posts / CPTs.
    if ( $is_single ) {
        $pt = (string) ( ! empty( $query_vars['post_type'] ) ? $query_vars['post_type'] : 'post' );
        if ( function_exists( 'wc_get_page_id' ) && 'product' === $pt ) { return $finish( 'PRODUCT' ); }
        if ( 'post' === $pt )  { return $finish( 'POST' ); }
        if ( 'page' === $pt )  { return $finish( 'PAGE' ); } // CPT registered with post_type=page is edge-case
        $pt_obj = get_post_type_object( $pt );
        return $finish( $pt_obj ? strtoupper( $pt_obj->labels->singular_name ) : strtoupper( $pt ) );
    }

    // Pages
    if ( $is_page ) {
        if ( $show_on_front === null ) {
            $show_on_front = (string) get_option( 'show_on_front', 'posts' );
            $pfp_id        = ( 'page' === $show_on_front )
                ? (int) get_option( 'page_for_posts', 0 )
                : 0;
            if ( $pfp_id > 0 ) {
                $pfp_post  = get_post( $pfp_id );
                $pfp_path  = $pfp_post ? trim( (string) get_page_uri( $pfp_post ), '/' ) : '';
            } else {
                $pfp_path  = '';
            }
        }

        if ( $pfp_id > 0 ) {
            $req_pid = ! empty( $query_vars['page_id'] ) ? (int) $query_vars['page_id'] : 0;
            if ( $req_pid > 0 && $req_pid === $pfp_id ) {
                return $finish( 'BLOG' );
            }

            if ( $req_pid === 0 && $pfp_path !== ''
              && trim( (string) ( $query_vars['pagename'] ?? '' ), '/' ) === $pfp_path ) {
                return $finish( 'BLOG' );
            }
        }

        return $finish( 'PAGE' );
    }

    // Standard taxonomy archives.
    if ( $is_category ) { return $finish( 'CATEGORY' ); }
    if ( $is_tag )      { return $finish( 'TAG' ); }
    if ( $is_tax ) {
        $tax_obj = $detected_tax !== '' ? get_taxonomy( $detected_tax ) : null;
        return $finish( $tax_obj ? strtoupper( $tax_obj->labels->singular_name ) : 'TAXONOMY' );
    }

    if ( $is_author ) { return $finish( 'AUTHOR' ); }
    if ( $is_date )   { return $finish( 'DATE_ARCHIVE' ); }
    if ( $is_search ) { return $finish( 'SEARCH_RESULTS' ); }

    if ( $is_pta ) {
        $pt_obj = $pta_pt !== '' ? get_post_type_object( $pta_pt ) : null;
        return $finish( $pt_obj ? strtoupper( $pt_obj->labels->name ) : 'ARCHIVE' );
    }

    return $finish( 'UNKNOWN' );
}
