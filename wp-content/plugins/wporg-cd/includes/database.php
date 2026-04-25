<?php
/**
 * Database Helpers
 * 
 * Core database functions used across the plugin.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get table name with prefix.
 *
 * @param string $table Table identifier. Currently only 'events' is registered.
 * @return string Full table name with WordPress prefix, or '' if unknown.
 */
function wporgcd_get_table($table) {
    global $wpdb;
    $tables = array(
        'events' => $wpdb->prefix . 'wporgcd_events',
    );
    return $tables[$table] ?? '';
}
