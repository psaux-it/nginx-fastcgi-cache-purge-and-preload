/**
 * JavaScript for Nginx FastCGI Cache Purge and Preload
 * Description: This JavaScript file contains functions to manage Nginx FastCGI Cache Purge and Preload plugin and interact with WordPress admin dashboard.
 * Version: 1.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
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
            // Hide help tab
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

                // Show the corresponding tab content
                if (targetId === '#settings') {
                    $('#settings').addClass('active').show();
                } else if (targetId === '#status') {
                    // Check if status content has been loaded already
                    if ($('#status').hasClass('loaded')) {
                        $('#status').addClass('active').show();
                    } else {
                        // AJAX request for "Status" tab content
                        $.ajax({
                            url: nginx_cache_ajax_object.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'my_status_ajax',
                                _wpnonce: nginx_cache_ajax_object.status_ajax_nonce
                            },
                            success: function(response) {
                                if (response.trim() !== '') {
                                    $('#status').html(response).addClass('active').addClass('loaded').show();
                                } else {
                                    console.error('Empty response received');
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error(error);
                            }
                        });
                    }
                } else {
                    $('#help').addClass('active').show();
                }
            });
        }
    });
})(jQuery);
