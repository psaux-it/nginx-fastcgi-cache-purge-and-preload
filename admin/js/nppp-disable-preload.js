/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains code to disable Preload features in unsupported environments for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.0
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Disable NPP Preload features in unsupported environments
(function($) {
    'use strict';

    // Function to disable preload button on Advanced Tab
    function NppdisablePreloadButtons() {
        $('.nppp-preload-btn').each(function() {
            var $btn = $(this);

            // Remove all jQuery-attached click event listeners
            $btn.off('click');

            // Remove any inline onclick attribute
            $btn.removeAttr('onclick');

            // Disable the button using the native disabled property
            $btn.prop('disabled', true);

            // Apply CSS to show the button as disabled
            $btn.css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

            // Add a unique class to flag it
            $btn.addClass('nppp-general');
        });
    }

    $(document).ready(function() {
        // Disable the Preload button in the WP admin bar
        var $preloadButton = $('#wp-admin-bar-preload-cache');
        if ($preloadButton.length) {
            // Remove all click events
            $preloadButton.off('click');

            // Disable click behavior
            $preloadButton.find('a')
                .removeAttr('href')
                .css({
                    'opacity': '0.5',
                    'cursor': 'not-allowed'
                });
        }

        // Disable the Preload button on the Dashboard Widget
        $('.nppp-action-button[data-action="nppp-widget-preload"]')
            .addClass('disabled')
            .removeAttr('href')
            .css({
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
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // Ensure the parent <p> tags are also non-clickable for rest API preload stuff
            $('#nppp-preload-url').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // style cron status heading
            $('.nppp-active-cron-heading').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });
        }
    });

    // Disable the Preload button on the Advanced Tab
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.data && settings.data.includes('action=nppp_load_premium_content')) {
            NppdisablePreloadButtons();
        }
    });
})(jQuery);
