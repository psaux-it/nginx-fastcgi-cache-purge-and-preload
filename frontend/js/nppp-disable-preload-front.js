/**
 * Frontend preload-guard scripts for Nginx Cache Purge Preload
 * Description: Prevents restricted frontend preload actions from being executed.
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Disable WP admin bar actions for front-end
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Select the buttons
        var npppAllButtonsfront = {
            npppPreload: $('#wp-admin-bar-preload-cache'),
            preloadButtonSinglefront: $('#wp-admin-bar-preload-cache-single')
        };

        // Function to disable a single button
        function npppDisableButtonSinglefront(npppButton) {
            if (npppButton.length) {
                npppButton.off('click');
                $(document).off('click', `#${npppButton.attr('id')}`);

                npppButton.find('a')
                    .removeAttr('href')
                    .css({
                        'opacity': '0.5',
                        'cursor': 'not-allowed'
                    })
                    .on('click', function (event) {
                        event.preventDefault();
                    });
            }
        }

        // Function to disable all buttons
        function npppDisableButtonAllfront() {
            $.each(npppAllButtonsfront, function (_, npppBtn) {
                npppDisableButtonSinglefront(npppBtn);
            });
        }

        // Disable all buttons
        npppDisableButtonAllfront();
    });
})(jQuery);
