<?php
/**
 * Fail2ban Nginx jail monitor for Nginx Cache Purge Preload
 * Description: Webhook-based, read-only monitor for nginx-related fail2ban jails.
 *              fail2ban pushes ban/unban events to a token-authenticated REST endpoint.
 * Drop-in Version: 1.0.0
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

// Token option name. The EP10 gate (rest_api_init, priority 1) reads it before the plugin bootstrap loads.
if ( ! defined( 'NPPP_F2B_TOKEN_OPTION' ) ) {
    define( 'NPPP_F2B_TOKEN_OPTION', 'nppp_f2b_token' );
}

// Schema version. Bump only when the table structure changes.
if ( ! defined( 'NPPP_F2B_DB_VERSION' ) ) {
    define( 'NPPP_F2B_DB_VERSION', '1.0.0' );
}
if ( ! defined( 'NPPP_F2B_DB_VERSION_OPTION' ) ) {
    define( 'NPPP_F2B_DB_VERSION_OPTION', 'nppp_f2b_db_version' );
}

// Timestamp gate for log lines that can fire from many requests. One small
// non-autoloaded option, see nppp_f2b_log_gate().
if ( ! defined( 'NPPP_F2B_LOG_GATE_OPTION' ) ) {
    define( 'NPPP_F2B_LOG_GATE_OPTION', 'nppp_f2b_log_gate' );
}

// Cron hook name, registered in EP2's cron list.
if ( ! defined( 'NPPP_F2B_CLEANUP_HOOK' ) ) {
    define( 'NPPP_F2B_CLEANUP_HOOK', 'nppp_f2b_cleanup_event' );
}

// Single, self-rotating rate-limit transient. One row, never N-per-minute.
if ( ! defined( 'NPPP_F2B_RATE_KEY' ) ) {
    define( 'NPPP_F2B_RATE_KEY', 'nppp_f2b_rl' );
}

// Safety valve only. A correctly configured fail2ban never approaches this.
if ( ! defined( 'NPPP_F2B_RATE_MAX_PER_MIN' ) ) {
    define( 'NPPP_F2B_RATE_MAX_PER_MIN', 300 );
}

// Rolling window for repeat-offender and event panels.
if ( ! defined( 'NPPP_F2B_WINDOW_DAYS' ) ) {
    define( 'NPPP_F2B_WINDOW_DAYS', 30 );
}

// Repeat Offenders panel — how many top IPs to display.
if ( ! defined( 'NPPP_F2B_RECIDIVE_TOP_N' ) ) {
    define( 'NPPP_F2B_RECIDIVE_TOP_N', 5 );
}

// Top Attack Countries panel — how many countries to display.
if ( ! defined( 'NPPP_F2B_TOP_COUNTRIES_N' ) ) {
    define( 'NPPP_F2B_TOP_COUNTRIES_N', 8 );
}

// World map bubbles — effectively "all of them" default.
if ( ! defined( 'NPPP_F2B_MAP_COUNTRIES_MAX' ) ) {
    define( 'NPPP_F2B_MAP_COUNTRIES_MAX', 300 );
}

// Not autoloaded. Caches whether country_code exists so we don't run
// SHOW COLUMNS on every Security tab load.
if ( ! defined( 'NPPP_F2B_COUNTRY_COL_OK_OPTION' ) ) {
    define( 'NPPP_F2B_COUNTRY_COL_OK_OPTION', 'nppp_f2b_country_col_ok' );
}

// LEGACY. Plugin no longer schedules this; kept so leftover cron events
// from <= 2.1.7 can drain. Current enrichment lives in fail2ban-worker.php.
if ( ! defined( 'NPPP_F2B_ENRICH_HOOK' ) ) {
    define( 'NPPP_F2B_ENRICH_HOOK', 'nppp_f2b_enrich_event' );
}

// Per-IP RDAP cache.
function nppp_f2b_rdap_cache_ttl(): int {
    $ttl = (int) apply_filters( 'nppp_f2b_rdap_cache_ttl', NPPP_F2B_WINDOW_DAYS * DAY_IN_SECONDS );
    if ( $ttl < HOUR_IN_SECONDS ) {
        $ttl = HOUR_IN_SECONDS;
    }
    if ( $ttl > 365 * DAY_IN_SECONDS ) {
        $ttl = 365 * DAY_IN_SECONDS;
    }
    return $ttl;
}

// Upper bound for the Live Feed. Retention already caps the table, but
// a jail under heavy attack can still push this into tens of thousands of rows.
if ( ! defined( 'NPPP_F2B_FEED_HARD_CAP' ) ) {
    define( 'NPPP_F2B_FEED_HARD_CAP', 5000 );
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

function nppp_f2b_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'nppp_f2b_events';
}

// ---------------------------------------------------------------------------
// Logging
//
// Direct append to the plugin log, same file and "[Y-m-d H:i:s] LEVEL ..."
// ---------------------------------------------------------------------------

/**
 * @param string $level   ERROR, WARNING or INFO.
 * @param string $message Already translated. Stripped, single-lined and capped here.
 */
function nppp_f2b_log( string $level, string $message ): void {
    $message = wp_html_excerpt( sanitize_text_field( $message ), 400, '...' );

    $line = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . strtoupper( $level ) . ' F2B: ' . $message . "\n";

    if ( function_exists( 'nppp_get_runtime_file' ) ) {
        $file = defined( 'NGINX_CACHE_LOG_FILE' ) ? NGINX_CACHE_LOG_FILE : nppp_get_runtime_file( 'fastcgi_ops.log' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false !== @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ) ) {
            return;
        }
    }

    // Log file not writable (runtime dir problem): PHP's error log is the last resort.
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    error_log( '[NPPP] ' . trim( $line ) );
}

/**
 * Timestamp gate for log lines that can fire from many requests.
 *
 * Returns 0 while $interval seconds have not passed since the last emit for
 * $key (suppressed). Otherwise returns how many events were seen since then
 * (at least 1) and re-arms the gate. The caller logs when the result is > 0.
 *
 * $count = false: only an emit writes the option. Cheap, for error paths that
 *                 may repeat on every request.
 * $count = true:  every call writes it, so the returned number is accurate.
 *                 Only for events that are rare by nature.
 *
 * Concurrent requests can occasionally both emit or lose a count. That is
 * acceptable for a log throttle.
 */
function nppp_f2b_log_gate( string $key, int $interval, bool $count = false ): int {
    $state = get_option( NPPP_F2B_LOG_GATE_OPTION, array() );
    if ( ! is_array( $state ) ) {
        $state = array();
    }

    $entry = ( isset( $state[ $key ] ) && is_array( $state[ $key ] ) ) ? $state[ $key ] : array();
    $last  = (int) ( $entry['t'] ?? 0 );
    $seen  = (int) ( $entry['n'] ?? 0 );

    if ( $count ) {
        $seen++;
    }

    $age = time() - $last;
    if ( $age >= 0 && $age < $interval ) {
        if ( $count ) {
            $state[ $key ] = array( 't' => $last, 'n' => $seen );
            update_option( NPPP_F2B_LOG_GATE_OPTION, $state, false );
        }
        return 0;
    }

    $state[ $key ] = array( 't' => time(), 'n' => 0 );
    update_option( NPPP_F2B_LOG_GATE_OPTION, $state, false );

    return max( 1, $seen );
}

/**
 * In-process throttle for the CLI worker and cron, where a single process
 * owns the loop and no option write is needed. True means: log now.
 */
function nppp_f2b_log_local_gate( string $key, int $interval ): bool {
    static $last = array();

    $now = time();
    if ( isset( $last[ $key ] ) ) {
        $age = $now - $last[ $key ];
        if ( $age >= 0 && $age < $interval ) {
            return false;
        }
    }

    $last[ $key ] = $now;
    return true;
}

// Retention period in days, filterable and clamped.
function nppp_f2b_retention_days(): int {
    $days = (int) apply_filters( 'nppp_f2b_retention_days', 90 );
    if ( $days < 1 ) {
        $days = 1;
    }
    if ( $days > 365 ) {
        $days = 365;
    }
    return $days;
}

/**
 * Create the event table (or heal it if the version stamp is stale) and
 * ensure its token and cleanup cron exist.
 *
 * Called on activation, from nppp_migration_218() on update-in-place
 * installs, and from admin_init self-heal. Schema version is stamped
 * only after confirming the table exists.
 */
function nppp_f2b_install_table(): void {
    global $wpdb;

    $table_name      = nppp_f2b_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // UTC storage keeps range queries timezone-safe.
    //
    // created_event_jail_idx: used by jail summaries and retention cleanup.
    // event_created_ip_idx: used to group ban events by ip.
    // ip_event_idx: worker write-back (UPDATE ... WHERE ip = ? AND event_type = 'ban').
    // queue_idx: enrichment queue (event_type = 'ban' AND rdap_json IS NULL). The
    //   1-char prefix is enough: only NULL vs non-NULL matters.
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        jail VARCHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        event_type VARCHAR(8) NOT NULL,
        created_at DATETIME NOT NULL,
        rdap_json LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY created_event_jail_idx (created_at, event_type, jail),
        KEY event_created_ip_idx (event_type, created_at, ip),
        KEY ip_event_idx (ip, event_type),
        KEY queue_idx (event_type, rdap_json(1))
    ) {$charset_collate};";

    dbDelta( $sql );

    // Confirm the table actually exists before stamping the schema version.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $nppp_f2b_table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
    );

    if ( $nppp_f2b_table_exists !== $table_name ) {
        // dbDelta() failed silently (e.g. no CREATE/ALTER privilege).
        // Don't stamp the version so the next admin_init retries -- but
        // that retry is silent too, so this needs its own gated ERROR or
        // the whole feature can stay dark indefinitely with no trace.
        if ( nppp_f2b_log_gate( 'install_table_fail', DAY_IN_SECONDS ) > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    /* translators: %s: name of the database table that could not be created. */
                    __( 'Fail2ban event table could not be created or verified (%s); the database user likely lacks CREATE/ALTER privilege. Events will not be recorded until this is fixed.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $table_name
                )
            );
        }
        return;
    }

    // country_code column and its index go outside dbDelta() on purpose --
    // dbDelta() can't diff GENERATED columns properly and may strip the
    // expression on a later run.
    nppp_f2b_ensure_country_code_column( $table_name );

    // Generate the token now so the setup snippets aren't empty on first load.
    nppp_f2b_get_token();

    nppp_f2b_schedule_cleanup();

    // Just a self-heal tick for the worker, not the dispatch path --
    // the webhook spawns the worker directly (see fail2ban-worker.php).
    if ( function_exists( 'nppp_f2b_schedule_worker_reconcile' ) ) {
        nppp_f2b_schedule_worker_reconcile();
    }

    // Not autoloaded -- only read once per admin_init, never on the front end.
    update_option( NPPP_F2B_DB_VERSION_OPTION, NPPP_F2B_DB_VERSION, false );
}

// Admin-only schema self-heal. REST requests do not run admin_init.
function nppp_f2b_maybe_install(): void {
    if ( get_option( NPPP_F2B_DB_VERSION_OPTION ) === NPPP_F2B_DB_VERSION ) {
        return;
    }
    nppp_f2b_install_table();
}

// ---------------------------------------------------------------------------
// Country aggregation (Top Attack Countries)
//
// STORED country_code + its index means we GROUP BY without re-parsing
// rdap_json. Uses the full retention window (90 days by default) since
// country spread shifts slower than the Repeat Offenders window does.
// ---------------------------------------------------------------------------

/**
 * Adds the country_code column and its index if missing. Idempotent,
 * safe to call on every install/self-heal. Runs outside dbDelta() since
 * it can't manage GENERATED columns.
 */
function nppp_f2b_ensure_country_code_column( string $table_name ): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $nppp_f2b_col_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW COLUMNS FROM %i LIKE %s',
            $table_name,
            'country_code'
        )
    );

    if ( ! $nppp_f2b_col_exists ) {
        // NULLIF turns an empty country into NULL so it's filtered out
        // together with rows that haven't been enriched yet.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, idempotent schema self-heal on a custom plugin table; no caching applies to a one-time ALTER TABLE
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional, idempotent schema self-heal on a custom plugin table, guarded by the SHOW COLUMNS check above
                'ALTER TABLE %i
                 ADD COLUMN country_code CHAR(2)
                     GENERATED ALWAYS AS (
                         NULLIF(UPPER(JSON_UNQUOTE(JSON_EXTRACT(rdap_json, \'$.country\'))), \'\')
                     ) STORED,
                 ADD INDEX ban_country_idx (event_type, created_at, country_code)',
                $table_name
            )
        );
    }

    // Re-check after ALTER since a privilege/support failure can be silent.
    // If it's still missing, just disable this feature instead of breaking
    // the Top Attack Countries query.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $nppp_f2b_col_ok = (bool) $wpdb->get_var(
        $wpdb->prepare(
            'SHOW COLUMNS FROM %i LIKE %s',
            $table_name,
            'country_code'
        )
    );

    if ( ! $nppp_f2b_col_ok && nppp_f2b_log_gate( 'country_col_fail', DAY_IN_SECONDS ) > 0 ) {
        nppp_f2b_log(
            'ERROR',
            __( 'country_code column could not be added to the fail2ban event table; the Top Attack Countries panel will stay disabled until this is fixed.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
    }

    // Not autoloaded -- only read once per Security tab load, never on the front end.
    update_option( NPPP_F2B_COUNTRY_COL_OK_OPTION, $nppp_f2b_col_ok, false );
}

// Cached flag so we skip SHOW COLUMNS on every tab load.
function nppp_f2b_country_feature_available(): bool {
    return (bool) get_option( NPPP_F2B_COUNTRY_COL_OK_OPTION, false );
}

// Full retention cutoff -- not the 30-day window used by Repeat Offenders.
function nppp_f2b_country_window_cutoff(): string {
    return gmdate( 'Y-m-d H:i:s', time() - ( nppp_f2b_retention_days() * DAY_IN_SECONDS ) );
}

// Index-only GROUP BY, no table access needed.
function nppp_f2b_get_top_countries( int $limit = NPPP_F2B_TOP_COUNTRIES_N ): array {
    if ( ! nppp_f2b_country_feature_available() ) {
        return array();
    }

    global $wpdb;
    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT country_code AS country, COUNT(*) AS attack_count, MAX(created_at) AS last_seen
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s AND country_code IS NOT NULL
             GROUP BY country_code
             ORDER BY attack_count DESC, country ASC
             LIMIT %d",
            $table,
            nppp_f2b_country_window_cutoff(),
            $limit
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Distinct country count, same filters as above -- just used to detect
// truncation in the UI.
function nppp_f2b_get_top_countries_total_count(): int {
    if ( ! nppp_f2b_country_feature_available() ) {
        return 0;
    }

    global $wpdb;
    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT country_code
                FROM %i
                WHERE event_type = 'ban' AND created_at >= %s AND country_code IS NOT NULL
                GROUP BY country_code
             ) AS nppp_top_countries",
            $table,
            nppp_f2b_country_window_cutoff()
        )
    );
}

// ---------------------------------------------------------------------------
// Retention cleanup, runs on WP-Cron only -- never on the webhook path.
// ---------------------------------------------------------------------------

function nppp_f2b_schedule_cleanup(): void {
    if ( wp_next_scheduled( NPPP_F2B_CLEANUP_HOOK )
        && wp_get_schedule( NPPP_F2B_CLEANUP_HOOK ) !== 'every_3hours_npp'
    ) {
        wp_clear_scheduled_hook( NPPP_F2B_CLEANUP_HOOK );
    }

    if ( ! wp_next_scheduled( NPPP_F2B_CLEANUP_HOOK ) ) {
        wp_schedule_event( time(), 'every_3hours_npp', NPPP_F2B_CLEANUP_HOOK );
    }
}

// Delete in batches so cleanup doesn't turn into one long-running query.
function nppp_f2b_cleanup_old_events(): void {
    global $wpdb;

    $table   = nppp_f2b_table_name();
    $cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( nppp_f2b_retention_days() * DAY_IN_SECONDS ) );
    $started = microtime( true );
    $batches = 0;

    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE created_at < %s LIMIT 1000',
                $table,
                $cutoff
            )
        );
        $batches++;

        if ( false === $deleted ) {
            if ( nppp_f2b_log_gate( 'cleanup_fail', HOUR_IN_SECONDS ) > 0 ) {
                nppp_f2b_log(
                    'ERROR',
                    sprintf(
                        /* translators: %s: database error message (not translated, comes from the DB driver). */
                        __( 'Retention cleanup DELETE failed: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $wpdb->last_error
                    )
                );
            }
            break;
        }
    } while ( 1000 === $deleted && $batches < 200 && ( microtime( true ) - $started ) < 10 );
}
add_action( NPPP_F2B_CLEANUP_HOOK, 'nppp_f2b_cleanup_old_events' );

// ---------------------------------------------------------------------------
// Token management -- generates the 64-char token EP10 checks against.
// ---------------------------------------------------------------------------

function nppp_f2b_get_token(): string {
    $token = get_option( NPPP_F2B_TOKEN_OPTION, '' );
    if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) {
        $token = nppp_f2b_regenerate_token();
    }
    return $token;
}

function nppp_f2b_regenerate_token(): string {
    $token = bin2hex( random_bytes( 32 ) );
    update_option( NPPP_F2B_TOKEN_OPTION, $token, false );
    return $token;
}

// ---------------------------------------------------------------------------
// RIPE lookup. The webhook only ever triggers this indirectly (via the
// worker); it never calls RIPE inline itself.
// ---------------------------------------------------------------------------

// Empty RDAP profile shape, every lookup starts from this.
function nppp_f2b_rdap_blank_result(): array {
    return array(
        'inetnum'      => '',
        'netname'      => '',
        'country'      => '',
        'org_id'       => '',
        'origin_asns'  => array(),
        'abuse_emails' => array(),
    );
}

function nppp_f2b_rdap_cache_key( string $ip ): string {
    return 'nppp_f2b_rdap_' . md5( $ip );
}

function nppp_f2b_rdap_whois_url( string $ip ): string {
    return add_query_arg( 'resource', rawurlencode( $ip ), 'https://stat.ripe.net/data/whois/data.json' );
}

function nppp_f2b_rdap_abuse_url( string $ip ): string {
    return add_query_arg( 'resource', rawurlencode( $ip ), 'https://stat.ripe.net/data/abuse-contact-finder/data.json' );
}

/**
 * Merges a decoded RIPEstat whois response into an RDAP profile.
 * Kept separate so both the serial (wp_remote_get) and parallel
 * (WpOrg\Requests worker) paths share one parser. $body isn't trusted
 * to actually be an array.
 */
function nppp_f2b_rdap_apply_whois( $body, array $result ): array {
    if ( ! is_array( $body ) || ! isset( $body['data'] ) ) {
        return $result;
    }

    // Different RIRs use different key names for the same fields:
    //   RIPE/APNIC/AFRINIC/LACNIC use inetnum/netname/country/org
    //   ARIN uses NetRange/CIDR, NetName, Country (only in the Org block),
    //   and Organization/OrgName/OrgId
    // Only matching the first style would silently drop every ARIN IP,
    // which covers most US cloud/hosting ranges. Check every record block
    // against both naming schemes, case-insensitively.
    foreach ( $body['data']['records'] ?? array() as $nppp_f2b_record_block ) {
        if ( ! is_array( $nppp_f2b_record_block ) ) {
            continue;
        }

        foreach ( $nppp_f2b_record_block as $record ) {
            $key   = strtolower( (string) ( $record['key'] ?? '' ) );
            $value = trim( (string) ( $record['value'] ?? '' ) );

            if ( '' === $value ) {
                continue;
            }

            // First non-empty value wins per field -- earlier blocks tend
            // to be the more specific registration.
            if ( '' === $result['inetnum'] && in_array( $key, array( 'inetnum', 'netrange', 'cidr' ), true ) ) {
                $result['inetnum'] = $value;
            } elseif ( '' === $result['netname'] && 'netname' === $key ) {
                $result['netname'] = $value;
            } elseif ( '' === $result['country'] && 'country' === $key ) {
                $result['country'] = nppp_f2b_rdap_clean_country( $value );
            } elseif ( '' === $result['org_id'] && in_array( $key, array( 'org', 'orgid', 'orgname', 'organization', 'custname' ), true ) ) {
                $result['org_id'] = $value;
            }
        }
    }

    foreach ( $body['data']['irr_records'] ?? array() as $route ) {
        if ( ! is_array( $route ) ) {
            continue;
        }
        foreach ( $route as $record ) {
            if ( 'origin' === ( $record['key'] ?? '' ) && ctype_digit( (string) $record['value'] ) ) {
                $result['origin_asns'][] = 'AS' . $record['value'];
            }
        }
    }
    $result['origin_asns'] = array_values( array_unique( $result['origin_asns'] ) );

    return $result;
}

// Merges a decoded RIPEstat abuse-contact response into an RDAP profile.
function nppp_f2b_rdap_apply_abuse( $body, array $result ): array {
    if ( ! is_array( $body ) ) {
        return $result;
    }

    foreach ( $body['data']['abuse_contacts'] ?? array() as $email ) {
        $email = sanitize_email( (string) $email );
        if ( '' !== $email ) {
            $result['abuse_emails'][] = $email;
        }
    }
    $result['abuse_emails'] = array_values( array_unique( $result['abuse_emails'] ) );

    return $result;
}

/**
 * Reduce a registry "country" value to a bare two-letter code, or ''.
 * The events table derives a CHAR(2) column from it; registries sometimes
 * append a comment ("EU # ...") or use a long name.
 */
function nppp_f2b_rdap_clean_country( $value ): string {
    if ( is_string( $value ) && preg_match( '/^\s*([A-Za-z]{2})(?:[\s#]|$)/', $value, $m ) ) {
        return strtoupper( $m[1] );
    }
    return '';
}

function nppp_f2b_rdap_has_data( array $result ): bool {
    return (
        '' !== $result['inetnum']
        || '' !== $result['netname']
        || '' !== $result['country']
        || '' !== $result['org_id']
        || ! empty( $result['origin_asns'] )
        || ! empty( $result['abuse_emails'] )
    );
}

/**
 * Caches an RDAP profile. Only uses the full TTL if we actually got
 * data back -- a temporary RIPE timeout shouldn't lock in an empty
 * result for 30 days and hide real data for this IP forever.
 */
function nppp_f2b_rdap_store_cache( string $ip, array $result ): void {
    $ttl = nppp_f2b_rdap_has_data( $result )
        ? nppp_f2b_rdap_cache_ttl()
        // Failed/empty lookups get a short TTL so we retry soon instead
        // of caching the failure for the full duration.
        : (int) apply_filters( 'nppp_f2b_rdap_negative_cache_ttl', 5 * MINUTE_IN_SECONDS );

    set_transient( nppp_f2b_rdap_cache_key( $ip ), $result, $ttl );
}

/**
 * Single-IP lookup, one request at a time.
 *
 * Only used as a fallback -- the rare host missing WpOrg\Requests, or the
 * inline cron batch when shell_exec is disabled. Normal enrichment goes
 * through nppp_f2b_lookup_ips_bulk() in the worker, which runs both
 * requests in parallel.
 */
function nppp_f2b_lookup_ip( string $ip, ?bool &$answered = null ): array {
    $cached = get_transient( nppp_f2b_rdap_cache_key( $ip ) );
    if ( is_array( $cached ) ) {
        $answered = true;
        return $cached;
    }

    $result = nppp_f2b_rdap_blank_result();

    $whois_response = wp_remote_get(
        nppp_f2b_rdap_whois_url( $ip ),
        array( 'timeout' => 3, 'headers' => array( 'Accept' => 'application/json' ) )
    );

    $whois_ok = false;
    if ( ! is_wp_error( $whois_response ) && 200 === (int) wp_remote_retrieve_response_code( $whois_response ) ) {
        $whois_body = json_decode( wp_remote_retrieve_body( $whois_response ), true );
        if ( is_array( $whois_body ) ) {
            $whois_ok = true;
            $result   = nppp_f2b_rdap_apply_whois( $whois_body, $result );
        }
    }

    $abuse_response = wp_remote_get(
        nppp_f2b_rdap_abuse_url( $ip ),
        array( 'timeout' => 3, 'headers' => array( 'Accept' => 'application/json' ) )
    );

    $abuse_ok = false;
    if ( ! is_wp_error( $abuse_response ) && 200 === (int) wp_remote_retrieve_response_code( $abuse_response ) ) {
        $abuse_body = json_decode( wp_remote_retrieve_body( $abuse_response ), true );
        if ( is_array( $abuse_body ) ) {
            $abuse_ok = true;
            $result   = nppp_f2b_rdap_apply_abuse( $abuse_body, $result );
        }
    }

    // WP_HTTP_BLOCK_EXTERNAL / request blocking is deterministic -- don't
    // spend a retry attempt on a request WordPress refused to send.
    $whois_blocked = is_wp_error( $whois_response ) && 'http_request_not_executed' === $whois_response->get_error_code();
    $abuse_blocked = is_wp_error( $abuse_response ) && 'http_request_not_executed' === $abuse_response->get_error_code();

    $answered = $whois_ok || $abuse_ok || $whois_blocked || $abuse_blocked;

    if ( $answered ) {
        nppp_f2b_rdap_store_cache( $ip, $result );
    }
    return $result;
}

/**
 * LEGACY. Nothing schedules this anymore -- only here to let leftover
 * per-event jobs from <= 2.1.7 finish once. Safe to remove later.
 */
add_action( NPPP_F2B_ENRICH_HOOK, 'nppp_f2b_enrich_event_callback', 10, 2 );
function nppp_f2b_enrich_event_callback( int $event_id, string $ip ): void {
    if ( $event_id <= 0 || '' === $ip ) {
        return;
    }
    $rdap = nppp_f2b_lookup_ip( $ip );

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $nppp_f2b_updated = $wpdb->update(
        nppp_f2b_table_name(),
        array( 'rdap_json' => wp_json_encode( $rdap ) ),
        array( 'id' => $event_id ),
        array( '%s' ),
        array( '%d' )
    );

    if ( false === $nppp_f2b_updated && nppp_f2b_log_gate( 'legacy_writeback_fail', 5 * MINUTE_IN_SECONDS, true ) > 0 ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %s: database error message (not translated, comes from the DB driver). */
                __( 'Legacy enrichment write-back failed: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $wpdb->last_error
            )
        );
    }
}

/**
 * Reuses cached RDAP data if we have it -- never makes a network call.
 * Ban events try this first before falling back to a real lookup;
 * unban events only ever use this path, they never trigger a fresh one.
 *
 * Returns true if it found and wrote cached data.
 */
function nppp_f2b_maybe_reuse_cached_rdap( int $event_id, string $ip ): bool {
    $cached = get_transient( 'nppp_f2b_rdap_' . md5( $ip ) );

    if ( ! is_array( $cached ) ) {
        return false;
    }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $nppp_f2b_updated = $wpdb->update(
        nppp_f2b_table_name(),
        array( 'rdap_json' => wp_json_encode( array_merge( $cached, array( 'country' => nppp_f2b_rdap_clean_country( $cached['country'] ?? '' ) ) ) ) ),
        array( 'id' => $event_id ),
        array( '%s' ),
        array( '%d' )
    );

    if ( false === $nppp_f2b_updated && nppp_f2b_log_gate( 'cache_writeback_fail', 5 * MINUTE_IN_SECONDS, true ) > 0 ) {
        nppp_f2b_log(
            'ERROR',
            sprintf(
                /* translators: %s: database error message (not translated, comes from the DB driver). */
                __( 'Write-back of a cached RDAP profile failed: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $wpdb->last_error
            )
        );
    }

    return false !== $nppp_f2b_updated;
}

/**
 * Called from nppp_f2b_handle_event() for 'ban' events.
 *
 * Cache hit: one indexed UPDATE, done, no network call.
 * Cache miss: leave rdap_json NULL (that's the queue) and make sure the
 * detached worker is running -- costs a stat, a signal-0 check, a flock
 * and maybe a backgrounded shell_exec.
 *
 * During a burst only the first cache-miss pays the spawn cost; the rest
 * just find the worker already running and let it drain the backlog.
 *
 * $response_payload is unused now (fastcgi_finish_request tier was
 * removed) but kept for signature compatibility.
 */
function nppp_f2b_maybe_enrich( int $event_id, string $ip, array $response_payload = array() ): void {
    if ( nppp_f2b_maybe_reuse_cached_rdap( $event_id, $ip ) ) {
        return;
    }

    if ( function_exists( 'nppp_f2b_maybe_spawn_worker' ) ) {
        nppp_f2b_maybe_spawn_worker();
    }
}

// ---------------------------------------------------------------------------
// REST route: POST /wp-json/nppp_f2b/v1/event
// EP10 (rest_api_init, priority 1) checks the token before the plugin
// bootstrap loads; WordPress core has already booted by then.
// ---------------------------------------------------------------------------

function nppp_f2b_register_routes() {
    register_rest_route(
        'nppp_f2b/v1',
        '/event',
        array(
            'methods'             => 'POST',
            'callback'            => 'nppp_f2b_handle_event',
            'permission_callback' => 'nppp_f2b_validate_request',
        )
    );
}
add_action( 'rest_api_init', 'nppp_f2b_register_routes' );

function nppp_f2b_validate_request( WP_REST_Request $request ) {
    // Read the stored token as-is -- don't generate one here.
    $stored = get_option( NPPP_F2B_TOKEN_OPTION, '' );

    $auth_header = $request->get_header( 'authorization' );
    $token       = '';
    if ( is_string( $auth_header ) && 0 === strpos( $auth_header, 'Bearer ' ) ) {
        $token = substr( $auth_header, 7 );
    }
    $token = sanitize_text_field( (string) $token );

    if ( ! is_string( $stored ) || '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
        $nppp_f2b_bad_tokens = nppp_f2b_log_gate( 'bad_token', 5 * MINUTE_IN_SECONDS, true );
        if ( $nppp_f2b_bad_tokens > 0 ) {
            nppp_f2b_log(
                'WARNING',
                sprintf(
                    /* translators: %d: number of rejected webhook auth attempts since the last report. */
                    __( 'Webhook rejected %d request(s) with an invalid or missing token since the last report.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $nppp_f2b_bad_tokens
                )
            );
        }

        return new WP_Error(
            'nppp_f2b_forbidden',
            __( 'Invalid or missing token.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 403 )
        );
    }

    return true;
}

// One rotating transient tracks the per-minute limit.
function nppp_f2b_rate_exceeded(): bool {
    $window = (int) floor( time() / 60 );
    $bucket = get_transient( NPPP_F2B_RATE_KEY );

    if ( ! is_array( $bucket ) || ( $bucket['w'] ?? 0 ) !== $window ) {
        $bucket = array(
            'w' => $window,
            'c' => 0,
        );
    }

    if ( (int) $bucket['c'] >= NPPP_F2B_RATE_MAX_PER_MIN ) {
        $nppp_f2b_drops = nppp_f2b_log_gate( 'rate_limited', MINUTE_IN_SECONDS, true );
        if ( $nppp_f2b_drops > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    /* translators: %1$d: number of webhook events dropped with HTTP 429 since the last report; %2$d: number of events allowed per minute before the limit kicks in. */
                    __( 'Webhook rate limit reached: %1$d event(s) dropped with 429 since the last report (limit %2$d/min).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $nppp_f2b_drops,
                    NPPP_F2B_RATE_MAX_PER_MIN
                )
            );
        }
        return true;
    }

    $bucket['c'] = (int) $bucket['c'] + 1;
    set_transient( NPPP_F2B_RATE_KEY, $bucket, 120 );

    return false;
}

function nppp_f2b_handle_event( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
        return new WP_Error(
            'nppp_f2b_bad_request',
            __( 'Invalid JSON body.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 400 )
        );
    }

    $jail_raw = isset( $body['jail'] ) ? (string) $body['jail'] : '';
    $ip_raw   = isset( $body['ip'] ) ? (string) $body['ip'] : '';
    $ev_raw   = isset( $body['event'] ) ? (string) $body['event'] : '';

    // "test" event comes from the Security tab's connection check.
    $is_test = ( 'test' === $ev_raw );

    // Test events don't count against the rate limit.
    if ( ! $is_test && nppp_f2b_rate_exceeded() ) {
        return new WP_Error(
            'nppp_f2b_rate_limited',
            __( 'Too many events this minute.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 429 )
        );
    }

    // fail2ban jail names are always plain identifiers, so validate as such.
    if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $jail_raw ) ) {
        return new WP_Error(
            'nppp_f2b_bad_request',
            __( 'Invalid jail name.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 400 )
        );
    }

    $ip = filter_var( $ip_raw, FILTER_VALIDATE_IP );
    if ( false === $ip ) {
        return new WP_Error(
            'nppp_f2b_bad_request',
            __( 'Invalid IP address.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 400 )
        );
    }

    // Test events go through the real INSERT, then delete their own row.
    if ( ! $is_test && ! in_array( $ev_raw, array( 'ban', 'unban' ), true ) ) {
        return new WP_Error(
            'nppp_f2b_bad_request',
            __( 'Invalid event type.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 400 )
        );
    }

    global $wpdb;

    // curl --retry in the fail2ban action can replay an event whose first
    // attempt already landed (timeout, or 5xx after the INSERT). Same event
    // for the same jail+ip within 60 s is a replay, not a new ban.
    if ( ! $is_test ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $nppp_f2b_replay = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, (rdap_json IS NULL) AS pending FROM %i WHERE event_type = %s AND created_at >= %s AND ip = %s AND jail = %s LIMIT 1',
                nppp_f2b_table_name(),
                $ev_raw,
                gmdate( 'Y-m-d H:i:s', time() - 60 ),
                $ip,
                $jail_raw
            ),
            ARRAY_A
        );
        if ( $nppp_f2b_replay ) {
            // Aggregated: at most one INFO line per 10 minutes, with the count.
            $nppp_f2b_dups = nppp_f2b_log_gate( 'replay_duplicate', 10 * MINUTE_IN_SECONDS, true );
            if ( $nppp_f2b_dups > 0 ) {
                nppp_f2b_log(
                    'INFO',
                    sprintf(
                        /* translators: %d: number of replayed (duplicate) webhook events ignored since the last report. */
                        __( 'Ignored %d replayed webhook event(s) since the last report (curl retry of an event that was already stored).', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $nppp_f2b_dups
                    )
                );
            }
            // The first attempt may have died after its INSERT but before it
            // queued enrichment. Cheap when the row is done or a worker is up.
            if ( 'ban' === $ev_raw && ! empty( $nppp_f2b_replay['pending'] ) ) {
                nppp_f2b_maybe_enrich( (int) $nppp_f2b_replay['id'], $ip );
            }
            return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
        }
    }

    // Fast insert first, rdap_json stays NULL -- enrichment happens after.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $inserted = $wpdb->insert(
        nppp_f2b_table_name(),
        array(
            'jail'       => $jail_raw,
            'ip'         => $ip,
            'event_type' => $is_test ? 'test' : $ev_raw,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'rdap_json'  => null,
        ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );

    if ( $is_test ) {
        if ( $inserted && $wpdb->insert_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete( nppp_f2b_table_name(), array( 'id' => (int) $wpdb->insert_id ), array( '%d' ) );
        }

        return rest_ensure_response(
            array(
                'ok'    => true,
                'test'  => true,
                'write' => (bool) $inserted,
            )
        );
    }

    if ( false === $inserted ) {
        // Read last_error first, the gate below may run queries of its own.
        $nppp_f2b_db_error = $wpdb->last_error;
        $nppp_f2b_fails    = nppp_f2b_log_gate( 'insert_fail', MINUTE_IN_SECONDS, true );
        if ( $nppp_f2b_fails > 0 ) {
            nppp_f2b_log(
                'ERROR',
                sprintf(
                    /* translators: %1$d: number of insert failures since the last report; %2$s: database error message (not translated, comes from the DB driver). */
                    __( 'Webhook event insert failed (%1$d since the last report): %2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $nppp_f2b_fails,
                    $nppp_f2b_db_error
                )
            );
        }

        return new WP_Error(
            'nppp_f2b_db_error',
            __( 'Event could not be stored.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 500 )
        );
    }

    // Bans get full enrichment (cache first, else spawn the worker).
    // Unbans never trigger a fresh RIPE lookup, but reuse cached data
    // if we already have it -- costs nothing.
    if ( $wpdb->insert_id ) {
        if ( 'ban' === $ev_raw ) {
            nppp_f2b_maybe_enrich( (int) $wpdb->insert_id, $ip, array( 'ok' => true ) );
        } elseif ( 'unban' === $ev_raw ) {
            nppp_f2b_maybe_reuse_cached_rdap( (int) $wpdb->insert_id, $ip );
        }
    }

    return rest_ensure_response( array( 'ok' => true ) );
}

// ---------------------------------------------------------------------------
// Data access. Aggregates use bounded time windows and composite indexes;
// recent-events and existence checks use LIMIT-bounded PK lookups.
// ---------------------------------------------------------------------------

function nppp_f2b_window_cutoff(): string {
    return gmdate( 'Y-m-d H:i:s', time() - ( NPPP_F2B_WINDOW_DAYS * DAY_IN_SECONDS ) );
}

// Uses the created_at/jail composite index.
function nppp_f2b_get_jail_summaries( int $since_hours = 24 ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();
    $since = gmdate( 'Y-m-d H:i:s', time() - ( $since_hours * HOUR_IN_SECONDS ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT jail,
                    SUM(event_type = 'ban')   AS bans,
                    SUM(event_type = 'unban') AS unbans,
                    MAX(created_at)           AS last_event
             FROM %i
             WHERE created_at >= %s
             GROUP BY jail
             ORDER BY bans DESC, jail ASC
             LIMIT 50",
            $table,
            $since
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Same as nppp_f2b_get_jail_summaries() but for an explicit [since, until)
// range -- used to get the prior period for the % change badges.
function nppp_f2b_get_jail_summaries_between( string $since, string $until ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT jail,
                    SUM(event_type = 'ban')   AS bans,
                    SUM(event_type = 'unban') AS unbans,
                    MAX(created_at)           AS last_event
             FROM %i
             WHERE created_at >= %s AND created_at < %s
             GROUP BY jail
             ORDER BY bans DESC, jail ASC
             LIMIT 50",
            $table,
            $since,
            $until
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Repeat-offender query, hits the event_type/ip composite index.
function nppp_f2b_get_recidive_ips( int $min_count = 2, int $limit = 25 ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ip, COUNT(*) AS ban_count, MAX(created_at) AS last_ban
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s
             GROUP BY ip
             HAVING ban_count >= %d
             ORDER BY ban_count DESC, last_ban DESC
             LIMIT %d",
            $table,
            nppp_f2b_window_cutoff(),
            $min_count,
            $limit
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Distinct IP count with the same filters as above, just to detect
// truncation for the UI.
function nppp_f2b_get_recidive_total_count( int $min_count = 2 ): int {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT ip
                FROM %i
                WHERE event_type = 'ban' AND created_at >= %s
                GROUP BY ip
                HAVING COUNT(*) >= %d
             ) AS nppp_recidive_ips",
            $table,
            nppp_f2b_window_cutoff(),
            $min_count
        )
    );
}

// PK descending scan, always LIMIT-bounded. $limit = 0 means "all events",
// but that's still capped by NPPP_F2B_FEED_HARD_CAP. Retention cleanup
// already keeps the table small on most installs anyway.
function nppp_f2b_get_recent_events( int $limit = 0 ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();

    $hard_cap = (int) apply_filters( 'nppp_f2b_feed_hard_cap', NPPP_F2B_FEED_HARD_CAP );
    if ( $hard_cap < 1 ) {
        $hard_cap = 1;
    }

    $limit = ( $limit > 0 ) ? min( $limit, $hard_cap ) : $hard_cap;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT jail, ip, event_type, created_at, rdap_json
             FROM %i
             ORDER BY id DESC
             LIMIT %d',
            $table,
            $limit
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Total row count. Only ever runs once per Security tab load.
function nppp_f2b_get_total_event_count(): int {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
}

// Bounded COUNT over a time range, not the whole table.
function nppp_f2b_get_window_event_count(): int {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
            $table,
            nppp_f2b_window_cutoff()
        )
    );
}

// Same as above but for an explicit [since, until) range -- used for the
// "Events / Nd" card's % change badge.
function nppp_f2b_get_window_event_count_between( string $since, string $until ): int {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at < %s',
            $table,
            $since,
            $until
        )
    );
}

// Ban/unban split for the "Events / Nd" card's sub-line. One SUM() query
// over the same window nppp_f2b_get_window_event_count() already counts --
// test events (event_type = 'test') are excluded from both, same as the
// total, since they're deleted immediately after the self-test anyway.
function nppp_f2b_get_window_ban_unban_split(): array {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT SUM(event_type = 'ban')   AS bans,
                    SUM(event_type = 'unban') AS unbans
             FROM %i
             WHERE created_at >= %s",
            $table,
            nppp_f2b_window_cutoff()
        ),
        ARRAY_A
    );

    return array(
        'bans'   => is_array( $row ) ? (int) ( $row['bans'] ?? 0 ) : 0,
        'unbans' => is_array( $row ) ? (int) ( $row['unbans'] ?? 0 ) : 0,
    );
}

// Check whether at least one event exists.
function nppp_f2b_has_any_events(): bool {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $table ) );
}

// Turns current/previous counts into a change badge (direction, %, label).
// Returns null if both periods are empty -- template just skips the badge then.
function nppp_f2b_pct_change( int $current, int $previous ): ?array {
    if ( $current <= 0 && $previous <= 0 ) {
        return null;
    }

    if ( $previous <= 0 ) {
        // Nothing in the prior period, so % is undefined -- show "New" instead.
        return array(
            'dir'   => 'up',
            'pct'   => null,
            'label' => __( 'New', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    $delta = $current - $previous;
    $pct   = ( $delta / $previous ) * 100;

    if ( 0 === $delta ) {
        $dir = 'flat';
    } elseif ( $delta > 0 ) {
        $dir = 'up';
    } else {
        $dir = 'down';
    }

    return array(
        'dir'   => $dir,
        'pct'   => $pct,
        'label' => sprintf( '%s%d%%', $delta > 0 ? '+' : '', (int) round( $pct ) ),
    );
}

// UTC column -> site timezone, for display only.
function nppp_f2b_local_time( string $gmt_datetime ): string {
    if ( '' === $gmt_datetime ) {
        return '';
    }
    return get_date_from_gmt( $gmt_datetime, 'Y-m-d H:i:s' );
}

// ---------------------------------------------------------------------------
// Config snippets shown in the UI for setting up fail2ban.
// ---------------------------------------------------------------------------

function nppp_f2b_get_endpoint_url(): string {
    return esc_url_raw( rest_url( 'nppp_f2b/v1/event' ) );
}

function nppp_f2b_get_action_conf_snippet(): string {
    $endpoint = nppp_f2b_get_endpoint_url();

    // nohup + & detaches curl from fail2ban's action queue so the jail
    // doesn't wait on the network call before banning the next IP.
    return "[Definition]\n" .
        "actionban   = nohup curl -sS -o /dev/null --max-time 10 --connect-timeout 3 --retry 2 --retry-delay 1 --retry-connrefused -X POST {$endpoint} \\\n" .
        "                -H \"Authorization: Bearer %(nppp_token)s\" \\\n" .
        "                -H \"Content-Type: application/json\" \\\n" .
        "                -d '{\"event\":\"ban\",\"jail\":\"<name>\",\"ip\":\"<ip>\"}' \\\n" .
        "                >/dev/null 2>&1 &\n" .
        "actionunban = nohup curl -sS -o /dev/null --max-time 10 --connect-timeout 3 --retry 2 --retry-delay 1 --retry-connrefused -X POST {$endpoint} \\\n" .
        "                -H \"Authorization: Bearer %(nppp_token)s\" \\\n" .
        "                -H \"Content-Type: application/json\" \\\n" .
        "                -d '{\"event\":\"unban\",\"jail\":\"<name>\",\"ip\":\"<ip>\"}' \\\n" .
        "                >/dev/null 2>&1 &\n" .
        "\n" .
        "[Init]\n" .
        "nppp_token =\n";
}

function nppp_f2b_get_jail_local_snippet(): string {
    $token = nppp_f2b_get_token();

    $comment = __(
        "Add this under each nginx-related [jail] section in jail.local.\nIf the jail already defines its own \"action = ...\" line, APPEND the\nnppp-webhook[...] line to it instead of replacing it — otherwise you\ndisable that jail's real ban action.",
        'fastcgi-cache-purge-and-preload-nginx'
    );
    $comment = '# ' . str_replace( "\n", "\n# ", $comment );

    return "action = %(action_)s\n" .
        "         nppp-webhook[nppp_token=\"{$token}\"]\n" .
        "\n" .
        $comment . "\n";
}

// ---------------------------------------------------------------------------
// AJAX callbacks, registered centrally by the admin module.
// ---------------------------------------------------------------------------

function nppp_f2b_load_tab_content_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    // Just a safety net -- covers admins hitting this before the update
    // check runs, or on a multisite sub-site.
    nppp_f2b_maybe_install();

    $summaries      = nppp_f2b_get_jail_summaries( 24 );
    $recidive       = nppp_f2b_get_recidive_ips( 2, (int) apply_filters( 'nppp_f2b_recidive_top_n', NPPP_F2B_RECIDIVE_TOP_N ) );
    $recidive_total = nppp_f2b_get_recidive_total_count( 2 );
    $recent         = nppp_f2b_get_recent_events();
    $total_events   = nppp_f2b_get_total_event_count();
    $feed_truncated = $total_events > count( $recent );
    $configured     = nppp_f2b_has_any_events();

    // Prior periods for the "% vs last period" badges -- the 24h and Nd
    // windows right before the current ones. Two extra queries per tab load.
    $nppp_prev_24h_since = gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) );
    $nppp_prev_24h_until = gmdate( 'Y-m-d H:i:s', time() - ( 24 * HOUR_IN_SECONDS ) );
    $summaries_prev      = nppp_f2b_get_jail_summaries_between( $nppp_prev_24h_since, $nppp_prev_24h_until );

    $nppp_prev_window_since = gmdate( 'Y-m-d H:i:s', time() - ( 2 * NPPP_F2B_WINDOW_DAYS * DAY_IN_SECONDS ) );
    $nppp_prev_window_until = gmdate( 'Y-m-d H:i:s', time() - ( NPPP_F2B_WINDOW_DAYS * DAY_IN_SECONDS ) );
    $nppp_window_prev_count = nppp_f2b_get_window_event_count_between( $nppp_prev_window_since, $nppp_prev_window_until );

    // Top Attack Countries uses the full retention window (90 days by
    // default), separate from the 30-day Repeat Offenders window above.
    $country_available   = nppp_f2b_country_feature_available();
    $country_days        = nppp_f2b_retention_days();
    $top_countries_n     = (int) apply_filters( 'nppp_f2b_top_countries_n', NPPP_F2B_TOP_COUNTRIES_N );
    $top_countries       = $country_available ? nppp_f2b_get_top_countries( $top_countries_n ) : array();
    $top_countries_total = $country_available ? nppp_f2b_get_top_countries_total_count() : 0;

    // Bubble map shows every country in the window, not just the top N --
    // same query, effectively no LIMIT (see NPPP_F2B_MAP_COUNTRIES_MAX).
    $map_countries_max = (int) apply_filters( 'nppp_f2b_map_countries_n', NPPP_F2B_MAP_COUNTRIES_MAX );
    $top_countries_map = $country_available ? nppp_f2b_get_top_countries( $map_countries_max ) : array();

    // Ban/unban split for the "Events / Nd" card's sub-line ("↑ N bans · ↓ M unbans").
    $nppp_window_split = nppp_f2b_get_window_ban_unban_split();

    $stats = array(
        'bans_24h'      => (int) array_sum( array_map( 'intval', array_column( $summaries, 'bans' ) ) ),
        'unbans_24h'    => (int) array_sum( array_map( 'intval', array_column( $summaries, 'unbans' ) ) ),
        'jails'         => count( $summaries ),
        'window'        => nppp_f2b_get_window_event_count(),
        'window_bans'   => $nppp_window_split['bans'],
        'window_unbans' => $nppp_window_split['unbans'],
    );

    $stats_prev = array(
        'bans_24h'   => (int) array_sum( array_map( 'intval', array_column( $summaries_prev, 'bans' ) ) ),
        'unbans_24h' => (int) array_sum( array_map( 'intval', array_column( $summaries_prev, 'unbans' ) ) ),
        'jails'      => count( $summaries_prev ),
        'window'     => $nppp_window_prev_count,
    );

    // Percentage-change badges for the 4 Activity Overview cards.
    $stats_change = array(
        'bans_24h'   => nppp_f2b_pct_change( $stats['bans_24h'], $stats_prev['bans_24h'] ),
        'unbans_24h' => nppp_f2b_pct_change( $stats['unbans_24h'], $stats_prev['unbans_24h'] ),
        'jails'      => nppp_f2b_pct_change( $stats['jails'], $stats_prev['jails'] ),
        'window'     => nppp_f2b_pct_change( $stats['window'], $stats_prev['window'] ),
    );

    // Per-jail change badge, bans only (unbans aren't tracked here).
    // Indexed by jail name for quick lookup in the template.
    $nppp_prev_bans_by_jail = array();
    foreach ( $summaries_prev as $nppp_prev_row ) {
        $nppp_prev_bans_by_jail[ $nppp_prev_row['jail'] ] = (int) $nppp_prev_row['bans'];
    }

    $jail_bans_change = array();
    foreach ( $summaries as $nppp_cur_row ) {
        $jail_bans_change[ $nppp_cur_row['jail'] ] = nppp_f2b_pct_change(
            (int) $nppp_cur_row['bans'],
            $nppp_prev_bans_by_jail[ $nppp_cur_row['jail'] ] ?? 0
        );
    }

    // Abuse Reporter: one options read, plus one bounded IN() lookup
    // for offender contacts, only if the reporter is actually enabled.
    $abuse_settings   = nppp_f2b_get_abuse_settings();
    $abuse_ready      = nppp_f2b_abuse_is_ready( $abuse_settings );
    $abuse_report_log = $abuse_ready ? nppp_f2b_get_abuse_report_log() : array();
    $abuse_contacts   = ( $abuse_ready && ! empty( $recidive ) )
        ? nppp_f2b_get_abuse_map_for_ips( array_column( $recidive, 'ip' ) )
        : array();

    $token          = nppp_f2b_get_token();
    $endpoint       = nppp_f2b_get_endpoint_url();
    $action_snippet = nppp_f2b_get_action_conf_snippet();
    $jail_snippet   = nppp_f2b_get_jail_local_snippet();
    $window_days    = NPPP_F2B_WINDOW_DAYS;
    $retention_days = nppp_f2b_retention_days();

    ob_start();
    include plugin_dir_path( __FILE__ ) . 'partials/fail2ban-tab.php';
    $html = ob_get_clean();

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is the captured output of partials/fail2ban-tab.php, which escapes every dynamic value itself (esc_html/esc_attr/esc_url) at the point of use. Re-escaping the whole buffer here would double-encode entities and break the rendered markup. Do not run wp_kses_post() here.
    echo $html;
    wp_die();
}

function nppp_f2b_regenerate_token_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    $token = nppp_f2b_regenerate_token();

    nppp_f2b_log(
        'INFO',
        sprintf(
            /* translators: %d: WordPress user ID. */
            __( 'Webhook token regenerated by user #%d; jails still sending the old token are rejected until jail.local is updated.', 'fastcgi-cache-purge-and-preload-nginx' ),
            get_current_user_id()
        )
    );

    wp_send_json_success(
        array(
            'token'        => $token,
            'jail_snippet' => nppp_f2b_get_jail_local_snippet(),
        )
    );
}

function nppp_f2b_clear_events_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    global $wpdb;
    $table = nppp_f2b_table_name();

    // Use DELETE so the action works without DROP privilege.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $nppp_f2b_deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

    if ( false === $nppp_f2b_deleted ) {
        wp_send_json_error(
            array( 'message' => __( 'Could not clear the event log. Check that the database user has DELETE privilege on this table.', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            500
        );
        return;
    }

    nppp_f2b_log(
        'INFO',
        sprintf(
            /* translators: %1$d: WordPress user ID; %2$d: number of event rows deleted. */
            __( 'Event log cleared by user #%1$d (%2$d row(s) removed).', 'fastcgi-cache-purge-and-preload-nginx' ),
            get_current_user_id(),
            (int) $nppp_f2b_deleted
        )
    );

    wp_send_json_success(
        array( 'message' => __( 'All events cleared.', 'fastcgi-cache-purge-and-preload-nginx' ) )
    );
}

/**
 * Runs a self-test through the same HTTP path fail2ban uses.
 */
function nppp_f2b_test_connection_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    $endpoint = nppp_f2b_get_endpoint_url();
    $token    = nppp_f2b_get_token();

    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout'   => 8,
            'sslverify' => apply_filters(
                'nppp_f2b_selftest_sslverify',
                apply_filters( 'https_local_ssl_verify', false )
            ),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'      => wp_json_encode(
                array(
                    'event' => 'test',
                    'jail'  => 'nppp-selftest',
                    // RFC 5737 TEST-NET-3 — never a routable address.
                    'ip'    => '203.0.113.1',
                )
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        wp_send_json_success(
            array(
                'ok'      => false,
                'message' => sprintf(
                    /* translators: %s: transport level error message */
                    __( 'Connection failed: %s. Check that this server can reach its own public URL over loopback and that no firewall rule blocks it.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $response->get_error_message()
                ),
            )
        );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 === $code && is_array( $body ) && ! empty( $body['ok'] ) ) {
        if ( empty( $body['write'] ) ) {
            wp_send_json_success(
                array(
                    'ok'      => false,
                    'message' => __( 'The endpoint is reachable and the token was accepted, but the test row could not be written. Check that the database user can write to the event table, then reload this tab.', 'fastcgi-cache-purge-and-preload-nginx' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'ok'      => true,
                'message' => __( 'Success. The webhook endpoint is reachable, the token was accepted and a test event was written and removed. fail2ban will be able to reach it too.', 'fastcgi-cache-purge-and-preload-nginx' ),
            )
        );
    }

    if ( 404 === $code ) {
        wp_send_json_success(
            array(
                'ok'      => false,
                'message' => __( 'HTTP 404 — the route never registered, which almost always means your web server is not forwarding the Authorization header to PHP. On Nginx + PHP-FPM, add fastcgi_param HTTP_AUTHORIZATION $http_authorization; inside the PHP location block, reload Nginx, then test again.', 'fastcgi-cache-purge-and-preload-nginx' ),
            )
        );
    }

    if ( 403 === $code ) {
        wp_send_json_success(
            array(
                'ok'      => false,
                'message' => __( 'HTTP 403 — the token was rejected. Use Regenerate, re-copy the jail.local snippet and reload fail2ban.', 'fastcgi-cache-purge-and-preload-nginx' ),
            )
        );
    }

    if ( 429 === $code ) {
        wp_send_json_success(
            array(
                'ok'      => false,
                'message' => __( 'HTTP 429 — rejected by a rate limit before the test event was recorded. The webhook locks an IP out for up to an hour after 20 rejected tokens (an old token still in jail.local is the usual cause); a web server, WAF or CDN rate limit can return 429 as well.', 'fastcgi-cache-purge-and-preload-nginx' ),
            )
        );
    }

    wp_send_json_success(
        array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                __( 'Unexpected response (HTTP %d). Check your PHP and Nginx error logs.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $code
            ),
        )
    );
}
