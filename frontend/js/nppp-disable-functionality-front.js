/**
 * Frontend JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript file contains functions that disabling frontpage admin bar actions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// disable front end on page purge preload admin bar actions
jQuery(document).ready(function($) {
    var preloadButton = $('#wp-admin-bar-preload-cache');
    var purgeButton = $('#wp-admin-bar-purge-cache');
    var statusButton = $('#wp-admin-bar-fastcgi-cache-status');
    var purgeButtonSingle = $('#wp-admin-bar-purge-cache-single');
    var preloadButtonSingle = $('#wp-admin-bar-preload-cache-single');

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

    // Check if the purge single button exists and disable it
    if (purgeButtonSingle.length > 0) {
        // Disable the button
        purgeButtonSingle.find('a').css({
            'pointer-events': 'none',
            'opacity': '0.5',
            'cursor': 'not-allowed'
        });

        // Prevent default click behavior
        purgeButtonSingle.find('a').click(function(event) {
            event.preventDefault();
        });
    }

    // Check if the preload single button exists and disable it
    if (preloadButtonSingle.length > 0) {
        // Disable the button
        preloadButtonSingle.find('a').css({
            'pointer-events': 'none',
            'opacity': '0.5',
            'cursor': 'not-allowed'
        });

        // Prevent default click behavior
        preloadButtonSingle.find('a').click(function(event) {
            event.preventDefault();
        });
    }
});
