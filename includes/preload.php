<?php
/**
 * Preload action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains preload action functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.2
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get proxy options
function nppp_get_proxy_settings() {
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $proxy_host = isset($nginx_cache_settings['nginx_cache_preload_proxy_host']) && !empty($nginx_cache_settings['nginx_cache_preload_proxy_host'])
        ? $nginx_cache_settings['nginx_cache_preload_proxy_host']
        : '127.0.0.1';
    $proxy_port = isset($nginx_cache_settings['nginx_cache_preload_proxy_port']) && !empty($nginx_cache_settings['nginx_cache_preload_proxy_port'])
        ? $nginx_cache_settings['nginx_cache_preload_proxy_port']
        : 3434;

    $use_proxy = isset($nginx_cache_settings['nginx_cache_preload_enable_proxy']) && $nginx_cache_settings['nginx_cache_preload_enable_proxy'] === 'yes'
        ? 'yes'
        : 'no';
    $http_proxy = "http://{$proxy_host}:{$proxy_port}";

    return array(
        'use_proxy'  => $use_proxy,
        'http_proxy' => $http_proxy,
    );
}

// Test DNS resolution on WP server
function nppp_check_network_env(): array {
    $test_domain = 'google.com';

    // Check DNS
    $dns_ok = checkdnsrr($test_domain, 'A') || checkdnsrr($test_domain, 'AAAA');

    // Check outbound connectivity
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
    $outbound_ok = @fsockopen($test_domain, 80, $errno, $errstr, 2) !== false;

    return [
        'dns_ok'      => $dns_ok,
        'outbound_ok' => $outbound_ok,
    ];
}

function nppp_is_proxy_reachable(string $proxy_host, int $proxy_port, int $timeout = 1): array {
    $env = nppp_check_network_env();

    if (!$env['dns_ok']) {
        return [
            'success' => false,
            'code'    => 'dns_error',
        ];
    }

    if (!$env['outbound_ok']) {
        return [
            'success' => false,
            'code'    => 'network_error',
        ];
    }

    // Resolve IP
    if (filter_var($proxy_host, FILTER_VALIDATE_IP)) {
        $resolved_ip = $proxy_host;
    } else {
        $resolved_ip = gethostbyname($proxy_host);
        if ($resolved_ip === $proxy_host) {
            return [
                'success' => false,
                'code'    => 'proxy_dns_fail',
            ];
        }
    }

    // Attempt connection
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
    $connection = @fsockopen($resolved_ip, $proxy_port, $errno, $errstr, $timeout);
    if ($connection) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($connection);
        return [
            'success' => true,
            'code'    => 'ok',
        ];
    }

    return [
        'success' => false,
        'code'    => 'proxy_unreachable',
    ];
}

// Check if a preload process fails immediately—too fast.
// Instead of relying on tools like 'ps' to detect it, we need to use 'proc_get_status'
// to get PID and exit status to determine if the process has already ended unexpectedly.
// Unfortunately, without 'proc_open', it's nearly impossible to detect premature
// shell processes in PHP using traditional techniques.
function nppp_detect_premature_process(
    string $fdomain,
    string $tmp_path,
    int $nginx_cache_limit_rate,
    int $nginx_cache_wait,
    string $nginx_cache_reject_regex,
    string $nginx_cache_reject_extension,
    string $NPPP_DYNAMIC_USER_AGENT
) {
    $test_process = false;

    // Get proxy options
    $proxy_settings = nppp_get_proxy_settings();
    $use_proxy  = $proxy_settings['use_proxy'];
    $http_proxy = $proxy_settings['http_proxy'];
    $https_proxy = $http_proxy;

    // Check proxy is live
    if ($use_proxy === 'yes') {
        // Parse proxy IP and Port
        $parsed_url = wp_parse_url($http_proxy);
        $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
        $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

        // Use proxy health check
        $proxy_status = nppp_is_proxy_reachable($proxy_host, $proxy_port);

        if (!$proxy_status['success']) {
            // Return a specific code to handle later
            return $proxy_status['code'];
        }
    }

    $testCommand = "wget --quiet --recursive --no-cache --no-cookies --no-directories --delete-after " .
                   "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                   "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                   "-e use_proxy=$use_proxy " .
                   "-e http_proxy=$http_proxy " .
                   "-e https_proxy=$https_proxy " .
                   "-P \"$tmp_path\" " .
                   "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                   "--wait=$nginx_cache_wait " .
                   "--reject-regex='\"$nginx_cache_reject_regex\"' " .
                   "--reject='\"$nginx_cache_reject_extension\"' " .
                   "--user-agent='\"". $NPPP_DYNAMIC_USER_AGENT ."\"' " .
                   "\"$fdomain\" ";

    // Redirect all I/O to /dev/null so wget can never block on pipes
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];

    // Start the testprocess
    $process = proc_open($testCommand, $descriptors, $pipes);

    // Verify that the process was successfully created
    if (is_resource($process)) {
        // Sleep for 100ms to allow process to initialize/stabilize
        usleep(300000);

        // Lets status check
        $status   = proc_get_status($process);
        $test_pid = $status['pid'];
        $running  = $status['running'];

        // If process not running after 100ms;
        if (!$running) {
            $exitCode  = $status['exitcode'];

            // Test exit code
            if ($exitCode === 0) {
                $test_process = true;
            } else {
                $test_process = false;
            }
        } else {
            // Test process is live; terminate it
            if (!defined('SIGTERM')) {
                define('SIGTERM', 15);
            }

            if (!@posix_kill($test_pid, SIGTERM)) {
                $kill_path = trim(shell_exec('command -v kill'));
                shell_exec(escapeshellcmd("$kill_path -9 $test_pid"));
            }
            $test_process = true;
        }
        proc_close($process);
    } else {
        $test_process = false;
    }

    return $test_process;
}

// Preload operation
function nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nppp_is_auto_preload = false, $nppp_is_rest_api = false, $nppp_is_wp_cron = false, $nppp_is_admin_bar = false, $preload_mobile = false) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Check if there is an ongoing preload process active
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            nppp_display_admin_notice('info', __( 'INFO: Nginx cache preloading is already running. If you want to stop it, please use Purge All!', 'fastcgi-cache-purge-and-preload-nginx' ));
            return;
        }
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_wait_time = 1;
    $default_reject_extension = nppp_fetch_default_reject_extension();
    $nginx_cache_reject_extension = isset($nginx_cache_settings['nginx_cache_reject_extension']) ? $nginx_cache_settings['nginx_cache_reject_extension'] : $default_reject_extension;
    $nginx_cache_wait = isset($nginx_cache_settings['nginx_cache_wait_request']) ? $nginx_cache_settings['nginx_cache_wait_request'] : $default_wait_time;

    // Determine which USER_AGENT to use
    // Check Preload Mobile is enabled
    if ($preload_mobile) {
        // Use the mobile user agent
        $NPPP_DYNAMIC_USER_AGENT = NPPP_USER_AGENT_MOBILE;
    } else {
        // Use the desktop user agent
        $NPPP_DYNAMIC_USER_AGENT = NPPP_USER_AGENT;
    }

    // Get proxy options
    $proxy_settings = nppp_get_proxy_settings();
    $use_proxy  = $proxy_settings['use_proxy'];
    $http_proxy = $proxy_settings['http_proxy'];
    $https_proxy = $http_proxy;

    // Create domain allowlist
    $parsed = wp_parse_url($fdomain);
    $host = $parsed['host'];
    $base_host = preg_replace('/^www\./i', '', $host);
    $www_host  = 'www.' . $base_host;
    $domain_list = implode(',', array_unique([$base_host, $www_host]));

    // Here, we check the source of the preload request. There are several possible routes.
    // If nppp_is_auto_preload is false, it means we arrived here through one of the following routes:
    // Preload (settings page), Preload (admin bar), Preload (CRON), or Preload (REST API).
    // In this case, we first purge the cache before starting the preload action, as the flag is false.

    // However, if Preload Mobile is enabled, we need to warm the cache for both desktop and mobile,
    // so we do not purge the cache before preloading.
    // Keep in mind that the auto-preload feature is triggered only by the purge action itself, not by the preload action.
    if (!$nppp_is_auto_preload) {
        // Purge cache according to Preload Mobile
        if (!$preload_mobile) {
            $status = nppp_purge_helper($nginx_cache_path, $tmp_path);
        } else {
            $status = 0;
        }

        // Handle different status codes
        if ($status === 0 || $status === 2) {
            // Create PID file
            if (!$wp_filesystem->exists($PIDFILE)) {
                if (!nppp_perform_file_operation($PIDFILE, 'create')) {
                    nppp_display_admin_notice('error', __( 'FATAL ERROR: Failed to create PID file.', 'fastcgi-cache-purge-and-preload-nginx' ));
                    return;
                }
            }

            // Check cpulimit command exist
            $cpulimitPath = shell_exec('type cpulimit');

            if (!empty(trim($cpulimitPath))) {
                $cpulimit = 1;
            } else {
                $cpulimit = 0;
            }

            // Test and detect premature process
            if (function_exists('proc_open') && is_callable('proc_open')) {
                $test_result = nppp_detect_premature_process(
                    $fdomain,
                    $tmp_path,
                    $nginx_cache_limit_rate,
                    $nginx_cache_wait,
                    $nginx_cache_reject_regex,
                    $nginx_cache_reject_extension,
                    $NPPP_DYNAMIC_USER_AGENT
                );


                if (in_array($test_result, ['dns_error', 'network_error', 'proxy_dns_fail', 'proxy_unreachable'], true)) {
                    $parsed_url = wp_parse_url($http_proxy);
                    $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
                    $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

                    switch ($test_result) {
                        case 'dns_error':
                            nppp_display_admin_notice('error', __('ERROR DNS: DNS resolution failed inside the WordPress container. Please check your container network/DNS settings.', 'fastcgi-cache-purge-and-preload-nginx'));
                            break;

                        case 'network_error':
                            nppp_display_admin_notice('error', __('ERROR NETWORK: Outbound network connection failed from WordPress container. Ensure firewall/Docker network settings allow outbound access.', 'fastcgi-cache-purge-and-preload-nginx'));
                            break;

                        case 'proxy_dns_fail':
                            // Translators: %s = proxy host
                            nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: The proxy hostname "%s" could not be resolved to an IP address.', 'fastcgi-cache-purge-and-preload-nginx'), $proxy_host));
                            break;

                        case 'proxy_unreachable':
                        default:
                            // Translators: %s = domain name, %s = proxy host, %d = proxy port
                            nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: Preloading failed for %1$s. Proxy is enabled, but %2$s:%3$d is not responding. Check your proxy or disable proxy mode.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain, $proxy_host, $proxy_port));
                            break;
                    }
                    return;
                } elseif ($test_result === false) {
                    // Translators: %s = domain name
                    nppp_display_admin_notice('error', sprintf(__('ERROR COMMAND: Preloading failed for %s. Please check Exclude Endpoints and Exclude File Extensions settings syntax.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain));
                    return;
                } elseif ($test_result !== true) {
                    // Translators: %s = domain name
                    nppp_display_admin_notice('error', sprintf(__('ERROR UNKNOWN: Preloading failed for %s. Unknown error occurred.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain));
                }
            } else {
                if ($use_proxy === 'yes') {
                    // Parse proxy IP and Port
                    $parsed_url = wp_parse_url($http_proxy);
                    $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
                    $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

                    // Use proxy health check
                    $proxy_status = nppp_is_proxy_reachable($proxy_host, $proxy_port);

                    if (!$proxy_status['success']) {
                        // Show admin notice based on error code
                        switch ($proxy_status['code']) {
                            case 'dns_error':
                                nppp_display_admin_notice('error', __('ERROR DNS: DNS resolution failed inside the WordPress container. Please check your container network/DNS settings.', 'fastcgi-cache-purge-and-preload-nginx'));
                                break;

                            case 'network_error':
                                nppp_display_admin_notice('error', __('ERROR NETWORK: Outbound network connection failed from WordPress container. Ensure firewall/Docker network settings allow outbound access.', 'fastcgi-cache-purge-and-preload-nginx'));
                                break;

                            case 'proxy_dns_fail':
                                // Translators: %s = proxy host
                                nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: The proxy hostname "%s" could not be resolved to an IP address.', 'fastcgi-cache-purge-and-preload-nginx'), $proxy_host));
                                break;

                            case 'proxy_unreachable':
                            default:
                                // Translators: %s = domain name, %s = proxy host, %d = proxy port
                                nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: Preloading failed for %1$s. Proxy is enabled, but %2$s:%3$d is not responding. Check your proxy or disable proxy mode.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain, $proxy_host, $proxy_port));
                                break;
                        }
                        return;
                    }
                }
            }

            // Start cache preloading for whole website (Preload All)
            // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
            // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
            //    speeding up cache preloading via reducing latency we use --no-check-certificate .
            //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
            $command = "nohup wget --quiet --recursive --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-e use_proxy=$use_proxy " .
                "-e http_proxy=$http_proxy " .
                "-e https_proxy=$https_proxy " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--wait=$nginx_cache_wait " .
                "--reject-regex='\"$nginx_cache_reject_regex\"' " .
                "--reject='\"$nginx_cache_reject_extension\"' " .
                "--domains=$domain_list " .
                "--user-agent='\"". $NPPP_DYNAMIC_USER_AGENT ."\"' " .
                "\"$fdomain\" >/dev/null 2>&1 & echo \$!";

            // We are ready to call main command
            $output = shell_exec($command);

            // Get the process ID
            $parts = explode(" ", $output);
            $pid = end($parts);
            nppp_perform_file_operation($PIDFILE, 'write', $pid);

            // Create a DateTime object for the current time in WordPress timezone
            $wordpress_timezone = new DateTimeZone(wp_timezone_string());
            $current_time = new DateTime('now', $wordpress_timezone);

            // Format the current time as the start time for the scheduled event
            $start_time = $current_time->format('H:i:s');

            // Call the function to schedule the status check event
            if (!$preload_mobile) {
                nppp_create_scheduled_event_preload_status($start_time);
            }

            // Start cpulimit if it is exist
            if ($cpulimit === 1) {
                $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" -zb >/dev/null 2>&1";
                shell_exec($command);
            }

            // Define a default success message
            $default_success_message = __( 'SUCCESS: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' );

            // Check the status of $nppp_is_rest_api and display success message accordingly
            if (is_bool($nppp_is_rest_api) && $nppp_is_rest_api) {
                if (!$preload_mobile) {
                    nppp_display_admin_notice('success', __( 'SUCCESS REST: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                } else {
                    nppp_display_admin_notice('success', __( 'SUCCESS REST: Nginx cache preloading has started for Mobile in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                }
            }

            // Check the status of $nppp_is_wp_cron and display success message accordingly
            if (is_bool($nppp_is_wp_cron) && $nppp_is_wp_cron) {
                if (!$preload_mobile) {
                    nppp_display_admin_notice('success', __( 'SUCCESS CRON: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                } else {
                    nppp_display_admin_notice('success', __( 'SUCCESS CRON: Nginx cache preloading has started for Mobile in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                }
            }

            // Check the status of $nppp_is_admin_bar and display success message accordingly
            if (is_bool($nppp_is_admin_bar) && $nppp_is_admin_bar) {
                if (!$preload_mobile) {
                    nppp_display_admin_notice('success', __( 'SUCCESS ADMIN: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                } else {
                    nppp_display_admin_notice('success', __( 'SUCCESS ADMIN: Nginx cache preloading has started for Mobile in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                }
            }

            // If none of the specific conditions were met, display the default success message
            if (!($nppp_is_rest_api || $nppp_is_wp_cron || $nppp_is_admin_bar)) {
                if (!$preload_mobile) {
                    nppp_display_admin_notice('success', $default_success_message);
                } else {
                    nppp_display_admin_notice('success', __( 'SUCCESS: Nginx cache preloading has started for Mobile in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
                }
            }
        } elseif ($status === 1) {
            nppp_display_admin_notice('error', __( 'ERROR PERMISSION: Cannot Purge Nginx cache to start cache Preloading. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ));
        } elseif ($status === 3) {
            // Translators: %s is the Nginx cache path
            nppp_display_admin_notice('error', sprintf( __( 'ERROR PATH: Nginx cache path (%s) was not found. Please check your cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path ));
        } else {
            nppp_display_admin_notice('error', __( 'ERROR UNKNOWN: An unexpected error occurred while Preloading the Nginx cache. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ));
        }
    // Here preload request comes from Purge(Settings Page) or Purge(Admin Bar)
    // As mentioned before auto preload feature only triggers with purge action itself
    // Thats means here purge action already triggered directly and cache purged,
    // so we just start the preload action without purge again.
    // Also we use deferred messages here.
    } else {
        // Create PID file
        if (!$wp_filesystem->exists($PIDFILE)) {
            if (!nppp_perform_file_operation($PIDFILE, 'create')) {
                nppp_display_admin_notice('error', __( 'FATAL ERROR: Failed to create PID file.', 'fastcgi-cache-purge-and-preload-nginx' ));
                return;
            }
        }

        // Check cpulimit command exist
        $cpulimitPath = shell_exec('type cpulimit');
        if (!empty(trim($cpulimitPath))) {
            $cpulimit = 1;
        } else {
            $cpulimit = 0;
        }

        // Test and detect premature process
        if (function_exists('proc_open') && is_callable('proc_open')) {
            $test_result = nppp_detect_premature_process(
                $fdomain,
                $tmp_path,
                $nginx_cache_limit_rate,
                $nginx_cache_wait,
                $nginx_cache_reject_regex,
                $nginx_cache_reject_extension,
                $NPPP_DYNAMIC_USER_AGENT
            );

            if (in_array($test_result, ['dns_error', 'network_error', 'proxy_dns_fail', 'proxy_unreachable'], true)) {
                $parsed_url = wp_parse_url($http_proxy);
                $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
                $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

                switch ($test_result) {
                    case 'dns_error':
                        nppp_display_admin_notice('error', __('ERROR DNS: DNS resolution failed inside the WordPress container. Please check your container network/DNS settings.', 'fastcgi-cache-purge-and-preload-nginx'));
                        break;

                    case 'network_error':
                        nppp_display_admin_notice('error', __('ERROR NETWORK: Outbound network connection failed from WordPress container. Ensure firewall/Docker network settings allow outbound access.', 'fastcgi-cache-purge-and-preload-nginx'));
                        break;

                    case 'proxy_dns_fail':
                        // Translators: %s = proxy host
                        nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: The proxy hostname "%s" could not be resolved to an IP address.', 'fastcgi-cache-purge-and-preload-nginx'), $proxy_host));
                        break;

                    case 'proxy_unreachable':
                    default:
                        // Translators: %s = domain name, %s = proxy host, %d = proxy port
                        nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: Preloading failed for %1$s. Proxy is enabled, but %2$s:%3$d is not responding. Check your proxy or disable proxy mode.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain, $proxy_host, $proxy_port));
                        break;
                }
                return;
            } elseif ($test_result === false) {
                // Translators: %s = domain name
                nppp_display_admin_notice('error', sprintf(__('ERROR COMMAND: Preloading failed for %s. Please check Exclude Endpoints and Exclude File Extensions settings syntax.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain));
                return;
            } elseif ($test_result !== true) {
                // Translators: %s = domain name
                nppp_display_admin_notice('error', sprintf(__('ERROR UNKNOWN: Preloading failed for %s. Unknown error occurred.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain));
            }
        } else {
            if ($use_proxy === 'yes') {
                // Parse proxy IP and Port
                $parsed_url = wp_parse_url($http_proxy);
                $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
                $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

                // Use proxy health check
                $proxy_status = nppp_is_proxy_reachable($proxy_host, $proxy_port);

                if (!$proxy_status['success']) {
                    // Show admin notice based on error code
                    switch ($proxy_status['code']) {
                        case 'dns_error':
                            nppp_display_admin_notice('error', __('ERROR DNS: DNS resolution failed inside the WordPress container. Please check your container network/DNS settings.', 'fastcgi-cache-purge-and-preload-nginx'));
                            break;

                        case 'network_error':
                            nppp_display_admin_notice('error', __('ERROR NETWORK: Outbound network connection failed from WordPress container. Ensure firewall/Docker network settings allow outbound access.', 'fastcgi-cache-purge-and-preload-nginx'));
                            break;

                        case 'proxy_dns_fail':
                            // Translators: %s = proxy host
                            nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: The proxy hostname "%s" could not be resolved to an IP address.', 'fastcgi-cache-purge-and-preload-nginx'), $proxy_host));
                            break;

                        case 'proxy_unreachable':
                        default:
                            // Translators: %s = domain name, %s = proxy host, %d = proxy port
                            nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: Preloading failed for %1$s. Proxy is enabled, but %2$s:%3$d is not responding. Check your proxy or disable proxy mode.', 'fastcgi-cache-purge-and-preload-nginx'), $fdomain, $proxy_host, $proxy_port));
                            break;
                    }
                    return;
                }
            }
        }

        // Start cache preloading for whole website (Preload All)
        // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
        // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
        //    speeding up cache preloading via reducing latency we use --no-check-certificate .
        //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
        $command = "nohup wget --quiet --recursive --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-e use_proxy=$use_proxy " .
                "-e http_proxy=$http_proxy " .
                "-e https_proxy=$https_proxy " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--wait=$nginx_cache_wait " .
                "--reject-regex='\"$nginx_cache_reject_regex\"' " .
                "--reject='\"$nginx_cache_reject_extension\"' " .
                "--domains=$domain_list " .
                "--user-agent='\"". $NPPP_DYNAMIC_USER_AGENT ."\"' " .
                "\"$fdomain\" >/dev/null 2>&1 & echo \$!";

        // We are ready to call main command
        $output = shell_exec($command);

        // Get the process ID
        $parts = explode(" ", $output);
        $pid = end($parts);
        nppp_perform_file_operation($PIDFILE, 'write', $pid);

        // Create a DateTime object for the current time in WordPress timezone
        $wordpress_timezone = new DateTimeZone(wp_timezone_string());
        $current_time = new DateTime('now', $wordpress_timezone);

        // Format the current time as the start time for the scheduled event
        $start_time = $current_time->format('H:i:s');

        // Call the function to schedule the status check event
        if (!$preload_mobile) {
            nppp_create_scheduled_event_preload_status($start_time);
        }

        // Start cpulimit if it is exist
        if ($cpulimit === 1) {
            $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" -zb >/dev/null 2>&1";
            shell_exec($command);
        }

        // Display the deferred message as admin notice
        if (is_bool($nppp_is_rest_api) && $nppp_is_rest_api) {
            if (!$preload_mobile) {
                nppp_display_admin_notice('success', __( 'SUCCESS REST: Nginx cache purged successfully. Auto preload initiated in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
            } else {
                nppp_display_admin_notice('success', __( 'SUCCESS REST: Auto Preload initiated for Mobile in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
            }
        } elseif (is_bool($nppp_is_admin_bar) && $nppp_is_admin_bar) {
            if (!$preload_mobile) {
                nppp_display_admin_notice('success', __( 'SUCCESS ADMIN: Nginx cache purged successfully. Auto Preload initiated in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
            } else {
                nppp_display_admin_notice('success', __( 'SUCCESS ADMIN: Auto Preload initiated for Mobile in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
            }
        } else {
            if (!$preload_mobile) {
                nppp_display_admin_notice('success', __( 'SUCCESS: Nginx cache purged successfully. Auto Preload initiated in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
            } else {
                nppp_display_admin_notice('success', __( 'SUCCESS: Auto Preload initiated for Mobile in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ));
            }
        }
    }
}

// Single page preload
function nppp_preload_single($current_page_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Check if there is an ongoing preload process active
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            nppp_display_admin_notice('info', __( 'INFO: Nginx cache preloading is already running. If you want to stop it, please use Purge All', 'fastcgi-cache-purge-and-preload-nginx' ));
            return;
        }
    } elseif (!nppp_perform_file_operation($PIDFILE, 'create')) {
        nppp_display_admin_notice('error', __( 'FATAL ERROR: Failed to create PID file.', 'fastcgi-cache-purge-and-preload-nginx' ));
        return;
    }

    // Display decoded URL to user
    $current_page_url_decoded = rawurldecode($current_page_url);

    // Check for any permisson issue softly
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        // Translators: %s: Current page URL
        nppp_display_admin_notice('error', sprintf( __( 'ERROR PERMISSION: Nginx cache preload failed for page %s due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ));
        return;
    // Recusive check for permission issues deeply
    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        // Translators: %s: Current page URL
        nppp_display_admin_notice('error', sprintf( __( 'ERROR PERMISSION: Nginx cache preload failed for page %s due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ));
        return;
    }

    // Valitade the sanitized url before process
    if (filter_var($current_page_url, FILTER_VALIDATE_URL) === false) {
        nppp_display_admin_notice('error', __( 'ERROR URL: HTTP_REFERER URL cannot be validated.', 'fastcgi-cache-purge-and-preload-nginx' ));
        return;
    }

    // Checks if the HTTP referrer originated from our own host domain
    $referrer_parsed_url = wp_parse_url($current_page_url);
    $home_url = home_url();
    $parsed_home_url = wp_parse_url($home_url);

    if ($referrer_parsed_url['host'] !== $parsed_home_url['host']) {
        nppp_display_admin_notice('error', __( 'ERROR URL: HTTP_REFERER URL is not from the allowed domain.', 'fastcgi-cache-purge-and-preload-nginx' ));
        return;
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check Preload Mobile enabled
    $preload_mobile = false;
    if (isset($nginx_cache_settings['nginx_cache_auto_preload_mobile']) && $nginx_cache_settings['nginx_cache_auto_preload_mobile'] === 'yes') {
        $preload_mobile = true;
    }

    // Initialize an array to hold the PIDs for both desktop and mobile preload processes
    $pids = [];

    // Get proxy options
    $proxy_settings = nppp_get_proxy_settings();
    $use_proxy  = $proxy_settings['use_proxy'];
    $http_proxy = $proxy_settings['http_proxy'];
    $https_proxy = $http_proxy;

    // Test proxy and server network
    if ($use_proxy === 'yes') {
        // Parse proxy IP and Port
        $parsed_url = wp_parse_url($http_proxy);
        $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
        $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

        // Use proxy health check
        $proxy_status = nppp_is_proxy_reachable($proxy_host, $proxy_port);

        if (!$proxy_status['success']) {
            // Show admin notice based on error code
            switch ($proxy_status['code']) {
                case 'dns_error':
                    nppp_display_admin_notice('error', __('ERROR DNS: DNS resolution failed inside the WordPress container. Please check your container network/DNS settings.', 'fastcgi-cache-purge-and-preload-nginx'));
                    break;

                case 'network_error':
                    nppp_display_admin_notice('error', __('ERROR NETWORK: Outbound network connection failed from WordPress container. Ensure firewall/Docker network settings allow outbound access.', 'fastcgi-cache-purge-and-preload-nginx'));
                    break;

                case 'proxy_dns_fail':
                    // Translators: %s = proxy host
                    nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: The proxy hostname "%s" could not be resolved to an IP address.', 'fastcgi-cache-purge-and-preload-nginx'), $proxy_host));
                    break;

                case 'proxy_unreachable':
                default:
                    // Translators: %s = domain name, %s = proxy host, %d = proxy port
                    nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: Preloading failed for %1$s. Proxy is enabled, but %2$s:%3$d is not responding. Check your proxy or disable proxy mode.', 'fastcgi-cache-purge-and-preload-nginx'), $current_page_url_decoded, $proxy_host, $proxy_port));
                    break;
            }
            return;
        }
    }

    // Start cache preloading for single post/page (when manual On-page preload action triggers)
    // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
    // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
    //    speeding up cache preloading via reducing latency we use --no-check-certificate .
    //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
    // 3. --recursive removed here that we need single URL request
    // 4. --wait removed we need single HTTP request
    // 5. --reject-regex removed that preload URL already verified
    // 6. --reject removed that we don't use --recursive
    $command_desktop = "nohup wget --quiet --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-e use_proxy=$use_proxy " .
                "-e http_proxy=$http_proxy " .
                "-e https_proxy=$https_proxy " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--user-agent='\"". NPPP_USER_AGENT ."\"' " .
                "\"$current_page_url\" >/dev/null 2>&1 & echo \$!";

    // Trigger desktop preload and get PID
    $output_desktop = shell_exec($command_desktop);

    // Extract the PID and store it in the array for desktop
    if ($output_desktop !== null) {
        $parts_desktop = explode(" ", $output_desktop);
        $pid_desktop = end($parts_desktop);

        // Check if the desktop process is still running
        $isRunning_desktop = nppp_is_process_alive($pid_desktop);
        if ($isRunning_desktop) {
            // If the process is running, add the desktop PID to the array
            $pids['desktop'] = $pid_desktop;

            // Write PID to file
            nppp_perform_file_operation($PIDFILE, 'write', $pid_desktop);
        }
    }

    // Preload cache also for Mobile
    if ($preload_mobile) {
        $command_mobile = "nohup wget --quiet --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-e use_proxy=$use_proxy " .
                "-e http_proxy=$http_proxy " .
                "-e https_proxy=$https_proxy " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--user-agent='\"". NPPP_USER_AGENT_MOBILE ."\"' " .
                "\"$current_page_url\" >/dev/null 2>&1 & echo \$!";

        // Trigger preload for mobile
        $output_mobile = shell_exec($command_mobile);

        // Extract the PID and store it in the array for mobile
        if ($output_mobile !== null) {
            $parts_mobile = explode(" ", $output_mobile);
            $pid_mobile = end($parts_mobile);

            // Check if the mobile process is still running
            $isRunning_mobile = nppp_is_process_alive($pid_mobile);
            if ($isRunning_mobile) {
                // If the process is running, add the mobile PID to the array
                $pids['mobile'] = $pid_mobile;

                // Write PID to file
                nppp_perform_file_operation($PIDFILE, 'write', $pid_mobile);
            }
        }
    }

    // Get the process ID
    $desktop_pid_exists = isset($pids['desktop']);
    $mobile_pid_exists = isset($pids['mobile']);

    // Initialize message variable
    $message = '';
    $message_type = '';

    // Determine success and error messages based on PID availability
    if ($desktop_pid_exists || $mobile_pid_exists) {
        if ($desktop_pid_exists && !$mobile_pid_exists) {
            // Only desktop PID exists
            // Translators: %s: Current page URL
            $message = sprintf( __( 'SUCCESS ADMIN: Nginx cache preloading has started in the background for the desktop version of page %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
        } elseif (!$desktop_pid_exists && $mobile_pid_exists) {
            // Only mobile PID exists
            // Translators: %s: Current page URL
            $message = sprintf( __( 'SUCCESS ADMIN: Nginx cache preloading has started in the background for the mobile version of page %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
        } elseif ($desktop_pid_exists && $mobile_pid_exists) {
            // Both desktop and mobile PIDs exist
            // Translators: %s: Current page URL
            $message = sprintf( __( 'SUCCESS ADMIN: Nginx cache preloading has started in the background for both desktop and mobile versions of page %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
        }

        // Set message type to success if any PID exists
        $message_type = 'success';
    } else {
        if (!$desktop_pid_exists && !$mobile_pid_exists) {
            if ($preload_mobile) {
                // Translators: %s: Current page URL
                $message = sprintf( __( 'ERROR COMMAND: Nginx cache preloading failed for both desktop and mobile versions of page %s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
            } else {
                // Translators: %s: Current page URL
                $message = sprintf( __( 'ERROR COMMAND: Nginx cache preloading failed for desktop version of page %s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
            }
        } else {
            // Neither desktop nor mobile PID exists
            if (!$desktop_pid_exists) {
                // Translators: %s: Current page URL
                $message = sprintf( __( 'ERROR COMMAND: Nginx cache preloading failed for desktop version of page %s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
            }

            if (!$mobile_pid_exists) {
                if ($preload_mobile) {
                    // Translators: %s: Current page URL
                    $message = sprintf( __( 'ERROR COMMAND: Nginx cache preloading failed for mobile version of page %s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded );
                }
            }
        }

        // Set message type to error if no PID exists
        $message_type = 'error';
    }

    // Display the appropriate message
    if (!empty($message) && !empty($message_type)) {
        nppp_display_admin_notice($message_type, $message);
    }
}

// Only triggers conditionally if Auto Purge & Auto Preload enabled at the same time
// Only preloads cache for single post/page if Auto Purge triggered before for this modified/updated post/page
// This functions not trgiggers after On-Page purge actions
function nppp_preload_cache_on_update($current_page_url, $found = false) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Display decoded URL to user
    $current_page_url_decoded = rawurldecode($current_page_url);

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1024;

    // Get the necessary data for preload action from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;

    // Extra data for preload action
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Check Preload Mobile enabled
    $preload_mobile = false;
    if (isset($nginx_cache_settings['nginx_cache_auto_preload_mobile']) && $nginx_cache_settings['nginx_cache_auto_preload_mobile'] === 'yes') {
        $preload_mobile = true;
    }

    // Here we already purged cache successfully and we did not face any permission issue
    // So we don't need to check any permission issues again.
    // Also all url valitadation the sanitization actions have been taken before in purge cache step
    // So we don't need to valitade the sanitize url again here.
    // Also we are sure that there is no any active ongoing preload process here that we checked in purge cache step
    // So we don't need to check any on going active preload process here.

    // We just need to create a PIDFILE if it does not exist yet
    if (!$wp_filesystem->exists($PIDFILE)) {
        if (!nppp_perform_file_operation($PIDFILE, 'create')) {
            nppp_display_admin_notice('error', __( 'FATAL ERROR: Failed to create PID file.', 'fastcgi-cache-purge-and-preload-nginx' ));
            return;
        }
    }

    // Initialize an array to hold the PIDs for both desktop and mobile preload processes
    $pids = [];

    // Get proxy options
    $proxy_settings = nppp_get_proxy_settings();
    $use_proxy  = $proxy_settings['use_proxy'];
    $http_proxy = $proxy_settings['http_proxy'];
    $https_proxy = $http_proxy;

    // Test proxy and server network
    if ($use_proxy === 'yes') {
        // Parse proxy IP and Port
        $parsed_url = wp_parse_url($http_proxy);
        $proxy_host = isset($parsed_url['host']) ? $parsed_url['host'] : '127.0.0.1';
        $proxy_port = isset($parsed_url['port']) ? $parsed_url['port'] : 3434;

        // Use proxy health check
        $proxy_status = nppp_is_proxy_reachable($proxy_host, $proxy_port);

        if (!$proxy_status['success']) {
            // Show admin notice based on error code
            switch ($proxy_status['code']) {
                case 'dns_error':
                    nppp_display_admin_notice('error', __('ERROR DNS: DNS resolution failed inside the WordPress container. Please check your container network/DNS settings.', 'fastcgi-cache-purge-and-preload-nginx'));
                    break;

                case 'network_error':
                    nppp_display_admin_notice('error', __('ERROR NETWORK: Outbound network connection failed from WordPress container. Ensure firewall/Docker network settings allow outbound access.', 'fastcgi-cache-purge-and-preload-nginx'));
                    break;

                case 'proxy_dns_fail':
                    // Translators: %s = proxy host
                    nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: The proxy hostname "%s" could not be resolved to an IP address.', 'fastcgi-cache-purge-and-preload-nginx'), $proxy_host));
                    break;

                case 'proxy_unreachable':
                default:
                    // Translators: %s = domain name, %s = proxy host, %d = proxy port
                    nppp_display_admin_notice('error', sprintf(__('ERROR PROXY: Preloading failed for %1$s. Proxy is enabled, but %2$s:%3$d is not responding. Check your proxy or disable proxy mode.', 'fastcgi-cache-purge-and-preload-nginx'), $current_page_url_decoded, $proxy_host, $proxy_port));
                    break;
            }
            return;
        }
    }

    // Start cache preloading for single post/page (when Auto Purge & Auto Preload enabled both)
    // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
    // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
    //    speeding up cache preloading via reducing latency we use --no-check-certificate .
    //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
    // 3. --recursive removed here that we need single URL request
    // 4. --wait removed we need single HTTP request
    // 5. --reject-regex removed that preload URL already verified
    // 6. --reject removed that we don't use --recursive
    $command_desktop = "nohup wget --quiet --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-e use_proxy=$use_proxy " .
                "-e http_proxy=$http_proxy " .
                "-e https_proxy=$https_proxy " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--user-agent='\"". NPPP_USER_AGENT ."\"' " .
                "\"$current_page_url\" >/dev/null 2>&1 & echo \$!";

    // Trigger desktop preload and get PID
    $output_desktop = shell_exec($command_desktop);

    // Extract the PID and store it in the array for desktop
    if ($output_desktop !== null) {
        $parts_desktop = explode(" ", $output_desktop);
        $pid_desktop = end($parts_desktop);

        // Check if the desktop process is still running
        $isRunning_desktop = nppp_is_process_alive($pid_desktop);
        if ($isRunning_desktop) {
            // If the process is running, add the desktop PID to the array
            $pids['desktop'] = $pid_desktop;

            // Write PID to file
            nppp_perform_file_operation($PIDFILE, 'write', $pid_desktop);
        }
    }

    // Preload cache also for Mobile
    if ($preload_mobile) {
        $command_mobile = "nohup wget --quiet --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-e use_proxy=$use_proxy " .
                "-e http_proxy=$http_proxy " .
                "-e https_proxy=$https_proxy " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--user-agent='\"". NPPP_USER_AGENT_MOBILE ."\"' " .
                "\"$current_page_url\" >/dev/null 2>&1 & echo \$!";

        // Trigger preload for mobile
        $output_mobile = shell_exec($command_mobile);

        // Extract the PID and store it in the array for mobile
        if ($output_mobile !== null) {
            $parts_mobile = explode(" ", $output_mobile);
            $pid_mobile = end($parts_mobile);

            // Check if the mobile process is still running
            $isRunning_mobile = nppp_is_process_alive($pid_mobile);
            if ($isRunning_mobile) {
                // If the process is running, add the mobile PID to the array
                $pids['mobile'] = $pid_mobile;

                // Write PID to file
                nppp_perform_file_operation($PIDFILE, 'write', $pid_mobile);
            }
        }
    }

    // Handling success and error messages
    $devices = ['desktop', 'mobile'];

    // Get the process ID
    foreach ($devices as $device) {
        if (isset($pids[$device])) {
            // Determine the success message based on auto purge status
            if ($found) {
                if ($device === 'mobile') {
                    // Translators: %s1: device type (desktop or mobile), %s2: current page URL
                    $success_message = sprintf( __( 'SUCCESS ADMIN: Auto preload started for %1$s version of %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $device, $current_page_url_decoded );
                } else {
                    // Translators: %s1: device type (desktop or mobile), %s2: current page URL
                    $success_message = sprintf( __( 'SUCCESS ADMIN: Auto purge cache completed, Auto preload started for %1$s version of %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $device, $current_page_url_decoded );
                }
            } else {
                if ($device === 'mobile') {
                    // Translators: %s1: device type (desktop or mobile), %s2: current page URL
                    $success_message = sprintf( __( 'SUCCESS ADMIN: Auto preload started for %1$s version of %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $device, $current_page_url_decoded );
                } else {
                    // Translators: %s1: device type (desktop or mobile), %s2: current page URL
                    $success_message = sprintf( __( 'SUCCESS ADMIN: Auto purge cache attempted but page not found in cache, Auto preload started for %1$s version of %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $device, $current_page_url_decoded );
                }
            }

            // Display the success message
            if (!empty($success_message)) {
                nppp_display_admin_notice('success', $success_message, true);
            }
        } else {
            // Determine the error message based on auto purge status
            if ($found) {
                if ($device === 'mobile') {
                    if ($preload_mobile) {
                        // Translators: %s1: device type (desktop or mobile), %s2: current page URL
                        $error_message = sprintf( __( 'ERROR COMMAND: Unable to start Auto preload for %1$s version of %2$s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $device, $current_page_url_decoded );
                    }
                } else {
                    // Translators: %s1: device type (desktop or mobile), %s2: current page URL
                    $error_message = sprintf( __( 'ERROR COMMAND: Auto purge cache completed, but unable to start Auto preload for %1$s version of %2$s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $device, $current_page_url_decoded );
                }
            } else {
                if ($device === 'mobile') {
                    if ($preload_mobile) {
                        // Translators: %s: device type (desktop or mobile)
                        $error_message = sprintf( __( 'ERROR COMMAND: Unable to start Auto preload for %s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $device );
                    }
                } else {
                    // Translators: %s: device type (desktop or mobile)
                    $error_message = sprintf( __( 'ERROR COMMAND: Auto purge cache attempted but page not found in cache, unable to start Auto preload for %s. Please report this issue on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ), $device );
                }
            }

            // Display the error message
            if (!empty($error_message)) {
                nppp_display_admin_notice('error', $error_message, true);
            }
        }
    }
}
