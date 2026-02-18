/**
 * Preload feature guards for Nginx Cache Purge Preload
 * Description: Disables preload-specific controls when required environment checks fail.
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Disable NPP Preload features in unsupported environments
(function($) {
    'use strict';

    // Keep disabled controls non-interactive even if other scripts try to re-bind.
    function npppHardDisableClick(selector) {
        $(document).on('click.npppDisablePreload', selector, function(event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return false;
        });
    }

    // Function to disable preload button on Advanced Tab
    function NppdisablePreloadButtons() {
        $('.nppp-preload-btn').each(function() {
            var $btn = $(this);

            // Remove all jQuery-attached click event listeners
            $btn.off('click');

            // Remove any inline onclick attribute
            $btn.removeAttr('onclick');

            // Disable the button using the native disabled property
            $btn.prop('disabled', true);

            // Apply CSS to show the button as disabled
            $btn.css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

            // Add a unique class to flag it
            $btn.addClass('nppp-general');
        });
    }

    $(document).ready(function() {
        // Disable the Preload button in the WP admin bar
        var $preloadButton = $('#wp-admin-bar-preload-cache');
        if ($preloadButton.length) {
            // Remove all click events
            $preloadButton.off('click');

            // Disable click behavior
            $preloadButton.find('a')
                .removeAttr('href')
                .css({
                    'opacity': '0.5',
                    'cursor': 'not-allowed'
                });
        }

        // Disable the Preload button on the Dashboard Widget
        $('.nppp-action-button[data-action="nppp-widget-preload"]')
            .addClass('disabled')
            .removeAttr('href')
            .css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

        // Check if we're on the plugin settings page to disable Preload-related features
        if ($('#nppp-nginx-tabs').length > 0) {
            // Disable the Preload button on the Settings page
            $('#nppp-preload-button').addClass('disabled').removeAttr('href');

            // Disable auto preload
            $('#nginx_cache_auto_preload').prop('disabled', true);

            // Disable preload mobile
            $('#nginx_cache_auto_preload_mobile').prop('disabled', true);

            // Disable the preload wp schedule
            $('#nginx_cache_schedule').prop('disabled', true);

            // Disable schedule controls under preload options
            $('#nginx-cache-schedule-set').prop('disabled', true);
            $('#nppp_cron_event').prop('disabled', true);
            $('#nppp_datetimepicker1Input').prop('disabled', true);

            // disable preload proxy checkbox
            $('#nginx_cache_preload_enable_proxy').prop('disabled', true);

            // Disable proxy host/port fields
            $('#nginx_cache_preload_proxy_host').prop('disabled', true);
            $('#nginx_cache_preload_proxy_port').prop('disabled', true);

            // Disable preload tuning fields
            $('#nginx_cache_cpu_limit').prop('disabled', true);
            $('#nginx_cache_limit_rate').prop('disabled', true);
            $('#nginx_cache_wait_request').prop('disabled', true);

            // Disable preload exclude reset actions
            $('#nginx-regex-reset-defaults').prop('disabled', true);
            $('#nginx-extension-reset-defaults').prop('disabled', true);

            // Disable preload exclude fields (not editable/clickable)
            $('#nginx_cache_reject_regex').prop('disabled', true).attr('readonly', 'readonly');
            $('#nginx_cache_reject_extension').prop('disabled', true).attr('readonly', 'readonly');

            // Disable URL normalization control set
            (function disablePctNorm() {
                var $fs = $('#nppp-pctnorm');
                if (!$fs.length) {
                    return;
                }

                $fs.attr({'aria-disabled': 'true'}).css({
                    'opacity': '0.5',
                    'cursor': 'not-allowed'
                });

                $fs.find('label, .nppp-segcontrol-thumb').css('pointer-events', 'none');
                $fs.find('input[type="radio"]').prop('disabled', true)
                    .attr('tabindex', '-1')
                    .off('.nppp')
                    .on('click.nppp change.nppp', function(e) {
                        e.preventDefault();
                        return false;
                    });
            })();

            // Disable rest API preload stuff
            $('#nppp-preload-url .nppp-tooltip').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // Ensure the parent <p> tags are also non-clickable for rest API preload stuff
            $('#nppp-preload-url').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // style cron status heading
            $('.nppp-active-cron-heading').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });
        }

        // Hard-disable click routes for preload-only actions.
        npppHardDisableClick('#nppp-preload-button');
        npppHardDisableClick('#nppp-preload-url');
        npppHardDisableClick('#nppp-preload-url .nppp-tooltip');
        npppHardDisableClick('.nppp-preload-btn');
        npppHardDisableClick('#nginx-regex-reset-defaults');
        npppHardDisableClick('#nginx-extension-reset-defaults');
        npppHardDisableClick('#nginx-cache-schedule-set');
    });

    // Disable the Preload button on the Advanced Tab
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.data && settings.data.includes('action=nppp_load_premium_content')) {
            NppdisablePreloadButtons();
        }
    });

    // DataTables redraws rows on pagination/sort/filter, so re-apply disabled state each draw.
    $(document).on('draw.dt.npppDisablePreload', '#nppp-premium-table', function() {
        NppdisablePreloadButtons();
    });
})(jQuery);
