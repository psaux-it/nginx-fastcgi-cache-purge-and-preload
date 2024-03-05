<?php
/*
Description: Automatically preload Nginx cache by crawling the website using WordPress HTTP API and DOMDocument.
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

// Function to crawl the website and retrieve links
function crawl_website($url) {
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $dom = new DOMDocument();
    @$dom->loadHTML($body); // Suppress errors for malformed HTML
    $links = [];

    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = $a->getAttribute('href');
        if (!empty($href) && strpos($href, '#') !== 0) {
            $links[] = $href;
        }
    }

    return $links;
}

// Function to preload cache for given URLs
function preload_cache($urls) {
    foreach ($urls as $url) {
        $response = wp_remote_get($url);
        // Optionally, you can check the response status code or body content here
        // For example, wp_remote_retrieve_response_code($response) will give you the HTTP status code

        // You may also want to add some delay between requests to avoid overwhelming the server
        usleep(100000); // Sleep for 100 milliseconds (adjust as needed)
    }
}

// Function to crawl and preload cache for the website
function crawl_and_preload() {
    // Get the home URL of the WordPress site
    $start_url = home_url();

    // Keep track of visited URLs to avoid crawling the same page multiple times
    $visited_urls = [];

    // Crawl the website starting from the home URL
    crawl_and_preload_recursive($start_url, $visited_urls);
}

// Recursive function to crawl the website and preload cache
function crawl_and_preload_recursive($url, &$visited_urls) {
    // Retrieve links from the current URL
    $links = crawl_website($url);

    // Preload cache for the current URL
    preload_cache([$url]);

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
            // Crawl and preload cache for the current URL recursively
            crawl_and_preload_recursive($link, $visited_urls);
        }
    }
}
