<?php
/**
 * Event Reference Dates
 *
 * Functions for managing reference dates used in time-based calculations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the reference end date for time-based calculations.
 * This is the date of the newest event, used instead of "today".
 */
function wporgcd_get_reference_end_date() {
	return get_option( 'wporgcd_reference_end_date', current_time( 'Y-m-d' ) );
}

/**
 * Get the reference start date for time-based calculations.
 * Hardcoded floor configured in config.php.
 */
function wporgcd_get_reference_start_date() {
	return WPORGCD_REFERENCE_START_DATE;
}

/**
 * Set the reference end date from the events table.
 *
 * Called after each successful event import (see wporgcd_bulk_insert_events())
 * so the reference end date always reflects the newest event.
 */
function wporgcd_set_reference_date_from_events() {
	global $wpdb;
	$events_table = wporgcd_get_table( 'events' );

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// $events_table is safe from wporgcd_get_table()
	$latest = $wpdb->get_var( "SELECT MAX(event_created_date) FROM $events_table" );
    // phpcs:enable

	if ( $latest ) {
		$end_date = gmdate( 'Y-m-d', strtotime( $latest ) );
		update_option( 'wporgcd_reference_end_date', $end_date );
	}
}
