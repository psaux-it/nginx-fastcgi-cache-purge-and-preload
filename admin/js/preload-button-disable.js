/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript code disables Preload features in unsupported environments for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.9
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Disable NPP Preload features in unsupported environments
(function($) {
    'use strict';

    $(document).ready(function() {
        // Disable the Preload button in the WP admin bar
        var preloadButton = $('#wp-admin-bar-preload-cache');

        if (preloadButton.length > 0) {
            preloadButton.find('a').css({
                'pointer-events': 'none',
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

            preloadButton.find('a').click(function(event) {
                event.preventDefault();
            });
        }

        // Disable the Preload button on the Dashboard Widget
        $('.nppp-action-button[data-action="nppp-widget-preload"]')
            .addClass('disabled')
            .removeAttr('href')
            .css({
                'pointer-events': 'none',
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

        // Check if we're on the plugin settings page to disable Preload-related features
        if ($('#nppp-nginx-tabs').length > 0) {
            // Disable the Preload button on the Settings page
            $('#nppp-preload-button').addClass('disabled').removeAttr('href');

            // Disable auto preload
            $('#nginx_cache_auto_preload').prop('disabled', true);

            // Disable preload mobile
            $('#nginx_cache_auto_preload_mobile').prop('disabled', true);

            // Disable the preload wp schedule
            $('#nginx_cache_schedule').prop('disabled', true);

            // Disable rest API preload stuff
            $('#nppp-preload-url .nppp-tooltip').css({
                'pointer-events': 'none',
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).off('click').on('click', function(event) {
                event.preventDefault();
            });

            // style cron status heading
            $('.nppp-active-cron-heading').css({
                'pointer-events': 'none',
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });
        }
    });
})(jQuery);
