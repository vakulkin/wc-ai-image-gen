<?php

/**
 * WCAIG ACF — ACF PRO field group and options page registration.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_ACF
{
    private static ?WCAIG_ACF $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('acf/init', [ $this, 'register_options_page' ]);
        add_action('acf/init', [ $this, 'register_field_groups' ]);

        // AJAX handlers.
        add_action('wp_ajax_wcaig_run_worker', [ $this, 'ajax_run_worker' ]);
        add_action('wp_ajax_wcaig_run_gc', [ $this, 'ajax_run_gc' ]);
        add_action('wp_ajax_wcaig_purge_all', [ $this, 'ajax_purge_all' ]);
        add_action('wp_ajax_wcaig_regenerate', [ $this, 'ajax_regenerate' ]);
    }

    /**
     * Register the ACF options page under WooCommerce.
     */
    public function register_options_page(): void
    {
        if (! function_exists('acf_add_options_page')) {
            return;
        }

        acf_add_options_page([
            'page_title'  => __('AI Image Gen Settings', 'wc-ai-image-gen'),
            'menu_title'  => __('AI Image Gen', 'wc-ai-image-gen'),
            'menu_slug'   => 'wcaig-settings',
            'parent_slug' => 'woocommerce',
            'capability'  => 'manage_woocommerce',
        ]);
    }

    /**
     * Register all ACF field groups.
     */
    public function register_field_groups(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        $this->register_options_fields();
        $this->register_product_fields();
        $this->register_variation_cpt_fields();
        $this->register_term_fields();
    }

    /**
     * Register options page fields.
     */
    private function register_options_fields(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_wcaig_options',
            'title'    => 'WCAIG Settings',
            'fields'   => [
                // API Tab
                [
                    'key'   => 'field_wcaig_api_key',
                    'label' => 'API Key',
                    'name'  => 'wcaig_api_key',
                    'type'  => 'text',
                    'instructions' => 'PIAPI API key. Can be overridden via WCAIG_API_KEY PHP constant.',
                ],
                [
                    'key'           => 'field_wcaig_model',
                    'label'         => 'Model',
                    'name'          => 'wcaig_model',
                    'type'          => 'text',
                    'default_value' => 'gemini',
                ],
                [
                    'key'           => 'field_wcaig_task_type',
                    'label'         => 'Task Type',
                    'name'          => 'wcaig_task_type',
                    'type'          => 'text',
                    'default_value' => 'gemini-2.5-flash-image',
                ],
                [
                    'key'   => 'field_wcaig_webhook_secret',
                    'label' => 'Webhook Secret',
                    'name'  => 'wcaig_webhook_secret',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_wcaig_webhook_url_override',
                    'label' => 'Webhook URL Override',
                    'name'  => 'wcaig_webhook_url_override',
                    'type'  => 'url',
                    'instructions' => 'Optional. If set, this URL is sent to PIAPI instead of auto-generated.',
                ],
                // Worker Tab
                [
                    'key'           => 'field_wcaig_poll_interval',
                    'label'         => 'Poll Interval (seconds)',
                    'name'          => 'wcaig_poll_interval',
                    'type'          => 'number',
                    'default_value' => 10,
                    'min'           => 3,
                    'max'           => 120,
                ],
                [
                    'key'           => 'field_wcaig_max_poll_attempts',
                    'label'         => 'Max Poll Attempts',
                    'name'          => 'wcaig_max_poll_attempts',
                    'type'          => 'number',
                    'default_value' => 0,
                    'instructions'  => '0 = unlimited polling.',
                ],
                [
                    'key'           => 'field_wcaig_retry_count',
                    'label'         => 'Max Retries',
                    'name'          => 'wcaig_retry_count',
                    'type'          => 'number',
                    'default_value' => 3,
                    'min'           => 0,
                    'max'           => 20,
                ],
                [
                    'key'           => 'field_wcaig_max_concurrent',
                    'label'         => 'Max Concurrent Tasks',
                    'name'          => 'wcaig_max_concurrent',
                    'type'          => 'number',
                    'default_value' => 5,
                    'min'           => 1,
                    'max'           => 50,
                ],
                [
                    'key'           => 'field_wcaig_task_timeout',
                    'label'         => 'Task Timeout (minutes)',
                    'name'          => 'wcaig_task_timeout',
                    'type'          => 'number',
                    'default_value' => 30,
                    'min'           => 5,
                    'max'           => 1440,
                ],
                // Prompt
                [
                    'key'   => 'field_wcaig_preservation_rules',
                    'label' => 'Custom Preservation Rules',
                    'name'  => 'wcaig_preservation_rules',
                    'type'  => 'textarea',
                    'instructions' => 'Custom text appended to every prompt.',
                ],
                // Limits
                [
                    'key'           => 'field_wcaig_global_image_cap',
                    'label'         => 'Global Image Cap',
                    'name'          => 'wcaig_global_image_cap',
                    'type'          => 'number',
                    'default_value' => 1000,
                    'instructions'  => 'Max total generated images. 0 = unlimited.',
                ],
                // Debug
                [
                    'key'           => 'field_wcaig_debug_logging',
                    'label'         => 'Debug Logging',
                    'name'          => 'wcaig_debug_logging',
                    'type'          => 'true_false',
                    'default_value' => 0,
                ],
                [
                    'key'           => 'field_wcaig_dryrun_gc',
                    'label'         => 'Dry-Run GC',
                    'name'          => 'wcaig_dryrun_gc',
                    'type'          => 'true_false',
                    'default_value' => 0,
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'options_page',
                        'operator' => '==',
                        'value'    => 'wcaig-settings',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Register product sidebar fields.
     */
    private function register_product_fields(): void
    {
        $fields = [
            [
                'key'           => 'field_wcaig_base_image',
                'label'         => 'Base Image for AI Generation',
                'name'          => 'wcaig_base_image',
                'type'          => 'image',
                'return_format' => 'id',
                'instructions'  => 'Source photo for AI generation. Fallback: product featured image.',
            ],
        ];

        // Add base_attr fields for each registered WC attribute.
        foreach ($this->get_attribute_choices() as $key => $label) {
            $fields[] = [
                'key'           => "field_wcaig_base_attr_{$key}",
                'label'         => "Base {$label} Value",
                'name'          => "wcaig_base_attr_{$key}",
                'type'          => 'taxonomy',
                'taxonomy'      => "pa_{$key}",
                'field_type'    => 'select',
                'allow_null'    => 1,
                'return_format' => 'object',
                'save_terms'    => 0,
                'load_terms'    => 0,
                'instructions'  => "What the base image currently shows for {$label}.",
            ];
        }

        acf_add_local_field_group([
            'key'      => 'group_wcaig_product',
            'title'    => 'AI Image Gen Settings',
            'fields'   => $fields,
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'product',
                    ],
                ],
            ],
            'position' => 'side',
        ]);
    }

    /**
     * Register image_variation CPT fields.
     */
    private function register_variation_cpt_fields(): void
    {
        $fields = [
            [
                'key'           => 'field_wcaig_parent_product',
                'label'         => 'Parent Product',
                'name'          => 'wcaig_parent_product',
                'type'          => 'post_object',
                'post_type'     => [ 'product' ],
                'return_format' => 'id',
            ],
            [
                'key'   => 'field_wcaig_task_id',
                'label' => 'PIAPI Task ID',
                'name'  => 'wcaig_task_id',
                'type'  => 'text',
            ],
            [
                'key'           => 'field_wcaig_retry_count',
                'label'         => 'Retry Count',
                'name'          => 'wcaig_retry_count',
                'type'          => 'number',
                'default_value' => 0,
            ],
        ];

        // Add attr fields for each registered WC attribute.
        foreach ($this->get_attribute_choices() as $key => $label) {
            $fields[] = [
                'key'           => "field_wcaig_attr_{$key}",
                'label'         => "Target {$label}",
                'name'          => "wcaig_attr_{$key}",
                'type'          => 'taxonomy',
                'taxonomy'      => "pa_{$key}",
                'field_type'    => 'select',
                'allow_null'    => 1,
                'return_format' => 'object',
                'save_terms'    => 0,
                'load_terms'    => 0,
            ];
        }

        acf_add_local_field_group([
            'key'      => 'group_wcaig_image_variation',
            'title'    => 'Image Variation Details',
            'fields'   => $fields,
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'image_variation',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Register term fields on all pa_* taxonomies.
     */
    private function register_term_fields(): void
    {
        $taxonomies = $this->get_pa_taxonomies();

        if (empty($taxonomies)) {
            return;
        }

        $locations = [];
        foreach ($taxonomies as $taxonomy) {
            $locations[] = [
                [
                    'param'    => 'taxonomy',
                    'operator' => '==',
                    'value'    => $taxonomy,
                ],
            ];
        }

        acf_add_local_field_group([
            'key'      => 'group_wcaig_term',
            'title'    => 'AI Image Gen Term Settings',
            'fields'   => [
                [
                    'key'   => 'field_wcaig_term_color_hex',
                    'label' => 'Color HEX',
                    'name'  => 'wcaig_term_color_hex',
                    'type'  => 'color_picker',
                ],
                [
                    'key'   => 'field_wcaig_term_color_rgb',
                    'label' => 'Color RGB',
                    'name'  => 'wcaig_term_color_rgb',
                    'type'  => 'text',
                    'instructions' => 'e.g., RGB 0, 184, 217',
                ],
                [
                    'key'   => 'field_wcaig_term_description',
                    'label' => 'Description',
                    'name'  => 'wcaig_term_description',
                    'type'  => 'text',
                    'instructions' => 'e.g., bright turquoise cyan',
                ],
            ],
            'location' => $locations,
        ]);
    }

    /**
     * Get WC attribute choices for field options.
     *
     * @return array Associative array of attr_name => Label.
     */
    private function get_attribute_choices(): array
    {
        if (! function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        $choices = [];
        foreach (wc_get_attribute_taxonomies() as $attr) {
            $choices[ $attr->attribute_name ] = $attr->attribute_label ?: ucfirst($attr->attribute_name);
        }

        return $choices;
    }

    /**
     * Get all pa_* taxonomy names.
     *
     * @return array
     */
    private function get_pa_taxonomies(): array
    {
        $taxonomies = [];
        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach (wc_get_attribute_taxonomies() as $attr) {
                $taxonomies[] = 'pa_' . $attr->attribute_name;
            }
        }
        return $taxonomies;
    }

    /**
     * AJAX: Run worker now.
     */
    public function ajax_run_worker(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }

        check_ajax_referer('wcaig_admin_nonce', '_wpnonce');

        if (class_exists('WCAIG_Worker')) {
            WCAIG_Worker::instance()->run();
        }

        wp_send_json_success([ 'message' => 'Worker run completed.' ]);
    }

    /**
     * AJAX: Run GC now.
     */
    public function ajax_run_gc(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }

        check_ajax_referer('wcaig_admin_nonce', '_wpnonce');

        if (class_exists('WCAIG_Garbage_Collector')) {
            WCAIG_Garbage_Collector::instance()->run();
        }

        wp_send_json_success([ 'message' => 'GC run completed.' ]);
    }

    /**
     * AJAX: Purge all generated images.
     */
    public function ajax_purge_all(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }

        check_ajax_referer('wcaig_admin_nonce', '_wpnonce');

        // Delete all image_variation CPT posts.
        $posts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($posts as $post_id) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                wp_delete_attachment($thumb_id, true);
            }
            wp_delete_post($post_id, true);
        }

        // Delete WCAIG media attachments.
        global $wpdb;
        $media = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE 'wcaig_%'"
        );
        foreach ($media as $mid) {
            wp_delete_attachment((int) $mid, true);
        }

        // Delete wcaig_image_* transients.
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcaig_image_%' OR option_name LIKE '_transient_timeout_wcaig_image_%'"
        );

        WCAIG_Logger::instance()->info('Purge all: deleted all generated images and variations.');

        wp_send_json_success([ 'message' => 'All generated images purged.' ]);
    }

    /**
     * AJAX: Regenerate a single variation.
     */
    public function ajax_regenerate(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }

        check_ajax_referer('wcaig_admin_nonce', '_wpnonce');

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (! $post_id) {
            wp_send_json_error([ 'message' => 'Missing post_id.' ]);
        }

        $post = get_post($post_id);
        if (! $post || 'image_variation' !== $post->post_type) {
            wp_send_json_error([ 'message' => 'Invalid post.' ]);
        }

        $hash = str_replace('variation_', '', $post->post_name);

        // Delete featured image.
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            wp_delete_attachment($thumb_id, true);
        }
        delete_post_thumbnail($post_id);

        // Reset.
        wp_update_post([ 'ID' => $post_id, 'post_status' => 'draft' ]);
        update_field('wcaig_task_id', '', $post_id);
        update_field('wcaig_retry_count', 0, $post_id);
        delete_transient("wcaig_image_{$hash}");

        wp_send_json_success([ 'message' => 'Variation reset for regeneration.' ]);
    }
}
