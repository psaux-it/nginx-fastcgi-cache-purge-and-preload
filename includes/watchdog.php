<?php
/**
 * Preload watchdog — post-preload completion trigger for Nginx Cache Purge Preload
 * Description: Monitors the preload server process and executes post-preload completion work.
 *              Ensures post-preload tasks run immediately after cache preloading finishes.
 *              Designed for low-traffic sites where WP-Cron delays can prevent timely execution.
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

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'NPPP_WATCHER_TOKEN_TTL',   12 * HOUR_IN_SECONDS );
define( 'NPPP_WATCHER_TOKEN_KEY',   'nppp_ping_token_'   . md5( 'nppp' ) );
define( 'NPPP_WATCHER_PID_FILE',    'preload_watcher.pid' );
define( 'NPPP_WATCHER_AJAX_ACTION', 'nppp_cron_wake' );

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

/**
 * Check rate limit for the watchdog endpoint — max 3 requests per minute per IP.
 * Fires before token validation to stop brute-force probing at minimal cost.
 */
function nppp_watchdog_rate_limit_check(): bool {
    $ip = isset( $_SERVER['REMOTE_ADDR'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
        : 'unknown';

    $transient_key = 'nppp_rate_limit_' . md5( 'watchdog|' . $ip );
    $count         = get_transient( $transient_key );

    if ( false === $count ) {
        set_transient( $transient_key, 1, 60 );
        return true;
    }

    $count++;
    if ( $count > 10 ) {
        return false;
    }

    set_transient( $transient_key, $count, 60 );
    return true;
}

// ---------------------------------------------------------------------------
// Token helpers
// ---------------------------------------------------------------------------

/**
 * Generate and store a fresh watchdog token.
 * Called when a new preload cycle starts so the watchdog and the handler share
 * the same secret.
 */
function nppp_watcher_generate_token(): string {
    $token = bin2hex( random_bytes( 16 ) );
    set_transient( NPPP_WATCHER_TOKEN_KEY, $token, NPPP_WATCHER_TOKEN_TTL );
    return $token;
}

/**
 * Read the current watchdog token from the transient.
 */
function nppp_watcher_get_token(): string {
    $token = get_transient( NPPP_WATCHER_TOKEN_KEY );
    return is_string( $token ) ? $token : '';
}

/**
 * Rotate the token — called by the watchdog handler after a valid request.
 * Prevents replay: a token is valid exactly once.
 */
function nppp_watcher_rotate_token(): string {
    return nppp_watcher_generate_token();
}

/**
 * Delete the watchdog token — called on cleanup paths (purge, deactivation).
 */
function nppp_watcher_delete_token(): void {
    delete_transient( NPPP_WATCHER_TOKEN_KEY );
}

// ---------------------------------------------------------------------------
// Watchdog process management
// ---------------------------------------------------------------------------

/**
 * Spawn the watchdog process alongside the main preload process.
 *
 * When preload finishes the loop exits and the HTTP POST fires once.
 * The watchdog's own PID is stored so deactivation can kill it cleanly.
 */
function nppp_spawn_preload_watcher( int $wget_pid, string $token ): bool {
    // Guard: wget pid must be a positive integer.
    if ( $wget_pid <= 0 ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: Invalid PID — not spawned.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        return false;
    }

    // Guard: token must not be empty.
    if ( empty( $token ) ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: Empty token — not spawned.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        return false;
    }

    // Guard: shell_exec must be available
    if ( ! function_exists( 'shell_exec' ) ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: shell_exec unavailable — not spawned.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        return false;
    }

    $ajax_url         = admin_url( 'admin-ajax.php' );
    $watcher_pid_file = nppp_get_runtime_file( NPPP_WATCHER_PID_FILE );

    // Build the post data string
    $post_data = 'action=' . NPPP_WATCHER_AJAX_ACTION . '&token=' . rawurlencode( $token );

    // Detect available shell — bash preferred, fall back to sh
    $shell_path = trim( (string) shell_exec( 'command -v bash 2>/dev/null' ) );
    if ( empty( $shell_path ) ) {
        $shell_path = trim( (string) shell_exec( 'command -v sh 2>/dev/null' ) );
    }

    if ( empty( $shell_path ) ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: No shell (bash/sh) found — not spawned.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        return false;
    }

    // Build the watchdog
    $watchdog = sprintf(
        'echo $$ > %s; while [ -d /proc/%d ]; do sleep 5; done; '
        . 'wget -q -O /dev/null --no-check-certificate '
        . '--no-config --no-cookies --prefer-family=IPv4 '
        . '--dns-timeout=10 --connect-timeout=5 --read-timeout=60 '
        . '--tries=2 --max-redirect=2 '
        . '--post-data %s %s',
        escapeshellarg( $watcher_pid_file ),
        $wget_pid,
        escapeshellarg( $post_data ),
        escapeshellarg( $ajax_url )
    );

    $command = 'nohup ' . escapeshellarg( $shell_path ) . ' -c ' . escapeshellarg( $watchdog ) . ' > /dev/null 2>&1 &';
    shell_exec( $command );

    nppp_display_admin_notice(
        'info',
        sprintf(
            /* Translators: %d is the wget process PID being monitored */
            __( 'INFO WATCHDOG: Spawned. Monitoring preload PID %d.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $wget_pid
        ),
        true,
        false
    );

    return true;
}

/**
 * Kill the watchdog if it is still alive.
 * Called during Purge All (preload killed externally) and plugin deactivation.
 */
function nppp_kill_preload_watcher(): bool {
    $watcher_pid_file = nppp_get_runtime_file( NPPP_WATCHER_PID_FILE );

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false || ! $wp_filesystem->exists( $watcher_pid_file ) ) {
        return true;
    }

    $watcher_pid = intval( trim( (string) $wp_filesystem->get_contents( $watcher_pid_file ) ) );

    if ( $watcher_pid <= 0 ) {
        $wp_filesystem->delete( $watcher_pid_file );
        return true;
    }

    // Check if the watchdog is still alive.
    if ( ! nppp_is_process_alive( $watcher_pid ) ) {
        $wp_filesystem->delete( $watcher_pid_file );

        nppp_display_admin_notice(
            'info',
            sprintf(
                /* Translators: %d is the watcher process PID */
                __( 'INFO WATCHDOG: Process (PID: %d) already gone. Nothing to kill.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $watcher_pid
            ),
            true,
            false
        );

        return true;
    }

    // Kill it gracefully with SIGTERM first.
    if ( function_exists( 'posix_kill' ) && defined( 'SIGTERM' ) ) {
        posix_kill( $watcher_pid, SIGTERM );
        usleep( 200000 );
    }

    // Fall back to kill -9 if still alive.
    if ( nppp_is_process_alive( $watcher_pid ) ) {
        $kill_path = trim( (string) shell_exec( 'command -v kill' ) );
        if ( ! empty( $kill_path ) ) {
            shell_exec( escapeshellarg( $kill_path ) . ' -9 ' . (int) $watcher_pid );
            usleep( 200000 );
        }
    }

    $wp_filesystem->delete( $watcher_pid_file );
    $killed = ! nppp_is_process_alive( $watcher_pid );

    if ( $killed ) {
        nppp_display_admin_notice(
            'info',
            sprintf(
                /* Translators: %d is the watcher process PID */
                __( 'INFO WATCHDOG: Process (PID: %d) terminated. Preload was interrupted, post-preload jobs will not run for this cycle.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $watcher_pid
            ),
            true,
            false
        );
    } else {
        nppp_display_admin_notice(
            'error',
            sprintf(
                /* Translators: %d is the watcher process PID */
                __( 'ERROR WATCHDOG: Failed to terminate watchdog process (PID: %1$d) Manual kill required: kill -9 %1$d', 'fastcgi-cache-purge-and-preload-nginx' ),
                $watcher_pid
            ),
            true,
            false
        );
    }

    return $killed;
}

// ---------------------------------------------------------------------------
// Watchdog handler (nopriv — token-gated)
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_nopriv_' . NPPP_WATCHER_AJAX_ACTION, 'nppp_cron_wake_handler' );
add_action( 'wp_ajax_'        . NPPP_WATCHER_AJAX_ACTION, 'nppp_cron_wake_handler' );

/**
 * Handle the watchdog's HTTP POST.
 */
function nppp_cron_wake_handler(): void {
    // Rate limit — max 10 requests per minute per IP.
    // Fires before token validation to stop brute-force probing cheaply.
    if ( ! nppp_watchdog_rate_limit_check() ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: Rate limit exceeded. Post-preload jobs will be handled by WP-Cron.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        wp_die( '', '', [ 'response' => 429 ] );
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- token IS the auth
    $submitted = isset( $_POST['token'] )
        ? sanitize_text_field( wp_unslash( $_POST['token'] ) )
        : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    // Reject if token is missing or wrong format
    if ( empty( $submitted ) || ! preg_match( '/^[a-f0-9]{32}$/i', $submitted ) ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: Malformed or missing token. Post-preload jobs will be handled by WP-Cron.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        wp_die( '', '', [ 'response' => 403 ] );
    }

    // Validate against stored token
    $stored = nppp_watcher_get_token();
    if ( empty( $stored ) ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: Token missing or expired. Post-preload jobs will be handled by WP-Cron.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        wp_die( '', '', [ 'response' => 403 ] );
    }
    if ( ! hash_equals( $stored, $submitted ) ) {
        nppp_display_admin_notice(
            'error',
            __( 'ERROR WATCHDOG: Token mismatch. Post-preload jobs will be handled by WP-Cron.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
        );
        wp_die( '', '', [ 'response' => 403 ] );
    }

    // Rotate token immediately
    nppp_watcher_rotate_token();

    // Log the wake event — visible in plugin log (fastcgi_ops.log).
    nppp_display_admin_notice(
        'info',
        __( 'INFO WATCHDOG: Preload finished. Executing post-preload jobs.', 'fastcgi-cache-purge-and-preload-nginx' ),
        true,
        false
    );

    // Complete post-preload tasks
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy hook name stored in wp_options
    do_action( 'npp_cache_preload_status_event' );

    // Respond and exit cleanly.
    wp_die( 'ok', '', [ 'response' => 200 ] );
}
