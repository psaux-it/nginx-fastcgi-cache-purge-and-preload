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
 *     wp npp purge --url=https://example.com/blog/my-post/
 *
 *     # Dry-run a full purge
 *     wp npp purge --dry-run
 *
 *     # Start full-site preloading
 *     wp npp preload
 *
 *     # Preload a single URL
 *     wp npp preload --url=https://example.com/blog/my-post/
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
     * [--url=<url>]
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
     *     wp npp purge --url=https://example.com/blog/my-post/
     *     wp npp purge --dry-run
     *     wp npp purge --porcelain && echo "cache clear confirmed"
     *
     * @when after_wp_load
     */
    public function purge( array $args, array $assoc_args ): void {
        $url       = (string) ( $assoc_args['url']       ?? '' );
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
     * [--url=<url>]
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
     *     wp npp preload --url=https://example.com/shop/
     *     wp npp preload --stop
     *     wp npp preload --dry-run
     *
     * @when after_wp_load
     */
    public function preload( array $args, array $assoc_args ): void {
        $url       = (string) ( $assoc_args['url']       ?? '' );
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

        $result = $this->capture_cli_output(
            function () use ( $cache_path, $plugin_dir, $tmp_path, $fdomain, $pid_file, $reject_regex, $limit_rate, $cpu_limit ): void {
                nppp_preload(
                    $cache_path,
                    $plugin_dir,
                    $tmp_path,
                    $fdomain,
                    $pid_file,
                    $reject_regex,
                    $limit_rate,
                    $cpu_limit,
                    false,  // $nppp_is_auto_preload
                    false,  // $nppp_is_rest_api
                    false,  // $nppp_is_wp_cron
                    false   // $nppp_is_admin_bar
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

        $disk           = nppp_get_cache_disk_size( $cache_path );
        $preload_status = nppp_check_preload_status();
        $path_status    = nppp_check_path();
        $perm_status    = nppp_check_permissions_recursive_with_cache();
        $page_count     = nppp_get_in_cache_page_count();

        $rows = [
            [ 'Field' => 'Cache Path',      'Value' => $cache_path ],
            [ 'Field' => 'Path Status',     'Value' => (string) $path_status ],
            [ 'Field' => 'Permissions OK',  'Value' => (string) $perm_status ],
            [ 'Field' => 'Preload Status',  'Value' => (string) $preload_status ],
            [ 'Field' => 'Disk Used',       'Value' => $disk['formatted']  ?? 'N/A' ],
            [ 'Field' => 'Cached Files',    'Value' => (string) ( $disk['file_count'] ?? 0 ) ],
            [ 'Field' => 'Pages in Cache',  'Value' => (string) ( $page_count ?? 'N/A' ) ],
            [ 'Field' => 'Auto Purge',      'Value' => $settings['nginx_cache_purge_on_update'] ?? 'no' ],
            [ 'Field' => 'Auto Preload',    'Value' => $settings['nginx_cache_auto_preload']    ?? 'no' ],
            [ 'Field' => 'Watchdog',        'Value' => $settings['nginx_cache_watchdog']        ?? 'no' ],
            [ 'Field' => 'REST API',        'Value' => $settings['nginx_cache_api']             ?? 'no' ],
            [ 'Field' => 'Schedule',        'Value' => $settings['nginx_cache_schedule']        ?? 'no' ],
        ];

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
            file_put_contents( $log_file, '' );
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
     * : For 'get': a specific settings key (omit to list all).
     * : For 'set': the settings key to update.
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
        $hook     = 'npp_cache_preload_event';
        $timezone = wp_timezone_string();
        $found    = [];

        if ( ! empty( $events ) ) {
            foreach ( $events as $timestamp => $crons ) {
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
                    break;
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
                'error', 'warning' => WP_CLI::warning( $line ),
                default            => WP_CLI::success( $line ),
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
        nppp_kill_preload_watcher();

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec( sprintf( 'kill -TERM %d 2>/dev/null', $pid ) );

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
            if ( ! array_key_exists( $key, $settings ) ) {
                WP_CLI::error( sprintf(
                    'Unknown setting key: "%s". Run `wp npp settings get` to list all keys.',
                    $key
                ) );
            }
            WP_CLI::line( (string) $settings[ $key ] );
            return;
        }

        $rows = [];
        foreach ( $settings as $k => $v ) {
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
        }

        // Keys that must never be mutated via CLI.
        $protected = [ 'nginx_cache_api_key', 'nginx_cache_key_custom_regex' ];
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
        'shortdesc' => 'Manages Nginx FastCGI cache: purge, preload, status, log, settings, schedule.',
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
