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
    define('HW_ONSALE_PER_PAGE', 24);
}

if (! defined('HW_ONSALE_TTL_IDS')) {
    define('HW_ONSALE_TTL_IDS', 600);
}

if (! defined('HW_ONSALE_TTL_HTML')) {
    define('HW_ONSALE_TTL_HTML', 120);
}

add_filter('the_content', 'hw_onsale_upgrade_filter_content', 20);
add_action('wp_enqueue_scripts', 'hw_onsale_upgrade_maybe_optimize_assets', 100);

/**
 * Replaces the On Sale page content with cached WooCommerce product output.
 *
 * @param string $content Original post content.
 * @return string Filtered content.
 */
function hw_onsale_upgrade_filter_content($content)
{
    if (is_admin() || is_user_logged_in()) {
        return $content;
    }

    if (! function_exists('is_page') || ! function_exists('wc_get_product_ids_on_sale')) {
        return $content;
    }

    if (! is_page(HW_ONSALE_PAGE_ID)) {
        return $content;
    }

    $paged = get_query_var('paged');
    $paged = $paged ? (int) $paged : 1;
    if ($paged < 1) {
        $paged = 1;
    }

    $fragment_key = 'hw_fc_onsale_html_v1_p' . $paged;
    $cached_html = get_transient($fragment_key);
    if (false !== $cached_html) {
        hw_onsale_upgrade_maybe_send_header('X-HW-FC: HIT');
        return $cached_html;
    }

    hw_onsale_upgrade_maybe_send_header('X-HW-FC: MISS');

    $ids_key = 'hw_sale_ids_v1';
    $product_ids = get_transient($ids_key);
    if (false === $product_ids) {
        $product_ids = wc_get_product_ids_on_sale();
        if (! is_array($product_ids)) {
            $product_ids = array();
        }
        set_transient($ids_key, $product_ids, HW_ONSALE_TTL_IDS);
    }

    if (empty($product_ids)) {
        $html = '<p class="woocommerce-info">' . esc_html__('No sale products', 'woocommerce') . '</p>';
        set_transient($fragment_key, $html, HW_ONSALE_TTL_HTML);
        return $html;
    }

    $per_page = HW_ONSALE_PER_PAGE;
    $total_products = count($product_ids);
    $total_pages = (int) ceil($total_products / $per_page);
    if ($paged > $total_pages) {
        $paged = $total_pages;
    }
    if ($paged < 1) {
        $paged = 1;
    }

    $offset = ($paged - 1) * $per_page;
    $paged_ids = array_slice($product_ids, $offset, $per_page);

    $html = hw_onsale_upgrade_render_products($paged_ids, $paged, $total_pages, $total_products);

    if ('' !== $html) {
        set_transient($fragment_key, $html, HW_ONSALE_TTL_HTML);
    }

    return $html ? $html : $content;
}

/**
 * Render WooCommerce products using the standard template parts.
 *
 * @param array $product_ids IDs to render.
 * @param int   $paged       Current page number.
 * @param int   $total_pages Total number of pages.
 * @param int   $total       Total number of products.
 * @return string
 */
function hw_onsale_upgrade_render_products(array $product_ids, $paged, $total_pages, $total)
{
    if (! function_exists('wc_get_product')) {
        return '';
    }

    $loop_args = array(
        'name'         => 'hw_onsale_cached',
        'is_paginated' => true,
        'total'        => $total,
        'per_page'     => HW_ONSALE_PER_PAGE,
        'current_page' => $paged,
        'total_pages'  => $total_pages,
    );

    if (function_exists('wc_setup_loop')) {
        wc_setup_loop($loop_args);
    } elseif (function_exists('wc_set_loop_prop')) {
        foreach ($loop_args as $prop => $value) {
            wc_set_loop_prop($prop, $value);
        }
    }

    ob_start();

    /**
     * Mimic the default WooCommerce shop loop wrapper actions so that themes/plugins
     * hooking into these events (e.g. result count, sorting, pagination) still run.
     */
    do_action('woocommerce_before_shop_loop');

    if (! empty($product_ids)) {
        if (function_exists('woocommerce_product_loop_start')) {
            woocommerce_product_loop_start();
        }

        global $post, $product;

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (! $product) {
                continue;
            }

            $post_object = get_post($product_id);
            if (! $post_object instanceof WP_Post) {
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
        /**
         * Match the behaviour of WooCommerce when no products are found.
         */
        do_action('woocommerce_no_products_found');
    }

    if (function_exists('wc_reset_loop')) {
        wc_reset_loop();
    }

    $html = ob_get_clean();

    if ('' === trim($html)) {
        return '';
    }

    if (false !== strpos($html, 'woocommerce-pagination')) {
        return $html;
    }

    $pagination_html = '';

    if ($total_pages > 1) {
        if (function_exists('woocommerce_pagination')) {
            ob_start();
            woocommerce_pagination(
                array(
                    'total'   => max(1, (int) $total_pages),
                    'current' => max(1, (int) $paged),
                )
            );
            $pagination_html = ob_get_clean();
        } else {
            $pagination_html = paginate_links(
                array(
                    'base'      => esc_url_raw(str_replace(999999999, '%#%', get_pagenum_link(999999999))),
                    'format'    => '?paged=%#%',
                    'current'   => max(1, (int) $paged),
                    'total'     => max(1, (int) $total_pages),
                    'prev_text' => '&larr;',
                    'next_text' => '&rarr;',
                    'type'      => 'plain',
                )
            );

            if ($pagination_html) {
                $pagination_html = '<nav class="woocommerce-pagination">' . $pagination_html . '</nav>';
            }
        }
    }

    return $html . $pagination_html;
}

/**
 * Send a response header when possible.
 *
 * @param string $header Header string.
 * @return void
 */
function hw_onsale_upgrade_maybe_send_header($header)
{
    if (! headers_sent()) {
        header($header);
    }
}

/**
 * Optionally dequeue heavy assets when viewing the On Sale page.
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
