<?php

/**
 * Plugin Name:       WC AI Image Gen
 * Plugin URI:        https://example.com/wc-ai-image-gen
 * Description:       Automatically generates unique product images for WooCommerce variable products using the PIAPI AI service (Gemini model).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            WCAIG
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-ai-image-gen
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce,advanced-custom-fields-pro
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 * Tested up to:         6.6
 */

if (! defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('WCAIG_VERSION', '1.0.0');
define('WCAIG_PLUGIN_FILE', __FILE__);
define('WCAIG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCAIG_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check plugin requirements.
 *
 * @return bool True if all requirements are met.
 */
function wcaig_check_requirements(): bool
{
    $errors = [];

    if (version_compare(PHP_VERSION, '8.0', '<')) {
        $errors[] = 'WC AI Image Gen requires PHP 8.0 or higher.';
    }

    if (! class_exists('WooCommerce')) {
        $errors[] = 'WC AI Image Gen requires WooCommerce 7.0 or higher.';
    }

    if (! class_exists('ACF') && ! function_exists('acf')) {
        $errors[] = 'WC AI Image Gen requires ACF PRO 6.0 or higher.';
    }

    if (! empty($errors)) {
        add_action('admin_notices', function () use ($errors) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
        });
        return false;
    }

    return true;
}

/**
 * Initialize the plugin.
 * Hooked to plugins_loaded at priority 20.
 */
function wcaig_init(): void
{
    if (! wcaig_check_requirements()) {
        return;
    }

    // Include class files.
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-logger.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-hash.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-cpt.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-activator.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-acf.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-api-client.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-rest-api.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-webhook-handler.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-worker.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-garbage-collector.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-statistics.php';
    require_once WCAIG_PLUGIN_DIR . 'class-wcaig-frontend.php';

    // Instantiate singletons.
    WCAIG_Logger::instance();
    WCAIG_Hash::instance();
    WCAIG_CPT::instance();
    WCAIG_ACF::instance();
    WCAIG_API_Client::instance();
    WCAIG_REST_API::instance();
    WCAIG_Webhook_Handler::instance();
    WCAIG_Worker::instance();
    WCAIG_Garbage_Collector::instance();
    WCAIG_Statistics::instance();
    WCAIG_Frontend::instance();
}

add_action('plugins_loaded', 'wcaig_init', 20);

// Declare WooCommerce feature compatibility.
add_action('before_woocommerce_init', function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
    }
});

// Activation / Deactivation hooks (must be in main file).
register_activation_hook(__FILE__, function () {
    require_once plugin_dir_path(__FILE__) . 'class-wcaig-logger.php';
    require_once plugin_dir_path(__FILE__) . 'class-wcaig-hash.php';
    require_once plugin_dir_path(__FILE__) . 'class-wcaig-cpt.php';
    require_once plugin_dir_path(__FILE__) . 'class-wcaig-activator.php';
    WCAIG_Activator::activate();
});

register_deactivation_hook(__FILE__, function () {
    require_once plugin_dir_path(__FILE__) . 'class-wcaig-activator.php';
    WCAIG_Activator::deactivate();
});

/**
 * Register custom cron interval.
 */
add_filter('cron_schedules', function (array $schedules): array {
    $schedules['wcaig_every_minute'] = [
        'interval' => 60,
        'display'  => __('Every Minute (WCAIG)', 'wc-ai-image-gen'),
    ];
    return $schedules;
});
