<?php
/**
 * Advanced tab handlers for Nginx Cache Purge Preload
 * Description: Implements advanced admin actions for targeted URL purge and preload tasks.
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

/** Plugin root */
function nppp_get_plugin_root_path() {
    return rtrim( dirname( plugin_dir_path( __FILE__ ) ), '/' );
}

/* ==========================================
   Parse nppp-wget.log → MISS candidates map
   ========================================== */

function nppp_parse_wget_log_urls( $wp_filesystem ) {
    $log_path    = nppp_get_runtime_file('nppp-wget.log');
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
    $settings      = get_option('nginx_cache_settings');
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
            'url'         => $display_url,
            'url_encoded' => $item['url_encoded'],
            'file_path'   => $item['file_path'],
            'category'    => $item['category'],
            'cache_date'  => $item['cache_date'],
            'status'      => 'HIT',
        ];
    }

    // Add MISSes from wget
    $wget_map = nppp_parse_wget_log_urls( $wp_filesystem );
    foreach ( $wget_map as $k => $item ) {
        if ( isset( $hit_map[ $k ] ) ) { continue; }
        $rows[] = [
            'url'         => $item['url'],
            'url_encoded' => $item['url_encoded'],
            'file_path'   => '—',
            'category'    => $item['category'],
            'cache_date'  => isset($item['log_date']) ? $item['log_date'] : '—',
            'status'      => 'MISS',
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
        /* Translators: "MISS" is a cache status label. Keep the <strong> tags. */
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

    // Output the premium tab content
    ob_start();
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
        <p style="margin: 0; display: flex; align-items: center;">
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
                <th><?php esc_html_e( 'Cache Date', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Action', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            </tr>
            <tr class="nppp-filter-row">
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th class="nppp-filter-no-col"></th>
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
                    <td><?php echo esc_html( $row['cache_date'] ); ?></td>
                    <td>
                        <button type="button"
                                class="nppp-purge-btn"
                                <?php echo $is_hit ? '' : 'disabled aria-disabled="true" title="' . esc_attr__('Not cached yet', 'fastcgi-cache-purge-and-preload-nginx') . '"'; ?>
                                data-file="<?php echo $is_hit ? esc_attr($row['file_path']) : ''; ?>">
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
function nppp_log_and_send_success_data($success_message, $log_file_path, $data_array) {
    // Log a plain-text version
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

    // Attach the message as "message" and anything else the caller passed
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
    $options = get_option('nginx_cache_settings');
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '';

    // Generate the HTML content
    $premium_content = nppp_premium_html($nginx_cache_path);

    // Return the generated HTML to AJAX
    if (!empty($premium_content)) {
        echo wp_kses_post($premium_content);
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

// Deletes the selected file when purging is triggered via AJAX.
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

    // On a large cache (100 k+ files)
    // on slow or network-attached storage this can easily exceed the default
    // 30-second ceiling that most PHP-FPM pools ship with, killing the process
    // mid-operation.

    // Note: because gate to nppp_purge_urls_silent
    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }

    // Create log file
    $log_file_path = NGINX_CACHE_LOG_FILE;
    nppp_perform_file_operation($log_file_path, 'create');

    // Get the main path from plugin settings
    $options = get_option('nginx_cache_settings');
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '';

    // Retrieve and decode user-defined cache key regex from the db, with a hardcoded fallback
    $regex = isset($options['nginx_cache_key_custom_regex'])
             ? base64_decode($options['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem');
    }

    // Get the PID file path
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    // First, check if any cache preloading action is in progress.
    // Purging the cache for a single page or post in Advanced tab while cache preloading is in progress can cause issues
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        // Check process is alive
        if ($pid > 0 && nppp_is_process_alive($pid)) {
            $error_message = __( 'INFO ADMIN: Single-page purge skipped — Nginx cache preloading is in progress. Check the Status tab to monitor; wait for completion or use "Purge All" to cancel.', 'fastcgi-cache-purge-and-preload-nginx' );
            nppp_log_and_send_error($error_message, $log_file_path);
        }
    }

    // Get the file path from the AJAX request and sanitize it
    $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';

    // Prevent Directory Traversal attacks
    $path_check = nppp_is_path_in_directory($file_path, $nginx_cache_path);

    if ($path_check !== true) {
        switch ($path_check) {
            case 'file_not_found':
                $error_message = __( 'Nginx cache purge attempted, but no cache entry was found.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 'invalid_cache_directory':
                $error_message = __( 'Nginx cache purge failed because the Nginx cache directory is invalid.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 'outside_cache_directory':
                $error_message = __( 'The attempt to purge the Nginx cache was blocked due to security restrictions.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            default:
                $error_message = __( 'An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Check permissions before purge cache
    if (!$wp_filesystem->is_readable($file_path) || !$wp_filesystem->is_writable($file_path)) {
        $error_message = __( 'ERROR PERMISSION: The Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Get the purged URL
    $https_enabled = wp_is_using_https();
    $content = nppp_read_head($wp_filesystem, $file_path, 4096);

    $final_url = '';
    if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
        // Build the URL
        $host = trim($matches[1]);
        $request_uri = trim($matches[2]);
        $constructed_url = $host . $request_uri;

        // Test parsed URL via regex with FILTER_VALIDATE_URL
        // We need to add prefix here
        $constructed_url_test = 'https://' . $constructed_url;

        if ($constructed_url !== '' && filter_var($constructed_url_test, FILTER_VALIDATE_URL)) {
            $scheme    = $https_enabled ? 'https://' : 'http://';
            $final_url = $scheme . $constructed_url;
        }
    }

    // Sanitize and validate the file path again deeply before purge cache
    // This is an extra security layer
    $validation_result = nppp_validate_path($file_path, true);

    // Check the validation result
    if ($validation_result !== true) {
        // Handle different validation outcomes
        switch ($validation_result) {
            case 'critical_path':
                $error_message = __( 'ERROR PATH: The Nginx cache path appears to be a critical system directory or a first-level directory. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 'file_not_found_or_not_readable':
                $error_message = __( 'ERROR PATH: The specified Nginx cache path was not found. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            default:
                $error_message = __( 'ERROR PATH: An invalid Nginx cache path was provided. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Acquire exclusive purge lock — prevents concurrent Advanced-tab purge
    // racing with Purge All or another admin's single-page purge.
    // Must be released explicitly before every nppp_log_and_send_* call
    // because those call wp_send_json_* → wp_die() which bypasses finally.
    if ( ! nppp_acquire_purge_lock( 'premium' ) ) {
        $error_message = __( 'INFO ADMIN: Single-page purge skipped — another cache purge operation is already in progress. Please try again shortly.', 'fastcgi-cache-purge-and-preload-nginx' );
        nppp_log_and_send_error( $error_message, $log_file_path );
    }

    // Perform the purge action (delete the file)
    $deleted = $wp_filesystem->delete($file_path);

    // Display decoded URL to user
    $final_url_decoded = rawurldecode($final_url);

    if ($deleted) {
        // Translators: %s is the page URL
        $success_message = sprintf( __( 'SUCCESS ADMIN: Nginx cache purged for page %s', 'fastcgi-cache-purge-and-preload-nginx' ), $final_url_decoded );

        $settings = get_option('nginx_cache_settings');
        $related_urls = nppp_get_related_urls_for_single($final_url);

        // Purge related cache (no extra notices, as this is an AJAX JSON response)
        nppp_purge_urls_silent($nginx_cache_path, $related_urls);

        // All cache filesystem work done — release lock before post-purge
        // side effects (Cloudflare, preload) so other admins are unblocked.
        nppp_release_purge_lock();

        // Cloudflare purge cache (sync with advanced single-page purge)
        $purged_urls = array_merge($final_url ? array($final_url) : array(), $related_urls);
        $purged_urls = array_values(array_filter($purged_urls));
        if (!empty($purged_urls)) {
            do_action(
                'nppp_purged_urls',
                $purged_urls,
                $final_url,
                $final_url ? (int) url_to_postid($final_url) : 0,
                false
            );
        }

        // Preload policy for manual (Advanced tab is manual)
        if (!empty($settings['nppp_related_preload_after_manual']) && $settings['nppp_related_preload_after_manual'] === 'yes') {
            nppp_preload_urls_fire_and_forget($related_urls);
        }

        // Optionally make the success message clearer
        if (!empty($related_urls)) {
            $labels = [];
            if (!empty($settings['nppp_related_include_home']) && $settings['nppp_related_include_home'] === 'yes') {
                $labels[] = esc_html__('Homepage', 'fastcgi-cache-purge-and-preload-nginx');
            }
            if (!empty($settings['nppp_related_apply_manual']) && $settings['nppp_related_apply_manual'] === 'yes') {
                $labels[] = esc_html__('Shop Page', 'fastcgi-cache-purge-and-preload-nginx');
            }
            if (!empty($settings['nppp_related_include_category']) && $settings['nppp_related_include_category'] === 'yes') {
                $labels[] = esc_html__('Category archive(s)', 'fastcgi-cache-purge-and-preload-nginx');
            }
            $label_text = implode('/', $labels);

            $preload_tail = (!empty($settings['nppp_related_preload_after_manual']) && $settings['nppp_related_preload_after_manual'] === 'yes')
                ? esc_html__(' purged & preloaded', 'fastcgi-cache-purge-and-preload-nginx')
                : esc_html__(' purged', 'fastcgi-cache-purge-and-preload-nginx');

            // Second line, colored
            $success_message .= '<br><span class="nppp-related-line">('
                . sprintf(
                    /* Translators: %s is like "homepage/category archive(s)" */
                    esc_html__('Related: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    esc_html($label_text)
                )
                . $preload_tail
                . ')</span>';
        }
        // Build affected list
        $affected_urls = array();
        if ($final_url) {
            $affected_urls[] = nppp_display_human_url($final_url);
        }
        if (!empty($related_urls)) {
            foreach ($related_urls as $rel) {
                $affected_urls[] = nppp_display_human_url($rel);
            }
        }
        $affected_urls = array_values(array_unique($affected_urls));

        // Whether auto-preload for related pages is active
        $preload_auto = (
            !empty($settings['nppp_related_preload_after_manual'])
            && $settings['nppp_related_preload_after_manual'] === 'yes'
            && !empty($related_urls)
        ) ? true : false;

        // Return structured payload so JS can update other rows
        // Lock already released above after filesystem work completed.
        nppp_log_and_send_success_data(
            $success_message,
            $log_file_path,
            array(
                'affected_urls' => $affected_urls,
                'preload_auto'  => $preload_auto,
            )
        );
    } else {
        // Translators: %s is the page URL
        $error_message = sprintf( __( 'ERROR ADMIN: Nginx cache can not be purged for page %s', 'fastcgi-cache-purge-and-preload-nginx' ), $final_url_decoded );
        nppp_release_purge_lock();
        nppp_log_and_send_error($error_message, $log_file_path);
    }
}

// Deletes the selected file when purging is triggered via AJAX
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
    $nginx_cache_settings = get_option('nginx_cache_settings');

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

    // Validate the sanitized URL
    if (filter_var($cache_url, FILTER_VALIDATE_URL) !== false) {
        // Reset tracker before buffering
        $GLOBALS['nppp_last_notice_type'] = 'success';

        // Start output buffering
        ob_start();

        // call single preload action
        nppp_preload_single($cache_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path);

        // Capture and clean the buffer
        $output = wp_strip_all_tags(ob_get_clean());

        // Read the type that nppp_display_admin_notice() recorded directly.
        // Language-independent
        if (($GLOBALS['nppp_last_notice_type'] ?? 'success') === 'error') {
            wp_send_json_error($output);
        } else {
            wp_send_json_success($output);
        }
    } else {
        wp_send_json_error('Preload Cache URL validation failed.');
    }
}

// Update Purge button data if missing before and hit now
function nppp_locate_cache_file_ajax() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( __( 'Permission denied.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // Nonce
    if ( empty($_POST['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['_wpnonce']) ), 'locate_cache_file_nonce' ) ) {
        wp_send_json_error( __( 'Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Source is trusted; nonce/cap checked; keep percent-encoding intact.
    $cache_url = isset($_POST['cache_url']) ? trim( wp_unslash($_POST['cache_url']) ) : '';
    if ( ! $cache_url || ! filter_var($cache_url, FILTER_VALIDATE_URL) ) {
        wp_send_json_error( __( 'Invalid URL.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // On a large cache (100 k+ files)
    // on slow or network-attached storage this can easily exceed the default
    // 30-second ceiling that most PHP-FPM pools ship with, killing the process
    // mid-operation.

    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    $settings = get_option('nginx_cache_settings');
    $nginx_cache_path = isset($settings['nginx_cache_path']) ? $settings['nginx_cache_path'] : '';

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false || ! $wp_filesystem->is_dir($nginx_cache_path) ) {
        wp_send_json_error( __( 'Cache directory not accessible.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // Cache-key regex (same as extractor uses)
    $regex = isset($settings['nginx_cache_key_custom_regex'])
             ? base64_decode($settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    // Build the target match key from the URL we just preloaded
    $needle_key = nppp_url_match_key( $cache_url );

    // Only scan *recent* files (default 10 minutes) for speed
    $window_secs = apply_filters('nppp_locate_recent_window', 600);
    $now = time();
    $found_path = '';

    // Read only the head (binary-safe)
    $head_bytes_primary  = (int) apply_filters('nppp_locate_head_bytes', 4096);
    $head_bytes_fallback = (int) apply_filters('nppp_locate_head_bytes_fallback', 32768);

    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iter as $file ) {
            $pathname = $file->getPathname();
            if (($now - $file->getMTime()) > $window_secs) { continue; }

            $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_primary);
            if ($content === '') { continue; }

            $match = [];
            if (!preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                // If we likely truncated at primary cap, try a single larger read
                if (strlen($content) >= $head_bytes_primary) {
                    $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_fallback);
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

            // Accept only GET entries (HEAD/POST/etc. are not cache targets here)
            $key_line = $match[1];
            if (strpos($key_line, 'POST') !== false ||
                strpos($key_line, 'HEAD') !== false ||
                strpos($key_line, 'PUT') !== false ||
                strpos($key_line, 'DELETE') !== false ||
                strpos($key_line, 'PATCH') !== false ||
                strpos($key_line, 'OPTIONS') !== false) {
                continue;
            }

            if (preg_match($regex, $content, $m) && isset($m[1], $m[2])) {
                $host = trim($m[1]);
                $uri  = trim($m[2]);

                // Rebuild encoded URL like the extractor does
                $https = wp_is_using_https();
                $final_encoded = ($https ? 'https://' : 'http://') . ($host . $uri);

                if ( filter_var($final_encoded, FILTER_VALIDATE_URL) ) {
                    $key = nppp_url_match_key($final_encoded);
                    if ($key === $needle_key) {
                        $found_path = $pathname;
                        break;
                    }
                }
            }
        }
    } catch ( Exception $e ) {
    }

    if ($found_path) {
        wp_send_json_success( array('file_path' => $found_path));
    } else {
        wp_send_json_error( __('Cache file not found yet. Try again in a moment.', 'fastcgi-cache-purge-and-preload-nginx'));
    }
}

// Recursively traverses directories and extracts necessary data from files.
// We already sanitized and validated the $nginx_cache_path
// so for file_path we don't apply any sanitize and validate
// we only sanitize and validate the urls parsed from files
function nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path) {
    // On a large cache (100 k+ files)
    // on slow or network-attached storage this can easily exceed the default
    // 30-second ceiling that most PHP-FPM pools ship with, killing the process
    // mid-operation.

    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    $urls = [];

    // Determine if HTTPS is enabled
    $https_enabled = wp_is_using_https();

    // Retrieve and decode user-defined cache key regex from the database, with a hardcoded fallback
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // User defined regex in Cache Key Regex option
    $regex = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    // Read only the head (binary-safe)
    $head_bytes_primary  = (int) apply_filters('nppp_locate_head_bytes', 4096);
    $head_bytes_fallback = (int) apply_filters('nppp_locate_head_bytes_fallback', 32768);

    try {
        // Traverse the cache directory and its subdirectories
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $regex_tested = false;
        foreach ($cache_iterator as $file) {
            $path = $file->getPathname();

            $content = nppp_read_head($wp_filesystem, $path, $head_bytes_primary);
            if ($content === '') { continue; }

            $match = [];
            if (!preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                if (strlen($content) >= $head_bytes_primary) {
                    $content = nppp_read_head($wp_filesystem, $path, $head_bytes_fallback);
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

            // Accept only GET entries (HEAD/POST/etc. are not cache targets here)
            $key_line = $match[1];
            if (strpos($key_line, 'POST') !== false ||
                strpos($key_line, 'HEAD') !== false ||
                strpos($key_line, 'PUT') !== false ||
                strpos($key_line, 'DELETE') !== false ||
                strpos($key_line, 'PATCH') !== false ||
                strpos($key_line, 'OPTIONS') !== false) {
                continue;
            }

            // Test regex only once
            // Regex operations can be computationally expensive,
            // especially when iterating over multiple files.
            // So here we test regex only once
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
                                /* Translators: %1$s and %2$s are dynamic strings, $host$request_uri is string */
                                __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is parsing the string <strong>\$host\$request_uri</strong> correctly.', 'fastcgi-cache-purge-and-preload-nginx'),
                                __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'),
                                __( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx')
                            )
                        ];
                    }
                } else {
                    return [
                        'error' => sprintf(
                            /* Translators: %1$s and %2$s are dynamic strings */
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

                    // Get the file modification time for cache date
                    $cache_timestamp = $file->getMTime();
                    $cache_date = wp_date('Y-m-d H:i:s', $cache_timestamp);

                    // Categorize URLs
                    $category = nppp_categorize_url($final_url);

                    // Store URL data
                    $urls[] = array(
                        'file_path'   => $file->getPathname(),
                        'url'         => $final_url,
                        'url_encoded' => $final_url_encoded,
                        'category'    => $category,
                        'cache_date'  => $cache_date
                    );
                }
            }
        }
    } catch (Exception $e) {
        // Handle exceptions and return an error message
        return [
            'error' => __( 'An error occurred while accessing the Nginx cache directory. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        ];
    }

    // Check if any URLs were extracted
    if (empty($urls)) {
        return [
            'error' => 'NPPP_EMPTY_CACHE',
        ];
    }

    return $urls;
}

/**
 * Categorize a URL into a human-readable content-type label.
 *
 * @param  string $url  Absolute URL to categorize.
 * @return string       Uppercase label (e.g. 'POST', 'PRODUCT', 'CATEGORY').
 */
function nppp_categorize_url( $url ) {
    // 0. Static per-request cache
    static $url_cache    = [];
    static $rewrite_rules = null;
    static $bulk_map     = null;
    static $needs_update  = false;

    if ( isset( $url_cache[ $url ] ) ) {
        return $url_cache[ $url ];
    }

    // 1. Bulk transient
    if ( $bulk_map === null ) {
        $bulk_map = get_transient( 'nppp_category_map' );
        $bulk_map = is_array( $bulk_map ) ? $bulk_map : [];

        add_action('shutdown', function() use (&$bulk_map, &$needs_update) {
            if ( $needs_update ) {
                set_transient('nppp_category_map', $bulk_map, WEEK_IN_SECONDS);
            }
        });
    }

    $map_key = md5( $url );

    if ( isset( $bulk_map[ $map_key ] ) ) {
        $url_cache[ $url ] = $bulk_map[ $map_key ];
        return $bulk_map[ $map_key ];
    }

    // Shared finalize helper
    $finish = function ( $label ) use ( $url, $map_key, &$url_cache, &$bulk_map, &$needs_update ) {
        $label = (string) apply_filters( 'nppp_categorize_url_result', $label, $url );
        $url_cache[ $url ]   = $label;
        $bulk_map[ $map_key ] = $label;
        $needs_update = true;
        return $label;
    };

    // 2. Host guard
    $site_host = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
    $url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );
    if ( $url_host === '' || strcasecmp( $url_host, $site_host ) !== 0 ) {
        return $finish( 'EXTERNAL' );
    }

    // 3. Normalize path
    $raw_path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '/' );
    $path     = trim( rawurldecode( $raw_path ), '/' );
    $path     = (string) preg_replace( '#(?:^|/)page/\d+(?:/.*)?$#', '', $path );
    $path     = trim( $path, '/' );

    // 4. Homepage early-exit
    if ( $path === '' ) {
        return $finish( 'HOMEPAGE' );
    }

    // 5. Rewrite rules → query vars
    global $wp_rewrite;
    if ( $rewrite_rules === null ) {
        $rewrite_rules = (array) $wp_rewrite->wp_rewrite_rules();
    }

    $query_vars = [];
    foreach ( $rewrite_rules as $match => $query ) {
        if ( preg_match( "#^{$match}#", $path, $matches )
             || preg_match( "#^{$match}#", rawurlencode( $path ), $matches ) ) {
            $query = preg_replace( '!^.+\?!', '', $query );
            if ( class_exists( 'WP_MatchesMapRegex' ) ) {
                $query = addslashes( WP_MatchesMapRegex::apply( $query, $matches ) );
            }
            parse_str( $query, $query_vars );
            break;
        }
    }

    if ( empty( $query_vars ) ) {
        $qs = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?? '' );
        if ( $qs !== '' ) {
            parse_str( $qs, $query_vars );
        }
    }

    if ( empty( $query_vars ) ) {
        return $finish( 'UNKNOWN' );
    }

    // 5b. WooCommerce shortcut
    if ( function_exists( 'wc_get_page_id' ) ) {
        if ( ! empty( $query_vars['product'] ) )     { return $finish( 'PRODUCT' ); }
        if ( ! empty( $query_vars['product_cat'] ) ) { return $finish( 'PRODUCT CATEGORY' ); }
        if ( ! empty( $query_vars['product_tag'] ) ) { return $finish( 'PRODUCT TAG' ); }

        if ( isset( $query_vars['post_type'] ) ) {
            $pt = (array) $query_vars['post_type'];
            if ( in_array( 'product', $pt, true ) ) {
                return $finish(
                    ! empty( $query_vars['name'] ) || ! empty( $query_vars['p'] )
                        ? 'PRODUCT'
                        : 'SHOP'
                );
            }
        }

        foreach ( array_keys( $query_vars ) as $var ) {
            if ( strpos( $var, 'pa_' ) === 0 && ! empty( $query_vars[ $var ] ) ) {
                return $finish( 'PRODUCT ATTR: ' . strtoupper( substr( $var, 3 ) ) );
            }
        }
    }

    // 6. WP_Query::parse_query()
    $q = new WP_Query();
    $q->parse_query( $query_vars );

    // 7. is_* → label
    if ( function_exists( 'wc_get_page_id' ) ) {
        if ( $q->is_post_type_archive ) {
            $pt = (array) $q->get( 'post_type' );
            if ( in_array( 'product', $pt, true ) ) { return $finish( 'SHOP' ); }
        }
        if ( $q->is_tax ) {
            $tax_name = (string) $q->get( 'taxonomy' );
            if ( $tax_name === 'product_cat' )         { return $finish( 'PRODUCT CATEGORY' ); }
            if ( $tax_name === 'product_tag' )         { return $finish( 'PRODUCT TAG' ); }
            if ( strpos( $tax_name, 'pa_' ) === 0 )   { return $finish( 'PRODUCT ATTR: ' . strtoupper( substr( $tax_name, 3 ) ) ); }
        }
    }

    if ( $q->is_singular || $q->is_single ) {
        $pt = (string) ( $q->get( 'post_type' ) ?: 'post' );
        if ( function_exists( 'wc_get_page_id' ) && $pt === 'product' ) { return $finish( 'PRODUCT' ); }
        if ( $pt === 'post' ) { return $finish( 'POST' ); }
        if ( $pt === 'page' ) { return $finish( 'PAGE' ); }
        $pt_obj = get_post_type_object( $pt );
        return $finish( $pt_obj ? strtoupper( $pt_obj->labels->singular_name ) : strtoupper( $pt ) );
    }

    if ( $q->is_page )     { return $finish( 'PAGE' ); }
    if ( $q->is_category ) { return $finish( 'CATEGORY' ); }
    if ( $q->is_tag )      { return $finish( 'TAG' ); }

    if ( $q->is_tax ) {
        $tax_name = (string) $q->get( 'taxonomy' );
        $tax_obj  = $tax_name ? get_taxonomy( $tax_name ) : null;
        return $finish( $tax_obj ? strtoupper( $tax_obj->labels->singular_name ) : 'TAXONOMY' );
    }

    if ( $q->is_author ) { return $finish( 'AUTHOR' ); }
    if ( $q->is_date )   { return $finish( 'DATE_ARCHIVE' ); }
    if ( $q->is_search ) { return $finish( 'SEARCH_RESULTS' ); }
    if ( $q->is_home )   { return $finish( 'BLOG' ); }

    if ( $q->is_post_type_archive ) {
        $pt     = (string) $q->get( 'post_type' );
        $pt_obj = $pt ? get_post_type_object( $pt ) : null;
        return $finish( $pt_obj ? strtoupper( $pt_obj->labels->name ) : 'ARCHIVE' );
    }

    return $finish( 'UNKNOWN' );
}
