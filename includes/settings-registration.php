<?php
/**
 * Settings registration for Nginx Cache Purge Preload
 * Description: Registers the settings group, settings section, and all settings fields.
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

// Initializes the Nginx Cache settings by registering settings, adding settings section, and fields
function nppp_nginx_cache_settings_init() {
    // Settings API only matters for the Settings page's full-page POST to
    // options.php — admin-ajax.php fires admin_init too.
    if (wp_doing_ajax()) {
        return;
    }

    // Settings API output here has zero consumers — no do_settings_sections(),
    // no settings_fields(), form posts to admin-post.php not options.php.
    // Restrict the 30+ field registrations to the one page that could ever use them.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection; no state change.
    $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( $current_page !== 'nginx_cache_settings' ) {
        return;
    }

    // Register settings
    register_setting('nppp_nginx_cache_settings_group', 'nginx_cache_settings', 'nppp_nginx_cache_settings_sanitize');

    // Add settings section and fields
    add_settings_section('nppp_nginx_cache_settings_section', 'FastCGI Cache Purge & Preload Settings', 'nppp_nginx_cache_settings_section_callback', 'nppp_nginx_cache_settings_group');
    add_settings_field('nginx_cache_path', 'Nginx FastCGI Cache Path', 'nppp_nginx_cache_path_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_bypass_path_restriction', 'Bypass Path Restriction', 'nppp_nginx_cache_bypass_path_restriction_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_email', 'Email Address', 'nppp_nginx_cache_email_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_cpu_limit', 'CPU Usage Limit for Cache Preloading (10-100)', 'nppp_nginx_cache_cpu_limit_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_reject_regex', 'Excluded endpoints from cache preloading', 'nppp_nginx_cache_reject_regex_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_reject_extension', 'Excluded file extensions from cache preloading', 'nppp_nginx_cache_reject_extension_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_send_mail', 'Send Mail', 'nppp_nginx_cache_send_mail_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_logs', 'Logs', 'nppp_nginx_cache_logs_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_limit_rate', 'Limit Rate Definition', 'nppp_nginx_cache_limit_rate_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_auto_preload', 'Auto Preload', 'nppp_nginx_cache_auto_preload_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_api_key', 'API Key', 'nppp_nginx_cache_api_key_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_api', 'API', 'nppp_nginx_cache_api_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_schedule', 'Scheduled Cache', 'nppp_nginx_cache_schedule_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_purge_on_update', 'Purge Cache on Post/Page Update', 'nppp_nginx_cache_purge_on_update_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_cloudflare_apo_sync', 'Cloudflare APO Sync', 'nppp_nginx_cache_cloudflare_apo_sync_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_redis_cache_sync', 'Redis Object Cache Sync', 'nppp_nginx_cache_redis_cache_sync_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_related_pages', 'Related Pages (single-URL purge only)', 'nppp_nginx_cache_related_pages_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_wait_request', 'Per Request Wait Time', 'nppp_nginx_cache_wait_request_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_read_timeout', 'PHP Response Timeout', 'nppp_nginx_cache_read_timeout_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_key_custom_regex', 'Enable Custom regex', 'nppp_nginx_cache_key_custom_regex_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_auto_preload_mobile', 'Auto Preload Mobile', 'nppp_nginx_cache_auto_preload_mobile_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_feeds', 'Preload Feeds', 'nppp_nginx_cache_preload_feeds_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_mobile_user_agent', 'Mobile User Agent', 'nppp_nginx_cache_mobile_user_agent_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_watchdog', 'Preload Watchdog', 'nppp_nginx_cache_watchdog_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_enable_proxy', 'Enable Proxy', 'nppp_nginx_cache_enable_proxy_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_host', 'Proxy Host', 'nppp_nginx_cache_proxy_host_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_preload_proxy_port', 'Proxy Port', 'nppp_nginx_cache_proxy_port_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nginx_cache_pctnorm_mode', 'Percent-encoding Case', 'nppp_nginx_cache_pctnorm_mode_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_enabled', 'HTTP Purge', 'nppp_http_purge_enabled_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_suffix', 'Purge Single Path', 'nppp_http_purge_suffix_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_custom_url', 'Purge Custom Base URL', 'nppp_http_purge_custom_url_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_http_purge_all_path', 'Purge All Path', 'nppp_http_purge_all_path_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
    add_settings_field('nppp_rg_purge_enabled', 'RG Purge', 'nppp_rg_purge_enabled_callback', 'nppp_nginx_cache_settings_group', 'nppp_nginx_cache_settings_section');
}

// Add settings page
function nppp_add_nginx_cache_settings_page() {
    add_submenu_page(
        'options-general.php',
        'Nginx Cache',
        'Nginx Cache Purge Preload',
        'manage_options',
        'nginx_cache_settings',
        'nppp_nginx_cache_settings_page'
    );
}

// Setup mode
function nppp_is_assume_nginx_mode(): bool {
    // wp-config.php hard override
    if (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX) {
        return true;
    }

    // Runtime option set by Setup
    if ( (bool) get_option('nppp_assume_nginx_runtime', false) ) {
        return true;
    }

    return false;
}

/**
 * Finds the byte offsets of the top-level "|" separators of a reject regex.
 *
 * Skips escaped characters, bracket expressions (incl. POSIX classes such as
 * [:alpha:]) and anything inside (...) groups. Returns null when the string is
 * not balanced.
 *
 * $bs_in_bracket selects how a backslash inside [...] is read: PCRE treats it
 * as an escape, POSIX ERE (wget's default --regex-type) as a literal.
 *
 * @param string $rx
 * @param bool   $bs_in_bracket
 * @return int[]|null
 */
function nppp_scan_top_level_pipes( string $rx, bool $bs_in_bracket ): ?array {
    $len   = strlen( $rx );
    $depth = 0;
    $pipes = [];

    for ( $i = 0; $i < $len; $i++ ) {
        $c = $rx[ $i ];

        if ( '\\' === $c ) {
            $i++;
            continue;
        }

        if ( '[' === $c ) {
            $j = $i + 1;
            if ( $j < $len && '^' === $rx[ $j ] ) {
                $j++;
            }
            if ( $j < $len && ']' === $rx[ $j ] ) {
                $j++;
            }
            while ( $j < $len && ']' !== $rx[ $j ] ) {
                if ( $bs_in_bracket && '\\' === $rx[ $j ] ) {
                    $j += 2;
                    continue;
                }
                if ( '[' === $rx[ $j ] && $j + 1 < $len && false !== strpos( ':.=', $rx[ $j + 1 ] ) ) {
                    $end = strpos( $rx, $rx[ $j + 1 ] . ']', $j + 2 );
                    if ( false === $end ) {
                        return null;
                    }
                    $j = $end + 2;
                    continue;
                }
                $j++;
            }
            if ( $j >= $len ) {
                return null;
            }
            $i = $j;
            continue;
        }

        if ( '(' === $c ) {
            $depth++;
        } elseif ( ')' === $c ) {
            if ( --$depth < 0 ) {
                return null;
            }
        } elseif ( '|' === $c && 0 === $depth ) {
            $pipes[] = $i;
        }
    }

    return 0 === $depth ? $pipes : null;
}

/**
 * Splits a reject regex into its top-level alternatives.
 *
 * Returns null when the split is not certain: unbalanced input, constructs that
 * change how "|" is read (\Q..\E, (?#..)), or a POSIX ERE reading (wget default)
 * and a PCRE reading that disagree.
 *
 * @param string $rx
 * @return string[]|null
 */
function nppp_reject_regex_alternatives( string $rx ): ?array {
    if ( false !== strpos( $rx, '\\Q' ) || false !== strpos( $rx, '(?#' ) ) {
        return null;
    }

    $posix = nppp_scan_top_level_pipes( $rx, false );
    $pcre  = nppp_scan_top_level_pipes( $rx, true );
    if ( null === $posix || $posix !== $pcre ) {
        return null;
    }

    $alts = [];
    $prev = 0;
    foreach ( $posix as $pipe ) {
        $alts[] = substr( $rx, $prev, $pipe - $prev );
        $prev   = $pipe + 1;
    }
    $alts[] = substr( $rx, $prev );

    return $alts;
}

/**
 * Removes the plugin-generated feed exclusions ("/feed/" and "[?&]feed=").
 *
 * A token is removed only when it is a complete top-level alternative. Text the
 * user wrote (e.g. "^/feed/private/", "(a|/feed/)", "a\|") is kept byte for
 * byte. If the regex cannot be split with certainty it is returned unchanged.
 *
 * @param string $rx Reject regex.
 * @return string
 */
function nppp_strip_generated_feed_tokens( string $rx ): string {
    $alts = nppp_reject_regex_alternatives( $rx );
    if ( null === $alts ) {
        return $rx;
    }

    $kept = array_values( array_diff( $alts, [ '/feed/', '[?&]feed=' ] ) );

    return count( $kept ) === count( $alts ) ? $rx : implode( '|', $kept );
}

/**
 * Appends the plugin-generated feed exclusions when they are not already
 * present as complete top-level alternatives. Existing text is never modified.
 *
 * A pattern that merely contains "feed" (e.g. "/podcast/feed/", "feedburner")
 * does not count as the generated token. If the regex cannot be split with
 * certainty, the historical substring check is used instead.
 *
 * @param string $rx Reject regex.
 * @return string
 */
function nppp_add_generated_feed_tokens( string $rx ): string {
    $alts = nppp_reject_regex_alternatives( $rx );

    if ( null === $alts ) {
        if ( strpos( $rx, '/feed/' ) === false ) {
            $rx .= '|/feed/';
        }
        if ( strpos( $rx, 'feed=' ) === false ) {
            $rx .= '|[?&]feed=';
        }
        return $rx;
    }

    foreach ( [ '/feed/', '[?&]feed=' ] as $token ) {
        if ( in_array( $token, $alts, true ) ) {
            continue;
        }
        $candidate = ( '' === $rx ) ? $token : $rx . '|' . $token;
        $check     = nppp_reject_regex_alternatives( $candidate );
        if ( null !== $check && in_array( $token, $check, true ) ) {
            $rx   = $candidate;
            $alts = $check;
        }
    }

    return $rx;
}

/**
 * Fires before every update_option( 'nginx_cache_settings', ... ) call,
 * an AJAX, and WP-CLI handler. Flushes state that depends on the cache path
 * or cache key regex when either value changes.
 *
 * Covers the eight direct update_option() calls in settings-ajax.php that
 * bypass nppp_nginx_cache_settings_sanitize() and would otherwise leave the
 * URL index and regex probe transient stale.
 *
 * @param mixed $new_value  The new option value about to be saved.
 * @param mixed $old_value  The current stored option value.
 * @return mixed            $new_value unchanged.
 */
function nppp_before_settings_option_update( $new_value, $old_value ) {
    if ( ! is_array( $new_value ) || ! is_array( $old_value ) ) {
        return $new_value;
    }

    $old_path = isset( $old_value['nginx_cache_path'] )
        ? rtrim( $old_value['nginx_cache_path'], '/' )
        : '';
    $new_path = isset( $new_value['nginx_cache_path'] )
        ? rtrim( $new_value['nginx_cache_path'], '/' )
        : '';

    if ( $old_path !== '' && $new_path !== '' && $old_path !== $new_path ) {
        delete_option( 'nppp_url_filepath_index' );
        nppp_display_admin_notice(
            'info',
            sprintf(
                /* translators: 1: old cache path 2: new cache path */
                __( 'INFO INDEX CLEARED: URL→filepath index flushed — cache path changed from %1$s to %2$s (pre-update option filter).', 'fastcgi-cache-purge-and-preload-nginx' ),
                $old_path,
                $new_path
            ),
            true,
            false
        );
        $static_key_base = 'nppp';
        $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
        delete_transient($transient_key_permissions_check);
        delete_transient( 'nppp_cache_key_regex_probe' );
    }

    $old_regex = $old_value['nginx_cache_key_custom_regex'] ?? '';
    $new_regex = $new_value['nginx_cache_key_custom_regex'] ?? '';
    if ( $old_regex !== $new_regex ) {
        delete_transient( 'nppp_cache_key_regex_probe' );
    }

    // Enforce feed exclusion rules in reject_regex whenever:
    //   (a) the preload_feeds toggle itself changed, OR
    //   (b) reject_regex was edited while preload_feeds stayed the same.
    $preload_feeds_changed = isset( $new_value['nginx_cache_preload_feeds'], $old_value['nginx_cache_preload_feeds'] )
        && $new_value['nginx_cache_preload_feeds'] !== $old_value['nginx_cache_preload_feeds'];

    $reject_regex_changed = ( $old_value['nginx_cache_reject_regex'] ?? '' )
        !== ( $new_value['nginx_cache_reject_regex'] ?? '' );

    if ( $preload_feeds_changed || $reject_regex_changed ) {
        $reject_regex  = $new_value['nginx_cache_reject_regex'] ?? nppp_fetch_default_reject_regex();
        $feeds_enabled = ( $new_value['nginx_cache_preload_feeds'] ?? 'no' ) === 'yes';

        if ( $feeds_enabled ) {
            // Remove only the generated feed exclusions (complete top-level
            // alternatives). User-written patterns are never modified.
            $reject_regex = nppp_strip_generated_feed_tokens( $reject_regex );
        } else {
            // Add the generated feed exclusions unless already present as
            // complete alternatives. Existing text is left untouched.
            $reject_regex = nppp_add_generated_feed_tokens( $reject_regex );
        }

        $new_value['nginx_cache_reject_regex'] = $reject_regex;
    }

    return $new_value;
}

/**
 * Flushes the URL→filepath index and cache-key regex probe transient when
 * the WordPress permalink structure changes.
 *
 * Nginx derives cache file paths from an MD5 of the full cache key string
 * ($scheme$request_method$host$request_uri). A permalink structure change
 * modifies $request_uri for every post, producing entirely new MD5 hashes
 * and filesystem paths. Without this flush, FP2 finds old-path entries that
 * pass the prefix check (any_prefix_match=true) but whose files have been
 * evicted by nginx (any_valid=false), concludes "confirmed miss", removes
 * the URL from pending, and never reaches FP3/FP4 — leaving new-permalink
 * cached content permanently unpurged.
 *
 * @param string $old_permalink_structure  Previous permalink format string.
 * @param string $new_permalink_structure  Newly saved permalink format string.
 */
function nppp_on_permalink_structure_changed( string $old_permalink_structure, string $new_permalink_structure ): void {
    if ( $old_permalink_structure === $new_permalink_structure ) {
        return;
    }

    delete_option( 'nppp_url_filepath_index' );
    nppp_display_admin_notice(
        'info',
        sprintf(
            /* translators: 1: old permalink structure 2: new permalink structure */
            __( 'INFO INDEX CLEARED: URL→filepath index flushed — permalink structure changed from %1$s to %2$s. Cache entries have new filesystem paths.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $old_permalink_structure !== '' ? $old_permalink_structure : __( '(plain)', 'fastcgi-cache-purge-and-preload-nginx' ),
            $new_permalink_structure !== '' ? $new_permalink_structure : __( '(plain)', 'fastcgi-cache-purge-and-preload-nginx' )
        ),
        true,
        false
    );
}
