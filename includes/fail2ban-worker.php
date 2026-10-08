<?php
/**
 * Fail2ban RIPEstat enrichment worker for Nginx Cache Purge Preload
 * Description: Background consumer for the Fail2Ban event queue. The
 *              webhook just records the event and makes sure a worker is
 *              running; the worker itself claims pending IPs, looks up RIPEstat
 *              whois + abuse contacts in parallel (via WordPress's bundled
 *              WpOrg\Requests library), writes results back, and exits when
 *              idle.
 * Version: 2.1.8
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
// Held by the running worker for its whole life; separate from the short-lived
// spawn lock above.
if ( ! defined( 'NPPP_F2B_WORKER_RUN_LOCK_FILE' ) ) {
    define( 'NPPP_F2B_WORKER_RUN_LOCK_FILE', 'f2b_rdap_worker.run.lock' );
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

// A worker that holds the run lock but has not touched its heartbeat for this
// long is hung (DB/network stall, SIGSTOP) and is terminated so the queue can
// move again. Must stay well above STALE_SECONDS plus the longest legitimate
// stall (a 30 s HTTP timeout), or a busy worker could be killed.
if ( ! defined( 'NPPP_F2B_WORKER_KILL_SECONDS' ) ) {
    define( 'NPPP_F2B_WORKER_KILL_SECONDS', 180 );
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
// and "RIPEstat didn't respond" used to be treated the same and both
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

/**
 * Last state recorded by the worker, read back from the heartbeat file body.
 * Empty when the worker never wrote one (the plain touch() and the bare
 * timestamp fallback below are not notes).
 */
function nppp_f2b_worker_read_hb_note(): string {
    $path = nppp_f2b_worker_hb_path();
    clearstatcache( true, $path );
    if ( ! @is_file( $path ) ) {
        return '';
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
    $note = trim( (string) @file_get_contents( $path ) );
    return ( '' !== $note && ! ctype_digit( $note ) ) ? substr( $note, 0, 80 ) : '';
}

/**
 * Untranslated diagnostic tail for the "worker died" line: what the dead
 * worker last reported and how long ago it last checked in. Must run before
 * nppp_f2b_worker_reset_state(), which deletes the heartbeat file.
 */
function nppp_f2b_worker_death_detail(): string {
    $note = nppp_f2b_worker_read_hb_note();
    $beat = nppp_f2b_worker_heartbeat_age();

    $out = nppp_f2b_worker_output_tail();

    return sprintf(
        ' [last state: %1$s; last heartbeat %2$s ago]',
        '' !== $note ? $note : 'unknown',
        PHP_INT_MAX === $beat ? '?' : $beat . 's'
    ) . ( '' !== $out ? ' Worker output: ' . $out : '' );
}

/**
 * Where the worker's stdout/stderr go. Truncated on every spawn, deleted on a
 * clean stop. A worker that dies while WordPress boots (wrong PHP CLI build
 * without mysqli, a fatal in another plugin, memory limit) never reaches
 * nppp_f2b_worker_on_shutdown(); what it printed here is the only trace.
 */
function nppp_f2b_worker_out_path(): string {
    return nppp_get_runtime_file( 'f2b_worker.out' );
}

// First readable text of the worker's output (WordPress dies with a whole HTML page).
// Default 150: the "died" and "exited right after spawn" lines already carry
// ~200 chars of message and state, and nppp_f2b_log() cuts at 400.
function nppp_f2b_worker_output_tail( int $max_chars = 150 ): string {
    $path = nppp_f2b_worker_out_path();
    if ( ! @is_readable( $path ) || (int) @filesize( $path ) <= 0 ) {
        return '';
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $data = (string) @file_get_contents( $path, false, null, 0, 8192 );
    $data = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $data ) ) );

    return strlen( $data ) > $max_chars ? substr( $data, 0, $max_chars ) . '...' : $data;
}

// PHP's "Uncaught ..." message carries a full stack trace: keep the first line.
function nppp_f2b_worker_short_error( string $message ): string {
    $cut = strpos( $message, 'Stack trace:' );
    if ( false !== $cut ) {
        $message = substr( $message, 0, $cut );
    }

    return substr( trim( $message ), 0, 220 );
}

/**
 * Worker only. Records the stage it is entering: kept in the run state for
 * nppp_f2b_worker_on_shutdown() and written into the heartbeat, which is all
 * that survives a SIGKILL or OOM kill.
 */
function nppp_f2b_worker_mark( string $stage ): void {
    if ( ! isset( $GLOBALS['nppp_f2b_worker_state'] ) || ! is_array( $GLOBALS['nppp_f2b_worker_state'] ) ) {
        return;
    }
    $GLOBALS['nppp_f2b_worker_state']['stage'] = $stage;

    nppp_f2b_worker_touch_heartbeat(
        sprintf(
            'stage=%1$s ips=%2$d mem=%3$.0fMB',
            $stage,
            (int) ( $GLOBALS['nppp_f2b_worker_state']['ips'] ?? 0 ),
            memory_get_usage( true ) / 1048576
        )
    );
}

/**
 * Refresh the heartbeat mtime. A $note becomes the file body; writing bumps
 * the mtime exactly like touch(), so the age check is unaffected.
 */
function nppp_f2b_worker_touch_heartbeat( string $note = '' ): void {
    $path = nppp_f2b_worker_hb_path();
    if ( '' !== $note ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false !== @file_put_contents( $path, $note, LOCK_EX ) ) {
            clearstatcache( true, $path );
            return;
        }
    }
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
 * Release only this process's worker ownership. Never unlink the run lock:
 * flock() ownership belongs to its inode, and the OS releases it on a crash.
 */
function nppp_f2b_worker_release_run_lock(): void {
    $lock = $GLOBALS['nppp_f2b_worker_run_lock'] ?? null;
    if ( ! is_resource( $lock ) ) {
        return;
    }

    // Clear shared state while we still own the run lock.
    nppp_f2b_worker_reset_state();
    @flock( $lock, LOCK_UN );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    @fclose( $lock );
    unset( $GLOBALS['nppp_f2b_worker_run_lock'] );
}

/**
 * True when no process holds the run lock, null when the lock file cannot be
 * opened. The probe lock is released before returning, so a child spawned
 * afterwards can never inherit it.
 */
function nppp_f2b_worker_run_lock_is_free(): ?bool {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $probe = @fopen( nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE ), 'c' );
    if ( ! $probe ) {
        return null;
    }
    $free = @flock( $probe, LOCK_EX | LOCK_NB );
    if ( $free ) {
        @flock( $probe, LOCK_UN );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    @fclose( $probe );
    return (bool) $free;
}

/**
 * Raw /proc/<pid>/cmdline, NULs turned into spaces. Null when unreadable
 * (no /proc, or the process is gone).
 */
function nppp_f2b_worker_proc_cmdline( int $pid ): ?string {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $cmd = @file_get_contents( '/proc/' . $pid . '/cmdline' );
    return is_string( $cmd ) ? str_replace( "\0", ' ', $cmd ) : null;
}

/**
 * One-letter kernel state of a process (S sleeping, D disk wait, T stopped,
 * Z zombie ...) or '?' when /proc is unavailable. Tells a SIGSTOP or an I/O
 * stall from a plain sleep when a worker is hung.
 */
function nppp_f2b_worker_proc_state( int $pid ): string {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $stat = @file_get_contents( '/proc/' . $pid . '/stat' );
    $pos  = is_string( $stat ) ? strrpos( $stat, ')' ) : false;
    if ( false === $pos ) {
        return '?';
    }
    $state = substr( ltrim( substr( $stat, $pos + 1 ) ), 0, 1 );
    return '' !== $state ? $state : '?';
}

function nppp_f2b_worker_fmt_age( int $age ): string {
    return PHP_INT_MAX === $age ? '?' : $age . 's';
}

/**
 * PID that /proc/locks reports as holder of the run lock, 0 when unknown (no
 * /proc/locks, lock not held, or a PID namespace that hides it). Diagnostics
 * only: when the PID file names the wrong process (a rejected duplicate worker
 * overwrites it), this is the only way to see who really blocks the queue.
 */
function nppp_f2b_worker_lock_holder_pid(): int {
    $inode = @fileinode( nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE ) );
    if ( ! $inode ) {
        return 0;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $locks = @file_get_contents( '/proc/locks' );
    if ( ! is_string( $locks ) || '' === $locks ) {
        return 0;
    }
    foreach ( explode( "\n", $locks ) as $line ) {
        if ( preg_match( '/FLOCK\s+\S+\s+\S+\s+(\d+)\s+[0-9a-f]+:[0-9a-f]+:(\d+)\s/i', $line, $m ) && (int) $m[2] === (int) $inode ) {
            return (int) $m[1];
        }
    }
    return 0;
}

/**
 * Watchdog could not act on a worker that is past the kill threshold. This is
 * the most dangerous silence in the whole pipeline: a hung worker that cannot
 * be removed blocks the queue indefinitely. Gated, because every spawn attempt
 * would otherwise repeat it.
 *
 * Untranslated key=value line, same convention as the "Worker stopped" line.
 * $reason: no_pid, no_posix, no_proc, pid_gone, cmdline_mismatch, lock_held.
 */
function nppp_f2b_worker_log_unkillable( string $reason, int $pid, int $hb_age, string $extra = '' ): void {
    $seen = nppp_f2b_log_gate( 'worker_unkillable', 5 * MINUTE_IN_SECONDS, true );
    if ( $seen < 1 ) {
        return;
    }
    $holder = nppp_f2b_worker_lock_holder_pid();
    nppp_f2b_log(
        'ERROR',
        sprintf(
            'Hung worker cannot be terminated: pid=%d hb_age=%s kill_after=%ds reason=%s%s lock_holder=%s occurrences=%d',
            $pid,
            nppp_f2b_worker_fmt_age( $hb_age ),
            NPPP_F2B_WORKER_KILL_SECONDS,
            $reason,
            $extra,
            $holder > 0 ? (string) $holder : '?',
            $seen
        )
    );
}

/**
 * Watchdog. The run lock holder is alive, but if its heartbeat is older than
 * NPPP_F2B_WORKER_KILL_SECONDS it is hung and blocks the queue forever.
 * Terminates it and reports whether the run lock is free afterwards.
 *
 * The PID file alone is never trusted: the process must be a Linux process
 * whose command line carries the worker bootstrap, otherwise nothing is
 * signalled. SIGKILL is the fallback because a stopped process cannot act on
 * SIGTERM. The kernel drops the flock when the process dies.
 *
 * Every outcome past the threshold is logged: a kill is an incident, and a
 * failed kill is the one case where the queue stays blocked with no other
 * trace. A heartbeat below the threshold is the normal case and stays silent.
 * After a confirmed kill the PID/heartbeat files are cleared here, otherwise
 * the next spawn would report the intentional kill as "died without a clean
 * exit (killed, out of memory ...)".
 */
function nppp_f2b_worker_terminate_hung(): bool {
    $hb_age = nppp_f2b_worker_heartbeat_age();
    if ( $hb_age <= NPPP_F2B_WORKER_KILL_SECONDS ) {
        return false;
    }

    $pid = nppp_f2b_worker_read_pid();
    if ( $pid <= 0 ) {
        nppp_f2b_worker_log_unkillable( 'no_pid', 0, $hb_age );
        return false;
    }
    if ( ! function_exists( 'posix_kill' ) ) {
        nppp_f2b_worker_log_unkillable( 'no_posix', $pid, $hb_age );
        return false;
    }
    // SIGTERM/SIGKILL are constants of the pcntl extension, not of posix. Debian
    // and Ubuntu ship pcntl for the CLI only, so under PHP-FPM (the webhook and
    // wp-cron paths that reach this function) they are undefined even though
    // posix_kill() works. The numbers are fixed on Linux, the only platform
    // with the /proc check below.
    $sigterm = defined( 'SIGTERM' ) ? SIGTERM : 15;
    $sigkill = defined( 'SIGKILL' ) ? SIGKILL : 9;

    $cmd = nppp_f2b_worker_proc_cmdline( $pid );
    if ( null === $cmd ) {
        // No /proc at all (not Linux), or the PID file names a process that no
        // longer exists while something else still holds the run lock.
        nppp_f2b_worker_log_unkillable( @is_dir( '/proc/self' ) ? 'pid_gone' : 'no_proc', $pid, $hb_age );
        return false;
    }
    if ( false === strpos( $cmd, 'nppp_f2b_worker_run' ) ) {
        // PID reuse or a stale PID file. Only the binary name is logged, never
        // the arguments of an unrelated process.
        $bin = basename( (string) strtok( trim( $cmd ), ' ' ) );
        nppp_f2b_worker_log_unkillable( 'cmdline_mismatch', $pid, $hb_age, ' proc=' . substr( $bin, 0, 40 ) );
        return false;
    }

    // Read before signalling: the heartbeat note says where the worker stopped,
    // the process state says why (T stopped, D I/O wait, S sleeping).
    $note       = nppp_f2b_worker_read_hb_note();
    $proc_state = nppp_f2b_worker_proc_state( $pid );

    $signal = 'SIGTERM';
    @posix_kill( $pid, $sigterm );
    usleep( 300000 );
    if ( true !== nppp_f2b_worker_run_lock_is_free() ) {
        $signal = 'SIGTERM+SIGKILL';
        @posix_kill( $pid, $sigkill );
        usleep( 300000 );
    }

    if ( true === nppp_f2b_worker_run_lock_is_free() ) {
        nppp_f2b_worker_reset_state();
        nppp_f2b_log(
            'WARNING',
            sprintf(
                'Hung worker terminated: pid=%d hb_age=%s kill_after=%ds proc_state=%s last_state="%s" signal=%s lock_freed=yes',
                $pid,
                nppp_f2b_worker_fmt_age( $hb_age ),
                NPPP_F2B_WORKER_KILL_SECONDS,
                $proc_state,
                '' !== $note ? $note : 'unknown',
                $signal
            )
        );
        return true;
    }

    nppp_f2b_worker_log_unkillable(
        'lock_held',
        $pid,
        $hb_age,
        sprintf( ' signal=%s proc_state=%s last_state="%s"', $signal, nppp_f2b_worker_proc_state( $pid ), '' !== $note ? $note : 'unknown' )
    );
    return false;
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

    // Without a spawn lock, do not race another parent's PID/state writes.
    // Reconciliation's inline fallback picks the work up later.
    if ( ! $lock ) {
        // Own gate key: 'spawn_dir' belongs to the unwritable-directory notice in
        // nppp_f2b_spawn_worker_process(); sharing it would let one hide the other
        // for a whole day.
        if ( nppp_f2b_log_gate( 'spawn_dir_lock', DAY_IN_SECONDS ) > 0 ) {
            $nppp_f2b_dir = dirname( $lock_path );
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    /* translators: %s: filesystem path of the worker spawn lock file. */
                    __( 'Worker spawn lock file cannot be opened, so no worker was started: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $lock_path
                ) . sprintf(
                    ' [dir_exists=%s dir_writable=%s]',
                    is_dir( $nppp_f2b_dir ) ? 'yes' : 'no',
                    is_writable( $nppp_f2b_dir ) ? 'yes' : 'no' // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
                )
            );
        }
        return false;
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

        // The heartbeat is a hint, the run lock is the proof. A live worker
        // with a stale heartbeat is still the owner and is not replaced, unless
        // it has been silent long enough to be hung (watchdog below).
        $free = nppp_f2b_worker_run_lock_is_free();
        if ( null === $free ) {
            if ( nppp_f2b_log_gate( 'spawn_run_lock', DAY_IN_SECONDS ) > 0 ) {
                nppp_f2b_log(
                    'ERROR',
                    sprintf(
                        /* translators: %s: filesystem path of the worker run lock file. */
                        __( 'Worker run lock file cannot be opened, so no worker was started and a hung worker cannot be detected: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                        nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE )
                    )
                );
            }
            return false;
        }
        if ( ! $free && ! nppp_f2b_worker_terminate_hung() ) {
            // A worker still owns the queue.
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
                    ) . nppp_f2b_worker_death_detail()
                );
            }
        } else {
            // Spawning is only reached with the run lock free, so this live PID
            // does not own the worker lock: a stale PID file, PID reuse, or a
            // process that inherited the lock descriptor. Nothing is killed.
            $hb_age = nppp_f2b_worker_heartbeat_age();
            if ( $hb_age > NPPP_F2B_WORKER_STALE_SECONDS && nppp_f2b_log_gate( 'worker_stale', 5 * MINUTE_IN_SECONDS ) > 0 ) {
                $hb_desc = ( PHP_INT_MAX === $hb_age )
                    ? __( 'missing', 'fastcgi-cache-purge-and-preload-nginx' )
                    : sprintf(
                        /* translators: %d: heartbeat age in seconds. */
                        __( 'stale (%ds old)', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $hb_age
                    );
                $nppp_f2b_cmd = nppp_f2b_worker_proc_cmdline( $prev_pid );
                nppp_f2b_log(
                    'WARNING',
                    sprintf(
                        /* translators: %1$d: process ID from the PID file; %2$s: heartbeat status ("missing" or "stale (Ns old)"). */
                        __( 'PID file points to a live process (PID %1$d) that does not own the worker lock and whose heartbeat is %2$s; the entry is ignored and a new worker is started.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $prev_pid,
                        $hb_desc
                    ) . sprintf(
                        ' [proc_state=%s worker_cmdline=%s]',
                        nppp_f2b_worker_proc_state( $prev_pid ),
                        null === $nppp_f2b_cmd ? '?' : ( false !== strpos( $nppp_f2b_cmd, 'nppp_f2b_worker_run' ) ? 'yes' : 'no' )
                    )
                );
            }
        }
    }

    // Without a writable runtime dir the PID/heartbeat files cannot be kept,
    // so only the spawn throttle guards against duplicate workers.
    $runtime_dir = dirname( nppp_f2b_worker_pid_path() );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
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
        . ' > ' . escapeshellarg( nppp_f2b_worker_out_path() ) . ' 2>&1 < /dev/null & echo $!';

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
                ) . ( '' !== ( $nppp_f2b_out = nppp_f2b_worker_output_tail() ) ? ' Worker output: ' . $nppp_f2b_out : '' )
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
        wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE ) );
        delete_option( NPPP_F2B_SPAWN_TICK_KEY );
        return true;
    }

    // The PID file is untrusted (stale file / PID reuse, and it sits in a
    // web-writable directory). Never signal a process that is not our worker.
    $nppp_f2b_cmd = nppp_f2b_worker_proc_cmdline( $pid );
    if ( null === $nppp_f2b_cmd ) {
        nppp_f2b_log( 'WARNING', sprintf( 'Deactivation: cannot verify PID %d (no /proc); nothing was signalled.', $pid ) );
        return false;
    }
    if ( false === strpos( $nppp_f2b_cmd, 'nppp_f2b_worker_run' ) ) {
        // The worker is gone and its PID was reused by a stranger: clean up only.
        nppp_f2b_worker_reset_state();
        wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_LOCK_FILE ) );
        wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE ) );
        delete_option( NPPP_F2B_SPAWN_TICK_KEY );
        nppp_f2b_log( 'INFO', sprintf( 'Deactivation: PID %d from the PID file is not an NPP worker; nothing was signalled.', $pid ) );
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

    // Only tear down state once the worker is confirmed dead. If it survived
    // SIGTERM+SIGKILL, keep the PID file (ownership evidence) and the lock
    // inodes: unlinking them under a live worker lets a second worker start
    // beside it after reactivation.
    if ( $dead ) {
        nppp_f2b_worker_reset_state();
        wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_LOCK_FILE ) );
        wp_delete_file( nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE ) );
        delete_option( NPPP_F2B_SPAWN_TICK_KEY );
    }

    if ( $dead ) {
        nppp_f2b_log(
            'INFO',
            sprintf(
                /* translators: %d: process ID of the worker. */
                __( 'Worker (PID %d) terminated on plugin deactivation.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $pid
            )
        );
    } else {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %d: process ID of the worker. */
                __( 'Worker (PID %d) could not be terminated on plugin deactivation.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $pid
            )
        );
    }

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
 * @param string|null $error Out-param: DB error text, '' when the query
 *                           worked. A failed query and an empty queue both
 *                           return array(); this is how the caller tells
 *                           them apart.
 */
function nppp_f2b_worker_claim_ips( int $limit, array $exclude_ips = array(), ?string &$error = null ): array {
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

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table; {$exclude_sql} is built only from count($exclude_ips) '%s' placeholders and every value is bound through prepare() via $args
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
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Handed back to the caller, so a failed query is never mistaken for an
    // empty queue.
    $error = (string) $wpdb->last_error;

    // Polled up to 4x/s while idle, so a broken DB must not log every time.
    if ( '' !== $error && nppp_f2b_log_local_gate( 'db_claim', 5 * MINUTE_IN_SECONDS ) ) {
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
 * Skip cooling-down IPs without spending a network batch on them.
 * Known cooling IPs are excluded in the claim SQL itself (one query, however
 * many are waiting); $waiting only catches the ones the list missed and
 * persists within a worker run so later claims do not rescan them.
 * The 5000-IP scan bound matches the worker's existing per-run safety cap.
 */
function nppp_f2b_worker_claim_ready_ips( int $limit, array $exclude_ips = array(), ?string &$error = null, array &$waiting = array() ): array {
    $ready = array();
    $skip  = array_fill_keys( array_merge( $exclude_ips, array_keys( $waiting ), array_keys( nppp_f2b_rdap_cooling_get() ) ), true );
    $limit = max( 1, $limit );
    $error = '';
    $scanned = count( $waiting );

    while ( count( $ready ) < $limit && $scanned < 5000 ) {
        $ips = nppp_f2b_worker_claim_ips( min( 32, 5000 - $scanned ), array_keys( $skip ), $error );
        if ( '' !== $error ) {
            return array();
        }
        if ( empty( $ips ) ) {
            break;
        }
        foreach ( $ips as $ip ) {
            $scanned++;
            $skip[ $ip ] = true;
            if ( nppp_f2b_rdap_retry_waiting( $ip ) ) {
                $waiting[ $ip ] = true;
                continue;
            }
            $ready[] = $ip;
            if ( count( $ready ) >= $limit ) {
                break;
            }
        }
    }
    return $ready;
}

/**
 * Write one RDAP profile to every pending row for this IP.
 *
 * Call only for a finished lookup: complete (including a valid empty result)
 * or one the retry budget gave up on. Incomplete lookups that still have
 * retries left stay NULL and are retried by the worker/cron.
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
                __( 'Write-back of a RIPEstat enrichment profile failed: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
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
 * Pending queue size and oldest pending age. Feeds the log and the worker's
 * shutdown handoff decision. Same 7-day window as the claim query, so the
 * (event_type, rdap_json) index bounds it.
 *
 * Keep the WHERE clause identical to nppp_f2b_worker_claim_ips(): the handoff
 * relies on "ips > 0" meaning "a claim would find something".
 *
 * @param array $exclude_ips IPs to leave out (cooling-down ones, for the health
 *                           warning). Default: none, same WHERE as the claim.
 * @return array{ips:int,oldest_age:int} oldest_age is in seconds, 0 when empty.
 *                                       ips is -1 when the query itself failed
 *                                       (unknown, not "empty").
 */
function nppp_f2b_queue_stats( array $exclude_ips = array() ): array {
    global $wpdb;

    $args        = array( nppp_f2b_table_name(), gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) ) );
    $exclude_sql = '';
    $exclude_ips = array_values( array_filter( array_map( 'strval', $exclude_ips ), 'strlen' ) );
    if ( ! empty( $exclude_ips ) ) {
        $exclude_sql = ' AND ip NOT IN (' . implode( ', ', array_fill( 0, count( $exclude_ips ), '%s' ) ) . ')';
        $args        = array_merge( $args, $exclude_ips );
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table; {$exclude_sql} is built only from count($exclude_ips) '%s' placeholders and every value is bound through prepare() via $args
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT ip) AS ips, MIN(created_at) AS oldest
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s AND rdap_json IS NULL{$exclude_sql}",
            $args
        ),
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    // An aggregate without GROUP BY always returns a row, so null is an error.
    if ( null === $row ) {
        return array( 'ips' => -1, 'oldest_age' => 0 );
    }

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

    // IPs waiting out a retry gap are expected, not a backlog.
    $cooling = nppp_f2b_rdap_cooling_get();
    $stats   = nppp_f2b_queue_stats( array_keys( $cooling ) );

    // ips = -1 is a failed query, not an empty queue; without this the backlog
    // check below would read a broken database as a healthy one.
    if ( $stats['ips'] < 0 ) {
        global $wpdb;
        if ( nppp_f2b_log_gate( 'queue_stats_fail', 30 * MINUTE_IN_SECONDS ) > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    'Queue statistics query failed, backlog cannot be assessed: db_error="%s"',
                    substr( trim( (string) $wpdb->last_error ), 0, 80 )
                )
            );
        }
        return;
    }

    // Cooling IPs are excluded from the backlog above and reported nowhere
    // else: one line every 6 hours while any are waiting. Untranslated key=value.
    if ( ! empty( $cooling ) && nppp_f2b_log_gate( 'rdap_cooling', 6 * HOUR_IN_SECONDS ) > 0 ) {
        $now = time();
        nppp_f2b_log(
            'INFO',
            sprintf(
                'RIPEstat retry queue: cooling=%d next_retry_in=%ds last_retry_in=%ds ready_pending=%d',
                count( $cooling ),
                max( 0, (int) min( $cooling ) - $now ),
                max( 0, (int) max( $cooling ) - $now ),
                $stats['ips']
            )
        );
    }

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

/**
 * The claim query and queue_stats() both bound themselves to the last 7 days
 * (index-range optimisation, see their comments). Anything that ages past
 * that line while still un-enriched becomes invisible to the whole pipeline
 * -- never claimed again, never counted as backlog -- until retention
 * cleanup deletes it, silently, up to ~83 days later. Gated to once a day;
 * this is a slow-moving problem, not a per-tick one.
 */
function nppp_f2b_log_stale_abandoned(): void {
    if ( nppp_f2b_log_gate( 'stale_abandoned', DAY_IN_SECONDS ) < 1 ) {
        return;
    }

    global $wpdb;
    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $stale = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE event_type = 'ban' AND rdap_json IS NULL AND created_at < %s",
            $table,
            gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) )
        )
    );

    if ( $stale > 0 ) {
        nppp_f2b_log(
            'WARNING',
            sprintf(
                /* translators: %d: number of ban events older than 7 days that were never enriched and are now outside the enrichment window. */
                __( '%d ban event(s) older than 7 days still have no RIPEstat enrichment data; they are outside the enrichment window and will stay blank until retention cleanup removes them.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $stale
            )
        );
    }
}

// ---------------------------------------------------------------------------
// Parallel RIPEstat lookup
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
function nppp_f2b_requests_json( $response, string $field ) {
    if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
        return null;
    }
    if ( 200 !== (int) $response->status_code ) {
        return null;
    }
    if ( ! is_string( $response->body ) || '' === $response->body ) {
        return null;
    }

    $body = json_decode( $response->body, true );
    return nppp_f2b_rdap_response_is_valid( $body, $field ) ? $body : null;
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
                /* translators: %d: HTTP status code returned by a RIPEstat API endpoint. */
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
 *
 * @param array $failed Out-param: ip => failure hint string for every IP that
 *                      still lacks a validated endpoint answer (plain true
 *                      on the serial fallback path).
 * @param array $stats  Out-param, diagnostics for the worker's stop line:
 *                      cached (transient hits), net (IPs sent to RIPEstat), ms
 *                      (wall time of the network round), partial (IPs where only
 *                      one of whois/abuse answered), hints (reason => count;
 *                      per failed endpoint response on the parallel path,
 *                      partial ones included, per failed IP on the serial one).
 */
function nppp_f2b_lookup_ips_bulk( array $ips, array &$failed = array(), array &$stats = array() ): array {
    $out     = array();
    $pending = array();
    $failed  = array();
    $stats   = array( 'cached' => 0, 'net' => 0, 'ms' => 0, 'partial' => 0, 'hints' => array(), 'waiting' => array() );

    foreach ( $ips as $ip ) {
        $ip = (string) $ip;
        if ( '' === $ip ) {
            continue;
        }
        if ( ! nppp_f2b_ip_is_public( $ip ) ) {
            $out[ $ip ] = nppp_f2b_rdap_blank_result();
            continue;
        }
        $cached = get_transient( nppp_f2b_rdap_cache_key( $ip ) );
        if ( is_array( $cached ) ) {
            $out[ $ip ] = $cached;
            $stats['cached']++;
            continue;
        }
        if ( nppp_f2b_rdap_retry_waiting( $ip ) ) {
            $state = nppp_f2b_rdap_work_get( $ip );
            $out[ $ip ] = $state['result'];
            $failed[ $ip ] = 'retry waiting';
            $stats['waiting'][ $ip ] = true;
            continue;
        }
        $pending[] = $ip;
    }

    if ( empty( $pending ) ) {
        return $out;
    }

    $stats['net'] = count( $pending );

    // Requests should always be loaded, but don't assume -- fall back to
    // the serial path if it's somehow missing. Also use it when the site
    // restricts outbound HTTP: request_multiple() bypasses WP_Http, so only
    // the WP HTTP API honours WP_HTTP_BLOCK_EXTERNAL and WP_PROXY_*.
    if ( ! class_exists( '\WpOrg\Requests\Requests' ) || nppp_f2b_http_is_restricted() ) {
        $serial_start = microtime( true );
        foreach ( $pending as $ip ) {
            $answered   = true;
            $attempted = false;
            $out[ $ip ] = nppp_f2b_lookup_ip( $ip, $answered, $attempted );
            if ( ! $answered ) {
                $failed[ $ip ] = true;
                if ( ! $attempted ) {
                    $stats['waiting'][ $ip ] = true;
                    $stats['net']--;
                    continue;
                }
                if ( nppp_f2b_rdap_has_partial( $ip ) ) {
                    $stats['partial']++;
                }
                $stats['hints']['no response'] = ( $stats['hints']['no response'] ?? 0 ) + 1;
            }
        }
        $stats['ms'] = (int) round( ( microtime( true ) - $serial_start ) * 1000 );
        return $out;
    }

    $timeout = nppp_f2b_rdap_timeout();

    $requests = array();
    $states   = array();
    foreach ( $pending as $index => $ip ) {
        $states[ $ip ] = nppp_f2b_rdap_work_get( $ip );
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
        if ( $states[ $ip ]['whois'] ) {
            unset( $requests[ 'w' . $index ] );
        }
        if ( $states[ $ip ]['abuse'] ) {
            unset( $requests[ 'a' . $index ] );
        }
    }

    $options = array(
        'timeout'          => $timeout,
        'connect_timeout'  => $timeout,
        'follow_redirects' => false,
        'redirects'        => 0,
        'useragent'        => 'NPP-Fail2Ban-Monitor/' . ( defined( 'NPPP_PLUGIN_VERSION' ) ? NPPP_PLUGIN_VERSION : '1.0' ),
    );

    $round_start = microtime( true );

    try {
        $responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
    } catch ( \Exception $nppp_f2b_requests_error ) {
        $responses = array();
        // The whole batch is lost and every slot below reads "no response";
        // the exception is the only place that says why.
        if ( nppp_f2b_log_gate( 'rdap_batch_exception', 5 * MINUTE_IN_SECONDS ) > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    'RIPEstat batch request threw an exception, the whole batch counts as failed: ips=%d class=%s message="%s"',
                    count( $pending ),
                    get_class( $nppp_f2b_requests_error ),
                    substr( trim( $nppp_f2b_requests_error->getMessage() ), 0, 120 )
                )
            );
        }
    }

    $stats['ms'] = (int) round( ( microtime( true ) - $round_start ) * 1000 );

    foreach ( $pending as $index => $ip ) {
        $state    = $states[ $ip ];
        $result   = $state['result'];
        $answered = $state['whois'] || $state['abuse'];

        $whois = nppp_f2b_requests_json( $responses[ 'w' . $index ] ?? null, 'records' );
        if ( null !== $whois ) {
            $answered = true;
            $state['whois'] = true;
            $result   = nppp_f2b_rdap_apply_whois( $whois, $result );
        }

        $abuse = nppp_f2b_requests_json( $responses[ 'a' . $index ] ?? null, 'abuse_contacts' );
        if ( null !== $abuse ) {
            $answered = true;
            $state['abuse'] = true;
            $result   = nppp_f2b_rdap_apply_abuse( $abuse, $result );
        }

        $permanent = true;

        // Every endpoint that failed, answered IP or not, so a partial
        // failure (one of the two) is counted too. The reason is cut at the
        // first colon so "cURL error 28: ..." variants of the same failure
        // count together.
        foreach ( array( 'w' => $whois, 'a' => $abuse ) as $kind => $decoded ) {
            if ( null !== $decoded || ! isset( $requests[ $kind . $index ] ) ) {
                continue;
            }
            $reply     = $responses[ $kind . $index ] ?? null;
            $permanent = $permanent && is_object( $reply ) && isset( $reply->status_code )
                && nppp_f2b_rdap_reply_is_permanent( (int) $reply->status_code, $reply->body ?? '' );
            $hint = nppp_f2b_requests_fail_hint( $reply );
            // Cut at the first colon, then drop the variable "after N ms..."
            // tail so identical timeouts land in one bucket.
            $key = (string) preg_replace( '/\bafter \d+.*$/i', '', (string) strtok( $hint, ':' ) );
            $key = substr( trim( $key ), 0, 40 );
            if ( '' === $key ) {
                $key = 'error';
            }
            $stats['hints'][ $key ] = ( $stats['hints'][ $key ] ?? 0 ) + 1;
        }
        if ( $answered && ! ( $state['whois'] && $state['abuse'] ) ) {
            $stats['partial']++;
        }

        $state['result'] = $result;

        // Built before the save: the retry budget log needs the last reason
        // even when this very save is the one that gives up on the IP.
        $hints = array();
        if ( ! $state['whois'] ) {
            $hints[] = nppp_f2b_requests_fail_hint( $responses[ 'w' . $index ] ?? null );
        }
        if ( ! $state['abuse'] ) {
            $hints[] = nppp_f2b_requests_fail_hint( $responses[ 'a' . $index ] ?? null );
        }
        $state['last_hint'] = substr( implode( ' / ', array_unique( $hints ) ), 0, 80 );

        if ( ! nppp_f2b_rdap_work_save( $ip, $state, $permanent ) ) {
            $failed[ $ip ] = implode( ' / ', array_unique( $hints ) );
        }
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
        ) . sprintf(
            // Structured tail, untranslated like the worker's stop line.
            ' [pid=%1$d stage=%2$s]',
            (int) ( $state['pid'] ?? 0 ),
            (string) ( $state['stage'] ?? '?' )
        )
        : __( ' (before the worker loop started)', 'fastcgi-cache-purge-and-preload-nginx' );

    if ( is_array( $error ) && in_array( $error['type'], $fatals, true ) ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %1$s: PHP fatal error message (not translated); %2$s: file path; %3$d: line number; %4$s: extra run info, may be empty. */
                __( 'Worker fatal: %1$s at %2$s:%3$d%4$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                nppp_f2b_worker_short_error( (string) $error['message'] ),
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

    // A bootstrap failure or rejected duplicate must not clear another owner.
    nppp_f2b_worker_release_run_lock();
}

function nppp_f2b_worker_run(): void {
    if ( 'cli' !== PHP_SAPI ) {
        return;
    }

    // Wait for the parent to publish its PID, but never wait indefinitely:
    // an inherited descriptor may retain its lock if the parent dies.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $spawn_lock = @fopen( nppp_get_runtime_file( NPPP_F2B_WORKER_LOCK_FILE ), 'c' );
    $got_spawn  = false;

    if ( $spawn_lock ) {
        $deadline = microtime( true ) + 5;

        do {
            $got_spawn = @flock( $spawn_lock, LOCK_EX | LOCK_NB );

            if ( $got_spawn ) {
                break;
            }

            usleep( 50000 );
        } while ( microtime( true ) < $deadline );
    }

    if ( ! $got_spawn ) {
        if ( is_resource( $spawn_lock ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            @fclose( $spawn_lock );
        }
        // Marked clean so the shutdown hook does not clear another owner.
        $GLOBALS['nppp_f2b_worker_state'] = array( 'clean' => true );
        nppp_f2b_log( 'ERROR', 'Worker cannot acquire its startup lock; enrichment was not started.' );
        return;
    }

    // Lifetime ownership, taken while the spawn lock is still held so the
    // parent's own checks never see a gap. The handle stays open until exit.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $run_lock      = @fopen( nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE ), 'c' );
    $owns_run_lock = $run_lock && @flock( $run_lock, LOCK_EX | LOCK_NB );
    @flock( $spawn_lock, LOCK_UN );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    @fclose( $spawn_lock );

    if ( ! $owns_run_lock ) {
        // Marked clean before anything is logged, so a failing log call can never
        // make the shutdown hook report a death or clear another owner's state.
        $GLOBALS['nppp_f2b_worker_state'] = array( 'clean' => true );
        if ( is_resource( $run_lock ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            @fclose( $run_lock );
            // Two workers were spawned for one queue. The spawn lock and the run
            // lock make this a race indicator, so it must leave a trace. The
            // spawner already rewrote the PID/heartbeat files for this process,
            // so pidfile_pid equal to pid means the owner's PID was overwritten.
            $nppp_f2b_rejected = nppp_f2b_log_gate( 'worker_rejected', 10 * MINUTE_IN_SECONDS, true );
            if ( $nppp_f2b_rejected > 0 ) {
                nppp_f2b_log(
                    'WARNING',
                    sprintf(
                        'Worker rejected, another worker owns the run lock: pid=%d pidfile_pid=%d hb_age=%s occurrences=%d',
                        (int) getmypid(),
                        nppp_f2b_worker_read_pid(),
                        nppp_f2b_worker_fmt_age( nppp_f2b_worker_heartbeat_age() ),
                        $nppp_f2b_rejected
                    )
                );
            }
        } else {
            nppp_f2b_log( 'ERROR', 'Worker cannot open its run lock; enrichment was not started.' );
        }
        // Another worker owns the queue (clean marker set above).
        return;
    }
    $GLOBALS['nppp_f2b_worker_run_lock'] = $run_lock;

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
    // Hard ceiling -- batch*2 requests must stay within RIPEstat's 8 concurrent
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

    $started            = time();
    $idle_since         = 0;
    $idle_total         = 0;
    $err_since          = 0;
    $seen               = array();
    $deferred           = array();
    $waiting            = array();
    $written            = 0;
    $batch_fails        = 0;
    $consec_batch_fails = 0;
    $stop_reason        = 'unknown';

    // Diagnostics for the stop line.
    $pid          = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
    $empty        = 0; // answered, but the registry had nothing for the IP
    $partial      = 0; // only one of whois/abuse answered
    $cached       = 0; // served from the RIPEstat enrichment cache, no network
    $unwritten    = 0; // IPs whose write-back touched no row
    $claim_errors = 0;
    $lat_batches  = 0; // batches that went to the network
    $lat_total    = 0;
    $lat_max      = 0;
    $fail_hints   = array();

    // Read by nppp_f2b_worker_on_shutdown(), which logs a fatal that would
    // otherwise vanish into /dev/null.
    $GLOBALS['nppp_f2b_worker_state'] = array(
        'started' => $started,
        'pid'     => $pid,
        'ips'     => 0,
        'stage'   => 'start',
        'clean'   => false,
    );

    // One query per worker lifetime: what is waiting, and for how long. The
    // oldest pending age is the gap between a ban and this worker reaching it.
    $queue_start = nppp_f2b_queue_stats();

    // Structured key=value line, untranslated on purpose like the stop line.
    nppp_f2b_log(
        'INFO',
        sprintf(
            'Worker started: pid=%d batch=%d max_runtime=%ds queue=%s oldest=%ds mem=%.1fMB',
            $pid,
            $batch,
            $max_runtime,
            $queue_start['ips'] < 0 ? '?' : (string) $queue_start['ips'],
            $queue_start['oldest_age'],
            memory_get_usage( true ) / 1048576
        )
    );

    while ( true ) {
        if ( ( time() - $started ) >= $max_runtime ) {
            $stop_reason = 'max_runtime';
            break;
        }

        nppp_f2b_worker_mark( 'claim' );

        // Skip addresses already deferred this run, see
        // nppp_f2b_worker_claim_ips().
        $claim_error = '';
        $ips         = nppp_f2b_worker_claim_ready_ips( $batch, array_keys( $deferred ), $claim_error, $waiting );

        // A failing claim comes back as an empty array, exactly like an empty
        // queue. Tracked separately so it is never reported as "idle".
        if ( '' !== $claim_error ) {
            $claim_errors++;
            if ( 0 === $err_since ) {
                $err_since = time();
            }
        } else {
            $err_since = 0;
        }

        if ( empty( $ips ) ) {
            if ( 0 !== $err_since ) {
                // Same tolerance as the idle exit: ride out a short DB blip,
                // give up on a real outage.
                if ( ( time() - $err_since ) >= NPPP_F2B_WORKER_IDLE_SECONDS ) {
                    $stop_reason = 'db_error';
                    break;
                }
                usleep( 250000 );
                continue;
            }
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

        if ( 0 !== $idle_since ) {
            $idle_total += time() - $idle_since;
            $idle_since  = 0;
        }

        // Progress guard: if a write-back ever fails (read-only replica,
        // revoked grant, full disk) the same IPs keep coming back and we'd
        // spin on RIPEstat forever. Skip anything already handled this run, and
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

        nppp_f2b_worker_mark( 'lookup' );

        $failed  = array();
        $lstats  = array();
        $results = nppp_f2b_lookup_ips_bulk( $fresh, $failed, $lstats );

        // Numbers for the stop line. Latency only counts batches that
        // actually went to the network.
        $cached  += (int) ( $lstats['cached'] ?? 0 );
        $partial += (int) ( $lstats['partial'] ?? 0 );
        if ( ! empty( $lstats['net'] ) ) {
            $batch_ms = (int) ( $lstats['ms'] ?? 0 );
            $lat_batches++;
            $lat_total += $batch_ms;
            $lat_max    = max( $lat_max, $batch_ms );
        }
        foreach ( (array) ( $lstats['hints'] ?? array() ) as $hint => $hint_count ) {
            $fail_hints[ $hint ] = ( $fail_hints[ $hint ] ?? 0 ) + (int) $hint_count;
        }

        // No IP in the batch got a usable answer: upstream outage, block or
        // timeout. The first one per run is logged with the HTTP code or curl
        // error, the stop line carries the total.
        if ( ! empty( $failed ) && empty( array_diff_key( $results, $failed ) )
            && empty( $lstats['partial'] ) && ! empty( $lstats['net'] ) ) {
            $batch_fails++;
            $consec_batch_fails++;
            if ( 1 === $batch_fails ) {
                $first = reset( $failed );
                nppp_f2b_log(
                    'WARNING',
                    sprintf(
                        /* translators: %1$d: number of IPs in the batch; %2$s: failure reason (HTTP status or transport error, not translated). */
                        __( 'RIPEstat batch failed for all %1$d IP(s): %2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                        count( $fresh ),
                        is_string( $first ) ? $first : __( 'no response', 'fastcgi-cache-purge-and-preload-nginx' )
                    )
                );
            }
        } else {
            $consec_batch_fails = 0;
        }

        nppp_f2b_worker_mark( 'write' );

        foreach ( $fresh as $ip ) {
            if ( isset( $lstats['waiting'][ $ip ] ) ) {
                $waiting[ $ip ] = true;
                continue;
            }
            $seen[ $ip ] = true;

            // Incomplete is never a successful empty profile.
            // Retry timing and failure counts were saved by the lookup.
            if ( isset( $failed[ $ip ] ) ) {
                $deferred[ $ip ] = true;
                continue;
            }

            $rdap = isset( $results[ $ip ] ) && is_array( $results[ $ip ] )
                ? $results[ $ip ]
                : nppp_f2b_rdap_blank_result();

            // An answer with nothing in it (private range, unallocated block,
            // or WP_HTTP_BLOCK_EXTERNAL): stored, but worth knowing about.
            if ( ! isset( $failed[ $ip ] ) && ! nppp_f2b_rdap_has_data( array_merge( nppp_f2b_rdap_blank_result(), $rdap ) ) ) {
                $empty++;
            }

            // Zero rows = the write failed, or another path filled them first.
            $rows = nppp_f2b_worker_write_result( $ip, $rdap );
            if ( $rows < 1 ) {
                $unwritten++;
            }
            $written += $rows;
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

        // RIPEstat outage guard: N fully-failed batches in a row means the
        // problem is upstream, not this batch. Stop early instead of
        // burning max_runtime at full timeout cost, and be a better
        // citizen toward a shared public API during its own outage.
        $consec_fail_limit = (int) apply_filters( 'nppp_f2b_consecutive_batch_fail_limit', 3 );
        if ( $consec_fail_limit > 0 && $consec_batch_fails >= $consec_fail_limit ) {
            $stop_reason = 'upstream_down';
            break;
        }
    }

    // Shared-file cleanup first, while still the owner: deleting the output
    // file after release could remove a successor's. Then release ownership
    // so a successor can actually claim it.
    wp_delete_file( nppp_f2b_worker_out_path() );
    nppp_f2b_worker_release_run_lock();

    // Stop summary: warnings first, then one structured diagnostic line with
    // the totals.
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

    // Structured key=value diagnostic dump, not a sentence -- left untranslated
    // on purpose, same convention as nppp_ep_gate_log()'s "IP: ... | Action: ..."
    // lines. $stop_reason is a fixed machine token: idle, max_runtime,
    // seen_cap, deferred_cap, no_progress, upstream_down or db_error.
    if ( 0 !== $idle_since ) {
        $idle_total += time() - $idle_since;
    }

    $queue   = nppp_f2b_queue_stats();
    $runtime = time() - $started;

    // Pending backlog alone is not a reason to spawn: retry times matter.
    $handoff = false;
    $late_arrival = false;
    if ( $queue['ips'] > 0 && 'upstream_down' !== $stop_reason
        && ( $written > 0 || 'idle' === $stop_reason ) ) {
        $handoff_error = '';
        $exclude = $written > 0
            ? array()
            : array_merge( array_keys( $deferred ), array_keys( $seen ) );
        $ready = nppp_f2b_worker_claim_ready_ips( 1, $exclude, $handoff_error );
        $handoff = ( '' === $handoff_error && ! empty( $ready ) );
        $late_arrival = ( $handoff && 0 === $written && 'idle' === $stop_reason );
    }

    $line = sprintf(
        'Worker stopped: pid=%d reason=%s ips=%d rows=%d deferred=%d batch_failures=%d batches=%d lat_avg=%dms lat_max=%dms runtime=%ds active=%ds peak_mem=%.1fMB backlog=%s handoff=%s',
        $pid,
        $stop_reason,
        count( $seen ),
        $written,
        count( $deferred ),
        $batch_fails,
        $lat_batches,
        $lat_batches > 0 ? (int) round( $lat_total / $lat_batches ) : 0,
        $lat_max,
        $runtime,
        max( 0, $runtime - $idle_total ),
        memory_get_peak_usage( true ) / 1048576,
        $queue['ips'] < 0 ? '?' : (string) $queue['ips'],
        $handoff ? 'yes' : 'no'
    );

    // Anomaly counters only appear when non-zero, so a healthy line stays short.
    foreach ( array(
        'empty'        => $empty,
        'partial'      => $partial,
        'cached'       => $cached,
        'waiting'      => count( $waiting ),
        'cooling'      => count( nppp_f2b_rdap_cooling_get() ),
        'gave_up'      => (int) ( $GLOBALS['nppp_f2b_rdap_gave_up_run'] ?? 0 ),
        'unwritten'    => $unwritten,
        'claim_errors' => $claim_errors,
        'late_arrival' => $late_arrival ? 1 : 0,
    ) as $label => $count ) {
        if ( $count > 0 ) {
            $line .= ' ' . $label . '=' . $count;
        }
    }

    // Top failure reasons of the run: an HTTP status or a cURL error code.
    if ( ! empty( $fail_hints ) ) {
        arsort( $fail_hints );
        $top = array();
        foreach ( array_slice( $fail_hints, 0, 3, true ) as $hint => $count ) {
            $top[] = $hint . ' x' . $count;
        }
        $line .= ' errors=' . implode( ', ', $top );
    }

    if ( 'db_error' === $stop_reason ) {
        $level = 'ERROR';
    } elseif ( in_array( $stop_reason, array( 'no_progress', 'upstream_down' ), true ) ) {
        $level = 'WARNING';
    } else {
        $level = 'INFO';
    }

    nppp_f2b_log( $level, $line );

    // Ownership was released before the queue recheck above.
    $GLOBALS['nppp_f2b_worker_state']['clean'] = true;

    if ( $handoff ) {
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
    // A storm may end without any later event to trigger the dropped-event report.
    nppp_f2b_rate_report_rejected();

    // Before the running-worker check: a worker that is alive but falling
    // behind is exactly the case worth a warning.
    nppp_f2b_log_queue_health();
    nppp_f2b_log_stale_abandoned();

    if ( nppp_f2b_worker_is_running() ) {
        return;
    }

    if ( ! nppp_f2b_has_pending_enrichment() ) {
        return;
    }

    // Pending rows that are all cooling down need no worker yet.
    $ready_error = '';
    $ready_ips   = nppp_f2b_worker_claim_ready_ips( 1, array(), $ready_error );
    if ( '' === $ready_error && empty( $ready_ips ) ) {
        return;
    }

    if ( nppp_f2b_maybe_spawn_worker() ) {
        // True is also returned when a live worker still owns the run lock
        // (heartbeat stale but not yet hung): nothing was spawned then.
        if ( ! nppp_f2b_worker_is_running() ) {
            // The worker owns the run lock but its heartbeat is stale; nothing was
            // spawned. Visible here so a slow-but-alive worker is told apart from
            // a dead one before the watchdog threshold is reached.
            // The run lock must really be held: the same "true" also covers a
            // spawn that is merely throttled or in progress in another request.
            if ( false === nppp_f2b_worker_run_lock_is_free() && nppp_f2b_log_gate( 'reconcile_owner_stale', 30 * MINUTE_IN_SECONDS ) > 0 ) {
                $stats = nppp_f2b_queue_stats();
                nppp_f2b_log(
                    'WARNING',
                    sprintf(
                        'Reconcile: a worker still owns the run lock but its heartbeat is stale, no new worker was started: hb_age=%s kill_after=%ds pending=%d oldest=%ds',
                        nppp_f2b_worker_fmt_age( nppp_f2b_worker_heartbeat_age() ),
                        NPPP_F2B_WORKER_KILL_SECONDS,
                        max( 0, $stats['ips'] ),
                        $stats['oldest_age']
                    )
                );
            }
            return;
        }
        // Age of the oldest event tells a dead worker (minutes) from a slow one.
        $stats = nppp_f2b_queue_stats();
        nppp_f2b_log(
            'WARNING',
            sprintf(
                /* translators: %1$d: number of pending IPs in the enrichment queue; %2$d: age in seconds of the oldest pending event. */
                __( 'Reconcile found %1$d pending IP(s), the oldest event is %2$ds old, with no running worker; a worker spawn was requested.', 'fastcgi-cache-purge-and-preload-nginx' ),
                max( 0, $stats['ips'] ),
                $stats['oldest_age']
            ) . sprintf( ' [cooling=%d]', count( nppp_f2b_rdap_cooling_get() ) )
        );
        return;
    }

    if ( nppp_f2b_log_gate( 'inline_fallback', DAY_IN_SECONDS ) > 0 ) {
        nppp_f2b_log( 'WARNING', __( 'No worker can be spawned on this host; enrichment runs as a small inline batch from cron.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // Last resort for shell_exec-disabled hosts: a small bounded batch
    // right here in the cron request. Only place RIPEstat I/O still runs
    // inside PHP-FPM, capped to a few seconds per tick.
    $limit = (int) apply_filters( 'nppp_f2b_cron_inline_batch', 3 );
    if ( $limit < 1 ) {
        return;
    }
    if ( $limit > 5 ) {
        $limit = 5;
    }

    $inline = array( 'ips' => 0, 'rows' => 0, 'unanswered' => 0 );
    foreach ( nppp_f2b_worker_claim_ready_ips( $limit ) as $ip ) {
        $answered = true;
        $attempted = false;
        $rdap     = nppp_f2b_lookup_ip( $ip, $answered, $attempted );
        $inline['ips']++;
        if ( ! $answered ) {
            $inline['unanswered']++;
            continue;
        }
        $inline['rows'] += nppp_f2b_worker_write_result( $ip, $rdap );
    }

    // This path has no stop line of its own: once an hour, say whether the
    // inline batch actually makes progress. Untranslated key=value.
    if ( $inline['ips'] > 0 && nppp_f2b_log_gate( 'inline_batch', HOUR_IN_SECONDS ) > 0 ) {
        nppp_f2b_log(
            'INFO',
            sprintf(
                'Inline enrichment batch: ips=%d rows=%d unanswered=%d',
                $inline['ips'],
                $inline['rows'],
                $inline['unanswered']
            )
        );
    }
}
