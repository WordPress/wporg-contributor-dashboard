<?php
/**
 * Events Table Schema
 * 
 * Database setup for contributor events.
 */

if (!defined('ABSPATH')) exit;

/**
 * Create the wporgcd_events table
 */
function wporgcd_create_events_table() {
    global $wpdb;
    $table_name = wporgcd_get_table('events');
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        internal_id bigint(20) NOT NULL AUTO_INCREMENT,
        event_id bigint(20) NOT NULL,
        contributor_id bigint(20) NOT NULL,
        contributor_created_date datetime DEFAULT NULL,
        event_type varchar(100) NOT NULL,
        event_data longtext DEFAULT NULL,
        event_created_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (internal_id),
        UNIQUE KEY event_id (event_id),
        KEY contributor_id (contributor_id),
        KEY event_type (event_type),
        KEY event_created_date (event_created_date),
        KEY idx_contributor_type_date (contributor_id, event_type, event_created_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
