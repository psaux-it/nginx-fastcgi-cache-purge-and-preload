<?php
/*
Description: Automatically preload Nginx cache by crawling the website using WordPress HTTP API and DOMDocument.
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

// Include the reject_regex
require_once plugin_dir_path( __FILE__ ) . 'reject_regex.php';

// Function to crawl the website and retrieve links, excluding certain endpoints
function crawl_website($url, $reject_regex) {
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

// Function to crawl and preload cache for the website, considering exclusion regex
function crawl_and_preload($reject_regex) {
    // Get the home URL of the WordPress site
    $start_url = home_url();

    // Keep track of visited URLs to avoid crawling the same page multiple times
    $visited_urls = [];

    // Crawl the website starting from the home URL
    crawl_and_preload_recursive($start_url, $visited_urls, $reject_regex);
}

// Recursive function to crawl the website and preload cache, considering exclusion regex
function crawl_and_preload_recursive($url, &$visited_urls, $reject_regex) {
    // Retrieve links from the current URL, excluding rejected URLs
    $links = crawl_website($url, $reject_regex);

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
            crawl_and_preload_recursive($link, $visited_urls, $reject_regex);
        }
    }
}
