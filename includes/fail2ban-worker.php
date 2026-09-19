<?php
/**
 * Fail2ban RDAP enrichment worker for Nginx Cache Purge Preload
 * Description: Background consumer for the Fail2Ban event queue. The
 *              webhook just records the event and makes sure a worker is
 *              running; the worker itself claims pending IPs, looks up RIPE
 *              whois + abuse contacts in parallel (via WordPress's bundled
 *              WpOrg\Requests library), writes results back, and exits when
 *              idle.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

// PID/heartbeat/lock files -- same runtime dir preload.php uses.
if ( ! defined( 'NPPP_F2B_WORKER_PID_FILE' ) ) {
    define( 'NPPP_F2B_WORKER_PID_FILE', 'f2b_rdap_worker.pid' );
}
if ( ! defined( 'NPPP_F2B_WORKER_HB_FILE' ) ) {
    define( 'NPPP_F2B_WORKER_HB_FILE', 'f2b_rdap_worker.hb' );
}
if ( ! defined( 'NPPP_F2B_WORKER_LOCK_FILE' ) ) {
    define( 'NPPP_F2B_WORKER_LOCK_FILE', 'f2b_rdap_worker.lock' );
}

// RIPEstat allows max 8 concurrent requests per source IP. Each IP needs
// 2 requests (whois + abuse), so 4 IPs per batch sits right at that limit.
if ( ! defined( 'NPPP_F2B_WORKER_BATCH' ) ) {
    define( 'NPPP_F2B_WORKER_BATCH', 4 );
}

// How long to sit on an empty queue before exiting. No permanent daemon.
if ( ! defined( 'NPPP_F2B_WORKER_IDLE_SECONDS' ) ) {
    define( 'NPPP_F2B_WORKER_IDLE_SECONDS', 10 );
}

// Max lifetime per process. A new one gets spawned when needed.
if ( ! defined( 'NPPP_F2B_WORKER_MAX_RUNTIME' ) ) {
    define( 'NPPP_F2B_WORKER_MAX_RUNTIME', 600 );
}

// Heartbeat older than this? Don't trust the PID, could be reused.
if ( ! defined( 'NPPP_F2B_WORKER_STALE_SECONDS' ) ) {
    define( 'NPPP_F2B_WORKER_STALE_SECONDS', 120 );
}

// Self-heal cron only, never the main dispatch path.
if ( ! defined( 'NPPP_F2B_WORKER_HOOK' ) ) {
    define( 'NPPP_F2B_WORKER_HOOK', 'nppp_f2b_worker_event' );
}

// Cache key for the php/nohup binary probe.
if ( ! defined( 'NPPP_F2B_WORKER_ENV_KEY' ) ) {
    define( 'NPPP_F2B_WORKER_ENV_KEY', 'nppp_f2b_worker_env' );
}

// Backup throttle that doesn't rely on the filesystem. If the runtime dir
// isn't writable, the PID/heartbeat check can't see a running worker and
// every event would try to spawn one. TTL is shorter than a worker actually
// takes to boot, so on a healthy site this never kicks in.
if ( ! defined( 'NPPP_F2B_SPAWN_TICK_KEY' ) ) {
    define( 'NPPP_F2B_SPAWN_TICK_KEY', 'nppp_f2b_spawn_tick' );
}
if ( ! defined( 'NPPP_F2B_SPAWN_TICK_TTL' ) ) {
    define( 'NPPP_F2B_SPAWN_TICK_TTL', 5 );
}

// Retry counter for total upstream failures. "RIPE has nothing on this IP"
// and "RIPE didn't respond" used to be treated the same and both
// permanently blanked the row. Now a real outage gets a few retries first.
if ( ! defined( 'NPPP_F2B_RDAP_FAIL_PREFIX' ) ) {
    define( 'NPPP_F2B_RDAP_FAIL_PREFIX', 'nppp_f2b_rdap_fail_' );
}
if ( ! defined( 'NPPP_F2B_RDAP_FAIL_TTL' ) ) {
    define( 'NPPP_F2B_RDAP_FAIL_TTL', 10 * MINUTE_IN_SECONDS );
}

// ---------------------------------------------------------------------------
// Runtime state (PID / heartbeat)
//
// Raw filesystem calls on purpose -- this runs on the webhook hot path and
// needs real flock(), which WP_Filesystem can't give us.
// ---------------------------------------------------------------------------

function nppp_f2b_worker_pid_path(): string {
    return nppp_get_runtime_file( NPPP_F2B_WORKER_PID_FILE );
}

function nppp_f2b_worker_hb_path(): string {
    return nppp_get_runtime_file( NPPP_F2B_WORKER_HB_FILE );
}

function nppp_f2b_worker_touch_heartbeat(): void {
    $path = nppp_f2b_worker_hb_path();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
    if ( ! @touch( $path ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents( $path, (string) time(), LOCK_EX );
    }
    clearstatcache( true, $path );
}

function nppp_f2b_worker_heartbeat_age(): int {
    $path = nppp_f2b_worker_hb_path();
    clearstatcache( true, $path );
    $mtime = @filemtime( $path );
    if ( ! $mtime ) {
        return PHP_INT_MAX;
    }
    return max( 0, time() - (int) $mtime );
}

function nppp_f2b_worker_read_pid(): int {
    $path = nppp_f2b_worker_pid_path();
    clearstatcache( true, $path );
    if ( ! @is_file( $path ) ) {
        return 0;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
    $pid = (int) trim( (string) @file_get_contents( $path ) );
    return $pid > 0 ? $pid : 0;
}

function nppp_f2b_worker_write_pid( int $pid ): void {
    if ( $pid <= 0 ) {
        return;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    @file_put_contents( nppp_f2b_worker_pid_path(), (string) $pid, LOCK_EX );
    clearstatcache( true, nppp_f2b_worker_pid_path() );
}

/**
 * Clear PID + heartbeat so the next caller sees a clean slate.
 *
 * Doesn't touch the lock file on purpose -- flock() locks an inode, so
 * deleting it would let two processes lock two different inodes and break
 * the single-flight guard. Only removed on deactivation, see
 * nppp_f2b_kill_worker().
 */
function nppp_f2b_worker_reset_state(): void {
    wp_delete_file( nppp_f2b_worker_pid_path() );
    wp_delete_file( nppp_f2b_worker_hb_path() );
    clearstatcache();
}

/**
 * Liveness check. posix_kill($pid, 0) is nearly free, which matters since
 * this runs on every cache-missing ban event. Falls back to the existing
 * ps-based probe if ext-posix isn't available.
 */
function nppp_f2b_pid_alive( int $pid ): bool {
    if ( $pid <= 0 ) {
        return false;
    }

    if ( function_exists( 'posix_kill' ) ) {
        if ( @posix_kill( $pid, 0 ) ) {
            return true;
        }
        // EPERM (1) means the process exists but belongs to another user.
        if ( function_exists( 'posix_get_last_error' ) && 1 === posix_get_last_error() ) {
            return true;
        }
        return false;
    }

    if ( function_exists( 'nppp_is_process_alive' ) ) {
        return (bool) nppp_is_process_alive( $pid );
    }

    return false;
}

function nppp_f2b_worker_is_running(): bool {
    $pid = nppp_f2b_worker_read_pid();
    if ( $pid <= 0 ) {
        return false;
    }
    if ( nppp_f2b_worker_heartbeat_age() > NPPP_F2B_WORKER_STALE_SECONDS ) {
        return false;
    }
    return nppp_f2b_pid_alive( $pid );
}

// ---------------------------------------------------------------------------
// Shell environment probe
//
// PHP_BINARY under PHP-FPM points at php-fpm, not a CLI binary, so it's
// only trusted when its basename is "php". Each candidate gets asked for
// its own SAPI before we cache the result.
// ---------------------------------------------------------------------------

function nppp_f2b_worker_env(): array {
    $cached = get_transient( NPPP_F2B_WORKER_ENV_KEY );
    if ( is_array( $cached ) && array_key_exists( 'php', $cached ) ) {
        return $cached;
    }

    $env = array( 'php' => '', 'nohup' => '' );

    if ( ! function_exists( 'shell_exec' ) ) {
        set_transient( NPPP_F2B_WORKER_ENV_KEY, $env, 10 * MINUTE_IN_SECONDS );
        return $env;
    }

    $candidates = array();

    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $which = trim( (string) shell_exec( 'command -v php 2>/dev/null' ) );
    if ( '' !== $which ) {
        $candidates[] = $which;
    }
    if ( defined( 'PHP_BINDIR' ) && '' !== PHP_BINDIR ) {
        $candidates[] = rtrim( PHP_BINDIR, '/' ) . '/php';
    }
    if ( defined( 'PHP_BINARY' ) && '' !== PHP_BINARY && 'php' === basename( PHP_BINARY ) ) {
        $candidates[] = PHP_BINARY;
    }

    foreach ( array_unique( $candidates ) as $bin ) {
        if ( ! @is_file( $bin ) || ! @is_executable( $bin ) ) {
            continue;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $sapi = trim( (string) shell_exec(
            escapeshellarg( $bin ) . ' -r ' . escapeshellarg( 'echo PHP_SAPI;' ) . ' 2>/dev/null'
        ) );
        if ( 'cli' === $sapi ) {
            $env['php'] = $bin;
            break;
        }
    }

    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $env['nohup'] = trim( (string) shell_exec( 'command -v nohup 2>/dev/null' ) );

    set_transient(
        NPPP_F2B_WORKER_ENV_KEY,
        $env,
        '' !== $env['php'] ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS
    );

    return $env;
}

// ---------------------------------------------------------------------------
// Spawn
// ---------------------------------------------------------------------------

/**
 * Make sure a worker is running for this site.
 *
 * Runs on the webhook path, so it stays cheap: a stat, a signal-0 probe,
 * a flock, and a shell_exec that returns as soon as the child is
 * backgrounded. No network calls.
 *
 * @param bool $force Skip the spawn throttle. Only used by the worker's own
 *                    handoff on shutdown -- it's already released ownership
 *                    and confirmed there's more work, so the throttle
 *                    (meant for concurrent requests) doesn't apply here.
 *                    flock() still prevents double-spawning either way.
 */
function nppp_f2b_maybe_spawn_worker( bool $force = false ): bool {
    if ( ! apply_filters( 'nppp_f2b_enable_cli_worker', true ) ) {
        return false;
    }
    if ( ! function_exists( 'shell_exec' ) ) {
        return false;
    }

    // Fast path: worker's already running, nothing more to do. Most events
    // in a burst hit this branch.
    if ( nppp_f2b_worker_is_running() ) {
        return true;
    }

    // Backup guard, see NPPP_F2B_SPAWN_TICK_KEY above. A worker always
    // outlives this TTL, so a handoff would clear it anyway -- but that's
    // coincidence, not a guarantee, hence $force.
    if ( ! $force && false !== get_transient( NPPP_F2B_SPAWN_TICK_KEY ) ) {
        return true;
    }

    $lock_path = nppp_get_runtime_file( NPPP_F2B_WORKER_LOCK_FILE );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $lock = @fopen( $lock_path, 'c' );

    // Couldn't open the lock file -- spawn anyway, the throttle above still
    // bounds this.
    if ( ! $lock ) {
        return nppp_f2b_spawn_worker_process();
    }

    // Someone else is already spawning. Their worker will pick up whatever
    // this request just queued.
    if ( ! @flock( $lock, LOCK_EX | LOCK_NB ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        @fclose( $lock );
        return true;
    }

    try {
        // Re-check under the lock.
        if ( nppp_f2b_worker_is_running() ) {
            return true;
        }
        return nppp_f2b_spawn_worker_process();
    } finally {
        @flock( $lock, LOCK_UN );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        @fclose( $lock );
    }
}

/**
 * Quote a string as a PHP single-quoted literal, for the -r bootstrap.
 *
 * Hand-rolled on purpose: var_export() gets flagged as path-disclosure, and
 * base64 reads as obfuscation. Only backslash and single quote need
 * escaping here, and the whole thing gets escapeshellarg()'d anyway.
 */
function nppp_f2b_php_literal( string $value ): string {
    return "'" . strtr( $value, array( '\\' => '\\\\', "'" => "\\'" ) ) . "'";
}

/**
 * Build the PHP snippet the worker runs.
 *
 * No worker script on disk on purpose -- a file under wp-content/plugins/
 * is reachable over HTTP and can't carry the usual ABSPATH guard (it has to
 * run before WordPress exists). Passing the code on the command line avoids
 * that entry point entirely.
 *
 * $_SERVER is seeded from home_url(), not the current request, so
 * ms-settings.php picks the right site on multisite without
 * switch_to_blog().
 */
function nppp_f2b_worker_bootstrap_code(): string {
    $parts = wp_parse_url( home_url( '/' ) );

    $host = ( isset( $parts['host'] ) && '' !== $parts['host'] ) ? $parts['host'] : 'localhost';
    if ( ! empty( $parts['port'] ) ) {
        $host .= ':' . (int) $parts['port'];
    }

    $path = ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';
    if ( '/' !== substr( $path, -1 ) ) {
        $path .= '/';
    }

    $code  = '$_SERVER["HTTP_HOST"]=' . nppp_f2b_php_literal( $host ) . ';';
    $code .= '$_SERVER["SERVER_NAME"]=$_SERVER["HTTP_HOST"];';
    $code .= '$_SERVER["REQUEST_URI"]=' . nppp_f2b_php_literal( $path ) . ';';
    $code .= '$_SERVER["REQUEST_METHOD"]="GET";';
    $code .= '$_SERVER["SCRIPT_NAME"]=' . nppp_f2b_php_literal( $path . 'index.php' ) . ';';
    $code .= '$_SERVER["SCRIPT_FILENAME"]=' . nppp_f2b_php_literal( ABSPATH . 'index.php' ) . ';';

    if ( isset( $parts['scheme'] ) && 'https' === $parts['scheme'] ) {
        $code .= '$_SERVER["HTTPS"]="on";';
    }

    $code .= 'define("NPPP_F2B_WORKER",true);';
    $code .= 'require ' . nppp_f2b_php_literal( ABSPATH . 'wp-load.php' ) . ';';
    $code .= 'if(function_exists("nppp_load_bootstrap")){nppp_load_bootstrap();}';
    $code .= 'if(function_exists("nppp_f2b_worker_run")){nppp_f2b_worker_run();}';

    return $code;
}

/**
 * Actually spawns the process. Don't call this directly -- go through
 * nppp_f2b_maybe_spawn_worker() so the single-flight guard applies.
 *
 * Nothing user-controlled reaches the command line: the bootstrap comes
 * from ABSPATH/home_url(), and the worker takes no arguments -- it queries
 * the queue itself.
 */
function nppp_f2b_spawn_worker_process(): bool {
    $env = nppp_f2b_worker_env();
    if ( '' === $env['php'] ) {
        return false;
    }

    // Arm the throttle before the process exists, not after.
    set_transient( NPPP_F2B_SPAWN_TICK_KEY, 1, NPPP_F2B_SPAWN_TICK_TTL );

    // Reserve the slot before the child exists, or a second request during
    // bootstrap could spawn a duplicate.
    nppp_f2b_worker_reset_state();
    nppp_f2b_worker_touch_heartbeat();

    $prefix = '' !== $env['nohup'] ? escapeshellarg( $env['nohup'] ) . ' ' : '';

    $command = $prefix
        . escapeshellarg( $env['php'] ) . ' -r '
        . escapeshellarg( nppp_f2b_worker_bootstrap_code() )
        . ' > /dev/null 2>&1 < /dev/null & echo $!';

    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $output = shell_exec( $command );

    $parts = explode( ' ', trim( (string) $output ) );
    $pid   = (int) trim( (string) end( $parts ) );

    if ( $pid <= 0 ) {
        // Spawn failed -- release the throttle so the next attempt isn't
        // blocked for no reason.
        nppp_f2b_worker_reset_state();
        delete_transient( NPPP_F2B_SPAWN_TICK_KEY );
        return false;
    }

    nppp_f2b_worker_write_pid( $pid );
    return true;
}

/**
 * Stop the worker. Only called on deactivation -- normally it exits on its
 * own once the queue's been empty for a while.
 */
function nppp_f2b_kill_worker(): bool {
    $pid = nppp_f2b_worker_read_pid();

    if ( $pid <= 0 || ! nppp_f2b_pid_alive( $pid ) ) {
        nppp_f2b_worker_reset_state();
        wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_LOCK_FILE ) );
        delete_transient( NPPP_F2B_SPAWN_TICK_KEY );
        return true;
    }

    if ( function_exists( 'posix_kill' ) && defined( 'SIGTERM' ) ) {
        @posix_kill( $pid, SIGTERM );
        usleep( 300000 );
    }

    if ( nppp_f2b_pid_alive( $pid ) && function_exists( 'shell_exec' ) ) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $kill = trim( (string) shell_exec( 'command -v kill 2>/dev/null' ) );
        if ( '' !== $kill ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
            shell_exec( escapeshellarg( $kill ) . ' -9 ' . (int) $pid . ' 2>/dev/null' );
            usleep( 200000 );
        }
    }

    $dead = ! nppp_f2b_pid_alive( $pid );
    nppp_f2b_worker_reset_state();

    // Safe to remove the lock file here -- deactivation is the only moment
    // nothing else could be spawning. Left alone otherwise, see
    // nppp_f2b_worker_reset_state().
    wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_LOCK_FILE ) );
    delete_transient( NPPP_F2B_SPAWN_TICK_KEY );

    return $dead;
}

// ---------------------------------------------------------------------------
// Queue access
//
// No separate job table -- "rdap_json IS NULL" on a ban row is the queue.
// Work is per IP, not per event: one lookup fills every pending row for
// that address in a single UPDATE.
// ---------------------------------------------------------------------------

/**
 * @param int   $limit       Max unique IPs to claim.
 * @param array $exclude_ips IPs to skip this round even though they're still
 *                            pending. Used within one worker run to step past
 *                            addresses that already failed, so they don't
 *                            block everything queued behind them (the claim
 *                            query is otherwise always MIN(id) ASC, so a
 *                            failed batch would just get reclaimed forever).
 */
function nppp_f2b_worker_claim_ips( int $limit, array $exclude_ips = array() ): array {
    global $wpdb;

    if ( $limit < 1 ) {
        $limit = 1;
    }

    $table = nppp_f2b_table_name();

    $exclude_ips = array_values( array_unique( array_filter(
        array_map( 'strval', $exclude_ips ),
        'strlen'
    ) ) );

    $exclude_sql = '';
    $args        = array( $table );

    if ( ! empty( $exclude_ips ) ) {
        $exclude_sql = ' AND ip NOT IN (' . implode( ', ', array_fill( 0, count( $exclude_ips ), '%s' ) ) . ')';
        $args        = array_merge( $args, $exclude_ips );
    }

    $args[] = $limit;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ip
             FROM %i
             WHERE event_type = 'ban' AND rdap_json IS NULL{$exclude_sql}
             GROUP BY ip
             ORDER BY MIN(id) ASC
             LIMIT %d",
            $args
        )
    );

    if ( ! is_array( $rows ) ) {
        return array();
    }

    return array_values( array_unique( array_map( 'strval', $rows ) ) );
}

/**
 * Write one RDAP profile to every pending row for this IP.
 *
 * Writes even when the profile is empty, on purpose -- it clears the row
 * so the worker can't loop on it forever. A genuine "nothing found" result
 * is retried via the 5-minute negative cache; total upstream failures get
 * a few retries first, see nppp_f2b_rdap_defer_attempt().
 */
function nppp_f2b_worker_write_result( string $ip, array $rdap ): int {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (int) $wpdb->query(
        $wpdb->prepare(
            "UPDATE %i
             SET rdap_json = %s
             WHERE ip = %s AND event_type = 'ban' AND rdap_json IS NULL",
            $table,
            wp_json_encode( $rdap ),
            $ip
        )
    );
}

// Existence check for the reconciliation cron. Both ends of the range are
// explicit so the index gets used. The 120s floor stops the cron from
// racing a worker that's already on it.
function nppp_f2b_has_pending_enrichment(): bool {
    global $wpdb;

    $table = nppp_f2b_table_name();
    $now   = time();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (bool) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM %i
             WHERE event_type = 'ban'
               AND created_at >= %s
               AND created_at <  %s
               AND rdap_json IS NULL
             LIMIT 1",
            $table,
            gmdate( 'Y-m-d H:i:s', $now - ( 7 * DAY_IN_SECONDS ) ),
            gmdate( 'Y-m-d H:i:s', $now - 120 )
        )
    );
}

// ---------------------------------------------------------------------------
// Parallel RIPE lookup
//
// The two upstream calls per IP used to run one after another, so one IP
// cost up to timeout+timeout. Running them together turns that into
// max(whois, abuse) instead.
// ---------------------------------------------------------------------------

/**
 * Decode one WpOrg\Requests response into a JSON array, or null.
 *
 * On a transport failure request_multiple() puts an Exception in the slot
 * instead of a response, so checking for the expected properties is the
 * safest test.
 */
function nppp_f2b_requests_json( $response ) {
    if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
        return null;
    }
    if ( 200 !== (int) $response->status_code ) {
        return null;
    }
    if ( ! is_string( $response->body ) || '' === $response->body ) {
        return null;
    }
    return json_decode( $response->body, true );
}

/**
 * Resolve a batch of IPs, running every upstream call in parallel.
 *
 * Uses WordPress's bundled WpOrg\Requests library instead of raw cURL --
 * it's the only HTTP API in core that can run requests concurrently, and it
 * falls back to a sequential fsockopen transport if ext-curl is missing.
 * wp_remote_get() can't do this at all, it's strictly one request at a time.
 *
 * At most 4 IPs come in per call, so at most 8 requests are in flight --
 * right at RIPEstat's documented per-source concurrency limit.
 */
function nppp_f2b_lookup_ips_bulk( array $ips, array &$failed = array() ): array {
    $out     = array();
    $pending = array();
    $failed  = array();

    foreach ( $ips as $ip ) {
        $ip = (string) $ip;
        if ( '' === $ip ) {
            continue;
        }
        $cached = get_transient( nppp_f2b_rdap_cache_key( $ip ) );
        if ( is_array( $cached ) ) {
            $out[ $ip ] = $cached;
            continue;
        }
        $pending[] = $ip;
    }

    if ( empty( $pending ) ) {
        return $out;
    }

    // Requests should always be loaded, but don't assume -- fall back to
    // the serial path if it's somehow missing.
    if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) {
        foreach ( $pending as $ip ) {
            $out[ $ip ] = nppp_f2b_lookup_ip( $ip );
        }
        return $out;
    }

    $timeout = (int) apply_filters( 'nppp_f2b_rdap_timeout', 3 );
    if ( $timeout < 1 ) {
        $timeout = 1;
    }

    $requests = array();
    foreach ( $pending as $index => $ip ) {
        $requests[ 'w' . $index ] = array(
            'url'     => nppp_f2b_rdap_whois_url( $ip ),
            'type'    => 'GET',
            'headers' => array( 'Accept' => 'application/json' ),
        );
        $requests[ 'a' . $index ] = array(
            'url'     => nppp_f2b_rdap_abuse_url( $ip ),
            'type'    => 'GET',
            'headers' => array( 'Accept' => 'application/json' ),
        );
    }

    $options = array(
        'timeout'          => $timeout,
        'connect_timeout'  => $timeout,
        'follow_redirects' => false,
        'redirects'        => 0,
        'useragent'        => 'NPP-Fail2Ban-Monitor/' . ( defined( 'NPPP_PLUGIN_VERSION' ) ? NPPP_PLUGIN_VERSION : '1.0' ),
    );

    try {
        $responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
    } catch ( \Exception $nppp_f2b_requests_error ) {
        $responses = array();
    }

    foreach ( $pending as $index => $ip ) {
        $result   = nppp_f2b_rdap_blank_result();
        $answered = false;

        $whois = nppp_f2b_requests_json( $responses[ 'w' . $index ] ?? null );
        if ( null !== $whois ) {
            $answered = true;
            $result   = nppp_f2b_rdap_apply_whois( $whois, $result );
        }

        $abuse = nppp_f2b_requests_json( $responses[ 'a' . $index ] ?? null );
        if ( null !== $abuse ) {
            $answered = true;
            $result   = nppp_f2b_rdap_apply_abuse( $abuse, $result );
        }

        // Neither endpoint gave us a usable response -- that's an outage,
        // not an answer, so don't cache it. Caching would make the retry
        // below pointless.
        if ( ! $answered ) {
            $failed[ $ip ] = true;
            $out[ $ip ]    = $result;
            continue;
        }

        nppp_f2b_rdap_store_cache( $ip, $result );
        $out[ $ip ] = $result;
    }

    return $out;
}

/**
 * Should a total upstream failure for $ip get another try?
 *
 * True means defer -- leave the row NULL, stays queued. False means the
 * attempt budget is spent, so the caller writes the blank profile instead.
 *
 * Counter lives in a transient, not a DB column -- outages last minutes,
 * not days, this shouldn't need a schema change.
 */
function nppp_f2b_rdap_defer_attempt( string $ip ): bool {
    $key = NPPP_F2B_RDAP_FAIL_PREFIX . md5( $ip );

    $max = (int) apply_filters( 'nppp_f2b_rdap_max_attempts', 3 );
    if ( $max < 1 ) {
        $max = 1;
    }

    $attempts = (int) get_transient( $key ) + 1;

    if ( $attempts >= $max ) {
        delete_transient( $key );
        return false;
    }

    set_transient( $key, $attempts, NPPP_F2B_RDAP_FAIL_TTL );
    return true;
}

// ---------------------------------------------------------------------------
// Worker loop
// CLI only -- entry point for the `php -r` bootstrap built above.
// ---------------------------------------------------------------------------

function nppp_f2b_worker_run(): void {
    if ( 'cli' !== PHP_SAPI ) {
        return;
    }

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }
    if ( function_exists( 'ignore_user_abort' ) ) {
        ignore_user_abort( true );
    }

    $batch = (int) apply_filters( 'nppp_f2b_worker_batch', NPPP_F2B_WORKER_BATCH );
    if ( $batch < 1 ) {
        $batch = 1;
    }
    // Hard ceiling -- batch*2 requests must stay within RIPE's 8 concurrent
    // limit.
    if ( $batch > 4 ) {
        $batch = 4;
    }

    $max_runtime = (int) apply_filters( 'nppp_f2b_worker_max_runtime', NPPP_F2B_WORKER_MAX_RUNTIME );
    if ( $max_runtime < 30 ) {
        $max_runtime = 30;
    }

    // Confirm our own PID -- the spawner already recorded it, this just
    // makes it authoritative.
    if ( function_exists( 'getmypid' ) ) {
        nppp_f2b_worker_write_pid( (int) getmypid() );
    }
    nppp_f2b_worker_touch_heartbeat();

    $started    = time();
    $idle_since = 0;
    $seen       = array();
    $deferred   = array();
    $written    = 0;

    while ( true ) {
        if ( ( time() - $started ) >= $max_runtime ) {
            break;
        }

        nppp_f2b_worker_touch_heartbeat();

        // Skip addresses already deferred this run, see
        // nppp_f2b_worker_claim_ips().
        $ips = nppp_f2b_worker_claim_ips( $batch, array_keys( $deferred ) );

        if ( empty( $ips ) ) {
            if ( 0 === $idle_since ) {
                $idle_since = time();
            }
            if ( ( time() - $idle_since ) >= NPPP_F2B_WORKER_IDLE_SECONDS ) {
                break;
            }
            usleep( 250000 );
            continue;
        }

        $idle_since = 0;

        // Progress guard: if a write-back ever fails (read-only replica,
        // revoked grant, full disk) the same IPs keep coming back and we'd
        // spin on RIPE forever. Skip anything already handled this run, and
        // stop once a claim has nothing new.
        $fresh = array();
        foreach ( $ips as $ip ) {
            if ( ! isset( $seen[ $ip ] ) ) {
                $fresh[] = $ip;
            }
        }

        if ( empty( $fresh ) || count( $seen ) >= 5000 ) {
            break;
        }

        $failed  = array();
        $results = nppp_f2b_lookup_ips_bulk( $fresh, $failed );

        foreach ( $fresh as $ip ) {
            $seen[ $ip ] = true;

            // No response at all -- leave it NULL for another try, until
            // the attempt budget runs out.
            if ( isset( $failed[ $ip ] ) && nppp_f2b_rdap_defer_attempt( $ip ) ) {
                $deferred[ $ip ] = true;
                continue;
            }

            $rdap = isset( $results[ $ip ] ) && is_array( $results[ $ip ] )
                ? $results[ $ip ]
                : nppp_f2b_rdap_blank_result();

            $written += nppp_f2b_worker_write_result( $ip, $rdap );
        }

        // Cap how many addresses we skip past in one run. Excluding
        // deferred IPs stops a few bad ones from blocking the rest -- but
        // without a cap, a total outage would walk the whole backlog one
        // doomed request at a time. This keeps fast-fail behavior for a
        // real outage while still letting a few bad IPs get skipped.
        if ( count( $deferred ) >= ( NPPP_F2B_WORKER_BATCH * 3 ) ) {
            break;
        }
    }

    // Release ownership first, so a successor can actually claim it.
    nppp_f2b_worker_reset_state();

    // Handoff: covers two gaps the cron would otherwise take minutes to
    // notice -- exiting with the queue still full, or a row landing right
    // as this process was shutting down. Gated on $written so a stalled
    // run (see progress guard) can't chain successors forever.
    if ( $written > 0 && ! empty( nppp_f2b_worker_claim_ips( 1 ) ) ) {
        nppp_f2b_maybe_spawn_worker( true );
    }
}

// ---------------------------------------------------------------------------
// Reconciliation cron -- self-heal only, not the dispatch path.
// Covers two cases: a worker that died mid-burst and the site went quiet,
// or a host with shell_exec disabled entirely.
// ---------------------------------------------------------------------------

function nppp_f2b_schedule_worker_reconcile(): void {
    // Five minutes is enough for both cases above.
    if ( wp_next_scheduled( NPPP_F2B_WORKER_HOOK )
        && wp_get_schedule( NPPP_F2B_WORKER_HOOK ) !== 'every_5min_npp'
    ) {
        wp_clear_scheduled_hook( NPPP_F2B_WORKER_HOOK );
    }

    if ( ! wp_next_scheduled( NPPP_F2B_WORKER_HOOK ) ) {
        wp_schedule_event( time() + 300, 'every_5min_npp', NPPP_F2B_WORKER_HOOK );
    }
}

add_action( NPPP_F2B_WORKER_HOOK, 'nppp_f2b_worker_reconcile' );
function nppp_f2b_worker_reconcile(): void {
    if ( nppp_f2b_worker_is_running() ) {
        return;
    }

    if ( ! nppp_f2b_has_pending_enrichment() ) {
        return;
    }

    if ( nppp_f2b_maybe_spawn_worker() ) {
        return;
    }

    // Last resort for shell_exec-disabled hosts: a small bounded batch
    // right here in the cron request. Only place RDAP I/O still runs
    // inside PHP-FPM, capped to a few seconds per tick.
    $limit = (int) apply_filters( 'nppp_f2b_cron_inline_batch', 3 );
    if ( $limit < 1 ) {
        return;
    }
    if ( $limit > 5 ) {
        $limit = 5;
    }

    foreach ( nppp_f2b_worker_claim_ips( $limit ) as $ip ) {
        nppp_f2b_worker_write_result( $ip, nppp_f2b_lookup_ip( $ip ) );
    }
}
