/**
 * JavaScript for Nginx FastCGI Cache and Preload For Wordpress plugin
 * Description: This JavaScript file contains functions to manage Nginx FastCGI Cache and Preload For Wordpress plugin and interact with WordPress admin dashboard.
 * Version: 1.0
 * Author: Hasan CALISIR | hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL2, WordPress copyright GPL.
 */

jQuery.noConflict();

(function($) {
    // JavaScript to handle send mail notifications tickbox
    $(document).ready(function() {
        // Update option value when checkbox state changes
        $('#nginx_cache_send_mail').change(function() {
            var isChecked = $(this).prop('checked') ? 'yes' : 'no';
            $.post(nginx_cache_ajax_object.ajaxurl, {
                action: 'update_send_mail_option',
                send_mail: isChecked,
                _wpnonce: nginx_cache_ajax_object.send_mail_nonce
            }, function(response) {
                // Handle response
                if (response.success) {
                    // Option updated successfully
                } else {
                    // Error updating option, revert checkbox
                    $('#nginx_cache_send_mail').prop('checked', !$('#nginx_cache_send_mail').prop('checked'));
                    alert('Error updating option!');
                }
            });
        });

        // JavaScript to handle clearing logs
        $('#clear-logs-button').click(function() {
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    $('#nginx-cache-logs-container').html(xhr.responseText);
                }
            };
            xhr.open('GET', nginx_cache_ajax_object.ajaxurl + '?action=clear_nginx_cache_logs&_wpnonce=' + nginx_cache_ajax_object.nonce, true);
            xhr.send();
        });

        // JavaScript to handle tab switching
        // Run only in the my plugin settings page
        if ($('#nginx_cache_settings_nonce').length > 0) {
            // Initially hide the help content
            $('#help').hide();

            // Add event listener for tab clicks
            $('.nav-tab').click(function(e) {
                e.preventDefault();

                var targetId = $(this).attr('href');

                // Remove active class from all tabs and tab contents
                $('.nav-tab').removeClass('nav-tab-active');
                $('.tab-content').removeClass('active');

                // Add the active class to the clicked tab
                $(this).addClass('nav-tab-active');

                // Hide all tab contents
                $('.tab-content').hide();

                // If the clicked tab is the "Settings" tab
                if (targetId === '#settings') {
                    // Show the settings content
                    $('#settings').addClass('active').show();
                } else {
                    // Show the help content
                    $('#help').addClass('active').show();
                }
            });
        }
    });
})(jQuery);
