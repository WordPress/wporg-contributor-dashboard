<?php
/**
 * Core Event Import Functions
 * 
 * Reusable import logic for processing contributor events from any source.
 * Used by: CSV import (admin), REST API, WP-CLI, etc.
 */

if (!defined('ABSPATH')) exit;

/**
 * Validate a single event
 * 
 * @param array $event Event data with keys: event_id, contributor_id, event_type, etc.
 * @return true|WP_Error True if valid, WP_Error with details if not
 */
function wporgcd_validate_event($event) {
    $missing = array();

    if (empty($event['event_id'])) {
        $missing[] = 'event_id';
    }
    if (empty($event['contributor_id'])) {
        $missing[] = 'contributor_id';
    }
    if (empty($event['event_type'])) {
        $missing[] = 'event_type';
    }

    if (!empty($missing)) {
        return new WP_Error(
            'missing_fields',
            'Missing required fields: ' . implode(', ', $missing),
            array('missing' => $missing)
        );
    }

    return true;
}

/**
 * Insert a single event into the database
 * 
 * Uses INSERT IGNORE for atomic duplicate handling - avoids race conditions
 * when multiple sources insert events concurrently.
 * 
 * @param array $event Event data
 * @return string|WP_Error 'inserted', 'exists' (skipped), or WP_Error on failure
 */
function wporgcd_insert_event($event) {
    global $wpdb;
    $table_name = wporgcd_get_table('events');

    // Sanitize required fields
    $event_id = absint($event['event_id']);
    $contributor_id = absint($event['contributor_id']);
    $event_type = sanitize_key($event['event_type']);

    // Build column and value arrays for dynamic INSERT
    $columns = array('event_id', 'contributor_id', 'event_type');
    $values = array($event_id, $contributor_id, $event_type);
    $formats = array('%d', '%d', '%s');

    // Optional: contributor_created_date
    if (!empty($event['contributor_created_date'])) {
        $columns[] = 'contributor_created_date';
        $values[] = sanitize_text_field($event['contributor_created_date']);
        $formats[] = '%s';
    }

    // Optional: event_created_date
    if (!empty($event['event_created_date'])) {
        $columns[] = 'event_created_date';
        $values[] = sanitize_text_field($event['event_created_date']);
        $formats[] = '%s';
    }

    // Optional: event_data (JSON)
    if (!empty($event['event_data'])) {
        $columns[] = 'event_data';
        $values[] = is_string($event['event_data']) 
            ? $event['event_data'] 
            : wp_json_encode($event['event_data']);
        $formats[] = '%s';
    }

    // Build the INSERT IGNORE query
    $columns_sql = implode(', ', $columns);
    $placeholders = implode(', ', $formats);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and columns are safe
    $result = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $table_name ($columns_sql) VALUES ($placeholders)",
        $values
    ));

    if ($result === false) {
        return new WP_Error(
            'db_error',
            'Database error: ' . $wpdb->last_error
        );
    }

    // rows_affected = 1 if inserted, 0 if ignored (duplicate)
    return $wpdb->rows_affected > 0 ? 'inserted' : 'exists';
}

/**
 * Bulk insert multiple events using multi-row INSERT IGNORE
 * 
 * Much faster than single-row inserts (10-50x improvement).
 * 
 * @param array $events Array of event arrays
 * @param int   $batch_size Number of rows per INSERT statement (default 500)
 * @return array Results with 'inserted', 'skipped', and 'errors' counts
 */
function wporgcd_bulk_insert_events($events, $batch_size = 500) {
    global $wpdb;
    $table_name = wporgcd_get_table('events');

    $results = array(
        'imported' => 0,
        'skipped' => 0,
        'errors' => array(),
    );

    if (empty($events)) {
        return $results;
    }

    // Validate and prepare all events first
    $valid_events = array();
    foreach ($events as $event) {
        $valid = wporgcd_validate_event($event);
        if (is_wp_error($valid)) {
            $results['errors'][] = array(
                'event_id' => $event['event_id'] ?? 'unknown',
                'error' => $valid->get_error_message(),
            );
            continue;
        }
        $valid_events[] = $event;
    }

    if (empty($valid_events)) {
        return $results;
    }

    // Process in batches
    $chunks = array_chunk($valid_events, $batch_size);
    
    foreach ($chunks as $chunk) {
        $values_list = array();
        $placeholders_list = array();

        foreach ($chunk as $event) {
            $event_id = absint($event['event_id']);
            $contributor_id = absint($event['contributor_id']);
            $event_type = sanitize_key($event['event_type']);
            $contributor_created_date = !empty($event['contributor_created_date']) 
                ? sanitize_text_field($event['contributor_created_date']) 
                : null;
            $event_created_date = !empty($event['event_created_date']) 
                ? sanitize_text_field($event['event_created_date']) 
                : null;
            $event_data = null;
            if (!empty($event['event_data'])) {
                $event_data = is_string($event['event_data']) 
                    ? $event['event_data'] 
                    : wp_json_encode($event['event_data']);
            }

            $placeholders_list[] = '(%d, %d, %s, %s, %s, %s)';
            $values_list[] = $event_id;
            $values_list[] = $contributor_id;
            $values_list[] = $event_type;
            $values_list[] = $contributor_created_date;
            $values_list[] = $event_created_date;
            $values_list[] = $event_data;
        }

        $placeholders_sql = implode(', ', $placeholders_list);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, placeholders are controlled
        $query = $wpdb->prepare(
            "INSERT IGNORE INTO $table_name 
             (event_id, contributor_id, event_type, contributor_created_date, event_created_date, event_data) 
             VALUES $placeholders_sql",
            $values_list
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $result = $wpdb->query($query);

        if ($result === false) {
            $results['errors'][] = array(
                'event_id' => 'batch',
                'error' => 'Batch insert failed: ' . $wpdb->last_error,
            );
        } else {
            $results['imported'] += $wpdb->rows_affected;
            $results['skipped'] += count($chunk) - $wpdb->rows_affected;
        }
    }

    return $results;
}

/**
 * Import multiple events at once
 * 
 * Uses bulk insert for performance (10-50x faster than single inserts).
 * 
 * @param array $events Array of event arrays
 * @param array $options Optional settings:
 *                       - auto_create_event_types: bool (default true)
 *                       - batch_size: int (default 500)
 * @return array Results with 'imported', 'skipped', and 'errors' counts
 */
function wporgcd_import_events($events, $options = array()) {
    $defaults = array(
        'auto_create_event_types' => true,
        'batch_size' => 500,
    );
    $options = wp_parse_args($options, $defaults);

    // Collect all unique event types from the events before inserting
    if ($options['auto_create_event_types']) {
        $event_types = wporgcd_get_event_types();
        $new_event_types = array();

        foreach ($events as $event) {
            if (!empty($event['event_type'])) {
                $event_type = sanitize_key($event['event_type']);
                if (!isset($event_types[$event_type]) && !isset($new_event_types[$event_type])) {
                    $new_event_types[$event_type] = array(
                        'title' => ucwords(str_replace('_', ' ', $event_type))
                    );
                }
            }
        }

        // Save new event types
        if (!empty($new_event_types)) {
            $event_types = array_merge($event_types, $new_event_types);
            update_option('wporgcd_event_types', $event_types);
        }
    }

    // Use bulk insert for the actual insertion
    return wporgcd_bulk_insert_events($events, $options['batch_size']);
}
