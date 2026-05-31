<?php
/**
 * WP-CLI commands for Nginx Cache Purge Preload
 * Description: Exposes cache purge, preload, status, log, settings, and scheduler to WP-CLI.
 * Version: 2.1.6
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

declare( strict_types=1 );

// Guard against direct web access to this file
if ( ! defined( 'ABSPATH' ) ) {
    return;
}

// Guard against non-CLI environments
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Manages Nginx Cache — purge, preload, status, log, settings, and scheduler.
 *
 * ## EXAMPLES
 *
 *     # Purge entire cache
 *     wp npp purge
 *
 *     # Purge a single URL
 *     wp npp purge --page-url=https://example.com/blog/my-post/
 *
 *     # Dry-run a full purge
 *     wp npp purge --dry-run
 *
 *     # Start full-site preloading
 *     wp npp preload
 *
 *     # Preload a single URL
 *     wp npp preload --page-url=https://example.com/blog/my-post/
 *
 *     # Kill a running preload (cache is NOT purged)
 *     wp npp preload --stop
 *
 *     # Print runtime status as JSON
 *     wp npp status --format=json
 *
 *     # Tail the last 100 log lines
 *     wp npp log --lines=100
 *
 *     # Truncate the log
 *     wp npp log --clear
 *
 *     # List all writable settings
 *     wp npp settings get
 *
 *     # Read a single setting
 *     wp npp settings get nginx_cache_path
 *
 *     # Toggle auto-purge on post save
 *     wp npp settings set nginx_cache_purge_on_update yes
 *
 *     # Clear all plugin transients
 *     wp npp flush
 *
 *     # Clear the URL→filepath index (stale after cache dir move or key change)
 *     wp npp index-clear
 * 
 *     # List active preload schedule events
 *     wp npp schedule
 *
 * @package NPPP
 */
class NPPP_CLI_Command extends WP_CLI_Command {

    // =========================================================================
    // Public subcommands
    // =========================================================================

    /**
     * Purges the entire Nginx cache, or a single URL.
     *
     * ## OPTIONS
     *
     * [--page-url=<url>]
     * : Purge a single cached page instead of the entire cache.
     *
     * [--dry-run]
     * : Show what would be purged without executing the operation.
     *
     * [--porcelain]
     * : Emit only the outcome token (success|warning|error) for shell scripting.
     *
     * ## EXAMPLES
     *
     *     wp npp purge
     *     wp npp purge --page-url=https://example.com/blog/my-post/
     *     wp npp purge --dry-run
     *     wp npp purge --porcelain && echo "cache clear confirmed"
     *
     * @when after_wp_load
     */
    public function purge( array $args, array $assoc_args ): void {
        $url       = (string) ( $assoc_args['page-url'] ?? '' );
        $dry_run   = array_key_exists( 'dry-run',   $assoc_args );
        $porcelain = array_key_exists( 'porcelain', $assoc_args );

        $settings   = get_option( 'nginx_cache_settings', [] );
        $cache_path = (string) ( $settings['nginx_cache_path'] ?? '/dev/shm/change-me-now' );
        $pid_file   = nppp_get_runtime_file( 'cache_preload.pid' );
        $tmp_path   = rtrim( $cache_path, '/' ) . '/tmp';

        if ( $dry_run ) {
            WP_CLI::line( $url !== ''
                /* translators: %s: URL being dry-run purged */
                ? sprintf( __( '[dry-run] Would purge single URL: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $url )
                /* translators: %s: Nginx cache path being dry-run purged */
                : sprintf( __( '[dry-run] Would purge entire cache at: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $cache_path )
            );
            return;
        }

        // Validate URL before entering the output buffer — WP_CLI::error() calls exit().
        if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            /* translators: %s: The invalid URL provided */
            WP_CLI::error( sprintf( __( 'Invalid URL: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $url ) );
        }

        $result = $this->capture_cli_output(
            function () use ( $url, $cache_path, $pid_file, $tmp_path ): void {
                if ( $url !== '' ) {
                    nppp_purge_single( $cache_path, $url, false );
                } else {
                    nppp_purge( $cache_path, $pid_file, $tmp_path, false, false, false );
                }
            }
        );

        $this->emit_result( $result, $porcelain );
    }

    /**
     * Starts, stops, or triggers single-URL cache preloading.
     *
     * A full preload spawns a wget crawl in the background and returns
     * immediately — the job continues after the command exits.
     *
     * ## OPTIONS
     *
     * [--page-url=<url>]
     * : Preload only a single URL instead of crawling the entire site.
     *
     * [--stop]
     * : Kill the running preload process without purging the cache.
     *
     * [--dry-run]
     * : Show what would happen without executing the operation.
     *
     * [--porcelain]
     * : Emit only the outcome token (success|warning|error) for shell scripting.
     *
     * ## EXAMPLES
     *
     *     wp npp preload
     *     wp npp preload --page-url=https://example.com/shop/
     *     wp npp preload --stop
     *     wp npp preload --dry-run
     *
     * @when after_wp_load
     */
    public function preload( array $args, array $assoc_args ): void {
        $url       = (string) ( $assoc_args['page-url'] ?? '' );
        $stop      = array_key_exists( 'stop',      $assoc_args );
        $dry_run   = array_key_exists( 'dry-run',   $assoc_args );
        $porcelain = array_key_exists( 'porcelain', $assoc_args );

        $settings     = get_option( 'nginx_cache_settings', [] );
        $cache_path   = (string) ( $settings['nginx_cache_path']        ?? '/dev/shm/change-me-now' );
        $limit_rate   = (int)    ( $settings['nginx_cache_limit_rate']  ?? 5120 );
        $cpu_limit    = (int)    ( $settings['nginx_cache_cpu_limit']   ?? 100 );
        $reject_regex = (string) ( $settings['nginx_cache_reject_regex']
                          ?? nppp_fetch_default_reject_regex() );
        $pid_file     = nppp_get_runtime_file( 'cache_preload.pid' );
        $tmp_path     = rtrim( $cache_path, '/' ) . '/tmp';
        $plugin_dir   = plugin_dir_path( NPPP_PLUGIN_FILE );
        $fdomain      = get_site_url();

        if ( $dry_run ) {
            if ( $stop ) {
                WP_CLI::line( __( '[dry-run] Would stop the active preload process (cache preserved).', 'fastcgi-cache-purge-and-preload-nginx' ) );
            } elseif ( $url !== '' ) {
                /* translators: %s: URL being dry-run preloaded */
                WP_CLI::line( sprintf( __( '[dry-run] Would preload single URL: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $url ) );
            } else {
                /* translators: %s: Site domain being dry-run preloaded */
                WP_CLI::line( sprintf( __( '[dry-run] Would start full-site preload for: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $fdomain ) );
            }
            return;
        }

        if ( $stop ) {
            $this->preload_stop( $pid_file, $porcelain );
            return;
        }

        // Validate before entering the output buffer.
        if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            /* translators: %s: The invalid URL provided */
            WP_CLI::error( sprintf( __( 'Invalid URL: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $url ) );
        }

        if ( $url !== '' ) {
            $result = $this->capture_cli_output(
                function () use ( $url, $pid_file, $tmp_path, $reject_regex, $limit_rate, $cpu_limit, $cache_path ): void {
                    nppp_preload_single( $url, $pid_file, $tmp_path, $reject_regex, $limit_rate, $cpu_limit, $cache_path );
                }
            );
            $this->emit_result( $result, $porcelain );
            return;
        }

        $preload_mobile = ( ( $settings['nginx_cache_auto_preload_mobile'] ?? 'no' ) === 'yes' );

        $result = $this->capture_cli_output(
            function () use ( $cache_path, $plugin_dir, $tmp_path, $fdomain, $pid_file, $reject_regex, $limit_rate, $cpu_limit, $preload_mobile ): void {
                nppp_preload(
                    $cache_path,
                    $plugin_dir,
                    $tmp_path,
                    $fdomain,
                    $pid_file,
                    $reject_regex,
                    $limit_rate,
                    $cpu_limit,
                    false,           // $nppp_is_auto_preload
                    false,           // $nppp_is_rest_api
                    false,           // $nppp_is_wp_cron
                    false,           // $nppp_is_admin_bar
                    $preload_mobile  // honour nginx_cache_auto_preload_mobile setting
                );
            }
        );

        $this->emit_result( $result, $porcelain );
    }

    /**
     * Displays a runtime status summary for the Nginx cache.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp npp status
     *     wp npp status --format=json
     *     wp npp status --format=csv > /tmp/npp-status.csv
     *
     * @when after_wp_load
     */
    public function status( array $args, array $assoc_args ): void {
        $settings   = get_option( 'nginx_cache_settings', [] );
        $cache_path = (string) ( $settings['nginx_cache_path'] ?? '/dev/shm/change-me-now' );

        // ── Action readiness ──────────────────────────────────────────────
        $action_server  = nppp_check_perm_in_cache( false, false, true );
        $action_purge   = nppp_check_perm_in_cache( true,  false, false );
        $action_preload = nppp_check_preload_status();

        // Human-readable label helpers for raw internal return values.
        $bool_label = static function ( ?string $v ): string {
            return match ( $v ) {
                'true'      => __( 'OK', 'fastcgi-cache-purge-and-preload-nginx' ),
                'false'     => __( 'Not Available', 'fastcgi-cache-purge-and-preload-nginx' ),
                'Not Found' => __( 'Cache Path Not Found', 'fastcgi-cache-purge-and-preload-nginx' ),
                default     => $v ?? __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' ),
            };
        };
        $preload_label = static function ( ?string $v ): string {
            return match ( $v ) {
                'progress' => __( 'Running', 'fastcgi-cache-purge-and-preload-nginx' ),
                'true'     => __( 'Ready', 'fastcgi-cache-purge-and-preload-nginx' ),
                'false'    => __( 'Not Available', 'fastcgi-cache-purge-and-preload-nginx' ),
                default    => $v ?? __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' ),
            };
        };

        // ── System checks ─────────────────────────────────────────────────
        $php_owner    = (string) nppp_get_website_user();
        $server_user  = (string) nppp_get_webserver_user();
        $php_lc       = trim( strtolower( $php_owner ) );
        $srv_lc       = trim( strtolower( $server_user ) );

        if ( $php_lc === 'not determined' || $srv_lc === 'not determined' ) {
            $isolation = __( 'Not Determined', 'fastcgi-cache-purge-and-preload-nginx' );
        } else {
            $isolation = ( $php_lc === $srv_lc )
                ? __( 'Not Isolated', 'fastcgi-cache-purge-and-preload-nginx' )
                : __( 'Isolated', 'fastcgi-cache-purge-and-preload-nginx' );
        }

        $shell_exec    = (string) nppp_shell_exec();
        $regex_probe   = (string) nppp_probe_cache_key_regex();
        $cmd_wget      = (string) nppp_check_command_status( 'wget' );
        $cmd_safexec   = (string) nppp_check_command_status( 'safexec' );
        $cmd_rg        = (string) nppp_check_command_status( 'rg' );
        $cmd_cpulimit  = (string) nppp_check_command_status( 'cpulimit' );

        // ── Cache health ──────────────────────────────────────────────────
        $path_status   = nppp_check_path();
        $perm_status   = nppp_check_permissions_recursive_with_cache();

        // Use pre-computed option — avoids slow live directory scan on large caches.
        // Updated automatically at preload completion, purge-all, and advanced tab scan.
        $page_count       = get_option( 'nppp_last_known_hits', false );
        $scanned_at       = get_option( 'nppp_last_hits_scanned_at', false );
        $page_count_label = $page_count !== false
            ? ( (string) $page_count . ( $scanned_at
                /* translators: %s: human-readable time difference, e.g. "5 minutes" */
                ? '  (' . sprintf( __( '%s ago', 'fastcgi-cache-purge-and-preload-nginx' ), human_time_diff( (int) $scanned_at, time() ) ) . ')'
                : '' ) )
            : __( 'N/A — run a Preload to populate', 'fastcgi-cache-purge-and-preload-nginx' );

        $ratio_raw   = nppp_get_cache_ratio( $page_count !== false ? $page_count : 'N/A' );
        $ratio_label = is_array( $ratio_raw )
            ? number_format( $ratio_raw['ratio'], 1 ) . '%'
              /* translators: %d: Number of cached pages */
              . '  (' . sprintf( __( '%d cached', 'fastcgi-cache-purge-and-preload-nginx' ), $ratio_raw['hits'] )
              /* translators: %d: Number of uncached pages */
              . ' / ' . sprintf( __( '%d not cached', 'fastcgi-cache-purge-and-preload-nginx' ), $ratio_raw['misses'] )
              /* translators: %d: Total number of pages */
              . ' / ' . sprintf( __( '%d total', 'fastcgi-cache-purge-and-preload-nginx' ), $ratio_raw['total'] ) . ')'
            : (string) $ratio_raw;

        $disk = nppp_get_cache_disk_size( $cache_path );
        if ( $disk === null ) {
            $disk_label = __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' );
        } elseif ( $disk['dedicated'] ) {
            $pct        = $disk['total'] > 0 ? number_format( ( $disk['used'] / $disk['total'] ) * 100, 1 ) : '0.0';
            $disk_label = $pct . '%  ('
                /* translators: %s: Human-readable disk size used */
                . sprintf( __( '%s used', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_format_cache_size( $disk['used'] ) )
                /* translators: %s: Human-readable total disk size on a dedicated filesystem */
                . ' / ' . sprintf( __( '%s total — dedicated fs', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_format_cache_size( $disk['total'] ) )
                . ')';
        } else {
            /* translators: %s: Human-readable disk size used by cache directory */
            $disk_label = sprintf( __( '%s used in cache dir', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_format_cache_size( $disk['used'] ) )
                /* translators: %s: Human-readable free disk space on partition */
                . '  (' . sprintf( __( '%s free on partition', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_format_cache_size( $disk['free'] ) )
                . ')';
        }

        // ── Nginx config paths ────────────────────────────────────────────
        $nginx_cache_paths_rows = [];
        $fuse_mounts_rows       = [];
        $nginx_conf_paths       = [];

        if ( function_exists( 'nppp_get_nginx_conf_paths' ) && function_exists( 'nppp_initialize_wp_filesystem' ) ) {
            $wp_fs_status = nppp_initialize_wp_filesystem();
            if ( $wp_fs_status !== false ) {
                $nginx_conf_paths = (array) nppp_get_nginx_conf_paths( $wp_fs_status );
            }
        }

        if ( ! empty( $nginx_conf_paths ) && function_exists( 'nppp_parse_nginx_config' ) ) {
            $config_data = nppp_parse_nginx_config( $nginx_conf_paths[0] );
            if ( is_array( $config_data ) && ! empty( $config_data['cache_paths'] ) ) {
                $settings_path = (string) ( $settings['nginx_cache_path'] ?? '' );
                $active_path   = rtrim( $settings_path, '/' );

                // Build FUSE mount data first so we can annotate cache paths.
                $fuse_data = [];
                if ( function_exists( 'nppp_check_fuse_cache_paths' ) ) {
                    $fuse_data = (array) nppp_check_fuse_cache_paths( $config_data['cache_paths'] );
                }
                $fuse_map = (array) ( $fuse_data['fuse_map'] ?? [] );

                foreach ( $config_data['cache_paths'] as $directive => $paths ) {
                    foreach ( (array) $paths as $path ) {
                        $path_n    = rtrim( (string) $path, '/' );
                        $fuse_dest = (string) ( $fuse_map[ $path_n ] ?? '' );
                        $is_active = $active_path !== '' && (
                            $path_n === $active_path || $fuse_dest === $active_path
                        );
                        $label     = $path_n;
                        if ( $is_active ) {
                            $label .= '  ' . __( '[active]', 'fastcgi-cache-purge-and-preload-nginx' );
                        }
                        if ( $fuse_dest !== '' ) {
                            /* translators: %s: FUSE mount destination path */
                            $label .= '  → ' . $fuse_dest . ' ' . __( '(FUSE)', 'fastcgi-cache-purge-and-preload-nginx' );
                        }
                        $nginx_cache_paths_rows[] = [
                            'Field' => $directive,
                            'Value' => $label,
                        ];
                    }
                }

                // FUSE mount rows.
                foreach ( (array) ( $fuse_data['fuse_paths'] ?? [] ) as $mount ) {
                    $fuse_mounts_rows[] = [
                        'Field' => __( 'Mount', 'fastcgi-cache-purge-and-preload-nginx' ),
                        'Value' => (string) $mount,
                    ];
                }
            }
        }

        // ── Binary versions ───────────────────────────────────────────────
        $nginx_info     = nppp_get_nginx_info();
        $ver_nginx      = (string) ( $nginx_info['nginx_version'] ?? __( 'Unknown', 'fastcgi-cache-purge-and-preload-nginx' ) );
        $ver_php        = (string) ( $nginx_info['php_version']   ?? __( 'Unknown', 'fastcgi-cache-purge-and-preload-nginx' ) );
        $ver_wget       = (string) nppp_check_wget_version();
        $ver_safexec    = (string) nppp_check_safexec_version();
        $ver_rg         = (string) nppp_check_rg_version();
        $ver_libfuse    = (string) nppp_check_libfuse_version();
        $ver_bindfs     = (string) nppp_check_bindfs_version();

        // ── Build rows ────────────────────────────────────────────────────
        $sep  = static fn( string $s ): array => [ 'Field' => "── $s ──", 'Value' => '' ];

        $rows = [
            $sep( _x( 'ACTION READINESS', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            [ 'Field' => __( 'Server Side Action', 'fastcgi-cache-purge-and-preload-nginx' ),                                                 'Value' => $bool_label( $action_server ) ],
            [ 'Field' => __( 'Purge Action', 'fastcgi-cache-purge-and-preload-nginx' ),                                                       'Value' => $bool_label( $action_purge ) ],
            [ 'Field' => __( 'Preload Action', 'fastcgi-cache-purge-and-preload-nginx' ),                                                     'Value' => $preload_label( $action_preload ) ],

            $sep( _x( 'SYSTEM CHECKS', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            [ 'Field' => __( 'PHP Process Owner (Website User)', 'fastcgi-cache-purge-and-preload-nginx' ),                                   'Value' => $php_owner ],
            [ 'Field' => __( 'Web Server User (nginx | www-data)', 'fastcgi-cache-purge-and-preload-nginx' ),                                 'Value' => $server_user ],
            [ 'Field' => __( 'Permission Isolation (Optional)', 'fastcgi-cache-purge-and-preload-nginx' ),                                    'Value' => $isolation ],
            [ 'Field' => __( 'Shell Execution (Required)', 'fastcgi-cache-purge-and-preload-nginx' ),                                         'Value' => $shell_exec ],
            [ 'Field' => __( 'Cache Key Regex Test (Required)', 'fastcgi-cache-purge-and-preload-nginx' ),                                    'Value' => $regex_probe ],
            [ 'Field' => __( 'wget (Required)', 'fastcgi-cache-purge-and-preload-nginx' ),                                                    'Value' => $cmd_wget ],
            [ 'Field' => __( 'safexec (Recommended)', 'fastcgi-cache-purge-and-preload-nginx' ),                                              'Value' => $cmd_safexec ],
            [ 'Field' => __( 'rg (Recommended)', 'fastcgi-cache-purge-and-preload-nginx' ),                                                   'Value' => $cmd_rg ],
            [ 'Field' => __( 'cpulimit (Optional)', 'fastcgi-cache-purge-and-preload-nginx' ),                                                'Value' => $cmd_cpulimit ],

            $sep( _x( 'CACHE HEALTH', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            [ 'Field' => __( 'Nginx Cache Path (Required)', 'fastcgi-cache-purge-and-preload-nginx' ),                                        'Value' => $cache_path ],
            [ 'Field' => __( 'Nginx Cache Path Status', 'fastcgi-cache-purge-and-preload-nginx' ),                                            'Value' => ( $path_status !== null ? (string) $path_status : __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' ) ) ],
            [ 'Field' => __( 'Cache Path Permission (Required)', 'fastcgi-cache-purge-and-preload-nginx' ),                                   'Value' => $bool_label( $perm_status ) ],
            [ 'Field' => __( 'Pages In Cache Count', 'fastcgi-cache-purge-and-preload-nginx' ),                                               'Value' => $page_count_label ],
            [ 'Field' => __( 'Cache Coverage', 'fastcgi-cache-purge-and-preload-nginx' ),                                                     'Value' => $ratio_label ],
            [ 'Field' => __( 'Cache RAM/Disk Size', 'fastcgi-cache-purge-and-preload-nginx' ),                                                'Value' => $disk_label ],
        ];

        $rows = array_merge(
            $rows,
            [ $sep( _x( 'NGINX CONFIG', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ) ],
            ! empty( $nginx_cache_paths_rows )
                ? $nginx_cache_paths_rows
                : [ [ 'Field' => __( 'Nginx Cache Paths', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => __( 'Not Found (nginx.conf not parsed)', 'fastcgi-cache-purge-and-preload-nginx' ) ] ],
            [ $sep( _x( 'FUSE MOUNTS', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ) ],
            ! empty( $fuse_mounts_rows )
                ? $fuse_mounts_rows
                : [ [ 'Field' => __( 'FUSE Mounts', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => __( 'Not Mounted', 'fastcgi-cache-purge-and-preload-nginx' ) ] ],
            [

                $sep( _x( 'BINARY VERSIONS', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
                [ 'Field' => 'nginx',                                                                                                         'Value' => $ver_nginx ],
                [ 'Field' => 'php',                                                                                                           'Value' => $ver_php ],
                [ 'Field' => 'wget',                                                                                                          'Value' => $ver_wget ],
                [ 'Field' => 'safexec',                                                                                                       'Value' => $ver_safexec ],
                [ 'Field' => 'rg',                                                                                                            'Value' => $ver_rg ],
                [ 'Field' => 'libfuse',                                                                                                       'Value' => $ver_libfuse ],
                [ 'Field' => 'bindfs',                                                                                                        'Value' => $ver_bindfs ],

                $sep( _x( 'SETTINGS', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
                [ 'Field' => __( 'Auto Purge', 'fastcgi-cache-purge-and-preload-nginx' ),                                                     'Value' => $settings['nginx_cache_purge_on_update']         ?? 'no' ],
                [ 'Field' => __( 'Auto Preload', 'fastcgi-cache-purge-and-preload-nginx' ),                                                   'Value' => $settings['nginx_cache_auto_preload']            ?? 'no' ],
                [ 'Field' => __( 'Preload Mobile', 'fastcgi-cache-purge-and-preload-nginx' ),                                                 'Value' => $settings['nginx_cache_auto_preload_mobile']     ?? 'no' ],
                [ 'Field' => __( 'Preload Watchdog', 'fastcgi-cache-purge-and-preload-nginx' ),                                               'Value' => $settings['nginx_cache_watchdog']                ?? 'no' ],
                [ 'Field' => __( 'REST API', 'fastcgi-cache-purge-and-preload-nginx' ),                                                       'Value' => $settings['nginx_cache_api']                     ?? 'no' ],
                [ 'Field' => __( 'WP Schedule Cache', 'fastcgi-cache-purge-and-preload-nginx' ),                                              'Value' => $settings['nginx_cache_schedule']                ?? 'no' ],
                [ 'Field' => __( 'Send Mail', 'fastcgi-cache-purge-and-preload-nginx' ),                                                      'Value' => $settings['nginx_cache_send_mail']               ?? 'no' ],
                [ 'Field' => __( 'HTTP Purge', 'fastcgi-cache-purge-and-preload-nginx' ),                                                     'Value' => $settings['nppp_http_purge_enabled']             ?? 'no' ],
                [ 'Field' => __( 'RG Purge', 'fastcgi-cache-purge-and-preload-nginx' ),                                                       'Value' => $settings['nppp_rg_purge_enabled']               ?? 'no' ],
                [ 'Field' => __( 'Cloudflare Cache Sync', 'fastcgi-cache-purge-and-preload-nginx' ),                                          'Value' => $settings['nppp_cloudflare_apo_sync']            ?? 'no' ],
                [ 'Field' => __( 'Redis Object Cache Sync', 'fastcgi-cache-purge-and-preload-nginx' ),                                        'Value' => $settings['nppp_redis_cache_sync']               ?? 'no' ],
                [ 'Field' => __( 'Proxy', 'fastcgi-cache-purge-and-preload-nginx' ),                                                          'Value' => $settings['nginx_cache_preload_enable_proxy']    ?? 'no' ],
                [ 'Field' => __( 'Bypass Path Restriction', 'fastcgi-cache-purge-and-preload-nginx' ),                                        'Value' => $settings['nginx_cache_bypass_path_restriction'] ?? 'no' ],
                [ 'Field' => __( 'URL Normalization', 'fastcgi-cache-purge-and-preload-nginx' ),                                              'Value' => $settings['nginx_cache_pctnorm_mode']            ?? 'off' ],

                $sep( _x( 'AUTO-PURGE TRIGGERS', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
                [ 'Field' => __( 'Auto-Purge Posts', 'fastcgi-cache-purge-and-preload-nginx' ),                                               'Value' => $settings['nppp_autopurge_posts']                ?? 'no' ],
                [ 'Field' => __( 'Auto-Purge Terms', 'fastcgi-cache-purge-and-preload-nginx' ),                                               'Value' => $settings['nppp_autopurge_terms']                ?? 'no' ],
                [ 'Field' => __( 'Auto-Purge Plugins', 'fastcgi-cache-purge-and-preload-nginx' ),                                             'Value' => $settings['nppp_autopurge_plugins']              ?? 'no' ],
                [ 'Field' => __( 'Auto-Purge Themes', 'fastcgi-cache-purge-and-preload-nginx' ),                                              'Value' => $settings['nppp_autopurge_themes']               ?? 'no' ],
                [ 'Field' => __( 'Auto-Purge 3rd Party', 'fastcgi-cache-purge-and-preload-nginx' ),                                           'Value' => $settings['nppp_autopurge_3rdparty']             ?? 'no' ],

                $sep( _x( 'RELATED PAGES', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
                [ 'Field' => __( 'Always Purge the Homepage', 'fastcgi-cache-purge-and-preload-nginx' ),                                      'Value' => $settings['nppp_related_include_home']           ?? 'no' ],
                [ 'Field' => __( 'Always Purge the Shop Page (WooCommerce)', 'fastcgi-cache-purge-and-preload-nginx' ),                       'Value' => $settings['nppp_related_apply_manual']           ?? 'no' ],
                [ 'Field' => __( 'Always Purge Archives & Related URLs (WordPress + WooCommerce)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $settings['nppp_related_include_category']       ?? 'no' ],
                [ 'Field' => __( 'Preload Related Pages', 'fastcgi-cache-purge-and-preload-nginx' ),                                          'Value' => $settings['nppp_related_preload_after_manual']   ?? 'no' ],
            ]
        );

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Field', 'Value' ] );
        $formatter->display_items( $rows );
    }

    /**
     * Views or truncates the NPP operation log.
     *
     * ## OPTIONS
     *
     * [--lines=<n>]
     * : Number of most-recent log lines to display. Defaults to 50.
     *
     * [--clear]
     * : Truncate the log file.
     *
     * ## EXAMPLES
     *
     *     wp npp log
     *     wp npp log --lines=200
     *     wp npp log --clear
     *
     * @when after_wp_load
     */
    public function log( array $args, array $assoc_args ): void {
        $log_file = NGINX_CACHE_LOG_FILE;
        $clear    = array_key_exists( 'clear', $assoc_args );
        $lines    = max( 1, (int) ( $assoc_args['lines'] ?? 50 ) );

        if ( $clear ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $cleared = file_put_contents( $log_file, '' );
            if ( $cleared === false ) {
                /* translators: %s: Path to the log file */
                WP_CLI::error( sprintf( __( 'Failed to truncate log file: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $log_file ) );
                return;
            }
            WP_CLI::success( __( 'Log file truncated.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        if ( ! file_exists( $log_file ) || filesize( $log_file ) === 0 ) {
            WP_CLI::warning( __( 'Log file is empty or does not exist.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $all_lines  = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $tail_lines = array_slice( (array) $all_lines, -$lines );

        foreach ( $tail_lines as $line ) {
            WP_CLI::line( (string) $line );
        }
    }

    /**
     * Gets or sets NPP plugin settings.
     *
     * All settings are readable and writable via CLI.
     *
     * ## OPTIONS
     *
     * <action>
     * : Operation to perform.
     * ---
     * options:
     *   - get
     *   - set
     * ---
     *
     * [<key>]
     * : Settings key. For 'get': specific key to read (omit to list all). For 'set': the key to update (requires <value>).
     *
     * [<value>]
     * : For 'set': the new value.
     *
     * [--format=<format>]
     * : Output format for 'get'.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp npp settings get
     *     wp npp settings get nginx_cache_path
     *     wp npp settings get --format=json
     *     wp npp settings set nginx_cache_purge_on_update yes
     *     wp npp settings set nginx_cache_limit_rate 2048
     *     wp npp settings set nginx_cache_path /var/cache/nginx/fastcgi
     *
     * @when after_wp_load
     */
    public function settings( array $args, array $assoc_args ): void {
        $action = (string) ( $args[0] ?? '' );

        if ( $action === 'get' ) {
            $this->settings_get( $args, $assoc_args );
        } elseif ( $action === 'set' ) {
            $this->settings_set( $args );
        } else {
            WP_CLI::error( __( 'Unknown action. Use: get or set.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    /**
     * Clears all NPP plugin transients (stale runtime caches).
     *
     * Transients cache expensive operations: permission checks, binary path
     * lookups, cache-size scans. Use this after manual cache-path changes,
     * permission fixes, or binary installs (rg, wget, safexec).
     *
     * ## EXAMPLES
     *
     *     wp npp flush
     *
     * @when after_wp_load
     */
    public function flush( array $args, array $assoc_args ): void {
        nppp_clear_plugin_cache( true );
        WP_CLI::success( __( 'All NPP transient caches cleared.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    /**
     * Lists active NPP cron/schedule events.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp npp schedule
     *     wp npp schedule --format=json
     *
     * @when after_wp_load
     */
    public function schedule( array $args, array $assoc_args ): void {
        $events   = _get_cron_array();
        $hooks    = [ 'npp_cache_preload_event', 'nppp_index_updater_event' ];
        $timezone = wp_timezone_string();
        $found    = [];

        if ( ! empty( $events ) ) {
            foreach ( $events as $timestamp => $crons ) {
                foreach ( $hooks as $hook ) {
                    if ( ! isset( $crons[ $hook ] ) ) {
                        continue;
                    }
                    foreach ( $crons[ $hook ] as $data ) {
                        $next_run = ( new DateTime( '@' . $timestamp ) )
                            ->setTimezone( new DateTimeZone( $timezone ) )
                            ->format( 'Y-m-d H:i:s' );
                        $found[] = [
                            'Hook'     => $hook,
                            'Next Run' => $next_run,
                            'Interval' => (string) ( $data['interval'] ?? __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' ) ),
                            'Args'     => wp_json_encode( $data['args'] ?? [] ),
                        ];
                    }
                }
            }
        }

        if ( empty( $found ) ) {
            WP_CLI::warning( __( 'No active NPP schedule events found.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Hook', 'Next Run', 'Interval', 'Args' ] );
        $formatter->display_items( $found );
    }

    /**
     * Configures or cancels the scheduled preload event.
     *
     * ## OPTIONS
     *
     * [--freq=<frequency>]
     * : Recurrence frequency.
     * ---
     * options:
     *   - daily
     *   - weekly
     *   - monthly
     * ---
     *
     * [--time=<HH:MM>]
     * : Time of day in 24-hour format, e.g. 02:30.
     *
     * [--cancel]
     * : Cancel all active NPP scheduled events.
     *
     * ## EXAMPLES
     *
     *     wp npp schedule set --freq=daily --time=03:00
     *     wp npp schedule set --freq=weekly --time=00:30
     *     wp npp schedule set --cancel
     *
     * @subcommand schedule-set
     * @when after_wp_load
     */
    public function schedule_set( array $args, array $assoc_args ): void {
        $cancel = array_key_exists( 'cancel', $assoc_args );

        if ( $cancel ) {
            if ( function_exists( 'nppp_cancel_scheduled_events' ) ) {
                nppp_cancel_scheduled_events();
            } else {
                // Fallback: clear via WP cron API directly.
                wp_clear_scheduled_hook( 'npp_cache_preload_event' );
                wp_clear_scheduled_hook( 'nppp_index_updater_event' );
            }
            WP_CLI::success( __( 'All NPP scheduled events cancelled.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $freq = (string) ( $assoc_args['freq'] ?? '' );
        $time = (string) ( $assoc_args['time'] ?? '' );

        if ( $freq === '' || $time === '' ) {
            WP_CLI::error( __( 'Usage: wp npp schedule-set --freq=daily|weekly|monthly --time=HH:MM', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        if ( ! in_array( $freq, [ 'daily', 'weekly', 'monthly' ], true ) ) {
            WP_CLI::error( __( 'Invalid frequency. Use: daily, weekly, or monthly.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
            WP_CLI::error( __( 'Invalid time format. Use HH:MM in 24-hour format, e.g. 02:30.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        $cron_expression = $freq . '|' . $time;
        update_option( 'nginx_cache_schedule_value', $cron_expression );

        if ( function_exists( 'nppp_create_scheduled_events' ) ) {
            nppp_create_scheduled_events( $cron_expression );
        }

        /* translators: 1: Frequency e.g. "daily" 2: Time e.g. "03:00" */
        WP_CLI::success( sprintf(
            /* translators: 1: Frequency e.g. "daily" 2: Time e.g. "03:00" */
            __( 'Schedule set: %1$s at %2$s.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $freq, $time
        ) );
    }

    /**
     * Resets a setting to its plugin default value.
     *
     * Supported keys: nginx_cache_reject_regex, nginx_cache_reject_extension,
     * nginx_cache_key_custom_regex, nginx_cache_mobile_user_agent.
     *
     * ## OPTIONS
     *
     * <key>
     * : Setting key to reset.
     *
     * ## EXAMPLES
     *
     *     wp npp settings-reset nginx_cache_reject_regex
     *     wp npp settings-reset nginx_cache_reject_extension
     *
     * @subcommand settings-reset
     * @when after_wp_load
     */
    public function settings_reset( array $args, array $assoc_args ): void {
        $key = (string) ( $args[0] ?? '' );

        $fetchers = [
            'nginx_cache_reject_regex'     => 'nppp_fetch_default_reject_regex',
            'nginx_cache_reject_extension' => 'nppp_fetch_default_reject_extension',
            'nginx_cache_key_custom_regex' => 'nppp_fetch_default_cache_key_regex',
            'nginx_cache_mobile_user_agent' => 'nppp_fetch_default_mobile_user_agent',
        ];

        if ( ! array_key_exists( $key, $fetchers ) ) {
            WP_CLI::error( sprintf(
                /* translators: %s: comma-separated list of resettable keys */
                __( 'Unknown or non-resettable key. Resettable keys: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                implode( ', ', array_keys( $fetchers ) )
            ) );
        }

        $fn = $fetchers[ $key ];
        if ( ! function_exists( $fn ) ) {
            WP_CLI::error( sprintf(
                /* translators: %s: settings key name */
                __( 'Default-value function not found for "%s". Cannot reset.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $key
            ) );
        }

        $default = $fn();

        // nginx_cache_key_custom_regex is base64-encoded in the DB.
        $stored = ( $key === 'nginx_cache_key_custom_regex' ) ? base64_encode( (string) $default ) : $default;

        $settings = get_option( 'nginx_cache_settings', [] );
        $settings[ $key ] = $stored;
        update_option( 'nginx_cache_settings', $settings );

        /* translators: %s: settings key name */
        WP_CLI::success( sprintf( __( '"%s" reset to plugin default.', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
    }

    /**
     * Clears the persistent URL→filepath index.
     *
     * The index maps cached URLs to their filesystem paths so that single-page
     * purges can skip a full directory scan. Clear it when the index may hold
     * stale entries — for example after moving the cache directory, changing
     * the cache key, or when single-page purges are not working correctly.
     *
     * The index rebuilds automatically on the next purge or preload operation,
     * and the background cron job (every 3 hours) also refreshes it.
     *
     * This is a separate operation from `wp npp flush`, which clears plugin
     * transients. The URL index is persistent data, not a transient.
     *
     * ## EXAMPLES
     *
     *     wp npp index-clear
     *
     * @subcommand index-clear
     * @when after_wp_load
     */
    public function index_clear( array $args, array $assoc_args ): void {
        $existed = (bool) get_option( 'nppp_url_filepath_index' );
        delete_option( 'nppp_url_filepath_index' );

        if ( $existed ) {
            WP_CLI::success( __( 'URL index cleared. It will rebuild automatically on the next purge or preload.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        } else {
            WP_CLI::warning( __( 'URL index was already empty — nothing to clear.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Executes $fn inside an output buffer and returns the captured message
     * with its severity type.
     *
     * Uses the same ob-level guard as the REST API (nppp_cli_ob_level →
     * checked in log.php) so that nppp_display_admin_notice() writes into
     * our buffer — and only our buffer — regardless of any pre-existing
     * buffering stack.
     *
     * @return array{message: string, type: string}
     */
    private function capture_cli_output( callable $fn ): array {
        $GLOBALS['nppp_last_notice_type'] = 'success';
        // Signal log.php's WP-CLI channel to emit into the buffer we open next.
        $GLOBALS['nppp_cli_ob_level'] = ob_get_level();

        ob_start();

        try {
            $fn();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            unset( $GLOBALS['nppp_cli_ob_level'], $GLOBALS['nppp_last_notice_type'] );
            WP_CLI::error( $e->getMessage() );
            return [ 'message' => $e->getMessage(), 'type' => 'error' ];
        }

        $raw  = (string) ob_get_clean();
        $type = (string) ( $GLOBALS['nppp_last_notice_type'] ?? 'success' );

        unset( $GLOBALS['nppp_cli_ob_level'], $GLOBALS['nppp_last_notice_type'] );

        return [
            'message' => trim( wp_strip_all_tags( $raw ) ),
            'type'    => $type,
        ];
    }

    /**
     * Routes a captured result to the appropriate WP-CLI output channel.
     *
     * Multi-line messages (stacked admin notices) are split and emitted one by
     * one. WP_CLI::error() is avoided inside the loop because it calls exit();
     * WP_CLI::halt(1) is used after the loop to set the correct shell exit code.
     *
     * @param array{message: string, type: string} $result
     */
    private function emit_result( array $result, bool $porcelain ): void {
        [ 'message' => $message, 'type' => $type ] = $result;

        if ( $porcelain ) {
            WP_CLI::line( $type );
            if ( $type === 'error' ) {
                WP_CLI::halt( 1 );
            }
            return;
        }

        if ( $message === '' ) {
            if ( $type === 'error' ) {
                WP_CLI::error( __( 'Operation failed — no status message captured. Check: wp npp log', 'fastcgi-cache-purge-and-preload-nginx' ) );
            } elseif ( $type === 'warning' ) {
                WP_CLI::warning( __( 'Operation completed with warnings — no message captured.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            } else {
                WP_CLI::success( __( 'Done.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            return;
        }

        $lines = array_values( array_filter(
            array_map( 'trim', explode( "\n", $message ) ),
            static fn( string $l ): bool => $l !== ''
        ) );

        foreach ( $lines as $line ) {
            match ( $type ) {
                'error'   => WP_CLI::log( WP_CLI::colorize( '%rError:%n ' . $line ) ),
                'warning' => WP_CLI::warning( $line ),
                default   => WP_CLI::success( $line ),
            };
        }

        // Signal shell-level failure for error outcomes without mid-loop exit.
        if ( $type === 'error' ) {
            WP_CLI::halt( 1 );
        }
    }

    /**
     * Kills a running preload process without touching the cache contents.
     * Reads PIDFILE → verifies liveness → kills watchdog → SIGTERM main process.
     */
    private function preload_stop( string $pid_file, bool $porcelain ): void {
        $wp_filesystem = nppp_initialize_wp_filesystem();

        if ( $wp_filesystem === false ) {
            WP_CLI::error( __( 'Failed to initialize WP_Filesystem.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        if ( ! $wp_filesystem->exists( $pid_file ) ) {
            $porcelain
                ? WP_CLI::line( 'warning' )
                : WP_CLI::warning( __( 'No active preload process found (PID file absent).', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $pid = (int) trim( (string) nppp_perform_file_operation( $pid_file, 'read' ) );

        if ( $pid <= 0 ) {
            $wp_filesystem->delete( $pid_file );
            $porcelain
                ? WP_CLI::line( 'warning' )
                : WP_CLI::warning( __( 'Invalid PID in lock file. Stale lock removed.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        if ( ! nppp_is_process_alive( $pid ) ) {
            $wp_filesystem->delete( $pid_file );
            $porcelain
                ? WP_CLI::line( 'warning' )
                /* translators: %d: Process ID that is no longer alive */
                : WP_CLI::warning( sprintf( __( 'PID %d is no longer alive. Stale lock removed.', 'fastcgi-cache-purge-and-preload-nginx' ), $pid ) );
            return;
        }

        // Terminate watchdog monitor before the main preload process.
        // Capture any admin notices emitted by the watchdog killer.
        $GLOBALS['nppp_last_notice_type'] = 'success';
        $GLOBALS['nppp_cli_ob_level']     = ob_get_level();
        ob_start();
        nppp_kill_preload_watcher();
        $watcher_raw = trim( wp_strip_all_tags( (string) ob_get_clean() ) );
        unset( $GLOBALS['nppp_cli_ob_level'], $GLOBALS['nppp_last_notice_type'] );

        if ( $watcher_raw !== '' && ! $porcelain ) {
            WP_CLI::line( $watcher_raw );
        }

        // Kill the main preload process — safexec-aware.
        // When safexec SUID drops wget to nobody, only safexec --kill can signal
        // it. A plain kill/posix_kill from the PHP-FPM user returns EPERM silently.
        $killed       = false;
        $process_user = '';

        if ( function_exists( 'shell_exec' ) ) {
            $process_user = trim( (string) shell_exec(
                'ps -o user= -p ' . escapeshellarg( (string) $pid ) . ' 2>/dev/null'
            ) );
        }

        if ( $process_user === 'nobody' ) {
            // Locate safexec
            $sfx = '/usr/bin/safexec';
            if ( ! file_exists( $sfx ) && function_exists( 'shell_exec' ) ) {
                $detected = trim( (string) shell_exec( 'command -v safexec 2>/dev/null' ) );
                $sfx      = $detected !== '' ? $detected : '';
            }

            if ( $sfx !== '' && function_exists( 'stat' ) ) {
                $sfx_info = @stat( $sfx );
                if ( $sfx_info
                    && isset( $sfx_info['uid'], $sfx_info['mode'] )
                    && $sfx_info['uid'] === 0
                    && ( $sfx_info['mode'] & 04000 ) === 04000
                ) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
                    shell_exec( escapeshellarg( $sfx ) . ' --kill=' . (int) $pid . ' 2>&1' );
                    usleep( 250000 );
                    if ( ! nppp_is_process_alive( $pid ) ) {
                        $killed = true;
                    }
                }
            }

            if ( ! $killed ) {
                // safexec is the ONLY valid kill path for a nobody process.
                // posix_kill / kill -9 from PHP-FPM user will return EPERM — do NOT attempt them.
                $wp_filesystem->delete( $pid_file );
                $porcelain
                    ? WP_CLI::line( 'error' )
                    : WP_CLI::error( sprintf(
                        /* translators: 1: Process ID that could not be stopped 2: Same process ID for the kill command example */
                        __( 'Cannot stop PID %1$d (running as nobody via safexec): safexec not found, not SUID-root, or --kill failed. Run as root: safexec --kill=%2$d', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $pid,
                        $pid
                    ) );
                return;
            }
        } else {
            // Standard (non-safexec) process — SIGTERM → verify → SIGKILL → verify.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            exec( sprintf( 'kill -TERM %d 2>/dev/null', $pid ) );
            usleep( 300000 );

            if ( nppp_is_process_alive( $pid ) ) {
                $kill_bin = function_exists( 'shell_exec' )
                    ? trim( (string) shell_exec( 'command -v kill 2>/dev/null' ) )
                    : '';
                if ( $kill_bin !== '' ) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
                    shell_exec( escapeshellarg( $kill_bin ) . ' -9 ' . (int) $pid . ' 2>/dev/null' );
                    usleep( 300000 );
                }
            }

            if ( ! nppp_is_process_alive( $pid ) ) {
                $killed = true;
            }

            if ( ! $killed ) {
                $porcelain
                    ? WP_CLI::line( 'error' )
                    : WP_CLI::error( sprintf(
                        /* translators: %d: Process ID that could not be killed */
                        __( 'Failed to stop preload process (PID %d) — still alive after SIGTERM + SIGKILL.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $pid
                    ) );
                return;
            }
        }

        $wp_filesystem->delete( $pid_file );

        $porcelain
            ? WP_CLI::line( 'success' )
            /* translators: %d: Process ID that was terminated */
            : WP_CLI::success( sprintf( __( 'Preload process (PID %d) terminated.', 'fastcgi-cache-purge-and-preload-nginx' ), $pid ) );
    }

    /**
     * Implementation for `wp npp settings get`.
     * Redacts the API key unconditionally.
     */
    private function settings_get( array $args, array $assoc_args ): void {
        $key      = (string) ( $args[1] ?? '' );
        $settings = get_option( 'nginx_cache_settings', [] );

        if ( $key !== '' ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                WP_CLI::error( sprintf(
                    /* translators: %s: The unknown settings key name */
                    __( 'Unknown setting key: "%s". Run `wp npp settings get` to list all keys.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $key
                ) );
            }
            $val = (string) $settings[ $key ];
            // nginx_cache_key_custom_regex is base64-encoded in the DB for safe storage.
            if ( $key === 'nginx_cache_key_custom_regex' && $val !== '' ) {
                $decoded = base64_decode( $val, true );
                if ( $decoded !== false && $decoded !== '' ) {
                    $val = $decoded;
                }
            }
            WP_CLI::line( $val );
            return;
        }

        $rows = [];
        foreach ( $settings as $k => $v ) {
            // nginx_cache_key_custom_regex is base64-encoded in the DB — decode for display.
            if ( $k === 'nginx_cache_key_custom_regex' && $v !== '' ) {
                $decoded_v = base64_decode( $v, true );
                if ( $decoded_v !== false && $decoded_v !== '' ) {
                    $v = $decoded_v;
                }
            }
            $rows[] = [ 'Key' => $k, 'Value' => (string) $v ];
        }

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Key', 'Value' ] );
        $formatter->display_items( $rows );
    }

    /**
     * Implementation for `wp npp settings set`.
     * Enforces per-key type constraints and blocks protected keys.
     */
    private function settings_set( array $args ): void {
        $key   = (string) ( $args[1] ?? '' );
        $value = (string) ( $args[2] ?? '' );

        if ( $key === '' || $value === '' ) {
            WP_CLI::error( __( 'Usage: wp npp settings set <key> <value>', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        // Block all settings changes while an operation is active.
        // Path / regex changes mid-purge or mid-preload leave the running
        // process out of sync with the newly persisted options.
        if ( function_exists( 'nppp_is_operation_active' ) && nppp_is_operation_active() ) {
            WP_CLI::error( __( 'Settings cannot be changed while a purge or preload operation is running. Wait for it to finish and retry.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        $settings = get_option( 'nginx_cache_settings', [] );
        if ( ! array_key_exists( $key, $settings ) ) {
            WP_CLI::error( sprintf(
                /* translators: %s: The unknown settings key name */
                __( 'Unknown setting key: "%s". Run `wp npp settings get` to list valid keys.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $key
            ) );
        }

        // Enum settings: pctnorm mode.
        static $pctnorm_allowed = [ 'off', 'upper', 'lower', 'preserve' ];
        if ( $key === 'nginx_cache_pctnorm_mode' ) {
            if ( ! in_array( $value, $pctnorm_allowed, true ) ) {
                /* translators: 1: Settings key name 2: Comma-separated list of allowed values */
                WP_CLI::error( sprintf( __( 'Value for "%1$s" must be one of: %2$s.', 'fastcgi-cache-purge-and-preload-nginx' ), $key, implode( ', ', $pctnorm_allowed ) ) );
            }
            $settings[ $key ] = $value;
            update_option( 'nginx_cache_settings', $settings );

            /* translators: 1: Settings key name 2: New value */
            WP_CLI::success( sprintf( __( 'Updated "%1$s" → "%2$s".', 'fastcgi-cache-purge-and-preload-nginx' ), $key, $value ) );
            return;
        }

        // Boolean (yes/no) settings.
        static $yes_no_keys = [
            'nginx_cache_purge_on_update', 'nginx_cache_auto_preload', 'nginx_cache_auto_preload_mobile',
            'nginx_cache_watchdog',        'nginx_cache_send_mail',    'nginx_cache_preload_enable_proxy',
            'nginx_cache_schedule',        'nginx_cache_api',          'nppp_cloudflare_apo_sync', 'nppp_redis_cache_sync',
            'nppp_autopurge_posts',        'nppp_autopurge_terms',     'nppp_autopurge_plugins',
            'nppp_autopurge_themes',       'nppp_autopurge_3rdparty',  'nppp_http_purge_enabled',
            'nppp_rg_purge_enabled',       'nginx_cache_bypass_path_restriction',
            'nppp_related_include_home',   'nppp_related_include_category',
            'nppp_related_apply_manual',   'nppp_related_preload_after_manual',
        ];

        // Positive-integer settings — ranges.
        static $int_ranges = [
            'nginx_cache_cpu_limit'          => [ 10,    100    ],
            'nginx_cache_limit_rate'         => [ 1,     102400 ],
            'nginx_cache_wait_request'       => [ 0,     60     ],
            'nginx_cache_read_timeout'       => [ 10,    300    ],
            'nginx_cache_preload_proxy_port' => [ 1,     65535  ],
        ];
        static $int_keys = [
            'nginx_cache_cpu_limit',      'nginx_cache_limit_rate',
            'nginx_cache_wait_request',   'nginx_cache_read_timeout',
            'nginx_cache_preload_proxy_port',
        ];

        if ( in_array( $key, $yes_no_keys, true ) ) {
            if ( ! in_array( $value, [ 'yes', 'no' ], true ) ) {
                /* translators: %s: Settings key name */
                WP_CLI::error( sprintf( __( 'Value for "%s" must be "yes" or "no".', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
            }
            $sanitized = $value;
        } elseif ( in_array( $key, $int_keys, true ) ) {
            if ( ! ctype_digit( $value ) ) {
                /* translators: %s: Settings key name */
                WP_CLI::error( sprintf( __( 'Value for "%s" must be a non-negative integer.', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
            }
            $sanitized = (int) $value;
            [ $rmin, $rmax ] = $int_ranges[ $key ];
            if ( $sanitized < $rmin || $sanitized > $rmax ) {
                WP_CLI::error( sprintf(
                    /* translators: 1: Settings key name 2: Minimum allowed value 3: Maximum allowed value */
                    __( 'Value for "%1$s" must be between %2$d and %3$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $key, $rmin, $rmax
                ) );
            }
        } elseif ( $key === 'nginx_cache_email' ) {
            $sanitized = sanitize_email( $value );
            if ( ! is_email( $sanitized ) ) {
                /* translators: %s: Settings key name */
                WP_CLI::error( sprintf( __( 'Value for "%s" must be a valid email address.', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
            }
        } elseif ( $key === 'nppp_http_purge_custom_url' ) {
            // esc_url_raw + FILTER_VALIDATE_URL logic.
            $sanitized = untrailingslashit( esc_url_raw( trim( $value ) ) );
            $scheme    = strtolower( (string) wp_parse_url( $sanitized, PHP_URL_SCHEME ) );
            if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
                /* translators: %s: Settings key name */
                WP_CLI::error( sprintf( __( 'Value for "%s" must be a valid http:// or https:// URL.', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
            }
        } elseif ( $key === 'nginx_cache_api_key' ) {
            // must be a 64-character hexadecimal string.
            if ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $value ) ) {
                WP_CLI::error( __( 'ERROR API KEY: Please enter a valid 64-character hexadecimal string for the API key.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            $sanitized = $value;
        } elseif ( $key === 'nginx_cache_key_custom_regex' ) {
            // User supplies the raw regex; full ReDoS guard,
            // then base64-encodes for DB storage
            if ( @preg_match( $value, '' ) === false ) {
                WP_CLI::error( __( 'ERROR REGEX: The custom cache key regex is invalid. Check the syntax and test it before use.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            if ( preg_match_all( '/(\(\?=.*\))/i', $value ) > 3 ) {
                WP_CLI::error( __( 'ERROR REGEX: The custom cache key regex contains more than 3 lookaheads and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            if ( preg_match( '/\(\?=.*\.\*\)/', $value ) ) {
                WP_CLI::error( __( 'ERROR REGEX: The custom cache key regex contains a greedy quantifier inside a lookahead and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            if ( preg_match_all( '/\.\*/', $value ) > 1 ) {
                WP_CLI::error( __( 'ERROR REGEX: The custom cache key regex contains more than one ".*" quantifier and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            if ( strlen( $value ) > 300 ) {
                WP_CLI::error( __( 'ERROR REGEX: The custom cache key regex exceeds the allowed length of 300 characters.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            // Store as base64
            $sanitized = base64_encode( $value );
        } elseif ( $key === 'nginx_cache_reject_regex' ) {
            // shell-byte guard first, then single-line sanitize.
            if ( function_exists( 'nppp_forbidden_shell_bytes_reason' ) ) {
                $reason = nppp_forbidden_shell_bytes_reason( $value );
                if ( $reason ) {
                    WP_CLI::error( $reason );
                }
            }
            $sanitized = function_exists( 'nppp_sanitize_reject_regex' )
                ? nppp_sanitize_reject_regex( $value )
                : sanitize_text_field( $value );
        } elseif ( $key === 'nginx_cache_reject_extension' ) {
            // validate each token, then normalize globs.
            $tokens = preg_split( '/[,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
            $bad    = [];
            foreach ( $tokens as $tok ) {
                $ok = preg_match( '/^(?:\*\.)?[a-z0-9]+(?:\.[a-z0-9]+)*$/i', $tok )
                    || preg_match( '/^\.[a-z0-9]+(?:\.[a-z0-9]+)*$/i', $tok );
                if ( ! $ok ) {
                    $bad[] = $tok;
                }
            }
            if ( ! empty( $bad ) ) {
                $preview = implode( ', ', array_slice( $bad, 0, 3 ) ) . ( count( $bad ) > 3 ? '…' : '' );
                WP_CLI::error( sprintf(
                    /* translators: %s: short comma-separated preview (max 3) of invalid extension patterns */
                    __( 'ERROR OPTION: Invalid extension pattern(s): %s. Allowed examples: *.css, .css, css', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $preview
                ) );
            }
            $sanitized = function_exists( 'nppp_sanitize_reject_extension_globs' )
                ? nppp_sanitize_reject_extension_globs( $value )
                : sanitize_text_field( $value );
        } elseif ( $key === 'nginx_cache_path' ) {
            $sanitized = sanitize_text_field( $value );
            if ( $sanitized === '' || $sanitized[0] !== '/' ) {
                WP_CLI::error( __( 'nginx_cache_path must be an absolute path starting with /.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            // Read the bypass flag from current saved settings, then run the
            // nppp_validate_path()
            $bypass_restriction = ( ( $settings['nginx_cache_bypass_path_restriction'] ?? 'no' ) === 'yes' );
            if ( function_exists( 'nppp_validate_path' ) ) {
                $path_result = nppp_validate_path( $sanitized, false, $bypass_restriction );
                if ( $path_result !== true ) {
                    switch ( $path_result ) {
                        case 'critical_path':
                            WP_CLI::error( __( 'ERROR PATH: The specified Nginx Cache Directory is either a critical system directory or a top-level directory and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx' ) );
                            break;
                        case 'directory_not_exist_or_readable':
                            WP_CLI::error( __( 'ERROR PATH: The specified Nginx Cache Directory does not exist. Please verify the Nginx Cache Directory.', 'fastcgi-cache-purge-and-preload-nginx' ) );
                            break;
                        default:
                            WP_CLI::error( __( 'ERROR PATH: An invalid path was provided for the Nginx Cache Directory. Please provide a valid directory path.', 'fastcgi-cache-purge-and-preload-nginx' ) );
                    }
                }
            }
        } elseif ( $key === 'nginx_cache_preload_proxy_host' ) {
            // Mirror settings-sanitize.php: full hostname/IPv4/IPv6 validation.
            if ( function_exists( 'nppp_sanitize_validate_proxy_host' ) ) {
                $err    = null;
                $notice = null;
                $host   = nppp_sanitize_validate_proxy_host( $value, $err, $notice );
                if ( $host === null ) {
                    WP_CLI::error( $err ?? __( 'ERROR OPTION: Invalid proxy host.', 'fastcgi-cache-purge-and-preload-nginx' ) );
                }
                if ( $notice ) {
                    WP_CLI::warning( $notice );
                }
                $sanitized = $host;
            } else {
                $sanitized = sanitize_text_field( $value );
            }
        } elseif ( $key === 'nginx_cache_mobile_user_agent' ) {
            // Mirror settings-sanitize.php: max 512 chars.
            $sanitized = sanitize_text_field( wp_unslash( $value ) );
            if ( strlen( $sanitized ) > 512 ) {
                WP_CLI::error( __( 'ERROR: Mobile User Agent exceeds the maximum allowed length of 512 characters.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
        } elseif ( $key === 'nppp_http_purge_suffix' ) {
            // Mirror settings-sanitize.php: alphanumeric + hyphens/underscores only, non-empty.
            $sanitized = trim( sanitize_text_field( $value ), '/' );
            if ( $sanitized === '' ) {
                WP_CLI::error( __( 'ERROR OPTION: HTTP Purge Suffix cannot be empty. Use e.g. "purge".', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $sanitized ) ) {
                WP_CLI::error( __( 'ERROR OPTION: HTTP Purge Suffix must contain only letters, digits, hyphens, and underscores.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
        } else {
            $sanitized = sanitize_text_field( $value );
        }

        $settings[ $key ] = $sanitized;

        // When bypass_path_restriction turns OFF, re-validate the stored cache path.
        // If it is now outside the allowed roots, reset it to the safe placeholder.
        $path_was_reset = false;
        if ( $key === 'nginx_cache_bypass_path_restriction' && $sanitized === 'no' ) {
            $stored_path = (string) ( $settings['nginx_cache_path'] ?? '' );
            if ( $stored_path !== ''
                && function_exists( 'nppp_validate_path' )
                && nppp_validate_path( $stored_path, false, false ) !== true
            ) {
                $settings['nginx_cache_path'] = '/dev/shm/change-me-now';
                $path_was_reset = true;
            }
        }

        update_option( 'nginx_cache_settings', $settings );

        /* translators: 1: Settings key name 2: New sanitized value */
        WP_CLI::success( sprintf( __( 'Updated "%1$s" → "%2$s".', 'fastcgi-cache-purge-and-preload-nginx' ), $key, (string) $sanitized ) );
        if ( $path_was_reset ) {
            WP_CLI::warning( __( 'Cache path was outside the allowed directories and has been reset to /dev/shm/change-me-now.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }
}

WP_CLI::add_command(
    'npp',
    'NPPP_CLI_Command',
    [
        'shortdesc' => 'Manages Nginx Cache: purge, preload, status, log, settings, schedule.',
        'longdesc'  =>
            "## OVERVIEW\n\n"
            . "Direct CLI access to all Nginx Cache Purge Preload operations.\n"
            . "Commands call the same underlying PHP functions as the REST API\n"
            . "and AJAX handlers — no HTTP overhead, no nonce validation.\n\n"
            . "Run 'wp help npp <subcommand>' for full subcommand docs.\n\n"
            . "## MORE INFO\n\n"
            . "  https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload",
    ]
);
