<?php

/**
 * WCAIG Statistics — Metrics and cap enforcement.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Statistics
{
    private static ?WCAIG_Statistics $instance = null;

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
     * Get all statistics.
     *
     * @return array{draft: int, processing: int, publish: int, failed: int, total_media: int, global_cap: int, cap_usage: float}
     */
    public function get_all(): array
    {
        $draft      = $this->count_by_status('draft');
        $processing = $this->count_by_status('processing');
        $publish    = $this->count_by_status('publish');
        $failed     = $this->count_by_status('failed');
        $total_media = $this->count_wcaig_media();
        $global_cap  = $this->get_global_cap();
        $cap_usage   = $global_cap > 0 ? round(($total_media / $global_cap) * 100, 2) : 0;

        return [
            'draft'       => $draft,
            'processing'  => $processing,
            'publish'     => $publish,
            'failed'      => $failed,
            'total_media' => $total_media,
            'global_cap'  => $global_cap,
            'cap_usage'   => $cap_usage,
        ];
    }

    /**
     * Count image_variation posts by status.
     *
     * @param string $status Post status.
     * @return int
     */
    private function count_by_status(string $status): int
    {
        $query = new WP_Query([
            'post_type'      => 'image_variation',
            'post_status'    => $status,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return $query->found_posts;
    }

    /**
     * Count WCAIG media attachments (title matching wcaig_*).
     *
     * @return int
     */
    public function count_wcaig_media(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'
               AND post_title LIKE 'wcaig_%'"
        );
    }

    /**
     * Get the global image cap setting.
     *
     * @return int
     */
    private function get_global_cap(): int
    {
        if (function_exists('get_field')) {
            $cap = get_field('wcaig_global_image_cap', 'option');
            if (is_numeric($cap)) {
                return (int) $cap;
            }
        }
        return 1000; // default
    }

    /**
     * Check if the global cap has been reached.
     *
     * @return bool
     */
    public function is_cap_reached(): bool
    {
        $cap = $this->get_global_cap();
        if ($cap <= 0) {
            return false; // unlimited
        }

        return $this->count_wcaig_media() >= $cap;
    }
}
