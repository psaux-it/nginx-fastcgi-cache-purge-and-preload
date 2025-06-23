/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains wp dashboard widget preloader effect for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.2
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// NPP WP dashboard widget
(function ($) {
    'use strict';

    $(document).ready(function () {
        const $preloader = $('#nppp-loader-overlay');

        // Function to show the preloader overlay
        // Adds the 'active' class and fades in the preloader over 50 milliseconds
        function showPreloader() {
            $('.nppp-loader-fill').css({
                'animation': 'nppp-fill 2s ease-in-out infinite'
            });

            // Remove backdrop filters from `#nppp-loader-overlay` by setting them to 'none'
            $('#nppp-loader-overlay').css({
                'backdrop-filter': 'none',
                '-webkit-backdrop-filter': 'none',
                'transition': 'none'
            });

            // Add 'active' class and fade in the preloader
            $preloader.addClass('active').fadeIn(50);
        }

        // Handle click events on widget action buttons
        $('.nppp-action-button').on('click', function (e) {
            const action = $(this).data('action');

            if (action === 'nppp-widget-purge' || action === 'nppp-widget-preload') {
                showPreloader();
            }
        });

        // Handle click events on "Purge All" and "Preload All" admin bar items
        $('.nppp-action-trigger').on('click', function() {
            showPreloader();
        });

        // Display status of ongoing Preload action
        function npppCheckPreloadStatus() {
            var npppPreloadInProgress = $('#nppp-preload-in-progress').length > 0;

            // Get the Preload All button related elements
            var npppPreloadButton = $('a[data-action="nppp-widget-preload"]');
            var npppPreloadIcon = npppPreloadButton.find('span');

            // Set the default styles
            var defaultStyles = {
                'font-size': '14px',
                'color': 'white',
                'background-color': '#3CB371',
                'padding': '8px 12px',
                'text-decoration': 'none',
                'font-weight': '500',
                'display': 'inline-flex',
                'align-items': 'center',
                'justify-content': 'center',
                'transition': 'background-color 0.3s ease',
                'flex': '48%',
                'opacity': '1',
                'pointer-events': 'auto',
            };

            // Check for the ongoing Preload process
            if (npppPreloadInProgress) {
                npppPreloadButton.css('color', 'orange');
                npppPreloadButton.css('pointer-events', 'none');
                //npppPreloadButton.css('transition', 'none');
                npppPreloadButton.prop('href', '#');

                // Start blinking effect
                npppstartBlinking(npppPreloadButton);

                // Change icon to clock
                npppPreloadIcon.removeClass('dashicons-update').addClass('dashicons-clock');
            } else {
                // Get the current background color
                var currentBackgroundColor = npppPreloadButton.css('background-color');

                // Revert styles only if they were previously modified
                if (currentBackgroundColor !== 'rgb(60, 179, 113)') {

                    // Apply the default styles
                    npppPreloadButton.css(defaultStyles);

                    // Apply default href
                    npppPreloadButton.prop('href', function() {
                        return $('a[data-action="nppp-widget-preload"]').data('href');
                    });

                    // Stop the blinking effect
                    npppstopBlinking(npppPreloadButton);

                    // Revert icon back
                    npppPreloadIcon.removeClass('dashicons-clock').addClass('dashicons-update');
                }
            }
        }

        // Function to start blinking
        function npppstartBlinking(element) {
            element.css('animation', 'nppp-blink 2s infinite alternate');
            element.css('animation-timing-function', 'ease-in-out');
        }

        // Function to stop blinking
        function npppstopBlinking(element) {
            element.css('animation', 'none');
        }

        // Call main
        npppCheckPreloadStatus();
    });
})(jQuery);
