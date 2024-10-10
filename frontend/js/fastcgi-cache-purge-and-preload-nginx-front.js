/**
 * Frontend JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript file contains functions that shows on-page purge & preload actions messages for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Keep frontend on-page purge & preload actions messages for 4 sec
document.addEventListener('DOMContentLoaded', function() {
    // Remove the nppp_notice element after 4 seconds
    setTimeout(function() {
        var noticeElement = document.querySelector('.nppp_notice');
        if (noticeElement) {
            // Apply fade-out animation
            noticeElement.style.transition = 'opacity 0.5s ease';
            noticeElement.style.opacity = '0';

            // Remove the element from the DOM after the animation completes
            setTimeout(function() {
                noticeElement.remove();
            }, 500);
        }
    }, 4000);

    // Check if the nppp_front query parameter exists in the URL
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('nppp_front')) {
        // Remove the nppp_front query parameter from the URL
        if (history.replaceState) {
            var urlWithoutQuery = window.location.href.split('?')[0];
            history.replaceState({}, document.title, urlWithoutQuery);
        }
    }
});
