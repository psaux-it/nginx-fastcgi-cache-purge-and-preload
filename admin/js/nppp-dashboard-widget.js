/**
 * Dashboard widget scripts for Nginx Cache Purge Preload
 * Description: Controls preload animation and refresh behavior in the WordPress dashboard widget.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// NPP WP dashboard widget
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

        // Handle click events on "Purge All" and "Preload All" admin bar items
        $('.nppp-action-trigger').on('click', function() {
            showPreloader();
        });

        // Display status of ongoing Preload action
        function npppCheckPreloadStatus() {
            var npppPreloadInProgress = $('#nppp-preload-in-progress').length > 0;

            // Get the Preload All button related elements
            var npppPreloadButton = $('a[data-action="nppp-widget-preload"]');
            var npppPreloadIcon = npppPreloadButton.find('span');

            // Set the default styles
            var defaultStyles = {
                'font-size': '14px',
                'color': 'white',
                'background-color': '#3CB371',
                'padding': '8px 12px',
                'text-decoration': 'none',
                'font-weight': '500',
                'display': 'inline-flex',
                'align-items': 'center',
                'justify-content': 'center',
                'transition': 'background-color 0.3s ease',
                'flex': '48%',
                'opacity': '1',
                'pointer-events': 'auto',
            };

            // Check for the ongoing Preload process
            if (npppPreloadInProgress) {
                npppPreloadButton.css('color', 'orange');
                npppPreloadButton.css('pointer-events', 'none');
                //npppPreloadButton.css('transition', 'none');
                npppPreloadButton.prop('href', '#');

                // Start blinking effect
                npppstartBlinking(npppPreloadButton);

                // Change icon to clock
                npppPreloadIcon.removeClass('dashicons-update').addClass('dashicons-clock');
            } else {
                // Get the current background color
                var currentBackgroundColor = npppPreloadButton.css('background-color');

                // Revert styles only if they were previously modified
                if (currentBackgroundColor !== 'rgb(60, 179, 113)') {

                    // Apply the default styles
                    npppPreloadButton.css(defaultStyles);

                    // Apply default href
                    npppPreloadButton.prop('href', function() {
                        return $('a[data-action="nppp-widget-preload"]').data('href');
                    });

                    // Stop the blinking effect
                    npppstopBlinking(npppPreloadButton);

                    // Revert icon back
                    npppPreloadIcon.removeClass('dashicons-clock').addClass('dashicons-update');
                }
            }
        }

        // Function to start blinking
        function npppstartBlinking(element) {
            element.css('animation', 'nppp-blink 2s infinite alternate');
            element.css('animation-timing-function', 'ease-in-out');
        }

        // Function to stop blinking
        function npppstopBlinking(element) {
            element.css('animation', 'none');
        }

        // Call main
        npppCheckPreloadStatus();

        // Cache Hit Ratio gauge — animate from values baked in by PHP server-side.
        // Also handles the refresh button AJAX re-scan.
        (function () {
            var CIRCUMFERENCE = 232.48; // 2 * π * 37
            var __ = wp.i18n.__;
            var _n = wp.i18n._n;

            function ratioColor(pct) {
                if (pct >= 70) { return '#3CB371'; }
                if (pct >= 40) { return '#f0a500'; }
                return '#d9534f';
            }

            function humanizeAge(unixTs) {
                var diff    = Math.floor(Date.now() / 1000) - unixTs;
                var minutes = Math.floor(diff / 60);
                var hours   = Math.floor(diff / 3600);
                var days    = Math.floor(diff / 86400);
                if (diff < 60)    { return __( 'just now', 'fastcgi-cache-purge-and-preload-nginx' ); }
                if (diff < 3600)  { return minutes + ' ' + _n( 'minute ago', 'minutes ago', minutes, 'fastcgi-cache-purge-and-preload-nginx' ); }
                if (diff < 86400) { return hours   + ' ' + _n( 'hour ago',   'hours ago',   hours,   'fastcgi-cache-purge-and-preload-nginx' ); }
                return days + ' ' + _n( 'day ago', 'days ago', days, 'fastcgi-cache-purge-and-preload-nginx' );
            }

            var STALE_WARN_SEC  = 5  * 60;
            var STALE_PULSE_SEC = 30 * 60;

            function checkStaleness($strip, scannedAt) {
                if (!scannedAt) { return; }
                var age = Math.floor(Date.now() / 1000) - scannedAt;

                var $btn = $strip.find('.nppp-ratio-refresh');
                var $arc = $strip.find('.nppp-gauge-progress');
                var $age = $strip.find('.nppp-ratio-age');

                $btn.removeClass('nppp-btn-pulse');
                $arc.removeClass('nppp-gauge-stale');
                $age.removeClass('nppp-age-warn');

                if (age > STALE_PULSE_SEC) {
                    $arc.addClass('nppp-gauge-stale');
                    $btn.addClass('nppp-btn-pulse');
                    $age.addClass('nppp-age-warn');
                    $strip.css({ 'border-left-color': '#f0a500', 'border-bottom-color': '#f0a500' });
                } else if (age > STALE_WARN_SEC) {
                    $age.addClass('nppp-age-warn');
                }
            }

            // Shared renderer — called on page load and after every AJAX refresh.
            function animateGauge($strip, pct, hits, misses, total, scannedAt) {
                var color     = ratioColor(pct);
                var offset    = CIRCUMFERENCE - (pct / 100) * CIRCUMFERENCE;
                var $progress = $strip.find('.nppp-gauge-progress');
                var $pct      = $strip.find('.nppp-gauge-pct');
                var $detail   = $strip.find('#nppp-ratio-detail');

                $progress.attr('stroke', color);
                setTimeout(function () {
                    $progress.css({
                        'stroke-dashoffset': offset,
                        'transition':        'stroke-dashoffset 1.1s cubic-bezier(0.4,0,0.2,1)'
                    });
                }, 80);

                var startVal = parseFloat($pct.text()) || 0;
                $({ val: startVal }).animate({ val: pct }, {
                    duration: 1100,
                    easing:   'swing',
                    step:     function () { $pct.text(parseFloat(this.val).toFixed(1) + '%'); },
                    complete: function () { $pct.text(pct.toFixed(1) + '%'); }
                });
                $pct.css({ color: color, 'font-size': '' });

                var statsHtml =
                    '<div class="nppp-ratio-stats-row">' +
                        '<span class="nppp-ratio-hit">&#x2714; '  + hits   + ' ' + __( 'Cached',     'fastcgi-cache-purge-and-preload-nginx' ) + '</span>' +
                        '<span class="nppp-ratio-sep"> &nbsp;/&nbsp; </span>' +
                        '<span class="nppp-ratio-miss">&#x2718; ' + misses + ' ' + __( 'Not Cached',  'fastcgi-cache-purge-and-preload-nginx' ) + '</span>' +
                    '</div>';

                var ageHtml = '';

                if (scannedAt) {
                    ageHtml = '<div class="nppp-ratio-age-row">' +
                        '<span class="nppp-ratio-age" title="' + __( 'Last refreshed', 'fastcgi-cache-purge-and-preload-nginx' ) + '">' +
                        '&#x23F1; ' + humanizeAge(scannedAt) +
                        '</span>' +
                        '<span class="nppp-ratio-sep"> &nbsp;&middot;&nbsp; </span>' +
                        '<span class="nppp-ratio-total">' + total + ' ' + __( 'total', 'fastcgi-cache-purge-and-preload-nginx' ) + '</span>' +
                    '</div>';
                }

                $detail.html(statsHtml + ageHtml);
                $strip.css({ 'border-left-color': color, 'border-bottom-color': color });
            }

            var $strip = $('#nppp-ratio-strip');
            if (!$strip.length) { return; }

            var $progress = $strip.find('.nppp-gauge-progress');
            var $pct      = $strip.find('.nppp-gauge-pct');
            var $detail   = $('#nppp-ratio-detail');

            var isNa     = $strip.data('na') === '1' || $strip.data('na') === 1;
            var naReason = $strip.data('na-reason') || '';

            // Initial page-load render
            if (isNa) {
                $pct.text('N/A').css('font-size', '11px');
                var naMsg = (naReason === 'not_initialized')
                    ? __( 'Click \u21BB to scan and initialize..', 'fastcgi-cache-purge-and-preload-nginx' )
                    : __( 'Run a full Preload to generate a snapshot.',      'fastcgi-cache-purge-and-preload-nginx' );
                $detail.html('<span class="nppp-ratio-na">' + naMsg + '</span>');
                $progress.attr('stroke', '#ccc');
            } else {
                animateGauge(
                    $strip,
                    parseFloat($strip.data('ratio')),
                    parseInt($strip.data('hits'),       10),
                    parseInt($strip.data('misses'),     10),
                    parseInt($strip.data('total'),      10),
                    parseInt($strip.data('scanned-at'), 10)
                );
                checkStaleness($strip, parseInt($strip.data('scanned-at'), 10));
            }

            // Refresh button — triggers a live re-scan via AJAX without page reload.
            // Button is only rendered by PHP when snapshot + hit-count option both exist,
            // so by the time this handler fires the data is always available.
            $(document).on('click', '.nppp-ratio-refresh', function (e) {
                e.preventDefault();
                var $btn = $(this);

                // Debounce: ignore while a scan is already in progress
                if ($btn.hasClass('nppp-refreshing')) { return; }

                $btn.addClass('nppp-refreshing');
                $strip.addClass('nppp-ratio-loading');

                $.ajax({
                    url:    nppp_widget_data.ajaxurl,
                    method: 'POST',
                    data: {
                        action:   'nppp_refresh_cache_ratio',
                        _wpnonce:  nppp_widget_data.ratio_refresh_nonce
                    },
                    success: function (response) {
                        if (!response || !response.success) { return; }
                        var d = response.data;

                        if (d.na) {
                            var naMsg;
                            if      (d.na_reason === 'path_not_found') { naMsg = __( 'Cache path not found. Check Nginx cache path setting.',  'fastcgi-cache-purge-and-preload-nginx' ); }
                            else if (d.na_reason === 'undetermined')   { naMsg = __( 'Cache unreadable. Check cache directory permissions.',   'fastcgi-cache-purge-and-preload-nginx' ); }
                            else if (d.na_reason === 'regex_error')    { naMsg = __( 'Cache key regex error. Check Advanced Options.',         'fastcgi-cache-purge-and-preload-nginx' ); }
                            else                                       { naMsg = __( 'Run a full Preload to generate a snapshot.',             'fastcgi-cache-purge-and-preload-nginx' ); }
                            $strip.find('.nppp-gauge-pct').text('N/A').css('font-size', '11px');
                            $strip.find('#nppp-ratio-detail').html('<span class="nppp-ratio-na">' + naMsg + '</span>');
                            $strip.find('.nppp-gauge-progress').attr('stroke', '#ccc');
                            $strip.css({ 'border-left-color': '#ddd', 'border-bottom-color': '#ddd' });
                        } else {
                            animateGauge($strip, d.ratio, d.hits, d.misses, d.total, d.scanned_at);
                            checkStaleness($strip, d.scanned_at);
                        }
                    },
                    // Silent fail — existing gauge data stays intact on network error
                    complete: function () {
                        $btn.removeClass('nppp-refreshing');
                        $strip.removeClass('nppp-ratio-loading');
                    }
                });
            });
        })();
    });
})(jQuery);
