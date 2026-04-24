<?php
/**
 * Profile Query Filters
 *
 * SQL filter builders for profile queries.
 */

if (!defined('ABSPATH')) exit;

/**
 * Build profile filter clauses for SQL queries.
 *
 * @param array $options {
 *     @type bool        $include_inactive Include inactive users (default: false).
 *     @type string|null $date_start       Inclusive lower bound, YYYY-MM-DD. Default: null (no lower bound).
 *     @type string|null $date_end         Inclusive upper bound, YYYY-MM-DD. Default: null (no upper bound).
 *     @type string      $date_column      Column name for date filtering (default: 'registered_date').
 * }
 * @return array {
 *     @type string $where Complete WHERE clause (includes "WHERE" when non-empty).
 *     @type string $and   Conditions with leading " AND " for appending.
 * }
 */
function wporgcd_build_profile_filters($options = array()) {
    global $wpdb;

    $defaults = array(
        'include_inactive' => false,
        'date_start'       => null,
        'date_end'         => null,
        'date_column'      => 'registered_date',
    );
    $options = wp_parse_args($options, $defaults);

    $conditions = array();

    if ( $options['date_start'] !== null && $options['date_start'] !== '' ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $options['date_column'] is from trusted internal defaults
        $conditions[] = $wpdb->prepare( "{$options['date_column']} >= %s", $options['date_start'] );
    }
    if ( $options['date_end'] !== null && $options['date_end'] !== '' ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $options['date_column'] is from trusted internal defaults
        $conditions[] = $wpdb->prepare( "{$options['date_column']} <= %s", $options['date_end'] );
    }

    if ( ! $options['include_inactive'] ) {
        $conditions[] = "status != 'inactive'";
    }

    $where = ! empty( $conditions ) ? " WHERE " . implode( " AND ", $conditions ) : "";
    $and   = ! empty( $conditions ) ? " AND " . implode( " AND ", $conditions ) : "";

    return array(
        'where' => $where,
        'and'   => $and,
    );
}
