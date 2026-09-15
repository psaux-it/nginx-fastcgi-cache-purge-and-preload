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

// Bearer token option. Read raw by the EP10 gate in the main plugin file.
if ( ! defined( 'NPPP_F2B_TOKEN_OPTION' ) ) {
    define( 'NPPP_F2B_TOKEN_OPTION', 'nppp_f2b_token' );
}

// Schema stamp. Bump only when the table definition changes.
if ( ! defined( 'NPPP_F2B_DB_VERSION' ) ) {
    define( 'NPPP_F2B_DB_VERSION', '1.0.0' );
}
if ( ! defined( 'NPPP_F2B_DB_VERSION_OPTION' ) ) {
    define( 'NPPP_F2B_DB_VERSION_OPTION', 'nppp_f2b_db_version' );
}

// Retention cron hook. Registered in the EP2 cron list of the main plugin file.
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

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

function nppp_f2b_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'nppp_f2b_events';
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
 * Create/update the event table and ensure its token and cleanup cron exist.
 *
 * Called on activation, migration, and admin_init self-heal.
 * Schema version is stamped only after confirming the table exists.
 */
function nppp_f2b_install_table(): void {
    global $wpdb;

    $table_name      = nppp_f2b_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // UTC storage keeps range queries timezone-safe.
    //
    // created_event_jail_idx supports the jail summary and retention delete.
    //
    // event_ip_created_idx matches event_type = 'ban' + GROUP BY ip and
    // keeps created_at available for the aggregate.
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        jail VARCHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        event_type VARCHAR(8) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY created_event_jail_idx (created_at, event_type, jail),
        KEY event_ip_created_idx (event_type, ip, created_at)
    ) {$charset_collate};";

    dbDelta( $sql );

    // Confirm the table actually exists before stamping the schema version.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $nppp_f2b_table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
    );

    if ( $nppp_f2b_table_exists !== $table_name ) {
        // dbDelta() failed silently (e.g. a DB user without CREATE/ALTER).
        // Leave the version stamp untouched so the next admin_init retries
        // instead of believing the schema is current when it is not.
        return;
    }

    // Pre-generate the token so the setup snippets are complete the very first
    // time an admin opens the Fail2Ban tab.
    nppp_f2b_get_token();

    nppp_f2b_schedule_cleanup();

    // Not autoloaded: this stamp is read once per admin_init via
    // nppp_f2b_maybe_install(), never on the front end, so there is no
    // reason to add it to the autoloaded options blob loaded on every request.
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
// Retention cleanup
// Runs on WP-Cron, never on the webhook insert path.
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

// Delete in bounded batches to avoid long-running cleanup queries.
function nppp_f2b_cleanup_old_events(): void {
    global $wpdb;

    $table   = nppp_f2b_table_name();
    $cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( nppp_f2b_retention_days() * DAY_IN_SECONDS ) );
    $started = microtime( true );
    $batches = 0;

    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT 1000", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix
                $cutoff
            )
        );
        $batches++;
    } while ( 1000 === $deleted && $batches < 200 && ( microtime( true ) - $started ) < 10 );
}
add_action( NPPP_F2B_CLEANUP_HOOK, 'nppp_f2b_cleanup_old_events' );

// ---------------------------------------------------------------------------
// Token management
// Generates the 64-character token used by EP10.
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
// REST route: POST /wp-json/nppp_f2b/v1/event
//
// Dedicated REST namespace for the Fail2Ban webhook.
// EP10 performs the pre-bootstrap token gate.
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
    // Read the stored token directly; do not self-generate credentials here.
    $stored = get_option( NPPP_F2B_TOKEN_OPTION, '' );

    $auth_header = $request->get_header( 'authorization' );
    $token       = '';
    if ( is_string( $auth_header ) && 0 === strpos( $auth_header, 'Bearer ' ) ) {
        $token = substr( $auth_header, 7 );
    }
    $token = sanitize_text_field( (string) $token );

    if ( ! is_string( $stored ) || '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
        return new WP_Error(
            'nppp_f2b_forbidden',
            __( 'Invalid or missing token.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 403 )
        );
    }

    return true;
}

// One rotating transient stores the per-minute safety limit.
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

    // The Security tab uses "test" for its connection diagnostic.
    $is_test = ( 'test' === $ev_raw );

    // Only real ban/unban events consume the rate-limit budget.
    if ( ! $is_test && nppp_f2b_rate_exceeded() ) {
        return new WP_Error(
            'nppp_f2b_rate_limited',
            __( 'Too many events this minute.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 429 )
        );
    }

    // fail2ban restricts jail names to safe identifier characters.
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

    // Test events use the real INSERT path, then remove their own row.
    if ( ! $is_test && ! in_array( $ev_raw, array( 'ban', 'unban' ), true ) ) {
        return new WP_Error(
            'nppp_f2b_bad_request',
            __( 'Invalid event type.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 400 )
        );
    }

    global $wpdb;

    // Store UTC; convert to site time only for display.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $inserted = $wpdb->insert(
        nppp_f2b_table_name(),
        array(
            'jail'       => $jail_raw,
            'ip'         => $ip,
            'event_type' => $is_test ? 'test' : $ev_raw,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ),
        array( '%s', '%s', '%s', '%s' )
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
        return new WP_Error(
            'nppp_f2b_db_error',
            __( 'Event could not be stored.', 'fastcgi-cache-purge-and-preload-nginx' ),
            array( 'status' => 500 )
        );
    }

    return rest_ensure_response( array( 'ok' => true ) );
}

// ---------------------------------------------------------------------------
// Data access
// Aggregate queries use bounded time windows and composite indexes.
// Recent events and existence checks use LIMIT-bounded primary-key lookups.
// ---------------------------------------------------------------------------

function nppp_f2b_window_cutoff(): string {
    return gmdate( 'Y-m-d H:i:s', time() - ( NPPP_F2B_WINDOW_DAYS * DAY_IN_SECONDS ) );
}

// Uses the created_at/jail composite index.
function nppp_f2b_get_jail_summaries( int $since_hours = 24 ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();
    $since = gmdate( 'Y-m-d H:i:s', time() - ( $since_hours * HOUR_IN_SECONDS ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT jail,
                    SUM(event_type = 'ban')   AS bans,
                    SUM(event_type = 'unban') AS unbans,
                    MAX(created_at)           AS last_event
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY jail
             ORDER BY bans DESC, jail ASC
             LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix
            $since
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Bounded repeat-offender query using the event_type/ip composite index.
function nppp_f2b_get_recidive_ips( int $min_count = 2, int $limit = 25 ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ip, COUNT(*) AS ban_count, MAX(created_at) AS last_ban
             FROM {$table}
             WHERE event_type = 'ban' AND created_at >= %s
             GROUP BY ip
             HAVING ban_count >= %d
             ORDER BY ban_count DESC, last_ban DESC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix
            nppp_f2b_window_cutoff(),
            $min_count,
            $limit
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Primary-key descending scan, LIMIT-bounded. At most $limit row lookups.
function nppp_f2b_get_recent_events( int $limit = 50 ): array {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT jail, ip, event_type, created_at
             FROM {$table}
             ORDER BY id DESC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix
            $limit
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

// Range-scan COUNT, never an unbounded COUNT(*).
function nppp_f2b_get_window_event_count(): int {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix
            nppp_f2b_window_cutoff()
        )
    );
}

// Check whether at least one event exists.
function nppp_f2b_has_any_events(): bool {
    global $wpdb;

    $table = nppp_f2b_table_name();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (bool) $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix
}

// UTC column -> site timezone, for display only.
function nppp_f2b_local_time( string $gmt_datetime ): string {
    if ( '' === $gmt_datetime ) {
        return '';
    }
    return get_date_from_gmt( $gmt_datetime, 'Y-m-d H:i:s' );
}

// ---------------------------------------------------------------------------
// Setup snippet generators
// Snippets shown in the UI for fail2ban configuration.
// ---------------------------------------------------------------------------

function nppp_f2b_get_endpoint_url(): string {
    return esc_url_raw( rest_url( 'nppp_f2b/v1/event' ) );
}

function nppp_f2b_get_action_conf_snippet(): string {
    $endpoint = nppp_f2b_get_endpoint_url();

    return "[Definition]\n" .
        "actionban   = curl -sS -o /dev/null --max-time 3 --connect-timeout 2 -X POST {$endpoint} \\\n" .
        "                -H \"Authorization: Bearer %(nppp_token)s\" \\\n" .
        "                -H \"Content-Type: application/json\" \\\n" .
        "                -d '{\"event\":\"ban\",\"jail\":\"<name>\",\"ip\":\"<ip>\"}'\n" .
        "actionunban = curl -sS -o /dev/null --max-time 3 --connect-timeout 2 -X POST {$endpoint} \\\n" .
        "                -H \"Authorization: Bearer %(nppp_token)s\" \\\n" .
        "                -H \"Content-Type: application/json\" \\\n" .
        "                -d '{\"event\":\"unban\",\"jail\":\"<name>\",\"ip\":\"<ip>\"}'\n" .
        "\n" .
        "[Init]\n" .
        "nppp_token =\n";
}

function nppp_f2b_get_jail_local_snippet(): string {
    $token = nppp_f2b_get_token();

    return "action = %(action_)s\n" .
        "         nppp-webhook[nppp_token=\"{$token}\"]\n" .
        "\n" .
        "# Add this under each nginx-related [jail] section in jail.local.\n" .
        "# If the jail already defines its own \"action = ...\" line, APPEND the\n" .
        "# nppp-webhook[...] line to it instead of replacing it — otherwise you\n" .
        "# disable that jail's real ban action.\n";
}

// ---------------------------------------------------------------------------
// AJAX callbacks
// AJAX callbacks registered centrally by the admin module.
// ---------------------------------------------------------------------------

function nppp_f2b_load_tab_content_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    // Defensive: an admin who lands here before nppp_check_for_plugin_update()
    // has run (or on a multisite sub-site) still gets a working table.
    nppp_f2b_maybe_install();

    $summaries = nppp_f2b_get_jail_summaries( 24 );
    $recidive  = nppp_f2b_get_recidive_ips( 2, 25 );
    $recent    = nppp_f2b_get_recent_events( 50 );
    $configured = nppp_f2b_has_any_events();

    $stats = array(
        'bans_24h'   => (int) array_sum( array_map( 'intval', array_column( $summaries, 'bans' ) ) ),
        'unbans_24h' => (int) array_sum( array_map( 'intval', array_column( $summaries, 'unbans' ) ) ),
        'jails'      => count( $summaries ),
        'window'     => nppp_f2b_get_window_event_count(),
    );

    $token          = nppp_f2b_get_token();
    $endpoint       = nppp_f2b_get_endpoint_url();
    $action_snippet = nppp_f2b_get_action_conf_snippet();
    $jail_snippet   = nppp_f2b_get_jail_local_snippet();
    $window_days    = NPPP_F2B_WINDOW_DAYS;
    $retention_days = nppp_f2b_retention_days();

    ob_start();
    include plugin_dir_path( __FILE__ ) . 'partials/security-tab.php';
    $html = ob_get_clean();

    // The partial escapes its own output. Do not run wp_kses_post() here.
    echo $html;
    wp_die();
}

function nppp_f2b_regenerate_token_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    $token = nppp_f2b_regenerate_token();

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
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $nppp_f2b_deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derived from $wpdb->prefix

    if ( false === $nppp_f2b_deleted ) {
        wp_send_json_error(
            array( 'message' => __( 'Could not clear the event log. Check that the database user has DELETE privilege on this table.', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            500
        );
    }

    wp_send_json_success(
        array( 'message' => __( 'Event log cleared.', 'fastcgi-cache-purge-and-preload-nginx' ) )
    );
}

/**
 * Test the webhook with the same HTTP path used by fail2ban.
 */
function nppp_f2b_test_connection_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    $endpoint = nppp_f2b_get_endpoint_url();
    $token    = nppp_f2b_get_token();

    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout'   => 8,
            'sslverify' => apply_filters( 'nppp_f2b_selftest_sslverify', true ),
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
                'message' => __( 'HTTP 429 — the per-minute event ceiling is currently saturated. Wait a minute and test again.', 'fastcgi-cache-purge-and-preload-nginx' ),
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
