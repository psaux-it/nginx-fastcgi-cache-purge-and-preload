/**
 * Frontend toast scripts for Nginx Cache Purge Preload
 * Description: Displays frontend purge/preload result messages in isolated toast notifications.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    function npppFrontEnsureToastContainer() {
        var container = document.getElementById('nppp-front-toast-container');

        if (!container) {
            container = document.createElement('div');
            container.id = 'nppp-front-toast-container';

            var adminBar = document.getElementById('wpadminbar');
            container.style.top = (adminBar ? adminBar.offsetHeight + 12 : 12) + 'px';

            document.body.appendChild(container);
        }

        return container;
    }

    function npppFrontDismissToast(toastElement) {
        if (!toastElement) {
            return;
        }

        toastElement.classList.add('nppp-front-toast-exit');

        setTimeout(function() {
            toastElement.remove();
        }, 180);
    }

    function npppFrontNormalizeType(type) {
        var normalized = String(type || 'info').toLowerCase();

        if (normalized === 'success' || normalized === 'error' || normalized === 'info') {
            return normalized;
        }

        return 'info';
    }

    function npppFrontToast(message, type, timeout) {
        if (!message) {
            return;
        }

        var safeType = npppFrontNormalizeType(type);
        var container = npppFrontEnsureToastContainer();

        var toast = document.createElement('div');
        toast.className = 'nppp-front-toast nppp-front-is-' + safeType;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', safeType === 'error' ? 'assertive' : 'polite');

        toast.innerHTML = [
            '<span class="nppp-front-ico" aria-hidden="true"></span>',
            '<button type="button" class="nppp-front-close" aria-label="Dismiss">×</button>',
            '<div class="nppp-front-msg"></div>'
        ].join('');

        toast.querySelector('.nppp-front-msg').textContent = String(message);
        toast.querySelector('.nppp-front-close').addEventListener('click', function() {
            npppFrontDismissToast(toast);
        });

        container.prepend(toast);

        var hideTimer = setTimeout(function() {
            npppFrontDismissToast(toast);
        }, timeout || 5000);

        toast.addEventListener('mouseenter', function() {
            clearTimeout(hideTimer);
        });

        toast.addEventListener('mouseleave', function() {
            hideTimer = setTimeout(function() {
                npppFrontDismissToast(toast);
            }, 1200);
        });
    }

    if (window.nppp_front_data && window.nppp_front_data.message) {
        npppFrontToast(window.nppp_front_data.message, window.nppp_front_data.type, 5000);
    }

    // Remove only our two args and keep every other byte of the query as-is.
    // URLSearchParams.delete() re-serializes the query (%20 -> +, x[] -> x%5B%5D) and would rewrite the URL
    // to a different cache key on reload.
    var nppp_pairs = window.location.search ? window.location.search.slice(1).split('&') : [];
    var nppp_kept = [];
    nppp_pairs.forEach(function(pair) {
        var name = pair.split('=')[0];
        try { name = decodeURIComponent(name); } catch (e) {}
        if (name !== 'nppp_front' && name !== 'redirect_nonce') {
            nppp_kept.push(pair);
        }
    });
    if (nppp_kept.length !== nppp_pairs.length && window.history.replaceState) {
        window.history.replaceState({}, document.title, window.location.pathname + (nppp_kept.length ? '?' + nppp_kept.join('&') : '') + window.location.hash);
    }
});
