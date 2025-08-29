<?php
/**
 * Related purge helpers for FastCGI Cache Purge and Preload for Nginx
 * Description: Helper functions to purge (and optionally preload) related URLs—homepage and category archives—
 *              whenever a single post/page is purged (via auto purge, front-end action, or Advanced tab).
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Return related URLs for a primary single page URL, based on plugin options.
function nppp_get_related_urls_for_single(string $primary_url): array {
    $settings = get_option( 'nginx_cache_settings', array() );
    $urls     = array();

    // Flags (keep existing option keys)
    $include_home = ! empty( $settings['nppp_related_include_home'] ) && $settings['nppp_related_include_home'] === 'yes';
    $include_shop = ! empty( $settings['nppp_related_apply_manual'] ) && $settings['nppp_related_apply_manual'] === 'yes'; // repurposed to "Shop"
    $include_cat  = ! empty( $settings['nppp_related_include_category'] ) && $settings['nppp_related_include_category'] === 'yes';

    // 1) Home page (if enabled)
    if ( $include_home ) {
        $urls[] = home_url( '/' );
    }

    // Resolve post from URL (posts, pages, products, CPTs)
    $post_id = url_to_postid( $primary_url );
    if ( $post_id ) {
        $post_type = get_post_type( $post_id );

        // 2) WooCommerce Shop page (if enabled, and this is a product)
        if ( $include_shop && 'product' === $post_type && function_exists( 'wc_get_page_id' ) ) {
            $shop_id = (int) wc_get_page_id( 'shop' );
            if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
                $shop_url = get_permalink( $shop_id );
                if ( $shop_url ) {
                    $urls[] = $shop_url;
                }
            }
        }

        // 3) Category archives (posts => 'category', products => 'product_cat')
        if ( $include_cat ) {
            $taxonomy = ( 'product' === $post_type ) ? 'product_cat' : 'category';

            // Only act on public taxonomies with archives
            $tax_obj = get_taxonomy( $taxonomy );
            if ( $tax_obj && ! empty( $tax_obj->public ) && false !== $tax_obj->rewrite ) {
                $terms = get_the_terms( $post_id, $taxonomy );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $link = get_term_link( $term, $taxonomy );
                        if ( ! is_wp_error( $link ) && ! empty( $link ) ) {
                            $urls[] = $link;
                        }
                    }
                }
            }
        }
    }

    // Normalization
    $primary_norm = user_trailingslashit($primary_url, 'single');

    // keep only non-empty, valid absolute URLs
    $urls = array_filter($urls, static function ($u) {
        return is_string($u) && $u !== '' && false !== wp_http_validate_url($u);
    } );

    // normalize trailing slashes using site preference
    $urls = array_map(static function ($u) {
        return user_trailingslashit($u, 'single');
    }, $urls);

    // dedupe and remove the primary itself
    $urls = array_values(array_unique(array_diff($urls, array($primary_norm))));

    return apply_filters('nppp_related_urls_for_single', $urls, $primary_url, $settings);
}

// Purge a single URL from FastCGI cache silently (no admin notices). Returns ['found'=>bool,'deleted'=>bool].
function nppp_purge_url_silent(string $nginx_cache_path, string $url): array {
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) return ['found' => false, 'deleted' => false];

    // Validate URL then build search key like nppp_purge_single does
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return ['found' => false, 'deleted' => false];

    $url_no_scheme = preg_replace('#^https?://#', '', $url);
    $url_to_search = rtrim($url_no_scheme, '/') . '/';

    $settings = get_option('nginx_cache_settings');
    $regex = isset($settings['nginx_cache_key_custom_regex'])
             ? base64_decode($settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    $found = false;
    $deleted = false;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $file) {
            if (!$wp_filesystem->is_file($file->getPathname())) continue;

            $content = $wp_filesystem->get_contents($file->getPathname());
            if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                strpos($content, 'Status: 302 Found') !== false) {
                continue;
            }
            if (!preg_match('/KEY:\s.*GET/', $content)) {
                continue;
            }

            if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
                $constructed = trim($matches[1]) . trim($matches[2]);
                if ($constructed === $url_to_search) {
                    $found = true;

                    // extra safety
                    $validation = nppp_validate_path($file->getPathname(), true);
                    if ($validation !== true) break;

                    if ($wp_filesystem->is_readable($file->getPathname()) &&
                        $wp_filesystem->is_writable($file->getPathname())) {
                        $deleted = (bool)$wp_filesystem->delete($file->getPathname());
                    }
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // ignore silently
    }

    return ['found' => $found, 'deleted' => $deleted];
}

// Fire-and-forget tiny warmups for a few URLs without touching PID/Status flow.
// We use WP HTTP API here to avoid PID collisions with wget-based preloader.
function nppp_preload_urls_fire_and_forget(array $urls): void {
    if (empty($urls)) return;

    $settings = get_option('nginx_cache_settings');
    $preload_mobile = !empty($settings['nginx_cache_auto_preload_mobile']) && $settings['nginx_cache_auto_preload_mobile'] === 'yes';

    // Desktop UA always
    $headers_desktop = array('User-Agent' => NPPP_USER_AGENT);
    // Optional mobile UA
    $headers_mobile  = array('User-Agent' => NPPP_USER_AGENT_MOBILE);

    foreach ($urls as $u) {
        if ( false === wp_http_validate_url($u)) {
            continue;
        }

        // Use the canonical value produced upstream.
        wp_remote_get($u, array(
            'timeout'     => 3,
            'redirection' => 1,
            'blocking'    => false,
            'headers'     => $headers_desktop,
        ));

        if ($preload_mobile) {
            wp_remote_get($u, array(
                'timeout'     => 3,
                'redirection' => 1,
                'blocking'    => false,
                'headers'     => $headers_mobile,
            ));
        }
    }
}
