<?php
/**
 * Preload progress REST endpoint for Nginx Cache Purge Preload
 * Description: Exposes authenticated progress data for running preload jobs.
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

// Protect preload progress endpoint.
function nppp_nginx_cache_progress_permission_check() {
    return current_user_can('manage_options');
}

// Register the REST API endpoint for preload progress tracking.
function nppp_nginx_cache_register_preload_progress_endpoint() {
    register_rest_route('nppp_nginx_cache/v2', '/preload-progress', array(
        'methods' => 'GET',
        'callback' => 'nppp_nginx_cache_preload_progress',
        'permission_callback' => 'nppp_nginx_cache_progress_permission_check',
    ));
}

// Tracks and returns Nginx cache preload progress.
function nppp_nginx_cache_preload_progress($request) {
    $plugin_path = dirname(plugin_dir_path(__FILE__));
    $log_path = nppp_get_runtime_file('nppp-wget.log');
    $pid_path = nppp_get_runtime_file('cache_preload.pid');

    $checked = 0;
    $errors = 0;
    $last_url = '';
    $broken_urls = array();
    $pending_error_url = '';
    $prev_line_trimmed = '';
    $time_info = '';
    $last_preload_time = '';
    $is_running = false;
    $log_found = false;
    $log_complete = false;
    $snapshot_exists = false;
    $snapshot_time = '';

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false ) {
        return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Filesystem init failed' ], 500 );
    }

    // Check if preload process is alive
    if ( $wp_filesystem->exists( $pid_path ) ) {
        $pid = intval( trim( $wp_filesystem->get_contents( $pid_path ) ) );
        if ( $pid > 0 && nppp_is_process_alive( $pid ) ) {
            $is_running = true;
        }
    }

    // Parse log file
    if ( $wp_filesystem->exists( $log_path ) ) {
        $log_found = true;
        $raw_log = $wp_filesystem->get_contents( $log_path );
        $lines = $raw_log !== false && $raw_log !== ''
            ? array_filter( array_map( 'trim', explode( "\n", $raw_log ) ) )
            : [];
        if ($lines) {
            foreach ($lines as $line) {
                $line_trimmed = trim($line);
                if ($line_trimmed === '') {
                    continue;
                }

                if (preg_match('/URL:(https?:\/\/[^\s]+).*?->/', $line, $match)) {
                    $checked++;
                    $last_url = $match[1];
                    $pending_error_url = '';
                }

                // wget writes failed URLs on their own line
                if (preg_match('/^(https?:\/\/\S+):\s*$/', $line_trimmed, $failed_match)) {
                    $pending_error_url = rtrim($failed_match[1], ':');
                }

                if (stripos($line, 'ERROR 404') !== false) {
                    if (empty($pending_error_url) && preg_match('/^(https?:\/\/\S+):\s*$/', $prev_line_trimmed, $prev_failed_match)) {
                        $pending_error_url = rtrim($prev_failed_match[1], ':');
                    }

                    $errors++;

                    if (!empty($pending_error_url)) {
                        $broken_urls[] = $pending_error_url;
                        $pending_error_url = '';
                    } elseif (preg_match('/URL:(https?:\/\/[^\s]+).*?->/i', $line, $match_404)) {
                        $broken_urls[] = $match_404[1];
                    }
                }

                if (stripos($line, 'Total wall clock time:') !== false) {
                    if (preg_match('/Total wall clock time:\s*((?:[0-9]+h\s*)?(?:[0-9]+m\s*)?[0-9]+(?:\.[0-9]+)?s)/i', $line, $match)) {
                        $time_info = trim($match[1]);
                    }
                }

                if (preg_match('/^FINISHED\s+--([\d\-]+\s+[\d:]+)--$/', $line, $match)) {
                    $last_preload_time = trim($match[1]);
                }

                $prev_line_trimmed = $line_trimmed;
            }
        }
    }

    // Check if live log has a FINISHED marker (complete run)
    if ( $log_found && isset( $raw_log ) && $raw_log !== false ) {
        $log_complete = nppp_wget_log_is_complete( $raw_log );
    }

    // Check snapshot file — skip during active run, it only changes on completion
    $snapshot_path = nppp_get_runtime_file( 'nppp-wget-snapshot.log' );
    if ( ! $is_running && $wp_filesystem->exists( $snapshot_path ) ) {
        $snapshot_exists = true;
        $snap_contents = $wp_filesystem->get_contents( $snapshot_path );
        if ( $snap_contents !== false && $snap_contents !== '' ) {
            if ( preg_match( '/^FINISHED\s+--([\d\-]+\s+[\d:]+)--$/m', $snap_contents, $snap_match ) ) {
                $snapshot_time = trim( $snap_match[1] );
            }
        }
    }

    // Get URL count
    $est_total = nppp_get_estimated_url_count( $wp_filesystem, $snapshot_path );

    // Server health — all reads from /proc virtual FS, near-zero cost
    $load       = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : [ 0, 0, 0 ];
    $load_1     = round( $load[0], 2 );
    $load_5     = round( $load[1], 2 );

    // Real server CPU count from /proc/cpuinfo
    $cpu_count = 1;
    if ( is_readable( '/proc/cpuinfo' ) ) {
        $cpuinfo = @file_get_contents( '/proc/cpuinfo' );
        if ( $cpuinfo !== false ) {
            preg_match_all( '/^processor/m', $cpuinfo, $cpu_matches );
            $cpu_count = max( 1, count( $cpu_matches[0] ) );
        }
    }

    // System RAM from /proc/meminfo — MemAvailable
    $mem_total_mb = 0;
    $mem_avail_mb = 0;
    $swap_total_mb = 0;
    $swap_free_mb  = 0;
    if ( is_readable( '/proc/meminfo' ) ) {
        $meminfo = @file_get_contents( '/proc/meminfo' );
        if ( $meminfo !== false ) {
            if ( preg_match( '/MemTotal:\s+(\d+)\s+kB/i',     $meminfo, $m ) ) $mem_total_mb  = round( $m[1] / 1024, 0 );
            if ( preg_match( '/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $m ) ) $mem_avail_mb  = round( $m[1] / 1024, 0 );
            if ( preg_match( '/SwapTotal:\s+(\d+)\s+kB/i',    $meminfo, $m ) ) $swap_total_mb = round( $m[1] / 1024, 0 );
            if ( preg_match( '/SwapFree:\s+(\d+)\s+kB/i',     $meminfo, $m ) ) $swap_free_mb  = round( $m[1] / 1024, 0 );
        }
    }

    // PHP-FPM pool status
    // Runs from inside the FPM process itself, no HTTP request, no socket, zero overhead
    $fpm_active          = null;
    $fpm_idle            = null;
    $fpm_listen_queue    = null;
    $fpm_max_children    = null;
    $fpm_slow_requests   = null;
    if ( function_exists( 'fpm_get_status' ) ) {
        $fpm = fpm_get_status();
        if ( is_array( $fpm ) ) {
            $fpm_active        = $fpm['active-processes']       ?? null;
            $fpm_idle          = $fpm['idle-processes']         ?? null;
            $fpm_listen_queue  = $fpm['listen-queue']           ?? null;
            $fpm_max_children  = $fpm['max-children-reached']   ?? null;
            $fpm_slow_requests = $fpm['slow-requests']          ?? null;
        }
    }

    // Expose the current preload phase so the JS poller knows not to stop
    // during the desktop→mobile transition gap (PIDFILE briefly absent).
    $phase_transient_key = 'nppp_preload_phase_' . md5( 'nppp' );
    $preload_phase       = get_transient( $phase_transient_key );
    $preload_phase       = ( is_string( $preload_phase ) && $preload_phase !== '' ) ? $preload_phase : 'desktop';

    return new WP_REST_Response([
        'load_1'            => $load_1,
        'load_5'            => $load_5,
        'cpu_count'         => $cpu_count,
        'mem_total_mb'      => $mem_total_mb,
        'mem_avail_mb'      => $mem_avail_mb,
        'swap_total_mb'     => $swap_total_mb,
        'swap_used_mb'      => $swap_total_mb - $swap_free_mb,
        'fpm_active'        => $fpm_active,
        'fpm_idle'          => $fpm_idle,
        'fpm_listen_queue'  => $fpm_listen_queue,
        'fpm_max_children'  => $fpm_max_children,
        'fpm_slow_requests' => $fpm_slow_requests,
        'status'            => $is_running ? 'running' : 'done',
        'checked'           => $checked,
        'errors'            => $errors,
        'broken_urls'       => array_values(array_slice(array_unique($broken_urls), -20)),
        'last_url'          => $last_url,
        'total'             => $est_total,
        'time'              => $time_info,
        'last_preload_time' => $last_preload_time,
        'log_found'         => $log_found,
        'log_complete'      => $log_complete,
        'snapshot_exists'   => $snapshot_exists,
        'snapshot_time'     => $snapshot_time,
        'preload_phase'     => $preload_phase,
    ]);
}

// Estimates the total number of cachable URLs for preload progress
function nppp_get_estimated_url_count( $wp_filesystem = null, $snapshot_path = '' ) {
    $static_key_base = 'nppp';
    $transient_key = 'nppp_est_url_counts_' . md5($static_key_base);

    // Return cached value if available
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    // -------------------------------------------------------------------
    // Tier 1: Snapshot exists — use the real count from the last completed
    // preload run.
    // -------------------------------------------------------------------
    if (
        $wp_filesystem instanceof WP_Filesystem_Base &&
        ! empty( $snapshot_path ) &&
        $wp_filesystem->exists( $snapshot_path )
    ) {
        $contents = $wp_filesystem->get_contents( $snapshot_path );
        if ( ! empty( $contents ) ) {
            if ( preg_match( '/^Downloaded:\s+(\d+)\s+files/m', $contents, $m ) ) {
                $real_count = (int) $m[1];
                if ( $real_count > 0 ) {
                    set_transient( $transient_key, $real_count, DAY_IN_SECONDS );
                    return $real_count;
                }
            }
        }
    }

    // -------------------------------------------------------------------
    // Tier 2: No snapshot yet (first ever run). Count published content
    // directly from the WordPress DB.
    // -------------------------------------------------------------------
    $total          = 0;
    $posts_per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );

    // Exclude specific post types via filter
    $exclude_types = apply_filters( 'nppp_exclude_post_types', [ 'attachment' ] );

    $post_types = get_post_types( [ 'public' => true ], 'objects' );
    foreach ( $post_types as $type => $pt_object ) {
        if ( in_array( $type, $exclude_types, true ) ) {
            continue;
        }

        $counts    = wp_count_posts( $type );
        $published = intval( $counts->publish ?? 0 );
        $total    += $published;

        // Count archive page + its paginated pages if this post type has one
        if ( ! empty( $pt_object->has_archive ) && $published > 0 ) {
            $total += 1 + (int) floor( $published / $posts_per_page );
        }
    }

    // Taxonomy archive pages
    foreach ( get_taxonomies( [ 'public' => true ] ) as $tax ) {
        $term_count = wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] );
        if ( is_wp_error( $term_count ) ) continue;
        $total += max( 0, (int) $term_count );
    }

    // Homepage + static front page + author archives + misc buffer
    $total += 5;

    if ( $total > 0 ) {
        set_transient( $transient_key, $total, DAY_IN_SECONDS );
        return $total;
    }

    // -------------------------------------------------------------------
    // Tier 3: Final fallback
    // -------------------------------------------------------------------
    set_transient( $transient_key, 2000, DAY_IN_SECONDS );
    return 2000;
}
