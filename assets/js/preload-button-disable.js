/**
 * JavaScript for Nginx FastCGI Cache Purge and Preload
 * Description: This JavaScript file contains function to disable Preload action if wget command not found on the host.
 * Version: 1.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

jQuery(document).ready(function($) {
    // Select the preload button in the admin bar
    var preloadButton = $('#wp-admin-bar-preload-cache');

    // Check if the preload button exists
    if (preloadButton.length > 0) {
        // Disable the button
        preloadButton.find('a').css('pointer-events', 'none');
        preloadButton.find('a').click(function(event) {
            event.preventDefault();
        });
    }
});

jQuery.noConflict();
