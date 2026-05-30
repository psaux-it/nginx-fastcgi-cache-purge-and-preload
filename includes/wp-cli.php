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

// Guard: only load inside a genuine WP-CLI invocation.
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
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
                ? sprintf( '[dry-run] Would purge single URL: %s', $url )
                : sprintf( '[dry-run] Would purge entire cache at: %s', $cache_path )
            );
            return;
        }

        // Validate URL before entering the output buffer — WP_CLI::error() calls exit().
        if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            WP_CLI::error( sprintf( 'Invalid URL: %s', $url ) );
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
                WP_CLI::line( '[dry-run] Would stop the active preload process (cache preserved).' );
            } elseif ( $url !== '' ) {
                WP_CLI::line( sprintf( '[dry-run] Would preload single URL: %s', $url ) );
            } else {
                WP_CLI::line( sprintf( '[dry-run] Would start full-site preload for: %s', $fdomain ) );
            }
            return;
        }

        if ( $stop ) {
            $this->preload_stop( $pid_file, $porcelain );
            return;
        }

        // Validate before entering the output buffer.
        if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            WP_CLI::error( sprintf( 'Invalid URL: %s', $url ) );
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
                'true'      => 'OK',
                'false'     => 'Not Available',
                'Not Found' => 'Cache Path Not Found',
                default     => $v ?? 'N/A',
            };
        };
        $preload_label = static function ( ?string $v ): string {
            return match ( $v ) {
                'progress' => 'Running',
                'true'     => 'Ready',
                'false'    => 'Not Available',
                default    => $v ?? 'N/A',
            };
        };

        // ── System checks ─────────────────────────────────────────────────
        $php_owner    = (string) nppp_get_website_user();
        $server_user  = (string) nppp_get_webserver_user();
        $php_lc       = trim( strtolower( $php_owner ) );
        $srv_lc       = trim( strtolower( $server_user ) );

        if ( $php_lc === 'not determined' || $srv_lc === 'not determined' ) {
            $isolation = 'Not Determined';
        } else {
            $isolation = ( $php_lc === $srv_lc ) ? 'Not Isolated' : 'Isolated';
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
            ? ( (string) $page_count . ( $scanned_at ? '  (' . human_time_diff( (int) $scanned_at, time() ) . ' ago)' : '' ) )
            : 'N/A — run a Preload to populate';

        $ratio_raw   = nppp_get_cache_ratio( $page_count !== false ? $page_count : 'N/A' );
        $ratio_label = is_array( $ratio_raw )
            ? number_format( $ratio_raw['ratio'], 1 ) . '%'
              . '  (' . $ratio_raw['hits']   . ' cached'
              . ' / ' . $ratio_raw['misses'] . ' not cached'
              . ' / ' . $ratio_raw['total']  . ' total)'
            : (string) $ratio_raw;

        $disk = nppp_get_cache_disk_size( $cache_path );
        if ( $disk === null ) {
            $disk_label = 'N/A';
        } elseif ( $disk['dedicated'] ) {
            $pct        = $disk['total'] > 0 ? number_format( ( $disk['used'] / $disk['total'] ) * 100, 1 ) : '0.0';
            $disk_label = $pct . '%  ('
                . nppp_format_cache_size( $disk['used'] ) . ' used'
                . ' / ' . nppp_format_cache_size( $disk['total'] ) . ' total — dedicated fs)';
        } else {
            $disk_label = nppp_format_cache_size( $disk['used'] ) . ' used in cache dir'
                . '  (' . nppp_format_cache_size( $disk['free'] ) . ' free on partition)';
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
                            $label .= '  [active]';
                        }
                        if ( $fuse_dest !== '' ) {
                            $label .= '  → ' . $fuse_dest . ' (FUSE)';
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
                        'Field' => 'Mount',
                        'Value' => (string) $mount,
                    ];
                }
            }
        }

        // ── Binary versions ───────────────────────────────────────────────
        $nginx_info     = nppp_get_nginx_info();
        $ver_nginx      = (string) ( $nginx_info['nginx_version'] ?? 'Unknown' );
        $ver_php        = (string) ( $nginx_info['php_version']   ?? 'Unknown' );
        $ver_wget       = (string) nppp_check_wget_version();
        $ver_safexec    = (string) nppp_check_safexec_version();
        $ver_rg         = (string) nppp_check_rg_version();
        $ver_libfuse    = (string) nppp_check_libfuse_version();
        $ver_bindfs     = (string) nppp_check_bindfs_version();

        // ── Build rows ────────────────────────────────────────────────────
        $sep  = static fn( string $s ): array => [ 'Field' => "── $s ──", 'Value' => '' ];

        $rows = [
            $sep( 'ACTION READINESS' ),
            [ 'Field' => 'Server Side Action',              'Value' => $bool_label( $action_server ) ],
            [ 'Field' => 'Purge Action',                    'Value' => $bool_label( $action_purge ) ],
            [ 'Field' => 'Preload Action',                  'Value' => $preload_label( $action_preload ) ],

            $sep( 'SYSTEM CHECKS' ),
            [ 'Field' => 'PHP Process Owner',               'Value' => $php_owner ],
            [ 'Field' => 'Web Server User',                 'Value' => $server_user ],
            [ 'Field' => 'Process Isolation',               'Value' => $isolation ],
            [ 'Field' => 'Shell Execution',                 'Value' => $shell_exec ],
            [ 'Field' => 'Cache Key Regex',                 'Value' => $regex_probe ],
            [ 'Field' => 'wget',                            'Value' => $cmd_wget ],
            [ 'Field' => 'safexec',                         'Value' => $cmd_safexec ],
            [ 'Field' => 'rg',                              'Value' => $cmd_rg ],
            [ 'Field' => 'cpulimit',                        'Value' => $cmd_cpulimit ],

            $sep( 'CACHE HEALTH' ),
            [ 'Field' => 'Cache Path',                      'Value' => $cache_path ],
            [ 'Field' => 'Path Status',                     'Value' => ( $path_status !== null ? (string) $path_status : 'N/A' ) ],
            [ 'Field' => 'Permissions',                     'Value' => $bool_label( $perm_status ) ],
            [ 'Field' => 'Pages in Cache',                  'Value' => $page_count_label ],
            [ 'Field' => 'Cache Coverage',                  'Value' => $ratio_label ],
            [ 'Field' => 'Disk Used',                       'Value' => $disk_label ],
        ];

        $rows = array_merge(
            $rows,
            [ $sep( 'NGINX CONFIG' ) ],
            ! empty( $nginx_cache_paths_rows )
                ? $nginx_cache_paths_rows
                : [ [ 'Field' => 'Nginx Cache Paths', 'Value' => 'Not Found (nginx.conf not parsed)' ] ],
            [ $sep( 'FUSE MOUNTS' ) ],
            ! empty( $fuse_mounts_rows )
                ? $fuse_mounts_rows
                : [ [ 'Field' => 'FUSE Mounts', 'Value' => 'Not Mounted' ] ],
            [

                $sep( 'BINARY VERSIONS' ),
                [ 'Field' => 'Nginx',                           'Value' => $ver_nginx ],
                [ 'Field' => 'PHP',                             'Value' => $ver_php ],
                [ 'Field' => 'wget',                            'Value' => $ver_wget ],
                [ 'Field' => 'safexec',                         'Value' => $ver_safexec ],
                [ 'Field' => 'rg',                              'Value' => $ver_rg ],
                [ 'Field' => 'libfuse',                         'Value' => $ver_libfuse ],
                [ 'Field' => 'bindfs',                          'Value' => $ver_bindfs ],

                $sep( 'SETTINGS' ),
                [ 'Field' => 'Auto Purge',                      'Value' => $settings['nginx_cache_purge_on_update']        ?? 'no' ],
                [ 'Field' => 'Auto Preload',                    'Value' => $settings['nginx_cache_auto_preload']           ?? 'no' ],
                [ 'Field' => 'Preload Mobile',                  'Value' => $settings['nginx_cache_auto_preload_mobile']    ?? 'no' ],
                [ 'Field' => 'Preload Watchdog',                'Value' => $settings['nginx_cache_watchdog']               ?? 'no' ],
                [ 'Field' => 'REST API',                        'Value' => $settings['nginx_cache_api']                    ?? 'no' ],
                [ 'Field' => 'Schedule',                        'Value' => $settings['nginx_cache_schedule']               ?? 'no' ],
                [ 'Field' => 'Send Mail',                       'Value' => $settings['nginx_cache_send_mail']              ?? 'no' ],
                [ 'Field' => 'HTTP Purge',                      'Value' => $settings['nppp_http_purge_enabled']            ?? 'no' ],
                [ 'Field' => 'RG Purge',                        'Value' => $settings['nppp_rg_purge_enabled']              ?? 'no' ],
                [ 'Field' => 'Cloudflare APO Sync',             'Value' => $settings['nppp_cloudflare_apo_sync']           ?? 'no' ],
                [ 'Field' => 'Redis Cache Sync',                'Value' => $settings['nppp_redis_cache_sync']              ?? 'no' ],
                [ 'Field' => 'Proxy Preload',                   'Value' => $settings['nginx_cache_preload_enable_proxy']   ?? 'no' ],
                [ 'Field' => 'Bypass Path Restriction',         'Value' => $settings['nginx_cache_bypass_path_restriction'] ?? 'no' ],
                [ 'Field' => 'URL Normalization',               'Value' => $settings['nginx_cache_pctnorm_mode']           ?? 'off' ],

                $sep( 'AUTO-PURGE TRIGGERS' ),
                [ 'Field' => 'Auto-Purge Posts',                'Value' => $settings['nppp_autopurge_posts']               ?? 'no' ],
                [ 'Field' => 'Auto-Purge Terms',                'Value' => $settings['nppp_autopurge_terms']               ?? 'no' ],
                [ 'Field' => 'Auto-Purge Plugins',              'Value' => $settings['nppp_autopurge_plugins']             ?? 'no' ],
                [ 'Field' => 'Auto-Purge Themes',               'Value' => $settings['nppp_autopurge_themes']              ?? 'no' ],
                [ 'Field' => 'Auto-Purge 3rd Party',            'Value' => $settings['nppp_autopurge_3rdparty']            ?? 'no' ],

                $sep( 'RELATED PAGES' ),
                [ 'Field' => 'Always Purge Homepage',           'Value' => $settings['nppp_related_include_home']          ?? 'no' ],
                [ 'Field' => 'Always Purge Shop Page',          'Value' => $settings['nppp_related_apply_manual']          ?? 'no' ],
                [ 'Field' => 'Always Purge Categories & Tags',  'Value' => $settings['nppp_related_include_category']      ?? 'no' ],
                [ 'Field' => 'Preload Related Pages',           'Value' => $settings['nppp_related_preload_after_manual']  ?? 'no' ],
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
                WP_CLI::error( sprintf( 'Failed to truncate log file: %s', $log_file ) );
                return;
            }
            WP_CLI::success( 'Log file truncated.' );
            return;
        }

        if ( ! file_exists( $log_file ) || filesize( $log_file ) === 0 ) {
            WP_CLI::warning( 'Log file is empty or does not exist.' );
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
     * The API key is always redacted on `get` and blocked on `set`.
     * Regex/base64 fields are read-only via CLI.
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
            WP_CLI::error( 'Unknown action. Use: get or set.' );
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
        WP_CLI::success( 'All NPP transient caches cleared.' );
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
                            'Interval' => (string) ( $data['interval'] ?? 'N/A' ),
                            'Args'     => wp_json_encode( $data['args'] ?? [] ),
                        ];
                    }
                }
            }
        }

        if ( empty( $found ) ) {
            WP_CLI::warning( 'No active NPP schedule events found.' );
            return;
        }

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Hook', 'Next Run', 'Interval', 'Args' ] );
        $formatter->display_items( $found );
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
                WP_CLI::error( 'Operation failed — no status message captured. Check: wp npp log' );
            } elseif ( $type === 'warning' ) {
                WP_CLI::warning( 'Operation completed with warnings — no message captured.' );
            } else {
                WP_CLI::success( 'Done.' );
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
            WP_CLI::error( 'Failed to initialize WP_Filesystem.' );
            return;
        }

        if ( ! $wp_filesystem->exists( $pid_file ) ) {
            $porcelain
                ? WP_CLI::line( 'warning' )
                : WP_CLI::warning( 'No active preload process found (PID file absent).' );
            return;
        }

        $pid = (int) trim( (string) nppp_perform_file_operation( $pid_file, 'read' ) );

        if ( $pid <= 0 ) {
            $wp_filesystem->delete( $pid_file );
            $porcelain
                ? WP_CLI::line( 'warning' )
                : WP_CLI::warning( 'Invalid PID in lock file. Stale lock removed.' );
            return;
        }

        if ( ! nppp_is_process_alive( $pid ) ) {
            $wp_filesystem->delete( $pid_file );
            $porcelain
                ? WP_CLI::line( 'warning' )
                : WP_CLI::warning( sprintf( 'PID %d is no longer alive. Stale lock removed.', $pid ) );
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
                    $kill_out = (string) shell_exec( escapeshellarg( $sfx ) . ' --kill=' . (int) $pid . ' 2>&1' );
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
                        'Cannot stop PID %d (running as nobody via safexec): '
                        . 'safexec not found, not SUID-root, or --kill failed. '
                        . 'Run as root: safexec --kill=%d',
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
                        'Failed to stop preload process (PID %d) — still alive after SIGTERM + SIGKILL.',
                        $pid
                    ) );
                return;
            }
        }

        $wp_filesystem->delete( $pid_file );

        $porcelain
            ? WP_CLI::line( 'success' )
            : WP_CLI::success( sprintf( 'Preload process (PID %d) terminated.', $pid ) );
    }

    /**
     * Implementation for `wp npp settings get`.
     * Redacts the API key unconditionally.
     */
    private function settings_get( array $args, array $assoc_args ): void {
        $key      = (string) ( $args[1] ?? '' );
        $settings = get_option( 'nginx_cache_settings', [] );

        // API key is never exposed via CLI.
        unset( $settings['nginx_cache_api_key'] );

        if ( $key !== '' ) {
            if ( $key === 'nginx_cache_api_key' ) {
                WP_CLI::error( 'The API key is protected and cannot be retrieved via WP-CLI.' );
            }
            if ( ! array_key_exists( $key, $settings ) ) {
                WP_CLI::error( sprintf(
                    'Unknown setting key: "%s". Run `wp npp settings get` to list all keys.',
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
            WP_CLI::error( 'Usage: wp npp settings set <key> <value>' );
            return;
        }

        // Block all settings changes while an operation is active.
        // Path / regex changes mid-purge or mid-preload leave the running
        // process out of sync with the newly persisted options.
        if ( function_exists( 'nppp_is_operation_active' ) && nppp_is_operation_active() ) {
            WP_CLI::error( 'Settings cannot be changed while a purge or preload operation is running. Wait for it to finish and retry.' );
        }

        // Keys that must never be mutated via CLI.
        // nginx_cache_reject_regex / reject_extension require shell-byte validation
        // that only settings-sanitize.php performs — block them here to stay safe.
        $protected = [
            'nginx_cache_api_key',
            'nginx_cache_key_custom_regex',
            'nginx_cache_reject_regex',
            'nginx_cache_reject_extension',
        ];
        if ( in_array( $key, $protected, true ) ) {
            WP_CLI::error( sprintf( 'Setting "%s" is protected and cannot be changed via WP-CLI.', $key ) );
        }

        $settings = get_option( 'nginx_cache_settings', [] );
        if ( ! array_key_exists( $key, $settings ) ) {
            WP_CLI::error( sprintf(
                'Unknown setting key: "%s". Run `wp npp settings get` to list valid keys.',
                $key
            ) );
        }

        // Enum settings: pctnorm mode.
        static $pctnorm_allowed = [ 'off', 'upper', 'lower', 'preserve' ];
        if ( $key === 'nginx_cache_pctnorm_mode' ) {
            if ( ! in_array( $value, $pctnorm_allowed, true ) ) {
                WP_CLI::error( sprintf( 'Value for "%s" must be one of: %s.', $key, implode( ', ', $pctnorm_allowed ) ) );
            }
            $settings[ $key ] = $value;
            update_option( 'nginx_cache_settings', $settings );
            WP_CLI::success( sprintf( 'Updated "%s" → "%s".', $key, $value ) );
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

        // Positive-integer settings.
        static $int_keys = [
            'nginx_cache_cpu_limit',      'nginx_cache_limit_rate',
            'nginx_cache_wait_request',   'nginx_cache_read_timeout',
            'nginx_cache_preload_proxy_port',
        ];

        if ( in_array( $key, $yes_no_keys, true ) ) {
            if ( ! in_array( $value, [ 'yes', 'no' ], true ) ) {
                WP_CLI::error( sprintf( 'Value for "%s" must be "yes" or "no".', $key ) );
            }
            $sanitized = $value;
        } elseif ( in_array( $key, $int_keys, true ) ) {
            if ( ! ctype_digit( $value ) ) {
                WP_CLI::error( sprintf( 'Value for "%s" must be a non-negative integer.', $key ) );
            }
            $sanitized = (int) $value;
        } elseif ( $key === 'nginx_cache_email' ) {
            $sanitized = sanitize_email( $value );
            if ( ! is_email( $sanitized ) ) {
                WP_CLI::error( sprintf( 'Value for "%s" must be a valid email address.', $key ) );
            }
        } elseif ( $key === 'nppp_http_purge_custom_url' ) {
            // Must match the esc_url_raw + FILTER_VALIDATE_URL logic in settings-sanitize.php.
            $sanitized = untrailingslashit( esc_url_raw( trim( $value ) ) );
            $scheme    = strtolower( (string) wp_parse_url( $sanitized, PHP_URL_SCHEME ) );
            if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
                WP_CLI::error( sprintf( 'Value for "%s" must be a valid http:// or https:// URL.', $key ) );
            }
        } elseif ( $key === 'nginx_cache_path' ) {
            $sanitized = sanitize_text_field( $value );
            if ( $sanitized === '' || $sanitized[0] !== '/' ) {
                WP_CLI::error( 'nginx_cache_path must be an absolute path starting with /.' );
            }
        } else {
            $sanitized = sanitize_text_field( $value );
        }

        $settings[ $key ] = $sanitized;
        update_option( 'nginx_cache_settings', $settings );

        WP_CLI::success( sprintf( 'Updated "%s" → "%s".', $key, (string) $sanitized ) );
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
