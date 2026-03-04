<?php
/**
 * Profiles Table Schema
 * 
 * Database setup for contributor profiles.
 */

if (!defined('ABSPATH')) exit;

/**
 * Create the wporgcd_profiles table
 */
function wporgcd_create_profiles_table() {
    global $wpdb;
    $table_name = wporgcd_get_table('profiles');
    $charset_collate = $wpdb->get_charset_collate();

    // Profile structure:
    // - user_id: The contributor's WordPress.org user ID/username
    // - registered_date: When the user account was created on WP.org
    // - ladder_journey: JSON array tracking progression through ladder steps
    //   [
    //     {
    //       "ladder_id": "connect",
    //       "step_joined": "2024-01-15T10:30:00Z",    // When they reached this step
    //       "step_left": "2024-03-20T14:22:00Z",      // When they moved to next step (null if current)
    //       "time_in_step_days": 64,                   // Calculated days in this step
    //       "first_event_id": "support-reply-12345",   // First qualifying event
    //       "first_event_type": "support_reply",
    //       "first_event_date": "2024-01-15T10:30:00Z",
    //       "last_event_id": "support-reply-67890",    // Last event before moving
    //       "last_event_type": "support_reply",
    //       "last_event_date": "2024-03-20T14:22:00Z",
    //       "events_in_step": 47,                      // Total events while in this step
    //       "requirement_met": {                       // Which requirement qualified them
    //         "event_type": "support_reply",
    //         "min": 5,
    //         "achieved": 5
    //       }
    //     },
    //     ...
    //   ]
    // - event_counts: JSON object with event type counts and dates
    //   {
    //     "support_reply": {
    //       "count": 47,
    //       "first_date": "2024-01-15T10:30:00Z",
    //       "last_date": "2024-06-15T09:15:00Z"
    //     },
    //     "trac_ticket": {
    //       "count": 12,
    //       "first_date": "2024-02-01T11:00:00Z",
    //       "last_date": "2024-05-20T16:45:00Z"
    //     }
    //   }
    // - current_ladder: The current/highest ladder step achieved
    // - total_events: Quick count of all events
    // - first_activity: Date of first recorded event
    // - last_activity: Date of most recent event
    // - status: Activity status based on last_activity
    //   - 'active': Last activity within 30 days
    //   - 'warning': Last activity 30-90 days ago
    //   - 'inactive': Last activity more than 90 days ago
    // - profile_computed_at: When this profile was last computed/updated

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        registered_date datetime DEFAULT NULL,
        ladder_journey longtext DEFAULT NULL,
        event_counts longtext DEFAULT NULL,
        current_ladder varchar(100) DEFAULT NULL,
        total_events int(11) DEFAULT 0,
        first_activity datetime DEFAULT NULL,
        last_activity datetime DEFAULT NULL,
        status varchar(20) DEFAULT 'inactive',
        profile_computed_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id),
        KEY current_ladder (current_ladder),
        KEY registered_date (registered_date),
        KEY last_activity (last_activity),
        KEY status (status),
        KEY profile_computed_at (profile_computed_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Create the profile generation queue table
 * 
 * Simple single-column table to store pending user IDs during
 * batch profile generation, avoiding PHP memory issues.
 */
function wporgcd_create_profile_queue_table() {
    global $wpdb;
    $table_name = wporgcd_get_profile_queue_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        user_id bigint(20) NOT NULL,
        PRIMARY KEY (user_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Get the profile queue table name
 * 
 * @return string Table name with prefix
 */
function wporgcd_get_profile_queue_table() {
    global $wpdb;
    return $wpdb->prefix . 'wporgcd_profile_queue';
}
