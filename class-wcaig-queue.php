<?php

/**
 * WCAIG Queue — Custom DB table for tracking pending image generation tasks.
 *
 * Replaces the CPT-based approach with a lightweight custom table.
 * Rows exist only while an image is being generated; once complete
 * the row is deleted and the result lives as a standard WP attachment.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Queue
{
    private static ?WCAIG_Queue $instance = null;

    /** @var string Full table name (with prefix). */
    private string $table;

    /** @var int Default TTL for queue entries in seconds (1 hour). */
    private int $default_ttl = 3600;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wcaig_queue';
    }

    /**
     * Get the full table name.
     */
    public function get_table_name(): string
    {
        return $this->table;
    }

    // ──────────────────────────────────────────────
    // Schema
    // ──────────────────────────────────────────────

    /**
     * Create or update the table. Called on plugin activation.
     */
    public static function create_table(): void
    {
        global $wpdb;
        $table           = $wpdb->prefix . 'wcaig_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            hash         CHAR(32)         NOT NULL,
            product_id   BIGINT(20)       UNSIGNED NOT NULL,
            attributes   TEXT             NOT NULL,
            task_id      VARCHAR(255)     DEFAULT NULL,
            status       VARCHAR(20)      NOT NULL DEFAULT 'draft',
            retries      TINYINT(3)       UNSIGNED NOT NULL DEFAULT 0,
            error_msg    TEXT             DEFAULT NULL,
            created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at   DATETIME         NOT NULL,
            PRIMARY KEY  (hash),
            KEY idx_status (status),
            KEY idx_expires (expires_at),
            KEY idx_product (product_id),
            KEY idx_task_id (task_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Drop the table. Called on plugin uninstall.
     */
    public static function drop_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wcaig_queue';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    // ──────────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────────

    /**
     * Insert a new queue entry (status = draft).
     *
     * Uses INSERT IGNORE so duplicate hashes are silently skipped
     * (PRIMARY KEY constraint = atomic dedup, no race conditions).
     *
     * @param string $hash       Variation hash.
     * @param int    $product_id Product ID.
     * @param array  $attributes Selected attributes.
     * @param int    $ttl        Time-to-live in seconds.
     * @return bool True if inserted, false if duplicate or error.
     */
    public function insert(string $hash, int $product_id, array $attributes, int $ttl = 0): bool
    {
        global $wpdb;

        if ($ttl <= 0) {
            $ttl = $this->default_ttl;
        }

        $now        = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + $ttl);

        // INSERT IGNORE: if hash already exists, silently fail (no race condition).
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$this->table}
                 (hash, product_id, attributes, status, retries, created_at, updated_at, expires_at)
                 VALUES (%s, %d, %s, 'draft', 0, %s, %s, %s)",
                $hash,
                $product_id,
                wp_json_encode($attributes),
                $now,
                $now,
                $expires_at
            )
        );

        return $result !== false && $wpdb->rows_affected > 0;
    }

    /**
     * Find a queue entry by hash.
     *
     * @param string $hash Variation hash.
     * @return object|null Row object or null.
     */
    public function find(string $hash): ?object
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE hash = %s", $hash)
        );
    }

    /**
     * Update status and optionally task_id.
     *
     * @param string      $hash    Variation hash.
     * @param string      $status  New status (draft|processing|failed).
     * @param string|null $task_id PIAPI task ID.
     * @return bool
     */
    public function update_status(string $hash, string $status, ?string $task_id = null): bool
    {
        global $wpdb;

        $data   = ['status' => $status, 'updated_at' => current_time('mysql', true)];
        $format = ['%s', '%s'];

        if (null !== $task_id) {
            $data['task_id'] = $task_id;
            $format[]        = '%s';
        }

        $result = $wpdb->update($this->table, $data, ['hash' => $hash], $format, ['%s']);

        return $result !== false;
    }

    /**
     * Increment retry count, clear task_id, and set status back to draft.
     *
     * @param string      $hash      Variation hash.
     * @param string|null $error_msg Error message to store.
     * @return int New retry count.
     */
    public function increment_retry(string $hash, ?string $error_msg = null): int
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET retries = retries + 1,
                     status = 'draft',
                     task_id = NULL,
                     error_msg = %s,
                     updated_at = %s
                 WHERE hash = %s",
                $error_msg,
                current_time('mysql', true),
                $hash
            )
        );

        $row = $this->find($hash);
        return $row ? (int) $row->retries : 0;
    }

    /**
     * Reset a queue entry for retry without burning a retry count.
     * Used for rate-limit failures that aren't our fault.
     */
    public function reset_for_rate_limit(string $hash): bool
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [
                'status'     => 'draft',
                'task_id'    => null,
                'error_msg'  => 'rate_limited',
                'updated_at' => current_time('mysql', true),
            ],
            ['hash' => $hash],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        ) !== false;
    }

    /**
     * Mark as permanently failed (no more retries).
     */
    public function mark_failed(string $hash, ?string $error_msg = null): bool
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [
                'status'     => 'failed',
                'error_msg'  => $error_msg,
                'updated_at' => current_time('mysql', true),
            ],
            ['hash' => $hash],
            ['%s', '%s', '%s'],
            ['%s']
        ) !== false;
    }

    /**
     * Delete a queue entry (called after successful sideload).
     */
    public function delete(string $hash): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['hash' => $hash], ['%s']) !== false;
    }

    // ──────────────────────────────────────────────
    // Batch queries
    // ──────────────────────────────────────────────

    /**
     * Get draft entries (ready to be sent to PIAPI).
     *
     * @param int $limit    Max entries to return.
     * @param int $max_retries  Skip entries that exhausted retries.
     * @return array Array of row objects.
     */
    public function get_drafts(int $limit = 5, int $max_retries = 3): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = 'draft'
                   AND retries < %d
                   AND expires_at > %s
                 ORDER BY created_at ASC
                 LIMIT %d",
                $max_retries,
                current_time('mysql', true),
                $limit
            )
        );
    }

    /**
     * Get processing entries (for poll fallback).
     *
     * @param int $limit Max entries to return.
     * @return array Array of row objects.
     */
    public function get_processing(int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = 'processing'
                   AND expires_at > %s
                 ORDER BY created_at ASC
                 LIMIT %d",
                current_time('mysql', true),
                $limit
            )
        );
    }

    /**
     * Get entry by task_id (used by webhook handler).
     *
     * @param string $task_id PIAPI task ID.
     * @return object|null
     */
    public function find_by_task_id(string $task_id): ?object
    {
        global $wpdb;

        if (empty($task_id)) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE task_id = %s",
                $task_id
            )
        );
    }

    /**
     * Count currently processing entries.
     */
    public function count_processing(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'processing' AND expires_at > %s",
                current_time('mysql', true)
            )
        );
    }

    // ──────────────────────────────────────────────
    // Cleanup
    // ──────────────────────────────────────────────

    /**
     * Purge all expired entries (auto-cleanup).
     *
     * @return int Number of rows deleted.
     */
    public function purge_expired(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE expires_at <= %s",
                current_time('mysql', true)
            )
        );
    }

    /**
     * Purge all entries (for admin purge action).
     *
     * @return int Number of rows deleted.
     */
    public function purge_all(): int
    {
        global $wpdb;

        return (int) $wpdb->query("TRUNCATE TABLE {$this->table}");
    }

    /**
     * Count entries by status.
     *
     * @param string|null $status Filter by status, or null for all.
     * @return int
     */
    public function count(?string $status = null): int
    {
        global $wpdb;

        if ($status) {
            return (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = %s", $status)
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }
}
