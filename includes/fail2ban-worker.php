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
        if ( nppp_f2b_log_gate( 'spawn_no_shell', DAY_IN_SECONDS ) > 0 ) {
            nppp_f2b_log( 'ERROR', __( 'Cannot spawn the enrichment worker: shell_exec is disabled. Enrichment falls back to the inline cron batch.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
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
    $tick_age = time() - (int) get_option( NPPP_F2B_SPAWN_TICK_KEY, 0 );
    if ( ! $force && $tick_age >= 0 && $tick_age < NPPP_F2B_SPAWN_TICK_TTL ) {
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

    // Registered before wp-load.php on purpose. Core's fatal-error handler is
    // also a shutdown function and it wp_die()s, and PHP stops running the
    // remaining shutdown functions once one of them exits. Registering here
    // puts ours ahead of it, so a worker fatal still gets logged.
    $code .= 'register_shutdown_function(function(){if(function_exists("nppp_f2b_worker_on_shutdown")){nppp_f2b_worker_on_shutdown();}});';
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
        if ( nppp_f2b_log_gate( 'spawn_no_php', DAY_IN_SECONDS ) > 0 ) {
            nppp_f2b_log( 'ERROR', __( 'Cannot spawn the enrichment worker: no PHP CLI binary found (checked PATH, PHP_BINDIR and PHP_BINARY).', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
        return false;
    }

    // Arm the throttle before the process exists, not after.
    update_option( NPPP_F2B_SPAWN_TICK_KEY, time(), false );

    // Whatever the previous worker left behind tells us how it ended. A clean
    // exit removes the PID file, and so does a worker that caught its own
    // fatal, so a leftover PID here means it was killed silently (SIGKILL, OOM
    // killer, host restart) or it has hung.
    $prev_pid = nppp_f2b_worker_read_pid();
    if ( $prev_pid > 0 ) {
        if ( ! nppp_f2b_pid_alive( $prev_pid ) ) {
            $died = nppp_f2b_log_gate( 'worker_died', 5 * MINUTE_IN_SECONDS, true );
            if ( $died > 0 ) {
                nppp_f2b_log(
                    'ERROR',
                    sprintf(
                        /* translators: %1$d: process ID of the previous worker; %2$d: number of times this was seen since the last report. */
                        __( 'Previous worker (PID %1$d) died without a clean exit (killed, out of memory or host restart); %2$d occurrence(s) since the last report.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $prev_pid,
                        $died
                    )
                );
            }
        } else {
            $hb_age = nppp_f2b_worker_heartbeat_age();
            if ( $hb_age > NPPP_F2B_WORKER_STALE_SECONDS && nppp_f2b_log_gate( 'worker_stale', 5 * MINUTE_IN_SECONDS ) > 0 ) {
                $hb_desc = ( PHP_INT_MAX === $hb_age )
                    ? __( 'missing', 'fastcgi-cache-purge-and-preload-nginx' )
                    : sprintf(
                        /* translators: %d: heartbeat age in seconds. */
                        __( 'stale (%ds old)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $hb_age
                    );
                nppp_f2b_log(
                    'WARNING',
                    sprintf(
                        /* translators: %1$d: worker process ID; %2$s: heartbeat status ("missing" or "stale (Ns old)"). */
                        __( 'Worker PID %1$d is still alive but its heartbeat is %2$s; declared dead and replaced.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $prev_pid,
                        $hb_desc
                    )
                );
            }
        }
    }

    // Without a writable runtime dir the PID/heartbeat files cannot be kept,
    // so only the spawn throttle guards against duplicate workers.
    $runtime_dir = dirname( nppp_f2b_worker_pid_path() );
    if ( ( ! is_dir( $runtime_dir ) || ! is_writable( $runtime_dir ) )
        && nppp_f2b_log_gate( 'spawn_dir', DAY_IN_SECONDS ) > 0
    ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %s: filesystem path to the runtime directory. */
                __( 'Runtime directory is not writable, worker PID/heartbeat files cannot be kept: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $runtime_dir
            )
        );
    }

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
        delete_option( NPPP_F2B_SPAWN_TICK_KEY );
        if ( nppp_f2b_log_gate( 'spawn_bad_pid', DAY_IN_SECONDS ) > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    /* translators: %s: raw output from the shell command used to spawn the worker. */
                    __( 'Worker spawn returned no valid PID (shell output: %s).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    trim( (string) $output )
                )
            );
        }
        return false;
    }

    nppp_f2b_worker_write_pid( $pid );

    // Catch a child that dies straight away (unusable binary or -r bootstrap).
    // Only the request that actually spawns pays for this pause, and a worker
    // that got past PHP start-up is still alive after it.
    usleep( 100000 );
    if ( ! nppp_f2b_pid_alive( $pid ) ) {
        nppp_f2b_worker_reset_state();
        $dead = nppp_f2b_log_gate( 'spawn_dead', 5 * MINUTE_IN_SECONDS, true );
        if ( $dead > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    /* translators: %1$d: process ID; %2$d: number of times seen since the last report; %3$s: path to the PHP CLI binary. */
                    __( 'Worker (PID %1$d) exited right after spawn (%2$d since the last report); check that this PHP CLI binary runs: %3$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $pid,
                    $dead,
                    $env['php']
                )
            );
        }
        return false;
    }

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
        delete_option( NPPP_F2B_SPAWN_TICK_KEY );
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
    delete_option( NPPP_F2B_SPAWN_TICK_KEY );

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
    // Same 7-day window as nppp_f2b_has_pending_enrichment(): lets the query
    // use the (event_type, created_at, ...) index range instead of visiting
    // every ban row in retention.
    $args        = array( $table, gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) ) );

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
             WHERE event_type = 'ban' AND created_at >= %s AND rdap_json IS NULL{$exclude_sql}
             GROUP BY ip
             ORDER BY MIN(id) ASC
             LIMIT %d",
            $args
        )
    );

    // Polled up to 4x/s while idle, so a broken DB must not log every time.
    if ( '' !== $wpdb->last_error && nppp_f2b_log_local_gate( 'db_claim', 5 * MINUTE_IN_SECONDS ) ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %s: database error message (not translated, comes from the DB driver). */
                __( 'Queue claim query failed: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $wpdb->last_error
            )
        );
    }

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

    // Profiles cached before country normalisation may still carry a long value.
    if ( isset( $rdap['country'] ) ) {
        $rdap['country'] = nppp_f2b_rdap_clean_country( $rdap['country'] );
    }

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE %i
             SET rdap_json = %s
             WHERE ip = %s AND event_type = 'ban' AND rdap_json IS NULL",
            $table,
            wp_json_encode( $rdap ),
            $ip
        )
    );

    if ( false === $updated && nppp_f2b_log_local_gate( 'db_writeback', 5 * MINUTE_IN_SECONDS ) ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %s: database error message (not translated, comes from the DB driver). */
                __( 'Write-back of an RDAP profile failed: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $wpdb->last_error
            )
        );
    }

    return (int) $updated;
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

/**
 * Pending queue size and oldest pending age. Feeds the log only. Same 7-day
 * window as the claim query, so the (event_type, rdap_json) index bounds it.
 *
 * @return array{ips:int,oldest_age:int} oldest_age is in seconds, 0 when empty.
 */
function nppp_f2b_queue_stats(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT ip) AS ips, MIN(created_at) AS oldest
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s AND rdap_json IS NULL",
            nppp_f2b_table_name(),
            gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) )
        ),
        ARRAY_A
    );

    if ( ! is_array( $row ) || empty( $row['oldest'] ) ) {
        return array( 'ips' => 0, 'oldest_age' => 0 );
    }

    $oldest = strtotime( $row['oldest'] . ' UTC' );

    return array(
        'ips'        => (int) $row['ips'],
        'oldest_age' => $oldest ? max( 0, time() - $oldest ) : 0,
    );
}

// WARN when the queue is backing up. Called from the 5-minute reconcile tick.
function nppp_f2b_log_queue_health(): void {
    $max_ips = (int) apply_filters( 'nppp_f2b_backlog_warn_ips', 100 );
    $max_age = (int) apply_filters( 'nppp_f2b_backlog_warn_age', 10 * MINUTE_IN_SECONDS );

    $stats = nppp_f2b_queue_stats();

    if ( $stats['ips'] < 1 || ( $stats['ips'] <= $max_ips && $stats['oldest_age'] <= $max_age ) ) {
        return;
    }

    if ( nppp_f2b_log_gate( 'queue_backlog', 30 * MINUTE_IN_SECONDS ) < 1 ) {
        return;
    }

    nppp_f2b_log(
        'WARNING',
        sprintf(
            /* translators: %1$d: number of pending IPs in the enrichment queue; %2$d: age in minutes of the oldest pending event. */
            __( 'Enrichment queue is backing up: %1$d pending IP(s), oldest pending event is %2$d min old.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $stats['ips'],
            (int) floor( $stats['oldest_age'] / 60 )
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
 * Short reason a WpOrg\Requests slot has no usable answer, for the log: the
 * transport error (curl timeout, DNS, TLS), an HTTP status, or a bad body.
 */
function nppp_f2b_requests_fail_hint( $response ): string {
    if ( $response instanceof \Exception ) {
        $message = trim( $response->getMessage() );
        return '' !== $message ? $message : get_class( $response );
    }
    if ( is_object( $response ) && isset( $response->status_code ) ) {
        return 200 === (int) $response->status_code
            ? __( 'unusable response body', 'fastcgi-cache-purge-and-preload-nginx' )
            : sprintf(
                /* translators: %d: HTTP status code returned by the upstream RDAP/abuse-contact service. */
                __( 'HTTP %d', 'fastcgi-cache-purge-and-preload-nginx' ),
                (int) $response->status_code
            );
    }
    return __( 'no response', 'fastcgi-cache-purge-and-preload-nginx' );
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
    // the serial path if it's somehow missing. Also use it when the site
    // restricts outbound HTTP: request_multiple() bypasses WP_Http, so only
    // the WP HTTP API honours WP_HTTP_BLOCK_EXTERNAL and WP_PROXY_*.
    if ( ! class_exists( '\WpOrg\Requests\Requests' ) || nppp_f2b_http_is_restricted() ) {
        foreach ( $pending as $ip ) {
            $answered   = true;
            $out[ $ip ] = nppp_f2b_lookup_ip( $ip, $answered );
            if ( ! $answered ) {
                $failed[ $ip ] = true; // same retry budget as the parallel path
            }
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
            // Keep the transport detail for the worker's log line. Callers
            // only test isset().
            $hint_w        = nppp_f2b_requests_fail_hint( $responses[ 'w' . $index ] ?? null );
            $hint_a        = nppp_f2b_requests_fail_hint( $responses[ 'a' . $index ] ?? null );
            $failed[ $ip ] = ( $hint_w === $hint_a ) ? $hint_w : $hint_w . ' / ' . $hint_a;
            $out[ $ip ]    = $result;
            continue;
        }

        nppp_f2b_rdap_store_cache( $ip, $result );
        $out[ $ip ] = $result;
    }

    return $out;
}

function nppp_f2b_http_is_restricted(): bool {
    if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
        return true;
    }
    return defined( 'WP_PROXY_HOST' ) && '' !== (string) WP_PROXY_HOST;
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

/**
 * Shutdown hook registered by the `php -r` bootstrap. Logs how a worker that
 * never reached its clean stop actually ended, and does nothing for a clean
 * exit. SIGKILL and the OOM killer skip shutdown functions entirely, so those
 * are picked up by the next spawn instead, see nppp_f2b_spawn_worker_process().
 */
function nppp_f2b_worker_on_shutdown(): void {
    $state = $GLOBALS['nppp_f2b_worker_state'] ?? null;

    if ( is_array( $state ) && ! empty( $state['clean'] ) ) {
        return;
    }

    $error  = error_get_last();
    $fatals = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
    $tail   = is_array( $state )
        ? sprintf(
            /* translators: %1$d: number of IPs processed this run; %2$d: run time in seconds. */
            __( ' (ips=%1$d, runtime=%2$ds)', 'fastcgi-cache-purge-and-preload-nginx' ),
            (int) $state['ips'],
            time() - (int) $state['started']
        )
        : __( ' (before the worker loop started)', 'fastcgi-cache-purge-and-preload-nginx' );

    if ( is_array( $error ) && in_array( $error['type'], $fatals, true ) ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %1$s: PHP fatal error message (not translated); %2$s: file path; %3$d: line number; %4$s: extra run info, may be empty. */
                __( 'Worker fatal: %1$s at %2$s:%3$d%4$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $error['message'],
                str_replace( ABSPATH, '', $error['file'] ),
                $error['line'],
                $tail
            )
        );
    } else {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %s: extra run info, may be empty, e.g. " (ips=3, runtime=12s)". */
                __( 'Worker exited without a clean stop and without a PHP fatal%s.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $tail
            )
        );
    }

    // Leave a clean slate, or the next spawn would report this same death again.
    nppp_f2b_worker_reset_state();
}

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

    $started     = time();
    $idle_since  = 0;
    $seen        = array();
    $deferred    = array();
    $written     = 0;
    $blanked     = 0;
    $batch_fails = 0;
    $stop_reason = 'unknown';

    // Read by nppp_f2b_worker_on_shutdown(), which logs a fatal that would
    // otherwise vanish into /dev/null.
    $GLOBALS['nppp_f2b_worker_state'] = array(
        'started' => $started,
        'ips'     => 0,
        'clean'   => false,
    );

    nppp_f2b_log(
        'INFO',
        sprintf(
            /* translators: %1$d: worker process ID; %2$d: batch size; %3$d: max runtime in seconds. */
            __( 'Worker started (PID %1$d, batch %2$d, max runtime %3$ds).', 'fastcgi-cache-purge-and-preload-nginx' ),
            function_exists( 'getmypid' ) ? (int) getmypid() : 0,
            $batch,
            $max_runtime
        )
    );

    while ( true ) {
        if ( ( time() - $started ) >= $max_runtime ) {
            $stop_reason = 'max_runtime';
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
                $stop_reason = 'idle';
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
            $stop_reason = empty( $fresh ) ? 'no_progress' : 'seen_cap';
            break;
        }

        $failed  = array();
        $results = nppp_f2b_lookup_ips_bulk( $fresh, $failed );

        // No IP in the batch got a usable answer: upstream outage, block or
        // timeout. The first one per run is logged with the HTTP code or curl
        // error, the stop line carries the total.
        if ( ! empty( $failed ) && empty( array_diff_key( $results, $failed ) ) ) {
            $batch_fails++;
            if ( 1 === $batch_fails ) {
                $first = reset( $failed );
                nppp_f2b_log(
                    'WARNING',
                    sprintf(
                        /* translators: %1$d: number of IPs in the batch; %2$s: failure reason (HTTP status or transport error, not translated). */
                        __( 'RDAP batch failed for all %1$d IP(s): %2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                        count( $fresh ),
                        is_string( $first ) ? $first : __( 'no response', 'fastcgi-cache-purge-and-preload-nginx' )
                    )
                );
            }
        }

        foreach ( $fresh as $ip ) {
            $seen[ $ip ] = true;

            // No response at all -- leave it NULL for another try, until
            // the attempt budget runs out.
            if ( isset( $failed[ $ip ] ) && nppp_f2b_rdap_defer_attempt( $ip ) ) {
                $deferred[ $ip ] = true;
                continue;
            }

            // Retry budget spent: a blank profile is about to be stored.
            if ( isset( $failed[ $ip ] ) ) {
                $blanked++;
            }

            $rdap = isset( $results[ $ip ] ) && is_array( $results[ $ip ] )
                ? $results[ $ip ]
                : nppp_f2b_rdap_blank_result();

            $written += nppp_f2b_worker_write_result( $ip, $rdap );
        }

        $GLOBALS['nppp_f2b_worker_state']['ips'] = count( $seen );

        // Cap how many addresses we skip past in one run. Excluding
        // deferred IPs stops a few bad ones from blocking the rest -- but
        // without a cap, a total outage would walk the whole backlog one
        // doomed request at a time. This keeps fast-fail behavior for a
        // real outage while still letting a few bad IPs get skipped.
        if ( count( $deferred ) >= ( NPPP_F2B_WORKER_BATCH * 3 ) ) {
            $stop_reason = 'deferred_cap';
            break;
        }
    }

    // Release ownership first, so a successor can actually claim it.
    nppp_f2b_worker_reset_state();

    // Stop summary: warnings first, then one INFO line with the totals.
    if ( 'deferred_cap' === $stop_reason ) {
        nppp_f2b_log(
            'WARNING',
            sprintf(
                /* translators: %d: number of IPs deferred to the next run. */
                __( 'Worker stopped early: %d IP(s) deferred after upstream failures (cap reached). They stay queued for the next run.', 'fastcgi-cache-purge-and-preload-nginx' ),
                count( $deferred )
            )
        );
    }
    if ( $blanked > 0 ) {
        nppp_f2b_log(
            'WARNING',
            sprintf(
                /* translators: %d: number of IPs for which the retry budget ran out and a blank profile was stored. */
                __( 'Retry budget spent for %d IP(s); blank profiles were stored for them.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $blanked
            )
        );
    }

    // Structured key=value diagnostic dump, not a sentence -- left untranslated
    // on purpose, same convention as nppp_ep_gate_log()'s "IP: ... | Action: ..."
    // lines. $stop_reason is a fixed machine token (idle, max_runtime, ...).
    $queue = nppp_f2b_queue_stats();
    nppp_f2b_log(
        'INFO',
        sprintf(
            'Worker stopped: reason=%s ips=%d rows=%d deferred=%d batch_failures=%d runtime=%ds peak_mem=%.1fMB backlog=%d',
            $stop_reason,
            count( $seen ),
            $written,
            count( $deferred ),
            $batch_fails,
            time() - $started,
            memory_get_peak_usage( true ) / 1048576,
            $queue['ips']
        )
    );

    // Handoff: covers two gaps the cron would otherwise take minutes to
    // notice -- exiting with the queue still full, or a row landing right
    // as this process was shutting down. Gated on $written so a stalled
    // run (see progress guard) can't chain successors forever.
    if ( $written > 0 && ! empty( nppp_f2b_worker_claim_ips( 1 ) ) ) {
        nppp_f2b_maybe_spawn_worker( true );
    }

    // Everything above ran, so the shutdown hook has nothing to report.
    $GLOBALS['nppp_f2b_worker_state']['clean'] = true;
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
    // Before the running-worker check: a worker that is alive but falling
    // behind is exactly the case worth a warning.
    nppp_f2b_log_queue_health();

    if ( nppp_f2b_worker_is_running() ) {
        return;
    }

    if ( ! nppp_f2b_has_pending_enrichment() ) {
        return;
    }

    if ( nppp_f2b_maybe_spawn_worker() ) {
        nppp_f2b_log( 'WARNING', __( 'Reconcile found pending events with no running worker; a worker spawn was requested.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        return;
    }

    if ( nppp_f2b_log_gate( 'inline_fallback', DAY_IN_SECONDS ) > 0 ) {
        nppp_f2b_log( 'WARNING', __( 'No worker can be spawned on this host; enrichment runs as a small inline batch from cron.', 'fastcgi-cache-purge-and-preload-nginx' ) );
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
        $answered = true;
        $rdap     = nppp_f2b_lookup_ip( $ip, $answered );
        if ( ! $answered && nppp_f2b_rdap_defer_attempt( $ip ) ) {
            continue;
        }
        nppp_f2b_worker_write_result( $ip, $rdap );
    }
}
