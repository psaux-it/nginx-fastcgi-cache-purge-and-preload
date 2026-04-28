/**
 * Frontend toast scripts for Nginx Cache Purge Preload
 * Description: Displays frontend purge/preload result messages in isolated toast notifications.
 * Version: 2.1.6
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

    var url = new URL(window.location.href);
    if (url.searchParams.has('nppp_front') || url.searchParams.has('redirect_nonce')) {
        url.searchParams.delete('nppp_front');
        url.searchParams.delete('redirect_nonce');

        if (window.history.replaceState) {
            window.history.replaceState({}, document.title, url.pathname + (url.search || '') + url.hash);
        }
    }
});
