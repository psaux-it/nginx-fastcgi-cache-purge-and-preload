/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains code to disable plugin functionality in unsupported environments for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.0
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Disable NPP functionality in unsupported environments
(function($) {
    'use strict';

    $(document).ready(function() {
        // Select the buttons in the admin bar with unique names
        var npppAllButtons = {
            npppPreload: $('#wp-admin-bar-preload-cache'),
            npppPurge: $('#wp-admin-bar-purge-cache'),
            npppStatus: $('#wp-admin-bar-fastcgi-cache-status'),
            npppAdvanced: $('#wp-admin-bar-fastcgi-cache-advanced')
        };

        // Function to disable a single button
        function npppDisableButtonSingle(npppButton) {
            if (npppButton.length) {
                npppButton.off('click');
                $(document).off('click', `#${npppButton.attr('id')}`);

                npppButton.find('a')
                    .removeAttr('href')
                    .css({
                        'opacity': '0.5',
                        'cursor': 'not-allowed'
                    })
                    .on('click', function(event) {
                        event.preventDefault();
                    });
            }
        }

        // Function to disable all buttons at once
        function npppDisableButtonAll() {
            $.each(npppAllButtons, function(_, npppBtn) {
                npppDisableButtonSingle(npppBtn);
            });
        }

        // Disable all buttons
        npppDisableButtonAll();

        // Disable WP dashboard widget buttons
        $('.nppp-action-button').addClass('disabled').removeAttr('href');

        // Check if we're on the plugin settings page and disable plugin functionality
        if ($('#nppp-nginx-tabs').length > 0) {
            // Disable Purge and Preload buttons on settings page
            $('.nppp-button').addClass('disabled').removeAttr('href');

            // Disable status and advanced tabs
            $('#nppp-nginx-tabs').tabs({
                disabled: [1, 2]
            });

            // Disable the schedule checkbox
            $('#nginx_cache_schedule').prop('disabled', true);

            // disable the rest api checkbox
            $('#nginx_cache_api').prop('disabled', true);

            // disable the schedule set cron button
            $('#nginx-cache-schedule-set').prop('disabled', true);

            // disable auto preload checkbox
            $('#nginx_cache_auto_preload').prop('disabled', true);

            // disable preload mobile checkbox
            $('#nginx_cache_auto_preload_mobile').prop('disabled', true);

            // disable send mail checkbox
            $('#nginx_cache_send_mail').prop('disabled', true);

            // disable auto purge checkbox
            $('#nginx_cache_purge_on_update').prop('disabled', true);

            // disable reset regex button
            $('#nginx-regex-reset-defaults').prop('disabled', true);

            // disable reset file extension button
            $('#nginx-extension-reset-defaults').prop('disabled', true);

            // disable reset cache key regex button
            $('#nginx-key-regex-reset-defaults').prop('disabled', true);

            // disable clear logs button
            $('#clear-logs-button').prop('disabled', true);

            // disable generate API key button
            $('#api-key-button').prop('disabled', true);

            // disable the rest API elements non-clickable
            $('#nppp-api-key .nppp-tooltip, #nppp-purge-url .nppp-tooltip, #nppp-preload-url .nppp-tooltip').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // ensure the parent <p> tags are also non-clickable
            $('#nppp-api-key, #nppp-purge-url, #nppp-preload-url').css({
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

            // disable main form submit button
            $('input[type="submit"][name="nppp_submit"].button-primary').prop('disabled', true);
        }
    });
})(jQuery);
