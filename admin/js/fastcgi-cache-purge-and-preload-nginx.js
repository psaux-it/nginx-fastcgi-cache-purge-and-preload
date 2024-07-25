/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript file contains functions to manage FastCGI Cache Purge and Preload for Nginx plugin and interact with WordPress admin dashboard.
 * Version: 2.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

jQuery(document).ready(function($) {
    // Initialize jQuery UI tabs
    $('#nppp-nginx-tabs').tabs({
        activate: function(event, ui) {
            var tabId = ui.newPanel.attr('id');

            // Handle specific actions for each tab
            if (tabId === 'settings') {
                // Check if the Settings tab is already active
                if (!ui.newPanel.hasClass('ui-tabs-active')) {
                    $('#settings-content-placeholder').html('<div class="nppp-loading-spinner"></div>');

                    // Reload the settings page to create cache
                    location.reload();
                }
            } else if (tabId === 'status') {
                loadStatusTabContent();
            } else if (tabId === 'premium') {
                loadPremiumTabContent();
            }
        },
        beforeLoad: function(event, ui) {
            ui.jqXHR.fail(function() {
                ui.panel.html("Couldn't load this tab. We'll try to fix this as soon as possible.");
            });
        }
    });

    // Load status content if user comes from wordpress admin bar directly
    if (window.location.hash === '#status') {
        loadStatusTabContent();
    }

    // Function to load content for the 'Status' tab via AJAX
    function loadStatusTabContent() {
        // Show loading spinner
        $('#status-content-placeholder').html('<div class="nppp-loading-spinner"></div>');

        // AJAX request for the "Status" tab content
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_cache_status',
                _wpnonce: nppp_admin_data.cache_status_nonce
            },
            success: function(response) {
                if (response.trim() !== '') {
                    // Replace loading spinner with content
                    $('#status-content-placeholder').html(response).show();

                    // Update status metrics after the content is inserted into the DOM
                    npppupdateStatus();
                } else {
                    console.error('Empty response received');
                }

                // Recalculate scroll positions and sizes
                $(window).trigger('resize').trigger('scroll');
            },
            error: function(xhr, status, error) {
                console.error(error);
            }
        });
    }

    // Function to load content for the 'Premium' tab via AJAX
    function loadPremiumTabContent() {
        // Show loading spinner
        $('#premium-content-placeholder').html('<div class="nppp-loading-spinner"></div>');

        // AJAX request for the "Premium" tab content
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_load_premium_content',
                _wpnonce: nppp_admin_data.premium_content_nonce
            },
            success: function(response) {
                // Replace loading spinner with content
                $('#premium-content-placeholder').html(response);

                // Initialize DataTables.js for premium table
                initializePremiumTable();

                // Recalculate column widths for responsive layout
                $('#nppp-premium-table').DataTable().responsive.recalc();

                // Show the content
                $('#premium-content-placeholder').show();

                // Recalculate scroll positions and sizes
                $(window).trigger('resize').trigger('scroll');
            },
            error: function(xhr, status, error) {
                console.error(status + ': ' + error);
            }
        });
    }

    // Handle click event for purge buttons in advanced tab
    $(document).on('click', '.nppp-purge-btn', function() {
        // Get the data
        var btn = $(this);
        var filePath = btn.data('file');
        var row = btn.closest('tr');

        // Send confirmation
        if (confirm('Are you sure you want to purge cache?')) {
            // AJAX request to purge the file
            $.ajax({
                url: nppp_admin_data.ajaxurl,
                type: 'POST',
                data: {
                    action: 'nppp_purge_cache_premium',
                    file_path: filePath,
                    _wpnonce: nppp_admin_data.premium_nonce_purge
                },
                success: function(response) {
                    // Check if the response indicates success
                    if (response.success) {
                        // Display a success message
                        alert(response.data);

                        // Check if the row is expanded on mobile
                        if (row.hasClass('child')) {
                            // If the row is expanded, target the parent row
                            row = row.prev('tr');
                        }

                        // find the preload button
                        var preloadBtn;
                        if (row.hasClass('dtr-expanded')) {
                            preloadBtn = row.next('.child').find('.nppp-preload-btn');
                        } else {
                            preloadBtn = row.find('.nppp-preload-btn');
                        }

                        // Disable the button
                        btn.prop('disabled', true);
                        // Add disabled style
                        btn.addClass('disabled');
                        // highlight preload action
                        preloadBtn.css('background-color', '#43A047');

                        // Enable preload button and reset its style
                        preloadBtn.prop('disabled', false);
                        preloadBtn.removeClass('disabled');
                        if (btn.css('background-color') === 'rgb(67, 160, 71)') {
                            btn.css('background-color', '');
                        }
                        // style the row for attention
                        $('tr.purged-row').removeClass('purged-row');
                        setTimeout(function() {
                            row.addClass('purged-row');
                        }, 0);
                    } else {
                        // Display an error message
                        alert(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Display an error message if the AJAX request fails
                    alert(error);
                }
            });
        }
    });

    // Handle click event for preload buttons in advanced tab
    $(document).on('click', '.nppp-preload-btn', function() {
        // Get the data
        var btn = $(this);
        var cacheUrl = btn.data('url');
        var row = btn.closest('tr');

        // Send confirmation
        if (confirm('Are you sure you want to preload cache?')) {
            // AJAX request to purge the file
            $.ajax({
                url: nppp_admin_data.ajaxurl,
                type: 'POST',
                data: {
                    action: 'nppp_preload_cache_premium',
                    cache_url: cacheUrl,
                    _wpnonce: nppp_admin_data.premium_nonce_preload
                },
                success: function(response) {
                    // Check if the response indicates success
                    if (response.success) {
                        // Display a success message
                        alert(response.data);

                        // Check if the row is expanded on mobile
                        if (row.hasClass('child')) {
                            // If the row is expanded, target the parent row
                            row = row.prev('tr');
                        }

                        // find the preload button
                        var purgeBtn;
                        if (row.hasClass('dtr-expanded')) {
                            purgeBtn = row.next('.child').find('.nppp-purge-btn');
                        } else {
                            purgeBtn = row.find('.nppp-purge-btn');
                        }

                        // Disable the button
                        btn.prop('disabled', true);
                        // Add disabled style
                        btn.addClass('disabled');
                        // highlight preload action
                        purgeBtn.css('background-color', '#43A047');

                        // Enable purge button and reset its style
                        purgeBtn.prop('disabled', false);
                        purgeBtn.removeClass('disabled');
                        if (btn.css('background-color') === 'rgb(67, 160, 71)') {
                            btn.css('background-color', '');
                        }
                        // style the row for attention
                        $('tr.purged-row').removeClass('purged-row');
                        setTimeout(function() {
                            row.addClass('purged-row');
                        }, 0);
                    } else {
                        // Display an error message
                        alert(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Display an error message if the AJAX request fails
                    alert(error);
                }
            });
        }
    });

    // Update send mail status when state changes
    $('#nginx_cache_send_mail').change(function() {
        // Calculate the notification position
        var sendMailElement = jQuery(this);
        var clickToCopySpanMail = sendMailElement.next('.nppp-onoffswitch-label');
        var clickToCopySpanOffsetMail = clickToCopySpanMail.offset();
        var notificationLeftMail = clickToCopySpanOffsetMail.left + clickToCopySpanMail.outerWidth() + 10;
        var notificationTopMail = clickToCopySpanOffsetMail.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_send_mail_option',
            send_mail: isChecked,
            _wpnonce: nppp_admin_data.send_mail_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successful saved option
                var notification = document.createElement('div');
                notification.textContent = 'Saved';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftMail + 'px';
                notification.style.top = notificationTopMail + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 1000);
            } else {
                // Error updating option, revert checkbox
                $('#nginx_cache_send_mail').prop('checked', !$('#nginx_cache_send_mail').prop('checked'));
                alert('Error updating option!');
            }
        });
    });

    // Update auto preload status when state changes
    $('#nginx_cache_auto_preload').change(function() {
        // Calculate the notification position
        var autoPreloadElement = jQuery(this);
        var clickToCopySpanAutoPreload = autoPreloadElement.next('.nppp-onoffswitch-label-preload');
        var clickToCopySpanOffsetAutoPreload = clickToCopySpanAutoPreload.offset();
        var notificationLeftAutoPreload = clickToCopySpanOffsetAutoPreload.left + clickToCopySpanAutoPreload.outerWidth() + 10;
        var notificationTopAutoPreload = clickToCopySpanOffsetAutoPreload.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_auto_preload_option',
            auto_preload: isChecked,
            _wpnonce: nppp_admin_data.auto_preload_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = 'Saved';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftAutoPreload + 'px';
                notification.style.top = notificationTopAutoPreload + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 1000);
            } else {
                // Error updating option, revert checkbox
                $('#nginx_cache_auto_preload').prop('checked', !$('#nginx_cache_auto_preload').prop('checked'));
                alert('Error updating option!');
            }
        });
    });

    // Update auto purge status when state changes
    $('#nginx_cache_purge_on_update').change(function() {
        // Calculate the notification position
        var autoPurgeElement = jQuery(this);
        var clickToCopySpanAutoPurge = autoPurgeElement.next('.nppp-onoffswitch-label-autopurge');
        var clickToCopySpanOffsetAutoPurge = clickToCopySpanAutoPurge.offset();
        var notificationLeftAutoPurge = clickToCopySpanOffsetAutoPurge.left + clickToCopySpanAutoPurge.outerWidth() + 10;
        var notificationTopAutoPurge = clickToCopySpanOffsetAutoPurge.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_auto_purge_option',
            auto_purge: isChecked,
            _wpnonce: nppp_admin_data.auto_purge_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = 'Saved';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftAutoPurge + 'px';
                notification.style.top = notificationTopAutoPurge + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 1000);
            } else {
                // Error updating option, revert checkbox
                $('#nginx_cache_purge_on_update').prop('checked', !$('#nginx_cache_purge_on_update').prop('checked'));
                alert('Error updating option!');
            }
        });
    });

    // Update rest api status when state changes
    $('#nginx_cache_api').change(function() {
        // Calculate the notification position
        var restApiElement = jQuery(this);
        var clickToCopySpanRestApi = restApiElement.next('.nppp-onoffswitch-label-api');
        var clickToCopySpanOffsetRestApi = clickToCopySpanRestApi.offset();
        var notificationLeftRestApi = clickToCopySpanOffsetRestApi.left + clickToCopySpanRestApi.outerWidth() + 10;
        var notificationTopRestApi = clickToCopySpanOffsetRestApi.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_api_option',
            nppp_api: isChecked,
            _wpnonce: nppp_admin_data.api_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = 'Saved';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftRestApi + 'px';
                notification.style.top = notificationTopRestApi + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 1000);
            } else {
                // Error updating option, revert checkbox
                $('#nginx_cache_api').prop('checked', !$('#nginx_cache_api').prop('checked'));
                alert('Error updating option!');
            }
        });
    });

    // Click event handler for the #nginx-cache-schedule-set button
    // Update schedule option values accordingly
    $('#nginx-cache-schedule-set').on('click', function() {
        event.preventDefault();

        var npppcronEvent = $('#nppp_cron_event').val();
        var nppptime = $('#nppp_datetimepicker1Input').val();

        // Validate npppcronEvent on client side
        if (!npppcronEvent || (npppcronEvent !== 'daily' && npppcronEvent !== 'weekly' && npppcronEvent !== 'monthly')) {
            alert('Please select cron schedule frequency "On Every"');
            return;
        }

        // Validate nppptime format on client side
        var timeRegex = /^([01]\d|2[0-3]):([0-5]\d)$/;
        if (!nppptime || !nppptime.match(timeRegex)) {
            alert('Please select cron "Time"');
            return;
        }

        // AJAX request to send data to server with nonce
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_get_save_cron_expression',
                nppp_cron_freq: npppcronEvent,
                nppp_time: nppptime,
                _wpnonce: nppp_admin_data.get_save_cron_nonce
            },
            success: function(response) {
                // Handle success response
                console.log(response);

                // Fetch and display updated scheduled event
                $.ajax({
                    url: nppp_admin_data.ajaxurl,
                    type: 'GET',
                    data: {
                        action: 'nppp_get_active_cron_events_ajax',
                        _wpnonce: nppp_admin_data.get_save_cron_nonce
                    },
                    success: function(response) {
                        // Clear the existing content
                        $('.scheduled-events-list').empty();

                        // Append the new content
                        response.data.forEach(function(event) {
                            var html = '<div class="nppp-scheduled-event">';
                            html += '<h3 class="nppp-active-cron-heading">Cron Status</h3>';
                            html += '<div class="nppp-cron-info">';
                            html += '<span class="nppp-hook-name">Cron Name: <strong>' + event.hook_name + '</strong></span> - ';
                            html += '<span class="nppp-next-run">Next Run: <strong>' + event.next_run + '</strong></span>';
                            html += '</div>';
                            html += '<div class="nppp-cancel-btn-container">';
                            html += '<button class="nppp-cancel-btn" data-hook="' + event.hook_name + '">Cancel</button>';
                            html += '</div>';
                            html += '</div>';

                            // DOM injection, update content
                            $('.scheduled-events-list').append(html);

                            // Find the newly added timestamp element and apply the highlight effect
                            var $newTimestamp = $('.scheduled-events-list').find('.nppp-next-run:last');

                            // Effect styles
                            $newTimestamp.css({
                                "background-color": "darkorange",
                                "color": "white"
                            });

                            // Effect duration
                            var duration = 100; // Duration of each flash
                            var numFlashes = 8; // Number of flashes
                            for (var i = 0; i < numFlashes; i++) {
                                $newTimestamp.fadeOut(duration).fadeIn(duration);
                            }

                            // Remove the highlight after a short delay
                            setTimeout(function() {
                                $newTimestamp.css({
                                    "background-color": "",
                                    "color": ""
                                });
                            }, numFlashes * duration * 2);
                        });
                    },
                    error: function(xhr, status, error) {
                        // Handle error
                        console.error(xhr.responseText);
                    }
                });
            },
            error: function(xhr, status, error) {
                $('.scheduled-events-list').empty();
                var html = '<div class="nppp-scheduled-event">';
                html += '<h3 class="nppp-active-cron-heading">Cron Status</h3>';
                html += '<div class="nppp-scheduled-event" style="padding-right: 45px;">Please set your Timezone in Wordpress - Options/General!</div>';
                html += '</div>';
                $('.scheduled-events-list').append(html);
                console.error(xhr.responseText);
            }
        });
    });

    // Initially disable the "Set Cron" button if the checkbox is unchecked
    if (!$('#nginx_cache_schedule').prop('checked')) {
        $('#nginx-cache-schedule-set').prop('disabled', true);
    }

    // Update cache schedule status when state changes
    $('#nginx_cache_schedule').change(function() {
        // Calculate the notification position
        var cacheScheduleElement = jQuery(this);
        var clickToCopySpanCacheSchedule = cacheScheduleElement.next('.nppp-onoffswitch-label-schedule');
        var clickToCopySpanOffsetCacheSchedule = clickToCopySpanCacheSchedule.offset();
        var notificationLeftCacheSchedule = clickToCopySpanOffsetCacheSchedule.left + clickToCopySpanCacheSchedule.outerWidth() + 10;
        var notificationTopCacheSchedule = clickToCopySpanOffsetCacheSchedule.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';

        // Disable or enable the "Set Cron" button based on toggle switch status
        $('#nginx-cache-schedule-set').prop('disabled', isChecked === 'no');

        // If the toggle switch is turned on, re-enable the "Set Cron" button
        if (isChecked === 'yes') {
            $('#nginx-cache-schedule-set').prop('disabled', false);
        }

        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_cache_schedule_option',
            cache_schedule: isChecked,
            _wpnonce: nppp_admin_data.cache_schedule_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = 'Saved';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftCacheSchedule + 'px';
                notification.style.top = notificationTopCacheSchedule + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 1000);

                switch (response.data) {
                    case 'Option updated successfully. Unschedule success.':
                        $('.scheduled-events-list').empty();
                        var html = '<div class="nppp-scheduled-event">';
                        html += '<h3 class="nppp-active-cron-heading">Cron Status</h3>';
                        html += '<div class="nppp-scheduled-event" style="padding-right: 45px;">No active scheduled events found!</div>';
                        html += '</div>';
                        $('.scheduled-events-list').append(html);
                        break;
                    case 'Option updated successfully. No event found.':
                        // Handle case where no existing event was found
                        break;
                    case 'Option updated successfully.':
                        // Handle generic success case
                        break;
                    default:
                        // Handle unknown response
                        break;
                }
            } else {
                // Error updating option, revert checkbox
                $('#nginx_cache_schedule').prop('checked', !$('#nginx_cache_schedule').prop('checked'));
                alert('Error updating option!');
            }
        }, 'json');
    });

    // Click event handler for cancel event button
    // We need event delegation here
    $(document).on('click', '.nppp-cancel-btn', function() {
        event.preventDefault();
        var hook = $(this).data('hook');

        // Confirm cancellation
        if (confirm('Are you sure you want to cancel the scheduled event "' + hook + '"?')) {
            // AJAX request to cancel the scheduled event
            $.ajax({
                url: nppp_admin_data.ajaxurl,
                type: 'POST',
                data: {
                    action: 'nppp_cancel_scheduled_event',
                    hook: hook,
                    _wpnonce: nppp_admin_data.cancel_scheduled_event_nonce
                },
                success: function(response) {
                    // Handle success response
                    $('.scheduled-events-list').empty();
                    var html = '<div class="nppp-scheduled-event">';
                    html += '<h3 class="nppp-active-cron-heading">Cron Status</h3>';
                    html += '<div class="nppp-scheduled-event" style="padding-right: 45px;">No active scheduled events found!</div>';
                    html += '</div>';
                    $('.scheduled-events-list').append(html);
                    console.log(response);
                },
                error: function(xhr, status, error) {
                    // Handle error
                    console.error(xhr.responseText);
                    alert('An error occurred while canceling the scheduled event.');
                }
            });
        }
    });

    // Clear logs on back-end and update them on front-end
    $('#clear-logs-button').on('click', function(event) {
        event.preventDefault();

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'GET',
            data: {
                action: 'nppp_clear_nginx_cache_logs',
                _wpnonce: nppp_admin_data.clear_logs_nonce
            },
            success: function(response) {
                // Logs cleared successfully
                // Trigger polling to get latest content
                $.ajax({
                    url: nppp_admin_data.ajaxurl,
                    type: 'GET',
                    data: {
                        action: 'nppp_get_nginx_cache_logs',
                        _wpnonce: nppp_admin_data.clear_logs_nonce
                    },
                    success: function(response) {
                        // Parse the timestamp and message from the response
                        var data = response.data;
                        var timestamp = data.substring(1, 20);
                        var message = data.substring(23);
                        // Construct HTML for logs container
                        var html = '<div class="logs-container">' +
                                       '<div class="success-line"><span class="timestamp">' + timestamp + '</span> ' + message + '</div>' +
                                       '<div class="cursor blink">#</div>' +
                                   '</div>';

                        // Update the content area with the new logs HTML
                        $('.logs-container').html(html);
                    },
                    error: function(xhr, status, error) {
                        console.error('Error getting log content:', status, error);
                    }
                });
            },
            // Error clearing logs
            error: function(xhr, status, error) {
                console.error('AJAX request failed:', status, error);
            }
        });
    });

    // Make AJAX request to update API key option
    $('#api-key-button').on('click', function(event) {
        event.preventDefault();

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            data: {
                action: 'nppp_update_api_key_option',
                _wpnonce: nppp_admin_data.api_key_nonce
            },
            success: function(response) {
                // Check if AJAX request was successful
                if (response.success) {
                    // Update input field with the new API key
                    $('#nginx_cache_api_key').val(response.data);
                } else {
                    // Display error message if AJAX request failed
                    console.error(response.data);
                }
            },
            error: function(xhr, status, error) {
                // Display error message if AJAX request encounters an error
                console.error(error);
            }
        });
    });

    // Make AJAX request to update default reject regex
    $('#nginx-regex-reset-defaults').on('click', function(event) {
        event.preventDefault();

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            data: {
                action: 'nppp_update_default_reject_regex_option',
                _wpnonce: nppp_admin_data.reject_regex_nonce
            },
            success: function(response) {
                // Check if AJAX request was successful
                if (response.success) {
                    // Update input field with the new API key
                    $('#nginx_cache_reject_regex').val(response.data);
                } else {
                    // Display error message if AJAX request failed
                    console.error(response.data);
                }
            },
            error: function(xhr, status, error) {
                // Display error message if AJAX request encounters an error
                console.error(error);
            }
        });
    });

    // Event handler for the clear plugin cache button
    $(document).off('click', '#nppp-clear-plugin-cache-btn').on('click', '#nppp-clear-plugin-cache-btn', function(e) {
        e.preventDefault();

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_clear_plugin_cache',
                _wpnonce: nppp_admin_data.plugin_cache_nonce
            },
            success: function(response) {
                var $messageElement = $('.clear-plugin-cache p');

                // Apply the response data to the <p> tag
                if (response.success) {
                    $messageElement.html(response.data);
                } else {
                    $messageElement.html('<p>An error occurred while clearing the plugin cache: ' + response.data + '</p>');
                }

                // Effect styles
                $messageElement.css({
                    "background-color": "darkorange",
                    "color": "white"
                });

                // Effect duration
                var duration = 100;
                var numFlashes = 8;

                // Flashing effect
                for (var i = 0; i < numFlashes; i++) {
                    $messageElement.fadeOut(duration).fadeIn(duration);
                }

                // Remove the highlight after a short delay
                setTimeout(function() {
                    $messageElement.css({
                        "background-color": "",
                        "color": ""
                    });

                    // Re-trigger recursive permission check
                    // and cache the result
                    location.reload();
                }, numFlashes * duration * 2);
            },
            error: function(xhr, status, error) {
                var $messageElement = $('.clear-plugin-cache p');
                $messageElement.html('<p>An error occurred while clearing the plugin cache: ' + error + '</p>');

                // Effect styles
                $messageElement.css({
                    "background-color": "darkorange",
                    "color": "white"
                });

                // Effect duration
                var duration = 100;
                var numFlashes = 8;

                // Flashing effect
                for (var i = 0; i < numFlashes; i++) {
                    $messageElement.fadeOut(duration).fadeIn(duration);
                }

                // Remove the highlight after a short delay
                setTimeout(function() {
                    $messageElement.css({
                        "background-color": "",
                        "color": ""
                    });
                }, numFlashes * duration * 2);
            }
        });
    });

    // Function to initialize DataTables.js for premium table
    function initializePremiumTable() {
        $('#nppp-premium-table').DataTable({
            autoWidth: false,
            responsive: true,
            paging: true,
            ordering: true,
            searching: true,
            lengthMenu: [10, 25, 50, 100],
            pageLength: 10,
            language: {
                // Customize text for pagination and other UI elements
                paginate: {
                    first: 'First',
                    last: 'Last',
                    next: '&#8594;',
                    previous: '&#8592;'
                },
                search: 'Search:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries',
                infoFiltered: '(filtered from _MAX_ total entries)'
            },

            // Set column widths
            columnDefs: [
                { width: "30%", targets: 0 },
                { width: "40%", targets: 1 },
                { width: "15%", targets: 2 },
                { width: "15%", targets: 3 },
                { responsivePriority: 1, targets: 0 },
                { responsivePriority: 2, targets: -1 },
                { defaultContent: "", targets: "_all" }
            ]
        });

        // Hide empty cells
        hideEmptyCells();
    }

    // Function to hide empty cells
    function hideEmptyCells() {
        // Get all the table rows
        var rows = document.querySelectorAll('#nppp-premium-table > tbody > tr');

        // Loop through each row
        rows.forEach(function(row) {
            // Get the cells from the second, third, and fourth columns
            var cells = [
                row.querySelector('td:nth-child(2)'),
                row.querySelector('td:nth-child(3)'),
                row.querySelector('td:nth-child(4)')
            ];

            // Loop through each cell
            cells.forEach(function(cell) {
                // Check if the cell is empty
                if (cell.textContent.trim() === '') {
                    // If empty, hide the cell
                    cell.style.display = 'none';
                }
            });
        });
    }

    // Toggle switch rules for send mail
    var isChecked = $('#nginx_cache_send_mail').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch').css('background', '#66b317');
        $('.nppp-on').css('color', '#ffffff');
        $('.nppp-off').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch').css('background', '#ea1919');
        $('.nppp-on').css('color', '#000000');
        $('.nppp-off').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_send_mail').change(function() {
        // Check if the checkbox is checked
        var isChecked = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch').css('background', '#66b317');
            $('.nppp-on').css('color', '#ffffff');
            $('.nppp-off').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch').css('background', '#ea1919');
            $('.nppp-on').css('color', '#000000');
            $('.nppp-off').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for auto preload
    var isChecked = $('#nginx_cache_auto_preload').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-preload').css('background', '#66b317');
        $('.nppp-on-preload').css('color', '#ffffff');
        $('.nppp-off-preload').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-preload').css('background', '#ea1919');
        $('.nppp-on-preload').css('color', '#000000');
        $('.nppp-off-preload').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_auto_preload').change(function() {
        // Check if the checkbox is checked
        var isChecked = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-preload').css('background', '#66b317');
            $('.nppp-on-preload').css('color', '#ffffff');
            $('.nppp-off-preload').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-preload').css('background', '#ea1919');
            $('.nppp-on-preload').css('color', '#000000');
            $('.nppp-off-preload').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for REST API
    var isChecked = $('#nginx_cache_api').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-api').css('background', '#66b317');
        $('.nppp-on-api').css('color', '#ffffff');
        $('.nppp-off-api').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-api').css('background', '#ea1919');
        $('.nppp-on-api').css('color', '#000000');
        $('.nppp-off-api').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_api').change(function() {
        // Check if the checkbox is checked
        var isChecked = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-api').css('background', '#66b317');
            $('.nppp-on-api').css('color', '#ffffff');
            $('.nppp-off-api').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-api').css('background', '#ea1919');
            $('.nppp-on-api').css('color', '#000000');
            $('.nppp-off-api').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for Schedule Cache
    var isChecked = $('#nginx_cache_schedule').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-schedule').css('background', '#66b317');
        $('.nppp-on-schedule').css('color', '#ffffff');
        $('.nppp-off-schedule').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-schedule').css('background', '#ea1919');
        $('.nppp-on-schedule').css('color', '#000000');
        $('.nppp-off-schedule').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_schedule').change(function() {
        // Check if the checkbox is checked
        var isChecked = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-schedule').css('background', '#66b317');
            $('.nppp-on-schedule').css('color', '#ffffff');
            $('.nppp-off-schedule').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-schedule').css('background', '#ea1919');
            $('.nppp-on-schedule').css('color', '#000000');
            $('.nppp-off-schedule').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for auto purge
    var isChecked = $('#nginx_cache_purge_on_update').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-autopurge').css('background', '#66b317');
        $('.nppp-on-autopurge').css('color', '#ffffff');
        $('.nppp-off-autopurge').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-autopurge').css('background', '#ea1919');
        $('.nppp-on-autopurge').css('color', '#000000');
        $('.nppp-off-autopurge').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_purge_on_update').change(function() {
        // Check if the checkbox is checked
        var isChecked = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-autopurge').css('background', '#66b317');
            $('.nppp-on-autopurge').css('color', '#ffffff');
            $('.nppp-off-autopurge').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-autopurge').css('background', '#ea1919');
            $('.nppp-on-autopurge').css('color', '#000000');
            $('.nppp-off-autopurge').css('color', '#ffffff');
        }
    });

    // Unique ID copy clipboard
    jQuery('#nppp-unique-id').click(function(event) {
        var uniqueIdElement = jQuery(this);
        var clickToRevealSpan = uniqueIdElement.find('span');
        var clickToRevealSpanOffset = clickToRevealSpan.offset();
        var notificationLeft = clickToRevealSpanOffset.left + clickToRevealSpan.outerWidth() + 10;
        var notificationTop = clickToRevealSpanOffset.top;

        var uniqueId = uniqueIdElement.data('unique-id');
        var tempInput = document.createElement('input');
        tempInput.value = uniqueId;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);

        // Show a small notification just after the 'Unique ID' text
        var notification = document.createElement('div');
        notification.textContent = 'Copied to clipboard';
        notification.style.position = 'absolute';
        notification.style.left = notificationLeft + 'px';
        notification.style.top = notificationTop + 'px';
        notification.style.backgroundColor = '#50C878';
        notification.style.color = '#fff';
        notification.style.padding = '2px 5px';
        notification.style.transition = 'opacity 0.3s ease-in-out';
        notification.style.opacity = '1';
        notification.style.zIndex = '9999';
        notification.style.fontSize = '12px';
        notification.style.fontWeight = '700';
        document.body.appendChild(notification);

        setTimeout(function() {
            notification.style.opacity = '0'; // Fade out
            setTimeout(function() {
                document.body.removeChild(notification); // Remove notification after fade out
            }, 300);
        }, 3000); // Remove notification after 3 seconds
    });

    // Click event handler for copying the API key to clipboard
    jQuery('#nppp-api-key').click(function(event) {
        var apiKeyElement = jQuery(this);
        var clickToCopySpan = apiKeyElement.find('span');
        var clickToCopySpanOffset = clickToCopySpan.offset();
        var notificationLeft = clickToCopySpanOffset.left + clickToCopySpan.outerWidth() + 10;
        var notificationTop = clickToCopySpanOffset.top;

        // Perform AJAX request to fetch the latest API key
        $.ajax({
            url:  nppp_admin_data.ajaxurl,
            type: 'GET',
            data: {
                action: 'nppp_update_api_key_copy_value',
                _wpnonce: nppp_admin_data.api_key_copy_nonce
            },
            success: function(response) {
                var apiKey = response.data.api_key;

                // Copy the API key to clipboard
                var tempInput = document.createElement('input');
                tempInput.value = apiKey;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);

                // Show a small notification indicating successful copy
                var notification = document.createElement('div');
                notification.textContent = 'Copied to clipboard';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '2px 5px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '12px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                setTimeout(function() {
                    notification.style.opacity = '0'; // Fade out
                    setTimeout(function() {
                        document.body.removeChild(notification); // Remove notification after fade out
                    }, 300);
                }, 3000); // Remove notification after 3 seconds
            },
            error: function(xhr, status, error) {
                console.error('Error fetching API key:', error);
            }
        });
    });

     // Click event handler for copying the API purge curl URL to clipboard
    jQuery('#nppp-purge-url').click(function(event) {
        var purgeUrlElement = jQuery(this);
        var clickToCopySpan = purgeUrlElement.find('span');
        var clickToCopySpanOffset = clickToCopySpan.offset();
        var notificationLeft = clickToCopySpanOffset.left + clickToCopySpan.outerWidth() + 10;
        var notificationTop = clickToCopySpanOffset.top;

        // Perform AJAX request to fetch the latest API key
        $.ajax({
            url:  nppp_admin_data.ajaxurl,
            type: 'GET',
            data: {
                action: 'nppp_rest_api_purge_url_copy',
                _wpnonce: nppp_admin_data.api_purge_url_copy_nonce
            },
            success: function(response) {
                var purgeUrl = response.data;

                // Copy the API key to clipboard
                var tempInput = document.createElement('input');
                tempInput.value = purgeUrl;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);

                // Show a small notification indicating successful copy
                var notification = document.createElement('div');
                notification.textContent = 'Copied to clipboard';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '2px 5px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '12px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                setTimeout(function() {
                    notification.style.opacity = '0'; // Fade out
                    setTimeout(function() {
                        document.body.removeChild(notification); // Remove notification after fade out
                    }, 300);
                }, 3000); // Remove notification after 3 seconds
            },
            error: function(xhr, status, error) {
                console.error('Error fetching API key:', error);
            }
        });
    });

     // Click event handler for copying the API purge curl URL to clipboard
    jQuery('#nppp-preload-url').click(function(event) {
        var preloadUrlElement = jQuery(this);
        var clickToCopySpan = preloadUrlElement.find('span');
        var clickToCopySpanOffset = clickToCopySpan.offset();
        var notificationLeft = clickToCopySpanOffset.left + clickToCopySpan.outerWidth() + 10;
        var notificationTop = clickToCopySpanOffset.top;

        // Perform AJAX request to fetch the latest API key
        $.ajax({
            url:  nppp_admin_data.ajaxurl,
            type: 'GET',
            data: {
                action: 'nppp_rest_api_preload_url_copy',
                _wpnonce: nppp_admin_data.api_preload_url_copy_nonce
            },
            success: function(response) {
                var preloadUrl = response.data;

                // Copy the API key to clipboard
                var tempInput = document.createElement('input');
                tempInput.value = preloadUrl;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);

                // Show a small notification indicating successful copy
                var notification = document.createElement('div');
                notification.textContent = 'Copied to clipboard';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '2px 5px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '12px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                setTimeout(function() {
                    notification.style.opacity = '0'; // Fade out
                    setTimeout(function() {
                        document.body.removeChild(notification); // Remove notification after fade out
                    }, 300);
                }, 3000); // Remove notification after 3 seconds
            },
            error: function(xhr, status, error) {
                console.error('Error fetching API key:', error);
            }
        });
    });

    // Initialize jQuery UI accordion
    $("#nppp-accordion").accordion({
        collapsible: true,
        heightStyle: "content"
    });

    // Hide the disabled option on page load
    $('.nppp-cron-event-select option[value=""][disabled]').hide();

    // Hide the disabled option when the dropdown is opened
    $('#nppp_cron_event').on('click', function() {
        $('.nppp-cron-event-select option[value=""][disabled]').hide();
    });

    $('#nppp-purge-button, #nppp-preload-button').on('click', function(event) {
    // Prevent the default click behavior
    event.preventDefault();

    // Disable the clicked button
    $(this).prop('disabled', true);

    // Store the URL of the button's destination
    var url = $(this).attr('href');

    // Set a timeout to reload the page after 2 seconds
    setTimeout(function() {
        window.location.href = url; // Reload the page with the stored URL
    }, 2000); // 2000 milliseconds = 2 seconds
    });
});

/*!
 * Tempus Dominus Date Time Picker v6.9.4
 * https://tempusdominus.github.io/bootstrap-4/
 * Copyright 2021 Tempus Dominus
 * Licensed under MIT (https://github.com/tempusdominus/bootstrap-4/blob/main/LICENSE)
 */
document.addEventListener("DOMContentLoaded", function() {
    new tempusDominus.TempusDominus(document.getElementById('nppp_datetimepicker1Input'), {
        display: {
            viewMode:'clock',
            components: {
                date:false,
                month:false,
                year:false,
                decades:false,
                hours:true,
                minutes:true,
                seconds:false,
            },
            icons: {
                time: 'dashicons dashicons-arrow-up-alt2',
                up: 'dashicons dashicons-arrow-up-alt2',
                down: 'dashicons dashicons-arrow-down-alt2'
            },
            inline: false,
            theme: 'auto'
        },
        localization: {
            dateFormats: {
                LT: 'HH:mm',
            },
            hourCycle: 'h23',
            format: 'LT'
        },
        stepping: 5
    });
});

// position vertically middle nppp ad
jQuery(document).ready(function($) {
    // Function to vertically position #nppp-ad
    function positionAdVertically() {
        // Get the height of .nginx-status element
        var statusHeight = $('section.nginx-status').outerHeight();

        // Get the height of the container
        var containerHeight = $('#nppp-nginx-info').outerHeight();

        // Calculate the available height
        var availableHeight = containerHeight - statusHeight;

        // Get the height of #nppp-ad element
        var adHeight = $('#nppp-ad').outerHeight();

        // Calculate the margin-top to vertically center #nppp-ad
        var marginTop = (availableHeight - adHeight) / 2 - 20;

        // Set the margin-top property
        $('#nppp-ad').css('margin-top', marginTop + 'px');
    }

    // Call the function initially and on window resize
    positionAdVertically();
    $(window).resize(positionAdVertically);
});

// trim trailing leading white spaces from inputs
document.addEventListener('DOMContentLoaded', function () {
    // IDs of input fields to apply trimming
    const inputIds = ['#nginx_cache_path', '#nginx_cache_email', '#nginx_cache_reject_regex', '#nginx_cache_api_key'];

    inputIds.forEach(function(inputId) {
        const inputField = document.querySelector(inputId);

        if (inputField) {
            // Function to trim the input value
            function trimInputValue() {
                inputField.value = inputField.value.trim();
            }

            // Trim the input value when the user leaves the input field (on blur)
            inputField.addEventListener('blur', trimInputValue);

            // Trim the input value on paste or when the user types in the input field
            inputField.addEventListener('input', function () {
                setTimeout(trimInputValue, 0);
            });

            // Trim the input value just before the form is submitted
            const form = inputField.closest('form');
            if (form) {
                form.addEventListener('submit', trimInputValue);
            }
        }
    });
});

// Function to remove status_message + message_type query parameters from redirected URL on plugin settings page
function removeQueryParameters(parameters) {
    var url = window.location.href;
    var urlParts = url.split('?');
    if (urlParts.length >= 2) {
        var baseUrl = urlParts[0];
        var queryParameters = urlParts[1].split('&');
        var updatedParameters = [];
        for (var i = 0; i < queryParameters.length; i++) {
            var parameter = queryParameters[i].split('=');
            if (parameters.indexOf(parameter[0]) === -1) {
                updatedParameters.push(queryParameters[i]);
            }
        }
        return baseUrl + '?' + updatedParameters.join('&');
    }
    return url;
}

// Clean the redirected URL immediately after page load
document.addEventListener('DOMContentLoaded', function() {
    var updatedUrl = removeQueryParameters(['status_message', 'message_type']);
    history.replaceState(null, document.title, updatedUrl);
});

// update status tab metrics
function npppupdateStatus() {
    // Fetch and update php fpm status
    var phpFpmRow = document.querySelector("#npppphpFpmStatus").closest("tr");
    var npppphpFpmStatusSpan = document.getElementById("npppphpFpmStatus");
    var npppphpFpmStatus = npppphpFpmStatusSpan.textContent.trim();

    // Log the fetched status to debug
    console.log("Fetched status:", npppphpFpmStatus);

    npppphpFpmStatusSpan.textContent = npppphpFpmStatus;
    npppphpFpmStatusSpan.style.fontSize = "14px";
    if (npppphpFpmStatus === "false") {
        npppphpFpmStatusSpan.style.color = "red";
        npppphpFpmStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Required (Check Help)';
    } else {
        npppphpFpmStatusSpan.style.color = "green";
        npppphpFpmStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Not Required';
    }

    // Fetch and update pages in cache count
    var npppcacheInPageSpan = document.getElementById("npppphpPagesInCache");
    var npppcacheInPageSpanValue = npppcacheInPageSpan.textContent.trim();
    npppcacheInPageSpan.style.fontSize = "14px";
    if (npppcacheInPageSpanValue === "Undetermined") {
        npppcacheInPageSpan.style.color = "red";
        npppcacheInPageSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Permission Issue';
    } else if (npppcacheInPageSpanValue === "0") {
        npppcacheInPageSpan.style.color = "orange";
        npppcacheInPageSpan.innerHTML = '<span class="dashicons dashicons-clock"></span> ' + npppcacheInPageSpanValue;
    } else {
        npppcacheInPageSpan.style.color = "green";
        npppcacheInPageSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> ' + npppcacheInPageSpanValue;
    }

    // Fetch and update php process owner
    // PHP-FPM (website user)
    var npppphpProcessOwnerSpan = document.getElementById("npppphpProcessOwner");
    var npppphpProcessOwner = npppphpProcessOwnerSpan.textContent.trim();
    npppphpProcessOwnerSpan.textContent = npppphpProcessOwner;
    npppphpProcessOwnerSpan.style.fontSize = "14px";
    npppphpProcessOwnerSpan.style.color = "green";
    npppphpProcessOwnerSpan.innerHTML = '<span class="dashicons dashicons-arrow-right-alt" style="font-size: 16px;"></span> ' + npppphpProcessOwner;

    // Fetch and update web server user
    // WEB-SERVER (webserver user)
    var npppphpWebServerSpan = document.getElementById("npppphpWebServer");
    var npppphpWebServer = npppphpWebServerSpan.textContent.trim();
    npppphpWebServerSpan.textContent = npppphpWebServer;
    npppphpWebServerSpan.style.fontSize = "14px";
    npppphpWebServerSpan.style.color = "green";
    npppphpWebServerSpan.innerHTML = '<span class="dashicons dashicons-arrow-right-alt" style="font-size: 16px;"></span> ' + npppphpWebServer;

    // Fetch and update nginx cache path status
    var npppcachePathSpan = document.getElementById("npppcachePath");
    var npppcachePath = npppcachePathSpan.textContent.trim();
    npppcachePathSpan.textContent = npppcachePath;
    npppcachePathSpan.style.fontSize = "14px";
    if (npppcachePath === "Found") {
        npppcachePathSpan.style.color = "green";
        npppcachePathSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Found';
    } else if (npppcachePath === "Not Found") {
        npppcachePathSpan.style.color = "red";
        npppcachePathSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Found';
    }

    // Fetch and update purge action status
    var nppppurgeStatusSpan = document.getElementById("nppppurgeStatus");
    var nppppurgeStatus = nppppurgeStatusSpan.textContent.trim();
    nppppurgeStatusSpan.textContent = nppppurgeStatus;
    nppppurgeStatusSpan.style.fontSize = "14px";
    if (nppppurgeStatus === "true") {
        nppppurgeStatusSpan.style.color = "green";
        nppppurgeStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Ready';
    } else if (nppppurgeStatus === "false") {
        nppppurgeStatusSpan.style.color = "red";
        nppppurgeStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Ready';
    } else {
        nppppurgeStatusSpan.style.color = "orange";
        nppppurgeStatusSpan.innerHTML = '<span class="dashicons dashicons-clock"></span> Not Determined';
    }

    // Fetch and update purge shell_exec status
    var npppshellExecSpan = document.getElementById("npppshellExec");
    var npppshellExec = npppshellExecSpan.textContent.trim();
    npppshellExecSpan.textContent = npppshellExec;
    npppshellExecSpan.style.fontSize = "14px";
    if (npppshellExec === "Ok") {
        npppshellExecSpan.style.color =  "green";
        npppshellExecSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Allowed';
    } else if (npppshellExec === "Not Ok") {
        npppshellExecSpan.style.color = "red";
        npppshellExecSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Allowed';
    }

    // Fetch and update ACLs status
    var npppaclStatusSpan = document.getElementById("npppaclStatus");
    var npppaclStatus = npppaclStatusSpan.textContent.trim();
    npppaclStatusSpan.textContent = npppaclStatus;
    npppaclStatusSpan.style.fontSize = "14px";
    if (npppaclStatus.includes("Granted")) {
        npppaclStatusSpan.style.color = "green";
        // Extract and display the process owner information if present
        var processOwner = npppaclStatus.replace("Granted", "").trim();
        npppaclStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Granted ' + (processOwner ? `<span style="color:darkorange;">${processOwner}</span>` : '');
    } else if (npppaclStatus.includes("Need Action")) {
        npppaclStatusSpan.style.color = "red";
        npppaclStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Need Action (Check Help)';
    } else {
        npppaclStatusSpan.style.color = "orange";
        npppaclStatusSpan.innerHTML = '<span class="dashicons dashicons-clock"></span> Not Determined';
    }

    // Fetch and update preload action status
    var nppppreloadStatusRow = document.querySelector("#nppppreloadStatus").closest("tr");
    var nppppreloadStatusCell = nppppreloadStatusRow.querySelector("#nppppreloadStatus");
    var nppppreloadStatusSpan = document.getElementById("nppppreloadStatus");
    var nppppreloadStatus = nppppreloadStatusSpan.textContent.trim();
    nppppreloadStatusSpan.textContent = nppppreloadStatus;
    nppppreloadStatusSpan.style.fontSize = "14px";
    if (nppppreloadStatus === "true") {
        nppppreloadStatusSpan.style.color = "green";
        nppppreloadStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Ready';
    } else if (nppppreloadStatus === "false") {
        nppppreloadStatusSpan.style.color = "red";
        nppppreloadStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Ready';
    } else {
        nppppreloadStatusSpan.style.color = "orange";
        nppppreloadStatusSpan.innerHTML = '<span class="dashicons dashicons-clock"></span> In Progress';
        nppppreloadStatusCell.style.backgroundColor = "lightgreen";
        // Blink animation
        nppppreloadStatusCell.animate([
            { backgroundColor: 'inherit' },
            { backgroundColor: '#90ee90' }
        ], {
            duration: 1000,
            iterations: Infinity,
            direction: 'alternate'
        });
    }

    // Fetch and update wget command status
    var npppwgetStatusSpan = document.getElementById("npppwgetStatus");
    var npppwgetStatus = npppwgetStatusSpan.textContent.trim();
    npppwgetStatusSpan.textContent = npppwgetStatus;
    npppwgetStatusSpan.style.fontSize = "14px";
    if (npppwgetStatus === "Installed") {
        npppwgetStatusSpan.style.color = "green";
        npppwgetStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Installed';
    } else if (npppwgetStatus === "Not Installed") {
        npppwgetStatusSpan.style.color = "red";
        npppwgetStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Installed';
    }

    // Fetch and update cpulimit command status
    var npppcpulimitStatusSpan = document.getElementById("npppcpulimitStatus");
    var npppcpulimitStatus = npppcpulimitStatusSpan.textContent.trim();
    npppcpulimitStatusSpan.textContent = npppcpulimitStatus;
    npppcpulimitStatusSpan.style.fontSize = "14px";
    if (npppcpulimitStatus === "Installed") {
        npppcpulimitStatusSpan.style.color = "green";
        npppcpulimitStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Installed';
    } else if (npppcpulimitStatus === "Not Installed") {
        npppcpulimitStatusSpan.style.color = "red";
        npppcpulimitStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Installed';
    }

    // Add spin effect to icons
    document.querySelectorAll('.status').forEach(status => {
        status.addEventListener('click', () => {
            status.querySelector('.dashicons').classList.add('spin');
            setTimeout(() => {
                status.querySelector('.dashicons').classList.remove('spin');
            }, 1000);
        });
    });
}
