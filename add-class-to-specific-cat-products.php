<?php


// Hook into the body_class filter to add custom classes
add_filter('body_class', 'dcloud_product_body_class');

/**
 * Add custom body class for products in a specific category
 *
 * @param array $classes Existing body classes
 * @return array Modified body classes
 */
function dcloud_product_body_class($classes)
{

    // Check if current page is a single product
    if (is_product()) {
        global $post;

        // Check if product has the category with ID 3158
        if (has_term(3158, 'product_cat', $post)) {
            // Add custom class for specific category products
            $classes[] = 'single-product-layout-dcloud-body';
        }
    }

    // Return the modified classes array
    return $classes;
}