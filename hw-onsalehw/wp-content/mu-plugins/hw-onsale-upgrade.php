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
    if (! hw_onsale_upgrade_is_target_request()) {
        return $content;
    }

    $paged = hw_onsale_upgrade_get_paged();

    $fragment_key = 'hw_fc_onsale_html_v1_p' . $paged;

    $cached_html = hw_onsale_upgrade_cache_get($fragment_key, HW_ONSALE_TTL_HTML);
    if (false !== $cached_html) {
        hw_onsale_upgrade_maybe_send_header('X-HW-FC: HIT');
        return $cached_html;
    }

    hw_onsale_upgrade_maybe_send_header('X-HW-FC: MISS');

    $product_ids = hw_onsale_upgrade_get_product_ids();

    if (empty($product_ids)) {
        $html = '<p class="woocommerce-info">' . esc_html__('No sale products', 'woocommerce') . '</p>';
        hw_onsale_upgrade_cache_set($fragment_key, $html, HW_ONSALE_TTL_HTML);
        return $html;
    }

    $per_page = HW_ONSALE_PER_PAGE;
    $total_products = count($product_ids);
    $total_pages = (int) ceil($total_products / $per_page);
    if ($total_pages < 1) {
        $total_pages = 1;
    }

    if ($paged > $total_pages) {
        $paged = $total_pages;
    }

    $offset = ($paged - 1) * $per_page;
    $paged_ids = array_slice($product_ids, $offset, $per_page);

    $html = hw_onsale_upgrade_render_products($paged_ids, $paged, $total_pages, $total_products);

    if ('' !== $html) {
        hw_onsale_upgrade_cache_set($fragment_key, $html, HW_ONSALE_TTL_HTML);
        return $html;
    }

    return $content;
}

/**
 * Determine if the current request should be handled by the fragment cache.
 *
 * @return bool
 */
function hw_onsale_upgrade_is_target_request()
{
    if (is_admin() || is_user_logged_in()) {
        return false;
    }

    if (! function_exists('is_page') || ! function_exists('wc_get_product_ids_on_sale')) {
        return false;
    }

    return is_page(HW_ONSALE_PAGE_ID);
}

/**
 * Retrieve the current paged value accounting for static pages.
 *
 * @return int
 */
function hw_onsale_upgrade_get_paged()
{
    $paged = (int) get_query_var('paged');
    if ($paged < 1) {
        $paged = (int) get_query_var('page');
    }

    return max(1, $paged);
}

/**
 * Retrieve product IDs currently on sale with caching.
 *
 * @return array
 */
function hw_onsale_upgrade_get_product_ids()
{
    $ids_key = 'hw_sale_ids_v1';

    $ids = hw_onsale_upgrade_cache_get($ids_key, HW_ONSALE_TTL_IDS);
    if (false !== $ids && is_array($ids)) {
        return array_values(array_map('absint', $ids));
    }

    // Allow interoperability with other MU plugins that may precompute IDs.
    $precomputed = get_transient('hw_onsale_ids');
    if (is_array($precomputed) && ! empty($precomputed['ids'])) {
        $ids = $precomputed['ids'];
    } else {
        $ids = wc_get_product_ids_on_sale();
    }

    if (! is_array($ids)) {
        $ids = array();
    }

    $ids = array_values(array_unique(array_map('absint', $ids)));

    hw_onsale_upgrade_cache_set($ids_key, $ids, HW_ONSALE_TTL_IDS);

    return $ids;
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

    hw_onsale_upgrade_prime_product_data($product_ids);

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

        $posts = hw_onsale_upgrade_fetch_posts($product_ids);

        global $post, $product;

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (! $product) {
                continue;
            }

            $post_object = isset($posts[$product_id]) ? $posts[$product_id] : get_post($product_id);
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
 * Prime post, meta, and term caches to minimize database queries per render.
 *
 * @param array $product_ids Product IDs to prepare.
 * @return void
 */
function hw_onsale_upgrade_prime_product_data(array $product_ids)
{
    $product_ids = array_values(array_unique(array_map('absint', $product_ids)));
    if (empty($product_ids)) {
        return;
    }

    update_meta_cache('post', $product_ids);
    update_object_term_cache($product_ids, 'product');

    if (class_exists('WC_Data_Store')) {
        WC_Data_Store::load('product');
    }

    if (function_exists('wc_get_products')) {
        wc_get_products(
            array(
                'include' => $product_ids,
                'limit'   => count($product_ids),
                'orderby' => 'post__in',
            )
        );
    }
}

/**
 * Retrieve WP_Post instances for the provided product IDs keyed by ID.
 *
 * @param array $product_ids Product IDs.
 * @return array<int, WP_Post>
 */
function hw_onsale_upgrade_fetch_posts(array $product_ids)
{
    $product_ids = array_values(array_unique(array_map('absint', $product_ids)));
    if (empty($product_ids)) {
        return array();
    }

    $posts = array();

    if (! class_exists('WP_Query')) {
        return $posts;
    }

    $query = new WP_Query(
        array(
            'post_type'              => 'product',
            'post__in'               => $product_ids,
            'orderby'                => 'post__in',
            'posts_per_page'         => count($product_ids),
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
            'post_status'            => array('publish'),
        )
    );

    if ($query->have_posts()) {
        foreach ($query->posts as $wp_post) {
            if ($wp_post instanceof WP_Post) {
                $posts[$wp_post->ID] = $wp_post;
            }
        }
    }

    wp_reset_postdata();

    return $posts;
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
 * Store a value in both the object cache and transient system.
 *
 * @param string $key Cache key.
 * @param mixed  $value Value to store.
 * @param int    $ttl  Time to live in seconds.
 * @return void
 */
function hw_onsale_upgrade_cache_set($key, $value, $ttl)
{
    if (function_exists('wp_cache_set')) {
        wp_cache_set($key, $value, 'hw_onsale_upgrade', $ttl);
    }

    set_transient($key, $value, $ttl);
}

/**
 * Retrieve a cached value checking the object cache first.
 *
 * @param string $key Cache key.
 * @param int    $ttl Expected TTL (unused here but kept for parity).
 * @return mixed
 */
function hw_onsale_upgrade_cache_get($key, $ttl)
{
    if (function_exists('wp_cache_get')) {
        $cached = wp_cache_get($key, 'hw_onsale_upgrade');
        if (false !== $cached) {
            return $cached;
        }
    }

    return get_transient($key);
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
