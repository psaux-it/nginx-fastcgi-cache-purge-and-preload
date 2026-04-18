<?php
/**
 * WooCommerce cache purge integration for Nginx Cache Purge Preload
 * Description: Handles WooCommerce product stock changes, order completions/cancellations,
 *              direct product saves (WP‑CLI, programmatic), and taxonomy term reassignments
 *              to keep the Nginx cache synchronized. All purge operations respect the
 *              "Posts & Comments" auto‑purge sub‑option.
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
    // Fires when stock QUANTITY changes (e.g. order placed, manual edit).
    // Passes WC_Product object directly — 1 arg.
    add_action( 'woocommerce_product_set_stock',           'nppp__wc_purge_product',        10, 1 );
    add_action( 'woocommerce_variation_set_stock',         'nppp__wc_purge_product',        10, 1 );

    // Fires when stock STATUS string changes: instock ↔ outofstock ↔ onbackorder.
    add_action( 'woocommerce_product_set_stock_status',    'nppp__wc_purge_stock_status',   10, 3 );
    add_action( 'woocommerce_variation_set_stock_status',  'nppp__wc_purge_stock_status',   10, 3 );

    // Fires when an order is cancelled. WooCommerce restores stock on cancellation,
    // so affected product pages need to be refreshed.
    add_action( 'woocommerce_order_status_cancelled',      'nppp__wc_purge_order_products', 10, 1 );

    // Safety net: fires when a stock-managed product's order goes directly to
    // 'completed' without passing through 'processing' (certain payment gateways,
    // manual admin completion). For the normal checkout flow the stock hooks above
    // already fired when the 'processing' transition ran; this catches the edge case.
    add_action( 'woocommerce_order_status_completed',      'nppp__wc_purge_order_products', 20, 1 );

    // Fires inside WC_Product_Data_Store_CPT::update() — called by $product->save().
    // Covers WP-CLI batch imports/updates, 3rd-party integrations, and any code
    // that calls $product->save() outside the standard save_post admin flow.
    // NOTE: REST API (wc/v3) and the standard admin form both already fire
    // transition_post_status, so the handler guards against double-purging.
    add_action( 'woocommerce_update_product',              'nppp__wc_purge_on_product_save', 20, 2 );
    add_action( 'woocommerce_update_product_variation',    'nppp__wc_purge_on_product_save', 20, 2 );

    // Fires just BEFORE WordPress deletes term relationships during a product save.
    // By the time transition_post_status fires, the old term IDs are already gone,
    // so nppp_get_related_urls_for_single() cannot discover the removed category
    // or tag archive URLs. Hooking here at priority 5 captures them while they
    // are still resolvable, so removing a product from a category purges that
    // category archive page correctly.
    // Priority 5 ensures this runs before WordPress removes the rows (core = 10).
    add_action( 'delete_term_relationships',               'nppp__wc_purge_removed_term_archives', 5, 2 );
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

    // Respect the "Posts & Comments" auto purge sub-option
    $opts = get_option( 'nginx_cache_settings' ) ?: [];
    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) { return; }

    nppp__wc_purge_product( $product );
}

/**
 * Purge all stock-managed products in a cancelled order.
 * Hooked to: woocommerce_order_status_cancelled, woocommerce_order_status_completed.
 *
 * WooCommerce restores stock quantities on order cancellation. Only products
 * with stock management enabled are affected — skip the rest to avoid
 * unnecessary cache scans.
 */
function nppp__wc_purge_order_products( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) { return; }
    $order = wc_get_order( $order_id );

    if ( ! $order ) { return; }
    $cache_path = nppp__wc_get_cache_path();

    if ( ! $cache_path ) { return; }

    $seen = [];

    // Respect the "Posts & Comments" auto purge sub-option
    $opts = get_option( 'nginx_cache_settings' ) ?: [];
    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) { return; }

    foreach ( $order->get_items( 'line_item' ) as $item ) {
        $product = $item->get_product();

        // Only purge products with WooCommerce stock management enabled.
        if ( ! $product || ! $product->managing_stock() ) { continue; }

        $post_id = $product->is_type( 'variation' )
            ? $product->get_parent_id()
            : $product->get_id();

        if ( isset( $seen[ $post_id ] ) ) { continue; }
        $seen[ $post_id ] = true;

        nppp__wc_purge_product_and_terms( $post_id, $cache_path );
    }
}

/**
 * Purge a product page when saved via the WooCommerce data store layer.
 * Hooked to: woocommerce_update_product, woocommerce_update_product_variation.
 *
 * These hooks fire inside WC_Product_Data_Store_CPT::update() on every call to
 * $product->save(), regardless of how the save was initiated (WP-CLI, 3rd-party
 * integrations, programmatic updates, etc.).
 *
 * Double-purge guard: When the standard admin form saves a product,
 * wp_update_post() is called inside the data store, which also fires
 * transition_post_status. That hook purges the page at priority 10 before
 * this handler runs at priority 20. We detect that situation via doing_action()
 * and bail early to skip the redundant second purge.
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
    if ( ( $opts['nppp_autopurge_posts'] ?? 'no' ) !== 'yes' ) { return; }

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
    $url = get_permalink( $post_id );
    if ( $url ) {
        nppp_purge_single( $cache_path, $url, true );
    }
}
