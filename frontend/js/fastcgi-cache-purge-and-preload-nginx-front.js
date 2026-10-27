/**
 * Legacy frontend notice scripts for Nginx Cache Purge Preload
 * Description: Displays and times frontend purge/preload action result messages.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Keep action messages for only 4 sec
document.addEventListener('DOMContentLoaded', function() {
    "use strict";

    setTimeout(function() {
        var noticeElement = document.querySelector('.nppp_notice');
        if (noticeElement) {
            noticeElement.style.transition = 'opacity 0.5s ease';
            noticeElement.style.opacity = '0';

            setTimeout(function() {
                noticeElement.remove();
            }, 500);
        }
    }, 4000);

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
    if (nppp_kept.length !== nppp_pairs.length && history.replaceState) {
        history.replaceState({}, document.title, window.location.pathname + (nppp_kept.length ? '?' + nppp_kept.join('&') : '') + window.location.hash);
    }
});
