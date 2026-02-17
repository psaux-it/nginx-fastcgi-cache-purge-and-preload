/**
 * Legacy frontend notice scripts for Nginx Cache Purge Preload
 * Description: Displays and times frontend purge/preload action result messages.
 * Version: 2.1.4
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

    var url = new URL(window.location.href);
    if (url.searchParams.has('nppp_front') || url.searchParams.has('redirect_nonce')) {
        url.searchParams.delete('nppp_front');
        url.searchParams.delete('redirect_nonce');

        if (history.replaceState) {
            history.replaceState({}, document.title, url.pathname + (url.search || '') + url.hash);
        }
    }
});
