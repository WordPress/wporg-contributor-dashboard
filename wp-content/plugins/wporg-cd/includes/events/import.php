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
    $event_id = sanitize_text_field($event['event_id']);
    $contributor_id = sanitize_text_field($event['contributor_id']);
    $event_type = sanitize_key($event['event_type']);

    // Build column and value arrays for dynamic INSERT
    $columns = array('event_id', 'contributor_id', 'event_type');
    $values = array($event_id, $contributor_id, $event_type);
    $formats = array('%s', '%s', '%s');

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
 * Import multiple events at once
 * 
 * @param array $events Array of event arrays
 * @param array $options Optional settings:
 *                       - auto_create_event_types: bool (default true)
 * @return array Results with 'imported', 'skipped', and 'errors' counts
 */
function wporgcd_import_events($events, $options = array()) {
    $defaults = array(
        'auto_create_event_types' => true,
    );
    $options = wp_parse_args($options, $defaults);

    $results = array(
        'imported' => 0,
        'skipped' => 0,
        'errors' => array(),
    );

    $event_types = wporgcd_get_event_types();
    $new_event_types = array();

    foreach ($events as $event) {
        // Validate
        $valid = wporgcd_validate_event($event);
        if (is_wp_error($valid)) {
            $results['errors'][] = array(
                'event_id' => $event['event_id'] ?? 'unknown',
                'error' => $valid->get_error_message(),
            );
            continue;
        }

        // Insert
        $result = wporgcd_insert_event($event);

        if (is_wp_error($result)) {
            $results['errors'][] = array(
                'event_id' => $event['event_id'],
                'error' => $result->get_error_message(),
            );
        } elseif ($result === 'inserted') {
            $results['imported']++;

            // Track new event types
            $event_type = sanitize_key($event['event_type']);
            if ($options['auto_create_event_types'] && !isset($event_types[$event_type]) && !isset($new_event_types[$event_type])) {
                $new_event_types[$event_type] = array(
                    'title' => ucwords(str_replace('_', ' ', $event_type))
                );
            }
        } else {
            $results['skipped']++;
        }
    }

    // Save new event types
    if (!empty($new_event_types)) {
        $event_types = array_merge($event_types, $new_event_types);
        update_option('wporgcd_event_types', $event_types);
    }

    return $results;
}
