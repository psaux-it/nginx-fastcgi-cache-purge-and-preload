<?php
/**
 * Settings field callbacks for Nginx Cache Purge Preload
 * Description: Renders all individual settings field HTML for the WordPress Settings API.
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

// Callback function to display the settings section description.
function nppp_nginx_cache_settings_section_callback() {
    echo 'Configure the settings for FastCGI Cache.';
}

// Callback function to display the input field for Nginx Cache Path setting
function nppp_nginx_cache_path_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_cache_path = '/dev/shm/change-me-now';
    echo "<input type='text' id='nginx_cache_path' name='nginx_cache_settings[nginx_cache_path]' value='" . esc_attr($options['nginx_cache_path'] ?? $default_cache_path) . "' class='regular-text' />";
}

// Callback function to display the input field for Email Address setting
function nppp_nginx_cache_email_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_email = 'your-email@example.com';
    echo "<input type='text' id='nginx_cache_email' name='nginx_cache_settings[nginx_cache_email]' value='" . esc_attr($options['nginx_cache_email'] ?? $default_email) . "' class='regular-text' />";
}

// Callback function to display the input field for CPU Usage Limit setting
function nppp_nginx_cache_cpu_limit_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_cpu_limit = 100;
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='10' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit'] ?? $default_cpu_limit) . "' class='small-text' />";
}

// Callback function to display the input field for Per Request Wait Time setting
function nppp_nginx_cache_wait_request_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_wait_time = 0;
    echo "<input type='number' id='nginx_cache_wait_request' name='nginx_cache_settings[nginx_cache_wait_request]' min='0' max='60' value='" . esc_attr($options['nginx_cache_wait_request'] ?? $default_wait_time) . "' class='small-text' />";
}

// Callback function to display the input field for PHP Response Timeout setting
function nppp_nginx_cache_read_timeout_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_read_timeout = 60;
    echo "<input type='number' id='nginx_cache_read_timeout' name='nginx_cache_settings[nginx_cache_read_timeout]' min='10' max='300' value='" . esc_attr($options['nginx_cache_read_timeout'] ?? $default_read_timeout) . "' class='small-text' />";
}

// Callback function to display the checkbox for Send Email Notification setting
function nppp_nginx_cache_send_mail_callback() {
    $options = get_option('nginx_cache_settings', []);
    $send_mail_checked = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes' ? 'checked="checked"' : '';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_send_mail]" class="nppp-onoffswitch-checkbox" value='yes' id="nginx_cache_send_mail" <?php echo esc_attr($send_mail_checked); ?>>
    <label class="nppp-onoffswitch-label" for="nginx_cache_send_mail">
        <span class="nppp-onoffswitch-inner">
            <span class="nppp-off"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_auto_preload field
function nppp_nginx_cache_auto_preload_callback() {
    $options = get_option('nginx_cache_settings', []);
    $auto_preload_checked = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_auto_preload]" class="nppp-onoffswitch-checkbox-preload" value="yes" id="nginx_cache_auto_preload" <?php echo esc_attr($auto_preload_checked); ?>>
    <label class="nppp-onoffswitch-label-preload" for="nginx_cache_auto_preload">
        <span class="nppp-onoffswitch-inner-preload">
            <span class="nppp-off-preload"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-preload"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-preload"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_schedule field
function nppp_nginx_cache_schedule_callback() {
    $options = get_option('nginx_cache_settings', []);
    $cache_schedule_checked = isset($options['nginx_cache_schedule']) && $options['nginx_cache_schedule'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_schedule]" class="nppp-onoffswitch-checkbox-schedule" value="yes" id="nginx_cache_schedule" <?php echo esc_attr($cache_schedule_checked); ?>>
    <label class="nppp-onoffswitch-label-schedule" for="nginx_cache_schedule">
        <span class="nppp-onoffswitch-inner-schedule">
            <span class="nppp-off-schedule"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-schedule"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-schedule"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_purge_on_update field
function nppp_nginx_cache_purge_on_update_callback() {
    $options = get_option('nginx_cache_settings', []);
    $auto_purge_checked = isset($options['nginx_cache_purge_on_update']) && $options['nginx_cache_purge_on_update'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_purge_on_update]" class="nppp-onoffswitch-checkbox-autopurge" value="yes" id="nginx_cache_purge_on_update" <?php echo esc_attr($auto_purge_checked); ?>>
    <label class="nppp-onoffswitch-label-autopurge" for="nginx_cache_purge_on_update">
        <span class="nppp-onoffswitch-inner-autopurge">
            <span class="nppp-off-autopurge"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-autopurge"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-autopurge"></span>
    </label>
    <?php
}

// Callback function for the Cloudflare APO sync field.
function nppp_nginx_cache_cloudflare_apo_sync_callback() {
    $options = get_option('nginx_cache_settings', []);
    $is_checked = isset($options['nppp_cloudflare_apo_sync']) && $options['nppp_cloudflare_apo_sync'] === 'yes';
    $cloudflare_checked = $is_checked ? 'checked="checked"' : '';
    $is_available = function_exists('nppp_cloudflare_apo_is_available') && nppp_cloudflare_apo_is_available();
    if ( ! $is_available && isset($options['nppp_cloudflare_apo_sync']) && $options['nppp_cloudflare_apo_sync'] !== 'no' ) {
        $options['nppp_cloudflare_apo_sync'] = 'no';
        update_option('nginx_cache_settings', $options);
        $cloudflare_checked = '';
    }
    $disabled = $is_available ? '' : 'disabled="disabled"';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nppp_cloudflare_apo_sync]" class="nppp-onoffswitch-checkbox-cloudflare" value="yes" id="nppp_cloudflare_apo_sync" <?php echo esc_attr($cloudflare_checked); ?> <?php echo esc_attr($disabled); ?>>
    <label class="nppp-onoffswitch-label-cloudflare" for="nppp_cloudflare_apo_sync">
        <span class="nppp-onoffswitch-inner-cloudflare">
            <span class="nppp-off-cloudflare"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-cloudflare"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-cloudflare"></span>
    </label>
    <?php if ( ! $is_available ) : ?>
        <div class="nppp-related-pages" aria-live="polite">
            <em class="nppp-hint" role="note" style="max-width:max-content; opacity: 0.5;">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <?php echo esc_html__( 'Cloudflare APO plugin not detected. Install and configure it to enable synchronization.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </em>
        </div>
    <?php endif; ?>
    <?php
}

// Callback function for the Redis Cache field.
function nppp_nginx_cache_redis_cache_sync_callback() {
    $options      = get_option('nginx_cache_settings', []);
    $is_checked   = isset( $options['nppp_redis_cache_sync'] ) && $options['nppp_redis_cache_sync'] === 'yes';
    $checked_attr = $is_checked ? 'checked="checked"' : '';
    $is_available = function_exists( 'nppp_redis_cache_is_available' ) && nppp_redis_cache_is_available();

    // If the toggle is on but Redis disappeared, clear the stored value.
    if ( ! $is_available && isset( $options['nppp_redis_cache_sync'] ) && $options['nppp_redis_cache_sync'] !== 'no' ) {
        $options['nppp_redis_cache_sync'] = 'no';
        update_option( 'nginx_cache_settings', $options );
        $checked_attr = '';
    }

    $disabled = $is_available ? '' : 'disabled="disabled"';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nppp_redis_cache_sync]" class="nppp-onoffswitch-checkbox-redis" value="yes" id="nppp_redis_cache_sync" <?php echo esc_attr( $checked_attr ); ?> <?php echo esc_attr( $disabled ); ?>>
    <label class="nppp-onoffswitch-label-redis" for="nppp_redis_cache_sync">
        <span class="nppp-onoffswitch-inner-redis">
            <span class="nppp-off-redis"><?php echo esc_html__( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-redis"><?php echo esc_html__( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-redis"></span>
    </label>
    <?php if ( ! $is_available ) : ?>
        <div class="nppp-related-pages" aria-live="polite">
            <em class="nppp-hint" role="note" style="max-width:max-content; opacity: 0.5;">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <?php echo esc_html__( 'Redis Object Cache plugin not detected or not connected. Install and configure it to enable sync.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </em>
        </div>
    <?php endif; ?>
    <?php
}

// Callback function for the nginx_cache_auto_preload_mobile field
function nppp_nginx_cache_auto_preload_mobile_callback() {
    $options = get_option('nginx_cache_settings', []);
    $auto_preload_mobile_checked = isset($options['nginx_cache_auto_preload_mobile']) && $options['nginx_cache_auto_preload_mobile'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_auto_preload_mobile]" class="nppp-onoffswitch-checkbox-preload-mobile" value="yes" id="nginx_cache_auto_preload_mobile" <?php echo esc_attr($auto_preload_mobile_checked); ?>>
    <label class="nppp-onoffswitch-label-preload-mobile" for="nginx_cache_auto_preload_mobile">
        <span class="nppp-onoffswitch-inner-preload-mobile">
            <span class="nppp-off-preload-mobile"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-preload-mobile"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-preload-mobile"></span>
    </label>
    <?php
}

// Callback function for the nginx_cache_watchdog field
function nppp_nginx_cache_watchdog_callback() {
    $options = get_option('nginx_cache_settings', []);
    $watchdog_checked = isset($options['nginx_cache_watchdog']) && $options['nginx_cache_watchdog'] === 'yes' ? 'checked="checked"' : '';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_watchdog]" class="nppp-onoffswitch-checkbox-watchdog" value="yes" id="nginx_cache_watchdog" <?php echo esc_attr($watchdog_checked); ?>>
    <label class="nppp-onoffswitch-label-watchdog" for="nginx_cache_watchdog">
        <span class="nppp-onoffswitch-inner-watchdog">
            <span class="nppp-off-watchdog"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-watchdog"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-watchdog"></span>
    </label>
    <?php
}

// Callback function to display the Reject Regex field
function nppp_nginx_cache_reject_regex_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_reject_regex = nppp_fetch_default_reject_regex();
    $default_reject_regex = isset($options['nginx_cache_reject_regex']) ? $options['nginx_cache_reject_regex'] : $default_reject_regex;
    $reject_regex = preg_replace('/\\\\+/', '\\', $default_reject_regex);
    echo "<textarea id='nginx_cache_reject_regex' name='nginx_cache_settings[nginx_cache_reject_regex]' rows='3' cols='50' class='large-text'>" . esc_textarea($reject_regex) . "</textarea>";
}

// Callback function to display the custom Regex field for fastcgi_cache_key
function nppp_nginx_cache_key_custom_regex_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_cache_key_regex = nppp_fetch_default_regex_for_cache_key();
    $cache_key_regex = isset($options['nginx_cache_key_custom_regex']) ? base64_decode($options['nginx_cache_key_custom_regex']) : $default_cache_key_regex;
    // Use wp_kses() with an empty array to allow raw text without HTML sanitization
    echo "<textarea id='nginx_cache_key_custom_regex' name='nginx_cache_settings[nginx_cache_key_custom_regex]' rows='1' cols='50' class='large-text'>" . esc_textarea($cache_key_regex) . "</textarea>";
}

// Callback function to display the Mobile User Agent field
function nppp_nginx_cache_mobile_user_agent_callback() {
    $options            = get_option('nginx_cache_settings', []);
    $default_mobile_ua  = nppp_fetch_default_mobile_user_agent();
    $mobile_user_agent  = ! empty( $options['nginx_cache_mobile_user_agent'] )
        ? $options['nginx_cache_mobile_user_agent']
        : $default_mobile_ua;
    echo "<textarea id='nginx_cache_mobile_user_agent' name='nginx_cache_settings[nginx_cache_mobile_user_agent]' rows='1' cols='50' class='large-text'>" . esc_textarea( $mobile_user_agent ) . "</textarea>";
}

// Callback function to display the Reject extension field
function nppp_nginx_cache_reject_extension_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_reject_extension = nppp_fetch_default_reject_extension();
    $default_reject_extension = isset($options['nginx_cache_reject_extension']) ? $options['nginx_cache_reject_extension'] : $default_reject_extension;
    $reject_extension = preg_replace('/\\\\+/', '\\', $default_reject_extension);
    echo "<textarea id='nginx_cache_reject_extension' name='nginx_cache_settings[nginx_cache_reject_extension]' rows='3' cols='50' class='large-text'>" . esc_textarea($reject_extension) . "</textarea>";
}

// Callback function to display the Logs field
function nppp_nginx_cache_logs_callback() {
    $log_file_path = NGINX_CACHE_LOG_FILE;
    nppp_perform_file_operation($log_file_path, 'create');
    if (file_exists($log_file_path) && is_readable($log_file_path)) {
        // Read the log file into an array of lines
        $lines = file($log_file_path);
        // Get the latest 5 lines
        if (is_array($lines)) {
            $latest_lines = array_slice($lines, -5);

            // Remove leading tab spaces and spaces from each line
            $cleaned_lines = array_map(function($line) {
                return trim($line);
            }, $latest_lines);
            ?>
            <div class="logs-container">
                <?php
                // Output the latest 5 lines
                foreach ($cleaned_lines as $line) {
                    if (!empty($line)) {
                        // Extract timestamp and message
                        preg_match('/^\[(.*?)\]\s*(.*?)$/', $line, $matches);
                        $timestamp = isset($matches[1]) ? $matches[1] : '';
                        $message = isset($matches[2]) ? $matches[2] : '';

                        // Apply different CSS classes based on whether it's an error line or not
                        $class = strpos($message, 'ERROR') !== false ? 'error-line' : (strpos($message, 'SUCCESS') !== false ? 'success-line' : 'normal-line');

                        // Output the line with the appropriate CSS class
                        echo '<div class="' . esc_attr($class) . '"><span class="timestamp">' . esc_html($timestamp) . '</span> ' . esc_html($message) . '</div>';
                    }
                }
                ?>
                <div class="cursor blink">#</div>
            </div>
            <?php
        } else {
            echo '<p>' . esc_html__('Unable to read log file. Please check file permissions.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
        }
    } else {
        echo '<p>' . esc_html__('Log file not found or is not readable.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
    }
}

// Callback function to display the input field for Limit Rate setting.
function nppp_nginx_cache_limit_rate_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_limit_rate = 5120;
    echo "<input type='number' id='nginx_cache_limit_rate' name='nginx_cache_settings[nginx_cache_limit_rate]' min='1' max='102400' value='" . esc_attr($options['nginx_cache_limit_rate'] ?? $default_limit_rate) . "' class='small-text' />";
}

// Callback function to display the input field for Proxy Port setting.
function nppp_nginx_cache_proxy_port_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_port = 3434;
    echo "<input type='number' id='nginx_cache_preload_proxy_port' name='nginx_cache_settings[nginx_cache_preload_proxy_port]' value='" . esc_attr($options['nginx_cache_preload_proxy_port'] ?? $default_port) . "' class='small-text' />";
}

// Callback function to display the input field for Proxy Host setting (IP field).
function nppp_nginx_cache_proxy_host_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_host = '127.0.0.1';
    echo "<input type='text' id='nginx_cache_preload_proxy_host' name='nginx_cache_settings[nginx_cache_preload_proxy_host]' value='" . esc_attr($options['nginx_cache_preload_proxy_host'] ?? $default_host) . "' class='regular-text' />";
}

// Callback function for the nginx_cache_preload_enable_proxy field
function nppp_nginx_cache_enable_proxy_callback() {
    $options = get_option('nginx_cache_settings', []);
    $enable_proxy_checked = isset($options['nginx_cache_preload_enable_proxy']) && $options['nginx_cache_preload_enable_proxy'] === 'yes' ? 'checked="checked"' : '';
    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_preload_enable_proxy]" class="nppp-onoffswitch-checkbox-proxy" value="yes" id="nginx_cache_preload_enable_proxy" <?php echo esc_attr($enable_proxy_checked); ?>>
    <label class="nppp-onoffswitch-label-proxy" for="nginx_cache_preload_enable_proxy">
        <span class="nppp-onoffswitch-inner-proxy">
            <span class="nppp-off-proxy"><?php echo esc_html__('OFF', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
            <span class="nppp-on-proxy"><?php echo esc_html__('ON', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-proxy"></span>
    </label>
    <?php
}

// Include preload defaults
function nppp_get_preload_defaults(): array {
    static $defaults = null;
    if ( $defaults === null ) {
        $file     = dirname( __FILE__ ) . '/preload-defaults.php';
        $loaded   = is_readable( $file ) ? ( include $file ) : [];
        $defaults = is_array( $loaded ) ? $loaded : [];
    }
    return $defaults;
}

// Get default url reject rules for preload
function nppp_fetch_default_reject_regex(): string {
    return nppp_get_preload_defaults()['reject_regex'] ?? '';
}

// Get default regex for nginx cache key
function nppp_fetch_default_regex_for_cache_key(): string {
    return nppp_get_preload_defaults()['cache_key_regex'] ?? '';
}

// Get default mobile user agent string
function nppp_fetch_default_mobile_user_agent(): string {
    return nppp_get_preload_defaults()['mobile_user_agent'] ?? '';
}

// Get default reject file extension rules for preload
function nppp_fetch_default_reject_extension(): string {
    return nppp_get_preload_defaults()['reject_extension'] ?? '';
}

// Callback function for REST API Key
function nppp_nginx_cache_api_key_callback() {
    $options = get_option('nginx_cache_settings', []);
    $default_api_key = bin2hex(random_bytes(32));
    $api_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : $default_api_key;
    echo "<input type='text' id='nginx_cache_api_key' name='nginx_cache_settings[nginx_cache_api_key]' value='" . esc_attr($api_key) . "' class='regular-text' />";

    echo "<div style='display: block; align-items: baseline;'>";
    echo "<button type='button' id='api-key-button' class='button nginx-api-key-button'>" . esc_html__( 'Generate API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "</button>";
    echo "<div style='display: flex; align-items: baseline; margin-top: 8px; margin-bottom: 8px;'>";
    echo "<p class='description' id='nppp-api-key' style='margin-right: 10px;'><span class='nppp-tooltip'>" . esc_html__( 'API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy REST API Key', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "<p class='description' id='nppp-purge-url' style='margin-right: 10px;'><span class='nppp-tooltip'>" . esc_html__( 'Purge URL', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy full REST API CURL URL for Purge', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "<p class='description' id='nppp-preload-url'><span class='nppp-tooltip'>" . esc_html__( 'Preload URL', 'fastcgi-cache-purge-and-preload-nginx' ) . "<span class='nppp-tooltiptext'>" . esc_html__( 'Click to copy full REST API CURL URL for Preload', 'fastcgi-cache-purge-and-preload-nginx' ) . "</span></span></p>";
    echo "</div>";
    echo "</div>";
}

// Related Pages (single-URL purge only) callback
function nppp_nginx_cache_related_pages_callback() {
    $options = get_option( 'nginx_cache_settings', array() );

    $home = $options['nppp_related_include_home'] ?? 'no';
    $cat  = $options['nppp_related_include_category'] ?? 'no';
    $shop = $options['nppp_related_apply_manual'] ?? 'no';
    $pre  = $options['nppp_related_preload_after_manual'] ?? 'no';

    // UI gating
    $has_related = ($home === 'yes' || $cat === 'yes' || $shop === 'yes');
    if (!$has_related) {
        $pre = 'no';
    }
    ?>
    <fieldset class="nppp-related-pages nppp-ui">

        <div class="nppp-switch">
            <input id="nppp_rel_home" type="checkbox"
                   name="nginx_cache_settings[nppp_related_include_home]"
                   value="yes" <?php checked( 'yes', $home ); ?> />
            <label for="nppp_rel_home">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Always Purge the Homepage', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When any single URL is purged (manual or auto), also purge the homepage.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br>
                </span>
            </label>
        </div>

        <div class="nppp-switch">
            <input id="nppp_rel_apply_manual" type="checkbox"
                   name="nginx_cache_settings[nppp_related_apply_manual]"
                   value="yes" <?php checked( 'yes', $shop ); ?> />
            <label for="nppp_rel_apply_manual">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Always Purge the Shop Page (WooCommerce)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When a product page is purged (manual or auto), also purge the WooCommerce shop page.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br>
                </span>
            </label>
        </div>

        <div class="nppp-switch">
            <input id="nppp_rel_cat" type="checkbox"
                   name="nginx_cache_settings[nppp_related_include_category]"
                   value="yes" <?php checked( 'yes', $cat ); ?> />
            <label for="nppp_rel_cat">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Always Purge Categories & Tags (WordPress + WooCommerce)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When a post or product is purged (manual or auto), also purge its category and tag archives.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br>
                </span>
            </label>
        </div>

        <div class="nppp-switch">
            <input id="nppp_rel_preload" type="checkbox"
                   name="nginx_cache_settings[nppp_related_preload_after_manual]"
                   value="yes"
                   <?php
                       checked( 'yes', $pre );
                       echo $has_related ? '' : ' disabled="disabled" aria-disabled="true"';
                   ?> />
            <label for="nppp_rel_preload">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Preload Related Pages', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc">
                        <?php esc_html_e( 'Manual Single Purge (On-Page or Advanced Tab): turn this ON to also preload related pages above. Auto Purge ON: related pages are preloaded automatically only when Auto Preload is ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </span>
                </span>
            </label>
        </div>

    </fieldset>
    <?php
}

// Callback function for the Bypass Path Restriction single toggle card.
function nppp_nginx_cache_bypass_path_restriction_callback() {
    $opts    = get_option( 'nginx_cache_settings', array() );
    $checked = isset( $opts['nginx_cache_bypass_path_restriction'] ) && $opts['nginx_cache_bypass_path_restriction'] === 'yes';
    ?>
    <fieldset class="nppp-bypass-pr-fieldset" id="nppp-bypass-pr-fieldset">
        <div class="nppp-switch">
            <input id="nginx_cache_bypass_path_restriction" type="checkbox"
                   name="nginx_cache_settings[nginx_cache_bypass_path_restriction]"
                   value="yes" <?php checked( true, $checked ); ?> />
            <label for="nginx_cache_bypass_path_restriction">
                <span class="nppp-toggle" aria-hidden="true"></span>
                <span class="nppp-text">
                    <span class="title"><?php esc_html_e( 'Bypass Path Restriction', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    <span class="desc"><?php esc_html_e( 'When enabled, it completely disables path restrictions and all built-in path safety guardrails.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                </span>
            </label>
        </div>
        <div class="nppp-bypass-pr-caution" id="nppp-bypass-pr-caution" role="alert" aria-live="polite"
            style="<?php echo $checked ? '' : 'display:none;'; ?>">
            <p class="nppp-caution-header">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php esc_html_e( 'CAUTION', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </p>
            <p class="nppp-caution-body">
                <?php esc_html_e( 'All built-in path safety guardrails are now disabled. The plugin will operate on ANY directory you configure as the Nginx cache path — including system-critical locations. Unintended, irreversible file deletions are possible. By enabling this feature, you explicitly accept full responsibility for any data loss or system damage that may result.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
            </p>
        </div>
    </fieldset>
    <?php
}

// Callback function for the Auto Purge Triggers sub-options fieldset.
function nppp_nginx_cache_autopurge_triggers_callback() {
    $options   = get_option( 'nginx_cache_settings', array() );
    $master_on = isset( $options['nginx_cache_purge_on_update'] ) && $options['nginx_cache_purge_on_update'] === 'yes';

    $posts     = $options['nppp_autopurge_posts']     ?? 'no';
    $terms     = $options['nppp_autopurge_terms']     ?? 'no';
    $plugins   = $options['nppp_autopurge_plugins']   ?? 'no';
    $themes    = $options['nppp_autopurge_themes']    ?? 'no';
    $thirdpty  = $options['nppp_autopurge_3rdparty']  ?? 'no';

    $disabled_attr    = $master_on ? '' : 'disabled="disabled"';
    $aria_disabled    = $master_on ? 'false' : 'true';
    $fieldset_class   = 'nppp-autopurge-triggers-fieldset' . ( $master_on ? '' : ' nppp-autopurge-triggers-off' );
    ?>
    <div class="nppp-autopurge-triggers-wrap">
        <fieldset class="<?php echo esc_attr( $fieldset_class ); ?>" id="nppp-autopurge-triggers-fieldset">

            <div class="nppp-switch">
                <input id="nppp_autopurge_posts" type="checkbox"
                       name="nginx_cache_settings[nppp_autopurge_posts]"
                       value="yes"
                       <?php checked( 'yes', $posts ); ?>
                       <?php echo esc_attr( $disabled_attr ); ?>
                       aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" />
                <label for="nppp_autopurge_posts">
                    <span class="nppp-toggle" aria-hidden="true"></span>
                    <span class="nppp-text">
                        <span class="title"><?php esc_html_e( 'Posts & Comments', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        <span class="desc"><?php esc_html_e( 'Auto Purge any post, page, or WooCommerce product when published, updated, deleted, when a comment is approved, or when product stock changes (quantity, status, or order cancellation). (Archive pages are purged only if enabled under Purge Scope.)', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    </span>
                </label>
            </div>

            <div class="nppp-switch">
                <input id="nppp_autopurge_terms" type="checkbox"
                       name="nginx_cache_settings[nppp_autopurge_terms]"
                       value="yes"
                       <?php checked( 'yes', $terms ); ?>
                       <?php echo esc_attr( $disabled_attr ); ?>
                       aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" />
                <label for="nppp_autopurge_terms">
                    <span class="nppp-toggle" aria-hidden="true"></span>
                    <span class="nppp-text">
                        <span class="title"><?php esc_html_e( 'Categories, Tags & Taxonomies', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        <span class="desc"><?php esc_html_e( 'Auto Purge category, tag, and custom taxonomy archive pages when terms are created, edited, or removed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    </span>
                </label>
            </div>

            <div class="nppp-switch">
                <input id="nppp_autopurge_plugins" type="checkbox"
                       name="nginx_cache_settings[nppp_autopurge_plugins]"
                       value="yes"
                       <?php checked( 'yes', $plugins ); ?>
                       <?php echo esc_attr( $disabled_attr ); ?>
                       aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" />
                <label for="nppp_autopurge_plugins">
                    <span class="nppp-toggle" aria-hidden="true"></span>
                    <span class="nppp-text">
                        <span class="title"><?php esc_html_e( 'Plugin Activations & Updates', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        <span class="desc"><?php esc_html_e( 'Auto Purge the entire cache when any plugin is activated, deactivated, or updated.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    </span>
                </label>
            </div>

            <div class="nppp-switch">
                <input id="nppp_autopurge_themes" type="checkbox"
                       name="nginx_cache_settings[nppp_autopurge_themes]"
                       value="yes"
                       <?php checked( 'yes', $themes ); ?>
                       <?php echo esc_attr( $disabled_attr ); ?>
                       aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" />
                <label for="nppp_autopurge_themes">
                    <span class="nppp-toggle" aria-hidden="true"></span>
                    <span class="nppp-text">
                        <span class="title"><?php esc_html_e( 'Theme Switches & Updates', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        <span class="desc"><?php esc_html_e( 'Auto Purge the entire cache when the active theme is switched, updated, or when global theme templates (Elementor Header/Footer) or theme CSS are modified.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    </span>
                </label>
            </div>

            <div class="nppp-switch">
                <input id="nppp_autopurge_3rdparty" type="checkbox"
                       name="nginx_cache_settings[nppp_autopurge_3rdparty]"
                       value="yes"
                       <?php checked( 'yes', $thirdpty ); ?>
                       <?php echo esc_attr( $disabled_attr ); ?>
                       aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" />
                <label for="nppp_autopurge_3rdparty">
                    <span class="nppp-toggle" aria-hidden="true"></span>
                    <span class="nppp-text">
                        <span class="title"><?php esc_html_e( 'WordPress Auto‑Updates & 3rd‑Party Plugins', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                        <span class="desc"><?php esc_html_e( 'Auto Purge the entire cache during automatic WordPress background updates and when compatible caching plugins trigger a purge.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                    </span>
                </label>
            </div>

        </fieldset>
    </div>
    <?php
}

// Percent encode URL Normalization callback
function nppp_nginx_cache_pctnorm_mode_callback() {
    $opts    = get_option('nginx_cache_settings', array());
    $current = isset($opts['nginx_cache_pctnorm_mode']) ? $opts['nginx_cache_pctnorm_mode'] : 'off';

    $cached = get_transient('nppp_safexec_ok');
    if ($cached === false) {
        $safexec_path = nppp_find_safexec_path();
        $safexec_ok = $safexec_path && nppp_is_safexec_usable($safexec_path, false);
        set_transient('nppp_safexec_ok', array('path' => $safexec_path, 'ok' => $safexec_ok), HOUR_IN_SECONDS);
    } else {
        $safexec_path = $cached['path'];
        $safexec_ok   = $cached['ok'];
    }
    $is_disabled = ! $safexec_ok;

    if ($is_disabled && $current !== 'off') {
        $opts['nginx_cache_pctnorm_mode'] = 'off';
        update_option('nginx_cache_settings', $opts);
        $current = 'off';
    }

    // Shown as native tooltip
    if (!$safexec_path) {
        $status_note = esc_html__( 'Unavailable: safexec not found. Install it to enable URL Normalization (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif (!$safexec_ok) {
        // Distinguish: SUID failure vs SHA256 integrity failure
        $p         = @realpath($safexec_path) ?: $safexec_path;
        $stat_info = function_exists('stat') ? @stat($p) : false;
        $suid_ok   = $stat_info
                     && ($stat_info['uid'] === 0)
                     && (($stat_info['mode'] & 04000) === 04000);

        if ($suid_ok) {
            $status_note = esc_html__( 'Unavailable: safexec integrity check failed. Reinstall the correct version (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
        } else {
            $status_note = esc_html__( 'Unavailable: safexec is not SUID/root-owned. Fix permissions (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
        }
    } else {
        $status_note = '';
    }

    $fieldset_aria    = $is_disabled ? ' aria-disabled="true"' : '';
    $fieldset_title   = $is_disabled ? ' title="' . esc_attr( $status_note ) . '"' : '';
    $fieldset_class   = 'nppp-segcontrol nppp-segcontrol--sm nppp-segcontrol--flat' . ( $is_disabled ? ' nppp-is-disabled' : '' );
    ?>
    <fieldset id="nppp-pctnorm"
              class="<?php echo esc_attr($fieldset_class); ?>"
              role="radiogroup"
              <?php echo wp_kses_post( $fieldset_aria . $fieldset_title ); ?>
              <?php if ( $is_disabled ) : ?>
                  data-note="<?php echo esc_attr($status_note); ?>"
              <?php endif; ?>
              aria-label="<?php echo esc_attr_x( 'Percent-encoding case', 'settings field label', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-off"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="off"
               <?php checked( $current, 'off' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-off">
                <?php echo esc_html_x( 'OFF', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-preserve"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="preserve"
               <?php checked( $current, 'preserve' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-preserve">
                <?php echo esc_html_x( 'PRESERVE', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-upper"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="upper"
               <?php checked( $current, 'upper' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-upper">
                <?php echo esc_html_x( 'UPPER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>

        <input class="nppp-segcontrol-radio nppp-pctnorm__radio" type="radio" id="pctnorm-lower"
               name="nginx_cache_settings[nginx_cache_pctnorm_mode]" value="lower"
               <?php checked( $current, 'lower' ); echo $is_disabled ? ' disabled' : ''; ?> />
        <label class="nppp-segcontrol-seg nppp-pctnorm__seg" for="pctnorm-lower">
               <?php echo esc_html_x( 'LOWER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
        </label>
        <span class="nppp-segcontrol-thumb nppp-pctnorm__thumb" aria-hidden="true"></span>
    </fieldset>

    <?php if ($is_disabled) : ?>
        <div class="nppp-related-pages" aria-live="polite">
            <div class="nppp-hint" role="note" style="max-width:max-content;">
                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                <?php echo esc_html( $status_note ); ?>
           </div>
        </div>
    <?php endif; ?>

    <p class="description" style="margin-top:6px;"><?php echo esc_html__( 'Fix cache misses caused by mixed-case percent-encoding during cache preloading (on-the-fly).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Different environments may send xx hex in different cases during cache preloading; Nginx treats these as different cache keys, which can cause misses.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Enable this if your URLs contain non-ASCII characters (Japanese/Chinese) or if you see xx-encoded bytes in paths.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Normalizing the hex case during cache preloading makes cache keys consistent and prevents Nginx cache misses after preloading completes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p>
    <p class="description"><?php echo esc_html__( 'Requirements: safexec installed (see the Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ); ?></p><br>
    <p class="description">
        <strong><?php echo esc_html_x('OFF', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php /* translators: %xx is a literal percent-encoded byte pattern, e.g. %2F — do not translate */ ?>
          <?php echo esc_html__( 'Use when your URLs are ASCII-only and you never see %xx bytes.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <p class="description">
        <strong><?php echo esc_html_x('PRESERVE', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php echo esc_html__( 'Normalize percent-encoding without changing hex case (keeps original upper/lower). Good default when encoded bytes appear but case consistency is unknown.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <p class="description">
        <strong><?php echo esc_html_x('UPPER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php /* translators: %xx is a literal percent-encoded byte pattern, e.g. %2F — do not translate */ ?>
          <?php echo esc_html__( 'Force %xx hex to uppercase during preloading to stay consistent with browser behaviour.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <p class="description">
        <strong><?php echo esc_html_x('LOWER', 'toggle option', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong>
        - <?php /* translators: %xx is a literal percent-encoded byte pattern, e.g. %2F — do not translate */ ?>
          <?php echo esc_html__( 'Force %xx hex to lowercase during preloading to stay consistent with browser behaviour.', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
    </p>
    <?php
}

// Callback function for REST API
function nppp_nginx_cache_api_callback() {
    $options = get_option('nginx_cache_settings', []);
    $api_checked = isset($options['nginx_cache_api']) && $options['nginx_cache_api'] === 'yes' ? 'checked="checked"' : '';

    ?>
    <input type="checkbox" name="nginx_cache_settings[nginx_cache_api]" class="nppp-onoffswitch-checkbox-api" value='yes' id="nginx_cache_api" <?php echo esc_attr($api_checked); ?>>
    <label class="nppp-onoffswitch-label-api" for="nginx_cache_api">
        <span class="nppp-onoffswitch-inner-api">
            <span class="nppp-off-api"><?php echo esc_html__( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-api"><?php echo esc_html__( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-api"></span>
    </label>
    <?php
}

// Callback function for HTTP Purge
function nppp_http_purge_enabled_callback(): void {
    $options      = get_option( 'nginx_cache_settings', [] );
    $is_checked   = isset( $options['nppp_http_purge_enabled'] ) && $options['nppp_http_purge_enabled'] === 'yes';
    $checked      = $is_checked ? 'checked="checked"' : '';

    ?>
    <input type="checkbox"
           name="nginx_cache_settings[nppp_http_purge_enabled]"
           class="nppp-onoffswitch-checkbox-httppurge"
           value="yes"
           id="nppp_http_purge_enabled"
           <?php echo esc_attr( $checked ); ?>>
    <label class="nppp-onoffswitch-label-httppurge" for="nppp_http_purge_enabled">
        <span class="nppp-onoffswitch-inner-httppurge">
            <span class="nppp-off-httppurge"><?php esc_html_e( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-httppurge"><?php esc_html_e( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-httppurge"></span>
    </label>
    <?php
}

// Callback function for HTTP Purge Suffix
function nppp_http_purge_suffix_callback(): void {
    $options = get_option( 'nginx_cache_settings', [] );
    echo "<input type='text' id='nppp_http_purge_suffix' name='nginx_cache_settings[nppp_http_purge_suffix]' value='" . esc_attr( $options['nppp_http_purge_suffix'] ?? 'purge' ) . "' class='regular-text' placeholder='purge' />";
}

// Callback function for HTTP Purge Custom URL
function nppp_http_purge_custom_url_callback(): void {
    $options = get_option( 'nginx_cache_settings', [] );
    echo "<input type='text' id='nppp_http_purge_custom_url' name='nginx_cache_settings[nppp_http_purge_custom_url]' value='" . esc_attr( $options['nppp_http_purge_custom_url'] ?? '' ) . "' class='regular-text' placeholder='https://docker/purge' />";
}

// Callback function for RG Purge
function nppp_rg_purge_enabled_callback(): void {
    $options    = get_option( 'nginx_cache_settings', [] );

    // Check rg availability
    $cached = get_transient( 'nppp_rg_ok' );
    if ( $cached === false ) {
        $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
        $rg_ok  = $rg_bin !== '' && is_executable( $rg_bin );
        set_transient( 'nppp_rg_ok', [ 'path' => $rg_bin, 'ok' => $rg_ok ], HOUR_IN_SECONDS );
    } else {
        $rg_bin = $cached['path'];
        $rg_ok  = $cached['ok'];
    }

    $is_disabled = ! $rg_ok;
    $is_checked  = ! $is_disabled && isset( $options['nppp_rg_purge_enabled'] ) && $options['nppp_rg_purge_enabled'] === 'yes';

    // Force option off if rg disappeared.
    if ( $is_disabled && isset( $options['nppp_rg_purge_enabled'] ) && $options['nppp_rg_purge_enabled'] === 'yes' ) {
        $options['nppp_rg_purge_enabled'] = 'no';
        update_option( 'nginx_cache_settings', $options );
        $is_checked = false;
    }

    if ( ! $rg_bin ) {
        $status_note = esc_html__( 'Unavailable: ripgrep (rg) not found. Install it to enable RG Purge (see Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif ( ! $rg_ok ) {
        $status_note = esc_html__( 'Unavailable: ripgrep (rg) binary is not executable. Check permissions.', 'fastcgi-cache-purge-and-preload-nginx' );
    } else {
        $status_note = '';
    }

    $checked  = $is_checked ? 'checked="checked"' : '';
    $disabled = $is_disabled ? ' disabled' : '';
    ?>
    <input type="checkbox"
           name="nginx_cache_settings[nppp_rg_purge_enabled]"
           class="nppp-onoffswitch-checkbox-rgpurge"
           value="yes"
           id="nppp_rg_purge_enabled"
           <?php echo esc_attr( $checked ); ?><?php echo esc_attr( $disabled ); ?>>
    <label class="nppp-onoffswitch-label-rgpurge" for="nppp_rg_purge_enabled">
        <span class="nppp-onoffswitch-inner-rgpurge">
            <span class="nppp-off-rgpurge"><?php esc_html_e( 'OFF', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            <span class="nppp-on-rgpurge"><?php esc_html_e( 'ON', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
        </span>
        <span class="nppp-onoffswitch-switch-rgpurge"></span>
    </label>
    <?php if ( $is_disabled ) : ?>
        <div class="nppp-related-pages" aria-live="polite" style="margin-top:6px;">
            <div class="nppp-hint" role="note" style="max-width:max-content;">
                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                <?php echo esc_html( $status_note ); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php
}
