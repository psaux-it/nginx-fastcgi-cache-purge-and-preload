/**
 * Frontend JavaScript for FastCGI Cache Purge and Preload for Nginx
 * Description: This JavaScript file contains admin bar icon css fix for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// position admin bar icon properly on front end
document.addEventListener("DOMContentLoaded", function() {
    var imgElement = document.querySelector("#wp-admin-bar-fastcgi-cache-operations > a.ab-item > img");
    if (imgElement) {
        imgElement.style.marginBottom = "4px";
    }
});
