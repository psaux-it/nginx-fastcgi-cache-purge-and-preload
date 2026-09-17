<?php
/**
 * Fail2ban Abuse Reporter — preview dialog body for Nginx Cache Purge Preload
 * Description: Rendered by nppp_f2b_abuse_preview_callback() and injected into
 *              the confirmation dialog before any report leaves the server.
 *              Expects $data, $settings and $blockers from the caller.
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

$nppp_abuse_dash = '—';
?>
<?php if ( ! empty( $blockers ) ) : ?>
    <div class="nppp-f2b-result nppp-f2b-result-fail">
        <?php foreach ( $blockers as $nppp_blocker ) : ?>
            <div><?php echo esc_html( $nppp_blocker ); ?></div>
        <?php endforeach; ?>
    </div>
<?php elseif ( 'yes' === $settings['dry_run'] ) : ?>
    <div class="nppp-f2b-note">
        <?php esc_html_e( 'Dry run is enabled. Pressing Send only records the attempt — no mail leaves this server.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </div>
<?php endif; ?>

<table class="nppp-f2b-table nppp-f2b-abuse-preview-table">
    <tbody>
        <tr>
            <th scope="row"><?php esc_html_e( 'To', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td>
                <?php if ( ! empty( $data['abuse_emails'] ) ) : ?>
                    <code><?php echo esc_html( implode( ', ', $data['abuse_emails'] ) ); ?></code>
                <?php else : ?>
                    <span class="nppp-f2b-abuse-muted"><?php esc_html_e( 'Unknown — RDAP lookup has not returned an abuse contact.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'From', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td>
                <code><?php echo esc_html( $settings['from_name'] . ' <' . $settings['from_email'] . '>' ); ?></code>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Subject', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td><?php echo esc_html( nppp_f2b_abuse_subject( $data ) ); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Offending IP', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td>
                <code><?php echo esc_html( $data['ip'] ); ?></code>
                <?php if ( '' !== $data['country'] ) : ?>
                    <span class="fi fi-<?php echo esc_attr( strtolower( $data['country'] ) ); ?>"></span>
                    <?php echo esc_html( strtoupper( $data['country'] ) ); ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Network', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td>
                <?php
                $nppp_net = array_filter(
                    array(
                        $data['netname'],
                        $data['inetnum'],
                        ! empty( $data['origin_asns'] ) ? implode( ', ', $data['origin_asns'] ) : '',
                    )
                );
                echo esc_html( ! empty( $nppp_net ) ? implode( ' · ', $nppp_net ) : $nppp_abuse_dash );
                ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Evidence', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td>
                <?php
                printf(
                    /* translators: 1: ban count, 2: window length in days, 3: comma separated jail names */
                    esc_html__( '%1$s bans in the last %2$d days across %3$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    esc_html( number_format_i18n( $data['ban_count'] ) ),
                    (int) $data['window_days'],
                    esc_html( ! empty( $data['jails'] ) ? implode( ', ', $data['jails'] ) : $nppp_abuse_dash )
                );
                ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'First / last ban', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            <td>
                <code><?php echo esc_html( $data['first_ban'] ); ?></code>
                &rarr;
                <code><?php echo esc_html( $data['last_ban'] ); ?></code>
                <span class="nppp-f2b-abuse-muted">UTC</span>
            </td>
        </tr>
    </tbody>
</table>

<p class="nppp-f2b-abuse-preview-label"><?php esc_html_e( 'Machine-readable evidence block included in the report', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
<pre class="nppp-f2b-abuse-evidence"><?php echo esc_html( nppp_f2b_abuse_build_evidence_plain( $data ) ); ?></pre>
