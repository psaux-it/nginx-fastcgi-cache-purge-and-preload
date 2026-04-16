<?php
/**
 * Related URL purge helpers for Nginx Cache Purge Preload
 * Description: Purges and optionally preloads related archives when singular content is purged.
 *              whenever a single post/page is purged (via auto purge, front-end action, or Advanced tab).
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Return related URLs for a primary URL, based on plugin options.
function nppp_get_related_urls_for_single(string $primary_url): array {
    $settings = get_option( 'nginx_cache_settings', array() );
    $urls     = array();

    // Flags (keep existing option keys)
    $include_home = ! empty( $settings['nppp_related_include_home'] ) && $settings['nppp_related_include_home'] === 'yes';
    $include_shop = ! empty( $settings['nppp_related_apply_manual'] ) && $settings['nppp_related_apply_manual'] === 'yes'; // repurposed to "Shop"
    $include_cat  = ! empty( $settings['nppp_related_include_category'] ) && $settings['nppp_related_include_category'] === 'yes';

    // 1) Home page (if enabled) — already unconditional, applies to all purge targets.
    if ( $include_home ) {
        $urls[] = home_url( '/' );
    }

    // Resolve post from URL (posts, pages, products, CPTs).
    // url_to_postid() returns 0 for taxonomy archive URLs (it only resolves singular posts).
    $post_id = url_to_postid( $primary_url );
    if ( $post_id ) {
        $post_type = get_post_type( $post_id );

        // 2) WooCommerce Shop page (if enabled, and primary URL is a product).
        if ( $include_shop && 'product' === $post_type && function_exists( 'wc_get_page_id' ) ) {
            $shop_id = (int) wc_get_page_id( 'shop' );
            if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
                $shop_url = get_permalink( $shop_id );
                if ( $shop_url ) {
                    $urls[] = $shop_url;
                }
            }
        }

        // 3) Category and tag archives (posts => category + post_tag, products => product_cat + product_tag)
        if ( $include_cat ) {
            $taxonomy = ( 'product' === $post_type ) ? 'product_cat' : 'category';

            // Categories
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

            // Product tag archives — WooCommerce products only.
            if ( 'product' === $post_type ) {
                $tag_obj = get_taxonomy( 'product_tag' );
                if ( $tag_obj && ! empty( $tag_obj->public ) && false !== $tag_obj->rewrite ) {
                    $tags = get_the_terms( $post_id, 'product_tag' );
                    if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
                        foreach ( $tags as $tag ) {
                            $link = get_term_link( $tag, 'product_tag' );
                            if ( ! is_wp_error( $link ) && ! empty( $link ) ) {
                                $urls[] = $link;
                            }
                        }
                    }
                }
            }

            // Post tag archives — standard WordPress posts only.
            if ( 'post' === $post_type ) {
                $tag_obj = get_taxonomy( 'post_tag' );
                if ( $tag_obj && ! empty( $tag_obj->public ) && false !== $tag_obj->rewrite ) {
                    $tags = get_the_terms( $post_id, 'post_tag' );
                    if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
                        foreach ( $tags as $tag ) {
                            $link = get_term_link( $tag, 'post_tag' );
                            if ( ! is_wp_error( $link ) && ! empty( $link ) ) {
                                $urls[] = $link;
                            }
                        }
                    }
                }
            }
        }
    } else {
        // 2b) WooCommerce Shop page for product taxonomy archive URLs.
        if ( $include_shop && function_exists( 'wc_get_page_id' ) ) {
            $is_wc_product_tax = false;
            foreach ( array( 'product_cat', 'product_tag' ) as $_wc_tax ) {
                $tax_obj = get_taxonomy( $_wc_tax );
                if ( ! $tax_obj || empty( $tax_obj->rewrite ) || false === $tax_obj->rewrite ) {
                    continue;
                }
                $rewrite_slug = $tax_obj->rewrite['slug'] ?? '';
                if ( $rewrite_slug === '' ) {
                    continue;
                }
                $path = wp_parse_url( $primary_url, PHP_URL_PATH ) ?? '';
                // Match /<rewrite-slug>/ anywhere in the path (handles sub-paths too).
                if ( false !== strpos( $path, '/' . ltrim( $rewrite_slug, '/' ) . '/' ) ) {
                    $is_wc_product_tax = true;
                    break;
                }
            }
            if ( $is_wc_product_tax ) {
                $shop_id = (int) wc_get_page_id( 'shop' );
                if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
                    $shop_url = get_permalink( $shop_id );
                    if ( $shop_url ) {
                        $urls[] = $shop_url;
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

// Fire-and-forget
function nppp_preload_urls_fire_and_forget(array $urls): void {
    if (empty($urls)) return;

    $settings                 = get_option('nginx_cache_settings');
    $preload_mobile           = !empty($settings['nginx_cache_auto_preload_mobile']) && $settings['nginx_cache_auto_preload_mobile'] === 'yes';
    $nginx_cache_path         = isset($settings['nginx_cache_path']) ? $settings['nginx_cache_path'] : '/dev/shm/change-me-now';
    $nginx_cache_limit_rate   = isset($settings['nginx_cache_limit_rate']) ? (int)$settings['nginx_cache_limit_rate'] : 5120;
    $nginx_cache_read_timeout = isset($settings['nginx_cache_read_timeout']) ? (int)$settings['nginx_cache_read_timeout'] : 60;
    $tmp_path                 = rtrim($nginx_cache_path, '/') . '/tmp';

    // Proxy settings
    $proxy_settings = nppp_get_proxy_settings();
    $use_proxy      = $proxy_settings['use_proxy'];
    $http_proxy     = $proxy_settings['http_proxy'];
    $https_proxy    = $http_proxy;

    // safexec
    $safexec_path = nppp_find_safexec_path();
    $use_safexec  = nppp_is_safexec_usable($safexec_path ?: '', false);

    foreach ($urls as $u) {
        if (false === wp_http_validate_url($u)) {
            continue;
        }

        // Build per-URL domain allowlist
        $parsed    = wp_parse_url($u);
        $host      = strtolower($parsed['host'] ?? '');
        if ($host === '') continue;
        $base_host   = preg_replace('/^www\./i', '', $host);
        $domain_list = escapeshellarg(implode(',', array_unique([$base_host, 'www.' . $base_host])));

        $common =
            '--quiet --no-config --no-cookies --delete-after ' .
            '--no-dns-cache --no-check-certificate --prefer-family=IPv4 ' .
            '--dns-timeout=10 --connect-timeout=5 --read-timeout=' . $nginx_cache_read_timeout . ' --tries=1 ' .
            '-e robots=off ' .
            '-e ' . escapeshellarg('use_proxy=' . $use_proxy) . ' ' .
            '-e ' . escapeshellarg('http_proxy='  . $http_proxy) . ' ' .
            '-e ' . escapeshellarg('https_proxy=' . $https_proxy) . ' ' .
            '-P ' . escapeshellarg($use_safexec ? '/tmp' : $tmp_path) . ' ' .
            '--limit-rate=' . $nginx_cache_limit_rate . 'k ' .
            '--domains=' . $domain_list . ' ' .
            '--header=' . escapeshellarg(NPPP_HEADER_ACCEPT) . ' ';

        $safexec_prefix = $use_safexec ? escapeshellarg($safexec_path) . ' ' : '';
        $url_arg        = escapeshellarg($u);

        // Desktop
        $cmd_desktop = $safexec_prefix .
            'nohup wget ' . $common .
            '--user-agent=' . escapeshellarg(NPPP_USER_AGENT) . ' ' .
            '-- ' . $url_arg . ' >/dev/null 2>&1 &';
        shell_exec($cmd_desktop);

        // Mobile (if enabled)
        if ($preload_mobile) {
            $cmd_mobile = $safexec_prefix .
                'nohup wget ' . $common .
                '--user-agent=' . escapeshellarg(NPPP_USER_AGENT_MOBILE) . ' ' .
                '-- ' . $url_arg . ' >/dev/null 2>&1 &';
            shell_exec($cmd_mobile);
        }
    }
}
