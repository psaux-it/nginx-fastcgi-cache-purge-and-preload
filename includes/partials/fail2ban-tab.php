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

            <div id="nppp-f2b-test-result" class="nppp-f2b-result" role="status" aria-live="polite" style="display:none;">
                <button type="button" class="nppp-f2b-result-close" aria-label="<?php esc_attr_e( 'Dismiss', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&times;</button>
                <span class="nppp-f2b-result-msg"></span>
            </div>

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

            <details class="nppp-f2b-setup">
                <summary><?php esc_html_e( 'Optional hardening — Nginx request rate limit', 'fastcgi-cache-purge-and-preload-nginx' ); ?></summary>

                <p class="nppp-f2b-note">
                    <?php esc_html_e( 'The flock in step 1 above only serializes calls made through fail2ban itself. It does not stop this endpoint from accepting unlimited concurrent requests from anything else -- a misconfigured client, a leaked token, a flood. Under a large burst that can pin every PHP-FPM worker in this site\'s pool and slow the whole site down. This caps concurrency in nginx, before PHP starts, with no separate FPM pool required.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </p>

                <p class="nppp-f2b-step"><span class="nppp-f2b-step-n">1</span> <?php esc_html_e( 'nginx.conf (or a conf.d file) and this site\'s server block', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                <div class="nppp-f2b-copy nppp-f2b-copy-block">
                    <textarea readonly rows="10" class="nppp-f2b-code" id="nppp-f2b-nginx-rl-snippet"><?php echo esc_textarea( $nginx_rl_snippet ); ?></textarea>
                    <button type="button" class="nppp-f2b-btn nppp-f2b-copy-btn" data-copy-target="nppp-f2b-nginx-rl-snippet">
                        <?php esc_html_e( 'Copy', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                </div>

                <p class="nppp-f2b-step"><span class="nppp-f2b-step-n">2</span> <?php esc_html_e( 'Test config and reload nginx.', 'fastcgi-cache-purge-and-preload-nginx' ); ?> <code>sudo nginx -t && sudo systemctl reload nginx</code></p>

                <p class="nppp-f2b-note">
                    <?php esc_html_e( 'Requires pretty permalinks (Settings > Permalinks, not "Plain"). Tuning notes are in the comments inside the snippet.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </p>
            </details>
        </div>
    </div>

    <?php
    // Static, non-dynamic reference snippets for the Options card below.
    $nppp_opt_retention_snippet = <<<'PHP'
// NPP - Fail2Ban Monitor: data retention & list sizes.

// Days an event stays in the database before the cleanup cron purges it.
// Default: 90
add_filter( 'nppp_f2b_retention_days', function( $days ) {
    return 180;
} );

// Hard ceiling on rows ever pulled into the Live Feed table.
// Default: 5000
add_filter( 'nppp_f2b_feed_hard_cap', function( $cap ) {
    return 10000;
} );

// How many IPs are listed in "Repeat Offenders".
// Default: 5
add_filter( 'nppp_f2b_recidive_top_n', function( $n ) {
    return 10;
} );

// How many countries are listed in "Top Attack Countries".
// Default: 8
add_filter( 'nppp_f2b_top_countries_n', function( $n ) {
    return 12;
} );

// How many countries are plotted on the Top Attack Countries bubble map.
// Default: 300
add_filter( 'nppp_f2b_map_countries_n', function( $n ) {
    return 500;
} );
PHP;

    $nppp_opt_rdap_snippet = <<<'PHP'
// NPP - Fail2Ban Monitor: RDAP lookups (country / network / ASN / abuse contact).

// How long a successful RDAP profile is cached per IP, in seconds.
// Default: 30 days
add_filter( 'nppp_f2b_rdap_cache_ttl', function( $ttl ) {
    return 60 * DAY_IN_SECONDS;
} );

// How long a failed/empty RDAP lookup is cached before the worker retries it.
// Default: 5 minutes
add_filter( 'nppp_f2b_rdap_negative_cache_ttl', function( $ttl ) {
    return 10 * MINUTE_IN_SECONDS;
} );

// HTTP timeout, in seconds, for each outgoing RDAP request.
// Default: 3
add_filter( 'nppp_f2b_rdap_timeout', function( $seconds ) {
    return 5;
} );

// Max retry attempts per IP before the worker gives up on that lookup.
// Default: 3
add_filter( 'nppp_f2b_rdap_max_attempts', function( $attempts ) {
    return 5;
} );

// Minimum spacing, in seconds, enforced between retry attempts for the same IP.
// Default: 120
add_filter( 'nppp_f2b_rdap_retry_gap', function( $seconds ) {
    return 180;
} );

// The "sourceapp" identifier sent with every RDAP request (RIPE convention).
// Default: npp-wp-plugin-fail2ban-monitor
add_filter( 'nppp_f2b_rdap_sourceapp', function( $sourceapp ) {
    return 'my-site-fail2ban-monitor';
} );
PHP;

    $nppp_opt_worker_snippet = <<<'PHP'
// NPP - Fail2Ban Monitor: background RDAP worker & cron fallback.

// Disable the CLI worker entirely and force all RDAP enrichment through
// the small inline cron batch instead. Default: true (worker enabled)
add_filter( 'nppp_f2b_enable_cli_worker', '__return_false' );

// IPs processed per CLI worker batch.
// Default: 4
add_filter( 'nppp_f2b_worker_batch', function( $batch ) {
    return 8;
} );

// Max seconds the CLI worker keeps running before exiting cleanly.
// Default: 600
add_filter( 'nppp_f2b_worker_max_runtime', function( $seconds ) {
    return 300;
} );

// IPs processed inline per WP-Cron tick -- only used when shell_exec()
// is unavailable on this host. Default: 3
add_filter( 'nppp_f2b_cron_inline_batch', function( $limit ) {
    return 5;
} );

// Consecutive failed RDAP batches before the worker stops early, assuming
// an upstream RDAP outage rather than burning its full runtime. Default: 3
add_filter( 'nppp_f2b_consecutive_batch_fail_limit', function( $limit ) {
    return 5;
} );

// Thresholds that trigger a "queue is backing up" WARNING log entry:
// pending IPs, then oldest pending age in seconds. Defaults: 100 / 10 minutes
add_filter( 'nppp_f2b_backlog_warn_ips', function( $ips ) {
    return 200;
} );
add_filter( 'nppp_f2b_backlog_warn_age', function( $seconds ) {
    return 20 * MINUTE_IN_SECONDS;
} );
PHP;

    $nppp_opt_nginx_snippet = <<<'PHP'
// NPP - Fail2Ban Monitor: values used by the auto-generated Nginx
// rate-limit snippet under "Optional hardening" above. Changing these
// regenerates the snippet text on next page load -- you still need to
// re-copy it into Nginx and reload.

// Request rate passed to Nginx's limit_req_zone.
// Default: 5r/s
add_filter( 'nppp_f2b_nginx_rl_rate', function( $rate ) {
    return '10r/s';
} );

// Burst allowance passed to Nginx's limit_req.
// Default: 30
add_filter( 'nppp_f2b_nginx_rl_burst', function( $burst ) {
    return 50;
} );
PHP;

    $nppp_opt_abuse_snippet = <<<'PHP'
// NPP - Fail2Ban Monitor: Abuse Reporter limits.

// Max abuse reports this site will send in a rolling hour, across all IPs.
// Default: 20
add_filter( 'nppp_f2b_abuse_hourly_max', function( $max ) {
    return 40;
} );

// Max recent ban events attached as evidence lines to a single report.
// Default: 20
add_filter( 'nppp_f2b_abuse_evidence_max', function( $max ) {
    return 30;
} );
PHP;
    ?>

    <div class="nppp-f2b-card nppp-f2b-card-options">
        <div class="nppp-f2b-card-titlebar">
            <h3 class="nppp-f2b-card-title"><?php esc_html_e( 'Options', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
        </div>
        <div class="nppp-f2b-card-body">

            <p class="nppp-f2b-hint-text">
                <?php esc_html_e( 'Every value below has a sane default and needs no action. They are plain WordPress filters, not settings stored by this plugin -- add the ones you want to change to your child theme\'s functions.php (or a site-specific mu-plugin) and reload the page; there is nothing to save here.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </p>

            <div class="nppp-f2b-opt-tabs" role="tablist">
                <button type="button" class="nppp-f2b-opt-tab is-active" role="tab" aria-selected="true" aria-controls="nppp-f2b-opt-panel-retention" data-opt-panel="nppp-f2b-opt-panel-retention">
                    <?php esc_html_e( 'Data retention & list sizes', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
                <button type="button" class="nppp-f2b-opt-tab" role="tab" aria-selected="false" aria-controls="nppp-f2b-opt-panel-rdap" data-opt-panel="nppp-f2b-opt-panel-rdap">
                    <?php esc_html_e( 'RDAP lookups', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
                <button type="button" class="nppp-f2b-opt-tab" role="tab" aria-selected="false" aria-controls="nppp-f2b-opt-panel-worker" data-opt-panel="nppp-f2b-opt-panel-worker">
                    <?php esc_html_e( 'Background worker & cron fallback', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
                <button type="button" class="nppp-f2b-opt-tab" role="tab" aria-selected="false" aria-controls="nppp-f2b-opt-panel-nginx" data-opt-panel="nppp-f2b-opt-panel-nginx">
                    <?php esc_html_e( 'Nginx rate-limit snippet values', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
                <button type="button" class="nppp-f2b-opt-tab" role="tab" aria-selected="false" aria-controls="nppp-f2b-opt-panel-abuse" data-opt-panel="nppp-f2b-opt-panel-abuse">
                    <?php esc_html_e( 'Abuse Reporter limits', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
            </div>

            <div class="nppp-f2b-opt-panels">

                <div class="nppp-f2b-opt-panel is-active" id="nppp-f2b-opt-panel-retention" role="tabpanel">
                    <p class="nppp-f2b-note">
                        <?php esc_html_e( 'How long events are kept, and how many rows the Live Feed, Repeat Offenders, Top Attack Countries list, and bubble map each show.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </p>
                    <div class="nppp-f2b-copy nppp-f2b-copy-block">
                        <textarea readonly rows="21" class="nppp-f2b-code" id="nppp-f2b-opt-retention"><?php echo esc_textarea( $nppp_opt_retention_snippet ); ?></textarea>
                    </div>
                </div>

                <div class="nppp-f2b-opt-panel" id="nppp-f2b-opt-panel-rdap" role="tabpanel">
                    <p class="nppp-f2b-note">
                        <?php esc_html_e( 'Caching, timeout, and retry behaviour for the per-IP RDAP lookups that resolve country, network, ASN, and abuse contact.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </p>
                    <div class="nppp-f2b-copy nppp-f2b-copy-block">
                        <textarea readonly rows="24" class="nppp-f2b-code" id="nppp-f2b-opt-rdap"><?php echo esc_textarea( $nppp_opt_rdap_snippet ); ?></textarea>
                    </div>
                </div>

                <div class="nppp-f2b-opt-panel" id="nppp-f2b-opt-panel-worker" role="tabpanel">
                    <p class="nppp-f2b-note">
                        <?php esc_html_e( 'Batch sizes, runtime caps, and backlog warning thresholds for the CLI worker that enriches events in the background, and its inline cron fallback for hosts without shell_exec().', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </p>
                    <div class="nppp-f2b-copy nppp-f2b-copy-block">
                        <textarea readonly rows="27" class="nppp-f2b-code" id="nppp-f2b-opt-worker"><?php echo esc_textarea( $nppp_opt_worker_snippet ); ?></textarea>
                    </div>
                </div>

                <div class="nppp-f2b-opt-panel" id="nppp-f2b-opt-panel-nginx" role="tabpanel">
                    <p class="nppp-f2b-note">
                        <?php esc_html_e( 'Feeds the auto-generated Nginx snippet in "Optional hardening" above -- change these, then re-copy the regenerated snippet into Nginx.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </p>
                    <div class="nppp-f2b-copy nppp-f2b-copy-block">
                        <textarea readonly rows="13" class="nppp-f2b-code" id="nppp-f2b-opt-nginx"><?php echo esc_textarea( $nppp_opt_nginx_snippet ); ?></textarea>
                    </div>
                </div>

                <div class="nppp-f2b-opt-panel" id="nppp-f2b-opt-panel-abuse" role="tabpanel">
                    <p class="nppp-f2b-note">
                        <?php esc_html_e( 'Caps that apply on top of the Abuse Reporter settings below, regardless of what is configured there.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </p>
                    <div class="nppp-f2b-copy nppp-f2b-copy-block">
                        <textarea readonly rows="12" class="nppp-f2b-code" id="nppp-f2b-opt-abuse"><?php echo esc_textarea( $nppp_opt_abuse_snippet ); ?></textarea>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="nppp-f2b-card nppp-f2b-card-abuse">
        <div class="nppp-f2b-card-titlebar">
            <h3 class="nppp-f2b-card-title"><?php esc_html_e( 'Abuse Reporter', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
            <div class="nppp-f2b-card-title-actions">
                <?php if ( $abuse_ready ) : ?>
                    <span class="nppp-f2b-pill nppp-f2b-pill-ok" id="nppp-f2b-abuse-pill"><?php esc_html_e( 'Armed', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                <?php else : ?>
                    <span class="nppp-f2b-pill nppp-f2b-pill-wait" id="nppp-f2b-abuse-pill"><?php esc_html_e( 'Not configured', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                <?php endif; ?>
                <button type="button" class="nppp-f2b-btn nppp-f2b-btn-primary" id="nppp-f2b-abuse-test">
                    <?php esc_html_e( 'Send Test Email', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
                <span class="nppp-tooltip nppp-f2b-info-badge" tabindex="0" aria-label="<?php esc_attr_e( 'What Send Test Email does', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">
                    ?
                    <span class="nppp-tooltiptext">
                        <?php esc_html_e( 'Sends the same template with a dummy IP and dummy ban data to the Reply-To address below (or the Sender address if Reply-To is empty). Uses the form as it is right now — no need to save first. Assumes WordPress can already send mail; SMTP setup is outside this plugin\'s scope.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </span>
                </span>
                <button type="button" class="nppp-f2b-btn nppp-f2b-btn-primary" id="nppp-f2b-abuse-save">
                    <?php esc_html_e( 'Save Reporter', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                </button>
            </div>
        </div>
        <div class="nppp-f2b-card-body">

            <div id="nppp-f2b-abuse-save-result" class="nppp-f2b-result" role="status" aria-live="polite" style="display:none;">
                <button type="button" class="nppp-f2b-result-close" aria-label="<?php esc_attr_e( 'Dismiss', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&times;</button>
                <span class="nppp-f2b-result-msg"></span>
            </div>
            <div id="nppp-f2b-abuse-test-result" class="nppp-f2b-result" role="status" aria-live="polite" style="display:none;">
                <button type="button" class="nppp-f2b-result-close" aria-label="<?php esc_attr_e( 'Dismiss', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&times;</button>
                <span class="nppp-f2b-result-msg"></span>
            </div>

            <p class="nppp-f2b-hint-text nppp-f2b-abuse-intro">
                <?php esc_html_e( 'Sends a formal abuse report to the network owner of a banned IP, built from the ban evidence below and addressed to the abuse contact already resolved for that network. Set this once and the Report buttons stay available in Repeat Offenders and the Live Feed. Use "Send Test Email" above to preview the template and confirm this site can deliver mail before relying on it.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </p>

            <div class="nppp-f2b-abuse-grid">

                <div class="nppp-f2b-row nppp-f2b-abuse-field nppp-f2b-abuse-field-wide">
                    <label for="nppp-f2b-abuse-enabled"><?php esc_html_e( 'Reporter status', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <select id="nppp-f2b-abuse-enabled">
                        <option value="no" <?php selected( $abuse_settings['enabled'], 'no' ); ?>><?php esc_html_e( 'Disabled', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                        <option value="yes" <?php selected( $abuse_settings['enabled'], 'yes' ); ?>><?php esc_html_e( 'Enabled', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                    </select>
                    <p class="nppp-f2b-hint-text"><?php esc_html_e( 'Reports are never sent automatically. Every report is a deliberate click, confirmed in a preview dialog first.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-from-name"><?php esc_html_e( 'Sender name', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="text" id="nppp-f2b-abuse-from-name" value="<?php echo esc_attr( $abuse_settings['from_name'] ); ?>" maxlength="120" />
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-from-email"><?php esc_html_e( 'Sender address', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="email" id="nppp-f2b-abuse-from-email" value="<?php echo esc_attr( $abuse_settings['from_email'] ); ?>" maxlength="190" placeholder="abuse-report@example.com" />
                    <p class="nppp-f2b-hint-text"><?php esc_html_e( 'Use an address on this domain so SPF and DKIM still pass.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-reply-to"><?php esc_html_e( 'Reply-To address', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="email" id="nppp-f2b-abuse-reply-to" value="<?php echo esc_attr( $abuse_settings['reply_to'] ); ?>" maxlength="190" />
                    <p class="nppp-f2b-hint-text"><?php esc_html_e( 'Where the abuse desk replies. A monitored mailbox, not a no-reply alias.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-cc-self"><?php esc_html_e( 'Keep a copy', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <select id="nppp-f2b-abuse-cc-self">
                        <option value="no" <?php selected( $abuse_settings['cc_self'], 'no' ); ?>><?php esc_html_e( 'No', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                        <option value="yes" <?php selected( $abuse_settings['cc_self'], 'yes' ); ?>><?php esc_html_e( 'Cc the Reply-To address', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                    </select>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-org-name"><?php esc_html_e( 'Organisation', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="text" id="nppp-f2b-abuse-org-name" value="<?php echo esc_attr( $abuse_settings['org_name'] ); ?>" maxlength="120" />
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-contact-name"><?php esc_html_e( 'Contact name', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="text" id="nppp-f2b-abuse-contact-name" value="<?php echo esc_attr( $abuse_settings['contact_name'] ); ?>" maxlength="120" />
                    <p class="nppp-f2b-hint-text"><?php esc_html_e( 'Anonymous reports are discarded by most providers.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-contact-phone"><?php esc_html_e( 'Contact phone (optional)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="text" id="nppp-f2b-abuse-contact-phone" value="<?php echo esc_attr( $abuse_settings['contact_phone'] ); ?>" maxlength="60" />
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-min-bans"><?php esc_html_e( 'Minimum bans to report', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="number" id="nppp-f2b-abuse-min-bans" value="<?php echo esc_attr( (string) $abuse_settings['min_bans'] ); ?>" min="1" max="100" step="1" />
                    <p class="nppp-f2b-hint-text"><?php esc_html_e( 'A single ban is usually noise. Three or more is a pattern worth reporting.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-cooldown"><?php esc_html_e( 'Cooldown (days)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <input type="number" id="nppp-f2b-abuse-cooldown" value="<?php echo esc_attr( (string) $abuse_settings['cooldown_days'] ); ?>" min="1" max="365" step="1" />
                    <p class="nppp-f2b-hint-text"><?php esc_html_e( 'The same address cannot be reported again until this many days have passed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                </div>

                <div class="nppp-f2b-row nppp-f2b-abuse-field">
                    <label for="nppp-f2b-abuse-dry-run"><?php esc_html_e( 'Dry run', 'fastcgi-cache-purge-and-preload-nginx' ); ?></label>
                    <select id="nppp-f2b-abuse-dry-run">
                        <option value="no" <?php selected( $abuse_settings['dry_run'], 'no' ); ?>><?php esc_html_e( 'Off — send for real', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                        <option value="yes" <?php selected( $abuse_settings['dry_run'], 'yes' ); ?>><?php esc_html_e( 'On — build but never send', 'fastcgi-cache-purge-and-preload-nginx' ); ?></option>
                    </select>
                </div>

            </div>
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
            <?php if ( ! empty( $stats_change['bans_24h'] ) ) : ?>
                <span class="nppp-f2b-stat-change nppp-f2b-stat-change-<?php echo esc_attr( $stats_change['bans_24h']['dir'] ); ?>">
                    <?php echo esc_html( $stats_change['bans_24h']['label'] ); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="nppp-f2b-stat nppp-f2b-stat-unban">
            <span class="nppp-f2b-stat-num"><?php echo esc_html( (string) $stats['unbans_24h'] ); ?></span>
            <span class="nppp-f2b-stat-label"><?php esc_html_e( 'Unbans / 24h', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <?php if ( ! empty( $stats_change['unbans_24h'] ) ) : ?>
                <span class="nppp-f2b-stat-change nppp-f2b-stat-change-<?php echo esc_attr( $stats_change['unbans_24h']['dir'] ); ?>">
                    <?php echo esc_html( $stats_change['unbans_24h']['label'] ); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="nppp-f2b-stat nppp-f2b-stat-jail">
            <span class="nppp-f2b-stat-num"><?php echo esc_html( (string) $stats['jails'] ); ?></span>
            <span class="nppp-f2b-stat-label"><?php esc_html_e( 'Active jails / 24h', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <?php if ( ! empty( $stats_change['jails'] ) ) : ?>
                <span class="nppp-f2b-stat-change nppp-f2b-stat-change-<?php echo esc_attr( $stats_change['jails']['dir'] ); ?>">
                    <?php echo esc_html( $stats_change['jails']['label'] ); ?>
                </span>
            <?php endif; ?>
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
            <span class="nppp-f2b-stat-sub">
                <span class="nppp-f2b-stat-sub-ban">&#9650; <?php echo esc_html( (string) $stats['window_bans'] ); ?> <?php esc_html_e( 'bans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                &middot;
                <span class="nppp-f2b-stat-sub-unban">&#9660; <?php echo esc_html( (string) $stats['window_unbans'] ); ?> <?php esc_html_e( 'unbans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            </span>
            <?php if ( ! empty( $stats_change['window'] ) ) : ?>
                <span class="nppp-f2b-stat-change nppp-f2b-stat-change-<?php echo esc_attr( $stats_change['window']['dir'] ); ?>">
                    <?php echo esc_html( $stats_change['window']['label'] ); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <h3 class="nppp-f2b-section"><?php esc_html_e( 'Jail Activity — last 24 hours', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
    <?php if ( empty( $summaries ) ) : ?>
        <p class="nppp-f2b-empty"><?php esc_html_e( 'No jail activity in the last 24 hours.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <?php else : ?>
        <?php
        // $summaries already arrives ORDER BY bans DESC from
        // nppp_f2b_get_jail_summaries() — reused as-is for chart ranking,
        // no extra query or re-sort needed.
        $nppp_jail_ban_max = 1;
        foreach ( $summaries as $nppp_scale_row ) {
            $nppp_jail_ban_max = max( $nppp_jail_ban_max, (int) $nppp_scale_row['bans'] );
        }
        ?>
        <div class="nppp-f2b-jail-activity-row">
            <div class="nppp-f2b-jails">
                <?php foreach ( $summaries as $nppp_row ) : ?>
                    <div class="nppp-f2b-jail">
                        <h4><?php echo esc_html( $nppp_row['jail'] ); ?></h4>
                        <div class="nppp-f2b-jail-nums">
                            <span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_row['bans'] ); ?></span>
                            <span class="nppp-f2b-badge-cap"><?php esc_html_e( 'bans', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                            <?php if ( ! empty( $jail_bans_change[ $nppp_row['jail'] ] ) ) : ?>
                                <?php $nppp_jail_change = $jail_bans_change[ $nppp_row['jail'] ]; ?>
                                <span class="nppp-f2b-jail-change nppp-f2b-stat-change-<?php echo esc_attr( $nppp_jail_change['dir'] ); ?>" title="<?php esc_attr_e( 'Change in bans vs the previous 24h', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">
                                    <?php echo esc_html( $nppp_jail_change['label'] ); ?>
                                </span>
                            <?php endif; ?>
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

            <div class="nppp-f2b-jail-chart-wrap">
                <div class="nppp-f2b-jail-chart-panel">
                    <p class="nppp-f2b-jail-chart-title"><?php esc_html_e( 'Bans by jail — ranked', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                    <div class="nppp-f2b-jail-chart-rows">
                        <?php foreach ( $summaries as $nppp_row ) : ?>
                            <?php
                            $nppp_bans_n   = (int) $nppp_row['bans'];
                            $nppp_bans_pct = $nppp_bans_n > 0 ? max( 3, (int) round( ( $nppp_bans_n / $nppp_jail_ban_max ) * 100 ) ) : 0;
                            ?>
                            <div class="nppp-f2b-jail-chart-row">
                                <div class="nppp-f2b-jail-chart-row-head">
                                    <span class="nppp-f2b-jail-chart-name"><?php echo esc_html( $nppp_row['jail'] ); ?></span>
                                    <span class="nppp-f2b-jail-chart-value"><?php echo esc_html( (string) $nppp_bans_n ); ?></span>
                                </div>
                                <div class="nppp-f2b-jail-chart-track">
                                    <div class="nppp-f2b-jail-chart-fill" style="width:<?php echo esc_attr( $nppp_bans_pct ); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $gate_totals ) ) : ?>
        <?php
        $nppp_gate_max = 1;
        foreach ( $gate_totals as $nppp_gate_scale ) {
            $nppp_gate_max = max( $nppp_gate_max, (int) $nppp_gate_scale['hits'] );
        }
        ?>
        <h3 class="nppp-f2b-section"><?php esc_html_e( 'Endpoint Attacks — last 24 hours', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
        <p class="nppp-f2b-hint-text nppp-f2b-gate-hint">
            <?php esc_html_e( 'Requests rejected by this plugin\'s own protection gates (bad or missing tokens and keys). Each IP is logged on its first failed attempt and on every 5th after that, until the gate locks it out, so the counts are a sample, not every request. This is not a firewall block: no IP here has been banned, and no external lookups are made for these IPs.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </p>

        <div class="nppp-f2b-jail-activity-row">
            <div class="nppp-f2b-jails">
                <?php foreach ( $gate_totals as $nppp_gate_row ) : ?>
                    <div class="nppp-f2b-jail">
                        <h4><?php echo esc_html( nppp_f2b_gate_label( (string) $nppp_gate_row['jail'] ) ); ?></h4>
                        <div class="nppp-f2b-jail-nums">
                            <span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_gate_row['hits'] ); ?></span>
                            <span class="nppp-f2b-badge-cap"><?php esc_html_e( 'rejections', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        </div>
                        <p class="nppp-f2b-jail-last">
                            <?php esc_html_e( 'Last event:', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            <?php echo esc_html( nppp_f2b_local_time( (string) $nppp_gate_row['last_seen'] ) ); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="nppp-f2b-jail-chart-wrap">
                <div class="nppp-f2b-jail-chart-panel">
                    <p class="nppp-f2b-jail-chart-title"><?php esc_html_e( 'Rejections by gate — ranked', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
                    <div class="nppp-f2b-jail-chart-rows">
                        <?php foreach ( $gate_totals as $nppp_gate_row ) : ?>
                            <?php
                            $nppp_gate_hits = (int) $nppp_gate_row['hits'];
                            $nppp_gate_pct  = $nppp_gate_hits > 0 ? max( 3, (int) round( ( $nppp_gate_hits / $nppp_gate_max ) * 100 ) ) : 0;
                            ?>
                            <div class="nppp-f2b-jail-chart-row">
                                <div class="nppp-f2b-jail-chart-row-head">
                                    <span class="nppp-f2b-jail-chart-name"><?php echo esc_html( strtoupper( (string) $nppp_gate_row['jail'] ) ); ?></span>
                                    <span class="nppp-f2b-jail-chart-value"><?php echo esc_html( (string) $nppp_gate_hits ); ?></span>
                                </div>
                                <div class="nppp-f2b-jail-chart-track">
                                    <div class="nppp-f2b-jail-chart-fill" style="width:<?php echo esc_attr( (string) $nppp_gate_pct ); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ( ! empty( $gate_summary ) ) : ?>
            <h3 class="nppp-f2b-section"><?php esc_html_e( 'Top source IPs — last 24 hours', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
            <table class="nppp-f2b-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Gate', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'IP Address', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Logged', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <th><?php esc_html_e( 'Last Seen', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $gate_summary as $nppp_gate_hit ) : ?>
                        <tr>
                            <td><?php echo esc_html( nppp_f2b_gate_label( (string) $nppp_gate_hit['jail'] ) ); ?></td>
                            <td><code><?php echo esc_html( (string) $nppp_gate_hit['ip'] ); ?></code></td>
                            <td><span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_gate_hit['hits'] ); ?></span></td>
                            <td><?php echo esc_html( nppp_f2b_local_time( (string) $nppp_gate_hit['last_seen'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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
                <?php if ( $abuse_ready ) : ?>
                    <th class="nppp-f2b-report-col"><?php esc_html_e( 'Abuse', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $recidive ) ) : ?>
                <tr><td colspan="<?php echo $abuse_ready ? '4' : '3'; ?>" class="nppp-f2b-empty-cell"><?php esc_html_e( 'No repeat offenders — that is a good sign.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $recidive as $nppp_row ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $nppp_row['ip'] ); ?></code></td>
                        <td><span class="nppp-f2b-badge nppp-f2b-badge-ban"><?php echo esc_html( (string) (int) $nppp_row['ban_count'] ); ?></span></td>
                        <td><?php echo esc_html( nppp_f2b_local_time( (string) $nppp_row['last_ban'] ) ); ?></td>
                        <?php if ( $abuse_ready ) : ?>
                            <td class="nppp-f2b-report-col">
                                <?php
                                nppp_f2b_render_report_cell(
                                    (string) $nppp_row['ip'],
                                    ! empty( $abuse_contacts[ (string) $nppp_row['ip'] ]['abuse_emails'] ),
                                    (int) $nppp_row['ban_count'],
                                    (int) $abuse_settings['min_bans'],
                                    nppp_f2b_abuse_last_reported( (string) $nppp_row['ip'], $abuse_report_log ),
                                    (int) $abuse_settings['cooldown_days']
                                );
                                ?>
                            </td>
                        <?php endif; ?>
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
            <table id="nppp-f2b-feed-table" class="nppp-f2b-feed-table" data-report-col="<?php echo $abuse_ready ? '1' : '0'; ?>">
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
                        <?php if ( $abuse_ready ) : ?>
                            <th><?php esc_html_e( 'Report', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                        <?php endif; ?>
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

                            <?php if ( $abuse_ready ) : ?>
                                <td class="nppp-f2b-report-col">
                                    <?php if ( 'ban' === $nppp_row['event_type'] ) : ?>
                                        <?php
                                        nppp_f2b_render_report_cell(
                                            (string) $nppp_row['ip'],
                                            ! empty( $nppp_rdap['abuse_emails'] ),
                                            // Per-IP totals are resolved server-side on click; the feed
                                            // row only knows about itself, so never pre-block on count.
                                            (int) $abuse_settings['min_bans'],
                                            (int) $abuse_settings['min_bans'],
                                            nppp_f2b_abuse_last_reported( (string) $nppp_row['ip'], $abuse_report_log ),
                                            (int) $abuse_settings['cooldown_days']
                                        );
                                        ?>
                                    <?php else : ?>
                                        <span class="nppp-f2b-report-na">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
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

    <?php if ( $abuse_ready ) : ?>
        <div id="nppp-f2b-abuse-modal" class="nppp-f2b-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nppp-f2b-abuse-modal-title">
            <div class="nppp-f2b-modal-backdrop"></div>
            <div class="nppp-f2b-modal-box" role="document">
                <div class="nppp-f2b-card-titlebar">
                    <h3 class="nppp-f2b-card-title" id="nppp-f2b-abuse-modal-title"><?php esc_html_e( 'Confirm abuse report', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h3>
                    <div class="nppp-f2b-card-title-actions">
                        <button type="button" class="nppp-f2b-btn nppp-f2b-modal-close" aria-label="<?php esc_attr_e( 'Close', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">
                            <?php esc_html_e( 'Close', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </button>
                    </div>
                </div>
                <div class="nppp-f2b-modal-body" id="nppp-f2b-abuse-modal-body"></div>
                <div class="nppp-f2b-modal-foot">
                    <p class="nppp-f2b-hint-text">
                        <?php esc_html_e( 'The recipient is resolved on the server from stored RDAP data — it is never taken from this page.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </p>
                    <button type="button" class="nppp-f2b-btn nppp-f2b-btn-primary" id="nppp-f2b-abuse-send" data-ip="" disabled>
                        <?php esc_html_e( 'Send Report', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
