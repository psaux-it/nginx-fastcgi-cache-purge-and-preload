/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains disable functionality for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */
jQuery(document).ready(function($) {
    // Select the buttons in the admin bar
    var preloadButton = $('#wp-admin-bar-preload-cache');
    var purgeButton = $('#wp-admin-bar-purge-cache');
    var statusButton = $('#wp-admin-bar-fastcgi-cache-status');

    // Check if the preload button exists and disable it
    if (preloadButton.length > 0) {
        // Disable the button
        preloadButton.find('a').css({
            'pointer-events': 'none',
            'opacity': '0.5',
            'cursor': 'not-allowed'
        });

        // Prevent default click behavior
        preloadButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }

    // Check if the purge button exists and disable it
    if (purgeButton.length > 0) {
        // Disable the button
        purgeButton.find('a').css({
            'pointer-events': 'none',
            'opacity': '0.5',
            'cursor': 'not-allowed'
        });

        // Prevent default click behavior
        purgeButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }

    // Check if the status button exists and disable it
    if (statusButton.length > 0) {
        // Disable the button
        statusButton.find('a').css({
            'pointer-events': 'none',
            'opacity': '0.5',
            'cursor': 'not-allowed'
        });

        // Prevent default click behavior
        statusButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }

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

        // disable send mail checkbox
        $('#nginx_cache_send_mail').prop('disabled', true);

        // disable auto purge checkbox
        $('#nginx_cache_purge_on_update').prop('disabled', true);

        // disable reset regex button
        $('#nginx-regex-reset-defaults').prop('disabled', true);

        // disable clear logs button
        $('#clear-logs-button').prop('disabled', true);

        // disable generate API key button
        $('#api-key-button').prop('disabled', true);

        // disable rest API stuff
        $('#nppp-api-key .nppp-tooltip, #nppp-purge-url .nppp-tooltip, #nppp-preload-url .nppp-tooltip').css({
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

        // disable main form submit button
        $('input[type="submit"][name="submit"].button-primary').prop('disabled', true);
    }
});
