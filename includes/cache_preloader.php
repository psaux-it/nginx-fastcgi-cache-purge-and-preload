<?php
/*
Description: Automatically preload Nginx cache by crawling the website using WordPress HTTP API and DOMDocument.
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

// Include the reject_regex
require_once plugin_dir_path( __FILE__ ) . 'reject_regex.php';

// Define user agent constant
define('PLUGIN_USER_AGENT', 'MyNginxCachePreloaderBot/1.0');

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

function parse_robots_txt() {
    $base_url = home_url(); // Dynamically get the WordPress site's home URL
    $robots_url = rtrim($base_url, '/') . '/robots.txt';
    $response = wp_remote_get($robots_url);

    if (is_wp_error($response)) {
        error_log('Error fetching robots.txt: ' . $response->get_error_message());
        return []; // Fail gracefully if there's an error fetching robots.txt
    }

    $robots_txt = wp_remote_retrieve_body($response);
    $lines = explode("\n", $robots_txt);
    $rules = ['Allow' => [], 'Disallow' => []];

    $current_user_agent = '*'; // Default user-agent
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'User-agent:') === 0) {
            $current_user_agent = trim(substr($line, strlen('User-agent:')));
        } elseif ($current_user_agent === PLUGIN_USER_AGENT || $current_user_agent === '*') {
            if (strpos($line, 'Disallow:') === 0) {
                $rule = trim(substr($line, strlen('Disallow:')));
                if ($rule !== '') { // Ignore empty Disallow rules
                    $rules['Disallow'][] = $rule;
                }
            } elseif (strpos($line, 'Allow:') === 0) {
                $rule = trim(substr($line, strlen('Allow:')));
                $rules['Allow'][] = $rule;
            }
        }
    }

    return $rules;
}

// Function to crawl and visit website links, respecting exclusion regex and robots.txt rules, and checking for broken links
function crawl_and_visit($reject_regex) {
    // Get the home URL of the WordPress site
    $start_url = home_url();

    // Keep track of visited URLs to avoid crawling the same page multiple times
    $visited_urls = [];

    // Parse robots.txt
    $robots_rules = parse_robots_txt();

    // Crawl the website starting from the home URL
    crawl_and_visit_recursive($start_url, $visited_urls, $reject_regex, $robots_rules);
}

// Recursive function to crawl the website and visit links, considering exclusion regex and robots.txt rules, and checking for broken links
function crawl_and_visit_recursive($url, &$visited_urls, $reject_regex, $robots_rules) {
    // Check if the URL is allowed by robots.txt rules
    if (!is_url_allowed_by_robots($url, $robots_rules)) {
        return; // Skip crawling this URL if disallowed by robots.txt
    }

    // Retrieve links from the current URL, excluding rejected URLs
    $links = crawl_website($url, $reject_regex);

    // Visit the current URL and check for broken links
    visit_url_and_check_links($url, $links);

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
            crawl_and_visit_recursive($link, $visited_urls, $reject_regex, $robots_rules);
        }
    }
}

// Placeholder function for visiting a URL and checking for broken links
function visit_url_and_check_links($url, $links) {
    // Check if the URL is reachable and doesn't return a 404 status code
    $response = wp_remote_get($url, ['user-agent' => PLUGIN_USER_AGENT]);
    if (is_wp_error($response)) {
        // Log error if URL cannot be visited
        error_log('Error visiting URL ' . $url . ': ' . $response->get_error_message());
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code == 404) {
        // Log broken link if URL returns 404 status code
        $log_message = date('Y-m-d H:i:s') . ' Broken Link: ' . $url . ' (Status Code: 404)' . PHP_EOL;
        $log_file = plugin_dir_path(__FILE__) . 'broken_links.log'; // Adjust the log file path as needed
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

// Function to check if a URL is allowed based on robots.txt rules
function is_url_allowed_by_robots($url, $robots_rules) {
    $parsed_url = parse_url($url);

    if (!isset($parsed_url['path'])) {
        // If URL doesn't have a path component, it's not allowed
        return false;
    }

    // Get the path component of the URL
    $path = $parsed_url['path'];

    // Check if the path is allowed based on robots.txt rules
    foreach ($robots_rules['Disallow'] as $disallow_rule) {
        // Check if the path matches any Disallow rule
        if (fnmatch($disallow_rule, $path)) {
            return false; // URL is disallowed
        }
    }

    // If no Disallow rule matches, the URL is allowed
    return true;
}

// Call the preload function
// crawl_and_visit();
