/**
 * Mobile FAB toggle for Nginx Cache Purge Preload
 * Description: Handles open/close of the mobile floating action button menu.
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

/* global document */
(function () {
    'use strict';

    function initFab() {
        var fab    = document.getElementById('nppp-mobile-fab');
        var btn    = document.getElementById('nppp-mobile-fab-toggle');
        var menu   = document.getElementById('nppp-mobile-fab-menu');

        if (!fab || !btn || !menu) {
            return;
        }

        /* Toggle menu on button click */
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = menu.classList.contains('nppp-fab-open');
            menu.classList.toggle('nppp-fab-open', !isOpen);
            btn.setAttribute('aria-expanded', String(!isOpen));
            menu.setAttribute('aria-hidden', String(isOpen));
        });

        /* Close when user clicks/taps outside the FAB */
        document.addEventListener('click', function (e) {
            if (!fab.contains(e.target)) {
                menu.classList.remove('nppp-fab-open');
                btn.setAttribute('aria-expanded', 'false');
                menu.setAttribute('aria-hidden', 'true');
            }
        });

        /* Close on Escape key */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                menu.classList.remove('nppp-fab-open');
                btn.setAttribute('aria-expanded', 'false');
                menu.setAttribute('aria-hidden', 'true');
                btn.focus();
            }
        });
    }

    /* Run after DOM is ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFab);
    } else {
        initFab();
    }
}());
