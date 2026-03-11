<?php

/**
 * WCAIG Hash — Hash computation and deduplication utilities.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Hash
{
    private static ?WCAIG_Hash $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Compute a deduplication hash from product ID, attributes, and enabled attributes.
     *
     * Algorithm:
     * 1. Filter attributes to only include keys in enabled_attributes
     * 2. Normalize: lowercase, trim, strip pa_ prefix
     * 3. Sort alphabetically by key
     * 4. Serialize: "{product_id}_{key1=val1&key2=val2}"
     * 5. Return md5()
     *
     * @param int   $product_id         WC product ID.
     * @param array $attributes         Key-value pairs of selected attributes.
     * @param array $enabled_attributes Array of enabled attribute names.
     * @return string MD5 hash (32-char hex).
     */
    public static function compute(int $product_id, array $attributes, array $enabled_attributes): string
    {
        // Normalize enabled attributes list.
        $enabled = array_map(function ($attr) {
            return strtolower(trim(preg_replace('/^pa_/', '', $attr)));
        }, $enabled_attributes);

        // Filter and normalize attributes.
        $filtered = [];
        foreach ($attributes as $key => $value) {
            $normalized_key = strtolower(trim(preg_replace('/^pa_/', '', $key)));
            if (in_array($normalized_key, $enabled, true)) {
                $filtered[ $normalized_key ] = strtolower(trim((string) $value));
            }
        }

        // Sort by key alphabetically.
        ksort($filtered);

        // Build serialization string.
        $parts = [];
        foreach ($filtered as $key => $value) {
            $parts[] = "{$key}={$value}";
        }
        $serialized = $product_id . '_' . implode('&', $parts);

        return md5($serialized);
    }

    /**
     * Get the CPT post slug for a hash.
     *
     * @param string $hash The variation hash.
     * @return string Slug in format "variation_{hash}".
     */
    public static function get_slug(string $hash): string
    {
        return "variation_{$hash}";
    }

    /**
     * Get the attachment title for a hash.
     *
     * @param string $hash The variation hash.
     * @return string Title in format "wcaig_{hash}".
     */
    public static function get_attachment_title(string $hash): string
    {
        return "wcaig_{$hash}";
    }

    /**
     * Get the filename for a generated image.
     *
     * @param string $hash The variation hash.
     * @return string Filename in format "image_{hash}.webp".
     */
    public static function get_filename(string $hash): string
    {
        return "image_{$hash}.webp";
    }

    /**
     * Get attributes stored on a CPT post via ACF fields.
     *
     * @param int $post_id The image_variation post ID.
     * @return array Associative array of attr_name => value.
     */
    public static function get_attributes_from_post(int $post_id): array
    {
        $attributes = [];
        $wc_attrs   = self::get_wc_attribute_names();

        foreach ($wc_attrs as $attr_name) {
            $term = get_field("wcaig_attr_{$attr_name}", $post_id);
            if ($term instanceof WP_Term) {
                $attributes[ $attr_name ] = $term->slug;
            } elseif ($term) {
                $attributes[ $attr_name ] = $term;
            }
        }

        return $attributes;
    }

    /**
     * Get all registered WooCommerce attribute taxonomy names (without pa_ prefix).
     *
     * @return array Array of attribute names.
     */
    public static function get_wc_attribute_names(): array
    {
        if (! function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        $taxonomies = wc_get_attribute_taxonomies();
        $names      = [];

        foreach ($taxonomies as $taxonomy) {
            $names[] = $taxonomy->attribute_name;
        }

        return $names;
    }

    /**
     * Get enabled attribute names for a product by checking which base_attr fields have a term set.
     *
     * @param int $product_id WC product ID.
     * @return array Array of enabled attribute names (without pa_ prefix).
     */
    public static function get_enabled_attributes(int $product_id): array
    {
        $enabled = [];

        if (! function_exists('get_field')) {
            return $enabled;
        }

        foreach (self::get_wc_attribute_names() as $attr_name) {
            $term = get_field("wcaig_base_attr_{$attr_name}", $product_id);
            if ($term instanceof \WP_Term) {
                $enabled[] = $attr_name;
            }
        }

        return $enabled;
    }
}
