<?php

/**
 * WCAIG Garbage Collector — Cleanup old and orphaned image variations.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Garbage_Collector
{
    private static ?WCAIG_Garbage_Collector $instance = null;

    private const BATCH_SIZE = 50;
    private const FAILED_AGE_DAYS    = 3;
    private const PUBLISHED_AGE_DAYS = 7;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wcaig_gc_cron', [ $this, 'run' ]);
    }

    /**
     * Run one GC cycle.
     */
    public function run(): void
    {
        // Acquire mutex.
        if (get_transient('wcaig_gc_running')) {
            WCAIG_Logger::instance()->debug('GC: mutex held, skipping run.');
            return;
        }

        set_transient('wcaig_gc_running', true, 10 * MINUTE_IN_SECONDS);

        $dry_run = $this->is_dry_run();

        try {
            $this->clean_old_failed($dry_run);
            $this->clean_old_published($dry_run);
            $this->clean_orphaned_media($dry_run);
        } finally {
            delete_transient('wcaig_gc_running');
        }
    }

    /**
     * Task 1: Delete failed variations older than 3 days.
     */
    private function clean_old_failed(bool $dry_run): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::FAILED_AGE_DAYS * DAY_IN_SECONDS);

        $posts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => 'failed',
            'posts_per_page' => self::BATCH_SIZE,
            'date_query'     => [
                [
                    'column' => 'post_modified_gmt',
                    'before' => $cutoff,
                ],
            ],
            'orderby'        => 'post_modified',
            'order'          => 'ASC',
        ]);

        foreach ($posts as $post) {
            if ($dry_run) {
                WCAIG_Logger::instance()->info("GC dry-run: would delete failed variation {$post->ID}");
                continue;
            }

            // Delete featured image attachment.
            $thumb_id = get_post_thumbnail_id($post->ID);
            if ($thumb_id) {
                wp_delete_attachment($thumb_id, true);
            }

            wp_delete_post($post->ID, true);
            WCAIG_Logger::instance()->info("GC: deleted failed variation {$post->ID}");
        }
    }

    /**
     * Task 2: Delete published variations older than 7 days.
     */
    private function clean_old_published(bool $dry_run): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::PUBLISHED_AGE_DAYS * DAY_IN_SECONDS);

        $posts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'date_query'     => [
                [
                    'column' => 'post_modified_gmt',
                    'before' => $cutoff,
                ],
            ],
            'orderby'        => 'post_modified',
            'order'          => 'ASC',
        ]);

        foreach ($posts as $post) {
            $hash = str_replace('variation_', '', $post->post_name);

            if ($dry_run) {
                WCAIG_Logger::instance()->info("GC dry-run: would delete published variation {$post->ID} (hash={$hash})");
                continue;
            }

            // Delete featured image attachment.
            $thumb_id = get_post_thumbnail_id($post->ID);
            if ($thumb_id) {
                wp_delete_attachment($thumb_id, true);
            }

            // Delete transient.
            delete_transient("wcaig_image_{$hash}");

            wp_delete_post($post->ID, true);
            WCAIG_Logger::instance()->info("GC: deleted published variation {$post->ID} (hash={$hash})");
        }
    }

    /**
     * Task 3: Delete orphaned WCAIG media attachments.
     */
    private function clean_orphaned_media(bool $dry_run): void
    {
        global $wpdb;

        $attachments = $wpdb->get_results(
            "SELECT ID, post_parent FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_title LIKE 'wcaig_%'
             LIMIT " . self::BATCH_SIZE
        );

        foreach ($attachments as $attachment) {
            $parent_exists = false;

            if ((int) $attachment->post_parent > 0) {
                $parent = get_post($attachment->post_parent);
                if ($parent && $parent->post_type === 'image_variation') {
                    $parent_exists = true;
                }
            }

            if (! $parent_exists) {
                if ($dry_run) {
                    WCAIG_Logger::instance()->info("GC dry-run: would delete orphaned attachment {$attachment->ID}");
                    continue;
                }

                wp_delete_attachment((int) $attachment->ID, true);
                WCAIG_Logger::instance()->info("GC: deleted orphaned attachment {$attachment->ID}");
            }
        }
    }

    /**
     * Check if dry-run mode is enabled.
     */
    private function is_dry_run(): bool
    {
        if (function_exists('get_field')) {
            return (bool) get_field('wcaig_dryrun_gc', 'option');
        }
        return false;
    }
}
