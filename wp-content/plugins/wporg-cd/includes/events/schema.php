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
        KEY event_type (event_type),
        KEY event_created_date (event_created_date),
        KEY idx_contributor_type_date (contributor_id, event_type, event_created_date),
        KEY idx_regdate_contributor_type_eventdate (contributor_created_date, contributor_id, event_type, event_created_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Run schema migrations when WPORGCD_DB_VERSION advances past the stored option.
 *
 * dbDelta() can add new columns and keys but never drops existing ones, so any
 * cleanup of removed indexes has to be issued explicitly here. The function is
 * idempotent: re-running it after a successful migration is a no-op.
 */
function wporgcd_maybe_run_db_migrations() {
    if ( ! defined( 'WPORGCD_DB_VERSION' ) ) {
        return;
    }

    $current = get_option( 'wporgcd_db_version' );
    if ( $current === WPORGCD_DB_VERSION ) {
        return;
    }

    wporgcd_create_events_table();

    global $wpdb;
    $table_name = wporgcd_get_table( 'events' );

    // Drop the legacy single-column contributor_id index now that the leftmost
    // prefix of idx_contributor_type_date covers the same access pattern. The
    // SHOW INDEX guard makes this safe to re-run on installs that never had the
    // index in the first place.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
    $has_legacy_index = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = %s
           AND index_name = 'contributor_id'",
        $table_name
    ) );

    if ( (int) $has_legacy_index > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
        $wpdb->query( "ALTER TABLE $table_name DROP INDEX contributor_id" );
    }

    update_option( 'wporgcd_db_version', WPORGCD_DB_VERSION, false );
}
