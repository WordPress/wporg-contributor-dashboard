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

    // Index design notes:
    // - idx_eventdate_type_contributor covers the new Wrapped workload
    //   (filter event_created_date BETWEEN + event_type IN, project/group by
    //   contributor_id). Leftmost prefix on event_created_date alone replaces
    //   the legacy single-column event_created_date index; the migration in
    //   wporgcd_maybe_run_db_migrations() drops that legacy index for new
    //   installs that already had it.
    // - idx_regdate_contributor_type_eventdate covers the Onboarding/Ladder
    //   workload (filter contributor_created_date, group by contributor_id).
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
        KEY idx_eventdate_type_contributor (event_created_date, event_type, contributor_id),
        KEY idx_contributor_type_date (contributor_id, event_type, event_created_date),
        KEY idx_regdate_contributor_type_eventdate (contributor_created_date, contributor_id, event_type, event_created_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Run schema migrations when WPORGCD_DB_VERSION advances past the stored option.
 *
 * dbDelta() is unreliable for adding multi-column indexes (silently no-ops in
 * many WP/MySQL combinations), so all index changes are issued explicitly here
 * via ALTER TABLE, guarded by information_schema lookups for idempotency.
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

    // Drop the legacy single-column contributor_id index: the leftmost prefix
    // of idx_contributor_type_date already covers the same access pattern.
    if ( wporgcd_index_exists( $table_name, 'contributor_id' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
        $wpdb->query( "ALTER TABLE $table_name DROP INDEX contributor_id" );
    }

    // Add the covering composite for the registration-date GROUP BY workload.
    // Done explicitly because dbDelta sometimes fails to add multi-column keys.
    if ( ! wporgcd_index_exists( $table_name, 'idx_regdate_contributor_type_eventdate' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
        $wpdb->query(
            "ALTER TABLE $table_name
             ADD KEY idx_regdate_contributor_type_eventdate
                 (contributor_created_date, contributor_id, event_type, event_created_date)"
        );
    }

    // Add the covering composite for the new Wrapped event-date GROUP BY
    // workload (filter event_created_date BETWEEN + event_type IN, group by
    // contributor_id). Without this, MySQL falls back to a date-only range
    // scan plus row lookups for event_type/contributor_id, which is the path
    // that drove the per-(contributor,type,month) rollup OOM.
    if ( ! wporgcd_index_exists( $table_name, 'idx_eventdate_type_contributor' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
        $wpdb->query(
            "ALTER TABLE $table_name
             ADD KEY idx_eventdate_type_contributor
                 (event_created_date, event_type, contributor_id)"
        );
    }

    // Drop the legacy single-column event_created_date index: the leftmost
    // prefix of idx_eventdate_type_contributor already covers the same
    // access pattern (e.g. SELECT MAX(event_created_date) in
    // wporgcd_set_reference_date_from_events).
    if ( wporgcd_index_exists( $table_name, 'idx_eventdate_type_contributor' )
        && wporgcd_index_exists( $table_name, 'event_created_date' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
        $wpdb->query( "ALTER TABLE $table_name DROP INDEX event_created_date" );
    }

    update_option( 'wporgcd_db_version', WPORGCD_DB_VERSION, false );
}

/**
 * Check whether an index exists on a table in the current database.
 *
 * @param string $table_name Full table name (already prefixed).
 * @param string $index_name Index identifier.
 * @return bool
 */
function wporgcd_index_exists( $table_name, $index_name ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema lookup, no caching needed
    $found = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = %s
           AND index_name = %s",
        $table_name,
        $index_name
    ) );
    return (int) $found > 0;
}
