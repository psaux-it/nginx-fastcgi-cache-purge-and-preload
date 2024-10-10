/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript file contains function to disable Preload action if wget command not found on the host for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

jQuery(document).ready(function($) {
    // Select the preload button in the admin bar
    var preloadButton = $('#wp-admin-bar-preload-cache');

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
});
