<?php
/**
 * Default preload rules for Nginx Cache Purge Preload
 * Description: Provides default URL regex, mobile agent, file-extension exclusions,
 *              and cache-key parsing patterns for preload requests.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Default preload rules
return [
    'reject_regex'      => '/wp-(admin|content|includes|json)/|/wp-.*\.php|/xmlrpc\.php|/(cart|checkout|order-received|order-pay|wc-ajax|wc-auth|my-account|wc-api|addons|wc/store)/|/(login|logout|register|lost-password|password-reset|activate)/|/robots\.txt|/sitemap(_index)?\.xml|/[a-z0-9_-]+-sitemap([0-9]+)?\.xml|/wp-sitemap.*\.xml|/embed/|/cgi-bin/|\.well-known/|(\?|&)(add-to-cart|added-to-cart|order_again|remove_item|undo_item|apply_coupon|remove_coupon|update_cart|empty_cart|download_file|pay_for_order|change_payment_method|wc-api|wc-ajax|add_to_wishlist|remove_from_wishlist|wc-authorize-wccom|productcheckout|cancel_order|attribute_pa_[^=]*|filter_[^=]*|query_type_[^=]*|rating_filter|min_price|max_price|orderby|_wpnonce|preview|preview_id|preview_nonce|customize_changeset|wp_customize|_skip_cache|s|nocache|rest_route|replytocom|key)=|/feed/|[?&]feed=',
    'reject_extension'  => '*.css,*.min.css,*.js,*.min.js,*.png,*.jpg,*.jpeg,*.gif,*.ico,*.mp4,*.webm,*.mov,*.avi,*.mkv,*.flv,*.wmv,*.mpeg,*.mpg,*.m4v,*.3gp,*.woff,*.woff2,*.ttf,*.eot,*.svg,*.bmp,*.pdf,*.doc,*.docx,*.xls,*.xlsx,*.ppt,*.pptx,*.zip,*.rar,*.tar,*.gz,*.bz2,*.7z,*.xml,*.txt,*.sql,*.log,*.ini,*.conf,*.json,*.bak,*.old,*.tmp,*.swp,*.md,*.rst,*.py,*.sh,*.iso,*.crt,*.key,*.pem,*.out,*.xsl',
    'cache_key_regex'   => '/^KEY:\s+(?:https?:\/\/|https?(?:[A-Z]+)?)?([^\/\s]+)(\/[^\s]*)/m',
    'mobile_user_agent' => 'Mozilla/5.0 (Linux; Android 10; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36',
];
