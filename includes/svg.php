<?php
/**
 * SVG icon code for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains svg icon code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Display an SVG icon
function nppp_svg_icon_shortcode($atts) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    // Shortcode attributes
    $atts = shortcode_atts(array(
        'icon' => '', // Icon name
        'class' => '', // CSS class for styling
        'size' => '24px' // Default size
    ), $atts);

    // Sanitize icon name
    $icon = sanitize_text_field($atts['icon']);
    // Sanitize CSS class
    $class = sanitize_text_field($atts['class']);
    // Sanitize icon size
    $size = sanitize_text_field($atts['size']);

    // Path to the SVG icons directory
    $icons_directory = dirname(__FILE__) . '/../admin/img/icons/';

    // Check if the icon file exists
    $icon_path = $icons_directory . $icon . '.svg';

    if ($wp_filesystem->exists($icon_path)) {
        // Read the SVG file content
        $icon_content = $wp_filesystem->get_contents($icon_path);

        // Remove any harmful tags from svg content
        $icon_content = preg_replace('/<!--(.|\s)*?-->/', '', $icon_content);
        $icon_content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $icon_content);
        $icon_content = preg_replace( '/<!ENTITY(.*?)>/is', '', $icon_content);
        $icon_content = preg_replace( '/<!DOCTYPE(.*?)>/is', '', $icon_content);

        // Output SVG icon
        ob_start();
        ?>
        <span class="nppp-svg-icon-container <?php echo esc_attr($class); ?>" style="width: <?php echo esc_attr($size); ?>; height: <?php echo esc_attr($size); ?>">
            <?php
            // Sanitize SVG content
            echo wp_kses($icon_content, array(
                'svg' => array(
                    'xmlns' => true,
                    'width' => true,
                    'height' => true,
                    'viewbox' => true,
                    'enable-background' => true,
                    'xml:space' => true,
                    'version' => true,
                    'id' => true,
                    'fill' => true,
                    'title' => true,
                ),
                'path' => array(
                    'd' => true,
                    'stroke' => true,
                    'stroke-width' => true,
                    'stroke-linecap' => true,
                    'stroke-linejoin' => true,
                    'fill' => true,
                ),
                'g' => array(
                    'id' => true,
                    'stroke-width' => true,
                    'stroke-linecap' => true,
                    'stroke-linejoin' => true,
                ),
            ));
            ?>
        </span>
        <?php
        return ob_get_clean();
    } else {
        // Icon file not found
        return '<p>Icon not found</p>';
    }
}
