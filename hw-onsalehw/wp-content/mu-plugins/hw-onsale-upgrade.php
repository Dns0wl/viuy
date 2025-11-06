<?php
/**
 * Plugin Name: HW On Sale Upgrade
 * Description: Optimizes the WooCommerce On Sale page with fragment caching and optional asset tweaks.
 * Author: Codex Assistant
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('HW_ONSALE_PAGE_ID')) {
    define('HW_ONSALE_PAGE_ID', 21650);
}

if (! defined('HW_ONSALE_PER_PAGE')) {
    define('HW_ONSALE_PER_PAGE', 12);
}

if (! defined('HW_TTL_IDS')) {
    define('HW_TTL_IDS', 600);
}

if (! defined('HW_TTL_HTML')) {
    define('HW_TTL_HTML', 120);
}

add_filter('the_content', 'hw_onsale_upgrade_filter_content', 20);
add_action('wp_enqueue_scripts', 'hw_onsale_upgrade_maybe_optimize_assets', 100);

/**
 * Filter the On Sale page content for anonymous visitors.
 *
 * @param string $content Original content.
 * @return string
 */
function hw_onsale_upgrade_filter_content($content)
{
    if (is_admin() || is_user_logged_in()) {
        return $content;
    }

    if (! function_exists('is_page') || ! is_page(HW_ONSALE_PAGE_ID)) {
        return $content;
    }

    if (! function_exists('wc_get_product_ids_on_sale')) {
        return $content;
    }

    $paged = (int) get_query_var('paged');
    if ($paged < 1) {
        $paged = (int) get_query_var('page');
    }
    if ($paged < 1) {
        $paged = 1;
    }

    $fragment_key = hw_onsale_upgrade_fragment_key($paged);
    $cached_html  = get_transient($fragment_key);

    if (false !== $cached_html) {
        hw_onsale_upgrade_maybe_send_header('X-HW-FC: HIT');
        return $cached_html;
    }

    hw_onsale_upgrade_maybe_send_header('X-HW-FC: MISS');

    $product_ids = hw_onsale_upgrade_get_sale_ids();

    if (empty($product_ids)) {
        $html = '<p class="woocommerce-info">' . esc_html__('No sale products', 'woocommerce') . '</p>';
        set_transient($fragment_key, $html, HW_TTL_HTML);
        return $html;
    }

    $total_products = count($product_ids);
    $per_page       = HW_ONSALE_PER_PAGE;
    $total_pages    = (int) ceil($total_products / $per_page);

    if ($paged > $total_pages) {
        $paged = $total_pages > 0 ? $total_pages : 1;
    }

    $offset    = ($paged - 1) * $per_page;
    $paged_ids = array_slice($product_ids, $offset, $per_page);

    $html = hw_onsale_upgrade_render_products($paged_ids, $paged, $total_pages, $total_products);

    if ('' === $html) {
        return $content;
    }

    set_transient($fragment_key, $html, HW_TTL_HTML);

    return $html;
}

/**
 * Build a unique cache key for a paginated fragment.
 *
 * @param int $paged Page number.
 * @return string
 */
function hw_onsale_upgrade_fragment_key($paged)
{
    return 'hw_fc_onsale_html_v1_p' . max(1, (int) $paged);
}

/**
 * Retrieve and cache on sale product IDs.
 *
 * @return int[]
 */
function hw_onsale_upgrade_get_sale_ids()
{
    $cache_key   = 'hw_sale_ids_v1';
    $product_ids = get_transient($cache_key);

    if (false !== $product_ids && is_array($product_ids)) {
        $product_ids = array_map('absint', $product_ids);
        $product_ids = array_filter($product_ids);
        $product_ids = array_values(array_unique($product_ids));
        if (! empty($product_ids)) {
            return $product_ids;
        }
    }

    $product_ids = wc_get_product_ids_on_sale();
    if (! is_array($product_ids)) {
        $product_ids = array();
    }

    $product_ids = array_map('absint', $product_ids);
    $product_ids = array_filter($product_ids);

    if (! empty($product_ids)) {
        sort($product_ids, SORT_NUMERIC);
    }

    set_transient($cache_key, $product_ids, HW_TTL_IDS);

    return $product_ids;
}

/**
 * Render a paginated set of WooCommerce products using standard templates.
 *
 * @param int[] $product_ids Product IDs for current page.
 * @param int   $paged       Current page number.
 * @param int   $total_pages Total pages available.
 * @param int   $total       Total number of products.
 * @return string
 */
function hw_onsale_upgrade_render_products(array $product_ids, $paged, $total_pages, $total)
{
    if (! function_exists('wc_setup_loop')) {
        return '';
    }

    $paged       = max(1, (int) $paged);
    $total_pages = max(1, (int) $total_pages);
    $total       = max(0, (int) $total);

    $loop_args = array(
        'name'           => 'hw_onsale_cached',
        'is_paginated'   => true,
        'total'          => $total,
        'total_products' => $total,
        'per_page'       => HW_ONSALE_PER_PAGE,
        'current_page'   => $paged,
        'page'           => $paged,
        'total_pages'    => $total_pages,
    );

    wc_setup_loop($loop_args);

    ob_start();

    if (! empty($product_ids)) {
        do_action('woocommerce_before_shop_loop');

        if (function_exists('woocommerce_product_loop_start')) {
            woocommerce_product_loop_start();
        }

        global $post, $product;

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (! $product) {
                continue;
            }

            $visible = apply_filters('woocommerce_product_is_visible', $product->is_visible(), $product_id);
            if (! $visible) {
                continue;
            }

            $post_object = get_post($product->get_id());
            if (! ($post_object instanceof WP_Post)) {
                continue;
            }

            $post = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            setup_postdata($post);
            wc_get_template_part('content', 'product');
        }

        wp_reset_postdata();

        if (function_exists('woocommerce_product_loop_end')) {
            woocommerce_product_loop_end();
        }

        do_action('woocommerce_after_shop_loop');
    } else {
        do_action('woocommerce_no_products_found');
    }

    if (function_exists('wc_reset_loop')) {
        wc_reset_loop();
    }

    $html = ob_get_clean();

    return is_string($html) ? $html : '';
}

/**
 * Send a response header when headers are not yet sent.
 *
 * @param string $header Header name and value.
 * @return void
 */
function hw_onsale_upgrade_maybe_send_header($header)
{
    if (! headers_sent()) {
        header($header);
    }
}

/**
 * Optionally dequeue heavy assets for anonymous visitors on the On Sale page.
 *
 * @return void
 */
function hw_onsale_upgrade_maybe_optimize_assets()
{
    if (is_admin() || is_user_logged_in()) {
        return;
    }

    if (! function_exists('is_page') || ! is_page(HW_ONSALE_PAGE_ID)) {
        return;
    }

    $style_handles = array(
        'jquery-ui-css',
        'styler-ajax-product-search-css',
    );

    foreach ($style_handles as $handle) {
        if (wp_style_is($handle, 'enqueued')) {
            wp_dequeue_style($handle);
        }
    }

    $script_handles = array(
        'facebook-pixel',
        'fb-pixel',
    );

    foreach ($script_handles as $handle) {
        if (wp_script_is($handle, 'enqueued')) {
            wp_dequeue_script($handle);
        }
    }
}
