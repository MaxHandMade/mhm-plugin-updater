<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Brain\Monkey setup for WordPress function mocking
\Brain\Monkey\setUp();

// Minimal WP_Error stub for unit tests
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '') {}
    }
}

// Minimal esc_html stub
if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
