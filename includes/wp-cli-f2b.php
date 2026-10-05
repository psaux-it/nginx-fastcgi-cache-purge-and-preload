<?php
/**
 * WP-CLI Fail2ban commands for Nginx Cache Purge Preload
 * Description: Exposes the Fail2ban webhook, jail monitor, RIPEstat enrichment
 *              worker, and Abuse Reporter to WP-CLI as `wp npp f2b`.
 * Version: 2.1.7
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
 * Manages the Fail2Ban webhook, jail monitor, RIPEstat enrichment worker and
 * Abuse Reporter — the same operations the Fail2Ban tab offers, from the shell.
 *
 * Run WP-CLI as the PHP-FPM user (for example `sudo -u www-data wp npp f2b ...`)
 * so the worker and its runtime files stay manageable by the web process.
 *
 * ## EXAMPLES
 *
 *     # One-screen health overview (webhook, event log, queue, worker)
 *     wp npp f2b status
 *
 *     # End-to-end webhook self-test (same check as the "Test" button)
 *     wp npp f2b test
 *
 *     # Print the webhook token / rotate it
 *     wp npp f2b token
 *     wp npp f2b token --regenerate --yes
 *
 *     # Print ready-to-paste Fail2Ban config
 *     wp npp f2b snippet action
 *     wp npp f2b snippet jail
 *
 *     # Jail activity, repeat offenders, top countries, live feed
 *     wp npp f2b jails --hours=24
 *     wp npp f2b offenders --min-bans=3
 *     wp npp f2b countries
 *     wp npp f2b events --type=ban --limit=20 --format=json
 *
 *     # RIPEstat enrichment worker
 *     wp npp f2b worker status
 *     wp npp f2b worker start
 *     wp npp f2b worker stop
 *
 *     # Abuse Reporter
 *     wp npp f2b abuse get
 *     wp npp f2b abuse set enabled yes
 *     wp npp f2b abuse report 198.51.100.7 --dry-run
 *
 * @package NPPP
 */
class NPPP_CLI_F2B_Command extends WP_CLI_Command {

    // =========================================================================
    // Public subcommands
    // =========================================================================

    /**
     * Shows a health overview of the Fail2Ban integration.
     *
     * Read-only: never creates the table, the token or any runtime file.
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
     *     wp npp f2b status
     *     wp npp f2b status --format=json
     *
     * @when after_wp_load
     */
    public function status( array $args, array $assoc_args ): void {
        global $wpdb;

        $sep   = static fn( string $s ): array => [ 'Field' => "── $s ──", 'Value' => '' ];
        $table = nppp_f2b_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
        $table_exists = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );

        // Webhook
        $stored_token = get_option( NPPP_F2B_TOKEN_OPTION, '' );
        $token_ok     = is_string( $stored_token ) && (bool) preg_match( '/^[a-f0-9]{64}$/i', $stored_token );

        // The rate counter row is written with raw SQL (bypasses the object
        // cache), so read it the same way or a persistent cache shows stale data.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter on a single options row
        $rate_raw   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'nppp_f2b_rate_win' ) );
        $rate_parts = explode( ':', $rate_raw . ':' );
        $rate_now   = ( $rate_parts[0] === (string) (int) floor( time() / 60 ) ) ? (int) $rate_parts[1] : 0;

        $trusted = apply_filters( 'nppp_f2b_trusted_ips', array() );

        $rows = [
            $sep( _x( 'WEBHOOK', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            [ 'Field' => __( 'Endpoint', 'fastcgi-cache-purge-and-preload-nginx' ),            'Value' => nppp_f2b_get_endpoint_url() ],
            [ 'Field' => __( 'Token', 'fastcgi-cache-purge-and-preload-nginx' ),               'Value' => $token_ok
                ? __( 'Configured (print with: wp npp f2b token)', 'fastcgi-cache-purge-and-preload-nginx' )
                : __( 'Missing (run: wp npp f2b token)', 'fastcgi-cache-purge-and-preload-nginx' ) ],
            [ 'Field' => __( 'Events this minute', 'fastcgi-cache-purge-and-preload-nginx' ),  'Value' => $rate_now . ' / ' . NPPP_F2B_RATE_MAX_PER_MIN ],
            [ 'Field' => __( 'IP allow-list (nppp_f2b_trusted_ips)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => ( is_array( $trusted ) && ! empty( $trusted ) )
                ? (string) count( $trusted )
                : __( 'Off', 'fastcgi-cache-purge-and-preload-nginx' ) ],
        ];

        // Event log
        $rows[] = $sep( _x( 'EVENT LOG', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) );
        $rows[] = [ 'Field' => __( 'Event table', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $table . ' (' . ( $table_exists ? __( 'OK', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'Missing', 'fastcgi-cache-purge-and-preload-nginx' ) ) . ')' ];
        $rows[] = [ 'Field' => __( 'Schema version', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => (string) get_option( NPPP_F2B_DB_VERSION_OPTION, '-' ) . ' / ' . NPPP_F2B_DB_VERSION ];
        $rows[] = [ 'Field' => __( 'Country column (Top Countries)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => nppp_f2b_country_feature_available() ? __( 'Available', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'Unavailable', 'fastcgi-cache-purge-and-preload-nginx' ) ];
        /* translators: %d: number of days events are kept */
        $rows[] = [ 'Field' => __( 'Retention', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => sprintf( __( '%d days', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_f2b_retention_days() ) ];

        if ( $table_exists ) {
            $summaries = nppp_f2b_get_jail_summaries( 24 );
            $bans_24h  = (int) array_sum( array_map( 'intval', array_column( $summaries, 'bans' ) ) );
            $unbans    = (int) array_sum( array_map( 'intval', array_column( $summaries, 'unbans' ) ) );
            $last      = nppp_f2b_get_recent_events( 1 );

            $rows[] = [ 'Field' => __( 'Events recorded (ban + unban)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => (string) nppp_f2b_get_total_event_count() ];
            $rows[] = [ 'Field' => __( 'Last 24 hours', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => sprintf(
                /* translators: 1: number of bans, 2: number of unbans, 3: number of jails */
                __( '%1$d bans / %2$d unbans across %3$d jail(s)', 'fastcgi-cache-purge-and-preload-nginx' ),
                $bans_24h,
                $unbans,
                count( $summaries )
            ) ];
            $rows[] = [ 'Field' => __( 'Last event (UTC)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => ! empty( $last ) ? (string) $last[0]['created_at'] : '-' ];
        }

        $next_cleanup = wp_next_scheduled( NPPP_F2B_CLEANUP_HOOK );
        $rows[]       = [ 'Field' => __( 'Retention cleanup next run', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $next_cleanup ? wp_date( 'Y-m-d H:i:s', (int) $next_cleanup ) : __( 'Not scheduled', 'fastcgi-cache-purge-and-preload-nginx' ) ];

        // Enrichment (RIPEstat)
        $rows[] = $sep( _x( 'ENRICHMENT (RIPESTAT)', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) );
        $rows[] = [ 'Field' => __( 'sourceapp identifier', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => nppp_f2b_rdap_sourceapp() ];

        if ( $table_exists ) {
            $queue   = nppp_f2b_queue_stats();
            $cooling = nppp_f2b_rdap_cooling_get();

            if ( $queue['ips'] < 0 ) {
                $queue_label = __( 'Unknown (queue query failed, see: wp npp log)', 'fastcgi-cache-purge-and-preload-nginx' );
            } elseif ( 0 === $queue['ips'] ) {
                $queue_label = '0';
            } else {
                /* translators: 1: number of pending IPs, 2: age of the oldest pending event, e.g. "3 mins" */
                $queue_label = sprintf( __( '%1$d (oldest: %2$s)', 'fastcgi-cache-purge-and-preload-nginx' ), $queue['ips'], $this->f2b_age( (int) $queue['oldest_age'] ) );
            }
            $rows[] = [ 'Field' => __( 'Pending IPs (no profile yet)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $queue_label ];
            $rows[] = [ 'Field' => __( 'Cooling IPs (waiting to retry)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => (string) count( $cooling ) ];
        }

        foreach ( $this->f2b_worker_rows() as $worker_row ) {
            $rows[] = $worker_row;
        }

        $next_reconcile = wp_next_scheduled( NPPP_F2B_WORKER_HOOK );
        $rows[]         = [ 'Field' => __( 'Reconcile tick next run', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $next_reconcile ? wp_date( 'Y-m-d H:i:s', (int) $next_reconcile ) : __( 'Not scheduled', 'fastcgi-cache-purge-and-preload-nginx' ) ];

        // Abuse Reporter
        $abuse   = nppp_f2b_get_abuse_settings();
        $reg_at  = nppp_f2b_ripe_reg_sent_at();
        $rows[]  = $sep( _x( 'ABUSE REPORTER', 'status table section header', 'fastcgi-cache-purge-and-preload-nginx' ) );
        $rows[]  = [ 'Field' => __( 'Enabled', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $abuse['enabled'] ];
        $rows[]  = [ 'Field' => __( 'Ready (identity complete)', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => nppp_f2b_abuse_is_ready( $abuse ) ? 'yes' : 'no' ];
        $rows[]  = [ 'Field' => __( 'Dry run', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $abuse['dry_run'] ];
        $rows[]  = [ 'Field' => __( 'Threshold', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => sprintf(
            /* translators: 1: minimum number of bans, 2: cooldown in days */
            __( '%1$d bans, %2$d day cooldown', 'fastcgi-cache-purge-and-preload-nginx' ),
            (int) $abuse['min_bans'],
            (int) $abuse['cooldown_days']
        ) ];
        $rows[]  = [ 'Field' => __( 'RIPEstat registration mail', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $reg_at > 0 ? wp_date( 'Y-m-d', $reg_at ) : __( 'Not sent', 'fastcgi-cache-purge-and-preload-nginx' ) ];

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Field', 'Value' ] );
        $formatter->display_items( $rows );
    }

    /**
     * Runs the webhook self-test through the real HTTP path.
     *
     * Sends a "test" event to this site's own webhook with the stored token.
     * The row is written and removed again. Exits non-zero on failure, so it
     * can gate provisioning scripts.
     *
     * ## EXAMPLES
     *
     *     wp npp f2b test
     *     wp npp f2b test && echo "webhook OK"
     *
     * @when after_wp_load
     */
    public function test( array $args, array $assoc_args ): void {
        $this->f2b_require_table();

        $result = nppp_f2b_run_selftest();

        if ( empty( $result['ok'] ) ) {
            WP_CLI::error( (string) $result['message'] );
        }

        WP_CLI::success( (string) $result['message'] );
    }

    /**
     * Prints or regenerates the webhook bearer token.
     *
     * The token is a secret: it is printed to stdout only and never logged.
     * Regenerating it makes every jail that still sends the old token get
     * rejected until jail.local is updated and Fail2Ban reloaded.
     *
     * ## OPTIONS
     *
     * [--regenerate]
     * : Create a new token and invalidate the current one.
     *
     * [--yes]
     * : Skip the confirmation prompt for --regenerate.
     *
     * ## EXAMPLES
     *
     *     wp npp f2b token
     *     wp npp f2b token --regenerate --yes
     *
     * @when after_wp_load
     */
    public function token( array $args, array $assoc_args ): void {
        if ( array_key_exists( 'regenerate', $assoc_args ) ) {
            WP_CLI::confirm(
                __( 'Regenerate the webhook token? Jails still sending the old token are rejected until jail.local is updated.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $assoc_args
            );

            $token = nppp_f2b_regenerate_token();

            nppp_f2b_log(
                'INFO',
                __( 'Webhook token regenerated via WP-CLI; jails still sending the old token are rejected until jail.local is updated.', 'fastcgi-cache-purge-and-preload-nginx' )
            );

            WP_CLI::line( $token );
            WP_CLI::warning( __( 'Token regenerated. Update jail.local (wp npp f2b snippet jail) and reload Fail2Ban.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        WP_CLI::line( nppp_f2b_get_token() );
    }

    /**
     * Prints a ready-to-paste Fail2Ban / Nginx configuration snippet.
     *
     * The "jail" snippet embeds the current webhook token.
     *
     * ## OPTIONS
     *
     * <type>
     * : Which snippet to print.
     * ---
     * options:
     *   - action
     *   - jail
     *   - nginx
     * ---
     *
     * ## EXAMPLES
     *
     *     wp npp f2b snippet action | sudo tee /etc/fail2ban/action.d/nppp-webhook.conf
     *     wp npp f2b snippet jail
     *     wp npp f2b snippet nginx
     *
     * @when after_wp_load
     */
    public function snippet( array $args, array $assoc_args ): void {
        $type = (string) ( $args[0] ?? '' );

        if ( $type === 'action' ) {
            $out = nppp_f2b_get_action_conf_snippet();
        } elseif ( $type === 'jail' ) {
            $out = nppp_f2b_get_jail_local_snippet();
        } elseif ( $type === 'nginx' ) {
            $out = nppp_f2b_get_nginx_rate_limit_snippet();
        } else {
            WP_CLI::error( __( 'Unknown snippet. Use: action, jail or nginx.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        // Raw output on purpose: the text is meant to be piped into a file.
        WP_CLI::line( rtrim( $out, "\n" ) );
    }

    /**
     * Lists per-jail ban/unban activity.
     *
     * ## OPTIONS
     *
     * [--hours=<n>]
     * : Look-back window in hours (1-8760). Defaults to 24.
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
     *     wp npp f2b jails
     *     wp npp f2b jails --hours=168 --format=json
     *
     * @when after_wp_load
     */
    public function jails( array $args, array $assoc_args ): void {
        $this->f2b_require_table();

        $hours = $this->f2b_int_arg( $assoc_args, 'hours', 24, 1, 8760 );
        $rows  = [];

        foreach ( nppp_f2b_get_jail_summaries( $hours ) as $row ) {
            $rows[] = [
                'Jail'         => (string) $row['jail'],
                'Bans'         => (int) $row['bans'],
                'Unbans'       => (int) $row['unbans'],
                'Last Event (UTC)' => (string) $row['last_event'],
            ];
        }

        $this->f2b_display( $rows, [ 'Jail', 'Bans', 'Unbans', 'Last Event (UTC)' ], $assoc_args, __( 'No ban or unban events in this window.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    /**
     * Lists repeat offenders (IPs banned several times in the last 30 days).
     *
     * ## OPTIONS
     *
     * [--min-bans=<n>]
     * : Minimum number of bans (1-1000). Defaults to 2.
     *
     * [--limit=<n>]
     * : Maximum rows (1-500). Defaults to 25.
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
     *     wp npp f2b offenders
     *     wp npp f2b offenders --min-bans=5 --limit=10 --format=json
     *
     * @when after_wp_load
     */
    public function offenders( array $args, array $assoc_args ): void {
        $this->f2b_require_table();

        $min   = $this->f2b_int_arg( $assoc_args, 'min-bans', 2, 1, 1000 );
        $limit = $this->f2b_int_arg( $assoc_args, 'limit', 25, 1, 500 );
        $found = nppp_f2b_get_recidive_ips( $min, $limit );
        $map   = nppp_f2b_get_abuse_map_for_ips( array_column( $found, 'ip' ) );
        $log   = nppp_f2b_get_abuse_report_log();
        $rows  = [];

        foreach ( $found as $row ) {
            $ip       = (string) $row['ip'];
            $reported = nppp_f2b_abuse_last_reported( $ip, $log );
            $rows[]   = [
                'IP'             => $ip,
                'Bans'           => (int) $row['ban_count'],
                'Last Ban (UTC)' => (string) $row['last_ban'],
                'Country'        => (string) ( $map[ $ip ]['country'] ?? '' ),
                'Network'        => (string) ( $map[ $ip ]['netname'] ?? '' ),
                'Abuse Contact'  => implode( ', ', (array) ( $map[ $ip ]['abuse_emails'] ?? [] ) ),
                'Reported'       => $reported > 0 ? wp_date( 'Y-m-d', $reported ) : '-',
            ];
        }

        $this->f2b_display(
            $rows,
            [ 'IP', 'Bans', 'Last Ban (UTC)', 'Country', 'Network', 'Abuse Contact', 'Reported' ],
            $assoc_args,
            __( 'No repeat offenders found.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
    }

    /**
     * Lists the top attack countries (bans per country over the retention window).
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Maximum rows (1-300). Defaults to 8.
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
     *     wp npp f2b countries
     *     wp npp f2b countries --limit=50 --format=csv
     *
     * @when after_wp_load
     */
    public function countries( array $args, array $assoc_args ): void {
        $this->f2b_require_table();

        if ( ! nppp_f2b_country_feature_available() ) {
            WP_CLI::warning( __( 'The country_code column is unavailable on this database, so Top Attack Countries is disabled. See: wp npp log', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $limit = $this->f2b_int_arg( $assoc_args, 'limit', NPPP_F2B_TOP_COUNTRIES_N, 1, NPPP_F2B_MAP_COUNTRIES_MAX );
        $rows  = [];

        foreach ( nppp_f2b_get_top_countries( $limit ) as $row ) {
            $rows[] = [
                'Country'        => (string) $row['country'],
                'Bans'           => (int) $row['attack_count'],
                'Last Seen (UTC)' => (string) $row['last_seen'],
            ];
        }

        $this->f2b_display( $rows, [ 'Country', 'Bans', 'Last Seen (UTC)' ], $assoc_args, __( 'No enriched ban events yet, so no country data.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    /**
     * Lists recent events from the Fail2Ban event log (the Live Feed).
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Event type. "all" means ban + unban (what the Live Feed shows).
     * ---
     * default: all
     * options:
     *   - all
     *   - ban
     *   - unban
     *   - gate
     * ---
     *
     * [--jail=<jail>]
     * : Only events of this jail (for "gate": ep3, ep8 or ep10).
     *
     * [--ip=<ip>]
     * : Only events of this IP address.
     *
     * [--hours=<n>]
     * : Only events from the last N hours (1-8760).
     *
     * [--limit=<n>]
     * : Maximum rows (1-5000). Defaults to 50.
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
     *     wp npp f2b events
     *     wp npp f2b events --type=ban --jail=nginx-botsearch --limit=100
     *     wp npp f2b events --ip=198.51.100.7 --format=json
     *
     * @when after_wp_load
     */
    public function events( array $args, array $assoc_args ): void {
        global $wpdb;

        $this->f2b_require_table();

        $type  = (string) ( $assoc_args['type'] ?? 'all' );
        $jail  = (string) ( $assoc_args['jail'] ?? '' );
        $ip    = (string) ( $assoc_args['ip'] ?? '' );
        $cap   = max( 1, (int) apply_filters( 'nppp_f2b_feed_hard_cap', NPPP_F2B_FEED_HARD_CAP ) );
        $limit = $this->f2b_int_arg( $assoc_args, 'limit', 50, 1, $cap );

        if ( ! in_array( $type, [ 'all', 'ban', 'unban', 'gate' ], true ) ) {
            WP_CLI::error( __( 'Invalid --type. Use: all, ban, unban or gate.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
        if ( $jail !== '' && ! preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $jail ) ) {
            WP_CLI::error( __( 'Invalid --jail. Jail names contain only letters, digits, ".", "_" and "-".', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
        if ( $ip !== '' && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            /* translators: %s: the invalid IP address provided */
            WP_CLI::error( sprintf( __( 'Invalid --ip: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $ip ) );
        }

        if ( $ip !== '' ) {
            $ip = (string) nppp_f2b_canonical_ip( $ip ); // same canonical form the webhook stores
        }

        $clauses = [];
        $params  = [ nppp_f2b_table_name() ];

        if ( $type === 'all' ) {
            $clauses[] = "event_type IN ('ban','unban')";
        } else {
            $clauses[] = 'event_type = %s';
            $params[]  = $type;
        }
        if ( $jail !== '' ) {
            $clauses[] = 'jail = %s';
            $params[]  = $jail;
        }
        if ( $ip !== '' ) {
            $clauses[] = 'ip = %s';
            $params[]  = $ip;
        }
        if ( array_key_exists( 'hours', $assoc_args ) ) {
            $hours     = $this->f2b_int_arg( $assoc_args, 'hours', 24, 1, 8760 );
            $clauses[] = 'created_at >= %s';
            $params[]  = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
        }
        $params[] = $limit;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table; the WHERE clause is built only from fixed fragments and every value is bound through prepare()
        $found = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, jail, ip, event_type, created_at, rdap_json FROM %i WHERE ' . implode( ' AND ', $clauses ) . ' ORDER BY id DESC LIMIT %d',
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $rows = [];
        foreach ( (array) $found as $row ) {
            $profile = $this->f2b_profile( $row['rdap_json'] ?? null, (string) $row['event_type'] );
            $rows[]  = [
                'ID'            => (int) $row['id'],
                'Time (UTC)'    => (string) $row['created_at'],
                'Type'          => (string) $row['event_type'],
                'Jail'          => (string) $row['jail'],
                'IP'            => (string) $row['ip'],
                'Country'       => $profile['country'],
                'Network'       => $profile['netname'],
                'ASN'           => $profile['asn'],
                'Abuse Contact' => $profile['abuse'],
                'Profile'       => $profile['state'],
            ];
        }

        $this->f2b_display(
            $rows,
            [ 'ID', 'Time (UTC)', 'Type', 'Jail', 'IP', 'Country', 'Network', 'ASN', 'Abuse Contact', 'Profile' ],
            $assoc_args,
            __( 'No matching events.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
    }

    /**
     * Lists rejected requests against the plugin's own endpoints (Endpoint Attacks).
     *
     * ## OPTIONS
     *
     * [--hours=<n>]
     * : Look-back window in hours (1-8760). Defaults to 24.
     *
     * [--limit=<n>]
     * : Maximum rows (1-500). Defaults to 25.
     *
     * [--totals]
     * : Show one total per endpoint gate instead of one row per gate + IP.
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
     *     wp npp f2b gate
     *     wp npp f2b gate --totals
     *
     * @when after_wp_load
     */
    public function gate( array $args, array $assoc_args ): void {
        $this->f2b_require_table();

        $hours = $this->f2b_int_arg( $assoc_args, 'hours', 24, 1, 8760 );
        $rows  = [];

        if ( array_key_exists( 'totals', $assoc_args ) ) {
            foreach ( nppp_f2b_get_gate_totals( $hours ) as $row ) {
                $rows[] = [
                    'Gate'           => nppp_f2b_gate_label( (string) $row['jail'] ),
                    'Hits'           => (int) $row['hits'],
                    'Last Seen (UTC)' => (string) $row['last_seen'],
                ];
            }
            $fields = [ 'Gate', 'Hits', 'Last Seen (UTC)' ];
        } else {
            $limit = $this->f2b_int_arg( $assoc_args, 'limit', 25, 1, 500 );
            foreach ( nppp_f2b_get_gate_summary( $limit, $hours ) as $row ) {
                $rows[] = [
                    'Gate'           => nppp_f2b_gate_label( (string) $row['jail'] ),
                    'IP'             => (string) $row['ip'],
                    'Hits'           => (int) $row['hits'],
                    'Last Seen (UTC)' => (string) $row['last_seen'],
                ];
            }
            $fields = [ 'Gate', 'IP', 'Hits', 'Last Seen (UTC)' ];
        }

        $this->f2b_display( $rows, $fields, $assoc_args, __( 'No rejected endpoint requests in this window.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    /**
     * Deletes every recorded Fail2Ban event.
     *
     * Same as the "Clear events" button in the Fail2Ban tab. Uses DELETE, so it
     * needs no DROP privilege. The webhook token and all settings are kept.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp npp f2b clear
     *     wp npp f2b clear --yes
     *
     * @when after_wp_load
     */
    public function clear( array $args, array $assoc_args ): void {
        global $wpdb;

        $this->f2b_require_table();

        $table = nppp_f2b_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

        if ( 0 === $count ) {
            WP_CLI::warning( __( 'The event log is already empty.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        WP_CLI::confirm(
            sprintf(
                /* translators: %d: number of event rows that will be deleted */
                __( 'Delete all %d recorded event(s)? This cannot be undone.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $count
            ),
            $assoc_args
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

        if ( false === $deleted ) {
            WP_CLI::error( __( 'Could not clear the event log. Check that the database user has DELETE privilege on this table.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        nppp_f2b_log(
            'INFO',
            sprintf(
                /* translators: %d: number of event rows deleted. */
                __( 'Event log cleared via WP-CLI (%d row(s) removed).', 'fastcgi-cache-purge-and-preload-nginx' ),
                (int) $deleted
            )
        );

        /* translators: %d: number of event rows deleted */
        WP_CLI::success( sprintf( __( 'All events cleared (%d row(s) removed).', 'fastcgi-cache-purge-and-preload-nginx' ), (int) $deleted ) );
    }

    /**
     * Shows, starts or stops the RIPEstat enrichment worker.
     *
     * The worker is a detached PHP process that fills in whois / abuse-contact
     * data for banned IPs. It exits on its own once the queue is empty, and is
     * started automatically by the next ban event or by the 5-minute reconcile
     * tick, so "stop" is a temporary pause while pending IPs remain.
     *
     * ## OPTIONS
     *
     * <action>
     * : Operation to perform.
     * ---
     * options:
     *   - status
     *   - start
     *   - stop
     * ---
     *
     * [--format=<format>]
     * : Output format for 'status'.
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
     *     wp npp f2b worker status
     *     wp npp f2b worker start
     *     wp npp f2b worker stop
     *
     * @when after_wp_load
     */
    public function worker( array $args, array $assoc_args ): void {
        $action = (string) ( $args[0] ?? '' );

        if ( $action === 'status' ) {
            $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Field', 'Value' ] );
            $formatter->display_items( $this->f2b_worker_rows() );
        } elseif ( $action === 'start' ) {
            $this->f2b_require_table();
            $this->f2b_worker_start();
        } elseif ( $action === 'stop' ) {
            $this->f2b_worker_stop();
        } else {
            WP_CLI::error( __( 'Unknown action. Use: status, start or stop.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    /**
     * Shows or sets the RIPEstat "sourceapp" identifier suffix.
     *
     * RIPEstat asks regular API callers to identify themselves. The plugin
     * always sends its fixed base identifier; an optional suffix lets the
     * RIPEstat team tell sites apart. Characters outside A-Z a-z 0-9 _ - are
     * normalised to "_" (so "example.com" becomes "example_com").
     *
     * ## OPTIONS
     *
     * [<suffix>]
     * : New suffix. Omit to show the current identifier.
     *
     * [--clear]
     * : Remove the suffix and fall back to the base identifier.
     *
     * ## EXAMPLES
     *
     *     wp npp f2b sourceapp
     *     wp npp f2b sourceapp example_com
     *     wp npp f2b sourceapp --clear
     *
     * @when after_wp_load
     */
    public function sourceapp( array $args, array $assoc_args ): void {
        $clear  = array_key_exists( 'clear', $assoc_args );
        $raw    = (string) ( $args[0] ?? '' );
        $change = $clear || array_key_exists( 0, $args );

        if ( $clear && array_key_exists( 0, $args ) ) {
            WP_CLI::error( __( 'Use either a <suffix> or --clear, not both.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        if ( $change ) {
            $suffix = $clear ? '' : nppp_f2b_sanitize_sourceapp_suffix( $raw );

            if ( ! $clear && '' === $suffix ) {
                WP_CLI::error( __( 'Invalid suffix: nothing is left after removing characters outside A-Z a-z 0-9 _ -', 'fastcgi-cache-purge-and-preload-nginx' ) );
            }
            if ( ! $clear && $suffix !== $raw ) {
                /* translators: %s: the normalised suffix */
                WP_CLI::warning( sprintf( __( 'Suffix normalised to "%s".', 'fastcgi-cache-purge-and-preload-nginx' ), $suffix ) );
            }

            if ( '' === $suffix ) {
                delete_option( NPPP_F2B_SOURCEAPP_SUFFIX_OPTION );
            } else {
                update_option( NPPP_F2B_SOURCEAPP_SUFFIX_OPTION, $suffix, false );
            }

            nppp_f2b_log(
                'INFO',
                sprintf(
                    'RIPEstat sourceapp suffix %s via WP-CLI: %s',
                    '' === $suffix ? 'cleared' : 'saved',
                    nppp_f2b_compose_sourceapp( $suffix )
                )
            );
        }

        $composed  = nppp_f2b_compose_sourceapp( nppp_f2b_get_sourceapp_suffix() );
        $effective = nppp_f2b_rdap_sourceapp();

        if ( $change ) {
            /* translators: %s: the identifier now sent to RIPEstat */
            WP_CLI::success( sprintf( __( 'RIPEstat identifier is now: %s', 'fastcgi-cache-purge-and-preload-nginx' ), $effective ) );
        } else {
            WP_CLI::line( $effective );
        }

        if ( $effective !== $composed ) {
            WP_CLI::warning( __( 'A filter overrides the identifier, so the saved suffix is not what is being sent.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    /**
     * Configures the Abuse Reporter and sends abuse reports.
     *
     * Reports go to the abuse contacts RIPEstat resolved for the offending
     * network. The recipient, ban count and evidence are always taken from the
     * event log, never from the command line.
     *
     * ## OPTIONS
     *
     * <action>
     * : Operation to perform.
     * ---
     * options:
     *   - get
     *   - set
     *   - report
     *   - test
     * ---
     *
     * [<key_or_ip>]
     * : For 'set': the setting key. For 'report': the IP address to report.
     *   Keys: enabled, from_name, from_email, reply_to, cc_self, org_name,
     *   contact_name, contact_phone, min_bans, cooldown_days, dry_run.
     *
     * [<value>]
     * : For 'set': the new value.
     *
     * [--dry-run]
     * : For 'report': show what would be reported and to whom, without sending
     *   anything or starting the cooldown.
     *
     * [--yes]
     * : For 'report': skip the confirmation prompt.
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
     *     wp npp f2b abuse get
     *     wp npp f2b abuse set from_email abuse-report@example.com
     *     wp npp f2b abuse set enabled yes
     *     wp npp f2b abuse report 198.51.100.7 --dry-run
     *     wp npp f2b abuse report 198.51.100.7 --yes
     *     wp npp f2b abuse test
     *
     * @when after_wp_load
     */
    public function abuse( array $args, array $assoc_args ): void {
        $action = (string) ( $args[0] ?? '' );

        if ( $action === 'get' ) {
            $this->abuse_get( $assoc_args );
        } elseif ( $action === 'set' ) {
            $this->abuse_set( $args );
        } elseif ( $action === 'report' ) {
            $this->f2b_require_table();
            $this->abuse_report( $args, $assoc_args );
        } elseif ( $action === 'test' ) {
            $result = nppp_f2b_abuse_send_test_mail( nppp_f2b_get_abuse_settings() );
            if ( empty( $result['ok'] ) ) {
                WP_CLI::error( (string) $result['message'] );
            }
            WP_CLI::success( (string) $result['message'] );
        } else {
            WP_CLI::error( __( 'Unknown action. Use: get, set, report or test.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    /**
     * Emails the RIPEstat team to register this site's sourceapp suffix.
     *
     * Optional and never automatic. Needs a saved sourceapp suffix, at least one
     * real webhook event and a filled-in Abuse Reporter identity (sender,
     * organisation, contact name). The reporter itself does not have to be on.
     * The message is always shown before it is sent.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show the message and any blockers without sending.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp npp f2b ripe-register --dry-run
     *     wp npp f2b ripe-register --yes
     *
     * @subcommand ripe-register
     * @when after_wp_load
     */
    public function ripe_register( array $args, array $assoc_args ): void {
        $this->f2b_require_table();

        $settings = nppp_f2b_get_abuse_settings();
        $blockers = nppp_f2b_ripe_reg_blockers( $settings );
        $mail     = nppp_f2b_ripe_reg_build( $settings );

        WP_CLI::line( 'To:      ' . $mail['to'] );
        WP_CLI::line( 'Subject: ' . $mail['subject'] );
        WP_CLI::line( '' );
        WP_CLI::line( rtrim( (string) $mail['body'], "\n" ) );
        WP_CLI::line( '' );

        foreach ( $blockers as $blocker ) {
            WP_CLI::warning( (string) $blocker );
        }

        if ( array_key_exists( 'dry-run', $assoc_args ) ) {
            WP_CLI::line( __( '[dry-run] Nothing was sent.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        if ( ! empty( $blockers ) ) {
            WP_CLI::error( (string) $blockers[0] );
            return;
        }

        WP_CLI::confirm( __( 'Send this registration email?', 'fastcgi-cache-purge-and-preload-nginx' ), $assoc_args );

        $result = nppp_f2b_ripe_reg_send();

        if ( empty( $result['ok'] ) ) {
            WP_CLI::error( (string) $result['message'] );
        }

        WP_CLI::success( (string) $result['message'] );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Heals the schema the same way the Fail2Ban tab does, then makes sure the
     * event table really exists. Called by every command that reads or writes it.
     */
    private function f2b_require_table(): void {
        global $wpdb;

        if ( function_exists( 'nppp_f2b_maybe_install' ) ) {
            nppp_f2b_maybe_install();
        }

        $table = nppp_f2b_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
            /* translators: %s: name of the missing database table */
            WP_CLI::error( sprintf( __( 'The Fail2Ban event table is missing (%s). The database user likely lacks CREATE/ALTER privilege; see: wp npp log', 'fastcgi-cache-purge-and-preload-nginx' ), $table ) );
        }
    }

    /**
     * Strict integer option: absent means $default, anything that is not a
     * whole number inside [$min, $max] is an error (never silently clamped).
     */
    private function f2b_int_arg( array $assoc_args, string $key, int $default, int $min, int $max ): int {
        if ( ! array_key_exists( $key, $assoc_args ) ) {
            return $default;
        }

        $raw = (string) $assoc_args[ $key ];

        if ( ! ctype_digit( $raw ) || (int) $raw < $min || (int) $raw > $max ) {
            WP_CLI::error( sprintf(
                /* translators: 1: option name, 2: minimum value, 3: maximum value */
                __( 'Value for --%1$s must be a whole number between %2$d and %3$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $key,
                $min,
                $max
            ) );
        }

        return (int) $raw;
    }

    /** Renders rows through the WP-CLI formatter, or a notice when there are none. */
    private function f2b_display( array $rows, array $fields, array $assoc_args, string $empty_notice ): void {
        $format = (string) ( $assoc_args['format'] ?? 'table' );

        // Machine formats must still emit a valid (empty) document.
        if ( empty( $rows ) && $format === 'table' ) {
            WP_CLI::warning( $empty_notice );
            return;
        }

        $formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
        $formatter->display_items( $rows );
    }

    /** Short human age, e.g. "3 mins". */
    private function f2b_age( int $seconds ): string {
        return PHP_INT_MAX === $seconds ? '?' : human_time_diff( 0, max( 1, $seconds ) );
    }

    /**
     * Flattens a stored RIPEstat profile for one event row.
     *
     * Only ban events are ever queued for a lookup, so a NULL profile is
     * "pending" for bans and simply "n/a" for unban and gate rows.
     *
     * @param string|null $json       Raw rdap_json column.
     * @param string      $event_type ban, unban or gate.
     * @return array{country:string,netname:string,asn:string,abuse:string,state:string}
     */
    private function f2b_profile( $json, string $event_type ): array {
        $blank = [ 'country' => '', 'netname' => '', 'asn' => '', 'abuse' => '', 'state' => '-' ];

        if ( ! is_string( $json ) || '' === $json ) {
            $blank['state'] = $event_type === 'ban' ? 'pending' : '-';
            return $blank;
        }

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return $blank;
        }

        return [
            'country' => (string) ( $data['country'] ?? '' ),
            'netname' => (string) ( $data['netname'] ?? '' ),
            'asn'     => implode( ',', array_map( 'strval', (array) ( $data['origin_asns'] ?? [] ) ) ),
            'abuse'   => implode( ', ', nppp_f2b_abuse_clean_emails( $data['abuse_emails'] ?? [] ) ),
            'state'   => 'ready',
        ];
    }

    /**
     * Worker state rows, shared by `f2b status` and `f2b worker status`.
     * Never creates a file: the run-lock probe opens its file in "c" mode, so
     * it is skipped while the file does not exist (a root-owned lock created by
     * a diagnostic would block the PHP-FPM user later).
     *
     * @return array<int, array{Field:string,Value:string}>
     */
    private function f2b_worker_rows(): array {
        $pid       = nppp_f2b_worker_read_pid();
        $running   = nppp_f2b_worker_is_running();
        $hb_age    = nppp_f2b_worker_heartbeat_age();
        $note      = nppp_f2b_worker_read_hb_note();
        $lock_file = nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE );
        $lock_free = is_file( $lock_file ) ? nppp_f2b_worker_run_lock_is_free() : true;

        if ( $running ) {
            $state = __( 'Running', 'fastcgi-cache-purge-and-preload-nginx' );
        } elseif ( false === $lock_free ) {
            $state = __( 'Owns the run lock but heartbeat is stale', 'fastcgi-cache-purge-and-preload-nginx' );
        } else {
            $state = __( 'Idle (normal: it exits when the queue is empty)', 'fastcgi-cache-purge-and-preload-nginx' );
        }

        $spawn = function_exists( 'shell_exec' )
            ? ( apply_filters( 'nppp_f2b_enable_cli_worker', true ) ? __( 'Enabled', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'Disabled by nppp_f2b_enable_cli_worker filter', 'fastcgi-cache-purge-and-preload-nginx' ) )
            : __( 'Unavailable (shell_exec disabled; cron runs a small inline batch)', 'fastcgi-cache-purge-and-preload-nginx' );

        return [
            [ 'Field' => __( 'Worker', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $state ],
            [ 'Field' => __( 'Worker PID', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $pid > 0 ? (string) $pid : '-' ],
            [ 'Field' => __( 'Worker heartbeat age', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => PHP_INT_MAX === $hb_age ? '-' : nppp_f2b_worker_fmt_age( $hb_age ) ],
            [ 'Field' => __( 'Worker last state', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => '' !== $note ? $note : '-' ],
            [ 'Field' => __( 'Worker spawning', 'fastcgi-cache-purge-and-preload-nginx' ), 'Value' => $spawn ],
        ];
    }

    /** Warns when running as root: worker and runtime files would be root-owned. */
    private function f2b_warn_if_root(): void {
        if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
            WP_CLI::warning( __( 'Running as root: the worker and its runtime files will be owned by root and the PHP-FPM user cannot manage them. Prefer: sudo -u www-data wp npp f2b worker ...', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    /** `wp npp f2b worker start` */
    private function f2b_worker_start(): void {
        if ( nppp_f2b_worker_is_running() ) {
            /* translators: %d: process ID of the running worker */
            WP_CLI::warning( sprintf( __( 'A worker is already running (PID %d).', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_f2b_worker_read_pid() ) );
            return;
        }

        $this->f2b_warn_if_root();

        $queue = nppp_f2b_queue_stats();
        if ( 0 === $queue['ips'] ) {
            WP_CLI::line( __( 'Note: the queue is empty, so the worker will exit again after a short idle period.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        // $force skips the 5-second spawn throttle, which exists for concurrent
        // webhook requests. The flock single-flight guard still applies.
        $spawned = nppp_f2b_maybe_spawn_worker( true );

        if ( nppp_f2b_worker_is_running() ) {
            /* translators: %d: process ID of the new worker */
            WP_CLI::success( sprintf( __( 'Worker started (PID %d).', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_f2b_worker_read_pid() ) );
            return;
        }

        if ( $spawned ) {
            WP_CLI::warning( __( 'A worker still owns the run lock but its heartbeat is stale, so no new worker was started. See: wp npp f2b worker status', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        WP_CLI::error( __( 'The worker could not be started. See: wp npp log', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    /**
     * `wp npp f2b worker stop`
     *
     * Same safety rules as the hung-worker watchdog: the PID file alone is never
     * trusted, so nothing is signalled unless /proc shows the worker bootstrap.
     * Unlike deactivation, the lock files are left alone (flock() binds to the
     * inode; removing them could let two workers lock different files).
     */
    private function f2b_worker_stop(): void {
        $pid = nppp_f2b_worker_read_pid();

        if ( $pid <= 0 || ! nppp_f2b_pid_alive( $pid ) ) {
            // Clear leftovers only when nobody holds the lock.
            $lock_file = nppp_get_runtime_file( NPPP_F2B_WORKER_RUN_LOCK_FILE );
            if ( ! is_file( $lock_file ) || true === nppp_f2b_worker_run_lock_is_free() ) {
                nppp_f2b_worker_reset_state();
            }
            WP_CLI::warning( __( 'No running worker found.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $cmd = nppp_f2b_worker_proc_cmdline( $pid );
        if ( null === $cmd || false === strpos( $cmd, 'nppp_f2b_worker_run' ) ) {
            WP_CLI::error( sprintf(
                /* translators: %d: process ID taken from the PID file */
                __( 'PID %d from the PID file is not an NPP worker (or /proc is unavailable). Nothing was signalled.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $pid
            ) );
            return;
        }

        if ( ! function_exists( 'posix_kill' ) || ! defined( 'SIGTERM' ) || ! defined( 'SIGKILL' ) ) {
            WP_CLI::error( __( 'The PHP posix extension is required to stop the worker.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        // The worker has no signal handler, so SIGTERM ends it at once.
        // The kernel drops its flock when the process dies.
        @posix_kill( $pid, SIGTERM );
        usleep( 300000 );
        if ( true !== nppp_f2b_worker_run_lock_is_free() ) {
            @posix_kill( $pid, SIGKILL );
            usleep( 300000 );
        }

        if ( true !== nppp_f2b_worker_run_lock_is_free() ) {
            WP_CLI::error( sprintf(
                /* translators: %d: process ID of the worker that could not be stopped */
                __( 'Could not stop worker PID %d (it probably runs as another user). Re-run as the PHP-FPM user: sudo -u www-data wp npp f2b worker stop', 'fastcgi-cache-purge-and-preload-nginx' ),
                $pid
            ) );
            return;
        }

        // Without this the next spawn would report an intentional stop as
        // "worker died without a clean exit".
        nppp_f2b_worker_reset_state();
        nppp_f2b_log( 'INFO', sprintf( 'Worker stopped via WP-CLI: pid=%d', $pid ) );

        /* translators: %d: process ID of the stopped worker */
        WP_CLI::success( sprintf( __( 'Worker (PID %d) stopped. A new one starts with the next ban event or reconcile tick while IPs are pending.', 'fastcgi-cache-purge-and-preload-nginx' ), $pid ) );
    }

    /** `wp npp f2b abuse get` */
    private function abuse_get( array $assoc_args ): void {
        $settings = nppp_f2b_get_abuse_settings();
        $rows     = [];

        foreach ( $settings as $key => $value ) {
            $rows[] = [ 'Key' => (string) $key, 'Value' => (string) $value ];
        }
        $rows[] = [ 'Key' => 'ready', 'Value' => nppp_f2b_abuse_is_ready( $settings ) ? 'yes' : 'no' ];

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Key', 'Value' ] );
        $formatter->display_items( $rows );
    }

    /**
     * `wp npp f2b abuse set <key> <value>`
     * Strict per-key validation (the AJAX form silently normalises; a script
     * should be told when its value was not usable), then the same
     * nppp_f2b_sanitize_abuse_settings() whitelist as the Fail2Ban tab.
     */
    private function abuse_set( array $args ): void {
        $key = (string) ( $args[1] ?? '' );

        if ( $key === '' || ! array_key_exists( 2, $args ) ) {
            WP_CLI::error( __( 'Usage: wp npp f2b abuse set <key> <value>', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $value    = (string) $args[2];
        $current  = nppp_f2b_get_abuse_settings();
        $yes_no   = [ 'enabled', 'cc_self', 'dry_run' ];
        $emails   = [ 'from_email', 'reply_to' ];
        $text     = [ 'from_name', 'org_name', 'contact_name', 'contact_phone' ];
        $int_keys = [ 'min_bans' => [ 1, 100 ], 'cooldown_days' => [ 1, 365 ] ];

        if ( ! array_key_exists( $key, $current ) ) {
            WP_CLI::error( sprintf(
                /* translators: %s: comma-separated list of valid keys */
                __( 'Unknown key. Valid keys: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                implode( ', ', array_keys( $current ) )
            ) );
            return;
        }

        if ( in_array( $key, $yes_no, true ) ) {
            if ( ! in_array( $value, [ 'yes', 'no' ], true ) ) {
                /* translators: %s: settings key name */
                WP_CLI::error( sprintf( __( 'Value for "%s" must be "yes" or "no".', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
            }
        } elseif ( isset( $int_keys[ $key ] ) ) {
            [ $min, $max ] = $int_keys[ $key ];
            if ( ! ctype_digit( $value ) || (int) $value < $min || (int) $value > $max ) {
                WP_CLI::error( sprintf(
                    /* translators: 1: settings key name, 2: minimum value, 3: maximum value */
                    __( 'Value for "%1$s" must be a whole number between %2$d and %3$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $key,
                    $min,
                    $max
                ) );
            }
        } elseif ( in_array( $key, $emails, true ) ) {
            // Empty is allowed (it clears the field); anything else must be a real address.
            if ( $value !== '' && ! is_email( $value ) ) {
                /* translators: %s: settings key name */
                WP_CLI::error( sprintf( __( 'Value for "%s" must be a valid email address.', 'fastcgi-cache-purge-and-preload-nginx' ), $key ) );
            }
        } elseif ( ! in_array( $key, $text, true ) ) {
            WP_CLI::error( __( 'This key cannot be set.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }

        $current[ $key ] = $value;
        $clean           = nppp_f2b_sanitize_abuse_settings( $current );

        update_option( NPPP_F2B_ABUSE_OPTION, $clean, false );

        nppp_f2b_log(
            'INFO',
            sprintf(
                'Abuse Reporter settings saved via WP-CLI: key=%s enabled=%s dry_run=%s min_bans=%d cooldown_days=%d',
                $key,
                $clean['enabled'],
                $clean['dry_run'],
                $clean['min_bans'],
                $clean['cooldown_days']
            )
        );

        /* translators: 1: settings key name, 2: stored value */
        WP_CLI::success( sprintf( __( 'Saved "%1$s" → "%2$s".', 'fastcgi-cache-purge-and-preload-nginx' ), $key, (string) $clean[ $key ] ) );

        if ( $clean['enabled'] === 'yes' && ! nppp_f2b_abuse_is_ready( $clean ) ) {
            WP_CLI::warning( __( 'The reporter stays inactive until the sender address, organisation and contact name are all set.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        }
    }

    /** `wp npp f2b abuse report <ip> [--dry-run] [--yes]` */
    private function abuse_report( array $args, array $assoc_args ): void {
        $ip_raw = (string) ( $args[1] ?? '' );
        $ip     = filter_var( $ip_raw, FILTER_VALIDATE_IP );

        if ( false === $ip ) {
            WP_CLI::error( __( 'Usage: wp npp f2b abuse report <ip> [--dry-run] [--yes]  (a valid IP address is required)', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        $ip       = (string) nppp_f2b_canonical_ip( $ip ); // $ip is already validated above
        $settings = nppp_f2b_get_abuse_settings();

        // Preview: same inputs the sender uses, but no mail, no cooldown stamp,
        // no rate-limit hit.
        if ( array_key_exists( 'dry-run', $assoc_args ) ) {
            $data = nppp_f2b_get_ip_report_data( $ip );

            if ( null === $data ) {
                WP_CLI::error( __( 'No ban evidence stored for that address in the current window.', 'fastcgi-cache-purge-and-preload-nginx' ) );
                return;
            }

            $last = nppp_f2b_abuse_last_reported( $ip );
            $rows = [
                [ 'Field' => 'IP',                 'Value' => $data['ip'] ],
                [ 'Field' => 'Bans (window)',      'Value' => $data['ban_count'] . ' / ' . (int) $settings['min_bans'] . ' ' . __( 'required', 'fastcgi-cache-purge-and-preload-nginx' ) ],
                [ 'Field' => 'Jails',              'Value' => implode( ', ', $data['jails'] ) ],
                [ 'Field' => 'Network',            'Value' => trim( $data['netname'] . ' ' . $data['inetnum'] ) ],
                [ 'Field' => 'Country',            'Value' => $data['country'] ],
                [ 'Field' => 'Abuse contacts',     'Value' => empty( $data['abuse_emails'] ) ? __( 'None known yet', 'fastcgi-cache-purge-and-preload-nginx' ) : implode( ', ', $data['abuse_emails'] ) ],
                [ 'Field' => 'Last reported',      'Value' => $last > 0 ? wp_date( 'Y-m-d H:i', $last ) : '-' ],
                [ 'Field' => 'Reporter ready',     'Value' => nppp_f2b_abuse_is_ready( $settings ) ? 'yes' : 'no' ],
                [ 'Field' => 'Settings dry run',   'Value' => $settings['dry_run'] ],
            ];

            $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'Field', 'Value' ] );
            $formatter->display_items( $rows );
            WP_CLI::line( __( '[dry-run] Nothing was sent.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }

        WP_CLI::confirm(
            'yes' === $settings['dry_run']
                /* translators: %s: IP address */
                ? sprintf( __( 'The reporter is in dry-run mode: no mail is sent, but the cooldown for %s starts. Continue?', 'fastcgi-cache-purge-and-preload-nginx' ), $ip )
                /* translators: %s: IP address */
                : sprintf( __( 'Email an abuse report about %s to the network operator now?', 'fastcgi-cache-purge-and-preload-nginx' ), $ip ),
            $assoc_args
        );

        $result = nppp_f2b_abuse_send_report( $ip );

        if ( empty( $result['ok'] ) ) {
            WP_CLI::error( (string) $result['message'] );
        }

        WP_CLI::success( (string) $result['message'] );
    }
}

WP_CLI::add_command(
    'npp f2b',
    'NPPP_CLI_F2B_Command',
    [
        'shortdesc' => 'Manages the Fail2Ban webhook, jail monitor, RIPEstat worker and Abuse Reporter.',
    ]
);
