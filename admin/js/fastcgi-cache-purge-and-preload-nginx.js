/**
 * JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains code to manage the plugin's admin interface for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.2
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// NPP plugin admin side main js code
(function ($) {
    'use strict';
    const { __, _x, _n, _nx } = wp.i18n;

$(document).ready(function() {
    // Selectors
    const $preloader = $('#nppp-loader-overlay');
    const $settingsPlaceholder = $('#settings-content-placeholder');
    const $statusPlaceholder = $('#status-content-placeholder');
    const $premiumPlaceholder = $('#premium-content-placeholder');
    const $helpPlaceholder = $('.nppp-premium-container');

    // UI tabs container and links
    const $nppTabs = $('#nppp-nginx-tabs');
    const $nppTabsLinks = $nppTabs.find('a');

    // Function to show the preloader overlay
    function showPreloader() {
        // Adjust the `.nppp-loader-fill` animation duration
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

    // Function to hide the preloader overlay
    function hidePreloader() {
        $preloader.removeClass('active').fadeOut(50);

        // Reset the `.nppp-loader-fill` animation duration back
        $('.nppp-loader-fill').css({
            'animation': 'nppp-fill 5s ease-in-out infinite'
        });

        // Re-apply backdrop filters to `#nppp-loader-overlay` with original values
        $('#nppp-loader-overlay').css({
            'backdrop-filter': 'blur(1px)',
            '-webkit-backdrop-filter': 'blur(1px)',
            'transition': 'opacity 0.3s ease, visibility 0.3s ease'
        });
    }

    // Flag to prevent the duplicate call to npppActivateTab()
    let isTabChangeFromHash = false;
    let npppCurrentObserver = null;

    // npp-submenu page load load effect
    function nppphighlightSubmenu(selector, totalTime) {
        var items = $(selector);

        // Check if the elements exist
        if (items.length === 0) {
            return;
        }

        var totalItems = items.length;
        var initialDelay = 350;
        var accelerationFactor = 0.6;
        var baseTimePerItem = (totalTime - initialDelay) / totalItems;
        var delay = initialDelay;

        // Loop through each item and apply the effect with varied timing
        items.each(function(index, element) {
            var item = $(element);

            // Add the 'active' class with staggered timing
            setTimeout(function() {
                item.addClass('active');

                // Remove the 'active' class after the base time duration
                setTimeout(function() {
                    item.removeClass('active');
                }, baseTimePerItem);
            }, delay);

            // Increase the delay for the next item to progressively speed up the animation
            delay += baseTimePerItem * accelerationFactor;
        });
    }

    // Function to handle tab content activation
    function npppActivateTab(tabId) {
        // Hide all content placeholders
        $settingsPlaceholder.hide();
        $statusPlaceholder.hide();
        $premiumPlaceholder.hide();
        $helpPlaceholder.hide();

        // Handle specific tab actions
        switch (tabId) {
            case 'settings':
                nppdisconnectObserver();
                $settingsPlaceholder.show();
                nppphighlightSubmenu('.nppp-submenu ul li a', 900);
                break;
            case 'status':
                showPreloader();
                loadStatusTabContent();
                // Warn the user if a systemd service restart
                // is required due to missing fuse cache path mounts
                const statusTabContent = document.querySelector('#status');
                if (statusTabContent) {
                    if (!npppCurrentObserver) {
                        nppobserveFuseStatusChange(statusTabContent);
                    }
                }
                break;
            case 'premium':
                showPreloader();
                nppdisconnectObserver();
                loadPremiumTabContent();
                break;
            case 'help':
                nppdisconnectObserver();
                $helpPlaceholder.show();
                break;
        }
    }

    // Function to observe changes in the "Status" tab
    function nppobserveFuseStatusChange(tabContent) {
        // Create a MutationObserver to observe the status content
        const observer = new MutationObserver((mutationsList, observer) => {
            const fuseStatusSpan = document.querySelector('#npppFuseMountStatus span:last-of-type');
            if (fuseStatusSpan) {
                // Compare the trimmed text
                if (fuseStatusSpan.textContent.trim() === 'Not Mounted') {
                    nppinsertWarningIcon(tabContent);
                }
                // Disconnect the observer after finding the status
                observer.disconnect();
            }
        });

        // Store the observer to be cleaned up later
        npppCurrentObserver = observer;

        // Start observing the tab content for changes in the DOM
        observer.observe(tabContent, { childList: true, subtree: true });
    }

    // Function to insert the warning icon into the "Status" tab
    function nppinsertWarningIcon(tabContent) {
        try {
            // Create the warning icon
            const attentionIcon = document.createElement('span');
            attentionIcon.className = 'dashicons dashicons-warning';
            attentionIcon.style.cssText = 'color: orange; margin-left: 8px; font-size: 28px;';

            // Ensure the restart button exists before inserting the icon
            const restartButton = document.querySelector('#nppp-restart-systemd-service-btn');
            if (restartButton) {
                restartButton.parentNode.insertBefore(attentionIcon, restartButton.nextSibling);

                // Apply blink effect
                const blinkEffect = attentionIcon.animate([
                    { opacity: 1 },
                    { opacity: 0 }
                 ], {
                    duration: 500,
                    iterations: Infinity,
                    direction: 'alternate'
                });

                // Stop blinking after 2 seconds
                setTimeout(() => {
                    blinkEffect.cancel();
                }, 2000);
            }
        } catch (error) {
            console.error('Error in warning icon insertion:', error);
        }
    }

    // Function to disconnect the observer
    function nppdisconnectObserver() {
        if (npppCurrentObserver) {
            npppCurrentObserver.disconnect();
            npppCurrentObserver = null;
        }
    }

    // Initialize jQuery UI tabs
    if (!$nppTabs.hasClass('ui-tabs')) {
        $nppTabs.tabs({
            activate: function(event, ui) {
                // Only trigger if it's a internal interaction (not direct link)
                if (!isTabChangeFromHash) {
                    const tabId = ui.newPanel.attr('id');
                    if (tabId) {
                        window.history.replaceState(null, null, `#${tabId}`);
                        npppActivateTab(tabId);
                    }
                }

                // Reset the flag after activation
                isTabChangeFromHash = false;
            },
            beforeLoad: function(event, ui) {
                const isActive = ui.tab.attr('aria-selected') === 'true' || ui.tab.closest('.ui-tabs-active').length > 0;
                // Cancel the default load action for inactive tabs
                if (!isActive) {
                    ui.jqXHR.abort();
                    ui.panel.html("");
                }
            }
        });
    }

    // Deep linking jQuery UI tabs
    function activateTabFromHash() {
        const hash = window.location.hash;

        if (hash) {
            // Set the flag to true because the tab change is triggered by the direct URL
            isTabChangeFromHash = true;

            const index = $nppTabsLinks.filter(`[href="${hash}"]`).parent().index();
            if (index !== -1) {
                $nppTabs.tabs("option", "active", index);
                npppActivateTab(hash.replace('#', ''));

                // Scroll to the top of the page
                window.scrollTo(0, 0);

                // Reset the flag after handling hash activation
                isTabChangeFromHash = false;
            }
        } else {
            // Set the default tab to 'Settings'
            npppActivateTab('settings');
        }
    }

    // Call on page load to handle deep linking (activate tab based on URL hash)
    activateTabFromHash();

    // Listen for hash changes (URL changes) and re-activate the correct tab
    $(window).on('hashchange', function() {
        activateTabFromHash();
    });

    // Preload progress status
    function fetchWgetProgress() {
        const barText = document.getElementById("wpt-bar-text");
        const bar = document.getElementById("wpt-bar-inner");
        const status = document.getElementById("wpt-status");
        if (!bar || !status) return;

        fetch(nppp_admin_data.wget_progress_api, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': nppp_admin_data.preload_progress_nonce
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            const estTotal = data.total || 500;
            let pct = Math.min(100, Math.round((data.checked / estTotal) * 100));

            if (data.status === "done") {
                pct = 100;
            }

            bar.style.width = pct + "%";
            if (barText) barText.textContent = pct + "%";

            let html = `
                <div class="nppp-progress-row">
                    <span class="nppp-label">${__('Processed URLs:', 'fastcgi-cache-purge-and-preload-nginx')}</span> <code>${data.checked}</code>
                    &nbsp;|&nbsp;
                    <span class="nppp-label">${__('Broken URLs (404):', 'fastcgi-cache-purge-and-preload-nginx')}</span> <code>${data.errors}</code>
                </div>
                <div class="nppp-progress-row">
                    <span class="nppp-label">${__('Last Processed Page:', 'fastcgi-cache-purge-and-preload-nginx')}</span>
                    <span class="nppp-url">${data.last_url}</span>
                </div>
            `;

            if (data.time) {
                html += `
                    <div class="nppp-progress-row">
                        <span class="nppp-label">${__('Time Elapsed:', 'fastcgi-cache-purge-and-preload-nginx')}</span>
                        <code>${data.time || '-'}</code>
                        &nbsp;|&nbsp;
                        <span class="nppp-label">${__('Last Preload:', 'fastcgi-cache-purge-and-preload-nginx')}</span>
                        <code>${data.last_preload_time || '-'}</code>
                    </div>
                `;
            }

            if (pct >= 100 || data.status === "done") {
                html += `<div class="nppp-done">✅ <span>${__('Preload Complete', 'fastcgi-cache-purge-and-preload-nginx')}</span></div>`;
            }

            status.innerHTML = html;

            const preloadStatusSpan = document.getElementById("nppppreloadStatus");
            if (preloadStatusSpan) {
                const preloadStatusCell = preloadStatusSpan.closest("td");
                preloadStatusSpan.textContent = '';
                preloadStatusSpan.style.fontSize = "14px";

                const iconSpan = document.createElement('span');
                let preloadStatusText = '';

                if (!preloadStatusCell._npppAnimation) {
                    preloadStatusCell._npppAnimation = null;
                }

                if (data.status === "done") {
                    preloadStatusSpan.style.color = "green";
                    iconSpan.classList.add("dashicons", "dashicons-yes");
                    preloadStatusText = ' ' + __('Ready', 'fastcgi-cache-purge-and-preload-nginx');

                    if (preloadStatusCell._npppAnimation) {
                        preloadStatusCell._npppAnimation.cancel();
                        preloadStatusCell._npppAnimation = null;
                    }

                    // Remove any running animation background
                    if (preloadStatusCell) {
                        preloadStatusCell.style.backgroundColor = "inherit";
                    }
                } else if (data.status === "running") {
                    preloadStatusSpan.style.color = "orange";
                    iconSpan.classList.add("dashicons", "dashicons-clock");
                    preloadStatusText = ' ' + __('In Progress', 'fastcgi-cache-purge-and-preload-nginx');

                    if (preloadStatusCell) {
                        preloadStatusCell.style.backgroundColor = "lightgreen";

                        if (preloadStatusCell._npppAnimation) {
                            preloadStatusCell._npppAnimation.cancel();
                        }

                        preloadStatusCell._npppAnimation = preloadStatusCell.animate([
                            { backgroundColor: '#90ee90' }
                        ], {
                            duration: 1,
                            iterations: 1
                        });
                    }
                } else {
                    preloadStatusSpan.style.color = "red";
                    iconSpan.classList.add("dashicons", "dashicons-no");
                    preloadStatusText = ' ' + __('Not Ready', 'fastcgi-cache-purge-and-preload-nginx');

                    if (preloadStatusCell._npppAnimation) {
                        preloadStatusCell._npppAnimation.cancel();
                        preloadStatusCell._npppAnimation = null;
                    }
                    preloadStatusCell.style.backgroundColor = "inherit";
                }

                preloadStatusSpan.appendChild(iconSpan);
                preloadStatusSpan.append(preloadStatusText);
            }

            if (pct < 100 && data.status === "running") {
                setTimeout(fetchWgetProgress, 500);
            }
        })
        .catch(err => {
            console.error('Fetch preload progress failed:', err);
        });
    }

    // Function to load content for the 'Status' tab via AJAX
    // Sends a POST request to the server to fetch the Status tab content
    function loadStatusTabContent() {
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
                    // Insert the response HTML into the Status tab placeholder
                    // Set initial opacity to 0 for fade-in effect and show the element
                    $statusPlaceholder.html(response).css('opacity', 0).show();

                    // Update status metrics or perform additional initialization after content is loaded
                    npppupdateStatus();

                    // Preloas status progress
                    setTimeout(() => {
                        fetchWgetProgress();
                    }, 100);

                    // Hide the preloader now that content is loaded
                    hidePreloader();

                    // Animate the opacity to 1 over 100 milliseconds for a fade-in effect
                    $statusPlaceholder.animate({ opacity: 1 }, 100);
                } else {
                    console.error('Empty response received');
                    // Hide the preloader since loading failed
                    hidePreloader();
                    // Replace placeholder with proper error message
                    $statusPlaceholder.html(`
                        <h2>Error Displaying Tab Content</h2>
                        <p class="nppp-advanced-error-message">Failed to initialize the Status TAB.</p>
                    `);
                    $statusPlaceholder.show();
                }
            },
            error: function(xhr, status, error) {
                console.error(error);
                // Hide the preloader since loading failed
                hidePreloader();
                // Replace placeholder with proper error message
                $statusPlaceholder.html(`
                    <h2>Error Displaying Tab Content</h2>
                    <p class="nppp-advanced-error-message">Failed to initialize the Status TAB.</p>
                `);
                $statusPlaceholder.show();
            }
        });
    }

    // Function to load content for the 'Premium' tab via AJAX
    // Sends a POST request to the server to fetch the Advanced tab content
    function loadPremiumTabContent() {
        // AJAX request for the "Premium" tab content
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_load_premium_content',
                _wpnonce: nppp_admin_data.premium_content_nonce
            },
            success: function(response) {
                if (response.trim() !== '') {
                    // Insert the response HTML into the Advanced tab placeholder
                    // Set initial opacity to 0 for fade-in effect
                    $premiumPlaceholder.html(response).css('opacity', 0).show();

                    // Initialize DataTables.js for the advanced table within the loaded content
                    initializePremiumTable();

                    // Recalculate column widths for responsive layout
                    $('#nppp-premium-table').DataTable().responsive.recalc();

                    // Hide the preloader now that content is loaded
                    hidePreloader();

                    // Animate the opacity to 1 over 200 milliseconds for a fade-in effect
                    $premiumPlaceholder.animate({ opacity: 1 }, 100);
                } else {
                    console.error(status + ': ' + error);
                    // Hide the preloader since loading failed
                    hidePreloader();
                    // Replace placeholder with proper error message
                    $premiumPlaceholder.html(`
                        <h2>Error Displaying Tab Content</h2>
                        <p class="nppp-advanced-error-message">Failed to initialize the Advanced TAB.</p>
                    `);
                    $premiumPlaceholder.show();
                }
            },
            error: function(xhr, status, error) {
                console.error(status + ': ' + error);
                // Hide the preloader since loading failed
                hidePreloader();
                // Replace placeholder with proper error message
                $premiumPlaceholder.html(`
                    <h2>Error Displaying Tab Content</h2>
                    <p class="nppp-advanced-error-message">Failed to initialize the Advanced TAB.</p>
                `);
                $premiumPlaceholder.show();
            }
        });
    }

    // Attach click event to the "Purge All" and "Preload All" menu items
    $('.nppp-action-trigger').on('click', function() {
        showPreloader();
    });

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

                        if (!preloadBtn.hasClass('nppp-general')) {
                            // highlight preload action
                            preloadBtn.css('background-color', '#43A047');

                            // Enable preload button and reset its style
                            preloadBtn.prop('disabled', false);
                            preloadBtn.removeClass('disabled');
                        }

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
                notification.textContent = '✔';
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
                notification.textContent = '✔';
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

    // Update enable proxy status when state changes
    $('#nginx_cache_preload_enable_proxy').change(function() {
        var proxyElement = jQuery(this);
        var clickToCopySpanProxy = proxyElement.next('.nppp-onoffswitch-label-proxy');
        var clickToCopySpanOffsetProxy = clickToCopySpanProxy.offset();
        var notificationLeftProxy = clickToCopySpanOffsetProxy.left + clickToCopySpanProxy.outerWidth() + 10;
        var notificationTopProxy = clickToCopySpanOffsetProxy.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_enable_proxy_option',
            enable_proxy: isChecked,
            _wpnonce: nppp_admin_data.enable_proxy_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = '✔';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftProxy + 'px';
                notification.style.top = notificationTopProxy + 'px';
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
                $('#nginx_cache_preload_enable_proxy').prop('checked', !$('#nginx_cache_preload_enable_proxy').prop('checked'));
                alert('Error updating option!');
            }
        });
    });

    // Update preload mobile status when state changes
    $('#nginx_cache_auto_preload_mobile').change(function() {
        var mobilePreloadElement = jQuery(this);
        var clickToCopySpanMobilePreload = mobilePreloadElement.next('.nppp-onoffswitch-label-preload-mobile');
        var clickToCopySpanOffsetMobilePreload = clickToCopySpanMobilePreload.offset();
        var notificationLeftMobilePreload = clickToCopySpanOffsetMobilePreload.left + clickToCopySpanMobilePreload.outerWidth() + 10;
        var notificationTopMobilePreload = clickToCopySpanOffsetMobilePreload.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_auto_preload_mobile_option',
            preload_mobile: isChecked,
            _wpnonce: nppp_admin_data.auto_preload_mobile_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = '✔';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftMobilePreload + 'px';
                notification.style.top = notificationTopMobilePreload + 'px';
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
                $('#nginx_cache_auto_preload_mobile').prop('checked', !$('#nginx_cache_auto_preload_mobile').prop('checked'));
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
                notification.textContent = '✔';
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
                notification.textContent = '✔';
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
                notification.textContent = '✔';
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
                    // Update input field with the default reject regex
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

    // Make AJAX request to update default reject extension
    $('#nginx-extension-reset-defaults').on('click', function(event) {
        event.preventDefault();

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            data: {
                action: 'nppp_update_default_reject_extension_option',
                _wpnonce: nppp_admin_data.reject_extension_nonce
            },
            success: function(response) {
                // Check if AJAX request was successful
                if (response.success) {
                    // Update input field with the default reject extension
                    $('#nginx_cache_reject_extension').val(response.data);
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

    // Make AJAX request to update default cache key regex
    $('#nginx-key-regex-reset-defaults').on('click', function(event) {
        event.preventDefault();

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            data: {
                action: 'nppp_update_default_cache_key_regex_option',
                _wpnonce: nppp_admin_data.cache_key_regex_nonce
            },
            success: function(response) {
                // Check if AJAX request was successful
                if (response.success) {
                    // Update input field with the default reject extension
                    $('#nginx_cache_key_custom_regex').val(response.data);
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

        // Get the button element and its position
        var buttonElement = $('#nppp-clear-plugin-cache-btn');
        var buttonOffset = buttonElement.offset();
        var buttonWidth = buttonElement.outerWidth();

        // Set the loading spinner
        var spinner = document.createElement('div');
        spinner.className = 'nppp-loading-spinner';
        spinner.style.position = 'absolute';
        spinner.style.left = buttonOffset.left + buttonWidth + 10 + 'px';
        spinner.style.top = (buttonOffset.top - 12) + 'px';
        spinner.style.zIndex = '9999';

        // Show loading spinner
        document.body.appendChild(spinner);

        // Make AJAX request to clear plugin cache
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_clear_plugin_cache',
                _wpnonce: nppp_admin_data.plugin_cache_nonce
            },
            success: function(response) {
                // Remove the loading spinner
                document.body.removeChild(spinner);

                // Calculate the notification position
                var notificationLeft = buttonOffset.left + buttonWidth + 10;
                var notificationTop = buttonOffset.top - 3;

                // Show a small notification indicating status
                var notification = document.createElement('div');
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                notification.style.borderRadius = '4px';

                if (response.success) {
                    // Handle success case
                    notification.textContent = 'Cache Cleared';
                    notification.style.backgroundColor = '#50C878';
                } else {
                    // Handle error case
                    notification.textContent = 'Cache cannot be cleared';
                    notification.style.backgroundColor = '#D32F2F';
                }

                // Show notification
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);

                        // Re-trigger recursive permission check and cache the result
                        if (response.success) {
                            location.reload();
                        }
                    }, 300);
                }, 1700);
            },
            error: function(xhr, status, error) {
                // Remove the loading spinner
                document.body.removeChild(spinner);

                // Calculate the notification position
                var notificationLeft = buttonOffset.left + buttonWidth + 10;
                var notificationTop = buttonOffset.top - 3;

                // Show a small notification indicating error
                var notification = document.createElement('div');
                notification.textContent = 'An ajax error occured';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.backgroundColor = '#D32F2F';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                notification.style.borderRadius = '4px';

                // Show notification
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 2000);
            }
        });
    });

    // Event listener for the restart systemd service button
    $(document).off('click', '#nppp-restart-systemd-service-btn').on('click', '#nppp-restart-systemd-service-btn', function(e) {
        e.preventDefault();

        // Get the button element and its position
        var buttonElement = $('#nppp-restart-systemd-service-btn');
        var buttonOffset = buttonElement.offset();
        var buttonWidth = buttonElement.outerWidth();

        // Set the loading spinner
        var spinner = document.createElement('div');
        spinner.className = 'nppp-loading-spinner';
        spinner.style.position = 'absolute';
        spinner.style.left = buttonOffset.left + buttonWidth + 10 + 'px';
        spinner.style.top = (buttonOffset.top - 12) + 'px';
        spinner.style.zIndex = '9999';

        // Show loading spinner
        document.body.appendChild(spinner);

        // Make AJAX request to restart systemd service
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            data: {
                action: 'nppp_restart_systemd_service',
                _wpnonce: nppp_admin_data.systemd_service_nonce
            },
            success: function(response) {
                // Remove the spinner
                document.body.removeChild(spinner);

                // Calculate the notification position
                var notificationLeft = buttonOffset.left + buttonWidth + 10;
                var notificationTop = buttonOffset.top - 3;

                // Show a small notification indicating status
                var notification = document.createElement('div');
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                notification.style.borderRadius = '4px';

                if (response.success) {
                    // Handle success case
                    notification.textContent = 'Service Restarted';
                    notification.style.backgroundColor = '#50C878';
                } else {
                    // Handle error case
                    notification.textContent = 'Service cannot be restarted';
                    notification.style.backgroundColor = '#D32F2F';
                }

                // Show status notification
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);

                        // Reload settings page to see updated fuse mount status
                        if (response.success) {
                            location.reload();
                        }
                    }, 300);
                }, 1700);
            },
            error: function() {
                // Remove the loading spinner
                document.body.removeChild(spinner);

                // Calculate the notification position
                var notificationLeft = buttonOffset.left + buttonWidth + 10;
                var notificationTop = buttonOffset.top - 3;

                // Show a small notification indicating failure
                var notification = document.createElement('div');
                notification.textContent = 'An ajax error occured';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeft + 'px';
                notification.style.top = notificationTop + 'px';
                notification.style.backgroundColor = '#D32F2F';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                notification.style.borderRadius = '4px';
                document.body.appendChild(notification);

                // Set the notification duration
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 2000);
            }
        });
    });

    // Function to initialize DataTables.js for premium table
    function initializePremiumTable() {
        var table = $('#nppp-premium-table').DataTable({
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
                { width: "21%", targets: 0, className: 'text-left' }, // Cached URL
                { width: "37%", targets: 1, className: 'text-left' }, // Cache Path
                { width: "10%", targets: 2, className: 'text-left' }, // Content Category
                { width: "5%", targets: 3, className: 'text-left' }, // Cache Method
                { width: "12%", targets: 4, className: 'text-left' }, // Cache Date
                { width: "15%", targets: 5, className: 'text-left' }, // Actions
                { responsivePriority: 1, targets: 0 }, // Cached URL gets priority for responsiveness
                { responsivePriority: 10000, targets: [1, 2, 3, 4, 5] }, // Collapse all in first row on mobile, hide actions always
                { defaultContent: "", targets: "_all" } // Ensures all columns render even if empty
            ],

            // Ensure callback on table draw for initial load
            initComplete: function() {
                applyCategoryStyles();
                hideEmptyCells();
            }
        });

        // Apply styles whenever the table is redrawn (e.g., after pagination)
        table.on('draw', function() {
            applyCategoryStyles();
            hideEmptyCells();
        });
    }

    // Function to apply custom styles based on Content Category column
    function applyCategoryStyles() {
        $('#nppp-premium-table tbody tr').each(function() {
            var $cell = $(this).find('td').eq(2);

            // Get the text of the Content Category column
            var category = $cell.text().trim();

            // Apply different CSS styles based on the category
            switch (category) {
                case 'POST':
                    $cell.css({
                        'color': 'fuchsia',
                        'font-weight': 'bold'
                    });
                    break;
                case 'AUTHOR':
                    $cell.css({
                        'color': 'orange',
                        'font-weight': 'bold'
                    });
                    break;
                case 'PAGE':
                    $cell.css({
                        'color': 'green',
                        'font-weight': 'bold'
                    });
                    break;
                case 'TAG':
                    $cell.css({
                        'color': 'blue',
                        'font-weight': 'bold'
                    });
                    break;
                case 'CATEGORY':
                    $cell.css({
                        'color': 'mediumslateblue',
                        'font-weight': 'bold'
                    });
                    break;
                case 'DAILY_ARCHIVE':
                    $cell.css({
                        'color': 'red',
                        'font-weight': 'bold'
                    });
                    break;
                case 'MONTHLY_ARCHIVE':
                    $cell.css({
                        'color': 'brown',
                        'font-weight': 'bold'
                    });
                    break;
                case 'YEARLY_ARCHIVE':
                    $cell.css({
                        'color': 'darkblue',
                        'font-weight': 'bold'
                    });
                    break;
                case 'DATE_ARCHIVE':
                    $cell.css({
                        'color': 'darkmagenta',
                        'font-weight': 'bold'
                    });
                    break;
                case 'PRODUCT':
                    $cell.css({
                        'color': 'coral',
                        'font-weight': 'bold'
                    });
                    break;
                default:
                    $cell.css({
                        'color': 'burlywood',
                        'font-weight': 'bold'
                    });
            }
            // Apply styles to the Cache Method column (4th column)
            var $cacheMethodCell = $(this).find('td').eq(3);
            $cacheMethodCell.css({
                'color': 'green'
            });
        });
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
                row.querySelector('td:nth-child(4)'),
                row.querySelector('td:nth-child(5)')
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

    // Toggle switch rules for preload mobile
    var isChecked = $('#nginx_cache_auto_preload_mobile').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-preload-mobile').css('background', '#66b317');
        $('.nppp-on-preload-mobile').css('color', '#ffffff');
        $('.nppp-off-preload-mobile').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-preload-mobile').css('background', '#ea1919');
        $('.nppp-on-preload-mobile').css('color', '#000000');
        $('.nppp-off-preload-mobile').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_auto_preload_mobile').change(function() {
        // Check if the checkbox is checked
        var isChecked = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-preload-mobile').css('background', '#66b317');
            $('.nppp-on-preload-mobile').css('color', '#ffffff');
            $('.nppp-off-preload-mobile').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-preload-mobile').css('background', '#ea1919');
            $('.nppp-on-preload-mobile').css('color', '#000000');
            $('.nppp-off-preload-mobile').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for enable proxy
    var isCheckedProxy = $('#nginx_cache_preload_enable_proxy').prop('checked');
    // Update the toggle switch based on the checkbox state
    if (isCheckedProxy) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-proxy').css('background', '#66b317');
        $('.nppp-on-proxy').css('color', '#ffffff');
        $('.nppp-off-proxy').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-proxy').css('background', '#ea1919');
        $('.nppp-on-proxy').css('color', '#000000');
        $('.nppp-off-proxy').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nginx_cache_preload_enable_proxy').change(function() {
        // Check if the checkbox is checked
        var isCheckedProxy = $(this).prop('checked');
        // Update the toggle switch based on the checkbox state
        if (isCheckedProxy) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-proxy').css('background', '#66b317');
            $('.nppp-on-proxy').css('color', '#ffffff');
            $('.nppp-off-proxy').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-proxy').css('background', '#ea1919');
            $('.nppp-on-proxy').css('color', '#000000');
            $('.nppp-off-proxy').css('color', '#ffffff');
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

    // Set cache button behaviours
    $('#nppp-purge-button, #nppp-preload-button').on('click', function(event) {
        // Prevent the default click behavior
        event.preventDefault();

        // Disable the clicked button
        $(this).prop('disabled', true).addClass('disabled');

        // Show the preloader
        $('#nppp-loader-overlay').addClass('active').fadeIn(200);

        // Store the URL of the button's destination
        var url = $(this).attr('href');

        // Set a timeout to reload the page after 2 seconds
        setTimeout(function() {
            window.location.href = url;
        }, 500);
    });

    // Start masking API key on front-end
    var nppApiKeyInput = $('#nginx_cache_api_key');
    var nppGenerateButton = $('#api-key-button');

    // Function to mask the first 10 characters of the API key
    function nppMaskApiKey(apiKey) {
        if (apiKey.length <= 10) {
            return '*'.repeat(apiKey.length);
        }
        return '*'.repeat(10) + apiKey.slice(10);
    }

    // Function to set the API key and apply masking
    function nppSetApiKey(apiKey) {
        nppApiKeyInput.data('original-key', apiKey);
        nppApiKeyInput.val(nppMaskApiKey(apiKey));
    }

    // Function to unmask the API key on focus
    function nppUnmaskApiKey() {
        var originalKey = nppApiKeyInput.data('original-key');
        nppApiKeyInput.val(originalKey);
    }

    // Function to remask the API key on blur
    function nppRemaskApiKey() {
        var originalKey = nppApiKeyInput.data('original-key');
        nppApiKeyInput.val(nppMaskApiKey(originalKey));
    }

    // Handles manual input by updating the stored original key.
    function nppHandleManualInput() {
        var currentVal = nppApiKeyInput.val();
        nppApiKeyInput.data('original-key', currentVal);
    }

    // Initial masking on page load
    var nppInitialApiKey = nppApiKeyInput.val();
    nppSetApiKey(nppInitialApiKey);

    // Bind focus and blur events to handle masking and unmasking
    nppApiKeyInput.on('focus', nppUnmaskApiKey);
    nppApiKeyInput.on('blur', nppRemaskApiKey);

    // Bind input event to handle manual changes
    nppApiKeyInput.on('input', nppHandleManualInput);

    // Handle the "Generate API Key" button click
    nppGenerateButton.on('click', function() {
        setTimeout(function() {
            // Backend has updated the input field with the new API key
            var newApiKey = nppApiKeyInput.val();
            nppSetApiKey(newApiKey);
            // Update the original value in the originalValues object for API key
            originalValues['#nginx_cache_api_key'] = nppApiKeyInput.data('original-key');
        }, 700);
    });

    // Find the closest form that contains the API key input
    var nppForm = nppApiKeyInput.closest('form');

    // Check if the form exists
    if (nppForm.length) {
        // Attach a submit event handler to the form
        nppForm.on('submit', function(event) {
            // Retrieve the original (unmasked) API key from data attribute
            var originalKey = nppApiKeyInput.data('original-key');

            // Replace the masked value with the original API key
            nppApiKeyInput.val(originalKey);
        });
    }

    // Handle click events on sub-menu links
    $('.nppp-submenu a').on('click', function(e){
        e.preventDefault();

        var target = $(this).attr('href');

        // Check if the target exists
        if ($(target).length) {
            // Animate scrolling to the target section
            $('html, body').animate({
                scrollTop: $(target).offset().top - 30
            }, 500);
        }
    });

    // Select the submit button within the form
    var $submitButton = $('#nppp-settings-form input[type="submit"]');

    // Store the original button text
    var originalText = $submitButton.val();

    // Object to store original values of monitored fields
    var originalValues = {};

    // List of field selectors to monitor
    var fieldsToMonitor = [
        '#nginx_cache_path',
        '#nginx_cache_cpu_limit',
        '#nginx_cache_reject_regex',
        '#nginx_cache_reject_extension',
        '#nginx_cache_limit_rate',
        '#nginx_cache_wait_request',
        '#nginx_cache_email',
        '#nginx_cache_tracking_opt_in',
        '#nginx_cache_api_key',
        '#nginx_cache_key_custom_regex'
    ];

    // Initialize originalValues with current field values
    fieldsToMonitor.forEach(function(selector) {
        var $field = $(selector);
        if (selector === '#nginx_cache_api_key') {
            // For the API key, use the unmasked value (original key)
            originalValues[selector] = nppApiKeyInput.data('original-key');
        } else if ($field.attr('type') === 'checkbox') {
            originalValues[selector] = $field.is(':checked');
        } else {
            originalValues[selector] = $field.val();
        }
    });

    // Function to check if any field has changed
    function checkForChanges() {
        var hasChanged = false;

        fieldsToMonitor.forEach(function(selector) {
            var $field = $(selector);
            var originalValue = originalValues[selector];
            var currentValue;

            if (selector === '#nginx_cache_api_key') {
                // For the API key, compare the unmasked value (original key)
                currentValue = nppApiKeyInput.data('original-key');
            } else if ($field.attr('type') === 'checkbox') {
                currentValue = $field.is(':checked');
            } else {
                currentValue = $field.val();
            }

            if (currentValue !== originalValue) {
                hasChanged = true;
            }
        });

        if (hasChanged) {
            markFormChanged();
        } else {
            resetButtonState();
        }
    }

    // Function to mark the form as changed
    function markFormChanged() {
        if (!$submitButton.hasClass('nppp-submit-changed')) {
            $submitButton.addClass('nppp-submit-changed');
            $submitButton.val('Save Settings Now');
        }
    }

    // Function to reset the button to its original state
    function resetButtonState() {
        if ($submitButton.hasClass('nppp-submit-changed')) {
            $submitButton.removeClass('nppp-submit-changed');
            $submitButton.val(originalText);
        }
    }

    // Attach event listeners to the specified fields
    $(fieldsToMonitor.join(', ')).on('input change', function() {
        checkForChanges();
    });

    // Reset the button state when the form is submitted
    $('#nppp-settings-form').on('submit', function() {
        resetButtonState();
        // Update originalValues to the new values after submission
        fieldsToMonitor.forEach(function(selector) {
            var $field = $(selector);
            if ($field.attr('type') === 'checkbox') {
                originalValues[selector] = $field.is(':checked');
            } else {
                originalValues[selector] = $field.val();
            }
        });
    });

    // Reset the button state if there's a reset button
    $('#nppp-settings-form').on('reset', function() {
        // Delay the reset to allow the form to reset first
        setTimeout(function() {
            resetButtonState();
            // Update originalValues to the reset values
            fieldsToMonitor.forEach(function(selector) {
                var $field = $(selector);
                if ($field.attr('type') === 'checkbox') {
                    originalValues[selector] = $field.is(':checked');
                } else {
                    originalValues[selector] = $field.val();
                }
            });
        }, 0);
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
                seconds:false
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
                LT: 'HH:mm'
            },
            hourCycle: 'h23',
            format: 'LT'
        },
        stepping: 5
    });
});

// Trim trailing leading white spaces from inputs, sanitize nginx cache path on client side
document.addEventListener('DOMContentLoaded', function () {
    // IDs of input fields to apply trimming
    const inputIds = ['#nginx_cache_path', '#nginx_cache_email', '#nginx_cache_reject_regex', '#nginx_cache_api_key', '#nginx_cache_reject_extension', '#nginx_cache_key_custom_regex'];

    inputIds.forEach(function(inputId) {
        const inputField = document.querySelector(inputId);

        if (inputField) {
            // Function to trim the input value
            function trimInputValue() {
                inputField.value = inputField.value.trim();
            }

            // Function to remove trailing slash and prevent special characters for Linux directory paths
            function sanitizeNginxCachePath() {
                let oldValue;
                do {
                    oldValue = inputField.value;

                    // Remove all invalid characters for Linux directory paths (allow /, -, _, ., a-z, A-Z, 0-9)
                    inputField.value = inputField.value.replace(/[^a-zA-Z0-9\/\-_\.]/g, '');

                    // Replace multiple consecutive slashes with a single slash
                    inputField.value = inputField.value.replace(/\/{2,}/g, '/');

                    // Remove folder names made up of only underscores or hyphens (e.g., __ or -- or ___)
                    inputField.value = inputField.value.replace(/\/(?:[_\-]+)(\/|$)/g, '/');

                    // Remove trailing slashes (if any)
                    inputField.value = inputField.value.replace(/\/+$/, '');

                } while (oldValue !== inputField.value);
            }

            // Apply specific logic for #nginx_cache_path
            if (inputId === '#nginx_cache_path') {
                inputField.addEventListener('blur', sanitizeNginxCachePath);
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
                form.addEventListener('submit', function() {
                    trimInputValue();
                    if (inputId === '#nginx_cache_path') {
                        sanitizeNginxCachePath(); // Ensure it's sanitized before submission
                    }
                });
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

// Update status tab metrics
function npppupdateStatus() {
    // Elements we need ready on DOM
    const elementsToCheck = [
        "#npppphpFpmStatus",
        "#npppphpPagesInCache",
        "#npppphpProcessOwner",
        "#npppphpWebServer",
        "#npppcachePath",
        "#nppppurgeStatus",
        "#npppshellExec",
        "#npppaclStatus",
        "#nppppreloadStatus",
        "#npppwgetStatus",
        "#npppLibfuseVersion",
        "#npppBindfsVersion",
        "#nppppermIsolation",
        "#npppcpulimitStatus"
    ];

    // Verify all elements are in the DOM
    const allElementsExist = elementsToCheck.every(selector => document.querySelector(selector));

    // If all elements are found, proceed to run the code
    if (!allElementsExist) {
        return;
    }

    // Fetch and update php fpm status
    var phpFpmRow = document.querySelector("#npppphpFpmStatus").closest("tr");
    var npppphpFpmStatusSpan = document.getElementById("npppphpFpmStatus");
    var npppphpFpmStatus = npppphpFpmStatusSpan.textContent.trim();

    npppphpFpmStatusSpan.textContent = npppphpFpmStatus;
    npppphpFpmStatusSpan.style.fontSize = "14px";

    let iconSpanFpm = document.createElement('span');
    let fpmStatusText = '';

    if (npppphpFpmStatus === "false") {
        npppphpFpmStatusSpan.style.color = "red";
        iconSpanFpm.classList.add("dashicons", "dashicons-no");
        fpmStatusText = ' ' + __('Required', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (npppphpFpmStatus === "Not Found") {
        npppphpFpmStatusSpan.style.color = "orange";
        iconSpanFpm.classList.add("dashicons", "dashicons-clock");
        fpmStatusText = ' ' + __('Not Determined', 'fastcgi-cache-purge-and-preload-nginx');
    } else {
        npppphpFpmStatusSpan.style.color = "green";
        iconSpanFpm.classList.add("dashicons", "dashicons-yes");
        fpmStatusText = ' ' + __('Not Required', 'fastcgi-cache-purge-and-preload-nginx');
    }

    npppphpFpmStatusSpan.textContent = '';
    npppphpFpmStatusSpan.appendChild(iconSpanFpm);
    npppphpFpmStatusSpan.append(fpmStatusText);

    // Fetch and update pages in cache count
    var npppcacheInPageSpan = document.getElementById("npppphpPagesInCache");
    var npppcacheInPageSpanValue = npppcacheInPageSpan.textContent.trim();
    npppcacheInPageSpan.style.fontSize = "14px";

    let iconSpanCache = document.createElement('span');
    let cacheStatusText = '';

    if (npppcacheInPageSpanValue === "Undetermined") {
        npppcacheInPageSpan.style.color = "red";
        iconSpanCache.classList.add("dashicons", "dashicons-no");
        cacheStatusText = ' ' + __('Fix Permission', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (npppcacheInPageSpanValue === "RegexError") {
        npppcacheInPageSpan.style.color = "red";
        iconSpanCache.classList.add("dashicons", "dashicons-no");
        cacheStatusText = ' ' + __('Fix Regex', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (npppcacheInPageSpanValue === "0") {
        npppcacheInPageSpan.style.color = "orange";
        iconSpanCache.classList.add("dashicons", "dashicons-clock");
        cacheStatusText = ' ' + npppcacheInPageSpanValue;
    } else if (npppcacheInPageSpanValue === "Not Found") {
        npppcacheInPageSpan.style.color = "orange";
        iconSpanCache.classList.add("dashicons", "dashicons-clock");
        cacheStatusText = ' ' + __('Not Determined', 'fastcgi-cache-purge-and-preload-nginx');
    } else {
        npppcacheInPageSpan.style.color = "green";
        iconSpanCache.classList.add("dashicons", "dashicons-yes");
        cacheStatusText = ' ' + npppcacheInPageSpanValue;
    }

    npppcacheInPageSpan.textContent = '';
    npppcacheInPageSpan.appendChild(iconSpanCache);
    npppcacheInPageSpan.append(cacheStatusText);

    // Fetch and update php process owner
    // PHP-FPM (website user)
    var npppphpProcessOwnerSpan = document.getElementById("npppphpProcessOwner");
    var npppphpProcessOwner = npppphpProcessOwnerSpan.textContent.trim();

    npppphpProcessOwnerSpan.style.fontSize = "14px";
    npppphpProcessOwnerSpan.style.color = "green";
    npppphpProcessOwnerSpan.textContent = '';

    let iconSpanProcessOwner = document.createElement('span');
    iconSpanProcessOwner.classList.add("dashicons", "dashicons-arrow-right-alt");
    iconSpanProcessOwner.style.fontSize = "16px";

    npppphpProcessOwnerSpan.appendChild(iconSpanProcessOwner);
    npppphpProcessOwnerSpan.append(' ' + npppphpProcessOwner);

    // Fetch and update web server user
    // WEB-SERVER (webserver user)
    var npppphpWebServerSpan = document.getElementById("npppphpWebServer");
    var npppphpWebServer = npppphpWebServerSpan.textContent.trim();

    npppphpWebServerSpan.style.fontSize = "14px";
    npppphpWebServerSpan.style.color = "green";
    npppphpWebServerSpan.textContent = '';

    let iconSpanWebServer = document.createElement('span');
    iconSpanWebServer.classList.add("dashicons", "dashicons-arrow-right-alt");
    iconSpanWebServer.style.fontSize = "16px";

    npppphpWebServerSpan.appendChild(iconSpanWebServer);
    npppphpWebServerSpan.append(' ' + npppphpWebServer);

    // Fetch and update nginx cache path status
    var npppcachePathSpan = document.getElementById("npppcachePath");
    var npppcachePath = npppcachePathSpan.textContent.trim();

    npppcachePathSpan.style.fontSize = "14px";
    npppcachePathSpan.textContent = '';

    let iconSpanCachePath = document.createElement('span');
    iconSpanCachePath.style.fontSize = "20px";

    if (npppcachePath === "Found") {
        npppcachePathSpan.style.color = "green";
        iconSpanCachePath.classList.add("dashicons", "dashicons-yes");
        npppcachePathSpan.appendChild(iconSpanCachePath);
        npppcachePathSpan.append(' ', __('Found', 'fastcgi-cache-purge-and-preload-nginx'));
    } else if (npppcachePath === "Not Found") {
        npppcachePathSpan.style.color = "red";
        iconSpanCachePath.classList.add("dashicons", "dashicons-no");
        npppcachePathSpan.appendChild(iconSpanCachePath);
        npppcachePathSpan.append(' ', __('Not Found', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Fetch and update purge action status
    var nppppurgeStatusSpan = document.getElementById("nppppurgeStatus");
    var nppppurgeStatus = nppppurgeStatusSpan.textContent.trim();

    nppppurgeStatusSpan.style.fontSize = "14px";
    nppppurgeStatusSpan.textContent = '';

    let iconSpanPurgeStatus = document.createElement('span');
    iconSpanPurgeStatus.style.fontSize = "20px";

    if (nppppurgeStatus === "true") {
        nppppurgeStatusSpan.style.color = "green";
        iconSpanPurgeStatus.classList.add("dashicons", "dashicons-yes");
        nppppurgeStatusSpan.appendChild(iconSpanPurgeStatus);
        nppppurgeStatusSpan.append(' ', __('Ready', 'fastcgi-cache-purge-and-preload-nginx'));
    } else if (nppppurgeStatus === "false") {
        nppppurgeStatusSpan.style.color = "red";
        iconSpanPurgeStatus.classList.add("dashicons", "dashicons-no");
        nppppurgeStatusSpan.appendChild(iconSpanPurgeStatus);
        nppppurgeStatusSpan.append(' ', __('Not Ready', 'fastcgi-cache-purge-and-preload-nginx'));
    } else {
        nppppurgeStatusSpan.style.color = "orange";
        iconSpanPurgeStatus.classList.add("dashicons", "dashicons-clock");
        nppppurgeStatusSpan.appendChild(iconSpanPurgeStatus);
        nppppurgeStatusSpan.append(' ', __('Not Determined', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Fetch and update shell_exec status
    var npppshellExecSpan = document.getElementById("npppshellExec");
    var npppshellExec = npppshellExecSpan.textContent.trim();

    npppshellExecSpan.style.fontSize = "14px";
    npppshellExecSpan.textContent = '';

    let iconSpanShellExec = document.createElement('span');
    iconSpanShellExec.style.fontSize = "20px";

    if (npppshellExec === "Ok") {
        npppshellExecSpan.style.color = "green";
        iconSpanShellExec.classList.add("dashicons", "dashicons-yes");
        npppshellExecSpan.appendChild(iconSpanShellExec);
        npppshellExecSpan.append(' ', __('Allowed', 'fastcgi-cache-purge-and-preload-nginx'));
    } else if (npppshellExec === "Not Ok") {
        npppshellExecSpan.style.color = "red";
        iconSpanShellExec.classList.add("dashicons", "dashicons-no");
        npppshellExecSpan.appendChild(iconSpanShellExec);
        npppshellExecSpan.append(' ', __('Not Allowed', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Fetch and update ACLs status
    var npppaclStatusSpan = document.getElementById("npppaclStatus");
    var npppaclStatus = npppaclStatusSpan.textContent.trim();
    npppaclStatusSpan.textContent = '';
    npppaclStatusSpan.style.fontSize = "14px";

    let iconSpanAcl = document.createElement('span');
    let aclStatusText = '';
    let processOwnerSpan = document.createElement('span');

    if (npppaclStatus.includes("Granted")) {
        npppaclStatusSpan.style.color = "green";
        iconSpanAcl.classList.add("dashicons", "dashicons-yes");
        aclStatusText = ' ' + __('Granted', 'fastcgi-cache-purge-and-preload-nginx') + ' ';

        var processOwner = npppaclStatus.replace("Granted", "").trim();
        if (processOwner) {
            processOwnerSpan.textContent = processOwner;
            processOwnerSpan.style.color = "darkorange";
        }

    } else if (npppaclStatus.includes("Need Action")) {
        npppaclStatusSpan.style.color = "red";
        iconSpanAcl.classList.add("dashicons", "dashicons-no");
        aclStatusText = ' ' + __('Need Action', 'fastcgi-cache-purge-and-preload-nginx');
    } else {
        npppaclStatusSpan.style.color = "orange";
        iconSpanAcl.classList.add("dashicons", "dashicons-clock");
        aclStatusText = ' ' + __('Not Determined', 'fastcgi-cache-purge-and-preload-nginx');
    }

    npppaclStatusSpan.appendChild(iconSpanAcl);
    npppaclStatusSpan.append(aclStatusText);
    if (processOwnerSpan.textContent) {
        npppaclStatusSpan.appendChild(processOwnerSpan);
    }

    // Fetch and update preload action status
    var nppppreloadStatusRow = document.querySelector("#nppppreloadStatus").closest("tr");
    var nppppreloadStatusCell = nppppreloadStatusRow.querySelector("#nppppreloadStatus");
    var nppppreloadStatusSpan = document.getElementById("nppppreloadStatus");
    var nppppreloadStatus = nppppreloadStatusSpan.textContent.trim();
    nppppreloadStatusSpan.textContent = '';
    nppppreloadStatusSpan.style.fontSize = "14px";

    let iconSpanPreload = document.createElement('span');
    let preloadStatusText = '';

    if (nppppreloadStatus === "true") {
        nppppreloadStatusSpan.style.color = "green";
        iconSpanPreload.classList.add("dashicons", "dashicons-yes");
        preloadStatusText = ' ' + __('Ready', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (nppppreloadStatus === "false") {
        nppppreloadStatusSpan.style.color = "red";
        iconSpanPreload.classList.add("dashicons", "dashicons-no");
        preloadStatusText = ' ' + __('Not Ready', 'fastcgi-cache-purge-and-preload-nginx');
    }

    nppppreloadStatusSpan.appendChild(iconSpanPreload);
    nppppreloadStatusSpan.append(preloadStatusText);

    // Fetch and update wget command status
    var npppwgetStatusSpan = document.getElementById("npppwgetStatus");
    var npppwgetStatus = npppwgetStatusSpan.textContent.trim();
    npppwgetStatusSpan.textContent = '';
    npppwgetStatusSpan.style.fontSize = "14px";

    let iconSpanWget = document.createElement('span');
    let wgetStatusText = '';

    if (npppwgetStatus === "Installed") {
        npppwgetStatusSpan.style.color = "green";
        iconSpanWget.classList.add("dashicons", "dashicons-yes");
        wgetStatusText = ' ' + __('Installed', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (npppwgetStatus === "Not Installed") {
        npppwgetStatusSpan.style.color = "red";
        iconSpanWget.classList.add("dashicons", "dashicons-no");
        wgetStatusText = ' ' + __('Not Installed', 'fastcgi-cache-purge-and-preload-nginx');
    }

    npppwgetStatusSpan.appendChild(iconSpanWget);
    npppwgetStatusSpan.append(wgetStatusText);

    // Update the FUSE status for libfuse
    var npppLibfuseVersionSpan = document.getElementById("npppLibfuseVersion");
    var npppLibfuseVersion = npppLibfuseVersionSpan.textContent.trim();

    npppLibfuseVersionSpan.style.fontSize = "14px";
    npppLibfuseVersionSpan.style.fontWeight = "bold";
    npppLibfuseVersionSpan.textContent = '';

    let iconSpanLibfuse = document.createElement('span');
    let libfuseStatusText = '';

    if (npppLibfuseVersion === "Not Installed") {
        npppLibfuseVersionSpan.style.color = "orange";
        iconSpanLibfuse.classList.add("dashicons", "dashicons-warning");
        iconSpanLibfuse.style.fontSize = "18px";
        libfuseStatusText = ' ' + npppLibfuseVersion;
    } else if (npppLibfuseVersion.includes("(Not Determined)")) {
        var installedVersion = npppLibfuseVersion.split(" ")[0];
        iconSpanLibfuse.classList.add("dashicons", "dashicons-yes");
        iconSpanLibfuse.style.fontSize = "20px";
        iconSpanLibfuse.style.color = "green";
        libfuseStatusText = ` ${installedVersion} <span style="color:orange;">(${__('Not Determined', 'fastcgi-cache-purge-and-preload-nginx')})</span>`;
    } else if (npppLibfuseVersion.includes("(")) {
        var versions = npppLibfuseVersion.match(/(\d+\.\d+\.\d+)\s\((\d+\.\d+\.\d+)\)/);
        if (versions) {
            var installedVersion = versions[1];
            var latestVersion = versions[2];

            if (installedVersion === latestVersion) {
                npppLibfuseVersionSpan.style.color = "green";
                iconSpanLibfuse.classList.add("dashicons", "dashicons-yes");
                iconSpanLibfuse.style.fontSize = "20px";
                iconSpanLibfuse.style.color = "green";
                libfuseStatusText = ` ${installedVersion} (${latestVersion})`;
            } else {
                iconSpanLibfuse.classList.add("dashicons", "dashicons-update");
                iconSpanLibfuse.style.fontSize = "18px";
                iconSpanLibfuse.style.color = "orange";
                libfuseStatusText = `<span style="color:orange;">${installedVersion}</span> <span style="color:green;">(${latestVersion})</span>`;
            }
        }
    } else {
        npppLibfuseVersionSpan.style.color = "green";
        iconSpanLibfuse.classList.add("dashicons", "dashicons-yes");
        iconSpanLibfuse.style.fontSize = "20px";
        iconSpanLibfuse.style.color = "green";
        libfuseStatusText = ' ' + npppLibfuseVersion;
    }

    npppLibfuseVersionSpan.appendChild(iconSpanLibfuse);
    npppLibfuseVersionSpan.insertAdjacentHTML('beforeend', libfuseStatusText);

    // Update the FUSE status for bindfs
    var npppBindfsVersionSpan = document.getElementById("npppBindfsVersion");
    var npppBindfsVersion = npppBindfsVersionSpan.textContent.trim();

    npppBindfsVersionSpan.style.fontSize = "14px";
    npppBindfsVersionSpan.style.fontWeight = "bold";
    npppBindfsVersionSpan.textContent = '';

    let iconSpanBindfs = document.createElement('span');
    let bindfsStatusText = '';

    if (npppBindfsVersion === "Not Installed") {
        npppBindfsVersionSpan.style.color = "orange";
        iconSpanBindfs.classList.add("dashicons", "dashicons-warning");
        iconSpanBindfs.style.fontSize = "18px";
        bindfsStatusText = ' ' + npppBindfsVersion;
    } else if (npppBindfsVersion.includes("(Not Determined)")) {
        var installedVersion = npppBindfsVersion.split(" ")[0];
        iconSpanBindfs.classList.add("dashicons", "dashicons-yes");
        iconSpanBindfs.style.fontSize = "20px";
        iconSpanBindfs.style.color = "green";
        bindfsStatusText = ` ${installedVersion} <span style="color:orange;">(${__('Not Determined', 'fastcgi-cache-purge-and-preload-nginx')})</span>`;
    } else if (npppBindfsVersion.includes("(")) {
        var versions = npppBindfsVersion.match(/(\d+\.\d+\.\d+)\s\((\d+\.\d+\.\d+)\)/);
        if (versions) {
            var installedVersion = versions[1];
            var latestVersion = versions[2];

            if (installedVersion === latestVersion) {
                npppBindfsVersionSpan.style.color = "green";
                iconSpanBindfs.classList.add("dashicons", "dashicons-yes");
                iconSpanBindfs.style.fontSize = "20px";
                iconSpanBindfs.style.color = "green";
                bindfsStatusText = ` ${installedVersion} (${latestVersion})`;
            } else {
                iconSpanBindfs.classList.add("dashicons", "dashicons-update");
                iconSpanBindfs.style.fontSize = "18px";
                iconSpanBindfs.style.color = "orange";
                bindfsStatusText = `<span style="color:orange;">${installedVersion}</span> <span style="color:green;">(${latestVersion})</span>`;
            }
        }
    } else {
        npppBindfsVersionSpan.style.color = "green";
        iconSpanBindfs.classList.add("dashicons", "dashicons-yes");
        iconSpanBindfs.style.fontSize = "20px";
        iconSpanBindfs.style.color = "green";
        bindfsStatusText = ' ' + npppBindfsVersion;
    }

    npppBindfsVersionSpan.appendChild(iconSpanBindfs);
    npppBindfsVersionSpan.insertAdjacentHTML('beforeend', bindfsStatusText);

    // Fetch and update permission isolation status
    var nppppermIsolationSpan = document.getElementById("nppppermIsolation");
    var nppppermIsolation = nppppermIsolationSpan.textContent.trim();
    nppppermIsolationSpan.textContent = '';
    nppppermIsolationSpan.style.fontSize = "14px";

    let iconSpanPermIsolation = document.createElement('span');
    let permIsolationStatusText = '';

    if (nppppermIsolation === "Isolated") {
        nppppermIsolationSpan.style.color = "green";
        iconSpanPermIsolation.classList.add("dashicons", "dashicons-yes");
        permIsolationStatusText = ' ' + __('Isolated', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (nppppermIsolation === "Not Isolated") {
        nppppermIsolationSpan.style.color = "orange";
        iconSpanPermIsolation.classList.add("dashicons", "dashicons-clock");
        permIsolationStatusText = ' ' + __('Not Isolated', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (nppppermIsolation === "Not Determined") {
        nppppermIsolationSpan.style.color = "red";
        iconSpanPermIsolation.classList.add("dashicons", "dashicons-no");
        permIsolationStatusText = ' ' + __('Not Determined', 'fastcgi-cache-purge-and-preload-nginx');
    }

    nppppermIsolationSpan.appendChild(iconSpanPermIsolation);
    nppppermIsolationSpan.append(permIsolationStatusText);

    // Fetch and update cpulimit command status
    var npppcpulimitStatusSpan = document.getElementById("npppcpulimitStatus");
    var npppcpulimitStatus = npppcpulimitStatusSpan.textContent.trim();
    npppcpulimitStatusSpan.textContent = '';
    npppcpulimitStatusSpan.style.fontSize = "14px";

    let iconSpanCpulimit = document.createElement('span');
    let cpulimitStatusText = '';

    if (npppcpulimitStatus === "Installed") {
        npppcpulimitStatusSpan.style.color = "green";
        iconSpanCpulimit.classList.add("dashicons", "dashicons-yes");
        cpulimitStatusText = ' ' + __('Installed', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (npppcpulimitStatus === "Not Installed") {
        npppcpulimitStatusSpan.style.color = "red";
        iconSpanCpulimit.classList.add("dashicons", "dashicons-no");
        cpulimitStatusText = ' ' + __('Not Installed', 'fastcgi-cache-purge-and-preload-nginx');
    }

    npppcpulimitStatusSpan.appendChild(iconSpanCpulimit);
    npppcpulimitStatusSpan.append(cpulimitStatusText);

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

// This ensures that the preloader is shown when the form is being processed.
document.addEventListener('DOMContentLoaded', function() {
    // Get the settings form by its ID
    var npppNginxForm = document.getElementById('nppp-settings-form');

    // Check if the form exists on the page
    if (npppNginxForm) {
        // Add a submit event listener to the form
        npppNginxForm.addEventListener('submit', function() {
            // Get the preloader overlay by its ID
            var npppOverlay = document.getElementById('nppp-loader-overlay');

            // If the overlay exists, add the "active" class to display it
            if (npppOverlay) {
                npppOverlay.classList.add('active');
            }
        });
    }
});

// Adjust width of submit button
document.addEventListener('DOMContentLoaded', function() {
    const tabsContainer = document.getElementById('nppp-nginx-tabs');
    const submitContainer = document.querySelector('#nppp-settings-form .submit');

    function updateSubmitPosition() {
        if (!tabsContainer || !submitContainer) {
            return;
        }

        const containerRect = tabsContainer.getBoundingClientRect();

        // Set the width and position of the submit button
        submitContainer.style.left = `${containerRect.left}px`;
        submitContainer.style.width = `${containerRect.width}px`;

        // Remove any extra padding or margins
        submitContainer.style.margin = '0';
        submitContainer.style.padding = '0';
    }

    // Wait for elements ready
    const waitForTabs = setInterval(() => {
        const tabsContainer = document.getElementById('nppp-nginx-tabs');
        const submitContainer = document.querySelector('#nppp-settings-form .submit');

        if (tabsContainer && submitContainer) {
            clearInterval(waitForTabs);
            updateSubmitPosition();
        }
    }, 10);

    // Update the position when the window is resized
    window.addEventListener('resize', updateSubmitPosition);
});

// Track the currently active link for the submenu
let npppActiveLink = null;

// Add event listener to the parent <ul> for event delegation
document.querySelector('.nppp-submenu ul').addEventListener('click', function(npppEvent) {
    // Check if the clicked element is an <a> inside the submenu
    const npppClickedLink = npppEvent.target.closest('a');
    if (!npppClickedLink) return;

    // Remove 'active' class from the previously active link
    if (npppActiveLink) {
        npppActiveLink.classList.remove('active');
    }

    // Add 'active' class to the clicked link
    npppClickedLink.classList.add('active');

    // Update the active link reference
    npppActiveLink = npppClickedLink;
});
})(jQuery);
