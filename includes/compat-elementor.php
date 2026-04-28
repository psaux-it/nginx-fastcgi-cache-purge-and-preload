<?php
/**
 * Elementor cache purge integration for Nginx Cache Purge Preload
 * Description: Triggers targeted Nginx cache purges when Elementor saves content or regenerates CSS.
 * Version: 2.1.6
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined('ABSPATH') ) { exit; }

// simple per-request de-dupe so we don't purge twice if both hooks run.
function nppp__el_mark_purged( $set = null ) {
    static $did = false;
    if ( $set === true ) { $did = true; }
    return $did;
}

// Elementor-specific purge triggers.
// Register directly so lazy bootstrap loading on init does not miss plugins_loaded timing.
if ( defined('ELEMENTOR_VERSION') && $nppp_auto_purge ) {
    // When an Elementor document is saved
    add_action('elementor/editor/after_save', 'nppp__el_after_save', 10, 2);
    add_action('elementor/document/after_save', 'nppp__el_document_after_save', 10, 2);

    // When Elementor clears its own files/CSS
    add_action('elementor/core/files/clear_cache', 'nppp__el_clear_files');
}

function nppp__el_after_save( $post_id, $editor_data ) {
    // Skip autosaves triggered on editor open.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Skip the Elementor editor page load (GET action=elementor).
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $get_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
    // phpcs:enable
    if ( 'elementor' === $get_action ) {
        return;
    }

    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;

    if ( nppp__el_mark_purged() ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $pidfile    = nppp_get_runtime_file('cache_preload.pid');
    $tmp        = rtrim($cache_path, '/') . '/tmp';

    if ( get_post_status( $post_id ) !== 'publish' ) {
        return;
    }

    // Elementor template type — covers both Free and Pro document types.
    $tpl_type     = get_post_meta( $post_id, '_elementor_template_type', true );
    $global_types = [ 'header', 'footer', 'single', 'archive', 'popup', 'search-results', '404', 'product', 'product-archive', 'loop-item' ];

    // Global theme parts => purge all cached pages (site-wide impact).
    if ( in_array( $tpl_type, $global_types, true ) ) {
        if ( ( $opts['nppp_autopurge_themes'] ?? 'no' ) === 'yes' ) {
            nppp_purge( $cache_path, $pidfile, $tmp, false, false, true );
            nppp__el_mark_purged( true );
        }
        return;
    }

    // Skip internal Elementor library types with no cacheable frontend URL.
    $internal_types = [ 'kit', 'floating-buttons', 'section', 'container', 'page' ];
    if ( in_array( $tpl_type, $internal_types, true ) ) {
        return;
    }

    // Regular page/post – only purge the specific URL.
    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) {
        return;
    }

    $url = get_permalink( $post_id );
    if ( $url ) {
        nppp_purge_single( $cache_path, $url, true );
        nppp__el_mark_purged( true );
    }
}

function nppp__el_document_after_save( $document, $data ) {
    // Skip Elementor autosave documents (child revisions created during editor open).
    if ( method_exists( $document, 'is_autosave' ) && $document->is_autosave() ) {
        return;
    }

    // Skip when WordPress itself has defined the autosave constant.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Skip when the save data explicitly carries an autosave status.
    if ( isset( $data['settings']['post_status'] ) && 'autosave' === $data['settings']['post_status'] ) {
        return;
    }

    // Skip when the save data carries NO settings and NO elements.
    // This is a metadata-only save (save_template_type / save_version calls)
    // triggered during editor initialisation, not a real user publish action.
    if ( empty( $data['settings'] ) && empty( $data['elements'] ) ) {
        return;
    }

    // Skip the Elementor editor page load (GET action=elementor).
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $get_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
    // phpcs:enable
    if ( 'elementor' === $get_action ) {
        return;
    }

    // Skip saves arriving via any REST request — those are internal Elementor REST
    // endpoints (e.g. elementor/v1/documents/{id}/media/import, CSS regeneration)
    // that call document->save() for non-publish purposes. Real Gutenberg-style
    // REST publishes are already handled by compat-gutenberg.php.
    if (
        ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST )
    ) {
        return;
    }

    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( nppp__el_mark_purged() ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $pidfile    = nppp_get_runtime_file('cache_preload.pid');
    $tmp        = rtrim($cache_path, '/') . '/tmp';

    $post_id = method_exists($document, 'get_main_id') ? $document->get_main_id() : 0;

    // Detect global theme parts via post meta (works with both Free & Pro).
    $tpl_type     = $post_id ? get_post_meta( $post_id, '_elementor_template_type', true ) : '';
    $global_types = [ 'header', 'footer', 'single', 'archive', 'popup', 'search-results', '404', 'product', 'product-archive', 'loop-item' ];
    $is_theme_doc = in_array( $tpl_type, $global_types, true );

    // For global theme parts: no publish check needed
    if ( $is_theme_doc ) {
        if ( ( $opts['nppp_autopurge_themes'] ?? 'no' ) === 'yes' ) {
            nppp_purge( $cache_path, $pidfile, $tmp, false, false, true );
            nppp__el_mark_purged( true );
        }
        return;
    }

    // Skip internal Elementor documents that have no cacheable frontend URL.
    $internal_types = [ 'kit', 'floating-buttons', 'section', 'container', 'page' ];
    if ( in_array( $tpl_type, $internal_types, true ) ) {
        return;
    }

    // Regular page/post: only purge when actually published.
    if ( ! $post_id || get_post_status( $post_id ) !== 'publish' ) {
        return;
    }

    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) === 'yes' ) {
        $url = get_permalink( $post_id );
        if ( $url ) {
            nppp_purge_single( $cache_path, $url, true );
            nppp__el_mark_purged( true );
        }
    }
}

function nppp__el_clear_files() {
    $opts = get_option('nginx_cache_settings') ?: [];
    if ( ($opts['nginx_cache_purge_on_update'] ?? 'no') !== 'yes' ) return;
    if ( ($opts['nppp_autopurge_themes'] ?? 'no') !== 'yes' ) return;

    $cache_path = $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
    $pidfile    = nppp_get_runtime_file('cache_preload.pid');
    $tmp        = rtrim($cache_path, '/') . '/tmp';

    // Elementor regenerated CSS → purge all (site-wide impact)
    nppp_purge($cache_path, $pidfile, $tmp, false, false, true);
    nppp__el_mark_purged(true);
}
