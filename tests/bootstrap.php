<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WP function stubs so plugin code under test can run without a full
// WordPress runtime. Each is a `function_exists` guard so a real WP
// environment (if ever present) wins.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        $str = (string) $str;
        $str = preg_replace('/<[^>]*>/', '', $str);
        $str = preg_replace('/[\r\n\t]+/', ' ', $str);
        $str = preg_replace('/ +/', ' ', $str);
        return trim($str);
    }
}

require_once __DIR__ . '/../wp-content/plugins/wporg-cd/config.php';
require_once __DIR__ . '/../wp-content/plugins/wporg-cd/includes/ladders.php';
