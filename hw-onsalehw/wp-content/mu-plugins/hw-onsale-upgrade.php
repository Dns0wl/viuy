<?php
/**
 * Plugin Name: HW On Sale Upgrade
 * Description: Optimizes the WooCommerce On Sale page, repairs corrupt product meta and primes caching for /onsale.
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

// Meta keys that frequently surface unserialize warnings on legacy data.
if (! defined('HW_ONSALE_CORRUPT_META_KEYS')) {
    define('HW_ONSALE_CORRUPT_META_KEYS', json_encode(array(
        '_product_attributes',
        '_product_image_gallery',
    )));
}

add_filter('the_content', 'hw_onsale_upgrade_filter_content', 20);
add_action('wp_enqueue_scripts', 'hw_onsale_upgrade_maybe_optimize_assets', 100);
add_action('init', 'hw_onsale_register_cli_commands');
add_filter('get_post_metadata', 'hw_onsale_repair_corrupt_meta', 5, 4);

/**
 * Safely read metadata without leaking unserialize notices.
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $default  Default fallback when empty.
 * @return mixed
 */
function hw_safe_meta($post_id, $meta_key, $default = null)
{
    $post_id  = absint($post_id);
    $meta_key = (string) $meta_key;

    if ($post_id <= 0 || '' === $meta_key) {
        return $default;
    }

    if (! function_exists('get_metadata_raw')) {
        $value = get_post_meta($post_id, $meta_key, true);
        return (null === $value || '' === $value) ? $default : $value;
    }

    $raw = get_metadata_raw('post', $post_id, $meta_key, true);

    if (null === $raw) {
        return $default;
    }

    if (! is_string($raw) || '' === $raw) {
        return $raw;
    }

    $fixed = hw_onsale_safe_unserialize($raw, $meta_key, $post_id);

    if (null === $fixed) {
        return $default;
    }

    return $fixed;
}

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

    $paged = hw_onsale_current_page();

    $fragment_key = hw_onsale_fragment_key($paged);
    $cached_html  = get_transient($fragment_key);

    if (false !== $cached_html) {
        hw_onsale_maybe_send_header('X-HW-FC: HIT');
        return $cached_html;
    }

    hw_onsale_maybe_send_header('X-HW-FC: MISS');

    $product_ids = hw_onsale_get_cached_sale_ids();

    if (empty($product_ids)) {
        $html = '<p class="woocommerce-info">' . esc_html__('No sale products', 'woocommerce') . '</p>';
        set_transient($fragment_key, $html, HW_TTL_HTML);
        return $html;
    }

    $total_products = count($product_ids);
    $total_pages    = (int) ceil($total_products / HW_ONSALE_PER_PAGE);

    if ($paged > $total_pages) {
        $paged = $total_pages > 0 ? $total_pages : 1;
    }

    $offset    = ($paged - 1) * HW_ONSALE_PER_PAGE;
    $paged_ids = array_slice($product_ids, $offset, HW_ONSALE_PER_PAGE);

    $products = hw_onsale_prime_products($paged_ids);
    $html     = hw_onsale_render_products($products, $paged, $total_pages, $total_products);

    if ('' === $html) {
        return $content;
    }

    set_transient($fragment_key, $html, HW_TTL_HTML);

    return $html;
}

/**
 * Compute the current pagination page.
 *
 * @return int
 */
function hw_onsale_current_page()
{
    $paged = (int) get_query_var('paged');
    if ($paged < 1) {
        $paged = (int) get_query_var('page');
    }

    if ($paged < 1) {
        $paged = 1;
    }

    return $paged;
}

/**
 * Build a unique fragment cache key.
 *
 * @param int $paged Page number.
 * @return string
 */
function hw_onsale_fragment_key($paged)
{
    return 'hw_fc_onsale_html_v2_p' . max(1, (int) $paged);
}

/**
 * Retrieve cached sale IDs, warming the transient when necessary.
 *
 * @return int[]
 */
function hw_onsale_get_cached_sale_ids()
{
    $cache_key = 'hw_sale_ids_v2';
    $cached    = get_transient($cache_key);

    if (is_array($cached) && ! empty($cached)) {
        return array_values(array_unique(array_map('absint', $cached)));
    }

    $ids = wc_get_product_ids_on_sale();

    if (! is_array($ids)) {
        $ids = array();
    }

    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    sort($ids, SORT_NUMERIC);

    set_transient($cache_key, $ids, HW_TTL_IDS);

    return $ids;
}

/**
 * Prime WooCommerce products for the supplied IDs without redundant queries.
 *
 * @param int[] $product_ids Product IDs.
 * @return WC_Product[]
 */
function hw_onsale_prime_products(array $product_ids)
{
    $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));

    if (empty($product_ids)) {
        return array();
    }

    if (function_exists('wc_get_products')) {
        $query = wc_get_products(
            array(
                'limit'        => count($product_ids),
                'return'       => 'objects',
                'include'      => $product_ids,
                'orderby'      => 'post__in',
                'paginate'     => false,
                'status'       => 'publish',
                'suppress_filters' => true,
            )
        );

        if (is_array($query) && count($query) === count($product_ids)) {
            $products = array();
            foreach ($product_ids as $id) {
                foreach ($query as $object) {
                    if ($object instanceof WC_Product && (int) $object->get_id() === $id) {
                        $products[] = $object;
                        break;
                    }
                }
            }
            if (! empty($products)) {
                return $products;
            }
        }
    }

    $products = array();
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if ($product instanceof WC_Product) {
            $products[] = $product;
        }
    }

    return $products;
}

/**
 * Render WooCommerce products with standard templates and pagination context.
 *
 * @param WC_Product[] $products      Products to display.
 * @param int          $paged         Current page.
 * @param int          $total_pages   Total pages.
 * @param int          $total_products Total count of sale products.
 * @return string
 */
function hw_onsale_render_products(array $products, $paged, $total_pages, $total_products)
{
    if (! function_exists('wc_setup_loop')) {
        return '';
    }

    $paged         = max(1, (int) $paged);
    $total_pages   = max(1, (int) $total_pages);
    $total_products = max(0, (int) $total_products);

    $loop_args = array(
        'name'           => 'hw_onsale_cached',
        'is_paginated'   => true,
        'total'          => $total_products,
        'total_products' => $total_products,
        'per_page'       => HW_ONSALE_PER_PAGE,
        'current_page'   => $paged,
        'page'           => $paged,
        'total_pages'    => $total_pages,
    );

    wc_setup_loop($loop_args);

    ob_start();

    if (! empty($products)) {
        do_action('woocommerce_before_shop_loop');

        if (function_exists('woocommerce_product_loop_start')) {
            woocommerce_product_loop_start();
        }

        global $post, $product;

        foreach ($products as $product_object) {
            if (! $product_object instanceof WC_Product) {
                continue;
            }

            $product_id = $product_object->get_id();

            $visible = apply_filters('woocommerce_product_is_visible', $product_object->is_visible(), $product_id);
            if (! $visible) {
                continue;
            }

            $post_object = get_post($product_id);
            if (! ($post_object instanceof WP_Post)) {
                continue;
            }

            $product = $product_object;
            $post    = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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
 * Attempt to repair corrupted serialized payloads when WooCommerce requests them.
 *
 * @param mixed  $value     Cached value (short-circuited when not null).
 * @param int    $object_id Post ID.
 * @param string $meta_key  Meta key.
 * @param bool   $single    Single flag.
 * @return mixed
 */
function hw_onsale_repair_corrupt_meta($value, $object_id, $meta_key, $single)
{
    $suspect_keys = json_decode(HW_ONSALE_CORRUPT_META_KEYS, true);

    if (! in_array($meta_key, $suspect_keys, true)) {
        return $value;
    }

    if (null !== $value) {
        return $value;
    }

    if (! function_exists('get_metadata_raw')) {
        return $value;
    }

    remove_filter('get_post_metadata', 'hw_onsale_repair_corrupt_meta', 5);

    $raw = get_metadata_raw('post', $object_id, $meta_key, $single);

    add_filter('get_post_metadata', 'hw_onsale_repair_corrupt_meta', 5, 4);

    if (null === $raw || '' === $raw) {
        return $value;
    }

    if (is_string($raw)) {
        $clean = hw_onsale_safe_unserialize($raw, $meta_key, $object_id);
        if (null !== $clean) {
            return $clean;
        }
    }

    return $value;
}

/**
 * Unserialize defensively and repair truncated payloads.
 *
 * @param string $raw      Raw serialized value.
 * @param string $meta_key Meta key.
 * @param int    $post_id  Post ID.
 * @return mixed|null
 */
function hw_onsale_safe_unserialize($raw, $meta_key, $post_id)
{
    $raw = trim((string) $raw);

    if ('' === $raw) {
        return null;
    }

    if (! is_serialized($raw)) {
        return $raw;
    }

    try {
        $value = @unserialize($raw); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        if (false === $value && 'b:0;' !== $raw) {
            $value = hw_onsale_attempt_repair_serialized($raw);
        }
    } catch (Throwable $exception) {
        $value = hw_onsale_attempt_repair_serialized($raw);
    }

    if (false === $value && 'b:0;' !== $raw) {
        $value = null;
    }

    if (null !== $value) {
        hw_onsale_schedule_meta_fix($post_id, $meta_key, $value);
    }

    return $value;
}

/**
 * Attempt to repair serialized content by normalizing length attributes.
 *
 * @param string $raw Raw serialized content.
 * @return mixed|false
 */
function hw_onsale_attempt_repair_serialized($raw)
{
    if (! preg_match_all('/s:(\d+):"(.*?)";/', $raw, $matches, PREG_SET_ORDER)) {
        return false;
    }

    $repaired = $raw;

    foreach ($matches as $match) {
        $string = $match[2];
        $actual = strlen($string);
        if ((int) $match[1] !== $actual) {
            $repaired = str_replace($match[0], 's:' . $actual . ':"' . $string . '";', $repaired);
        }
    }

    return @unserialize($repaired); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
}

/**
 * Persist repaired meta in the background using a shutdown hook.
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $value    Clean value.
 * @return void
 */
function hw_onsale_schedule_meta_fix($post_id, $meta_key, $value)
{
    $post_id  = absint($post_id);
    $meta_key = (string) $meta_key;

    if ($post_id <= 0 || '' === $meta_key) {
        return;
    }

    static $queue = array();

    $queue_key = $post_id . '|' . $meta_key;
    $queue[$queue_key] = array(
        'post_id'  => $post_id,
        'meta_key' => $meta_key,
        'value'    => $value,
    );

    if (1 === count($queue)) {
        add_action(
            'shutdown',
            static function () use (&$queue) {
                foreach ($queue as $item) {
                    if (! isset($item['post_id'], $item['meta_key'])) {
                        continue;
                    }

                    update_post_meta($item['post_id'], $item['meta_key'], $item['value']);
                }
            },
            0
        );
    }
}

/**
 * Send a response header when headers are not yet sent.
 *
 * @param string $header Header name and value.
 * @return void
 */
function hw_onsale_maybe_send_header($header)
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

/**
 * Register WP-CLI commands for repairing corrupt sale product metadata.
 *
 * @return void
 */
function hw_onsale_register_cli_commands()
{
    if (! defined('WP_CLI') || ! WP_CLI) {
        return;
    }

    WP_CLI::add_command('hw-onsale repair-meta', 'hw_onsale_cli_repair_meta');
}

/**
 * WP-CLI handler to repair corrupt metadata for the supplied keys.
 *
 * ## OPTIONS
 *
 * [--meta_key=<key>]
 * : Meta key to scan (defaults to _product_attributes).
 *
 * [--dry-run]
 * : Only report affected posts without updating values.
 *
 * ## EXAMPLES
 *
 *     wp hw-onsale repair-meta --meta_key=_product_attributes
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative args.
 * @return void
 */
function hw_onsale_cli_repair_meta($args, $assoc_args)
{
    $meta_key = isset($assoc_args['meta_key']) ? (string) $assoc_args['meta_key'] : '_product_attributes';
    $dry_run  = isset($assoc_args['dry-run']);

    $posts = get_posts(
        array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'suppress_filters' => true,
        )
    );

    if (empty($posts)) {
        WP_CLI::success('No products found.');
        return;
    }

    $affected = 0;

    foreach ($posts as $post_id) {
        $raw = get_post_meta($post_id, $meta_key, true);

        if ('' === $raw || null === $raw) {
            continue;
        }

        if (is_string($raw) && is_serialized($raw)) {
            $clean = hw_onsale_safe_unserialize($raw, $meta_key, $post_id);

            if (null === $clean) {
                continue;
            }

            $affected++;

            if ($dry_run) {
                WP_CLI::log(sprintf('Would repair %s for product #%d', $meta_key, $post_id));
                continue;
            }

            update_post_meta($post_id, $meta_key, $clean);
        }
    }

    if ($affected > 0) {
        if ($dry_run) {
            WP_CLI::success(sprintf('Identified %d products requiring fixes.', $affected));
        } else {
            WP_CLI::success(sprintf('Repaired metadata for %d products.', $affected));
        }
    } else {
        WP_CLI::success('No corrupt metadata detected.');
    }
}
