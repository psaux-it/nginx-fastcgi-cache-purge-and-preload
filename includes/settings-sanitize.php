<?php
/**
 * Settings sanitization and validation for Nginx Cache Purge Preload
 * Description: Sanitizes and validates all settings inputs; validates the cache path.
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

// Log error messages
function nppp_log_error_message($message) {
    $log_message = esc_html($message);
    $log_file_path = NGINX_CACHE_LOG_FILE;

    // Create log file if not exist
    nppp_perform_file_operation($log_file_path, 'create');

    // Check if the log path is valid and writable
    if (!empty($log_file_path)) {
        nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $log_message);
    }
}

// Prevent command injections
function nppp_single_line(string $s): string {
    $s = str_replace("\0", '', $s);
    $s = str_replace(["\r","\n","\t"], ' ', $s);
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x80-\x9F]/', '', $s);
    $s = trim($s);
    if (strlen($s) > 4000) $s = substr($s, 0, 4000);
    return $s;
}
function nppp_sanitize_reject_regex(string $rx): string {
    return nppp_single_line($rx);
}
function nppp_sanitize_reject_extension_globs(string $s): string {
    $s = nppp_single_line($s);
    $parts = preg_split('/[,\s]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $p = strtolower(trim($p));
        if (preg_match('/^(?:\*\.)?[a-z0-9]+(?:\.[a-z0-9]+)*$/', $p) || preg_match('/^\.[a-z0-9]+(?:\.[a-z0-9]+)*$/', $p)) {
            if ($p[0] === '.')      $p = '*'.$p;
            elseif ($p[0] !== '*')  $p = '*.' . $p;
            $out[$p] = true;
        }
    }
    $out = array_slice(array_keys($out), 0, 200);
    return implode(',', $out);
}
function nppp_forbidden_shell_bytes_reason(string $s): ?string {
    if (strpos($s, "\0") !== false) {
        return __('ERROR OPTION: NUL byte is not allowed. (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    if (preg_match('/[\r\n]/', $s)) {
        return __('ERROR OPTION: Newline characters are not allowed. (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    if (preg_match('/[\x00-\x08\x0B-\x1F\x7F\x80-\x9F]/', $s)) {
        return __('ERROR OPTION: Control characters are not allowed. (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    if (strlen($s) > 4000) {
        return __('ERROR OPTION: Value too long (max 4000 chars). (Reject Regex)', 'fastcgi-cache-purge-and-preload-nginx');
    }
    return null;
}

// Sanitize + validate proxy host input.
function nppp_is_valid_hostname(string $h): bool {
    if ($h === '' || strlen($h) > 253) return false;
    $labels = explode('.', $h);
    foreach ($labels as $lab) {
        $len = strlen($lab);
        if ($len === 0 || $len > 63) return false;
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $lab)) return false;
    }
    return true;
}
function nppp_idn_to_ascii_host(string $host): ?string {
    // If non-ASCII chars present, try converting.
    if (preg_match('/[^\x00-\x7F]/', $host)) {
        if (function_exists('idn_to_ascii')) {
            if (defined('INTL_IDNA_VARIANT_UTS46')) {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            } else {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT);
            }
            if ($ascii === false || $ascii === null) {
                return null;
            }
            return $ascii;
        }
        return null;
    }
    return $host;
}
function nppp_is_valid_hostname_with_reason(string $h, ?string &$reason = null): bool {
    if ($h === '') { $reason = 'empty'; return false; }
    if (strlen($h) > 253) { $reason = 'too_long'; return false; }
    $labels = explode('.', $h);
    foreach ($labels as $lab) {
        $len = strlen($lab);
        if ($len === 0) { $reason = 'empty_label'; return false; }
        if ($len > 63) { $reason = 'label_too_long'; return false; }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $lab)) {
            $reason = 'bad_chars'; return false;
        }
    }
    return true;
}
function nppp_is_ipv4_like_hostname(string $h): bool {
    return (bool) preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $h);
}
function nppp_sanitize_validate_proxy_host(string $raw, ?string &$err = null, ?string &$notice = null): ?string {
    $s = nppp_single_line($raw);

    // Strip scheme if pasted like http://host:port
    $s = preg_replace('#^[a-z][a-z0-9+\-.]*://#i', '', $s);
    $s = trim($s);

    // Reject userinfo, path, query, fragments, spaces
    if (preg_match('/[@\/?#\s]/', $s)) {
        $err = __('ERROR OPTION: Proxy Host must not include credentials, path, query, fragments, or spaces.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // Trailing dot (FQDN.) -> normalize
    $s = rtrim($s, '.');

    // [IPv6] or [IPv6]:port — allow;
    if (preg_match('/^\[([0-9A-Fa-f:.%]+)\](?::(\d{1,5}))?$/', $s, $m)) {
        $ip6_raw = $m[1];
        $port    = $m[2] ?? null;

        $ip6_plain = preg_replace('/%.*/', '', $ip6_raw);
        if (!filter_var($ip6_plain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $err = __('ERROR OPTION: Invalid IPv6 address.', 'fastcgi-cache-purge-and-preload-nginx');
            return null;
        }
        if ($port !== null) {
            $notice = __('NOTICE: Port ignored in Proxy Host. Use the Proxy Port field.', 'fastcgi-cache-purge-and-preload-nginx');
        }
        return '[' . $ip6_raw . ']';
    }

    // host:port (non-IPv6) — allow but ignore port, nudge user
    if (preg_match('/^([^:]+):(\d{1,5})$/', $s, $m) && strpos($m[1], ':') === false) {
        $s = $m[1];
        $notice = __('NOTICE: Port ignored in Proxy Host. Use the Proxy Port field.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Any remaining ":" means it's trying to be IPv6 but failed
    if (strpos($s, ':') !== false) {
        $plain = preg_replace('/%.*/', '', $s);
        if (filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '[' . $s . ']';
        }
        $err = __('ERROR OPTION: Unexpected ":" in host. Use [IPv6] or a valid hostname/IPv4; do not include ports or paths here.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // IPv4?
    if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $s;
    }

    // Looks like IPv4 but invalid?
    if (nppp_is_ipv4_like_hostname($s)) {
        $err = __('ERROR OPTION: Value looks like an IPv4 address but is invalid.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // Normalize case and accept IDNs via Punycode
    $host_input = strtolower($s);
    $host_ascii = nppp_idn_to_ascii_host($host_input);
    if ($host_ascii === null) {
        $err = __('ERROR OPTION: Invalid internationalized domain name.', 'fastcgi-cache-purge-and-preload-nginx');
        return null;
    }

    // Validate hostname with specific reasons
    $why = null;
    if (nppp_is_valid_hostname_with_reason($host_ascii, $why)) {
        return $host_ascii;
    }

    switch ($why) {
        case 'too_long':
            $err = __('ERROR OPTION: Hostname too long (max 253 chars).', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        case 'label_too_long':
            $err = __('ERROR OPTION: A hostname label exceeds 63 characters.', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        case 'empty_label':
            $err = __('ERROR OPTION: Hostname contains empty labels (e.g., consecutive dots).', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        case 'bad_chars':
            $err = __('ERROR OPTION: Hostname contains invalid characters. Use letters, digits, and hyphens; labels must start/end alphanumeric.', 'fastcgi-cache-purge-and-preload-nginx');
            break;
        default:
            $err = __('ERROR OPTION: Please enter a valid IP address or hostname for the Proxy Host.', 'fastcgi-cache-purge-and-preload-nginx');
    }
    return null;
}

// Sanitize inputs
function nppp_nginx_cache_settings_sanitize($input) {
    // Guard against double/triple sanitization — WordPress core bug Trac #21989
    static $pass_count = 0;
    $pass_count++;
    if ( $pass_count > 1 ) return $input;

    $sanitized_input = array();

    // Ensure input is an array
    if (!is_array($input)) {
        return $sanitized_input;
    }

    // Sanitize and validate cache path
    if (!empty($input['nginx_cache_path'])) {
        // Validate the path
        $validation_result = nppp_validate_path($input['nginx_cache_path'], false);

        // Check the validation result
        if ($validation_result === true) {
            $sanitized_input['nginx_cache_path'] = sanitize_text_field($input['nginx_cache_path']);
        } else {
            // Handle different validation outcomes
            switch ($validation_result) {
                case 'critical_path':
                    $error_message = __('ERROR PATH: The specified Nginx Cache Directory is either a critical system directory or a top-level directory and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
                    break;
                case 'directory_not_exist_or_readable':
                    $error_message = __('ERROR PATH: The specified Nginx Cache Directory does not exist. Please verify the Nginx Cache Directory.', 'fastcgi-cache-purge-and-preload-nginx');
                    break;
                default:
                    $error_message = __('ERROR PATH: An invalid path was provided for the Nginx Cache Directory. Please provide a valid directory path.', 'fastcgi-cache-purge-and-preload-nginx');
            }

            // Add settings error
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid_path',
                $error_message,
                'error'
            );

            // Log the error message
            nppp_log_error_message($error_message);
        }
    } else {
        $error_message = __('ERROR PATH: Nginx Cache Directory cannot be empty. Please provide a valid directory path.', 'fastcgi-cache-purge-and-preload-nginx');
        add_settings_error('nppp_nginx_cache_settings_group', 'empty_path', $error_message, 'error');
        nppp_log_error_message($error_message);
    }

    // Sanitize and validate email
    if (!empty($input['nginx_cache_email'])) {
        // Validate email format
        $email = sanitize_email($input['nginx_cache_email']);
        if (is_email($email)) {
            $sanitized_input['nginx_cache_email'] = $email;
        } else {
            // Email is not valid, add error message
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-email',
                __('ERROR OPTION: Please enter a valid email address.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Please enter a valid email address.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize and validate CPU limit
    if (!empty($input['nginx_cache_cpu_limit'])) {
        // Check if the input is numeric to prevent non-numeric strings from passing through
        if (is_numeric($input['nginx_cache_cpu_limit'])) {
            // Convert to integer
            $cpu_limit = intval($input['nginx_cache_cpu_limit']);
            // Validate range
            if ($cpu_limit >= 10 && $cpu_limit <= 100) {
                $sanitized_input['nginx_cache_cpu_limit'] = $cpu_limit;
            } else {
                // CPU limit is not within range, add error message
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-cpu-limit',
                    __('Please enter a CPU limit between 10 and 100.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a CPU limit between 10 and 100.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-cpu-limit-format',
                __('CPU limit must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: CPU limit must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize and validate Per Request Wait Time
    if (isset($input['nginx_cache_wait_request'])) {
        // Check if the input is numeric to prevent non-numeric strings from passing through
        if (is_numeric($input['nginx_cache_wait_request'])) {
            // Convert to integer
            $wait_time = intval($input['nginx_cache_wait_request']);
            // Validate range
            if ($wait_time >= 0 && $wait_time <= 60) {
                $sanitized_input['nginx_cache_wait_request'] = $wait_time;
            } else {
                // Wait Time is not within range, add error message
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-wait-time',
                    __('Please enter a Per Request Wait Time between 0 and 60 seconds.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a per request wait time between 0 and 60 seconds.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-wait-time-format',
                __('Wait time must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Wait time must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize and validate PHP Response Timeout
    if (isset($input['nginx_cache_read_timeout'])) {
        if (is_numeric($input['nginx_cache_read_timeout'])) {
            $read_timeout = intval($input['nginx_cache_read_timeout']);
            if ($read_timeout >= 10 && $read_timeout <= 300) {
                $sanitized_input['nginx_cache_read_timeout'] = $read_timeout;
            } else {
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-read-timeout',
                    __('Please enter a PHP Response Timeout between 10 and 300 seconds.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );
                nppp_log_error_message(__('ERROR OPTION: Please enter a PHP response timeout between 10 and 300 seconds.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-read-timeout-format',
                __('PHP Response Timeout must be a numeric value in seconds.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );
            nppp_log_error_message(__('ERROR OPTION: PHP response timeout must be a numeric value in seconds.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize Reject Regex field
    if (!empty($input['nginx_cache_reject_regex'])) {
        $raw = $input['nginx_cache_reject_regex'];
        if ($reason = nppp_forbidden_shell_bytes_reason($raw)) {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-reject-regex',
                $reason,
                'error'
            );
            nppp_log_error_message($reason);
        } else {
            $sanitized_input['nginx_cache_reject_regex'] = nppp_sanitize_reject_regex($raw);
        }
    }

    // Sanitize & validate custom cache key regex
    if (!empty($input['nginx_cache_key_custom_regex'])) {
        // Decode the base64-encoded regex if it's being passed encoded
        $decoded_regex = base64_decode($input['nginx_cache_key_custom_regex'], true);
        if ($decoded_regex !== false) {
            // Retrieve the decoded regex
            $regex = $decoded_regex;
        } else {
            // If decoding fails, use the input directly
            $regex = $input['nginx_cache_key_custom_regex'];
        }

        // ####################################################################
        // Validate & Sanitize the regex
        // Limit catastrophic backtracking, greedy quantifiers inside lookaheads
        // excessively long regex patterns to prevent ReDoS attacks
        // ####################################################################

        // Validate the regex
        if (@preg_match($regex, "") === false) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex is invalid. Check the syntax and test it before use.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Check for excessive lookaheads (limit to 3)
        $lookahead_count = preg_match_all('/(\(\?=.*\))/i', $regex);
        if ($lookahead_count > 3) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex contains more than 3 lookaheads and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Check for greedy quantifiers inside lookaheads
        if (preg_match('/\(\?=.*\.\*\)/', $regex)) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex contains a greedy quantifier inside a lookahead and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Allow only a single ".*" in the regex
        $greedy_count = preg_match_all('/\.\*/', $regex);
        if ($greedy_count > 1) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex contains more than one ".*" quantifier and cannot be used.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // Check for excessively long regex patterns (limit length to 300 characters)
        if (strlen($regex) > 300) {
            $error_message_regex = __('ERROR REGEX: The custom cache key regex exceeds the allowed length of 300 characters.', 'fastcgi-cache-purge-and-preload-nginx');
        }

        // If an error message was set, trigger the error and log it
        if (isset($error_message_regex)) {
            // Add settings error
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid_regex',
                $error_message_regex,
                'error'
            );
            // Log the error message
            nppp_log_error_message($error_message_regex);
        } else {
            // Sanitization & validation the regex completed, safely store regex in db
            $sanitized_input['nginx_cache_key_custom_regex'] = base64_encode($regex);
        }
    }

    // Sanitize Reject extension field
    if (!empty($input['nginx_cache_reject_extension'])) {
        $raw = nppp_single_line($input['nginx_cache_reject_extension']);

        // Tokenize by comma/whitespace only
        $tokens = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        $bad = [];
        foreach ($tokens as $tok) {
            // Only forms like: *.css  | .css  | css  | css.min.js
            $ok = preg_match('/^(?:\*\.)?[a-z0-9]+(?:\.[a-z0-9]+)*$/i', $tok)
                || preg_match('/^\.[a-z0-9]+(?:\.[a-z0-9]+)*$/i', $tok);
            if (!$ok) $bad[] = $tok;
        }

        if ($bad) {
            $preview = implode(', ', array_slice($bad, 0, 3));
            $msg = sprintf(
                /* translators: %s: short comma-separated preview (max 3) of invalid extension patterns */
                __('ERROR OPTION: Invalid extension pattern(s): %s. Allowed examples: *.css, .css, css', 'fastcgi-cache-purge-and-preload-nginx'),
                esc_html($preview) . (count($bad) > 3 ? '…' : '')
            );
            add_settings_error('nppp_nginx_cache_settings_group', 'invalid-reject-ext', $msg, 'error');
            nppp_log_error_message($msg);
        } else {
            $sanitized_input['nginx_cache_reject_extension'] = nppp_sanitize_reject_extension_globs($raw);
        }
    }

    // Sanitize Send Mail, Auto Preload, Auto Preload Mobile, Auto Purge, Cache Schedule, REST API, Opt-in, Related Pages
    $sanitized_input['nginx_cache_send_mail']              = isset($input['nginx_cache_send_mail'])               && $input['nginx_cache_send_mail'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_auto_preload']           = isset($input['nginx_cache_auto_preload'])            && $input['nginx_cache_auto_preload'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_auto_preload_mobile']    = isset($input['nginx_cache_auto_preload_mobile'])     && $input['nginx_cache_auto_preload_mobile'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_watchdog']               = isset($input['nginx_cache_watchdog'])                && $input['nginx_cache_watchdog'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_purge_on_update']        = isset($input['nginx_cache_purge_on_update'])         && $input['nginx_cache_purge_on_update'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nppp_autopurge_posts']               = ( isset( $input['nppp_autopurge_posts'] )            && $input['nppp_autopurge_posts']    === 'yes' ) ? 'yes' : 'no';
    $sanitized_input['nppp_autopurge_terms']               = ( isset( $input['nppp_autopurge_terms'] )            && $input['nppp_autopurge_terms']    === 'yes' ) ? 'yes' : 'no';
    $sanitized_input['nppp_autopurge_plugins']             = ( isset( $input['nppp_autopurge_plugins'] )          && $input['nppp_autopurge_plugins']  === 'yes' ) ? 'yes' : 'no';
    $sanitized_input['nppp_autopurge_themes']              = ( isset( $input['nppp_autopurge_themes'] )           && $input['nppp_autopurge_themes']   === 'yes' ) ? 'yes' : 'no';
    $sanitized_input['nppp_autopurge_3rdparty']            = ( isset( $input['nppp_autopurge_3rdparty'] )         && $input['nppp_autopurge_3rdparty'] === 'yes' ) ? 'yes' : 'no';
    $sanitized_input['nppp_cloudflare_apo_sync']           = isset($input['nppp_cloudflare_apo_sync'])            && $input['nppp_cloudflare_apo_sync'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nppp_redis_cache_sync']              = isset($input['nppp_redis_cache_sync'])               && $input['nppp_redis_cache_sync'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_schedule']               = isset($input['nginx_cache_schedule'])                && $input['nginx_cache_schedule'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nginx_cache_api']                    = isset($input['nginx_cache_api'])                     && $input['nginx_cache_api'] === 'yes' ? 'yes' : 'no';
    $sanitized_input['nppp_related_include_home']          = (isset($input['nppp_related_include_home'])          && $input['nppp_related_include_home'] === 'yes') ? 'yes' : 'no';
    $sanitized_input['nppp_related_include_category']      = (isset($input['nppp_related_include_category'])      && $input['nppp_related_include_category'] === 'yes') ? 'yes' : 'no';
    $sanitized_input['nppp_related_apply_manual']          = (isset($input['nppp_related_apply_manual'])          && $input['nppp_related_apply_manual'] === 'yes') ? 'yes' : 'no';
    $sanitized_input['nppp_related_preload_after_manual']  = (isset($input['nppp_related_preload_after_manual'])  && $input['nppp_related_preload_after_manual'] === 'yes') ? 'yes' : 'no';

    // Sanitize Mobile User Agent: strip tags, collapse whitespace, hard-cap at 512 chars.
    if ( ! empty( $input['nginx_cache_mobile_user_agent'] ) ) {
        $raw_mobile_ua = sanitize_text_field( wp_unslash( $input['nginx_cache_mobile_user_agent'] ) );
        if ( strlen( $raw_mobile_ua ) > 512 ) {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid_mobile_user_agent',
                __( 'ERROR: Mobile User Agent exceeds the maximum allowed length of 512 characters.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'error'
            );
        } else {
            $sanitized_input['nginx_cache_mobile_user_agent'] = $raw_mobile_ua;
        }
    }

    // HTTP Purge
    // Toggle
    $sanitized_input['nppp_http_purge_enabled'] =
        ( isset( $input['nppp_http_purge_enabled'] ) && $input['nppp_http_purge_enabled'] === 'yes' )
        ? 'yes' : 'no';

    // URL suffix
    $raw_suffix = isset( $input['nppp_http_purge_suffix'] )
                  ? trim( sanitize_text_field( $input['nppp_http_purge_suffix'] ), '/' )
                  : '';
    if ( $raw_suffix === '' ) {
        // Empty = user cleared the field. Reset to default and tell them.
        $sanitized_input['nppp_http_purge_suffix'] = 'purge';
        add_settings_error(
            'nppp_nginx_cache_settings_group',
            'nppp-http-purge-suffix',
            __( 'ERROR OPTION: HTTP Purge Suffix cannot be empty. Reset to "purge".', 'fastcgi-cache-purge-and-preload-nginx' ),
            'error'
        );
    } elseif ( preg_match( '/^[a-zA-Z0-9_\-]+$/', $raw_suffix ) ) {
        $sanitized_input['nppp_http_purge_suffix'] = $raw_suffix;
    } else {
        $sanitized_input['nppp_http_purge_suffix'] = 'purge';
        add_settings_error(
            'nppp_nginx_cache_settings_group',
            'nppp-http-purge-suffix',
            __( 'ERROR OPTION: HTTP Purge Suffix must contain only letters, numbers, hyphens, or underscores. Reset to "purge".', 'fastcgi-cache-purge-and-preload-nginx' ),
            'error'
        );
    }

    // RG Purge
    $sanitized_input['nppp_rg_purge_enabled'] =
        ( isset( $input['nppp_rg_purge_enabled'] ) && $input['nppp_rg_purge_enabled'] === 'yes' )
        ? 'yes' : 'no';

    // Custom base URL
    $raw_custom = isset( $input['nppp_http_purge_custom_url'] )
                  ? untrailingslashit( esc_url_raw( trim( $input['nppp_http_purge_custom_url'] ) ) )
                  : '';
    if ( $raw_custom !== '' ) {
        $scheme = strtolower( (string) wp_parse_url( $raw_custom, PHP_URL_SCHEME ) );
        if ( in_array( $scheme, [ 'http', 'https' ], true ) && filter_var( $raw_custom, FILTER_VALIDATE_URL ) ) {
            $sanitized_input['nppp_http_purge_custom_url'] = $raw_custom;
        } else {
            $sanitized_input['nppp_http_purge_custom_url'] = '';
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'nppp-http-purge-custom-url',
                __( 'ERROR OPTION: HTTP Purge Custom Base URL must be a valid http:// or https:// URL. It has been cleared.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'error'
            );
        }
    } else {
        $sanitized_input['nppp_http_purge_custom_url'] = '';
    }

    // Sanitize bypass path restriction
    $sanitized_input['nginx_cache_bypass_path_restriction'] =
        ( isset( $input['nginx_cache_bypass_path_restriction'] ) && $input['nginx_cache_bypass_path_restriction'] === 'yes' )
        ? 'yes' : 'no';

    // Sanitize pctnorm
    if (!empty($input['nginx_cache_pctnorm_mode']) ) {
        $mode = sanitize_text_field($input['nginx_cache_pctnorm_mode']);
        $allowed = array('off','upper','lower','preserve');
        $sanitized_input['nginx_cache_pctnorm_mode'] = in_array($mode, $allowed, true) ? $mode : 'off';
    }

    // Sanitize and validate cache limit rate
    if (!empty($input['nginx_cache_limit_rate'])) {
        // Check if the input is numeric to prevent non-numeric strings from passing through
        if (is_numeric($input['nginx_cache_limit_rate'])) {
            // Convert to integer
            $limit_rate = intval($input['nginx_cache_limit_rate']);
            // Validate range: 1 KB to 102400 KB (100 MB)
            if ($limit_rate >= 1 && $limit_rate <= 102400) {
                $sanitized_input['nginx_cache_limit_rate'] = $limit_rate;
            } else {
                // Limit rate is not within range, add error message
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-limit-rate',
                    __('Please enter a limit rate between 1 KB/sec and 100 MB/sec.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a limit rate between 1 KB/sec and 100 MB/sec.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-limit-rate-format',
                __('Limit rate must be a numeric value in KB/sec.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Limit rate must be a numeric value in KB/sec.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize REST API Key
    if (!empty($input['nginx_cache_api_key'])) {
        $api_key = $input['nginx_cache_api_key'];

        // Validate if the input is a 32-character hexadecimal string
        if (preg_match('/^[0-9a-fA-F]{64}$/', $api_key)) {
            // Input is a valid 32-character hexadecimal string, sanitize and store it
            $sanitized_input['nginx_cache_api_key'] = $api_key;
        } else {
            // API key is not valid, add error message
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-api-key',
                __('ERROR API KEY: Please enter a valid 64-character hexadecimal string for the API key.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR API KEY: Please enter a valid 64-character hexadecimal string for the API key.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    // Sanitize Enable Proxy
    $sanitized_input['nginx_cache_preload_enable_proxy'] = isset($input['nginx_cache_preload_enable_proxy']) && $input['nginx_cache_preload_enable_proxy'] === 'yes' ? 'yes' : 'no';

    // Sanitize and validate Proxy Host
    if (!empty($input['nginx_cache_preload_proxy_host'])) {
        $notice = $err = null;
        $host = nppp_sanitize_validate_proxy_host($input['nginx_cache_preload_proxy_host'], $err, $notice);

        if ($err) {
            add_settings_error('nppp_nginx_cache_settings_group','invalid-proxy-host',$err,'error');
            nppp_log_error_message($err);
        } else {
            if ($notice) {
                add_settings_error('nppp_nginx_cache_settings_group','proxy-host-port-ignored',$notice,'notice');
                nppp_log_error_message($notice);
            }
            $sanitized_input['nginx_cache_preload_proxy_host'] = $host;
        }
    }

    // Sanitize and validate Proxy Port
    if (!empty($input['nginx_cache_preload_proxy_port'])) {
        // Check if numeric
        if (is_numeric($input['nginx_cache_preload_proxy_port'])) {
            $proxy_port = intval($input['nginx_cache_preload_proxy_port']);
            // Validate valid port range
            if ($proxy_port >= 1 && $proxy_port <= 65535) {
                $sanitized_input['nginx_cache_preload_proxy_port'] = $proxy_port;
            } else {
                add_settings_error(
                    'nppp_nginx_cache_settings_group',
                    'invalid-proxy-port',
                    __('ERROR OPTION: Please enter a valid Proxy Port between 1 and 65535.', 'fastcgi-cache-purge-and-preload-nginx'),
                    'error'
                );

                // Log the error message
                nppp_log_error_message(__('ERROR OPTION: Please enter a valid Proxy Port between 1 and 65535.', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            add_settings_error(
                'nppp_nginx_cache_settings_group',
                'invalid-proxy-port-format',
                __('ERROR OPTION: Proxy Port must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'),
                'error'
            );

            // Log the error message
            nppp_log_error_message(__('ERROR OPTION: Proxy Port must be a numeric value.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    }

    return $sanitized_input;
}

// Validate the cache path to prevent bad inputs
function nppp_validate_path($path, $nppp_is_premium_purge = false) {
    // Whitelist the default placeholder — directory mode only.
    if (!$nppp_is_premium_purge && $path === '/dev/shm/change-me-now') {
        return true;
    }

    // 1. Format check — rejects malformed input before any string ops.
    $pattern = '/^\/(?:[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)*)\/?$/';
    if (!preg_match($pattern, $path)) {
        return 'critical_path';
    }

    // 2. Dotdot check — the regex character class permits dots so '..'
    //    passes the format check. Block traversal sequences explicitly.
    if (strpos($path, '..') !== false) {
        return 'critical_path';
    }

    // 3. Normalise — strip trailing slash before prefix comparisons.
    $normalised = rtrim($path, '/');

    // 4. Allowlist of safe cache roots.
    $allowed_roots = ['/dev/shm/', '/tmp/', '/var/', '/cache/'];

    $allowed = false;
    foreach ($allowed_roots as $root) {
        if (strpos($normalised, $root) === 0) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        return 'critical_path';
    }

    // 5. Blocklist of dangerous subtrees within the allowed roots.
    //    Exact match + trailing slash prevents false positives:
    $blocked_subdirs = [
        '/var/log',
        '/var/spool',
        '/var/lib',
        '/var/www',
        '/var/mail',
        '/var/lock',
        '/var/backups',
        '/var/snap',
    ];

    foreach ($blocked_subdirs as $blocked) {
        if ($normalised === $blocked ||
            strpos($normalised, $blocked . '/') === 0) {
            return 'critical_path';
        }
    }

    // 5b. Block /var/cache root only — subdirectories are allowed.
    //     Using /var/cache directly would wipe all system cache data.
    if ($normalised === '/var/cache' || $normalised === '/var/run' || $normalised === '/cache') {
        return 'critical_path';
    }

    // 6. Existence check — also required before realpath() is safe to call,
    //    since realpath() returns false for non-existent paths.
    if ($nppp_is_premium_purge) {
        if (!is_file($path)) {
            return 'file_not_found_or_not_readable';
        }
    } else {
        if (!is_dir($path)) {
            return 'directory_not_exist_or_readable';
        }
    }

    // 7. Symlink resolution — resolve the real path and re-run allowlist +
    //    blocklist on the destination.
    //    all checks above, since is_dir() follows symlinks silently.
    $resolved = realpath($path);
    if ($resolved === false) {
        return $nppp_is_premium_purge
            ? 'file_not_found_or_not_readable'
            : 'directory_not_exist_or_readable';
    }

    $resolved_normalised = rtrim($resolved, '/');

    $resolved_allowed = false;
    foreach ($allowed_roots as $root) {
        if (strpos($resolved_normalised, $root) === 0) {
            $resolved_allowed = true;
            break;
        }
    }

    if (!$resolved_allowed) {
        return 'critical_path';
    }

    foreach ($blocked_subdirs as $blocked) {
        if ($resolved_normalised === $blocked ||
            strpos($resolved_normalised, $blocked . '/') === 0) {
            return 'critical_path';
        }
    }

    // 5b repeated on resolved path — catches symlinks pointing at /var/cache root
    if ($resolved_normalised === '/var/cache' || $resolved_normalised === '/var/run' || $resolved_normalised === '/cache') {
        return 'critical_path';
    }

    return true;
}
