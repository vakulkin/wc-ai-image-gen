<?php

/**
 * WCAIG Activator — Handles plugin activation and deactivation.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Activator
{
    /**
     * Plugin activation.
     */
    public static function activate(): void
    {
        // Register CPT so rewrite rules exist.
        WCAIG_CPT::instance()->register_post_type();
        WCAIG_CPT::instance()->register_custom_statuses();

        // Schedule WP-Cron events.
        if (! wp_next_scheduled('wcaig_worker_cron')) {
            wp_schedule_event(time(), 'wcaig_every_minute', 'wcaig_worker_cron');
        }

        if (! wp_next_scheduled('wcaig_gc_cron')) {
            wp_schedule_event(time(), 'daily', 'wcaig_gc_cron');
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate(): void
    {
        // Clear cron hooks.
        wp_clear_scheduled_hook('wcaig_worker_cron');
        wp_clear_scheduled_hook('wcaig_gc_cron');

        // Delete mutex transients.
        delete_transient('wcaig_worker_running');
        delete_transient('wcaig_gc_running');

        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}
