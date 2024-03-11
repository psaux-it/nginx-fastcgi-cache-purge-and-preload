/**
 * JavaScript for Nginx FastCGI Cache and Preload For Wordpress plugin
 * Description: This JavaScript file contains functions to manage Nginx FastCGI Cache and Preload For Wordpress plugin and interact with WordPress admin dashboard.
 * Version: 1.0
 * Author: Hasan CALISIR | hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL2, WordPress copyright GPL.
 */

jQuery(document).ready(function($) {
    // Update option value when checkbox state changes
    $('#nginx_cache_send_mail').change(function() {
        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nginx_cache_ajax_object.ajaxurl, {
            action: 'update_send_mail_option',
            send_mail: isChecked,
            _wpnonce: nginx_cache_ajax_object.send_mail_nonce // Use localized nonce for send mail option
        }, function(response) {
            // Check if the option is updated successfully
            if (response.success) {
                // Do nothing, option is updated
            } else {
                // Revert checkbox state
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
});
