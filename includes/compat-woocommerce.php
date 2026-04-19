<?php
/**
 * WooCommerce cache purge integration for Nginx Cache Purge Preload
 * Description: Handles WooCommerce product stock changes, order completions/cancellations,
 *              direct product saves (WP‑CLI, programmatic), and taxonomy term reassignments
 *              to keep the Nginx cache synchronized. All purge operations respect the
 *              "Posts & Comments" and "Categories, Tags & Taxonomies" auto‑purge sub‑option.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Only register hooks when WooCommerce is active and NPP auto-purge is enabled.
// $nppp_auto_purge is set in admin.php before this file is required.
if ( class_exists( 'WooCommerce' ) && $nppp_auto_purge ) {
    // Stock quantity change: fires from handle_updated_props() (PATH-A, explicit save)
    // or from wc_update_product_stock() after save() returns (PATH-B, order-triggered).
    add_action( 'woocommerce_product_set_stock',           'nppp__wc_purge_product',         10, 1 );
    add_action( 'woocommerce_variation_set_stock',         'nppp__wc_purge_product',         10, 1 );

    // Stock status change (instock ↔ outofstock ↔ onbackorder): fires from
    // handle_updated_props() when validate_props() auto-adjusts status on save().
    // On order-triggered stock changes this fires BEFORE woocommerce_update_product,
    // so it claims the dedup slot first when stock crosses the outofstock threshold.
    add_action( 'woocommerce_product_set_stock_status',    'nppp__wc_purge_stock_status',    10, 3 );
    add_action( 'woocommerce_variation_set_stock_status',  'nppp__wc_purge_stock_status',    10, 3 );

    // Programmatic/REST/WP-CLI saves: fires at end of data_store::update(), after
    // handle_updated_props(). The doing_action('save_post') guard prevents double-purge
    // with NPP core on admin saves. Dedup serialises racing stock hooks.
    add_action( 'woocommerce_update_product',              'nppp__wc_purge_on_product_save', 20, 2 );
    add_action( 'woocommerce_update_product_variation',    'nppp__wc_purge_on_product_save', 20, 2 );

    // Term archive purge: fires BEFORE WordPress removes term rows. Bypasses dedup
    // intentionally — purging category/tag archive URLs (not the product URL).
    add_action( 'delete_term_relationships',               'nppp__wc_purge_removed_term_archives', 5, 2 );
}

/**
 * Request-scoped deduplication registry.
 *
 * Returns true (and marks) on first call for a given post ID, false on
 * subsequent calls within the same PHP request. This prevents multiple hooks
 * that all respond to the same underlying stock/product save event from
 * firing redundant cache scans.
 *
 * @param int $post_id The product post ID (always the parent for variations).
 * @return bool True if this post_id was NOT yet purged this request; false if already purged.
 */
function nppp__wc_claim_purge( int $post_id ): bool {
    static $seen = [];
    if ( isset( $seen[ $post_id ] ) ) {
        return false;
    }
    $seen[ $post_id ] = true;
    return true;
}

/**
 * Helper: returns the configured Nginx cache path if auto-purge is enabled,
 * or false if purging is disabled or settings are missing.
 * Called by every purge function to avoid repeating the options lookup.
 */
function nppp__wc_get_cache_path() {
    $opts = get_option( 'nginx_cache_settings' ) ?: [];
    if ( ( $opts['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
        return false;
    }
    return $opts['nginx_cache_path'] ?? '/dev/shm/change-me-now';
}

/**
 * Purge a single product triggered by a stock quantity change.
 * Hooked to: woocommerce_product_set_stock, woocommerce_variation_set_stock.
 *
 * For variations, WooCommerce may pass the variation object or the parent object
 * depending on whether the variation manages its own stock. In both cases we
 * resolve to the parent product ID because that is the public-facing URL.
 */
function nppp__wc_purge_product( $product ) {
    if ( ! ( $product instanceof WC_Product ) ) { return; }

    // During a manual product save, transition_post_status already fired
    // and purged this product URL. Skip to avoid a redundant second purge.
    // We check doing_action('save_post') because WC fires stock hooks from
    // inside the save_post callback chain during a full wp_update_post() save.
    // Order placement, cancellation, and direct stock edits do NOT go through
    // save_post, so those paths are unaffected by this guard.
    if ( doing_action( 'save_post' ) || doing_action( 'save_post_product' ) ) { return; }

    $cache_path = nppp__wc_get_cache_path();
    if ( ! $cache_path ) { return; }

    // Respect the "Posts & Comments" auto purge sub-option
    $opts = get_option( 'nginx_cache_settings' ) ?: [];
    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) { return; }

    // For variations, purge the parent product page.
    $post_id = $product->is_type( 'variation' )
        ? $product->get_parent_id()
        : $product->get_id();

    nppp__wc_purge_product_and_terms( $post_id, $cache_path );
}

/**
 * Purge a product triggered by a stock STATUS change (instock/outofstock/onbackorder).
 * Hooked to: woocommerce_product_set_stock_status, woocommerce_variation_set_stock_status.
 *
 * WooCommerce passes the product object as the 3rd argument from WC 3.0+.
 * Fall back to wc_get_product() for older versions where $product may be null.
 */
function nppp__wc_purge_stock_status( $product_id, $stock_status = null, $product = null ) {
    if ( ! $product && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( $product_id );
    }
    if ( ! $product ) { return; }

    nppp__wc_purge_product( $product );
}

/**
 * Purge a product page when saved via the WooCommerce data store layer.
 * Hooked to: woocommerce_update_product, woocommerce_update_product_variation.
 *
 * Covers WP-CLI batch imports, REST API updates where stock does not change
 * post-status (publish→publish), and any 3rd-party code calling $product->save()
 * outside the standard admin save_post flow.
 *
 * Firing context: this action fires at the END of data_store::update(),
 * AFTER handle_updated_props() has already fired woocommerce_product_set_stock /
 * woocommerce_product_set_stock_status. For PATH-B order-triggered stock
 * changes via wc_update_product_stock(), this hook fires BEFORE the explicit
 * woocommerce_product_set_stock that fires after save() returns. The request-scoped
 * dedup in nppp__wc_purge_product_and_terms() correctly serialises all three paths.
 *
 * Guards:
 *  - doing_action('save_post'): admin form saves are handled by NPP core via
 *    transition_post_status; skip to avoid double purge.
 *  - REST API first-publish (draft→publish): both NPP core and this hook fire,
 *    both are no-ops (no cache entry exists yet). Accepted minor overhead.
 */
function nppp__wc_purge_on_product_save( $product_id, $product = null ) {
    // Skip when the standard save_post flow is active — transition_post_status
    // has already queued or executed the purge for this URL.
    if (
        doing_action( 'save_post' )               ||
        doing_action( 'save_post_product' )        ||
        doing_action( 'save_post_product_variation' )
    ) {
        return;
    }

    if ( ! function_exists( 'wc_get_product' ) ) { return; }

    // $product is passed as 2nd arg from WC 3.0+; fall back to lookup for older.
    if ( ! $product instanceof WC_Product ) {
        $product = wc_get_product( $product_id );
    }
    if ( ! $product ) { return; }

    $cache_path = nppp__wc_get_cache_path();
    if ( ! $cache_path ) { return; }

    // Respect the "Posts & Comments" auto-purge sub-option.
    $opts = get_option( 'nginx_cache_settings' ) ?: [];
    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) { return; }

    // For variations, purge the parent product page (the public-facing URL).
    $post_id = $product->is_type( 'variation' )
        ? $product->get_parent_id()
        : $product->get_id();

    nppp__wc_purge_product_and_terms( $post_id, $cache_path );
}

/**
 * Purge old taxonomy archive pages when a product's category/tag assignments change.
 * Hooked to: delete_term_relationships (priority 5, fires before rows are deleted).
 *
 * WordPress calls wp_delete_object_term_relationships() on every product save —
 * it always wipes the existing rows and re-inserts the new set. Without this hook,
 * the previous category archive page stays cached with the product still listed
 * after it has been removed.
 *
 * Performance note: WordPress always delete-then-reinserts all term relationships
 * on every product save, so this fires even when categories did not change.
 * The post-type guard and taxonomy filter keep overhead minimal.
 */
function nppp__wc_purge_removed_term_archives( $object_id, $tt_ids ) {
    if ( empty( $tt_ids ) || ! is_array( $tt_ids ) ) { return; }
    if ( 'product' !== get_post_type( $object_id ) ) { return; }

    $cache_path = nppp__wc_get_cache_path();
    if ( ! $cache_path ) { return; }

    $opts = get_option( 'nginx_cache_settings' ) ?: [];
    if ( ( $opts['nppp_autopurge_terms'] ?? 'no' ) !== 'yes' ) { return; }

    // Collect the taxonomy names for publicly viewable WooCommerce product taxonomies.
    // get_object_taxonomies() is cached by WordPress after the first call.
    $wc_taxonomies = get_object_taxonomies( 'product', 'objects' );
    $public_tax    = [];
    foreach ( $wc_taxonomies as $tax_name => $tax_obj ) {
        if ( ! empty( $tax_obj->public ) ) {
            $public_tax[] = $tax_name;
        }
    }
    if ( empty( $public_tax ) ) { return; }

    // Resolve each term_taxonomy_id to a term object and purge its archive URL.
    // get_term_by( 'term_taxonomy_id' ) hits the WordPress term cache when warm.
    foreach ( (array) $tt_ids as $tt_id ) {
        $term = get_term_by( 'term_taxonomy_id', (int) $tt_id );
        if ( ! $term || is_wp_error( $term ) ) { continue; }
        if ( ! in_array( $term->taxonomy, $public_tax, true ) ) { continue; }

        $archive_url = get_term_link( $term );
        if ( is_wp_error( $archive_url ) || empty( $archive_url ) ) { continue; }

        nppp_purge_single( $cache_path, $archive_url, true );
    }
}

/**
 * Core purge helper: deletes cache for a product page and all its taxonomy archive pages.
 *
 * Called by every purge path above. nppp_purge_single() internally calls
 * nppp_get_related_urls_for_single() which handles home, shop, product_cat,
 * and product_tag archives per the user's Purge Scope settings.
 */
function nppp__wc_purge_product_and_terms( $post_id, $cache_path ) {
    // Single dedup gate: whichever hook reaches here first for this post_id
    // wins the purge slot; all subsequent hooks within this request are no-ops.
    // Covers: set_stock + woocommerce_update_product double-fire, stock_status
    // side-effect fires, and order-hook + stock-hook overlap on cancel/complete.
    if ( ! nppp__wc_claim_purge( $post_id ) ) {
        return;
    }

    $url = get_permalink( $post_id );
    if ( $url ) {
        nppp_purge_single( $cache_path, $url, true );
    }
}
