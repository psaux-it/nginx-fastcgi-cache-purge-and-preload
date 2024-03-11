<?php
/*
Description: Automatically preload Nginx cache by crawling the website using WordPress HTTP API and DOMDocument.
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

// Define user agent constant
define('PLUGIN_USER_AGENT', 'NginxCachePreloaderBot/1.0');

// Function to crawl the website and retrieve links, excluding certain endpoints
function crawl_website($url, $reject_regex) {
    $args = ['user-agent' => PLUGIN_USER_AGENT];

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        error_log('WP Remote GET Error: ' . $response->get_error_message());
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $dom = new DOMDocument();
    @$dom->loadHTML($body); // Suppress errors for malformed HTML
    $links = [];

    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = $a->getAttribute('href');
        // Check if the link matches any rejected endpoint
        if (preg_match($reject_regex, $href)) {
            continue; // Skip crawling this link
        }
        if (!empty($href) && strpos($href, '#') !== 0) {
            $links[] = $href;
        }
    }

    return $links;
}

function inotify_helper($nginx_cache_path) {
    // Execute pgrep command to search for inotifywait process
    $output = shell_exec("pgrep -f 'inotifywait.*$nginx_cache_path'");

    // Check if output is not empty (indicating process found)
    if (!empty($output)) {
        return true; // Return true indicating process is alive
    } else {
        return false; // Return false indicating process is not alive
    }
}

// Function to crawl and visit website links, checking for broken links
function crawl_and_visit($reject_regex, $nginx_cache_path) {
    // Check if the crawl and visit operation is in progress
    if (get_option(CRAWL_AND_VISIT_OPTION) !== 'in_progress') {
        // Call purge_helper() to purge cache before preload
        $status = purge_helper($nginx_cache_path);

        // Check the status returned by purge_helper()
        if ($status === 0) {
            # Check inotify/setfacl operations started on root
            if (!inotify_helper($nginx_cache_path)) {
                display_admin_notice('error', 'ERROR INOTIFY: Please start inotify service via "systemctl start wp-fcgi-notify" first !');
            }

            // Set the option to indicate that the operation is in progress
            update_option(CRAWL_AND_VISIT_OPTION, 'in_progress');

            // Get the home URL of the WordPress site
            $start_url = home_url();

            // Keep track of visited URLs to avoid crawling the same page multiple times
            $visited_urls = [];

            // Crawl the website starting from the home URL
            crawl_and_visit_recursive($start_url, $visited_urls, $reject_regex);

            // Set the option to indicate that the operation has finished
            update_option(CRAWL_AND_VISIT_OPTION, 'completed');
        } elseif ($status === 1) {
            display_admin_notice('error', 'ERROR PERMISSION: Cannot Purge FastCGI cache to start cache preloading. Please restart wp-fcgi-notify.service !');
        } elseif ($status === 2) {
            display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service');
        } else {
            display_admin_notice('error', 'ERROR UNKNOWN: Cannot Purge FastCGI cache to start cache preloading !');
        }
    } else {
        // Notify the user that the operation is already in progress
        display_admin_notice('info', 'INFO PRELOAD: FastCGI cache is already preloading... If you want stop it now use FCGI Cache Purge');
    }
}

function crawl_and_visit_recursive($url, &$visited_urls, $reject_regex) {
    // Control preload process
    if (get_option(CRAWL_AND_VISIT_OPTION) !== 'in_progress') {
        exit(1); // Stop crawling if the option is no longer in progress
    }

    // Retrieve links from the current URL, excluding rejected URLs
    $links = crawl_website($url, $reject_regex);

    // Iterate through links and crawl recursively
    foreach ($links as $link) {
        // Construct absolute URL if it's a relative URL
        if (strpos($link, 'http') !== 0) {
            $link = rtrim($url, '/') . '/' . ltrim($link, '/');
        }
        // Check if the URL hasn't been visited yet to avoid duplicate crawling
        if (!in_array($link, $visited_urls)) {
            // Add URL to visited URLs array
            $visited_urls[] = $link;
            // Crawl and visit links for the current URL recursively
            crawl_and_visit_recursive($link, $visited_urls, $reject_regex);
        }
    }
}
