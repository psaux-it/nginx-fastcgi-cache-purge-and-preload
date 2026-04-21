/**
 * Admin interface scripts for Nginx Cache Purge Preload
 * Description: Handles interactive behavior for plugin settings, tabs, and admin actions.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// NPP plugin admin side main js code
(function ($) {
    'use strict';
    const { __, _x, _n, _nx, sprintf } = wp.i18n;

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

    // Toggle the FAB from outside
    function npppFabSet(forceHidden) {
        var fab = document.querySelector('.nppp-scrollfab');
        if (!fab) return;

        if (forceHidden) {
            // lock it hidden regardless of scroll
            fab.setAttribute('data-lock', 'true');
            fab.setAttribute('data-hidden', 'true');
        } else {
            // unlock and re-evaluate via scroll logic
            fab.removeAttribute('data-lock');
            window.dispatchEvent(new Event('scroll'));
        }
    }

    // Function to handle tab content activation
    function npppActivateTab(tabId) {
        // Hide all content placeholders
        $settingsPlaceholder.hide();
        $statusPlaceholder.hide();
        $premiumPlaceholder.hide();
        $helpPlaceholder.hide();

        // Badge bar: settings tab only
        var $badgeBar = $('#nppp-badge-bar');
        if ($badgeBar.length) {
            if (tabId === 'settings') {
                $badgeBar.show();
            } else {
                $badgeBar.hide();
            }
        }

        // Handle specific tab actions
        switch (tabId) {
            case 'settings':
                nppdisconnectObserver();
                $settingsPlaceholder.show();
                nppphighlightSubmenu('.nppp-submenu ul li a', 900);
                npppFabSet(false);
                break;
            case 'status':
                showPreloader();
                loadStatusTabContent();
                if (window.NPPPAurora && typeof window.NPPPAurora.setProgressGate === 'function') {
                    window.NPPPAurora.setProgressGate(true);
                }
                npppFabSet(true);
                break;
            case 'premium':
                showPreloader();
                nppdisconnectObserver();
                loadPremiumTabContent();
                npppFabSet(true);
                break;
            case 'help':
                nppdisconnectObserver();
                $helpPlaceholder.show();
                npppFabSet(true);
                break;
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
                const newId = ui.newPanel && ui.newPanel.attr('id');
                const oldId = ui.oldPanel && ui.oldPanel.attr('id');

                // If we are LEAVING Status, stop Status-specific background work
                if (oldId === 'status' && newId !== 'status') {
                    npppStopWgetPolling();
                    nppdisconnectObserver();
                }

                // Shut down aurora reactions off the Status tab
                if (oldId === 'status' && newId !== 'status' && window.NPPPAurora) {
                    if (typeof window.NPPPAurora.setProgressGate === 'function') {
                        window.NPPPAurora.setProgressGate(false);
                    }
                    if (typeof window.NPPPAurora.setMode === 'function') {
                        window.NPPPAurora.setMode('idle');
                    }
                    if (typeof window.NPPPAurora.setProgressPercent === 'function') {
                        window.NPPPAurora.setProgressPercent(0);
                    }
                }

                // Only trigger if it's a internal interaction (not direct link)
                if (!isTabChangeFromHash) {
                    const tabId = ui.newPanel.attr('id');
                    if (tabId) {
                        const _npppTabUrl = new URL(window.location.href);
                        _npppTabUrl.searchParams.set('nppp_tab', tabId);
                        _npppTabUrl.hash = tabId;
                        window.history.replaceState(null, null, _npppTabUrl.toString());
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
                    if (ui.jqXHR) ui.jqXHR.abort();
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

                // Hash-driven activation path: activate callback intentionally skips
                // npppActivateTab while isTabChangeFromHash is true.
                const tabId = hash.replace('#', '');
                npppActivateTab(tabId);

                // Persist active tab as GET param so PHP can see it on next hard reload.
                const _npppHashUrl = new URL(window.location.href);
                _npppHashUrl.searchParams.set('nppp_tab', tabId);
                _npppHashUrl.hash = tabId;
                window.history.replaceState(null, null, _npppHashUrl.toString());

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

    // Scroll FAB + anchor offset (scoped)
    (function () {
        // Only run on our screen
        var $container = $('#nppp-nginx-tabs');
        if (!$container.length) return;

        // Create back-to-top / bottom controls (only once)
        if (!document.querySelector('.nppp-scrollfab')) {
            var fab = document.createElement('div');
            fab.className = 'nppp-scrollfab';
            fab.setAttribute('data-hidden', 'true');

            var btnTop = document.createElement('button');
            btnTop.type = 'button';
            btnTop.setAttribute('aria-label', 'Back to top');
            btnTop.textContent = '↑ Top';

            var btnBottom = document.createElement('button');
            btnBottom.type = 'button';
            btnBottom.setAttribute('aria-label', 'Go to bottom');
            btnBottom.textContent = '↓ Bot';

            fab.appendChild(btnTop);
            fab.appendChild(btnBottom);
            document.body.appendChild(fab);

            // Helpers
            function wpAdminBarOffset() {
                var bar = document.getElementById('wpadminbar');
                return (bar ? bar.offsetHeight : 0) + 8;
            }
            function prefersNoMotion() {
                return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            }
            function smoothScrollTo(y) {
                if (prefersNoMotion()) {
                    window.scrollTo(0, y);
                } else {
                    window.scrollTo({ top: y, behavior: 'smooth' });
                }
            }

            // Top / Bottom clicks
            btnTop.addEventListener('click', function () {
                smoothScrollTo(0);
            });
            btnBottom.addEventListener('click', function () {
                smoothScrollTo(document.documentElement.scrollHeight);
            });

            // Show/hide controls after you scroll a bit
            var lastStateHidden = true;
            function onScroll() {
                // if locked, stay hidden no matter what
                if (fab.getAttribute('data-lock') === 'true') {
                    if (fab.getAttribute('data-hidden') !== 'true') {
                        fab.setAttribute('data-hidden', 'true');
                    }
                    return;
                }

                // only allow the FAB on the Settings tab
                var onSettings = $('#settings').is(':visible');

                // require some scroll depth AND being on Settings
                var hidden = !onSettings || window.scrollY < 400;

                if (hidden !== lastStateHidden) {
                    fab.setAttribute('data-hidden', hidden ? 'true' : 'false');
                    lastStateHidden = hidden;
                }
            }
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();

            // Anchor jumps INSIDE our container should respect the admin bar
            $container.on('click', 'a[href^="#"]', function (e) {
                var href = $(this).attr('href');
                if (!href || href === '#') return;

                var id = href.slice(1);
                var target = document.getElementById(id);
                if (!target) return;

                // Skip if it's a tab link (let your tabs code handle it)
                var tabIds = ['settings','status','premium','help'];
                if (tabIds.indexOf(id) !== -1) return;

                e.preventDefault();
                var rect = target.getBoundingClientRect();
                var y = window.scrollY + rect.top - wpAdminBarOffset() - 8;
                smoothScrollTo(y);

                // keep URL hash tidy without firing your hashchange logic
                history.replaceState(null, '', '#' + id);
            });

            // Re-evaluate FAB visibility after tab switches
            $container.on('tabsactivate', function () {
                setTimeout(onScroll, 0);
            });
        }
    })();

    // Ensure FAB visibility matches the current tab on load
    (function syncFabOnce(){
        var id = ($('#nppp-nginx-tabs .ui-tabs-panel:visible').attr('id')) || (location.hash || '#settings').slice(1);
        npppFabSet(id !== 'settings');
    })();

    // Listen for hash changes (URL changes) and re-activate the correct tab
    $(window).on('hashchange', function() {
        activateTabFromHash();
    });

    // Toasts
    function npppEnsureToastContainer() {
        let c = document.getElementById('nppp-toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'nppp-toast-container';
            // respect admin bar height
            const bar = document.getElementById('wpadminbar');
            c.style.top = (bar ? bar.offsetHeight + 12 : 12) + 'px';
            document.body.appendChild(c);
        }
        return c;
    }
    function npppInferType(msg, fallback='info'){
        if (/success/i.test(msg)) return 'success';
        if (/error|fail|denied|invalid/i.test(msg)) return 'error';
        if (/info|notice|warning/i.test(msg)) return 'info';
        return fallback;
    }
    function npppToast(message, type='info', timeout=5500){
        const c = npppEnsureToastContainer();

        // Map legacy types
        const map = {
            success: 'nppp-is-success',
            error:   'nppp-is-error',
            info:    'nppp-is-info',
            warning: 'nppp-is-info'
        };
        const variant = map[(type || 'info').toLowerCase()] || 'nppp-is-info';

        const t = document.createElement('div');
        t.className = 'nppp-toast ' + variant;
        t.setAttribute('role', 'status');
        t.setAttribute('aria-live', (variant === 'nppp-is-error') ? 'assertive' : 'polite');
        t.innerHTML = `
            <span class="nppp-ico" aria-hidden="true"></span>
            <span class="nppp-close" aria-label="${__('Dismiss','fastcgi-cache-purge-and-preload-nginx')}">×</span>
            <div class="nppp-msg"></div>
        `;
        t.querySelector('.nppp-msg').innerHTML = message;
        t.querySelector('.nppp-close').onclick = () => npppDismissToast(t);
        c.prepend(t);

        let hideTimer = setTimeout(() => npppDismissToast(t), timeout);
        t.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        t.addEventListener('mouseleave', () => hideTimer = setTimeout(() => npppDismissToast(t), 1500));
    }
    function npppDismissToast(t){
        if (!t) return;
        t.style.animation = 'nppp-slide-out .14s ease-in forwards';
        setTimeout(() => t.remove(), 160);
    }

    let npppPollActive       = false;
    let npppPollTimer        = null;
    let npppPollRetries      = 0;
    const NPPP_MAX_RETRIES   = 4;
    let snapshotMissingCount = 0;

    // Preload progress status
    function fetchWgetProgress() {
        const barText = document.getElementById("wpt-bar-text");
        const bar = document.getElementById("wpt-bar-inner");
        const status = document.getElementById("wpt-status");

        // Only run while polling is active
        if (!npppPollActive) return;

        // If Status tab is not visible anymore, stop safely.
        if (!$('#status').is(':visible')) {
            npppStopWgetPolling();
            return;
        }

        // If DOM isn’t ready yet, keep loop alive
        if (!bar || !status || !bar.isConnected || !status.isConnected) {
            if (npppPollTimer) clearTimeout(npppPollTimer);
            npppPollTimer = setTimeout(fetchWgetProgress, 800);
            return;
        }

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
            npppPollRetries = 0;
            if (!data.log_found) {
                const preloadSection = document.getElementById("nppp-preload-progress-section");
                if (preloadSection) preloadSection.style.display = "none";
                npppStopWgetPolling();
                return;
            } else {
                const preloadSection = document.getElementById("nppp-preload-progress-section");
                if (preloadSection) preloadSection.style.display = "block";
            }

            // Notify the aurora header with a targeted event — no global fetch/XHR patching needed.
            // nppp-header.js listens for this and drives all visual state from it.
            try {
                window.dispatchEvent(new CustomEvent('nppp:preload-progress', { detail: data }));
            } catch(_) {}

            const estTotal = data.total || 2000;
            let pct = Math.min(100, Math.round((data.checked / estTotal) * 100));

            // Detect interrupted BEFORE overriding pct so the raw estimate is preserved
            const isInterrupted = data.status === "done" && data.log_found && !data.log_complete && data.checked > 0;

            if (data.status === "done" && !isInterrupted) {
                pct = 100;
            } else if (data.status === "running") {
                // Hard-cap at 99 while process is alive — the estimate is imprecise,
                // and hitting 100 mid-run would stop polling prematurely.
                pct = Math.min(99, pct);
            }

            // Bar hidden — progress shown as table metric instead
            const barTrack = document.querySelector(".nppp-bar-track");
            if (barTrack) barTrack.style.display = "none";

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const icon = (cls, color) =>
                `<span class="dashicons ${cls}" style="color:${color};font-size:20px;vertical-align:middle;margin-right:4px;"></span>`;

            let rows = '';

            // Progress % — three distinct states
            let pctColor, pctIcon, pctCell;

            if (data.status === "running") {
                // In progress: animated bar, blue
                pctColor = "#337AB7";
                pctIcon  = "dashicons-chart-line";
                pctCell  = `<div class="nppp-pct-bar-wrap">
                                ${icon(pctIcon, pctColor)}
                                <div class="nppp-pct-bar-track">
                                    <div class="nppp-pct-bar-fill" style="width:${pct}%;background-color:${pctColor};">
                                        <span>${pct}%</span>
                                    </div>
                                </div>
                            </div>`;
            } else if (isInterrupted) {
                // Interrupted: approximate %, amber warning, tilde prefix
                pctColor = "#b45309";
                pctIcon  = "dashicons-warning";
                pctCell  = `${icon(pctIcon, pctColor)}<span style="color:${pctColor};font-weight:bold;">~${pct}%</span>`;
            } else {
                // Completed: 100%, green
                pctColor = "green";
                pctIcon  = "dashicons-chart-line";
                pctCell  = `${icon(pctIcon, pctColor)}<span style="color:${pctColor};font-weight:bold;">${pct}%</span>`;
            }

            rows += `<tr>
                <td class="check">${__('Progress (%)', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                <td class="status">${pctCell}</td>
            </tr>`;

            // Last Processed Page
            if (data.last_url) {
                rows += `<tr>
                    <td class="check">${__('Last Processed Page', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                    <td class="status">${icon('dashicons-visibility', '#2563eb')}<a href="${escapeHtml(data.last_url)}" target="_blank" rel="noopener" style="color:#2563eb;word-break:break-all;">${escapeHtml(data.last_url)}</a></td>
                </tr>`;
            }

            // Processed URLs
            rows += `<tr>
                <td class="check">${__('Processed URLs Count', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                <td class="status" style="color:green;">${icon('dashicons-admin-links', 'green')}${data.checked}</td>
            </tr>`;

            // Broken URLs Count
            if (data.errors > 0) {
                const hasErrors  = data.errors > 0;
                const errColor   = hasErrors ? "#d63638" : "green";
                const errIco     = hasErrors ? "dashicons-warning" : "dashicons-yes";
                rows += `<tr>
                    <td class="check">${__('Broken URLs (404) Count', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                    <td class="status" style="color:${errColor};">${icon(errIco, errColor)}${data.errors}</td>
                </tr>`;
            }

            // Recent Broken URLs
            if (Array.isArray(data.broken_urls) && data.broken_urls.length) {
                const brokenList = data.broken_urls
                    .map(url => `<li style="color:#d63638;">${icon('dashicons-warning','#d63638')}${escapeHtml(url)}</li>`)
                    .join('');
                rows += `<tr>
                    <td class="check">${__('Recent Broken URLs', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                    <td class="status"><ul class="nppp-broken-urls">${brokenList}</ul></td>
                </tr>`;
            }

            // Last Preload Started At — completion time minus elapsed duration
            if (data.last_preload_time && data.time) {
                const npppParseDuration = (str) => {
                    let secs = 0;
                    const h = str.match(/(\d+)\s*h/i);
                    const m = str.match(/(\d+)\s*m/i);
                    const s = str.match(/([\d.]+)\s*s/i);
                    if (h) secs += parseInt(h[1]) * 3600;
                    if (m) secs += parseInt(m[1]) * 60;
                    if (s) secs += parseFloat(s[1]);
                    return secs;
                };

                const durationSecs = npppParseDuration(data.time);
                const completedMs  = new Date(data.last_preload_time.replace(' ', 'T')).getTime();

                if (durationSecs > 0 && !isNaN(completedMs)) {
                    const startDate = new Date(completedMs - durationSecs * 1000);
                    const pad = (n) => String(n).padStart(2, '0');
                    const startStr = `${startDate.getFullYear()}-${pad(startDate.getMonth()+1)}-${pad(startDate.getDate())} ${pad(startDate.getHours())}:${pad(startDate.getMinutes())}:${pad(startDate.getSeconds())}`;
                    rows += `<tr>
                        <td class="check">${__('Last Preload Started At', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                        <td class="status" style="color:#374151;font-size:13px;font-weight:600;">${icon('dashicons-calendar-alt', '#6b7280')}${startStr}</td>
                    </tr>`;
                }
            }

            // Last Preload Completed At
            if (data.last_preload_time) {
                rows += `<tr>
                    <td class="check">${__('Last Preload Completed At', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                    <td class="status" style="color:#374151;font-size:13px;font-weight:600;">${icon('dashicons-calendar-alt', '#6b7280')}${data.last_preload_time}</td>
                </tr>`;
            }

            // Last Preload Completed In
            if (data.time) {
                rows += `<tr>
                    <td class="check">${__('Last Preload Completed In', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                    <td class="status" style="color:#374151;">${icon('dashicons-clock', '#6b7280')}${data.time}</td>
                </tr>`;
            }

            // Last Preload Status
            let statusValue = '';
            if (isInterrupted) {
                statusValue = `${icon('dashicons-warning', 'orange')}<span style="color:orange;font-weight:bold;">${__('Interrupted', 'fastcgi-cache-purge-and-preload-nginx')}</span>`;
            } else if (data.status === "done") {
                statusValue = `${icon('dashicons-update', 'green')}<span style="color:green;font-weight:bold;">${__('Completed', 'fastcgi-cache-purge-and-preload-nginx')}</span>`;
            } else {
                statusValue = `${icon('dashicons-clock', '#337AB7')}<span style="color:#337AB7;font-weight:bold;">${__('In Progress', 'fastcgi-cache-purge-and-preload-nginx')}</span>`;
            }
            rows += `<tr>
                <td class="check">${__('Last Preload Status', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                <td class="status">${statusValue}</td>
            </tr>`;

            // Snapshot Status + Last Snapshot Time — shown when interrupted OR completed-but-missing
            // Counter guards against false positive: snapshot write can lag up to ~5s after wget finishes.
            // Only flag as genuinely missing after 2 consecutive "done + no snapshot" polls (~6s grace).
            if (data.status === "running") {
                snapshotMissingCount = 0;
            } else if (data.status === "done" && !isInterrupted && !data.snapshot_exists) {
                snapshotMissingCount++;
            } else {
                snapshotMissingCount = 0;
            }

            const snapshotGenuinelyMissing = snapshotMissingCount >= 2;
            const showSnapshotRow = isInterrupted || snapshotGenuinelyMissing;
            if (showSnapshotRow) {
                let snapStatus = '';

                if (data.snapshot_exists) {
                    // Interrupted but a previous good snapshot exists
                    snapStatus = `${icon('dashicons-yes', '#16a34a')}<span style="color:#16a34a;">${__('Available', 'fastcgi-cache-purge-and-preload-nginx')}</span>`;
                } else if (isInterrupted) {
                    // Interrupted AND no snapshot at all
                    snapStatus = `${icon('dashicons-no', '#d63638')}<span style="color:#d63638;">${__('None — run Preload All to build one.', 'fastcgi-cache-purge-and-preload-nginx')}</span>`;
                } else {
                    // Completed successfully but snapshot not yet written.
                    // Two causes: (1) WP-Cron tick has not fired yet — normal on
                    // low-traffic sites, resolves on next visitor request.
                    // (2) Snapshot write actually failed (disk full, permissions) or manually deleted.
                    snapStatus = `${icon('dashicons-warning', '#b45309')}<span style="color:#b45309;font-weight:bold;">${__('Missing — last preload completed but snapshot not yet saved. On low-traffic sites this resolves automatically on the next visitor request (WP-Cron). If it persists, re-run Preload All.', 'fastcgi-cache-purge-and-preload-nginx')}</span>`;
                }

                rows += `<tr>
                    <td class="check">${__('Snapshot Status', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                    <td class="status">${snapStatus}</td>
                </tr>`;

                // Second row: timestamp only when snapshot exists and date is known
                if (data.snapshot_exists && data.snapshot_time) {
                    rows += `<tr>
                        <td class="check">${__('Last Snapshot Time', 'fastcgi-cache-purge-and-preload-nginx')}</td>
                        <td class="status" style="color:#374151;font-size:13px;">${icon('dashicons-calendar-alt', '#6b7280')}${data.snapshot_time}</td>
                    </tr>`;
                }
            }

            // Server Load
            if ( data.load_1 !== undefined && data.cpu_count ) {
                const loadPct   = ( data.load_1 / data.cpu_count ) * 100;
                const loadColor = loadPct > 80 ? '#d63638'
                                : loadPct > 50 ? '#b45309'
                                : '#16a34a';
                rows += `<tr>
                    <td class="check">${__( 'Server Load (1m / 5m)', 'fastcgi-cache-purge-and-preload-nginx' )}</td>
                    <td class="status">
                        ${icon( 'dashicons-performance', loadColor )}
                        <span style="color:${loadColor};font-weight:bold;">${data.load_1} / ${data.load_5}</span>
                        <span style="color:#9ca3af;font-size:12px;margin-left:4px;">(${data.cpu_count} CPU)</span>
                    </td>
                </tr>`;
            }

            // System RAM
            if ( data.mem_total_mb && data.mem_avail_mb !== undefined ) {
                const memUsedMb  = data.mem_total_mb - data.mem_avail_mb;
                const memPct     = Math.round( ( memUsedMb / data.mem_total_mb ) * 100 );
                const memColor   = memPct > 85 ? '#d63638'
                                 : memPct > 65 ? '#b45309'
                                 : '#16a34a';
                rows += `<tr>
                    <td class="check">${__( 'System RAM', 'fastcgi-cache-purge-and-preload-nginx' )}</td>
                    <td class="status">
                        ${icon( 'dashicons-database', memColor )}
                        <span style="color:${memColor};font-weight:bold;">${memPct}%</span>
                        <span style="color:#6b7280;font-size:12px;margin-left:4px;">${memUsedMb} / ${data.mem_total_mb} MB used</span>
                    </td>
                </tr>`;
            }

            // Swap — only show if swap is configured; red immediately if any swap used during preload
            if ( data.swap_total_mb > 0 ) {
                const swapPct   = Math.round( ( data.swap_used_mb / data.swap_total_mb ) * 100 );
                const swapColor = data.swap_used_mb > 0 ? '#d63638' : '#16a34a';
                rows += `<tr>
                    <td class="check">${__( 'Swap Usage', 'fastcgi-cache-purge-and-preload-nginx' )}</td>
                    <td class="status">
                        ${icon( 'dashicons-warning', swapColor )}
                        <span style="color:${swapColor};font-weight:bold;">${data.swap_used_mb} MB (${swapPct}%)</span>
                    </td>
                </tr>`;
            }

            // PHP-FPM pool pressure
            if ( data.fpm_active !== null && data.fpm_active !== undefined ) {
                const total       = ( data.fpm_active || 0 ) + ( data.fpm_idle || 0 );
                const queueed     = data.fpm_listen_queue || 0;
                const maxHit      = data.fpm_max_children || 0;
                const fpmColor    = queueed > 0 ? '#d63638'
                                  : maxHit  > 0 ? '#b45309'
                                  : '#16a34a';
                const queueBadge  = queueed > 0
                    ? `<span style="color:#d63638;font-weight:bold;margin-left:6px;">⚠ ${queueed} queued</span>`
                    : '';
                rows += `<tr>
                    <td class="check">${__( 'PHP-FPM Workers', 'fastcgi-cache-purge-and-preload-nginx' )}</td>
                    <td class="status">
                        ${icon( 'dashicons-networking', fpmColor )}
                        <span style="color:${fpmColor};font-weight:bold;">${data.fpm_active} active / ${data.fpm_idle} idle</span>
                        <span style="color:#9ca3af;font-size:12px;margin-left:4px;">(${total} total)</span>
                        ${queueBadge}
                    </td>
                </tr>`;
            }

            const html = `
                <table>
                    <thead>
                        <tr>
                            <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> ${__('Metric', 'fastcgi-cache-purge-and-preload-nginx')}</th>
                            <th class="status-header"><span class="dashicons dashicons-info"></span> ${__('Value', 'fastcgi-cache-purge-and-preload-nginx')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;

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
                } else if (data.status === "running") {
                    preloadStatusSpan.style.color = "green";
                    iconSpan.classList.add("dashicons", "dashicons-yes");
                    preloadStatusText = ' ' + __('Ready', 'fastcgi-cache-purge-and-preload-nginx');
                } else {
                    // Use pre-stored raw value (true/false)
                    const rawStatus = preloadStatusSpan.dataset.statusRaw;

                    if (rawStatus === "true") {
                        preloadStatusSpan.style.color = "green";
                        iconSpan.classList.add("dashicons", "dashicons-yes");
                        preloadStatusText = ' ' + __('Ready', 'fastcgi-cache-purge-and-preload-nginx');
                    } else {
                        preloadStatusSpan.style.color = "red";
                        iconSpan.classList.add("dashicons", "dashicons-no");
                        preloadStatusText = ' ' + __('Not Ready', 'fastcgi-cache-purge-and-preload-nginx');
                    }
                }

                preloadStatusSpan.appendChild(iconSpan);
                preloadStatusSpan.append(preloadStatusText);
            }

            if (data.status === "running") {
                // Polling is driven ONLY by process liveness — never by the estimated pct.
                // The estimate can overshoot 100 before wget actually finishes.
                if (npppPollTimer) clearTimeout(npppPollTimer);
                npppPollTimer = setTimeout(fetchWgetProgress, 800);
            } else {
                npppStopWgetPolling();
            }
        })
        .catch(err => {
            console.error('Fetch preload progress failed:', err);
            if (npppPollRetries < NPPP_MAX_RETRIES) {
                // Transient error — back off and retry rather than killing the poll.
                const backoff = 1000 * Math.pow(2, npppPollRetries);
                npppPollRetries++;
                if (npppPollTimer) clearTimeout(npppPollTimer);
                npppPollTimer = setTimeout(fetchWgetProgress, backoff);
            } else {
                // Persistent failure — give up cleanly.
                npppPollRetries = 0;
                npppStopWgetPolling();
            }
        });
    }

    // Polling guard (singleton)
    function npppStartWgetPolling(){
        if (npppPollActive) return;
        npppPollActive = true;
        fetchWgetProgress();
    }

    function npppStopWgetPolling(){
        npppPollActive = false;
        if (npppPollTimer) clearTimeout(npppPollTimer);
        npppPollTimer = null;
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

                    // Preload live status progress
                    (async () => {
                        await new Promise(resolve => setTimeout(resolve, 100));

                        const preloadStatusSpan = document.getElementById("nppppreloadStatus");
                        const preloadProgressRow = document.getElementById("nppp-preload-progress-section");

                        if (!preloadStatusSpan || !preloadProgressRow) return;

                        const preloadRawStatus = preloadStatusSpan.dataset.statusRaw?.toLowerCase();
                        if (preloadRawStatus === "true" || preloadRawStatus === "progress") {
                            preloadProgressRow.style.display = "block";
                            npppStartWgetPolling();
                        } else {
                            preloadProgressRow.style.display = "none";
                        }
                    })();

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

    // Function to load content for the 'Advanced' tab via AJAX
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
                if (response !== '') {
                    // Clean teardown if a previous DT exists
                    var tblSel = '#nppp-premium-table';
                    if ($.fn.dataTable.isDataTable(tblSel)) {
                        $(tblSel).DataTable().destroy(true);
                    }

                    // Inject fresh HTML and show placeholder
                    $premiumPlaceholder
                        .stop(true, true)
                        .css('opacity', 0)
                        .html(response)
                        .show();

                    // Init DT for the newly injected table
                    initializePremiumTable();

                    // Hide the preloader now that content is loaded
                    hidePreloader();

                    // Recalc again after the fade-in completes
                    $premiumPlaceholder.animate({ opacity: 1 }, 100, function () {
                        if ($.fn.dataTable.isDataTable(tblSel)) {
                            var dtLater = $(tblSel).DataTable();
                            dtLater.columns.adjust();
                            if (dtLater.responsive) dtLater.responsive.recalc();
                        }
                    });
                } else {
                    console.error('Empty response received for Premium tab.');
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

    // IMPORTANT
    var npppPreloadInProgress = false;

    function npppDT(){
        return $.fn.dataTable.isDataTable('#nppp-premium-table')
        ? $('#nppp-premium-table').DataTable()
        : null;
    }

    // Highlight row
    function npppFlashRow($row){
        var $main  = $row.hasClass('child') ? $row.prev('tr') : $row;
        var $child = $main.next('.child');

        // add highlight
        $main.addClass('purged-row');
        if ($child.length) $child.addClass('purged-row');

        // remove after your CSS animation finishes (adjust 900ms to your CSS)
        setTimeout(function(){
            $main.removeClass('purged-row');
            if ($child.length) $child.removeClass('purged-row');
        }, 900);
    }

    // Change HIT/MISS on fly
    function npppSetStatus($row, isHit){
        // if this is a responsive "child" row, target its parent
        var $main = $row.hasClass('child') ? $row.prev('tr') : $row;

        // DOM update (desktop)
        var $status = $main.find('td.nppp-status');
        $status.removeClass('is-hit is-miss')
            .addClass(isHit ? 'is-hit' : 'is-miss')
            .html('<strong>' + (isHit ? 'HIT' : 'MISS') + '</strong>');

        // invalidate JUST this cell in DT cache (no redraw)
        var dt = npppDT();
        if (dt && $status.length) dt.cell($status[0]).invalidate('dom');

        // Responsive child sync
        var $child = $main.next('.child');
        if ($child.length){
            var label = (window.nppp_admin_data && nppp_admin_data.col_cache_status) ? nppp_admin_data.col_cache_status : __('Status', 'fastcgi-cache-purge-and-preload-nginx');
            $child.find('.dtr-details li').each(function(){
                var $li = $(this);
                if ($li.find('.dtr-title').text().trim() === label){
                    $li.find('.dtr-data')
                    .removeClass('is-hit is-miss')
                    .addClass('nppp-status ' + (isHit ? 'is-hit' : 'is-miss'))
                    .html('<strong>' + (isHit ? 'HIT' : 'MISS') + '</strong>');
                }
            });
        }
    }

    // Extract a human message from a WP AJAX response
    function npppMsg(resp, fallback){
        if (!resp) return fallback || '';
        // success payloads usually have resp.data
        var d = resp.data;
        if (typeof d === 'string') return d;
        if (d && typeof d.message === 'string') return d.message;
        // legacy shape: sometimes message sits at top-level
        if (typeof resp.message === 'string') return resp.message;
        return fallback || '';
    }

    // Find the main (non-child) row by its URL cell text
    function npppFindRowByUrl(url){
        if (!url) return $();
        url = String(url).trim();

        var dt = npppDT();
        if (dt) {
            var match = $();
            dt.rows().every(function(){
                var $row  = $(this.node());
                var $main = $row.hasClass('child') ? $row.prev('tr') : $row;
                var text  = $main.find('td.nppp-url').text().trim();
                if (text === url) {
                    match = $main;
                    return false;
                }
            });
            return match;
        }

        // Non-DataTables fallback (shouldn’t be needed)
        var $r = $('#nppp-premium-table tbody tr').filter(function(){
            var $main = $(this).hasClass('child') ? $(this).prev('tr') : $(this);
            return $main.find('td.nppp-url').text().trim() === url;
        }).first();
        return $r.hasClass('child') ? $r.prev('tr') : $r;
    }

    // Apply the "just purged" state to a row
    function npppApplyPurgedState($row){
        if (!$row || !$row.length) return;
        var $main = $row.hasClass('child') ? $row.prev('tr') : $row;

        // Status -> MISS
        npppSetStatus($main, false);

        var $purgeBtn = $main.hasClass('dtr-expanded')
            ? $main.next('.child').find('.nppp-purge-btn')
            : $main.find('.nppp-purge-btn');

        $purgeBtn.prop('disabled', true).addClass('disabled');

        // Make preload available (unless it’s the global preload button you gate elsewhere)
        var $preloadBtn = $main.hasClass('dtr-expanded')
            ? $main.next('.child').find('.nppp-preload-btn')
            : $main.find('.nppp-preload-btn');

        if ($preloadBtn.length && !$preloadBtn.hasClass('nppp-general')) {
            $preloadBtn.prop('disabled', false).removeClass('disabled');
        }

        npppFlashRow($main);

        // Invalidate the row in DataTables cache
        var dt = npppDT();
        if (dt) {
            dt.row($main[0]).invalidate('dom');
            dt.draw(false);
        }
    }

    // Handle click event for purge buttons in advanced tab
    $(document).on('click', '.nppp-purge-btn', function(e) {
        e.preventDefault();

        // Get the data
        var btn = $(this);
        var cacheUrl = btn.data('url');
        var row = btn.closest('tr');

        // disable during request + inline spinner
        btn.prop('disabled', true).addClass('disabled');
        var spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo(btn);

        // AJAX request to purge cache
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'nppp_purge_cache_premium',
                cache_url: cacheUrl,
                _wpnonce: nppp_admin_data.premium_nonce_purge
            },
            success: function(response) {
                var msg  = npppMsg(response, __('Purge completed.','fastcgi-cache-purge-and-preload-nginx'));
                var type = (response && response.success) ? 'success' : npppInferType(msg, 'error');
                npppToast(msg, type);

                if (response && response.success) {
                    // Check if the row is expanded on mobile
                    if (row.hasClass('child')) {
                        row = row.prev('tr');
                    }

                    // Do not flip status to MISS when Auto Preload is enabled
                    var primaryPreload = !!(response && response.data && response.data.primary_preload);
                    if (!primaryPreload) {
                        npppSetStatus(row, false);
                    } else {
                        // Status stays HIT — page re-warms immediately, keep purge button active.
                        btn.prop('disabled', false).removeClass('disabled');
                    }

                    // find the preload button
                    var preloadBtn;
                    if (row.hasClass('dtr-expanded')) {
                        preloadBtn = row.next('.child').find('.nppp-preload-btn');
                    } else {
                        preloadBtn = row.find('.nppp-preload-btn');
                    }

                    // state changes
                    if (!preloadBtn.hasClass('nppp-general')) {
                        preloadBtn.css('background-color', '#43A047');
                        preloadBtn.prop('disabled', false);
                        preloadBtn.removeClass('disabled');
                        setTimeout(function(){ preloadBtn.css('background-color',''); }, 1200);
                    }
                    if (btn.css('background-color') === 'rgb(67, 160, 71)') {
                        btn.css('background-color', '');
                    }
                    npppFlashRow(row);

                    // Update related rows returned by the server
                    // Expected shape from PHP: response.data.affected_urls = [ "https://.../", ... ]
                    var affected = response && response.data && response.data.affected_urls;
                    var preloadAuto = !!(response && response.data && response.data.preload_auto);

                    if (affected && Array.isArray(affected) && affected.length) {
                        // Get the clicked row's URL to avoid double-applying (harmless if we don't check)
                        var thisUrl = row.find('td.nppp-url').text().trim();

                        affected.forEach(function(u){
                            u = String(u || '').trim();
                            if (!u) return;

                            var $rel = npppFindRowByUrl(u);
                            if (!$rel.length) return;

                            // Skip if it's the same row we already updated above
                            if (thisUrl && u === thisUrl) return;

                            // If auto-preload is enabled for related pages, do NOT flip to MISS
                            // and do NOT detach data-file here; let preload warm it back to HIT.
                            if (!preloadAuto) {
                                npppApplyPurgedState($rel);
                            }
                        });
                    }
                } else {
                    // on error
                    btn.prop('disabled', false).removeClass('disabled');
                }
            },
            error: function(xhr, status, error) {
                npppToast(error || __('AJAX error','fastcgi-cache-purge-and-preload-nginx'), 'error');
                btn.prop('disabled', false).removeClass('disabled');
            },
            complete: function() {
                spin.remove();
            }
        });
    });

    // Handle click event for preload buttons in advanced tab
    $(document).on('click', '.nppp-preload-btn', function(e) {
        e.preventDefault();

        // If another preload is already running, bail out
        if (npppPreloadInProgress) {
            npppToast(__('Preload is in progress, please wait…', 'fastcgi-cache-purge-and-preload-nginx'), 'info');
            return;
        }
        npppPreloadInProgress = true;

        // Disable ALL preload buttons while this one runs
        var allBtns = $('.nppp-preload-btn');
        allBtns.prop('disabled', true).addClass('disabled');

        // Get the data
        var btn = $(this);
        var cacheUrl = btn.data('url');
        var row = btn.closest('tr');

        // disable during request + inline spinner
        btn.prop('disabled', true).addClass('disabled');
        var spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo(btn);

        // AJAX request to preload
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'nppp_preload_cache_premium',
                cache_url: cacheUrl,
                _wpnonce: nppp_admin_data.premium_nonce_preload
            },
            success: function(response) {
                // response.data is now { message: '...', cached: true/false }
                var msg  = (response && response.data && response.data.message) ? response.data.message
                         : (response && typeof response.data === 'string')      ? response.data
                         : __('Preload queued.', 'fastcgi-cache-purge-and-preload-nginx');
                var type = (response && response.success) ? 'success' : npppInferType(msg, 'error');
                npppToast(msg, type);

                if (response && response.success) {
                    // Check if the row is expanded on mobile
                    if (row.hasClass('child')) {
                        row = row.prev('tr');
                    }

                    // locate the purge button (main vs child)
                    var purgeBtn = row.hasClass('dtr-expanded')
                        ? row.next('.child').find('.nppp-purge-btn')
                        : row.find('.nppp-purge-btn');

                    npppFlashRow(row);

                    if (response.data && response.data.cached === true) {
                        npppSetStatus(row, true);
                        purgeBtn.prop('disabled', false).removeClass('disabled');
                        purgeBtn.css('background-color', '#43A047');
                        setTimeout(function(){ purgeBtn.css('background-color', ''); }, 1200);
                    } else if (response.data && response.data.rg_used === true) {
                        // not cached
                        var cleanUrl = String(cacheUrl || '').trim();
                        npppToast(
                            sprintf(
                                __(
                                    "Couldn't verify that the cache was warmed for %s. This URL may be bypassed by Nginx " +
                                    "or the cache hasn't warmed yet. Refresh to recheck.",
                                    'fastcgi-cache-purge-and-preload-nginx'
                                ),
                                cleanUrl
                            ),
                            'info'
                        );
                    } else {
                        // optimistic HIT flip
                        npppSetStatus(row, true);
                        purgeBtn.prop('disabled', false).removeClass('disabled');
                        purgeBtn.css('background-color', '#43A047');
                        setTimeout(function(){ purgeBtn.css('background-color', ''); }, 1200);
                    }

                    npppPreloadInProgress = false;
                    $('.nppp-preload-btn').prop('disabled', false).removeClass('disabled');
                } else {
                    npppPreloadInProgress = false;
                    allBtns.prop('disabled', false).removeClass('disabled');
                    btn.prop('disabled', false).removeClass('disabled');
                }
            },
            error: function(xhr, status, error) {
                npppToast(error || __('AJAX error','fastcgi-cache-purge-and-preload-nginx'), 'error');
                npppPreloadInProgress = false;
                allBtns.prop('disabled', false).removeClass('disabled');
                btn.prop('disabled', false).removeClass('disabled');
            },
            complete: function() {
                spin.remove();
            }
        });
    });

    // === Percent-encoding Case (pctnorm) autosave ===
    (function npppPctnormAutoSave() {
        const $wrap = $('#nppp-pctnorm');
        if (!$wrap.length || !window.nppp_admin_data) return;

        const $radios = $wrap.find('input[name="nginx_cache_settings[nginx_cache_pctnorm_mode]"]');
        if (!$radios.length) return;

        // Track last committed value to allow revert on failure
        let lastVal = $radios.filter(':checked').val();

        // Lightweight inline badge
        function showMiniBadge(text, ok=true){
            // WordPress admin's common breakpoint
            const isMobile = window.matchMedia && window.matchMedia('(max-width: 782px)').matches;

            // On mobile, use the global toast
            if (isMobile) {
                // map ok -> toast type
                const type = ok ? 'success' : 'error';
                npppToast(String(text), type, 3000);
                return;
            }

            const off = $wrap.offset();
            if (!off) return;
            const $badge = $('<div/>', {
                text,
                class: 'nppp-mini-badge'
            }).css({
                position: 'absolute',
                left: off.left + $wrap.outerWidth() + 10,
                top:  off.top  + 2,
                backgroundColor: ok ? '#50C878' : '#D32F2F',
                color: '#fff',
                padding: '6px 10px',
                fontSize: '12px',
                fontWeight: 700,
                borderRadius: '4px',
                zIndex: 9999,
                opacity: 1,
                transition: 'opacity .3s ease'
            }).appendTo(document.body);

            setTimeout(() => {
                $badge.css('opacity', 0);
                setTimeout(() => $badge.remove(), 300);
            }, 1200);
        }

        // Debounce helper
        function debounce(fn, wait){ let t; return function(){ clearTimeout(t); t=setTimeout(() => fn.apply(this, arguments), wait); }; }

        const saveNow = debounce(function(){
            if ($wrap.hasClass('is-saving')) return;

            const $checked = $radios.filter(':checked');
            if (!$checked.length) return;

            const mode = $checked.val();
            if (mode === lastVal) return;

            // Disable during save
            $wrap.addClass('is-saving');

            $.ajax({
                url: nppp_admin_data.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'nppp_update_pctnorm_mode',
                    mode: mode,
                    _wpnonce: nppp_admin_data.pctnorm_nonce
                }
            })
            .done(function(resp){
                if (resp && resp.success) {
                    lastVal = mode;
                    showMiniBadge(
                        (resp.data && resp.data.message) || __('Percent-encoding.', 'fastcgi-cache-purge-and-preload-nginx'),
                        true
                    );
                } else {
                    // revert on error
                    $radios.filter('[value="'+ lastVal +'"]').prop('checked', true);
                    const msg = (resp && (resp.message || (resp.data && resp.data))) || __('Failed to save.', 'fastcgi-cache-purge-and-preload-nginx');
                    showMiniBadge(msg, false);
                }
            })
            .fail(function(xhr){
                // revert on ajax fail
                $radios.filter('[value="'+ lastVal +'"]').prop('checked', true);
                const j = xhr && xhr.responseJSON;
                const msg = (j && (j.message || (j.data && j.data))) || __('Network error.', 'fastcgi-cache-purge-and-preload-nginx');
                showMiniBadge(msg, false);
            })
            .always(function(){
                $wrap.removeClass('is-saving');
            });
        }, 200);

        // Save on change
        $wrap.on('change', 'input[name="nginx_cache_settings[nginx_cache_pctnorm_mode]"]', function(){
            if ($wrap.hasClass('is-saving')) return;
            saveNow();
        });
    })();

    // Update send mail status when state changes
    $('#nginx_cache_send_mail').change(function() {
        // Calculate the notification position
        var sendMailElement = $(this);
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
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Update auto preload status when state changes
    $('#nginx_cache_auto_preload').change(function() {
        // Calculate the notification position
        var autoPreloadElement = $(this);
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
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Update enable proxy status when state changes
    $('#nginx_cache_preload_enable_proxy').change(function() {
        var proxyElement = $(this);
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
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Update preload mobile status when state changes
    $('#nginx_cache_auto_preload_mobile').change(function() {
        var mobilePreloadElement = $(this);
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
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Update watchdog status when state changes
    $('#nginx_cache_watchdog').change(function() {
        var watchdogElement = $(this);
        var clickToCopySpanWatchdog = watchdogElement.next('.nppp-onoffswitch-label-watchdog');
        var clickToCopySpanOffsetWatchdog = clickToCopySpanWatchdog.offset();
        var notificationLeftWatchdog = clickToCopySpanOffsetWatchdog.left + clickToCopySpanWatchdog.outerWidth() + 10;
        var notificationTopWatchdog = clickToCopySpanOffsetWatchdog.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_watchdog_option',
            watchdog: isChecked,
            _wpnonce: nppp_admin_data.watchdog_nonce
        }, function(response) {
            if (response.success) {
                var notification = document.createElement('div');
                notification.textContent = '✔';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftWatchdog + 'px';
                notification.style.top = notificationTopWatchdog + 'px';
                notification.style.backgroundColor = '#50C878';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                document.body.appendChild(notification);

                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        document.body.removeChild(notification);
                    }, 300);
                }, 1000);
            } else {
                $('#nginx_cache_watchdog').prop('checked', !$('#nginx_cache_watchdog').prop('checked'));
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Auto Purge Triggers sub-options
    (function npppSetupAutoPurgeTriggers() {
        const $npppTrigFS = $('#nppp-autopurge-triggers-fieldset');
        if (!$npppTrigFS.length || !window.nppp_admin_data) return;

        // Inline status badge inserted after the fieldset wrapper div
        const $npppTrigStatus = $('<span/>', {
            'class': 'nppp-related-status',
            'aria-live': 'polite',
            'aria-atomic': 'true',
            'role': 'status'
        }).attr('data-state', 'idle').hide().insertAfter($npppTrigFS);

        const npppTrigGet = () => ({
            nppp_autopurge_posts:    $npppTrigFS.find('#nppp_autopurge_posts').is(':checked')    ? 'yes' : 'no',
            nppp_autopurge_terms:    $npppTrigFS.find('#nppp_autopurge_terms').is(':checked')    ? 'yes' : 'no',
            nppp_autopurge_plugins:  $npppTrigFS.find('#nppp_autopurge_plugins').is(':checked')  ? 'yes' : 'no',
            nppp_autopurge_themes:   $npppTrigFS.find('#nppp_autopurge_themes').is(':checked')   ? 'yes' : 'no',
            nppp_autopurge_3rdparty: $npppTrigFS.find('#nppp_autopurge_3rdparty').is(':checked') ? 'yes' : 'no'
        });

        const npppTrigDisable = (flag) => $npppTrigFS.find('input[type=checkbox]').prop('disabled', flag);
        let npppTrigHideTimer;

        function npppTrigSetStatus(state, html, ttlMs) {
            clearTimeout(npppTrigHideTimer);
            $npppTrigStatus.attr('data-state', state).html(html).show();
            if (ttlMs) {
                npppTrigHideTimer = setTimeout(() => {
                    $npppTrigStatus.attr('data-state', 'idle').empty().hide();
                }, ttlMs);
            }
        }

        const npppTrigShowSaving = () => npppTrigSetStatus(
            'saving',
            '<span class="dashicons dashicons-update" aria-hidden="true"></span>' +
            '<span class="nppp-sr-only">' + __('Saving', 'fastcgi-cache-purge-and-preload-nginx') + '</span>' +
            '<span>' + __('Saving…', 'fastcgi-cache-purge-and-preload-nginx') + '</span>'
        );
        const npppTrigShowSaved = () => npppTrigSetStatus(
            'saved',
            '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' +
            '<span class="nppp-sr-only">' + __('Saved', 'fastcgi-cache-purge-and-preload-nginx') + '</span>' +
            '<span>' + __('Saved', 'fastcgi-cache-purge-and-preload-nginx') + '</span>',
            1000
        );
        const npppTrigShowError = (msg) => npppTrigSetStatus(
            'error',
            '<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' +
            '<span class="nppp-sr-only">' + __('Error', 'fastcgi-cache-purge-and-preload-nginx') + '</span>' +
            '<span>' + (msg || __('Failed to save', 'fastcgi-cache-purge-and-preload-nginx')) + '</span>',
            2000
        );

        const npppTrigRevertTo = (v) => {
            $npppTrigFS.find('#nppp_autopurge_posts').prop('checked',    v.nppp_autopurge_posts    === 'yes');
            $npppTrigFS.find('#nppp_autopurge_terms').prop('checked',    v.nppp_autopurge_terms    === 'yes');
            $npppTrigFS.find('#nppp_autopurge_plugins').prop('checked',  v.nppp_autopurge_plugins  === 'yes');
            $npppTrigFS.find('#nppp_autopurge_themes').prop('checked',   v.nppp_autopurge_themes   === 'yes');
            $npppTrigFS.find('#nppp_autopurge_3rdparty').prop('checked', v.nppp_autopurge_3rdparty === 'yes');
        };

        let npppTrigLast   = npppTrigGet();
        let npppTrigSaving = false;

        function npppTrigSaveNow() {
            if (npppTrigSaving) return;

            npppTrigSaving = true;
            $npppTrigFS.addClass('is-saving');
            npppTrigDisable(true);
            npppTrigShowSaving();

            const payload = npppTrigGet();

            $.ajax({
                url: nppp_admin_data.ajaxurl,
                method: 'POST',
                dataType: 'json',
                timeout: 15000,
                data: {
                    action:    'nppp_update_autopurge_triggers',
                    _wpnonce:  nppp_admin_data.autopurge_triggers_nonce,
                    fields:    payload
                }
            }).done((res) => {
                if (res && res.success) {
                    const normalized =
                        (res.data && (res.data.data || res.data.normalized || res.data)) || payload;
                    npppTrigLast = normalized;
                    npppTrigRevertTo(normalized);
                    npppTrigShowSaved();
                } else {
                    npppTrigRevertTo(npppTrigLast);
                    const msg = (res && res.data && res.data.message) || 'Failed to save';
                    npppTrigShowError(msg);
                }
            }).fail((xhr) => {
                npppTrigRevertTo(npppTrigLast);
                const j   = xhr && xhr.responseJSON;
                const msg = (j && (j.message || (j.data && j.data.message))) || 'Network error';
                npppTrigShowError(msg);
            }).always(() => {
                npppTrigSaving = false;
                $npppTrigFS.removeClass('is-saving');
                // Re-enable only when master is ON
                const masterOn = $('#nginx_cache_purge_on_update').prop('checked');
                npppTrigDisable(!masterOn);
            });
        }

        const npppTrigDebounce = (fn, wait) => {
            let t;
            return function () { clearTimeout(t); t = setTimeout(fn, wait); };
        };

        // Debounce-save any checkbox change within this fieldset
        $npppTrigFS.on('change', 'input[type=checkbox]', npppTrigDebounce(npppTrigSaveNow, 350));
    })();

    // Update auto purge status when state changes
    $('#nginx_cache_purge_on_update').change(function() {
        // Calculate the notification position
        var autoPurgeElement = $(this);
        var clickToCopySpanAutoPurge = autoPurgeElement.next('.nppp-onoffswitch-label-autopurge');
        var clickToCopySpanOffsetAutoPurge = clickToCopySpanAutoPurge.offset();
        var notificationLeftAutoPurge = clickToCopySpanOffsetAutoPurge.left + clickToCopySpanAutoPurge.outerWidth() + 10;
        var notificationTopAutoPurge = clickToCopySpanOffsetAutoPurge.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        var masterIsOn = $(this).prop('checked');

        // Sync the Auto Purge Triggers sub-options disabled state immediately
        var $trigFS = $('#nppp-autopurge-triggers-fieldset');
        if ($trigFS.length) {
            $trigFS.find('input[type=checkbox]')
                .prop('disabled', !masterIsOn)
                .attr('aria-disabled', masterIsOn ? 'false' : 'true');
            if (masterIsOn) {
                $trigFS.removeClass('nppp-autopurge-triggers-off');
            } else {
                $trigFS.addClass('nppp-autopurge-triggers-off');
            }
        }

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
                if (!masterIsOn) {
                    $('#nppp-autopurge-triggers-fieldset').find('input[type=checkbox]').prop('checked', false);
                }
            } else {
                // Error updating option, revert checkbox
                $('#nginx_cache_purge_on_update').prop('checked', !$('#nginx_cache_purge_on_update').prop('checked'));

                // Revert sub-options disabled state
                var $trigFSErr = $('#nppp-autopurge-triggers-fieldset');
                if ($trigFSErr.length) {
                    var revertMasterOn = $('#nginx_cache_purge_on_update').prop('checked');
                    $trigFSErr.find('input[type=checkbox]')
                        .prop('disabled', !revertMasterOn)
                        .attr('aria-disabled', revertMasterOn ? 'false' : 'true');
                    revertMasterOn ? $trigFSErr.removeClass('nppp-autopurge-triggers-off') : $trigFSErr.addClass('nppp-autopurge-triggers-off');
                }
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Update Cloudflare APO sync status when state changes
    $('#nppp_cloudflare_apo_sync').change(function() {
        var cloudflareElement = $(this);
        var clickToCopySpanCloudflare = cloudflareElement.next('.nppp-onoffswitch-label-cloudflare');
        var clickToCopySpanOffsetCloudflare = clickToCopySpanCloudflare.offset();
        var notificationLeftCloudflare = clickToCopySpanOffsetCloudflare.left + clickToCopySpanCloudflare.outerWidth() + 10;
        var notificationTopCloudflare = clickToCopySpanOffsetCloudflare.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action: 'nppp_update_cloudflare_apo_sync_option',
            cloudflare_sync: isChecked,
            _wpnonce: nppp_admin_data.cloudflare_apo_sync_nonce
        }, function(response) {
            // Handle response
            if (response.success) {
                // Show a small notification indicating successfully saved option
                var notification = document.createElement('div');
                notification.textContent = '✔';
                notification.style.position = 'absolute';
                notification.style.left = notificationLeftCloudflare + 'px';
                notification.style.top = notificationTopCloudflare + 'px';
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
                $('#nppp_cloudflare_apo_sync').prop('checked', !$('#nppp_cloudflare_apo_sync').prop('checked'));
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Redis Object Cache sync toggle
    $('#nppp_redis_cache_sync').change(function() {
        var redisElement     = $(this);
        var labelSpan        = redisElement.next('.nppp-onoffswitch-label-redis');
        var labelOffset      = labelSpan.offset();
        var notifLeft        = labelOffset.left + labelSpan.outerWidth() + 10;
        var notifTop         = labelOffset.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action:           'nppp_update_redis_cache_sync_option',
            redis_cache_sync: isChecked,
            _wpnonce:         nppp_admin_data.redis_cache_sync_nonce
        }, function(response) {
            if (response.success) {
                var notification       = document.createElement('div');
                notification.textContent = '\u2714';
                notification.style.cssText = [
                    'position:absolute',
                    'left:'  + notifLeft + 'px',
                    'top:'   + notifTop  + 'px',
                    'background-color:#50C878',
                    'color:#fff',
                    'padding:8px 12px',
                    'transition:opacity 0.3s ease-in-out',
                    'opacity:1',
                    'z-index:9999',
                    'font-size:13px',
                    'font-weight:700'
                ].join(';');
                document.body.appendChild(notification);
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() { document.body.removeChild(notification); }, 300);
                }, 1000);
            } else {
                $('#nppp_redis_cache_sync').prop('checked', !$('#nppp_redis_cache_sync').prop('checked'));
                if (typeof npppToast === 'function') {
                    npppToast('Error updating option!', 'error');
                }
            }
        }, 'json');
    });

    // Update HTTP purge fast-path toggle when state changes
    $('#nppp_http_purge_enabled').change(function() {
        var httpPurgeElement = $(this);
        var labelSpan        = httpPurgeElement.next('.nppp-onoffswitch-label-httppurge');
        var labelOffset      = labelSpan.offset();
        var notifLeft        = labelOffset.left + labelSpan.outerWidth() + 10;
        var notifTop         = labelOffset.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action:     'nppp_update_http_purge_option',
            http_purge: isChecked,
            _wpnonce:   nppp_admin_data.http_purge_nonce
        }, function(response) {
            if (response.success) {
                var notification       = document.createElement('div');
                notification.textContent = '\u2714';
                notification.style.cssText = [
                    'position:absolute',
                    'left:'  + notifLeft + 'px',
                    'top:'   + notifTop  + 'px',
                    'background-color:#50C878',
                    'color:#fff',
                    'padding:8px 12px',
                    'transition:opacity 0.3s ease-in-out',
                    'opacity:1',
                    'z-index:9999',
                    'font-size:13px',
                    'font-weight:700'
                ].join(';');
                document.body.appendChild(notification);
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() { document.body.removeChild(notification); }, 300);
                }, 1000);
            } else {
                $('#nppp_http_purge_enabled').prop('checked', !$('#nppp_http_purge_enabled').prop('checked'));
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // RG Purge toggle when state changes
    $('#nppp_rg_purge_enabled').change(function() {
        var rgPurgeElement = $(this);
        var labelSpan      = rgPurgeElement.next('.nppp-onoffswitch-label-rgpurge');
        var labelOffset    = labelSpan.offset();
        var notifLeft      = labelOffset.left + labelSpan.outerWidth() + 10;
        var notifTop       = labelOffset.top;

        var isChecked = $(this).prop('checked') ? 'yes' : 'no';
        $.post(nppp_admin_data.ajaxurl, {
            action:    'nppp_update_rg_purge_option',
            rg_purge:  isChecked,
            _wpnonce:  nppp_admin_data.rg_purge_nonce
        }, function(response) {
            if (response.success) {
                var notification       = document.createElement('div');
                notification.textContent = '\u2714';
                notification.style.cssText = [
                    'position:absolute',
                    'left:'  + notifLeft + 'px',
                    'top:'   + notifTop  + 'px',
                    'background-color:#50C878',
                    'color:#fff',
                    'padding:8px 12px',
                    'transition:opacity 0.3s ease-in-out',
                    'opacity:1',
                    'z-index:9999',
                    'font-size:13px',
                    'font-weight:700'
                ].join(';');
                document.body.appendChild(notification);
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() { document.body.removeChild(notification); }, 300);
                }, 1000);
            } else {
                $('#nppp_rg_purge_enabled').prop('checked', !$('#nppp_rg_purge_enabled').prop('checked'));
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Related Pages
    (function npppSetupRelatedAutoSave() {
        const $npppRelWrappers = $('.nppp-related-pages').not('[data-nppp-rel-init]');
        if (!$npppRelWrappers.length || !window.nppp_admin_data) return;

        $npppRelWrappers.each(function () {
            const $npppRelFS = $(this).attr('data-nppp-rel-init', '1');

            // Cache the dependency inputs + preload input
            const $preload = $npppRelFS.find('[name="nginx_cache_settings[nppp_related_preload_after_manual]"]');
            const $deps = $npppRelFS.find(
                '[name="nginx_cache_settings[nppp_related_include_home]"],' +
                '[name="nginx_cache_settings[nppp_related_include_category]"],' +
                '[name="nginx_cache_settings[nppp_related_apply_manual]"]'
            );

            // Inline status badge after this fieldset
            const $npppRelStatus = $('<span/>', {
                'class': 'nppp-related-status',
                'aria-live': 'polite',
                'aria-atomic': 'true',
                'role': 'status'
            }).attr('data-state', 'idle').hide().insertAfter($npppRelFS);

            const npppRelGet = () => ({
                nppp_related_include_home:
                    $npppRelFS.find('[name="nginx_cache_settings[nppp_related_include_home]"]').is(':checked') ? 'yes' : 'no',
                nppp_related_include_category:
                    $npppRelFS.find('[name="nginx_cache_settings[nppp_related_include_category]"]').is(':checked') ? 'yes' : 'no',
                nppp_related_apply_manual:
                    $npppRelFS.find('[name="nginx_cache_settings[nppp_related_apply_manual]"]').is(':checked') ? 'yes' : 'no',
                nppp_related_preload_after_manual:
                    $npppRelFS.find('[name="nginx_cache_settings[nppp_related_preload_after_manual]"]').is(':checked') ? 'yes' : 'no'
            });

            const npppRelDisable = (flag) => $npppRelFS.find('input[type=checkbox]').prop('disabled', flag);
            let npppRelHideTimer;

            function npppRelSetStatus(state, html, ttlMs) {
                clearTimeout(npppRelHideTimer);
                $npppRelStatus.attr('data-state', state).html(html).show();
                if (ttlMs) {
                    npppRelHideTimer = setTimeout(() => {
                        $npppRelStatus.attr('data-state', 'idle').empty().hide();
                    }, ttlMs);
                }
            }

            const npppRelShowSaving = () => npppRelSetStatus(
                'saving',
                '<span class="dashicons dashicons-update" aria-hidden="true"></span>' +
                '<span class="nppp-sr-only">' + __('Saving', 'fastcgi-cache-purge-and-preload-nginx') + '</span>' +
                '<span>' + __('Saving…', 'fastcgi-cache-purge-and-preload-nginx') + '</span>'
            );
            const npppRelShowSaved = () => npppRelSetStatus(
                'saved',
                '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' +
                '<span class="nppp-sr-only">' + __('Saved', 'fastcgi-cache-purge-and-preload-nginx') + '</span>' +
                '<span>' + __('Saved', 'fastcgi-cache-purge-and-preload-nginx') + '</span>',
                1000
            );
            const npppRelShowError = (msg) => npppRelSetStatus(
                'error',
                '<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' +
                '<span class="nppp-sr-only">' + __('Error', 'fastcgi-cache-purge-and-preload-nginx') + '</span>' +
                '<span>' + (msg || __('Failed to save', 'fastcgi-cache-purge-and-preload-nginx')) + '</span>',
                2000
            );

            const npppRelRevertTo = (v) => {
                $npppRelFS.find('[name="nginx_cache_settings[nppp_related_include_home]"]').prop('checked', v.nppp_related_include_home === 'yes');
                $npppRelFS.find('[name="nginx_cache_settings[nppp_related_include_category]"]').prop('checked', v.nppp_related_include_category === 'yes');
                $npppRelFS.find('[name="nginx_cache_settings[nppp_related_apply_manual]"]').prop('checked', v.nppp_related_apply_manual === 'yes');
                $npppRelFS.find('[name="nginx_cache_settings[nppp_related_preload_after_manual]"]').prop('checked', v.nppp_related_preload_after_manual === 'yes');
                npppRelUpdatePreloadState();
            };

            // Dependency enforcer (UI)
            function npppRelUpdatePreloadState() {
                const anyOn = $deps.toArray().some(el => el.checked);

                // gate the preload checkbox
                $preload.prop('disabled', !anyOn).attr('aria-disabled', !anyOn ? 'true' : 'false');
                if (!anyOn) $preload.prop('checked', false);

                // manage the hint node inside the preload row's description
                const $desc  = $npppRelFS.find('#nppp_rel_preload + label .desc');
                const $hint  = $desc.find('.nppp-hint');

                if (anyOn) {
                    // at least one related is enabled -> remove hint if present
                    if ($hint.length) $hint.remove();
                } else {
                    // none enabled -> ensure hint exists (but don't duplicate)
                    if (!$hint.length) {
                        const $newHint = $('<em/>', {
                            'class': 'nppp-hint',
                            html:
                                '<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
                                '<span>' + __('Enable at least one above to unlock this.', 'fastcgi-cache-purge-and-preload-nginx') + '</span>'
                        });
                        $desc.append($newHint);
                    }
                }
            }

            let npppRelLast = npppRelGet();
            let npppRelSaving = false;

            function npppRelSaveNow() {
                if (npppRelSaving) return;

                npppRelUpdatePreloadState();
                npppRelSaving = true;
                $npppRelFS.addClass('is-saving');
                npppRelDisable(true);
                npppRelShowSaving();

                const payload = npppRelGet();

                $.ajax({
                    url: nppp_admin_data.ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    timeout: 15000,
                    data: {
                        action: 'nppp_update_related_fields',
                        _wpnonce: nppp_admin_data.related_purge_nonce,
                        fields: payload
                    }
                }).done((res) => {
                    if (res && res.success) {
                        // Trust server’s normalized result
                        const normalized =
                            (res.data && (res.data.data || res.data.normalized || res.data)) || payload;
                        npppRelLast = normalized;
                        npppRelRevertTo(normalized);
                        npppRelShowSaved();
                    } else {
                        npppRelRevertTo(npppRelLast);
                        const msg = (res && (res.message || (res.data && res.data.message))) || 'Failed to save';
                        npppRelShowError(msg);
                    }
                }).fail((xhr) => {
                    npppRelRevertTo(npppRelLast);
                    const j = xhr && xhr.responseJSON;
                    const msg = (j && (j.message || (j.data && j.data.message))) || 'Network error';
                    npppRelShowError(msg);
                }).always(() => {
                    npppRelSaving = false;
                    $npppRelFS.removeClass('is-saving');
                    npppRelDisable(false);
                    npppRelUpdatePreloadState();
                });
            }

            const npppRelDebounce = (fn, wait) => { let t; return function(){ clearTimeout(t); t = setTimeout(fn, wait); }; };

            // Initialize gating immediately
            npppRelUpdatePreloadState();

            // When any dependency changes, update gating immediately
            $deps.on('change', npppRelUpdatePreloadState);

            // Debounce-save any checkbox change within this fieldset
            $npppRelFS.on('change', 'input[type=checkbox]', npppRelDebounce(npppRelSaveNow, 350));
        });
    })();

    // Update rest api status when state changes
    $('#nginx_cache_api').change(function() {
        // Calculate the notification position
        var restApiElement = $(this);
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
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Click event handler for the #nginx-cache-schedule-set button
    // Update schedule option values accordingly
    $('#nginx-cache-schedule-set').on('click', function(e) {
        e.preventDefault();

        var npppcronEvent = $('#nppp_cron_event').val();
        var nppptime = $('#nppp_datetimepicker1Input').val();

        // Validate npppcronEvent on client side
        if (!npppcronEvent || (npppcronEvent !== 'daily' && npppcronEvent !== 'weekly' && npppcronEvent !== 'monthly')) {
            npppToast(__('Please select cron schedule frequency "On Every"', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            return;
        }

        // Validate nppptime format on client side
        var timeRegex = /^([01]\d|2[0-3]):([0-5]\d)$/;
        if (!nppptime || !nppptime.match(timeRegex)) {
            npppToast(__('Please select cron "Time"', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            return;
        }

        // AJAX request to send data to server with nonce
        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
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
                    dataType: 'json',
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
        var cacheScheduleElement = $(this);
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
                npppToast(__('Error updating option!', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            }
        }, 'json');
    });

    // Click event handler for cancel event button
    $(document).on('click', '.nppp-cancel-btn', function (e) {
        e.preventDefault();

        const $btn = $(this);
        const hook = $btn.data('hook');

        if (!hook) {
            npppToast(__('Missing cron.', 'fastcgi-cache-purge-and-preload-nginx'), 'error');
            return;
        }

        // lock UI + inline spinner
        $btn.prop('disabled', true).addClass('disabled');
        const $spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo($btn);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'nppp_cancel_scheduled_event',
                hook: hook,
                _wpnonce: nppp_admin_data.cancel_scheduled_event_nonce
            }
        })
            .done(function (resp) {
                const msg  = (resp && resp.data) ? resp.data : __('Scheduled event cancelled.', 'fastcgi-cache-purge-and-preload-nginx');
                const type = (resp && resp.success) ? 'success' : npppInferType(msg, 'error');
                npppToast(msg, type);

                // refresh the list UI like before
                $('.scheduled-events-list').empty().append(
                    '<div class="nppp-scheduled-event">' +
                        '<h3 class="nppp-active-cron-heading">' + __('Cron Status', 'fastcgi-cache-purge-and-preload-nginx') + '</h3>' +
                        '<div class="nppp-scheduled-event" style="padding-right:45px;">' +
                            __('No active scheduled events found!', 'fastcgi-cache-purge-and-preload-nginx') +
                        '</div>' +
                    '</div>'
                );

                if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
                    wp.a11y.speak(__('Scheduled event cancelled.', 'fastcgi-cache-purge-and-preload-nginx'));
                }
            })
            .fail(function (xhr) {
                const msg =
                    (xhr && xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data :
                    (xhr && xhr.responseText) ? xhr.responseText :
                    __('An error occurred while canceling the scheduled event.', 'fastcgi-cache-purge-and-preload-nginx');
                npppToast(msg, 'error');
                console.error(xhr);
            })
            .always(function () {
                $spin.remove();
                $btn.prop('disabled', false).removeClass('disabled');
            });
    });

    // Clear logs on back-end and update them on front-end
    $('#clear-logs-button').on('click', function(event) {
        event.preventDefault();

        const $btn  = $(this);
        $btn.prop('disabled', true).addClass('disabled');
        const $spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo($btn);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
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
                    dataType: 'json',
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
                }).always(function () {
                    $spin.remove();
                    $btn.prop('disabled', false).removeClass('disabled');
                });
            },
            // Error clearing logs
            error: function(xhr, status, error) {
                console.error('AJAX request failed:', status, error);
                $spin.remove();
                $btn.prop('disabled', false).removeClass('disabled');
            }
        });
    });

    // Make AJAX request to update API key option
    $('#api-key-button').on('click', function(event) {
        event.preventDefault();

        const $btn  = $(this);
        $btn.prop('disabled', true).addClass('disabled');
        const $spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo($btn);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            dataType: 'json',
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
                console.error(error);
            },
            complete: function() {
                $spin.remove();
                $btn.prop('disabled', false).removeClass('disabled');
            }
        });
    });

    // Make AJAX request to update default reject regex
    $('#nginx-regex-reset-defaults').on('click', function(event) {
        event.preventDefault();

        const $btn  = $(this);
        $btn.prop('disabled', true).addClass('disabled');
        const $spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo($btn);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            dataType: 'json',
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
                console.error(error);
            },
            complete: function() {
                $spin.remove();
                $btn.prop('disabled', false).removeClass('disabled');
            }
        });
    });

    // Make AJAX request to update default reject extension
    $('#nginx-extension-reset-defaults').on('click', function(event) {
        event.preventDefault();

        const $btn  = $(this);
        $btn.prop('disabled', true).addClass('disabled');
        const $spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo($btn);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            dataType: 'json',
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
                console.error(error);
            },
            complete: function() {
                $spin.remove();
                $btn.prop('disabled', false).removeClass('disabled');
            }
        });
    });

    // Make AJAX request to update default cache key regex
    $('#nginx-key-regex-reset-defaults').on('click', function(event) {
        event.preventDefault();

        const $btn  = $(this);
        $btn.prop('disabled', true).addClass('disabled');
        const $spin = $('<span class="nppp-inline-spinner" aria-hidden="true"></span>').appendTo($btn);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            method: 'POST',
            dataType: 'json',
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
                console.error(error);
            },
            complete: function() {
                $spin.remove();
                $btn.prop('disabled', false).removeClass('disabled');
            }
        });
    });

    // Event handler for the clear url index button
    $(document).off('click', '#nppp-clear-url-index-btn').on('click', '#nppp-clear-url-index-btn', function(e) {
        e.preventDefault();

        var buttonElement = $('#nppp-clear-url-index-btn');
        var buttonOffset = buttonElement.offset();
        var buttonWidth = buttonElement.outerWidth();

        var spinner = document.createElement('div');
        spinner.className = 'nppp-loading-spinner';
        spinner.style.position = 'absolute';
        spinner.style.left = buttonOffset.left + buttonWidth + 10 + 'px';
        spinner.style.top = (buttonOffset.top - 12) + 'px';
        spinner.style.zIndex = '9999';
        document.body.appendChild(spinner);

        $.ajax({
            url: nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'nppp_clear_url_index',
                _wpnonce: nppp_admin_data.clear_url_index_nonce
            },
            success: function(response) {
                document.body.removeChild(spinner);

                var notification = document.createElement('div');
                notification.style.position = 'absolute';
                notification.style.left = (buttonOffset.left + buttonWidth + 10) + 'px';
                notification.style.top = (buttonOffset.top - 3) + 'px';
                notification.style.color = '#fff';
                notification.style.padding = '8px 12px';
                notification.style.transition = 'opacity 0.3s ease-in-out';
                notification.style.opacity = '1';
                notification.style.zIndex = '9999';
                notification.style.fontSize = '13px';
                notification.style.fontWeight = '700';
                notification.style.borderRadius = '4px';

                if (response.success) {
                    notification.style.backgroundColor = '#50C878';
                    notification.textContent = 'Index Cleared';
                } else {
                    notification.style.backgroundColor = '#D32F2F';
                    notification.textContent = 'Index cannot be cleared';
                }

                document.body.appendChild(notification);
                setTimeout(function() {
                    notification.style.opacity = '0';
                    setTimeout(function() {
                        if (document.body.contains(notification)) {
                            document.body.removeChild(notification);
                        }
                    }, 300);
                }, 1700);
            },
            error: function() {
                document.body.removeChild(spinner);
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
            dataType: 'json',
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

    // Function to initialize DataTables.js for premium table
    function initializePremiumTable() {
        var $tbl = $('#nppp-premium-table');
        if (!$tbl.length) return;

        // Already initialised?
        if ($.fn.dataTable.isDataTable($tbl)) {
            var dtExisting = $tbl.DataTable();
            dtExisting.columns.adjust();
            if (dtExisting.responsive) dtExisting.responsive.recalc();
            return;
        }

        // Initialise
        $tbl.DataTable({
            autoWidth: false,
            responsive: true,
            orderClasses: false, // PERF
            paging: true,
            ordering: true,
            order: [],
            orderCellsTop: true,
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
                { width: "25%", targets: 0, className: 'text-left' },                    // Cached URL
                { width: "35%", targets: 1, className: 'text-left' },                    // Cache Path
                { width: "9%", targets: 2, className: 'text-left nppp-category-cell' },  // Content
                { width: "6%", targets: 3, className: 'text-left' },                     // Status
                { width: "10%", targets: 4, className: 'text-left nppp-variant-cell' },  // Variants
                { width: "15%", targets: 5, className: 'text-left' },                    // Actions
                { responsivePriority: 1, targets: 0 },                                   // Cached URL gets priority for responsiveness
                { responsivePriority: 10000, targets: [1, 2, 3, 4, 5] },                 // Collapse all in first row on mobile, hide actions always
                { defaultContent: "", targets: "_all" },                                 // Ensures all columns render even if empty
                { searchable: false, targets: [1, 4, 5] },                               // PERF: searchable:false on cols 1+4+5
                { orderable: false, targets: [5] }                                       // PERF: orderable:false on Actions skips
            ]
        });

        // clear one-shot highlight before any redraw
        $tbl.off('page.dt.nppp')
            .on('page.dt.nppp', function () {
                $(this).find('tr.purged-row, tr.child.purged-row').removeClass('purged-row');
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

    // Toggle switch rules for Cloudflare APO sync
    var isCloudflareChecked = $('#nppp_cloudflare_apo_sync').prop('checked');
    if (isCloudflareChecked) {
        // Checkbox is checked, toggle switch to On
        $('.nppp-onoffswitch-switch-cloudflare').css('background', '#66b317');
        $('.nppp-on-cloudflare').css('color', '#ffffff');
        $('.nppp-off-cloudflare').css('color', '#000000');
    } else {
        // Checkbox is unchecked, toggle switch to Off
        $('.nppp-onoffswitch-switch-cloudflare').css('background', '#ea1919');
        $('.nppp-on-cloudflare').css('color', '#000000');
        $('.nppp-off-cloudflare').css('color', '#ffffff');
    }

    // Add event listener to the original checkbox
    $('#nppp_cloudflare_apo_sync').change(function() {
        var isCloudflareChecked = $(this).prop('checked');
        if (isCloudflareChecked) {
            // Checkbox is checked, toggle switch to On
            $('.nppp-onoffswitch-switch-cloudflare').css('background', '#66b317');
            $('.nppp-on-cloudflare').css('color', '#ffffff');
            $('.nppp-off-cloudflare').css('color', '#000000');
        } else {
            // Checkbox is unchecked, toggle switch to Off
            $('.nppp-onoffswitch-switch-cloudflare').css('background', '#ea1919');
            $('.nppp-on-cloudflare').css('color', '#000000');
            $('.nppp-off-cloudflare').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for Redis Object Cache sync
    var isRedisChecked = $('#nppp_redis_cache_sync').prop('checked');
    if (isRedisChecked) {
        $('.nppp-onoffswitch-switch-redis').css('background', '#66b317');
        $('.nppp-on-redis').css('color', '#ffffff');
        $('.nppp-off-redis').css('color', '#000000');
    } else {
        $('.nppp-onoffswitch-switch-redis').css('background', '#ea1919');
        $('.nppp-on-redis').css('color', '#000000');
        $('.nppp-off-redis').css('color', '#ffffff');
    }

    $('#nppp_redis_cache_sync').change(function() {
        var isRedisChecked = $(this).prop('checked');
        if (isRedisChecked) {
            $('.nppp-onoffswitch-switch-redis').css('background', '#66b317');
            $('.nppp-on-redis').css('color', '#ffffff');
            $('.nppp-off-redis').css('color', '#000000');
        } else {
            $('.nppp-onoffswitch-switch-redis').css('background', '#ea1919');
            $('.nppp-on-redis').css('color', '#000000');
            $('.nppp-off-redis').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for HTTP purge fast-path
    function npppHttpPurgeSubOptions(isChecked) {
        $('#nppp-http-purge-suffix-row, #nppp-http-purge-custom-url-row').toggle(isChecked);
    }
    var isHttpPurgeChecked = $('#nppp_http_purge_enabled').prop('checked');
    npppHttpPurgeSubOptions(isHttpPurgeChecked);

    if (isHttpPurgeChecked) {
        $('.nppp-onoffswitch-switch-httppurge').css('background', '#66b317');
        $('.nppp-on-httppurge').css('color', '#ffffff');
        $('.nppp-off-httppurge').css('color', '#000000');
    } else {
        $('.nppp-onoffswitch-switch-httppurge').css('background', '#ea1919');
        $('.nppp-on-httppurge').css('color', '#000000');
        $('.nppp-off-httppurge').css('color', '#ffffff');
    }

    $('#nppp_http_purge_enabled').change(function() {
        var isHttpPurgeChecked = $(this).prop('checked');
        npppHttpPurgeSubOptions(isHttpPurgeChecked);
        if (isHttpPurgeChecked) {
            $('.nppp-onoffswitch-switch-httppurge').css('background', '#66b317');
            $('.nppp-on-httppurge').css('color', '#ffffff');
            $('.nppp-off-httppurge').css('color', '#000000');
        } else {
            $('.nppp-onoffswitch-switch-httppurge').css('background', '#ea1919');
            $('.nppp-on-httppurge').css('color', '#000000');
            $('.nppp-off-httppurge').css('color', '#ffffff');
        }
    });

    // Toggle switch rules for RG Purge
    var isRgPurgeChecked = $('#nppp_rg_purge_enabled').prop('checked');
    if (isRgPurgeChecked) {
        $('.nppp-onoffswitch-switch-rgpurge').css('background', '#66b317');
        $('.nppp-on-rgpurge').css('color', '#ffffff');
        $('.nppp-off-rgpurge').css('color', '#000000');
    } else {
        $('.nppp-onoffswitch-switch-rgpurge').css('background', '#ea1919');
        $('.nppp-on-rgpurge').css('color', '#000000');
        $('.nppp-off-rgpurge').css('color', '#ffffff');
    }

    $('#nppp_rg_purge_enabled').change(function() {
        var isRgPurgeChecked = $(this).prop('checked');
        if (isRgPurgeChecked) {
            $('.nppp-onoffswitch-switch-rgpurge').css('background', '#66b317');
            $('.nppp-on-rgpurge').css('color', '#ffffff');
            $('.nppp-off-rgpurge').css('color', '#000000');
        } else {
            $('.nppp-onoffswitch-switch-rgpurge').css('background', '#ea1919');
            $('.nppp-on-rgpurge').css('color', '#000000');
            $('.nppp-off-rgpurge').css('color', '#ffffff');
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

    // Toggle switch rules for watchdog
    var isCheckedWatchdog = $('#nginx_cache_watchdog').prop('checked');
    if (isCheckedWatchdog) {
        $('.nppp-onoffswitch-switch-watchdog').css('background', '#66b317');
        $('.nppp-on-watchdog').css('color', '#ffffff');
        $('.nppp-off-watchdog').css('color', '#000000');
    } else {
        $('.nppp-onoffswitch-switch-watchdog').css('background', '#ea1919');
        $('.nppp-on-watchdog').css('color', '#000000');
        $('.nppp-off-watchdog').css('color', '#ffffff');
    }

    $('#nginx_cache_watchdog').change(function() {
        var isChecked = $(this).prop('checked');
        if (isChecked) {
            $('.nppp-onoffswitch-switch-watchdog').css('background', '#66b317');
            $('.nppp-on-watchdog').css('color', '#ffffff');
            $('.nppp-off-watchdog').css('color', '#000000');
        } else {
            $('.nppp-onoffswitch-switch-watchdog').css('background', '#ea1919');
            $('.nppp-on-watchdog').css('color', '#000000');
            $('.nppp-off-watchdog').css('color', '#ffffff');
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

    // Copy clipborad function
    async function npppCopy(text){
        try { await navigator.clipboard.writeText(String(text)); return true; }
        catch(e){
            const i=document.createElement('input');
            i.value=String(text);
            document.body.appendChild(i);
            i.select();
            const ok=document.execCommand('copy');
            document.body.removeChild(i);
            return ok;
        }
    }

    // Unique ID copy clipboard
    $('#nppp-unique-id').on('click', async function (event) {
        var uniqueId = $(this).data('unique-id');
        await npppCopy(uniqueId);
        npppToast(__('Copied to clipboard', 'fastcgi-cache-purge-and-preload-nginx'), 'success', 2000);
    });

    // Click event handler for copying the API key to clipboard
    $('#nppp-api-key').click(function(event) {
        // Perform AJAX request to fetch the API key
        $.ajax({
            url:  nppp_admin_data.ajaxurl,
            type: 'GET',
            dataType: 'json',
            data: {
                action: 'nppp_update_api_key_copy_value',
                _wpnonce: nppp_admin_data.api_key_copy_nonce
            },
            success: async function(response) {
                var apiKey = (response && response.data && response.data.api_key) || '';
                if (!apiKey) {
                    console.error('API key not found in response');
                    return;
                }

                // Copy the API key to clipboard
                try {
                    await npppCopy(apiKey);
                } catch (e) {
                    console.error('Clipboard write failed:', e);
                    return;
                }
                npppToast(__('Copied to clipboard', 'fastcgi-cache-purge-and-preload-nginx'), 'success', 2000);
            },
            error: function(xhr, status, error) {
                console.error('Error fetching API key:', error);
            }
        });
    });

    // Click event handler for copying the Purge URL to clipboard
    $('#nppp-purge-url').click(function(event) {
        // Perform AJAX request to fetch the Purge URL
        $.ajax({
            url:  nppp_admin_data.ajaxurl,
            type: 'GET',
            dataType: 'json',
            data: {
                action: 'nppp_rest_api_purge_url_copy',
                _wpnonce: nppp_admin_data.api_purge_url_copy_nonce
            },
            success: async function(response) {
                var purgeUrl = response.data;

                // Copy the Purge URL to clipboard
                await npppCopy(purgeUrl);
                npppToast(__('Copied to clipboard', 'fastcgi-cache-purge-and-preload-nginx'), 'success', 2000);
            },
            error: function(xhr, status, error) {
                console.error('Error fetching Purge URL:', error);
            }
        });
    });

    // Click event handler for copying the Preload URL to clipboard
    $('#nppp-preload-url').click(function(event) {
        // Perform AJAX request to fetch the Preload URL
        $.ajax({
            url:  nppp_admin_data.ajaxurl,
            type: 'GET',
            dataType: 'json',
            data: {
                action: 'nppp_rest_api_preload_url_copy',
                _wpnonce: nppp_admin_data.api_preload_url_copy_nonce
            },
            success: async function(response) {
                var preloadUrl = response.data;

                // Copy the Preload URL to clipboard
                await npppCopy(preloadUrl);
                npppToast(__('Copied to clipboard', 'fastcgi-cache-purge-and-preload-nginx'), 'success', 2000);
            },
            error: function(xhr, status, error) {
                console.error('Error fetching Preload URL:', error);
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
        '#nginx_cache_read_timeout',
        '#nginx_cache_email',
        '#nginx_cache_tracking_opt_in',
        '#nginx_cache_api_key',
        '#nginx_cache_key_custom_regex',
        '#nginx_cache_preload_proxy_port',
        '#nginx_cache_preload_proxy_host',
        '#nppp_http_purge_suffix',
        '#nppp_http_purge_custom_url'
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
        return updatedParameters.length ? (baseUrl + '?' + updatedParameters.join('&')) : baseUrl;
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
    const { __, _x, _n, _nx, sprintf } = wp.i18n;

    // Elements we need ready on DOM
    const elementsToCheck = [
        "#npppphpFpmStatus",
        "#npppphpPagesInCache",
        "#npppCacheHitRatio",
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
        "#npppcpulimitStatus",
        "#npppsafexecStatus",
        "#npppSafexecVersion",
        "#nppprgStatus",
        "#npppCacheDiskSize"
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

    // Cache Hit Ratio colour-band
    (function () {
        var ratioCell = document.getElementById('npppCacheHitRatio');
        if (!ratioCell) { return; }

        ratioCell.style.fontSize = '14px';

        var rawText     = ratioCell.textContent.trim();
        var iconSpan    = document.createElement('span');
        var bandClass   = 'ratio-na';
        var displayText = ' ' + rawText;

        // Extract leading percentage number if present (e.g. "87.5%  (35 HIT …)")
        var pctMatch = rawText.match(/^(\d+(?:\.\d+)?)\s*%/);
        if (pctMatch) {
            var pct = parseFloat(pctMatch[1]);
            if (pct >= 80) {
                bandClass = 'ratio-high';
                iconSpan.classList.add('dashicons', 'dashicons-yes');
                iconSpan.style.color = '#008000';
            } else if (pct >= 50) {
                bandClass = 'ratio-medium';
                iconSpan.classList.add('dashicons', 'dashicons-warning');
                iconSpan.style.color = '#e69500';
            } else {
                bandClass = 'ratio-low';
                iconSpan.classList.add('dashicons', 'dashicons-no');
                iconSpan.style.color = '#ff0000';
            }
        } else {
            // N/A states
            iconSpan.classList.add('dashicons', 'dashicons-no');
            iconSpan.style.color = '#ff0000';
        }

        iconSpan.style.fontSize = '20px';
        iconSpan.style.width    = '20px';
        iconSpan.style.height   = '20px';

        var textSpan       = document.createElement('span');
        textSpan.textContent = displayText;
        textSpan.style.color  = iconSpan.style.color;
        textSpan.style.fontWeight = '700';

        ratioCell.classList.add(bandClass);
        ratioCell.textContent = '';
        ratioCell.appendChild(iconSpan);
        ratioCell.appendChild(textSpan);
    })();

    // Cache Disk Size colour-band (low usage = good, high usage = bad)
    (function () {
        var diskCell = document.getElementById('npppCacheDiskSize');
        if (!diskCell) { return; }

        diskCell.style.fontSize = '14px';

        var rawText  = diskCell.textContent.trim();
        var iconSpan = document.createElement('span');
        var diskClass = 'disk-na';
        var displayText = ' ' + rawText;

        // Extract trailing percentage
        var pctMatch = rawText.match(/^(\d+(?:\.\d+)?)\s*%/);
        if (pctMatch) {
            var pct = parseFloat(pctMatch[1]);
            if (pct < 50) {
                diskClass = 'disk-low';
                iconSpan.classList.add('dashicons', 'dashicons-yes');
                iconSpan.style.color = '#008000';
            } else if (pct < 80) {
                diskClass = 'disk-medium';
                iconSpan.classList.add('dashicons', 'dashicons-warning');
                iconSpan.style.color = '#e69500';
            } else {
                diskClass = 'disk-high';
                iconSpan.classList.add('dashicons', 'dashicons-no');
                iconSpan.style.color = '#ff0000';
            }
        } else if (rawText === 'Unavailable') {
            iconSpan.classList.add('dashicons', 'dashicons-clock');
            iconSpan.style.color = '#e69500';
        } else {
            iconSpan.classList.add('dashicons', 'dashicons-clock');
            iconSpan.style.color = '#72777c';
        }

        iconSpan.style.fontSize = '20px';
        iconSpan.style.width    = '20px';
        iconSpan.style.height   = '20px';

        var textSpan          = document.createElement('span');
        textSpan.textContent  = displayText;
        textSpan.style.color  = iconSpan.style.color;
        textSpan.style.fontWeight = '700';

        diskCell.classList.add(diskClass);
        diskCell.textContent = '';
        diskCell.appendChild(iconSpan);
        diskCell.appendChild(textSpan);
    })();

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
    npppphpWebServerSpan.textContent = '';

    let iconSpanWebServer = document.createElement('span');
    iconSpanWebServer.style.fontSize = "16px";

    if (npppphpWebServer.toLowerCase() === "dummy" || npppphpWebServer.toLowerCase() === "not determined") {
        iconSpanWebServer.classList.add("dashicons", "dashicons-clock");
        npppphpWebServerSpan.style.color = "orange";
        npppphpWebServerSpan.appendChild(iconSpanWebServer);
        npppphpWebServerSpan.append(' ' + __('Not Determined', 'fastcgi-cache-purge-and-preload-nginx'));
    } else {
        iconSpanWebServer.classList.add("dashicons", "dashicons-arrow-right-alt");
        npppphpWebServerSpan.style.color = "green";
        npppphpWebServerSpan.appendChild(iconSpanWebServer);
        npppphpWebServerSpan.append(' ' + npppphpWebServer);
    }

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
    nppppreloadStatusSpan.dataset.statusRaw = nppppreloadStatus;
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

    // Fetch and update safexec command status
    var npppsafexecStatusSpan = document.getElementById("npppsafexecStatus");
    var npppsafexecStatus = npppsafexecStatusSpan.textContent.trim();
    npppsafexecStatusSpan.textContent = '';
    npppsafexecStatusSpan.style.fontSize = "14px";

    let iconSpanSafexec = document.createElement('span');
    let safexecStatusText = '';

    if (npppsafexecStatus === "Installed") {
        npppsafexecStatusSpan.style.color = "green";
        iconSpanSafexec.classList.add("dashicons", "dashicons-yes");
        safexecStatusText = ' ' + __('Installed', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (npppsafexecStatus === "Not Installed") {
        npppsafexecStatusSpan.style.color = "red";
        iconSpanSafexec.classList.add("dashicons", "dashicons-no");
        safexecStatusText = ' ' + __('Not Installed', 'fastcgi-cache-purge-and-preload-nginx');
    }

    npppsafexecStatusSpan.appendChild(iconSpanSafexec);
    npppsafexecStatusSpan.append(safexecStatusText);

    var npppSafexecVersionSpan = document.getElementById("npppSafexecVersion");
    var npppSafexecVersion = npppSafexecVersionSpan.textContent.trim();

    npppSafexecVersionSpan.style.fontSize = "14px";
    npppSafexecVersionSpan.style.fontWeight = "bold";
    npppSafexecVersionSpan.textContent = '';

    let iconSpanSafexecVersion = document.createElement('span');
    let safexecVersionText = '';

    if (npppSafexecVersion === "Not Installed" || npppSafexecVersion === "Unknown") {
        npppSafexecVersionSpan.style.color = "orange";
        iconSpanSafexecVersion.classList.add("dashicons", "dashicons-warning");
        iconSpanSafexecVersion.style.fontSize = "18px";
        iconSpanSafexecVersion.style.setProperty('font-weight', 'normal', 'important');
        safexecVersionText = ' ' + npppSafexecVersion;
    } else if (npppSafexecVersion.includes("(")) {
        var versions = npppSafexecVersion.match(/(\d+\.\d+\.\d+)\s\((\d+\.\d+\.\d+)\)/);
        if (versions) {
            var installedVersion = versions[1];
            var pluginVersion = versions[2];

            if (installedVersion === pluginVersion) {
                npppSafexecVersionSpan.style.color = "green";
                iconSpanSafexecVersion.classList.add("dashicons", "dashicons-yes");
                iconSpanSafexecVersion.style.fontSize = "20px";
                iconSpanSafexecVersion.style.color = "green";
                iconSpanSafexecVersion.style.setProperty('font-weight', 'normal', 'important');
                safexecVersionText = ` ${installedVersion} (${pluginVersion})`;
            } else {
                iconSpanSafexecVersion.classList.add("dashicons", "dashicons-update");
                iconSpanSafexecVersion.style.fontSize = "18px";
                iconSpanSafexecVersion.style.color = "orange";
                iconSpanSafexecVersion.style.setProperty('font-weight', 'normal', 'important');
                safexecVersionText = `<span style="color:orange;">${installedVersion}</span> <span style="color:green;">(${pluginVersion})</span>`;
            }
        }
    } else {
        npppSafexecVersionSpan.style.color = "green";
        iconSpanSafexecVersion.classList.add("dashicons", "dashicons-yes");
        iconSpanSafexecVersion.style.fontSize = "20px";
        iconSpanSafexecVersion.style.color = "green";
        iconSpanSafexecVersion.style.setProperty('font-weight', 'normal', 'important');
        safexecVersionText = ' ' + npppSafexecVersion;
    }

    npppSafexecVersionSpan.appendChild(iconSpanSafexecVersion);
    npppSafexecVersionSpan.insertAdjacentHTML('beforeend', safexecVersionText);

    // Fetch and update rg command status
    var nppprgStatusSpan = document.getElementById("nppprgStatus");
    var nppprgStatus = nppprgStatusSpan.textContent.trim();
    nppprgStatusSpan.textContent = '';
    nppprgStatusSpan.style.fontSize = "14px";

    let iconSpanRg = document.createElement('span');
    let rgStatusText = '';

    if (nppprgStatus === "Installed") {
        nppprgStatusSpan.style.color = "green";
        iconSpanRg.classList.add("dashicons", "dashicons-yes");
        rgStatusText = ' ' + __('Installed', 'fastcgi-cache-purge-and-preload-nginx');
    } else if (nppprgStatus === "Not Installed") {
        nppprgStatusSpan.style.color = "red";
        iconSpanRg.classList.add("dashicons", "dashicons-no");
        rgStatusText = ' ' + __('Not Installed', 'fastcgi-cache-purge-and-preload-nginx');
    }

    nppprgStatusSpan.appendChild(iconSpanRg);
    nppprgStatusSpan.append(rgStatusText);

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
const npppSubmenuUl = document.querySelector('.nppp-submenu ul');
if (npppSubmenuUl) npppSubmenuUl.addEventListener('click', function(npppEvent) {
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
