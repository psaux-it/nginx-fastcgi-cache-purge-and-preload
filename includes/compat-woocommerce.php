<?php
/**
 * WooCommerce cache purge integration for Nginx Cache Purge Preload
 * Description: Purges Nginx cache when WooCommerce product stock changes.
 *              Stock updates bypass wp_update_post() entirely (direct DB write),
 *              so transition_post_status never fires for them.
 * Version: 2.1.4
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
 * Purge all stock-managed products in a cancelled order.
 * Hooked to: woocommerce_order_status_cancelled.
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
 * Core purge helper: deletes cache for a product page and all its taxonomy archive pages.
 *
 * Called by every purge path above. Purges three layers:
 *   1. The product page itself  (e.g. /product/blue-shirt/)
 *   2. Its product_cat archives (e.g. /product-category/shirts/) — WooCommerce can
 *      hide out-of-stock products from listings, so the category page must refresh.
 *   3. Its product_tag archives (e.g. /product-tag/summer/) — same reason.
 */
function nppp__wc_purge_product_and_terms( $post_id, $cache_path ) {
    // 1. Product page.
    $url = get_permalink( $post_id );
    if ( $url ) {
        nppp_purge_single( $cache_path, $url, true );
    }

    // 2. Category archive pages.
    if ( function_exists( 'wc_get_product_cat_ids' ) ) {
        foreach ( wc_get_product_cat_ids( $post_id ) as $cat_id ) {
            $cat_url = get_term_link( (int) $cat_id, 'product_cat' );
            if ( $cat_url && ! is_wp_error( $cat_url ) ) {
                nppp_purge_single( $cache_path, $cat_url, true );
            }
        }
    }

    // 3. Product tag archive pages.
    if ( function_exists( 'wc_get_product_terms' ) ) {
        $tags = wc_get_product_terms( $post_id, 'product_tag', [ 'fields' => 'ids' ] );
        foreach ( $tags as $tag_id ) {
            $tag_url = get_term_link( (int) $tag_id, 'product_tag' );
            if ( $tag_url && ! is_wp_error( $tag_url ) ) {
                nppp_purge_single( $cache_path, $tag_url, true );
            }
        }
    }
}
