<?php

/**
 * WCAIG Logger — WooCommerce logging wrapper.
 *
 * Wraps wc_get_logger() with source channel 'wcaig'.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Logger
{
    private static ?WCAIG_Logger $instance = null;

    private ?WC_Logger_Interface $logger = null;

    private array $context = [ 'source' => 'wcaig' ];

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (function_exists('wc_get_logger')) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Log an info message (always logged).
     */
    public function info(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message, $this->context);
        }
    }

    /**
     * Log a debug message (only when debug logging is enabled).
     */
    public function debug(string $message): void
    {
        if (! $this->is_debug_enabled()) {
            return;
        }
        if ($this->logger) {
            $this->logger->debug($message, $this->context);
        }
    }

    /**
     * Log a warning message (always logged).
     */
    public function warning(string $message): void
    {
        if ($this->logger) {
            $this->logger->warning($message, $this->context);
        }
    }

    /**
     * Log an error message (always logged).
     */
    public function error(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message, $this->context);
        }
    }

    /**
     * Check if debug logging is enabled.
     */
    private function is_debug_enabled(): bool
    {
        if (function_exists('get_field')) {
            return (bool) get_field('wcaig_debug_logging', 'option');
        }
        return false;
    }
}
