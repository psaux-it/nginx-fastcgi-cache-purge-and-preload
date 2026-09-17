<?php
/**
 * Fail2ban Security Tab for Nginx Cache Purge Preload
 * Description: Admin UI partial for the read-only Fail2ban Nginx jail monitor,
 *              including webhook setup, token management, diagnostics,
 *              jail activity, repeat offenders, and recent events.
 * Drop-in Version: 1.0.0
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$nppp_masked_token = substr( $token, 0, 8 ) . str_repeat( '•', 24 );
?>
<div id="nppp-f2b-tab">

    <div class="nppp-f2b-card nppp-f2b-card-connection">
        <div class="nppp-f2b-card-titlebar">
            <h3 class="nppp-f2b-card-title"><?php esc_html_e( 'Connection', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
            <div class="nppp-f2b-card-title-actions">
                <?php if ( $configured ) : ?>
                    <span class="nppp-f2b-pill nppp-f2b-pill-ok"><?php esc_html_e( 'Receiving events', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                <?php else : ?>
                    <span class="nppp-f2b-pill nppp-f2b-pill-wait"><?php esc_html_e( 'Waiting for first event', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                <?php endif; ?>
                <button type="button" class="nppp-f2b-btn nppp-f2b-btn-primary" id="nppp-f2b-test-connection">
                    <?php esc_html_e( 'Test Connection', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
            </div>
        </div>
        <div class="nppp-f2b-card-body">

            <div id="nppp-f2b-test-result" class="nppp-f2b-result" role="status" aria-live="polite" style="display:none;"></div>

            <div class="nppp-f2b-row">
                <label for="nppp-f2b-endpoint-field"><?php esc_html_e( 'Webhook URL', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                <div class="nppp-f2b-copy">
                    <input type="text" readonly id="nppp-f2b-endpoint-field" value="<?php echo esc_attr( $endpoint ); ?>" />
                    <button type="button" class="nppp-f2b-btn nppp-f2b-copy-btn" data-copy-target="nppp-f2b-endpoint-field">
                        <?php esc_html_e( 'Copy', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                </div>
            </div>

            <div class="nppp-f2b-row">
                <label for="nppp-f2b-token-field"><?php esc_html_e( 'Bearer Token', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                <div class="nppp-f2b-copy">
                    <input type="text" readonly id="nppp-f2b-token-field"
                        value="<?php echo esc_attr( $nppp_masked_token ); ?>"
                        data-full="<?php echo esc_attr( $token ); ?>"
                        data-masked="<?php echo esc_attr( $nppp_masked_token ); ?>" />
                    <button type="button" class="nppp-f2b-btn" id="nppp-f2b-reveal-token">
                        <?php esc_html_e( 'Show', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                    <button type="button" class="nppp-f2b-btn nppp-f2b-copy-btn" data-copy-target="nppp-f2b-token-field" data-copy-full="1">
                        <?php esc_html_e( 'Copy', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                    <button type="button" class="nppp-f2b-btn nppp-f2b-btn-danger" id="nppp-f2b-regenerate-token">
                        <?php esc_html_e( 'Regenerate', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                </div>
                <p class="nppp-f2b-hint-text"><?php esc_html_e( 'Regenerating invalidates the current token immediately. Update jail.local and reload fail2ban straight afterwards or events stop arriving.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
            </div>

            <details class="nppp-f2b-setup" <?php echo $configured ? '' : 'open'; ?>>
                <summary><?php esc_html_e( 'Server-side setup — one time, over SSH', 'fastcgi-cache-purge-and-preload-nginx' ); ?></summary>

                <p class="nppp-f2b-step"><span class="nppp-f2b-step-n">1</span> <code>/etc/fail2ban/action.d/nppp-webhook.conf</code></p>
                <div class="nppp-f2b-copy nppp-f2b-copy-block">
                    <textarea readonly rows="12" class="nppp-f2b-code" id="nppp-f2b-action-conf"><?php echo esc_textarea( $action_snippet ); ?></textarea>
                    <button type="button" class="nppp-f2b-btn nppp-f2b-copy-btn" data-copy-target="nppp-f2b-action-conf">
                        <?php esc_html_e( 'Copy', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                </div>

                <p class="nppp-f2b-step"><span class="nppp-f2b-step-n">2</span> <code>/etc/fail2ban/jail.local</code> — <?php esc_html_e( 'add under each nginx jail', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                <div class="nppp-f2b-copy nppp-f2b-copy-block">
                    <textarea readonly rows="8" class="nppp-f2b-code" id="nppp-f2b-jail-snippet"><?php echo esc_textarea( $jail_snippet ); ?></textarea>
                    <button type="button" class="nppp-f2b-btn nppp-f2b-copy-btn" data-copy-target="nppp-f2b-jail-snippet">
                        <?php esc_html_e( 'Copy', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                </div>

                <p class="nppp-f2b-step"><span class="nppp-f2b-step-n">3</span> <?php esc_html_e( 'Reload fail2ban, then press Test Connection above.', 'fastcgi-cache-purge-and-preload-nginx' ); ?> <code>sudo systemctl reload fail2ban</code></p>

                <p class="nppp-f2b-note">
                    <?php esc_html_e( 'Nginx + PHP-FPM: if Test Connection reports HTTP 404, PHP is not receiving the Authorization header. Add this inside your PHP location block and reload Nginx.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br>
                    <code>fastcgi_param HTTP_AUTHORIZATION $http_authorization;</code>
                </p>
            </details>
        </div>
    </div>

    <?php if ( ! $configured ) : ?>
        <div class="nppp-f2b-banner">
            <?php esc_html_e( 'No events received yet. Complete the setup above, then use Test Connection to verify the whole path end to end.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </div>
    <?php endif; ?>

    <div class="nppp-f2b-activity-toolbar">
        <h3 class="nppp-f2b-activity-toolbar-title"><?php esc_html_e( 'Activity Overview', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
        <div class="nppp-f2b-toolbar">
            <button type="button" class="nppp-f2b-btn" id="nppp-f2b-refresh">
                <?php esc_html_e( 'Refresh', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </button>
            <button type="button" class="nppp-f2b-btn nppp-f2b-btn-danger" id="nppp-f2b-clear-log">
                <?php esc_html_e( 'Clear All Events', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </button>
        </div>
    </div>

    <div class="nppp-f2b-stats">
        <div class="nppp-f2b-stat nppp-f2b-stat-ban">
            <span class="nppp-f2b-stat-num"><?php echo esc_html( (string) $stats['bans_24h'] ); ?></span>
            <span class="nppp-f2b-stat-label"><?php esc_html_e( 'Bans / 24h', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </div>
        <div class="nppp-f2b-stat nppp-f2b-stat-unban">
            <span class="nppp-f2b-stat-num"><?php echo esc_html( (string) $stats['unbans_24h'] ); ?></span>
            <span class="nppp-f2b-stat-label"><?php esc_html_e( 'Unbans / 24h', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </div>
        <div class="nppp-f2b-stat nppp-f2b-stat-jail">
            <span class="nppp-f2b-stat-num"><?php echo esc_html( (string) $stats['jails'] ); ?></span>
            <span class="nppp-f2b-stat-label"><?php esc_html_e( 'Active jails / 24h', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </div>
        <div class="nppp-f2b-stat nppp-f2b-stat-total">
            <span class="nppp-f2b-stat-num"><?php echo esc_html( (string) $stats['window'] ); ?></span>
            <span class="nppp-f2b-stat-label">
                <?php
                printf(
                    /* translators: %d: number of days in the rolling reporting window */
                    esc_html__( 'Events / %dd', 'fastcgi-cache-purge-and-preload-nginx' ),
                    (int) $window_days
                );
                ?>
            </span>
        </div>
    </div>

    <h3 class="nppp-f2b-section"><?php esc_html_e( 'Jail Activity — last 24 hours', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
    <?php if ( empty( $summaries ) ) : ?>
        <p class="nppp-f2b-empty"><?php esc_html_e( 'No jail activity in the last 24 hours.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <?php else : ?>
        <div class="nppp-f2b-jails">
            <?php foreach ( $summaries as $nppp_row ) : ?>
                <div class="nppp-f2b-jail">
                    <h4><?php echo esc_html( $nppp_row['jail'] ); ?></h4>
                    <div class="nppp-f2b-jail-nums">
                        <span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_row['bans'] ); ?></span>
                        <span class="nppp-f2b-badge-cap"><?php esc_html_e( 'bans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        <span class="nppp-f2b-badge nppp-f2b-badge-unban"><?php echo esc_html( (string) (int) $nppp_row['unbans'] ); ?></span>
                        <span class="nppp-f2b-badge-cap"><?php esc_html_e( 'unbans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    </div>
                    <p class="nppp-f2b-jail-last">
                        <?php esc_html_e( 'Last event:', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        <?php echo esc_html( nppp_f2b_local_time( (string) $nppp_row['last_event'] ) ); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 class="nppp-f2b-section">
        <?php if ( ! empty( $recidive ) && $recidive_total > count( $recidive ) ) : ?>
            <?php
            printf(
                /* translators: 1: number of days in the rolling reporting window, 2: number of IPs shown, 3: total repeat-offender IPs in that window */
                esc_html__( 'Repeat Offenders — last %1$d days (top %2$s of %3$s)', 'fastcgi-cache-purge-and-preload-nginx' ),
                (int) $window_days,
                esc_html( number_format_i18n( count( $recidive ) ) ),
                esc_html( number_format_i18n( $recidive_total ) )
            );
            ?>
        <?php else : ?>
            <?php
            printf(
                /* translators: %d: number of days in the rolling reporting window */
                esc_html__( 'Repeat Offenders — last %d days', 'fastcgi-cache-purge-and-preload-nginx' ),
                (int) $window_days
            );
            ?>
        <?php endif; ?>
    </h3>
    <?php if ( ! empty( $recidive ) ) : ?>
        <div class="nppp-f2b-repeat">
            <div class="nppp-f2b-repeat-list">
    <?php endif; ?>

    <table class="nppp-f2b-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'IP Address', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Bans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Last Ban', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $recidive ) ) : ?>
                <tr><td colspan="3" class="nppp-f2b-empty-cell"><?php esc_html_e( 'No repeat offenders — that is a good sign.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $recidive as $nppp_row ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $nppp_row['ip'] ); ?></code></td>
                        <td><span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_row['ban_count'] ); ?></span></td>
                        <td><?php echo esc_html( nppp_f2b_local_time( (string) $nppp_row['last_ban'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( ! empty( $recidive ) ) : ?>
            </div>

            <div class="nppp-f2b-repeat-chart-wrap">
                <div
                    id="nppp-f2b-offenders-chart"
                    class="nppp-f2b-offenders-chart"
                    data-offenders="<?php
                        echo esc_attr(
                            wp_json_encode(
                                array_map(
                                    static function ( $nppp_row ) {
                                        return array(
                                            'ip'    => (string) $nppp_row['ip'],
                                            'count' => (int) $nppp_row['ban_count'],
                                        );
                                    },
                                    $recidive
                                )
                            )
                        );
                    ?>"
                ></div>
            </div>
        </div>
    <?php endif; ?>

    <h3 class="nppp-f2b-section">
        <?php if ( $country_available && ! empty( $top_countries ) && $top_countries_total > count( $top_countries ) ) : ?>
            <?php
            printf(
                /* translators: 1: number of days in the full retention window, 2: number of countries shown, 3: total distinct countries in that window */
                esc_html__( 'Top Attack Countries — last %1$d days (top %2$s of %3$s)', 'fastcgi-cache-purge-and-preload-nginx' ),
                (int) $country_days,
                esc_html( number_format_i18n( count( $top_countries ) ) ),
                esc_html( number_format_i18n( $top_countries_total ) )
            );
            ?>
        <?php else : ?>
            <?php
            printf(
                /* translators: %d: number of days in the full retention window */
                esc_html__( 'Top Attack Countries — last %d days', 'fastcgi-cache-purge-and-preload-nginx' ),
                (int) $country_days
            );
            ?>
        <?php endif; ?>
    </h3>

    <?php if ( ! $country_available ) : ?>
        <p class="nppp-f2b-empty">
            <?php esc_html_e( 'This panel needs a one-time database update. It activates automatically the next time an administrator opens this page.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </p>
    <?php elseif ( empty( $top_countries ) ) : ?>
        <p class="nppp-f2b-empty">
            <?php esc_html_e( 'No enriched ban events yet. Countries appear here once RDAP lookups complete for banned IPs.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </p>
    <?php else : ?>
        <div class="nppp-f2b-geo">
            <div class="nppp-f2b-geo-list">
                <table class="nppp-f2b-table nppp-f2b-geo-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Country', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                            <th><?php esc_html_e( 'Bans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top_countries as $nppp_country_row ) : ?>
                            <?php $nppp_cc = strtolower( (string) $nppp_country_row['country'] ); ?>
                            <tr>
                                <td>
                                    <span class="fi fi-<?php echo esc_attr( $nppp_cc ); ?>"></span>
                                    <?php echo esc_html( strtoupper( (string) $nppp_country_row['country'] ) ); ?>
                                </td>
                                <td>
                                    <span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_country_row['attack_count'] ); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="nppp-f2b-geo-map-wrap">
                <div
                    id="nppp-f2b-world-map"
                    class="nppp-f2b-world-map"
                    data-countries="<?php
                        echo esc_attr(
                            wp_json_encode(
                                array_map(
                                    static function ( $nppp_country_row ) {
                                        return array(
                                            'code'  => strtoupper( (string) $nppp_country_row['country'] ),
                                            'count' => (int) $nppp_country_row['attack_count'],
                                        );
                                    },
                                    $top_countries_map
                                )
                            )
                        );
                    ?>"
                ></div>
                <p class="nppp-f2b-geo-hint">
                    <?php esc_html_e( 'Bubble size and color reflect ban volume. Drag to pan, use the +/- buttons to zoom, hover a bubble for the exact count.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <h3 class="nppp-f2b-section">
        <?php if ( $feed_truncated ) : ?>
            <?php
            printf(
                /* translators: 1: number of events shown, 2: total number of events stored */
                esc_html__( 'Live Feed — showing most recent %1$s of %2$s events', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_html( number_format_i18n( count( $recent ) ) ),
                esc_html( number_format_i18n( $total_events ) )
            );
            ?>
        <?php else : ?>
            <?php esc_html_e( 'Live Feed — all events', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        <?php endif; ?>
    </h3>

    <div class="nppp-f2b-feed">
        <?php if ( empty( $recent ) ) : ?>
            <div class="nppp-f2b-feed-empty">
                <?php esc_html_e( 'No events yet.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </div>
        <?php else : ?>
            <table id="nppp-f2b-feed-table" class="nppp-f2b-feed-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Event', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Jail', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'IP', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Country', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Network', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Range', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'ASN', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Abuse', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ( $recent as $nppp_row ) : ?>
                        <?php
                        $nppp_rdap = array();

                        if ( ! empty( $nppp_row['rdap_json'] ) ) {
                            $nppp_decoded = json_decode( $nppp_row['rdap_json'], true );

                            if ( is_array( $nppp_decoded ) ) {
                                $nppp_rdap = $nppp_decoded;
                            }
                        }
                        ?>

                        <tr class="nppp-f2b-is-<?php echo esc_attr( $nppp_row['event_type'] ); ?>">
                            <td class="nppp-f2b-ts">
                                <?php echo esc_html( nppp_f2b_local_time( (string) $nppp_row['created_at'] ) ); ?>
                            </td>

                            <td class="nppp-f2b-ev">
                                <?php echo esc_html( strtoupper( $nppp_row['event_type'] ) ); ?>
                            </td>

                            <td class="nppp-f2b-jailname">
                                <?php echo esc_html( $nppp_row['jail'] ); ?>
                            </td>

                            <td class="nppp-f2b-ip">
                                <?php echo esc_html( $nppp_row['ip'] ); ?>
                            </td>

                            <td>
                                <?php if ( ! empty( $nppp_rdap['country'] ) ) : ?>
                                    <span class="fi fi-<?php echo esc_attr( strtolower( $nppp_rdap['country'] ) ); ?>"></span>
                                    <?php echo esc_html( strtoupper( $nppp_rdap['country'] ) ); ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php echo ! empty( $nppp_rdap['netname'] ) ? esc_html( $nppp_rdap['netname'] ) : ''; ?>
                            </td>

                            <td>
                                <?php echo ! empty( $nppp_rdap['inetnum'] ) ? esc_html( $nppp_rdap['inetnum'] ) : ''; ?>
                            </td>

                            <td>
                                <?php echo ! empty( $nppp_rdap['origin_asns'] ) ? esc_html( implode( ', ', $nppp_rdap['origin_asns'] ) ) : ''; ?>
                            </td>

                            <td>
                                <?php echo ! empty( $nppp_rdap['abuse_emails'] ) ? esc_html( implode( ', ', $nppp_rdap['abuse_emails'] ) ) : ''; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <p class="nppp-f2b-foot">
        <?php
        printf(
            /* translators: %d: retention period in days */
            esc_html__( 'Events older than %d days are pruned automatically by a WP-Cron job that runs every three hours.', 'fastcgi-cache-purge-and-preload-nginx' ),
            (int) $retention_days
        );
        ?>
    </p>
</div>
