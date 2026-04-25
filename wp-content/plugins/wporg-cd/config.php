<?php
/**
 * Plugin configuration: event types, ladders, and shared status thresholds.
 *
 * Single source of truth for the event-type registry and the ladder
 * progression. Both functions return plain arrays in declaration order;
 * ladders are evaluated top-to-bottom. Edit the arrays below to add, rename,
 * or remove entries — changes take effect on the next page load since views
 * compute ladder placement live from the events table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activity-status thresholds (in days) shared across views.
define( 'WPORGCD_STATUS_ACTIVE_DAYS', 30 );
define( 'WPORGCD_STATUS_WARNING_DAYS', 90 );

/**
 * Get configured event types.
 *
 * @return array Event types keyed by ID.
 */
function wporgcd_get_event_types() {
    return [
        'forum_reply_create'              => [ 'title' => 'Forum Reply Posted' ],
        'glotpress_translation_approved'  => [ 'title' => 'Translation Approved' ],
        'forum_topic_create'              => [ 'title' => 'Forum Topic Started' ],
        'updated_profile'                 => [ 'title' => 'Profile Updated' ],
        'glotpress_translation_suggested' => [ 'title' => 'Translation Suggested' ],
        'learn_course_complete'           => [ 'title' => 'Course Completed' ],
        'blog_comment_create'             => [ 'title' => 'Blog Comment Posted' ],
        'blog_post_create'                => [ 'title' => 'Blog Post Published' ],
        'blog_handbook_update'            => [ 'title' => 'Handbook Updated' ],
        'wordcamp_attendee_add'           => [ 'title' => 'Registered for WordCamp' ],
        'glotpress_translation_reviewed'  => [ 'title' => 'Translation Reviewed' ],
        'wordcamp_attendee_checked_in'    => [ 'title' => 'Checked In at WordCamp' ],
        'wordcamp_organizer_add'          => [ 'title' => 'Joined WordCamp as Organizer' ],
        'wordcamp_speaker_add'            => [ 'title' => 'Spoke at WordCamp' ],
        'wordcamp_mentor_assign'          => [ 'title' => 'Mentored at WordCamp' ],
        'slack_props_given'               => [ 'title' => 'Gave Slack Props' ],
        'workshop_presenter_assign'       => [ 'title' => 'Presented Workshop' ],
        'commit'                          => [ 'title' => 'Code Committed' ],
        'review'                          => [ 'title' => 'Code Reviewed' ],
        'plugin_review'                   => [ 'title' => 'Plugin Reviewed' ],
        'activity_update'                 => [ 'title' => 'Activity Updated' ],
        'github_issue_create'             => [ 'title' => 'GitHub Issue Opened' ],
        'bbp_topic_create'                => [ 'title' => 'bbPress Topic Started' ],
        'bbp_reply_create'                => [ 'title' => 'bbPress Reply Posted' ],
        'new_blog_comment'                => [ 'title' => 'New Blog Comment' ],
        'plugin_create'                   => [ 'title' => 'Plugin Submitted' ],
        'new_blog_post'                   => [ 'title' => 'New Blog Post' ],
        'bpc_page_edit'                   => [ 'title' => 'BPC Page Edited' ],
        'activity_comment'                => [ 'title' => 'Activity Comment Added' ],
        'theme_create'                    => [ 'title' => 'Theme Submitted' ],
        'test_activity'                   => [ 'title' => 'Test Activity' ],
    ];
}

/**
 * Get event types that should be ignored by analytics views.
 *
 * Listed types are still imported and stored, but every analytics query built
 * via wporgcd_get_event_type_filter_sql() filters them out. Use this for noise
 * (e.g. auto-generated profile updates on login) that would otherwise distort
 * engagement stats.
 *
 * @return array List of event_type slugs to exclude from views.
 */
function wporgcd_get_excluded_event_types() {
    return [
        'updated_profile',
    ];
}

/**
 * Build an event_type WHERE fragment for use in analytics queries.
 *
 * Returns a prepared SQL snippet of the form `event_type IN (%s, %s, ...)`
 * built from the registered event types in wporgcd_get_event_types() minus the
 * slugs in wporgcd_get_excluded_event_types(). Designed to be appended directly
 * into a WHERE clause without further preparation.
 *
 * Index-friendly by construction: positive `IN (...)` predicates can use B-tree
 * indexes leading with event_type, while negated forms (`!=`, `NOT IN`) cannot.
 *
 * Returns `1=0` if every registered type happens to be excluded, so the caller
 * still produces a valid WHERE clause (and yields zero rows, matching intent).
 *
 * @return string Prepared SQL fragment, e.g. "event_type IN ('a','b','c')".
 */
function wporgcd_get_event_type_filter_sql() {
    global $wpdb;

    $allowed = array_values( array_diff(
        array_keys( wporgcd_get_event_types() ),
        wporgcd_get_excluded_event_types()
    ) );

    if ( empty( $allowed ) ) {
        return '1=0';
    }

    $placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is built from a fixed-size array of '%s' tokens
    return $wpdb->prepare( "event_type IN ($placeholders)", $allowed );
}

/**
 * Get configured ladders.
 *
 * @return array Ladders keyed by ID, in evaluation order.
 */
function wporgcd_get_ladders() {
    return [
        'connect' => [
            'title'        => 'Connect',
            'requirements' => [
                [ 'event_type' => 'forum_reply_create',              'min' => 1 ],
                [ 'event_type' => 'glotpress_translation_approved',  'min' => 1 ],
                [ 'event_type' => 'forum_topic_create',              'min' => 1 ],
                [ 'event_type' => 'glotpress_translation_suggested', 'min' => 1 ],
                [ 'event_type' => 'learn_course_complete',           'min' => 1 ],
                [ 'event_type' => 'blog_comment_create',             'min' => 1 ],
                [ 'event_type' => 'blog_post_create',                'min' => 1 ],
                [ 'event_type' => 'blog_handbook_update',            'min' => 1 ],
                [ 'event_type' => 'wordcamp_attendee_add',           'min' => 1 ],
                [ 'event_type' => 'glotpress_translation_reviewed',  'min' => 1 ],
                [ 'event_type' => 'wordcamp_attendee_checked_in',    'min' => 1 ],
                [ 'event_type' => 'wordcamp_organizer_add',          'min' => 1 ],
                [ 'event_type' => 'wordcamp_speaker_add',            'min' => 1 ],
                [ 'event_type' => 'wordcamp_mentor_assign',          'min' => 1 ],
                [ 'event_type' => 'slack_props_given',               'min' => 1 ],
                [ 'event_type' => 'workshop_presenter_assign',       'min' => 1 ],
            ],
        ],
        'contribute' => [
            'title'        => 'Contribute',
            'requirements' => [
                [ 'event_type' => 'forum_reply_create',              'min' => 10 ],
                [ 'event_type' => 'glotpress_translation_suggested', 'min' => 5 ],
                [ 'event_type' => 'learn_course_complete',           'min' => 2 ],
                [ 'event_type' => 'blog_comment_create',             'min' => 2 ],
            ],
        ],
        'engage' => [
            'title'        => 'Engage',
            'requirements' => [
                [ 'event_type' => 'glotpress_translation_approved',  'min' => 5 ],
                [ 'event_type' => 'blog_post_create',                'min' => 3 ],
                [ 'event_type' => 'blog_comment_create',             'min' => 20 ],
                [ 'event_type' => 'glotpress_translation_reviewed',  'min' => 2 ],
                [ 'event_type' => 'wordcamp_speaker_add',            'min' => 1 ],
                [ 'event_type' => 'slack_props_given',               'min' => 2 ],
            ],
        ],
    ];
}

/**
 * Check if ladder requirements are met.
 *
 * Returns the first matched requirement (any-of semantics) or false.
 *
 * @param array $ladder Ladder configuration (with a `requirements` array).
 * @param array $counts Event counts keyed by event_type.
 * @return array|false  The met requirement (with `event_type`, `min`, `achieved`) or false.
 */
function wporgcd_check_ladder_requirements( $ladder, $counts ) {
    if ( empty( $ladder['requirements'] ) ) {
        return false;
    }

    foreach ( $ladder['requirements'] as $req ) {
        $event_type = $req['event_type'];
        $min        = $req['min'];

        if ( isset( $counts[ $event_type ] ) && $counts[ $event_type ] >= $min ) {
            return array(
                'event_type' => $event_type,
                'min'        => $min,
                'achieved'   => $counts[ $event_type ],
            );
        }
    }

    return false;
}
