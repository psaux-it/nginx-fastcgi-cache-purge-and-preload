<?php
/**
 * Fail2ban Abuse Reporter for Nginx Cache Purge Preload
 * Description: Builds and sends RFC-style abuse reports to the network owner's
 *              abuse desk, using ban evidence already stored by the fail2ban
 *              webhook monitor and the abuse contacts already resolved by the
 *              RDAP/RIPE enrichment pipeline.
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

// Reporter configuration. Not autoloaded: read only on the Fail2Ban tab and
// inside the three reporter AJAX callbacks, never on the front end.
if ( ! defined( 'NPPP_F2B_ABUSE_OPTION' ) ) {
    define( 'NPPP_F2B_ABUSE_OPTION', 'nppp_f2b_abuse_settings' );
}

// ip => last-reported unix timestamp. Bounded map, pruned on every write.
if ( ! defined( 'NPPP_F2B_ABUSE_LOG_OPTION' ) ) {
    define( 'NPPP_F2B_ABUSE_LOG_OPTION', 'nppp_f2b_abuse_reports' );
}

// Hard ceiling on the ip => timestamp map so the option row cannot grow
// without bound on a heavily attacked install.
if ( ! defined( 'NPPP_F2B_ABUSE_LOG_MAX' ) ) {
    define( 'NPPP_F2B_ABUSE_LOG_MAX', 1000 );
}

// Evidence lines embedded in the report body.
if ( ! defined( 'NPPP_F2B_ABUSE_EVIDENCE_MAX' ) ) {
    define( 'NPPP_F2B_ABUSE_EVIDENCE_MAX', 20 );
}

// Global safety valve. Abuse desks blacklist senders that flood them, and a
// runaway loop here would be sent from the site's own domain.
if ( ! defined( 'NPPP_F2B_ABUSE_HOURLY_MAX' ) ) {
    define( 'NPPP_F2B_ABUSE_HOURLY_MAX', 20 );
}

if ( ! defined( 'NPPP_F2B_ABUSE_RATE_KEY' ) ) {
    define( 'NPPP_F2B_ABUSE_RATE_KEY', 'nppp_f2b_abuse_rl' );
}

// Debounce window for the "Send Test Email" button.
if ( ! defined( 'NPPP_F2B_ABUSE_TEST_RATE_KEY' ) ) {
    define( 'NPPP_F2B_ABUSE_TEST_RATE_KEY', 'nppp_f2b_abuse_test_rl' );
}

if ( ! defined( 'NPPP_F2B_ABUSE_TEST_COOLDOWN' ) ) {
    define( 'NPPP_F2B_ABUSE_TEST_COOLDOWN', 20 );
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

// Bare host of the site, shared by the default sender and the report body.
function nppp_f2b_abuse_site_domain(): string {
    $parts = wp_parse_url( get_site_url() );
    $host  = isset( $parts['host'] ) ? (string) $parts['host'] : '';
    return str_replace( 'www.', '', $host );
}

function nppp_f2b_abuse_default_settings(): array {
    $domain = nppp_f2b_abuse_site_domain();

    return array(
        'enabled'       => 'no',
        'from_name'     => (string) get_bloginfo( 'name' ),
        'from_email'    => '' !== $domain ? 'abuse-report@' . $domain : '',
        'reply_to'      => (string) get_option( 'admin_email', '' ),
        'cc_self'       => 'no',
        'org_name'      => (string) get_bloginfo( 'name' ),
        'contact_name'  => '',
        'contact_phone' => '',
        'min_bans'      => 3,
        'cooldown_days' => 7,
        'dry_run'       => 'no',
    );
}

function nppp_f2b_get_abuse_settings(): array {
    $stored = get_option( NPPP_F2B_ABUSE_OPTION, array() );
    if ( ! is_array( $stored ) ) {
        $stored = array();
    }
    return nppp_f2b_sanitize_abuse_settings( array_merge( nppp_f2b_abuse_default_settings(), $stored ) );
}

/**
 * Whitelist + normalise every reporter field.
 *
 * Every string that ends up in a mail header is additionally stripped of CR/LF
 * here, so a saved value can never inject extra headers later on.
 */
function nppp_f2b_sanitize_abuse_settings( array $input ): array {
    $defaults = nppp_f2b_abuse_default_settings();
    $clean    = array();

    foreach ( array( 'enabled', 'cc_self', 'dry_run' ) as $flag ) {
        $raw            = isset( $input[ $flag ] ) ? (string) $input[ $flag ] : 'no';
        $clean[ $flag ] = in_array( $raw, array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';
    }

    foreach ( array( 'from_name', 'org_name', 'contact_name', 'contact_phone' ) as $text_key ) {
        $raw               = isset( $input[ $text_key ] ) ? (string) $input[ $text_key ] : '';
        $clean[ $text_key ] = nppp_f2b_abuse_strip_header_breaks( sanitize_text_field( $raw ) );
    }

    foreach ( array( 'from_email', 'reply_to' ) as $mail_key ) {
        $raw                = isset( $input[ $mail_key ] ) ? (string) $input[ $mail_key ] : '';
        $mail               = sanitize_email( nppp_f2b_abuse_strip_header_breaks( $raw ) );
        $clean[ $mail_key ] = is_email( $mail ) ? $mail : '';
    }

    $min_bans          = isset( $input['min_bans'] ) ? (int) $input['min_bans'] : (int) $defaults['min_bans'];
    $clean['min_bans'] = max( 1, min( 100, $min_bans ) );

    $cooldown               = isset( $input['cooldown_days'] ) ? (int) $input['cooldown_days'] : (int) $defaults['cooldown_days'];
    $clean['cooldown_days'] = max( 1, min( 365, $cooldown ) );

    return $clean;
}

/**
 * Mail headers are line-delimited, and the From header delimits the address
 * with angle brackets. Strip both classes of character so a stored value can
 * neither inject an extra header nor malform the address it sits next to.
 */
function nppp_f2b_abuse_strip_header_breaks( string $value ): string {
    return trim( str_replace( array( "\r", "\n", "\t", '<', '>' ), ' ', $value ) );
}

/**
 * The reporter is "ready" only when it is switched on AND carries the
 * identification an abuse desk needs to act on a report. An anonymous report
 * is discarded by most providers, so a half-filled card must not render
 * report buttons that will only produce silent no-ops.
 */
function nppp_f2b_abuse_is_ready( ?array $settings = null ): bool {
    if ( null === $settings ) {
        $settings = nppp_f2b_get_abuse_settings();
    }

    return 'yes' === $settings['enabled']
        && '' !== $settings['from_email']
        && '' !== $settings['org_name']
        && '' !== $settings['contact_name'];
}

// ---------------------------------------------------------------------------
// Report bookkeeping — cooldown map + global hourly valve
// ---------------------------------------------------------------------------

function nppp_f2b_get_abuse_report_log(): array {
    $log = get_option( NPPP_F2B_ABUSE_LOG_OPTION, array() );
    return is_array( $log ) ? $log : array();
}

function nppp_f2b_abuse_last_reported( string $ip, ?array $log = null ): int {
    if ( null === $log ) {
        $log = nppp_f2b_get_abuse_report_log();
    }
    return isset( $log[ $ip ] ) ? (int) $log[ $ip ] : 0;
}

/**
 * Stamp an IP as reported, then prune. Oldest-first eviction keeps the option
 * row small without ever losing a still-relevant cooldown.
 */
function nppp_f2b_record_abuse_report( string $ip ): void {
    $log        = nppp_f2b_get_abuse_report_log();
    $log[ $ip ] = time();

    $stale_before = time() - ( 365 * DAY_IN_SECONDS );
    foreach ( $log as $logged_ip => $stamp ) {
        if ( (int) $stamp < $stale_before ) {
            unset( $log[ $logged_ip ] );
        }
    }

    if ( count( $log ) > NPPP_F2B_ABUSE_LOG_MAX ) {
        asort( $log );
        $log = array_slice( $log, -NPPP_F2B_ABUSE_LOG_MAX, null, true );
    }

    update_option( NPPP_F2B_ABUSE_LOG_OPTION, $log, false );
}

// Same rotating-bucket shape as nppp_f2b_rate_exceeded(): one transient row.
function nppp_f2b_abuse_rate_exceeded(): bool {
    $window = (int) floor( time() / HOUR_IN_SECONDS );
    $bucket = get_transient( NPPP_F2B_ABUSE_RATE_KEY );

    if ( ! is_array( $bucket ) || ( $bucket['w'] ?? 0 ) !== $window ) {
        $bucket = array(
            'w' => $window,
            'c' => 0,
        );
    }

    $max = (int) apply_filters( 'nppp_f2b_abuse_hourly_max', NPPP_F2B_ABUSE_HOURLY_MAX );

    if ( (int) $bucket['c'] >= $max ) {
        return true;
    }

    $bucket['c'] = (int) $bucket['c'] + 1;
    set_transient( NPPP_F2B_ABUSE_RATE_KEY, $bucket, 2 * HOUR_IN_SECONDS );

    return false;
}

// ---------------------------------------------------------------------------
// Data access
// ---------------------------------------------------------------------------

/**
 * Latest known abuse contacts for a set of IPs, in one indexed query.
 *
 * Repeat Offenders rows are produced by a GROUP BY that cannot carry
 * rdap_json, so the panel would otherwise need one lookup per row. Bounded by
 * the caller (top N offenders), so the IN() list stays tiny.
 *
 * @return array ip => array( 'abuse_emails' => string[], 'netname' => string, 'country' => string )
 */
function nppp_f2b_get_abuse_map_for_ips( array $ips ): array {
    $ips = array_values(
        array_filter(
            array_map(
                static function ( $ip ) {
                    return filter_var( (string) $ip, FILTER_VALIDATE_IP );
                },
                $ips
            )
        )
    );

    if ( empty( $ips ) ) {
        return array();
    }

    global $wpdb;
    $table = nppp_f2b_table_name();

    $placeholders = implode( ', ', array_fill( 0, count( $ips ), '%s' ) );

    // MAX(id) per ip picks the most recently enriched row for that address.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a fixed '%s, %s, ...' string built only from count($ips); it carries no user data, every %s is filled by prepare() below
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT e.ip, e.rdap_json
             FROM %i AS e
             INNER JOIN (
                 SELECT ip, MAX(id) AS max_id
                 FROM %i
                 WHERE ip IN ({$placeholders}) AND rdap_json IS NOT NULL
                 GROUP BY ip
             ) AS latest ON latest.max_id = e.id",
            array_merge( array( $table, $table ), $ips )
        ),
        ARRAY_A
    );

    $map = array();

    foreach ( is_array( $rows ) ? $rows : array() as $row ) {
        $decoded = json_decode( (string) $row['rdap_json'], true );
        if ( ! is_array( $decoded ) ) {
            continue;
        }

        $map[ (string) $row['ip'] ] = array(
            'abuse_emails' => nppp_f2b_abuse_clean_emails( $decoded['abuse_emails'] ?? array() ),
            'netname'      => (string) ( $decoded['netname'] ?? '' ),
            'country'      => (string) ( $decoded['country'] ?? '' ),
        );
    }

    return $map;
}

// Validate + de-duplicate whatever the RDAP enrichment stored.
function nppp_f2b_abuse_clean_emails( $emails ): array {
    if ( ! is_array( $emails ) ) {
        return array();
    }

    $clean = array();
    foreach ( $emails as $email ) {
        $email = sanitize_email( nppp_f2b_abuse_strip_header_breaks( (string) $email ) );
        if ( is_email( $email ) ) {
            $clean[] = $email;
        }
    }

    return array_values( array_unique( $clean ) );
}

/**
 * Everything needed to build one report, resolved server-side from the event
 * table. The caller never supplies the recipient, the ban count or the
 * evidence — only the IP — so this endpoint cannot be turned into an open
 * relay by a compromised admin session posting an arbitrary address.
 *
 * @return array|null Null when the IP has no ban history in the window.
 */
function nppp_f2b_get_ip_report_data( string $ip ): ?array {
    $ip = filter_var( $ip, FILTER_VALIDATE_IP );
    if ( false === $ip ) {
        return null;
    }

    global $wpdb;
    $table  = nppp_f2b_table_name();
    $cutoff = nppp_f2b_window_cutoff();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $summary = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) AS ban_count, MIN(created_at) AS first_ban, MAX(created_at) AS last_ban
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s AND ip = %s",
            $table,
            $cutoff,
            $ip
        ),
        ARRAY_A
    );

    if ( ! is_array( $summary ) || (int) $summary['ban_count'] < 1 ) {
        return null;
    }

    $evidence_max = (int) apply_filters( 'nppp_f2b_abuse_evidence_max', NPPP_F2B_ABUSE_EVIDENCE_MAX );
    if ( $evidence_max < 1 ) {
        $evidence_max = 1;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $evidence = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT jail, created_at
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s AND ip = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $table,
            $cutoff,
            $ip,
            $evidence_max
        ),
        ARRAY_A
    );

    $evidence = is_array( $evidence ) ? $evidence : array();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $jails = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT jail
             FROM %i
             WHERE event_type = 'ban' AND created_at >= %s AND ip = %s
             ORDER BY jail ASC
             LIMIT 50",
            $table,
            $cutoff,
            $ip
        )
    );

    $rdap = nppp_f2b_get_abuse_map_for_ips( array( $ip ) );
    $rdap = $rdap[ $ip ] ?? array(
        'abuse_emails' => array(),
        'netname'      => '',
        'country'      => '',
    );

    // The network block and ASN are not part of the compact map above, so
    // pull the full record for the report header when it is available.
    $inetnum = '';
    $asns    = array();
    $org     = '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, not part of WP core schema
    $raw_rdap = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT rdap_json
             FROM %i
             WHERE ip = %s AND rdap_json IS NOT NULL
             ORDER BY id DESC
             LIMIT 1',
            $table,
            $ip
        )
    );

    if ( is_string( $raw_rdap ) && '' !== $raw_rdap ) {
        $decoded = json_decode( $raw_rdap, true );
        if ( is_array( $decoded ) ) {
            $inetnum = (string) ( $decoded['inetnum'] ?? '' );
            $org     = (string) ( $decoded['org_id'] ?? '' );
            $asns    = is_array( $decoded['origin_asns'] ?? null ) ? array_map( 'strval', $decoded['origin_asns'] ) : array();
        }
    }

    return array(
        'ip'           => $ip,
        'ban_count'    => (int) $summary['ban_count'],
        'first_ban'    => (string) $summary['first_ban'],
        'last_ban'     => (string) $summary['last_ban'],
        'jails'        => is_array( $jails ) ? array_map( 'strval', $jails ) : array(),
        'evidence'     => $evidence,
        'abuse_emails' => $rdap['abuse_emails'],
        'netname'      => $rdap['netname'],
        'country'      => $rdap['country'],
        'inetnum'      => $inetnum,
        'org_id'       => $org,
        'origin_asns'  => $asns,
        'window_days'  => (int) NPPP_F2B_WINDOW_DAYS,
    );
}

/**
 * Synthetic report payload for the "Send Test Email" button, shaped exactly
 * like nppp_f2b_get_ip_report_data() so it can be dropped into the same
 * renderer. Every value lives inside a documentation/reserved range (RFC
 * 5737 / RFC 5398) so it can never resolve to a real network or a real
 * abuse desk, and it never touches the fail2ban events table, the cooldown
 * log, or the hourly abuse rate limiter.
 *
 * @return array Same shape as nppp_f2b_get_ip_report_data().
 */
function nppp_f2b_abuse_dummy_report_data(): array {
    $now = time();

    $evidence = array(
        array(
            'jail'       => 'wp-login',
            'created_at' => gmdate( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS ),
        ),
        array(
            'jail'       => 'nginx-limit-req',
            'created_at' => gmdate( 'Y-m-d H:i:s', $now - 40 * MINUTE_IN_SECONDS ),
        ),
        array(
            'jail'       => 'nginx-botsearch',
            'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3 * HOUR_IN_SECONDS ),
        ),
    );

    return array(
        'ip'           => '203.0.113.45',
        'ban_count'    => 7,
        'first_ban'    => gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS ),
        'last_ban'     => gmdate( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS ),
        'jails'        => array( 'nginx-botsearch', 'nginx-limit-req', 'wp-login' ),
        'evidence'     => $evidence,
        'abuse_emails' => array(),
        'netname'      => 'EXAMPLE-TEST-NET',
        'country'      => 'ZZ',
        'inetnum'      => '203.0.113.0/24',
        'org_id'       => 'EXAMPLE-TEST-ORG',
        'origin_asns'  => array( 'AS64500' ),
        'window_days'  => (int) NPPP_F2B_WINDOW_DAYS,
    );
}

// ---------------------------------------------------------------------------
// Report rendering
// ---------------------------------------------------------------------------

// Stable, human-quotable identifier an abuse desk can reference in a reply.
function nppp_f2b_abuse_report_id( string $ip ): string {
    return 'NPP-' . strtoupper( substr( md5( $ip . '|' . gmdate( 'Y-m-d' ) . '|' . get_site_url() ), 0, 10 ) );
}

// Public IP of this server, for the "attacked target" block. Best effort:
// behind a proxy SERVER_ADDR is the upstream, which is still accurate enough
// for an abuse desk to correlate with its own flow logs.
function nppp_f2b_abuse_target_ip(): string {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP below, never echoed raw
    $addr = isset( $_SERVER['SERVER_ADDR'] ) ? (string) wp_unslash( $_SERVER['SERVER_ADDR'] ) : '';
    $addr = filter_var( $addr, FILTER_VALIDATE_IP );
    return false === $addr ? '' : $addr;
}

/**
 * Plain-text evidence block. Many abuse desks pipe the body through a parser
 * before a human ever sees it, so the report always carries a machine-readable
 * section alongside the styled table.
 */
function nppp_f2b_abuse_build_evidence_plain( array $data ): string {
    $lines = array();

    foreach ( $data['evidence'] as $row ) {
        $lines[] = sprintf(
            '%s UTC  jail=%s  src=%s  action=ban',
            (string) $row['created_at'],
            (string) $row['jail'],
            $data['ip']
        );
    }

    if ( empty( $lines ) ) {
        $lines[] = __( 'No individual ban records available.', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    return implode( "\n", $lines );
}

// Pre-rendered <tr> rows for the HTML evidence table in mail-abuse.html.
function nppp_f2b_abuse_build_evidence_rows( array $data ): string {
    $html = '';
    $i    = 0;

    foreach ( $data['evidence'] as $row ) {
        $i++;
        $bg = ( 0 === $i % 2 ) ? '#f8fafc' : '#ffffff';

        $html .= '<tr>'
            . '<td style="padding:7px 10px; border-bottom:1px solid #e2e8f0; background-color:' . $bg . '; font-family:Consolas,Menlo,monospace; font-size:12px; color:#334155; white-space:nowrap;">'
            . esc_html( (string) $row['created_at'] ) . ' UTC'
            . '</td>'
            . '<td style="padding:7px 10px; border-bottom:1px solid #e2e8f0; background-color:' . $bg . '; font-family:Consolas,Menlo,monospace; font-size:12px; color:#334155;">'
            . esc_html( (string) $row['jail'] )
            . '</td>'
            . '<td style="padding:7px 10px; border-bottom:1px solid #e2e8f0; background-color:' . $bg . '; font-family:Consolas,Menlo,monospace; font-size:12px; color:#ef4444; font-weight:bold;">BAN</td>'
            . '</tr>';
    }

    if ( '' === $html ) {
        $html = '<tr><td colspan="3" style="padding:12px; font-family:Arial,Helvetica,sans-serif; font-size:12px; color:#94a3b8;">'
            . esc_html__( 'No individual ban records available.', 'fastcgi-cache-purge-and-preload-nginx' )
            . '</td></tr>';
    }

    return $html;
}

function nppp_f2b_abuse_subject( array $data ): string {
    return sprintf(
        /* translators: 1: offending IP address, 2: reporting site domain */
        __( 'Abuse Report: repeated unauthorised access attempts from %1$s against %2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
        $data['ip'],
        nppp_f2b_abuse_site_domain()
    );
}

/**
 * Render includes/mail-abuse.html with the same str_replace token engine used
 * by nppp_send_mail_now(). Returns '' when the template is unreadable so the
 * caller can fail loudly instead of mailing an empty body.
 */
function nppp_f2b_abuse_render_email( array $data, array $settings, bool $is_test = false ): string {
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( false === $wp_filesystem ) {
        return '';
    }

    $template_file = __DIR__ . '/templates/mail-abuse.html';
    if ( ! $wp_filesystem->exists( $template_file ) ) {
        return '';
    }

    $html = $wp_filesystem->get_contents( $template_file );
    if ( empty( $html ) ) {
        return '';
    }

    // Stamp an unmissable banner on test sends so this can never be read as
    // a real abuse report, whether viewed inline or forwarded on later.
    if ( $is_test ) {
        $banner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="background-color:#b45309; padding:10px 40px; text-align:center;">'
            . '<p style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:12px; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase; color:#ffffff;">'
            . esc_html__( 'TEST EMAIL — sample data, not a real abuse report', 'fastcgi-cache-purge-and-preload-nginx' )
            . '</p></td></tr></table>';

        $html = str_replace( '<body style="margin:0; padding:0; background-color:#f1f5f9;">', '<body style="margin:0; padding:0; background-color:#f1f5f9;">' . $banner, $html );
    }

    $dash      = '&ndash;';
    $domain    = nppp_f2b_abuse_site_domain();
    $target_ip = nppp_f2b_abuse_target_ip();

    $tokens = array(
        '{{image_url}}'      => plugins_url( '/admin/img/logo-blackwhite.png', dirname( __FILE__ ) ),
        '{{site_url}}'       => esc_url( get_site_url() ),
        '{{domain}}'         => esc_html( $domain ),
        '{{report_id}}'      => esc_html( nppp_f2b_abuse_report_id( $data['ip'] ) ),
        '{{generated_at}}'   => esc_html( gmdate( 'Y-m-d H:i:s' ) ),
        '{{abuse_ip}}'       => esc_html( $data['ip'] ),
        '{{ban_count}}'      => esc_html( number_format_i18n( $data['ban_count'] ) ),
        '{{window_days}}'    => esc_html( (string) $data['window_days'] ),
        '{{first_seen}}'     => esc_html( '' !== $data['first_ban'] ? $data['first_ban'] : $dash ),
        '{{last_seen}}'      => esc_html( '' !== $data['last_ban'] ? $data['last_ban'] : $dash ),
        '{{jails}}'          => ! empty( $data['jails'] ) ? esc_html( implode( ', ', $data['jails'] ) ) : $dash,
        '{{netname}}'        => '' !== $data['netname'] ? esc_html( $data['netname'] ) : $dash,
        '{{inetnum}}'        => '' !== $data['inetnum'] ? esc_html( $data['inetnum'] ) : $dash,
        '{{country}}'        => '' !== $data['country'] ? esc_html( strtoupper( $data['country'] ) ) : $dash,
        '{{asns}}'           => ! empty( $data['origin_asns'] ) ? esc_html( implode( ', ', $data['origin_asns'] ) ) : $dash,
        '{{org_id}}'         => '' !== $data['org_id'] ? esc_html( $data['org_id'] ) : $dash,
        '{{target_host}}'    => esc_html( $domain ),
        '{{target_ip}}'      => '' !== $target_ip ? esc_html( $target_ip ) : $dash,
        '{{evidence_rows}}'  => nppp_f2b_abuse_build_evidence_rows( $data ),
        '{{evidence_plain}}' => esc_html( nppp_f2b_abuse_build_evidence_plain( $data ) ),
        '{{reporter_org}}'   => esc_html( $settings['org_name'] ),
        '{{reporter_name}}'  => esc_html( $settings['contact_name'] ),
        '{{reporter_email}}' => esc_html( '' !== $settings['reply_to'] ? $settings['reply_to'] : $settings['from_email'] ),
        '{{reporter_phone}}' => '' !== $settings['contact_phone'] ? esc_html( $settings['contact_phone'] ) : $dash,
    );

    return str_replace( array_keys( $tokens ), array_values( $tokens ), $html );
}

// ---------------------------------------------------------------------------
// Send pipeline
// ---------------------------------------------------------------------------

/**
 * Validate, render and dispatch one abuse report.
 *
 * Returns array( 'ok' => bool, 'message' => string ). Never throws; the AJAX
 * layer turns this into a toast so a single bad recipient cannot break the
 * batch path.
 */
function nppp_f2b_abuse_send_report( string $ip ): array {
    $settings = nppp_f2b_get_abuse_settings();

    if ( ! nppp_f2b_abuse_is_ready( $settings ) ) {
        return array(
            'ok'      => false,
            'message' => __( 'Finish the Abuse Reporter card first — sender address, organisation and contact name are all required.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    $data = nppp_f2b_get_ip_report_data( $ip );

    if ( null === $data ) {
        return array(
            'ok'      => false,
            'message' => __( 'No ban evidence stored for that address in the current window.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    if ( $data['ban_count'] < (int) $settings['min_bans'] ) {
        return array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: 1: recorded ban count, 2: configured minimum */
                __( 'Only %1$d bans recorded — the reporting threshold is %2$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $data['ban_count'],
                (int) $settings['min_bans']
            ),
        );
    }

    if ( empty( $data['abuse_emails'] ) ) {
        return array(
            'ok'      => false,
            'message' => __( 'No abuse contact is known for this network yet. It appears once the RDAP lookup for this address completes.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    $cooldown = (int) $settings['cooldown_days'] * DAY_IN_SECONDS;
    $last     = nppp_f2b_abuse_last_reported( $data['ip'] );

    if ( $last > 0 && ( time() - $last ) < $cooldown ) {
        return array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: %s: human readable time difference, e.g. "2 days" */
                __( 'Already reported %s ago. Wait for the cooldown to expire before reporting this address again.', 'fastcgi-cache-purge-and-preload-nginx' ),
                human_time_diff( $last, time() )
            ),
        );
    }

    $body = nppp_f2b_abuse_render_email( $data, $settings );

    if ( '' === $body ) {
        return array(
            'ok'      => false,
            'message' => __( 'The abuse report template could not be read. Reinstall the plugin files and try again.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    // Dry run stamps the cooldown too, so an operator testing the workflow
    // sees exactly the same UI state transition a real send produces.
    if ( 'yes' === $settings['dry_run'] ) {
        nppp_f2b_record_abuse_report( $data['ip'] );
        return array(
            'ok'      => true,
            'message' => sprintf(
                /* translators: 1: offending IP address, 2: comma separated abuse contacts */
                __( 'Dry run — no mail sent. A live report for %1$s would go to %2$s.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $data['ip'],
                implode( ', ', $data['abuse_emails'] )
            ),
        );
    }

    if ( nppp_f2b_abuse_rate_exceeded() ) {
        return array(
            'ok'      => false,
            'message' => __( 'Hourly abuse report limit reached. Try again later — abuse desks rate-limit senders that flood them.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    $from_name = '' !== $settings['from_name'] ? $settings['from_name'] : 'NPP Abuse Reporter';

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        sprintf( 'From: %s <%s>', $from_name, $settings['from_email'] ),
    );

    if ( '' !== $settings['reply_to'] ) {
        $headers[] = 'Reply-To: ' . $settings['reply_to'];

        if ( 'yes' === $settings['cc_self'] ) {
            $headers[] = 'Cc: ' . $settings['reply_to'];
        }
    }

    $sent = wp_mail(
        $data['abuse_emails'],
        nppp_f2b_abuse_subject( $data ),
        $body,
        $headers
    );

    if ( ! $sent ) {
        return array(
            'ok'      => false,
            'message' => __( 'WordPress could not hand the report to the mail transport. Check your SMTP configuration, then try again.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    nppp_f2b_record_abuse_report( $data['ip'] );

    return array(
        'ok'      => true,
        'message' => sprintf(
            /* translators: 1: offending IP address, 2: comma separated abuse contacts */
            __( 'Abuse report for %1$s sent to %2$s.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $data['ip'],
            implode( ', ', $data['abuse_emails'] )
        ),
    );
}

/**
 * Render the real template with dummy evidence and hand it to wp_mail(),
 * addressed back to the operator instead of a real abuse desk.
 *
 * Deliberately bypasses every gate that only makes sense for a real report:
 * nppp_f2b_abuse_is_ready() (min_bans/cooldown/contact fields are about the
 * outgoing report, not about whether mail can be sent at all), the ban
 * evidence lookup (there is none for a fake IP), the per-IP cooldown log,
 * dry_run, and the hourly abuse rate limiter. It keeps its own tiny
 * debounce instead so the button can't be hammered into a mail loop.
 *
 * $settings is taken as given by the caller (the AJAX callback below builds
 * it straight from the form fields) so a user can preview the template
 * before ever pressing "Save Reporter".
 *
 * @return array array( 'ok' => bool, 'message' => string ).
 */
function nppp_f2b_abuse_send_test_mail( array $settings ): array {
    $recipient = '' !== $settings['reply_to'] ? $settings['reply_to'] : $settings['from_email'];

    if ( '' === $recipient ) {
        return array(
            'ok'      => false,
            'message' => __( 'Enter a Reply-To address (or a Sender address) first — the test email is sent there so you can review it.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    if ( '' === $settings['from_email'] ) {
        return array(
            'ok'      => false,
            'message' => __( 'Enter a Sender address first — it is required to build the From header.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    $bucket = get_transient( NPPP_F2B_ABUSE_TEST_RATE_KEY );
    if ( is_int( $bucket ) && ( time() - $bucket ) < NPPP_F2B_ABUSE_TEST_COOLDOWN ) {
        return array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: %d: seconds to wait */
                __( 'Please wait %d seconds before sending another test email.', 'fastcgi-cache-purge-and-preload-nginx' ),
                NPPP_F2B_ABUSE_TEST_COOLDOWN - ( time() - $bucket )
            ),
        );
    }

    $data = nppp_f2b_abuse_dummy_report_data();
    $body = nppp_f2b_abuse_render_email( $data, $settings, true );

    if ( '' === $body ) {
        return array(
            'ok'      => false,
            'message' => __( 'The abuse report template could not be read. Reinstall the plugin files and try again.', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
    }

    $from_name = '' !== $settings['from_name'] ? $settings['from_name'] : 'NPP Abuse Reporter';

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        sprintf( 'From: %s <%s>', $from_name, $settings['from_email'] ),
    );

    // No Reply-To/Cc header here: the recipient of a test send already IS
    // the reply-to/self address, so adding it again would only risk a
    // duplicate copy in the same inbox depending on the SMTP provider.

    set_transient( NPPP_F2B_ABUSE_TEST_RATE_KEY, time(), NPPP_F2B_ABUSE_TEST_COOLDOWN );

    $subject = sprintf(
        /* translators: %s: the normal report subject line */
        __( '[TEST] %s', 'fastcgi-cache-purge-and-preload-nginx' ),
        nppp_f2b_abuse_subject( $data )
    );

    $mail_result = nppp_wp_mail_diagnostic( $recipient, $subject, $body, $headers );

    if ( ! $mail_result['sent'] ) {
        return array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: %s: the underlying mail transport error message (e.g. an SMTP connect/auth failure) */
                __( 'WordPress could not hand the test email to the mail transport: %s. This site\'s SMTP setup is outside this plugin\'s scope — check whatever mail plugin or server configuration you use for outgoing mail, then try again.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $mail_result['error']
            ),
        );
    }

    return array(
        'ok'      => true,
        'message' => sprintf(
            /* translators: %s: recipient email address the test was sent to */
            __( 'Test email sent to %s using dummy IP 203.0.113.45 — check that inbox (and spam folder) to review the template.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $recipient
        ),
    );
}

// ---------------------------------------------------------------------------
// UI helper
// ---------------------------------------------------------------------------

/**
 * Render one Report cell. Shared by the Repeat Offenders table and the Live
 * Feed so both panels stay visually and behaviourally identical.
 *
 * Echoes escaped markup. Every disabled state here is a UX affordance only —
 * nppp_f2b_abuse_send_report() re-checks all of it server-side.
 */
function nppp_f2b_render_report_cell(
    string $ip,
    bool $has_contact,
    int $ban_count,
    int $min_bans,
    int $last_reported,
    int $cooldown_days
): void {
    $in_cooldown = $last_reported > 0 && ( time() - $last_reported ) < ( $cooldown_days * DAY_IN_SECONDS );

    if ( $in_cooldown ) {
        printf(
            '<span class="nppp-f2b-report-done" title="%s">%s</span>',
            esc_attr(
                sprintf(
                    /* translators: %s: human readable time difference, e.g. "2 days" */
                    __( 'Reported %s ago — inside the cooldown window.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    human_time_diff( $last_reported, time() )
                )
            ),
            esc_html__( 'Reported', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    if ( ! $has_contact ) {
        printf(
            '<span class="nppp-f2b-report-na" title="%s">&mdash;</span>',
            esc_attr__( 'No abuse contact resolved for this network yet.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    if ( $ban_count < $min_bans ) {
        printf(
            '<span class="nppp-f2b-report-na" title="%s">&mdash;</span>',
            esc_attr(
                sprintf(
                    /* translators: %d: configured minimum ban count */
                    __( 'Below the reporting threshold of %d bans.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $min_bans
                )
            )
        );
        return;
    }

    printf(
        '<button type="button" class="nppp-f2b-btn nppp-f2b-report-btn" data-ip="%s">%s</button>',
        esc_attr( $ip ),
        esc_html__( 'Report', 'fastcgi-cache-purge-and-preload-nginx' )
    );
}

// ---------------------------------------------------------------------------
// AJAX callbacks
// AJAX callbacks registered centrally by the admin module.
// ---------------------------------------------------------------------------

function nppp_f2b_save_abuse_settings_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    $fields = array(
        'enabled',
        'from_name',
        'from_email',
        'reply_to',
        'cc_self',
        'org_name',
        'contact_name',
        'contact_phone',
        'min_bans',
        'cooldown_days',
        'dry_run',
    );

    $input = array();
    foreach ( $fields as $field ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in nppp_ajax_auth() above; $field is drawn from the fixed $fields whitelist, and every value is re-sanitized in nppp_f2b_sanitize_abuse_settings() below
        $input[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
    }

    $clean = nppp_f2b_sanitize_abuse_settings( $input );

    update_option( NPPP_F2B_ABUSE_OPTION, $clean, false );

    $ready = nppp_f2b_abuse_is_ready( $clean );

    wp_send_json_success(
        array(
            'ready'    => $ready,
            'settings' => $clean,
            'message'  => $ready
                ? __( 'Abuse Reporter saved. Report buttons are now available.', 'fastcgi-cache-purge-and-preload-nginx' )
                : __( 'Saved. The reporter stays inactive until it is enabled and the sender address, organisation and contact name are filled in.', 'fastcgi-cache-purge-and-preload-nginx' ),
        )
    );
}

// Build the confirmation dialog shown before anything leaves the server.
function nppp_f2b_abuse_preview_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
    $ip_raw = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
    $ip     = filter_var( $ip_raw, FILTER_VALIDATE_IP );

    if ( false === $ip ) {
        wp_send_json_error(
            array( 'message' => __( 'Invalid IP address.', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            400
        );
    }

    $settings = nppp_f2b_get_abuse_settings();
    $data     = nppp_f2b_get_ip_report_data( $ip );

    if ( null === $data ) {
        wp_send_json_error(
            array( 'message' => __( 'No ban evidence stored for that address in the current window.', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            404
        );
    }

    $last     = nppp_f2b_abuse_last_reported( $data['ip'] );
    $cooldown = (int) $settings['cooldown_days'] * DAY_IN_SECONDS;

    $blockers = array();

    if ( ! nppp_f2b_abuse_is_ready( $settings ) ) {
        $blockers[] = __( 'The Abuse Reporter card is incomplete.', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    if ( empty( $data['abuse_emails'] ) ) {
        $blockers[] = __( 'No abuse contact is known for this network yet.', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    if ( $data['ban_count'] < (int) $settings['min_bans'] ) {
        $blockers[] = sprintf(
            /* translators: 1: recorded ban count, 2: configured minimum */
            __( 'Only %1$d bans recorded — the reporting threshold is %2$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $data['ban_count'],
            (int) $settings['min_bans']
        );
    }

    if ( $last > 0 && ( time() - $last ) < $cooldown ) {
        $blockers[] = sprintf(
            /* translators: %s: human readable time difference, e.g. "2 days" */
            __( 'Already reported %s ago — still inside the cooldown.', 'fastcgi-cache-purge-and-preload-nginx' ),
            human_time_diff( $last, time() )
        );
    }

    ob_start();
    include plugin_dir_path( __FILE__ ) . 'partials/fail2ban-abuse-preview.php';
    $html = ob_get_clean();

    wp_send_json_success(
        array(
            'ip'      => $data['ip'],
            'can_send' => empty( $blockers ),
            'dry_run' => 'yes' === $settings['dry_run'],
            'html'    => $html,
        )
    );
}

/**
 * "Send Test Email" — validates and sanitizes whatever is currently in the
 * form (not necessarily saved yet) and sends one real email built from
 * dummy ban data to the operator's own Reply-To/Sender address, so the
 * template and the site's outgoing mail path can both be checked without
 * touching any real fail2ban evidence or any real abuse desk.
 */
function nppp_f2b_abuse_send_test_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    // Last-resort safety net: if a fatal error kills this request before a
    // wp_send_json_*() call runs (e.g. a bug in a third-party SMTP plugin
    // hooked into phpmailer_init), still emit valid JSON instead of a
    // blank/HTML body — that's what turns into an opaque "AJAX error"
    // client-side rather than an actionable message. Note this only helps
    // against a PHP-level fatal; it cannot protect against PHP-FPM's
    // request_terminate_timeout or nginx's fastcgi_read_timeout killing the
    // process outright — that's what nppp_wp_mail_diagnostic()'s tightened
    // connect timeout is for.
    register_shutdown_function( static function () {
        if ( headers_sent() ) {
            return; // A response (JSON or otherwise) was already sent.
        }
        $error = error_get_last();
        if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
            return; // Normal completion, or a non-fatal notice/warning.
        }
        if ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        wp_send_json_error(
            array(
                'message' => __( 'The test email could not be sent because the request failed unexpectedly on the server. Check your PHP error log for details.', 'fastcgi-cache-purge-and-preload-nginx' ),
            ),
            500
        );
    } );

    $fields = array(
        'enabled',
        'from_name',
        'from_email',
        'reply_to',
        'cc_self',
        'org_name',
        'contact_name',
        'contact_phone',
        'min_bans',
        'cooldown_days',
        'dry_run',
    );

    $input = array();
    foreach ( $fields as $field ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in nppp_ajax_auth() above; $field is drawn from the fixed $fields whitelist, and every value is re-sanitized in nppp_f2b_sanitize_abuse_settings() below
        $input[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
    }

    $settings = nppp_f2b_sanitize_abuse_settings( $input );
    $result   = nppp_f2b_abuse_send_test_mail( $settings );

    if ( empty( $result['ok'] ) ) {
        wp_send_json_error( array( 'message' => $result['message'] ) );
    }

    wp_send_json_success( array( 'message' => $result['message'] ) );
}

function nppp_f2b_abuse_send_callback() {
    nppp_ajax_auth( 'nppp-security-tab' );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in nppp_ajax_auth()
    $ip_raw = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
    $ip     = filter_var( $ip_raw, FILTER_VALIDATE_IP );

    if ( false === $ip ) {
        wp_send_json_error(
            array( 'message' => __( 'Invalid IP address.', 'fastcgi-cache-purge-and-preload-nginx' ) ),
            400
        );
    }

    $result = nppp_f2b_abuse_send_report( $ip );

    if ( empty( $result['ok'] ) ) {
        wp_send_json_error( array( 'message' => $result['message'] ) );
    }

    wp_send_json_success(
        array(
            'ip'      => $ip,
            'message' => $result['message'],
        )
    );
}
