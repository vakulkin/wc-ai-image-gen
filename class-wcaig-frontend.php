<?php

/**
 * WCAIG Frontend — conditional asset loading and localized data.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Frontend
{
    private static ?WCAIG_Frontend $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ]);
    }

    /**
     * Conditionally enqueue scripts/styles on product pages.
     */
    public function maybe_enqueue_assets(): void
    {
        if (! is_product()) {
            return;
        }

        global $post;
        $product = wc_get_product($post->ID);

        if (! $product || ! $product->is_type('variable')) {
            return;
        }

        $product_id = $product->get_id();

        // Get base attributes (also determines which attributes are enabled).
        $base_attributes = $this->get_base_attributes($product_id);
        if (empty($base_attributes)) {
            return;
        }

        $enabled_attributes = array_keys($base_attributes);

        // Check base image.
        $base_image_url = $this->get_base_image_url($product_id);

        // Get options.
        $poll_interval    = $this->get_option('wcaig_poll_interval', 10);
        $max_poll_attempts = $this->get_option('wcaig_max_poll_attempts', 0);

        $plugin_url = WCAIG_PLUGIN_URL;

        // Register and enqueue vendor scripts.
        wp_register_script(
            'sweetalert2',
            $plugin_url . 'assets/vendor/sweetalert2.min.js',
            [],
            '11.0.0',
            true
        );

        wp_register_style(
            'sweetalert2',
            $plugin_url . 'assets/vendor/sweetalert2.min.css',
            [],
            '11.0.0'
        );

        wp_register_script(
            'wcaig-md5',
            $plugin_url . 'assets/vendor/md5.min.js',
            [],
            '2.19.0',
            true
        );

        // Register and enqueue frontend script.
        wp_register_script(
            'wcaig-frontend',
            $plugin_url . 'assets/js/wcaig-frontend.js',
            [ 'jquery', 'sweetalert2', 'wcaig-md5' ],
            WCAIG_VERSION,
            true
        );

        wp_register_style(
            'wcaig-frontend',
            $plugin_url . 'assets/css/wcaig-frontend.css',
            [ 'sweetalert2' ],
            WCAIG_VERSION
        );

        // Localize params.
        wp_localize_script('wcaig-frontend', 'wcaig_params', [
            'rest_url'           => rest_url('wcaig/v1/'),
            'product_id'         => $product_id,
            'poll_interval'      => (int) $poll_interval,
            'max_poll_attempts'  => (int) $max_poll_attempts,
            'enabled_attributes' => array_values($enabled_attributes),
            'base_attributes'    => $base_attributes,
            'base_image_url'     => $base_image_url,
            'i18n'               => [
                'loading'     => __('Generating your image...', 'wc-ai-image-gen'),
                'error'       => __('Image generation failed', 'wc-ai-image-gen'),
                'retry'       => __('Please try again', 'wc-ai-image-gen'),
                'cap_reached' => __('Image generation limit reached', 'wc-ai-image-gen'),
            ],
        ]);

        wp_enqueue_script('sweetalert2');
        wp_enqueue_style('sweetalert2');
        wp_enqueue_script('wcaig-md5');
        wp_enqueue_script('wcaig-frontend');
        wp_enqueue_style('wcaig-frontend');
    }

    /**
     * Get base image URL for a product.
     */
    private function get_base_image_url(int $product_id): string
    {
        if (function_exists('get_field')) {
            $base_image = get_field('wcaig_base_image', $product_id);
            if (is_array($base_image) && ! empty($base_image['url'])) {
                return $base_image['url'];
            }
            if (is_string($base_image) && ! empty($base_image)) {
                return $base_image;
            }
        }

        // Fallback to product featured image.
        $thumb_id = get_post_thumbnail_id($product_id);
        if ($thumb_id) {
            return (string) wp_get_attachment_url($thumb_id);
        }

        return '';
    }

    /**
     * Get base attribute values for a product.
     *
     * Iterates all registered WC attributes and returns only those
     * that have a base term selected — effectively deriving "enabled" attributes.
     */
    private function get_base_attributes(int $product_id): array
    {
        $base = [];
        if (function_exists('get_field') && function_exists('wc_get_attribute_taxonomies')) {
            foreach (wc_get_attribute_taxonomies() as $attr) {
                $key  = $attr->attribute_name;
                $term = get_field("wcaig_base_attr_{$key}", $product_id);
                if ($term instanceof WP_Term) {
                    $base[ $key ] = $term->slug;
                }
            }
        }
        return $base;
    }

    /**
     * Get a plugin option from ACF.
     */
    private function get_option(string $field_name, $default = null)
    {
        if (function_exists('get_field')) {
            $value = get_field($field_name, 'option');
            if (null !== $value && '' !== $value) {
                return $value;
            }
        }
        return $default;
    }
}
