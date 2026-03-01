<?php
/**
 * Profile Query Filters
 * 
 * SQL filter builders for profile queries.
 */

if (!defined('ABSPATH')) exit;

/**
 * Build profile filter clauses for SQL queries
 * 
 * @param array $options {
 *     @type bool   $include_inactive  Include inactive users (default: false)
 *     @type int    $range_days        Filter by registration date within X days (default: null = all time)
 *     @type string $date_column       Column name for date filtering (default: 'registered_date')
 * }
 * @return array {
 *     @type string $where      Complete WHERE clause (includes "WHERE")
 *     @type string $and        Conditions with leading " AND " for appending
 * }
 */
function wporgcd_build_profile_filters($options = array()) {
    global $wpdb;

    $defaults = array(
        'include_inactive' => false,
        'range_days' => null,
        'date_column' => 'registered_date',
    );
    $options = wp_parse_args($options, $defaults);

    $conditions = array();

    // Date filter
    if ( $options['range_days'] !== null ) {
        $reference_end = wporgcd_get_reference_end_date();
        $cutoff_date = gmdate( 'Y-m-d', strtotime( $reference_end . " -{$options['range_days']} days" ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $options['date_column'] is from trusted internal defaults
        $conditions[] = $wpdb->prepare( "{$options['date_column']} >= %s", $cutoff_date );
    }

    // Status filter
    if (!$options['include_inactive']) {
        $conditions[] = "status != 'inactive'";
    }

    // Build clauses
    $where = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";
    $and = !empty($conditions) ? " AND " . implode(" AND ", $conditions) : "";

    return array(
        'where' => $where,
        'and' => $and,
    );
}
