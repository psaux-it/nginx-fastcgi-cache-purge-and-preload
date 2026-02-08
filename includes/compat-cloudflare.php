<?php
/**
 * Cloudflare APO integration — keep Cloudflare cache in sync when Nginx cache purges.
 * Description: Mirrors NPP purge actions to Cloudflare APO (full purge + single\related URLs).
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'nppp_cloudflare_apo_is_available' ) ) {
    function nppp_cloudflare_apo_is_available(): bool {
        return class_exists( '\Cloudflare\APO\WordPress\Hooks' ) && defined( 'CLOUDFLARE_PLUGIN_DIR' );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_get_hooks' ) ) {
    function nppp_cloudflare_apo_get_hooks() {
        static $hooks = null;

        if ( $hooks instanceof \Cloudflare\APO\WordPress\Hooks ) {
            return $hooks;
        }

        if ( ! nppp_cloudflare_apo_is_available() ) {
            return null;
        }

        $config_path = trailingslashit( CLOUDFLARE_PLUGIN_DIR ) . 'config.json';
        if ( ! is_readable( $config_path ) ) {
            return null;
        }

        $hooks = new \Cloudflare\APO\WordPress\Hooks();
        return $hooks;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_term_urls' ) ) {
    function nppp_cloudflare_apo_term_urls( int $post_id ): array {
        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return array();
        }

        $urls = array();
        $taxonomies = get_object_taxonomies( $post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $tax_obj = get_taxonomy( $taxonomy );
            if ( ! ( $tax_obj instanceof WP_Taxonomy ) || false === $tax_obj->public ) {
                continue;
            }

            $terms = get_the_terms( $post_id, $taxonomy );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) && $link ) {
                    $urls[] = $link;
                }
                $feed = get_term_feed_link( $term->term_id, $term->taxonomy );
                if ( ! is_wp_error( $feed ) && $feed ) {
                    $urls[] = $feed;
                }
            }
        }

        return array_values( array_unique( $urls ) );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_is_date_archive_url' ) ) {
    function nppp_cloudflare_apo_is_date_archive_url( string $url ): bool {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) ) {
            return false;
        }

        return (bool) preg_match( '#/(?:\d{4})(?:/\d{1,2})?(?:/\d{1,2})?/?$#', $path );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_filter_urls' ) ) {
    function nppp_cloudflare_apo_filter_urls( $urls, $post_id ) {
        if ( empty( $GLOBALS['NPPP_CF_APO_FILTER_ACTIVE'] ) ) {
            return $urls;
        }

        if ( ! is_array( $urls ) ) {
            return $urls;
        }

        $excluded = array();
        if ( is_numeric( $post_id ) ) {
            $excluded = nppp_cloudflare_apo_term_urls( (int) $post_id );
        }

        $filtered = array();
        foreach ( $urls as $url ) {
            $candidate = $url;
            if ( is_array( $url ) && ! empty( $url['url'] ) ) {
                $candidate = $url['url'];
            }

            if ( is_string( $candidate ) ) {
                if ( in_array( $candidate, $excluded, true ) ) {
                    continue;
                }

                if ( nppp_cloudflare_apo_is_date_archive_url( $candidate ) ) {
                    continue;
                }
            }

            $filtered[] = $url;
        }

        return array_values( $filtered );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_purge_all' ) ) {
    function nppp_cloudflare_apo_purge_all(): void {
        if ( ! nppp_cloudflare_apo_is_available() ) {
            return;
        }

        if ( ! apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_all' ) ) {
            return;
        }

        $hooks = nppp_cloudflare_apo_get_hooks();
        if ( $hooks ) {
            $hooks->purgeCacheEverything();
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_purge_urls' ) ) {
    function nppp_cloudflare_apo_purge_urls( array $urls, string $primary_url = '', int $post_id = 0, bool $is_auto = false ): void {
        if ( ! nppp_cloudflare_apo_is_available() ) {
            return;
        }

        if ( ! apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_urls', $urls, $primary_url, $post_id, $is_auto ) ) {
            return;
        }

        if ( $post_id <= 0 ) {
            $post_id = (int) url_to_postid( $primary_url );
        }

        if ( $post_id <= 0 ) {
            return;
        }

        $hooks = nppp_cloudflare_apo_get_hooks();
        if ( ! $hooks ) {
            return;
        }

        $GLOBALS['NPPP_CF_APO_FILTER_ACTIVE'] = true;
        add_filter( 'cloudflare_purge_by_url', 'nppp_cloudflare_apo_filter_urls', 10, 2 );

        $hooks->purgeCacheByRelevantURLs( $post_id );

        remove_filter( 'cloudflare_purge_by_url', 'nppp_cloudflare_apo_filter_urls', 10 );
        unset( $GLOBALS['NPPP_CF_APO_FILTER_ACTIVE'] );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_sync_option_enabled' ) ) {
    function nppp_cloudflare_apo_sync_option_enabled( $enabled, string $context = '' ): bool {
        $options = get_option( 'nginx_cache_settings', array() );
        if ( ! nppp_cloudflare_apo_is_available() && isset( $options['nppp_cloudflare_apo_sync'] ) && $options['nppp_cloudflare_apo_sync'] !== 'no' ) {
            $options['nppp_cloudflare_apo_sync'] = 'no';
            update_option( 'nginx_cache_settings', $options );
        }
        if ( isset( $options['nppp_cloudflare_apo_sync'] ) && $options['nppp_cloudflare_apo_sync'] === 'no' ) {
            return false;
        }

        return (bool) $enabled;
    }
}

add_filter( 'nppp_sync_cloudflare_apo_enabled', 'nppp_cloudflare_apo_sync_option_enabled', 10, 5 );
add_action( 'nppp_purged_all', 'nppp_cloudflare_apo_purge_all' );
add_action( 'nppp_purged_urls', 'nppp_cloudflare_apo_purge_urls', 10, 4 );
