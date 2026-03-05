<?php
/**
 * Event Reference Dates
 * 
 * Functions for managing reference dates used in time-based calculations.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get the reference end date for time-based calculations.
 * This is the date of the newest event, used instead of "today".
 */
function wporgcd_get_reference_end_date() {
    return get_option('wporgcd_reference_end_date', current_time('Y-m-d'));
}

/**
 * Get the reference start date for time-based calculations.
 * This is the date of the oldest event.
 */
function wporgcd_get_reference_start_date() {
    return get_option('wporgcd_reference_start_date', current_time('Y-m-d'));
}

/**
 * Set the reference dates from the events table.
 * Call this at the start of profile generation.
 * Start date is set to 5 years before the end date to exclude unreliable older data.
 */
function wporgcd_set_reference_date_from_events() {
    global $wpdb;
    $events_table = wporgcd_get_table('events');

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // $events_table is safe from wporgcd_get_table()
    $latest = $wpdb->get_var( "SELECT MAX(event_created_date) FROM $events_table" );
    // phpcs:enable

    if ( $latest ) {
        $end_date = gmdate( 'Y-m-d', strtotime( $latest ) );
        update_option( 'wporgcd_reference_end_date', $end_date );

        // Start date is 5 years before the end date
        $start_date = gmdate( 'Y-m-d', strtotime( $end_date . ' -5 years' ) );
        update_option( 'wporgcd_reference_start_date', $start_date );
    }
}
