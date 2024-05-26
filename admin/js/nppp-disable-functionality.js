/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains disable functionality for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */
jQuery(document).ready(function($) {
    // Select the preload button in the admin bar
    var preloadButton = $('#wp-admin-bar-preload-cache');
    var purgeButton = $('#wp-admin-bar-purge-cache');
    var statusButton = $('#wp-admin-bar-fastcgi-cache-status');

    // Select the status and advanced tabs
    var statusTab = $('#nppp-nginx-tabs > ul > li:nth-child(2) a');
    var advancedTab = $('#nppp-nginx-tabs > ul > li:nth-child(3) a');

    // Check if the preload button exists and disable it
    if (preloadButton.length > 0) {
        // Disable the button
        preloadButton.find('a').css('pointer-events', 'none');
        preloadButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }

    // Check if the purge button exists and disable it
    if (purgeButton.length > 0) {
        // Disable the button
        purgeButton.find('a').css('pointer-events', 'none');
        purgeButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }

     // Check if the purge button exists and disable it
    if (statusButton.length > 0) {
        // Disable the button
        statusButton.find('a').css('pointer-events', 'none');
        statusButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }

    // Disable Purge and Preload buttons on settings page
    $('.nppp-button').addClass('disabled').removeAttr('href');

    // Check if we're on the plugin settings page
    if ($('#nppp-nginx-tabs').length > 0) {
        // Disable status and advanced tabs
        $('#nppp-nginx-tabs').tabs({
            disabled: [1, 2] // Indexes of the tabs to be disabled (starting from 0)
        });
    }

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

    // disable reset regex button
    $('#nginx-regex-reset-defaults').prop('disabled', true);

    // disable clear logs button
    $('#clear-logs-button').prop('disabled', true);

    // disable clear logs button
    $('#api-key-button').prop('disabled', true);

    // disable rest url stuff
    $('#nppp-api-key .nppp-tooltip, #nppp-purge-url .nppp-tooltip, #nppp-preload-url .nppp-tooltip').css({
        'pointer-events': 'none',
        'opacity': '0.5',
        'cursor': 'not-allowed'
     }).off('click').on('click', function(event) {
        event.preventDefault();
    });

    $('.nppp-active-cron-heading').css({
        'pointer-events': 'none',
        'opacity': '0.5',
        'cursor': 'not-allowed'
    });
});
