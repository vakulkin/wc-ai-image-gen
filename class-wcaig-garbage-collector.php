<?php

/**
 * WCAIG Garbage Collector — Removes orphaned images and expired queue entries.
 *
 * An image is orphaned when:
 *   1. Its parent product no longer exists, OR
 *   2. Any of the attribute terms stored in _wcaig_attributes no longer exist
 *      in their respective pa_* taxonomy.
 *
 * Time-based deletion is NOT used — images stay as long as they remain valid.
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

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wcaig_gc_cron', [$this, 'run']);
    }

    /**
     * Run one GC cycle.
     */
    public function run(): void
    {
        if (get_transient('wcaig_gc_running')) {
            return;
        }

        set_transient('wcaig_gc_running', true, 10 * MINUTE_IN_SECONDS);

        $dry_run = (bool) WCAIG_Hash::get_option('wcaig_dryrun_gc', false);

        try {
            $this->purge_expired_queue($dry_run);
            $this->clean_orphaned_attachments($dry_run);
        } finally {
            delete_transient('wcaig_gc_running');
        }
    }

    /**
     * Purge expired queue entries (TTL exceeded).
     */
    private function purge_expired_queue(bool $dry_run): void
    {
        if ($dry_run) {
            $count = WCAIG_Queue::instance()->count();
            WCAIG_Logger::instance()->info("GC dry-run: queue has {$count} entries total.");
            return;
        }

        $purged = WCAIG_Queue::instance()->purge_expired();
        if ($purged > 0) {
            WCAIG_Logger::instance()->info("GC: purged {$purged} expired queue entries.");
        }
    }

    /**
     * Delete WCAIG attachments whose product or attribute terms no longer exist.
     */
    private function clean_orphaned_attachments(bool $dry_run): void
    {
        global $wpdb;

        // Get all attachment IDs that have _wcaig_hash meta.
        $attachment_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wcaig_hash'
                 LIMIT %d",
                self::BATCH_SIZE
            )
        );

        $deleted = 0;

        foreach ($attachment_ids as $attachment_id) {
            $attachment_id = (int) $attachment_id;
            $hash       = get_post_meta($attachment_id, '_wcaig_hash', true);
            $product_id = (int) get_post_meta($attachment_id, '_wcaig_product_id', true);
            $attrs_json = get_post_meta($attachment_id, '_wcaig_attributes', true);

            $reason = $this->get_orphan_reason($product_id, $attrs_json);

            if ($reason === null) {
                continue; // Still valid.
            }

            if ($dry_run) {
                WCAIG_Logger::instance()->info("GC dry-run: would delete attachment {$attachment_id} (hash={$hash}, reason={$reason})");
                continue;
            }

            wp_delete_attachment($attachment_id, true);
            $deleted++;

            WCAIG_Logger::instance()->info("GC: deleted orphaned attachment {$attachment_id} (hash={$hash}, reason={$reason})");
        }

        if ($deleted > 0) {
            WCAIG_Logger::instance()->info("GC: deleted {$deleted} orphaned attachments.");
        }
    }

    /**
     * Determine why an attachment is orphaned, or null if still valid.
     *
     * @param int    $product_id  Product ID from _wcaig_product_id meta.
     * @param string $attrs_json  JSON from _wcaig_attributes meta.
     * @return string|null Reason string if orphaned, null if valid.
     */
    private function get_orphan_reason(int $product_id, string $attrs_json): ?string
    {
        // 1. Product must exist.
        if ($product_id <= 0 || ! wc_get_product($product_id)) {
            return 'product_missing';
        }

        // 2. Each attribute term must still exist in its pa_* taxonomy.
        $attributes = json_decode($attrs_json, true);

        if (! is_array($attributes) || empty($attributes)) {
            // No attributes stored — can't validate, but keep (legacy images).
            return null;
        }

        foreach ($attributes as $attr_name => $term_slug) {
            $taxonomy = 'pa_' . strtolower(trim(preg_replace('/^pa_/', '', $attr_name)));

            if (! taxonomy_exists($taxonomy)) {
                return "taxonomy_missing:{$taxonomy}";
            }

            $term = get_term_by('slug', $term_slug, $taxonomy);
            if (! $term || is_wp_error($term)) {
                return "term_missing:{$taxonomy}/{$term_slug}";
            }
        }

        return null; // All checks passed — attachment is valid.
    }
}
