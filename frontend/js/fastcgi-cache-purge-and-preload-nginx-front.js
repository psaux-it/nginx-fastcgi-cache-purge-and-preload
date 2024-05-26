/**
 * Frontend JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript file contains functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// this function keeps frontend on page purge preload actions messages  for 4 sec
document.addEventListener('DOMContentLoaded', function() {
    // Remove the nppp_notice element after three seconds
    setTimeout(function() {
        var noticeElement = document.querySelector('.nppp_notice');
        if (noticeElement) {
            // Apply fade-out animation
            noticeElement.style.transition = 'opacity 0.5s ease';
            noticeElement.style.opacity = '0';

            // Remove the element from the DOM after the animation completes
            setTimeout(function() {
                noticeElement.remove();
            }, 500); // Wait for the animation duration (0.5s)
        }
    }, 4000); // Wait for 3 seconds before starting the animation

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
