<?php
/**
 * Persistent job state for Nginx Cache Purge Preload
 * Description: Option-backed state store for process tracking and learned state that must survive an object-cache flush.
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

/**
 * With an external object cache (Redis/Memcached) a transient lives only in the
 * cache, so wp_cache_flush() (Redis dashboard, `wp cache flush`, another plugin,
 * or this plugin's own Redis compat sync) silently erases it. That is fine for a
 * real cache, but wrong for state that tracks a process which is still running,
 * or for observations the plugin needs to keep acting correctly.
 *
 * Rows are stored as wp_options entries (autoload off) shaped
 * array( 'v' => value, 'exp' => unix deadline or 0 ). Expiry is enforced on READ
 * only; a reader never deletes, so a reader can never race a concurrent writer.
 * Stale rows are removed by the explicit delete paths, by
 * nppp_state_prune_expired() for per-item families, and on uninstall.
 *
 * Every *_with_legacy helper also touches the pre-2.1.9 transient of the same
 * state: reads fall back to it only while no option row exists (in-place update
 * during a running cycle); writes and deletes remove it so it can never be
 * resurrected. The fallback can be dropped one release after 2.1.9.
 */

/**
 * Option name for a state key.
 */
function nppp_state_option_name( string $key ): string {
    return 'nppp_st_' . $key;
}

/**
 * Read a state value.
 *
 * @param string $key   State key (without the nppp_st_ prefix).
 * @param bool   $fresh Bypass this request's option cache. Use it in callers that
 *                      must observe a row written by another request/process.
 * @return mixed|null   Stored value, or null when missing, expired or malformed.
 */
function nppp_state_get( string $key, bool $fresh = false ) {
    $name = nppp_state_option_name( $key );

    if ( $fresh ) {
        // Same pattern as nppp_http_purge_gate_pending(): a cached negative
        // ('notoptions') must not hide a row written by another process.
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    $row = get_option( $name, false );
    if ( ! is_array( $row ) || ! array_key_exists( 'v', $row ) ) {
        return null;
    }

    $exp = isset( $row['exp'] ) ? (int) $row['exp'] : 0;
    if ( $exp > 0 && $exp <= time() ) {
        return null;
    }

    return $row['v'];
}

/**
 * Write a state value.
 *
 * @param string $key   State key (without the nppp_st_ prefix).
 * @param mixed  $value Serializable value.
 * @param int    $ttl   Seconds until the value is ignored on read. 0 = no expiry.
 */
function nppp_state_set( string $key, $value, int $ttl = 0 ): void {
    update_option(
        nppp_state_option_name( $key ),
        array(
            'v'   => $value,
            'exp' => ( $ttl > 0 ) ? time() + $ttl : 0,
        ),
        false
    );
}

/**
 * Delete a state value.
 */
function nppp_state_delete( string $key ): void {
    delete_option( nppp_state_option_name( $key ) );
}

/**
 * Read a state value; while no option row exists, fall back to the pre-2.1.9 transient.
 *
 * @return mixed|null null when neither exists.
 */
function nppp_state_get_with_legacy( string $key, string $legacy_transient, bool $fresh = false ) {
    $value = nppp_state_get( $key, $fresh );
    if ( $value !== null ) {
        return $value;
    }

    $legacy = get_transient( $legacy_transient );
    return ( $legacy === false ) ? null : $legacy;
}

/**
 * Write a state value and drop the pre-2.1.9 transient so it cannot disagree.
 */
function nppp_state_set_with_legacy( string $key, string $legacy_transient, $value, int $ttl = 0 ): void {
    nppp_state_set( $key, $value, $ttl );
    delete_transient( $legacy_transient );
}

/**
 * Delete a state value together with its pre-2.1.9 transient.
 */
function nppp_state_delete_with_legacy( string $key, string $legacy_transient ): void {
    nppp_state_delete( $key );
    delete_transient( $legacy_transient );
}

/**
 * Delete expired (and malformed) rows of a per-item state family.
 *
 * Readers never delete, so families with one row per item (nppp_st_<prefix><id>)
 * need this sweep or abandoned items would stay forever. Walks the family in
 * option_id order, 500 rows per query, and stops after $max_rows rows.
 *
 * @param string $key_prefix Family prefix without nppp_st_, e.g. 'f2b_rdap_work_'.
 * @param int    $max_rows   Upper bound of rows examined per call.
 * @return int Number of rows deleted.
 */
function nppp_state_prune_expired( string $key_prefix, int $max_rows = 10000 ): int {
    global $wpdb;

    $like    = $wpdb->esc_like( nppp_state_option_name( $key_prefix ) ) . '%';
    $now     = time();
    $last_id = 0;
    $seen    = 0;
    $pruned  = 0;

    while ( $seen < $max_rows ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded keyset scan over this plugin's own option rows
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 500",
                $like,
                $last_id
            )
        );
        if ( empty( $rows ) ) {
            break;
        }

        foreach ( $rows as $row ) {
            $last_id = (int) $row->option_id;
            $seen++;

            $value = maybe_unserialize( $row->option_value );
            $exp   = ( is_array( $value ) && isset( $value['exp'] ) ) ? (int) $value['exp'] : 0;

            if ( ! is_array( $value ) || ( $exp > 0 && $exp <= $now ) ) {
                // delete_option() so an external object cache entry cannot outlive the row.
                delete_option( $row->option_name );
                $pruned++;
            }
        }
    }

    return $pruned;
}

// ---------------------------------------------------------------------------
// A1. Preload phase: 'desktop' (default, row absent) or 'mobile'.
// Replaces the nppp_preload_phase_<md5> transient used up to 2.1.8.
// ---------------------------------------------------------------------------

/**
 * Legacy (<= 2.1.8) transient name for the preload phase.
 */
function nppp_preload_phase_legacy_key(): string {
    return 'nppp_preload_phase_' . md5( 'nppp' );
}

/**
 * Current preload phase.
 *
 * @param bool $fresh See nppp_state_get().
 * @return string 'desktop' or 'mobile'.
 */
function nppp_preload_phase_get( bool $fresh = false ): string {
    $phase = nppp_state_get_with_legacy( 'preload_phase', nppp_preload_phase_legacy_key(), $fresh );

    return ( $phase === 'mobile' ) ? 'mobile' : 'desktop';
}

/**
 * Record the current preload phase (12 hours, same horizon as the old transient).
 */
function nppp_preload_phase_set( string $phase ): void {
    nppp_state_set_with_legacy(
        'preload_phase',
        nppp_preload_phase_legacy_key(),
        ( $phase === 'mobile' ) ? 'mobile' : 'desktop',
        12 * HOUR_IN_SECONDS
    );
}

/**
 * Clear the preload phase (back to the implicit 'desktop').
 */
function nppp_preload_phase_delete(): void {
    nppp_state_delete_with_legacy( 'preload_phase', nppp_preload_phase_legacy_key() );
}

// ---------------------------------------------------------------------------
// A2. Preload cycle start: unix timestamp of the desktop crawl start, used at
// completion to compute the total desktop+mobile elapsed time.
// Replaces the nppp_preload_cycle_start_<md5> transient used up to 2.1.8.
// ---------------------------------------------------------------------------

/**
 * Legacy (<= 2.1.8) transient name for the cycle start timestamp.
 */
function nppp_preload_cycle_start_legacy_key(): string {
    return 'nppp_preload_cycle_start_' . md5( 'nppp' );
}

/**
 * Cycle start timestamp, 0 when unknown.
 *
 * @param bool $fresh See nppp_state_get().
 */
function nppp_preload_cycle_start_get( bool $fresh = false ): int {
    $ts = nppp_state_get_with_legacy( 'preload_cycle_start', nppp_preload_cycle_start_legacy_key(), $fresh );

    return is_numeric( $ts ) ? max( 0, (int) $ts ) : 0;
}

/**
 * Record the cycle start (12 hours, same horizon as the old transient).
 */
function nppp_preload_cycle_start_set( int $timestamp ): void {
    nppp_state_set_with_legacy( 'preload_cycle_start', nppp_preload_cycle_start_legacy_key(), $timestamp, 12 * HOUR_IN_SECONDS );
}

/**
 * Forget the cycle start.
 *
 * Every way a Preload All cycle ends goes through here (completion tick, watchdog, Purge All,
 * Stop Preload), so this is also where the finished cycle is handed to the traffic hit ratio:
 * the requests the crawler made between start and now are left out of it
 * (nppp_http_purge_traffic_mark_preload()).
 *
 * @param bool $mark False when the cycle record is only an orphan being tidied up (no crawl
 *                   ran until now), so its age must not become an excluded period.
 */
function nppp_preload_cycle_start_delete( bool $mark = true ): void {
    if ( $mark && function_exists( 'nppp_http_purge_traffic_mark_preload' ) ) {
        $from = nppp_preload_cycle_start_get( true );
        if ( $from > 0 ) {
            nppp_http_purge_traffic_mark_preload( $from, time() );
        }
    }

    nppp_state_delete_with_legacy( 'preload_cycle_start', nppp_preload_cycle_start_legacy_key() );
}

// ---------------------------------------------------------------------------
// A3. Preload trigger: Manual | Auto Preload | REST API | Scheduled.
// Shown in the completion mail. Replaces the nppp_preload_trigger_<md5>
// transient used up to 2.1.8.
// ---------------------------------------------------------------------------

/**
 * Legacy (<= 2.1.8) transient name for the preload trigger.
 */
function nppp_preload_trigger_legacy_key(): string {
    return 'nppp_preload_trigger_' . md5( 'nppp' );
}

/**
 * Trigger label, '' when unknown.
 *
 * @param bool $fresh See nppp_state_get().
 */
function nppp_preload_trigger_get( bool $fresh = false ): string {
    $trigger = nppp_state_get_with_legacy( 'preload_trigger', nppp_preload_trigger_legacy_key(), $fresh );

    return is_string( $trigger ) ? $trigger : '';
}

/**
 * Record the trigger (12 hours, same horizon as the old transient).
 */
function nppp_preload_trigger_set( string $trigger ): void {
    nppp_state_set_with_legacy( 'preload_trigger', nppp_preload_trigger_legacy_key(), $trigger, 12 * HOUR_IN_SECONDS );
}

/**
 * Forget the trigger.
 */
function nppp_preload_trigger_delete(): void {
    nppp_state_delete_with_legacy( 'preload_trigger', nppp_preload_trigger_legacy_key() );
}

// ---------------------------------------------------------------------------
// A4. HTTP Purge endpoint state: scoped locks, remembered request method,
// ngx_cache_purge generation-mode observation (ns), probe, issue and hint.
// Replaces the nppp_http_purge_endpoint_broken transient used up to 2.1.8.
// Every sub-state carries its own timestamp and is expired on read by
// nppp_http_purge_state(), so the row needs no expiry of its own beyond the
// TTL nppp_http_purge_state_save() computes.
// ---------------------------------------------------------------------------

/**
 * Legacy (<= 2.1.8) transient name; same value as NPPP_HTTP_PURGE_BROKEN_KEY.
 */
function nppp_http_purge_state_legacy_key(): string {
    return 'nppp_http_purge_endpoint_broken';
}

/**
 * Raw stored endpoint state, or false when nothing is stored.
 * Pre-2.1.9 plugin versions may have left a bare string in the transient;
 * nppp_http_purge_state() still understands it.
 *
 * @return mixed
 */
function nppp_http_purge_state_raw() {
    $raw = nppp_state_get_with_legacy( 'http_purge_endpoint', nppp_http_purge_state_legacy_key() );

    return ( $raw === null ) ? false : $raw;
}

/**
 * Persist the endpoint state for $ttl seconds.
 */
function nppp_http_purge_state_write( array $state, int $ttl ): void {
    nppp_state_set_with_legacy( 'http_purge_endpoint', nppp_http_purge_state_legacy_key(), $state, $ttl );
}

/**
 * Forget the whole endpoint state ("Clear Plugin Cache", or nothing left to remember).
 */
function nppp_http_purge_state_reset(): void {
    nppp_state_delete_with_legacy( 'http_purge_endpoint', nppp_http_purge_state_legacy_key() );
}

// ---------------------------------------------------------------------------
// A5. Generation-mode "seen" flag: a scan saw p4 keys in the cache directory.
// Replaces the nppp_gen_mode_seen transient used up to 2.1.8.
// ---------------------------------------------------------------------------

/**
 * True while the 24 h "generation-mode keys were seen" flag is set.
 */
function nppp_gen_mode_flag_get(): bool {
    return nppp_state_get_with_legacy( 'gen_mode_seen', 'nppp_gen_mode_seen' ) !== null;
}

/**
 * Set the flag for 24 hours.
 */
function nppp_gen_mode_flag_set(): void {
    nppp_state_set_with_legacy( 'gen_mode_seen', 'nppp_gen_mode_seen', 1, DAY_IN_SECONDS );
}

/**
 * Clear the flag ("Clear Plugin Cache").
 */
function nppp_gen_mode_flag_delete(): void {
    nppp_state_delete_with_legacy( 'gen_mode_seen', 'nppp_gen_mode_seen' );
}

// ---------------------------------------------------------------------------
// A6. RIPEstat/RDAP in-progress work per IP: partial results, failure counter
// and retry_at. Replaces the nppp_f2b_rdap_work_<md5(ip)> transient used up to
// 2.1.8. One row per IP that is still being resolved; rows are removed when
// the profile completes or the retry budget is spent, and expired leftovers
// are swept by nppp_state_prune_expired( 'f2b_rdap_work_' ) from the existing
// 3-hourly f2b cleanup. The failure counter is what bounds retries per IP, so
// losing it on a cache flush would restart the 3 / 12 attempt budget.
// ---------------------------------------------------------------------------

/**
 * State key of one IP's in-progress work.
 */
function nppp_f2b_rdap_work_state_key( string $ip ): string {
    return 'f2b_rdap_work_' . md5( $ip );
}

/**
 * Stored in-progress state of one IP, or null.
 * Always read fresh: the worker, the cron batch and web requests may be
 * different PHP processes.
 *
 * @return mixed|null
 */
function nppp_f2b_rdap_work_load( string $ip ) {
    return nppp_state_get_with_legacy( nppp_f2b_rdap_work_state_key( $ip ), nppp_f2b_rdap_work_key( $ip ), true );
}

/**
 * Persist one IP's in-progress state.
 */
function nppp_f2b_rdap_work_store( string $ip, array $state, int $ttl ): void {
    nppp_state_set_with_legacy( nppp_f2b_rdap_work_state_key( $ip ), nppp_f2b_rdap_work_key( $ip ), $state, $ttl );
}

/**
 * Forget one IP's in-progress state.
 */
function nppp_f2b_rdap_work_delete( string $ip ): void {
    nppp_state_delete_with_legacy( nppp_f2b_rdap_work_state_key( $ip ), nppp_f2b_rdap_work_key( $ip ) );
}
