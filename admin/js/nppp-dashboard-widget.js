/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains wp dashboard widget preloader effect for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.9
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// NPP WP dashboard widget preloader
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
    });
})(jQuery);
