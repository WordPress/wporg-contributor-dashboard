<?php
/**
 * Database Helpers
 * 
 * Core database functions used across the plugin.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get table name with prefix
 * 
 * @param string $table Table identifier: 'events' or 'profiles'
 * @return string Full table name with WordPress prefix
 */
function wporgcd_get_table($table) {
    global $wpdb;
    $tables = array(
        'events' => $wpdb->prefix . 'wporgcd_events',
        'profiles' => $wpdb->prefix . 'wporgcd_profiles',
    );
    return $tables[$table] ?? '';
}
