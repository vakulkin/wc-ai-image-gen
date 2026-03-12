<?php

/**
 * WCAIG Hash — Hash computation, attachment lookup, and shared product utilities.
 *
 * All methods are static — no instance needed.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Hash
{
    // ──────────────────────────────────────────────
    // Hash computation
    // ──────────────────────────────────────────────

    /**
     * Compute a deduplication hash from product ID, attributes, and enabled attributes.
     *
     * @param int   $product_id         WC product ID.
     * @param array $attributes         Key-value pairs of selected attributes.
     * @param array $enabled_attributes Array of enabled attribute names.
     * @return string MD5 hash (32-char hex).
     */
    public static function compute(int $product_id, array $attributes, array $enabled_attributes): string
    {
        $enabled = array_map(fn($attr) => strtolower(trim(preg_replace('/^pa_/', '', $attr))), $enabled_attributes);

        $filtered = [];
        foreach ($attributes as $key => $value) {
            $normalized_key = strtolower(trim(preg_replace('/^pa_/', '', $key)));
            if (in_array($normalized_key, $enabled, true)) {
                $filtered[$normalized_key] = strtolower(trim((string) $value));
            }
        }

        ksort($filtered);

        $parts = [];
        foreach ($filtered as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return md5($product_id . '_' . implode('&', $parts));
    }

    /**
     * Get the attachment title for a hash.
     */
    public static function get_attachment_title(string $hash): string
    {
        return "wcaig_{$hash}";
    }

    /**
     * Get the filename for a generated image.
     */
    public static function get_filename(string $hash): string
    {
        return "image_{$hash}.webp";
    }

    // ──────────────────────────────────────────────
    // Attachment lookup (centralised — used by REST API, Worker, Webhook, GC)
    // ──────────────────────────────────────────────

    /**
     * Find a WCAIG attachment by its hash.
     *
     * @param string $hash  Variation hash.
     * @param string $status Optional: filter by wcaig_status ('pending'|'published'). Empty = any.
     * @return WP_Post|null
     */
    public static function find_attachment(string $hash, string $status = ''): ?WP_Post
    {
        $meta_query = [
            [
                'key'   => '_wcaig_hash',
                'value' => $hash,
            ],
        ];

        if ($status !== '') {
            $meta_query[] = [
                'key'   => 'wcaig_status',
                'value' => $status,
            ];
        }

        $posts = get_posts([
            'post_type'      => 'attachment',
            // 'post_status'    => 'inherit',
            'meta_query'     => $meta_query,
            'posts_per_page' => 1,
        ]);

        error_log("find_attachment: hash={$hash}, status={$status}, found=" . count($posts));

        return ! empty($posts) ? $posts[0] : null;
    }

    /**
     * Count all WCAIG attachments (for cap enforcement).
     */
    public static function count_attachments(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_wcaig_hash'"
        );
    }

    /**
     * Check if the global image cap has been reached.
     */
    public static function is_cap_reached(): bool
    {
        $cap = (int) self::get_option('wcaig_global_image_cap', 1000);
        if ($cap <= 0) {
            return false;
        }
        return self::count_attachments() >= $cap;
    }

    // ──────────────────────────────────────────────
    // Product / attribute helpers
    // ──────────────────────────────────────────────

    /**
     * Get all registered WooCommerce attribute taxonomy names (without pa_ prefix).
     */
    public static function get_wc_attribute_names(): array
    {
        if (! function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        return array_map(fn($t) => $t->attribute_name, wc_get_attribute_taxonomies());
    }

    /**
     * Get enabled attribute names for a product (those with a base_attr term set).
     *
     * @param int $product_id WC product ID.
     * @return array Attribute names (without pa_ prefix).
     */
    public static function get_enabled_attributes(int $product_id): array
    {
        if (! function_exists('get_field')) {
            return [];
        }

        $enabled = [];
        foreach (self::get_wc_attribute_names() as $attr_name) {
            $term = get_field("wcaig_base_attr_{$attr_name}", $product_id);
            if ($term instanceof \WP_Term) {
                $enabled[] = $attr_name;
            }
        }
        return $enabled;
    }

    /**
     * Get the base image URL for a product (ACF field → featured image fallback).
     *
     * @param int $product_id WC product ID.
     * @return string Image URL or empty string.
     */
    public static function get_base_image_url(int $product_id): string
    {
        if (function_exists('get_field')) {
            $base_image = get_field('wcaig_base_image', $product_id);
            if ($base_image) {
                if (is_numeric($base_image)) {
                    $url = wp_get_attachment_url($base_image);
                    if ($url) {
                        return $url;
                    }
                }
                if (is_array($base_image) && ! empty($base_image['url'])) {
                    return $base_image['url'];
                }
                if (is_string($base_image) && ! empty($base_image)) {
                    return $base_image;
                }
            }
        }

        // Fallback: product featured image.
        $thumb_id = get_post_thumbnail_id($product_id);
        if ($thumb_id) {
            return wp_get_attachment_url($thumb_id) ?: '';
        }

        return '';
    }

    /**
     * Check if selected attributes match the base image attributes.
     *
     * @param int   $product_id Product ID.
     * @param array $attributes Selected attributes.
     * @param array $enabled    Enabled attribute names.
     * @return bool
     */
    public static function is_base_match(int $product_id, array $attributes, array $enabled): bool
    {
        foreach ($enabled as $attr_name) {
            $base_term = get_field("wcaig_base_attr_{$attr_name}", $product_id);
            if (! ($base_term instanceof \WP_Term)) {
                return false;
            }

            $selected_value = null;
            foreach ($attributes as $key => $val) {
                $normalized_key = strtolower(trim(preg_replace('/^pa_/', '', $key)));
                if ($normalized_key === $attr_name) {
                    $selected_value = $val;
                    break;
                }
            }

            if ($selected_value === null || $base_term->slug !== strtolower(trim((string) $selected_value))) {
                return false;
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────
    // Shared option reader
    // ──────────────────────────────────────────────

    /**
     * Read an ACF option field with a default fallback.
     *
     * @param string $field   ACF field name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get_option(string $field, mixed $default = ''): mixed
    {
        if (function_exists('get_field')) {
            $value = get_field($field, 'option');
            if ($value !== null && $value !== '' && $value !== false) {
                return $value;
            }
        }
        return $default;
    }
}
