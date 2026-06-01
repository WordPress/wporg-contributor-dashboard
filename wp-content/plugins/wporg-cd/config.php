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

// Hard floor (YYYY-MM-DD) for the dashboard's queryable window. Events
// older than this are excluded from every view — date pickers won't let
// users go further back, the wrapped year picker hides earlier years, etc.
// Edit this if you want to expose more (or less) historical data.
define( 'WPORGCD_REFERENCE_START_DATE', '2019-01-01' );

/**
 * Get configured event types.
 *
 * @return array Event types keyed by ID.
 */
function wporgcd_get_event_types() {
	return array(
		'forum_reply_create'              => array( 'title' => 'Forum Reply Posted' ),
		'glotpress_translation_approved'  => array( 'title' => 'Translation Approved' ),
		'forum_topic_create'              => array( 'title' => 'Forum Topic Started' ),
		'updated_profile'                 => array( 'title' => 'Profile Updated' ),
		'glotpress_translation_suggested' => array( 'title' => 'Translation Suggested' ),
		'learn_course_complete'           => array( 'title' => 'Course Completed' ),
		'blog_comment_create'             => array( 'title' => 'Blog Comment Posted' ),
		'blog_post_create'                => array( 'title' => 'Blog Post Published' ),
		'blog_handbook_update'            => array( 'title' => 'Handbook Updated' ),
		'wordcamp_attendee_add'           => array( 'title' => 'Registered for WordCamp' ),
		'glotpress_translation_reviewed'  => array( 'title' => 'Translation Reviewed' ),
		'wordcamp_attendee_checked_in'    => array( 'title' => 'Checked In at WordCamp' ),
		'wordcamp_organizer_add'          => array( 'title' => 'Joined WordCamp as Organizer' ),
		'wordcamp_speaker_add'            => array( 'title' => 'Spoke at WordCamp' ),
		'wordcamp_mentor_assign'          => array( 'title' => 'Mentored at WordCamp' ),
		'slack_props_given'               => array( 'title' => 'Gave Slack Props' ),
		'workshop_presenter_assign'       => array( 'title' => 'Presented Workshop' ),
		'commit'                          => array( 'title' => 'Code Committed' ),
		'review'                          => array( 'title' => 'Code Reviewed' ),
		'plugin_review'                   => array( 'title' => 'Plugin Reviewed' ),
		'activity_update'                 => array( 'title' => 'Activity Updated' ),
		'github_issue_create'             => array( 'title' => 'GitHub Issue Opened' ),
		'bbp_topic_create'                => array( 'title' => 'bbPress Topic Started' ),
		'bbp_reply_create'                => array( 'title' => 'bbPress Reply Posted' ),
		'new_blog_comment'                => array( 'title' => 'New Blog Comment' ),
		'plugin_create'                   => array( 'title' => 'Plugin Submitted' ),
		'new_blog_post'                   => array( 'title' => 'New Blog Post' ),
		'bpc_page_edit'                   => array( 'title' => 'BPC Page Edited' ),
		'activity_comment'                => array( 'title' => 'Activity Comment Added' ),
		'theme_create'                    => array( 'title' => 'Theme Submitted' ),
		'test_activity'                   => array( 'title' => 'Test Activity' ),
	);
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
	return array(
		'updated_profile',
	);
}

/**
 * Build an event_type WHERE fragment for use in analytics queries.
 *
 * Returns a prepared SQL snippet of the form `event_type IN (%s, %s, ...)`
 * built from the registered event types in wporgcd_get_event_types() minus the
 * slugs in wporgcd_get_excluded_event_types() and the optional per-request
 * `$extra_excluded` list (used by the "Exclude event types" filter).
 * Designed to be appended directly into a WHERE clause without further
 * preparation.
 *
 * Index-friendly by construction: positive `IN (...)` predicates can use B-tree
 * indexes leading with event_type, while negated forms (`!=`, `NOT IN`) cannot.
 *
 * Returns `1=0` if every registered type happens to be excluded, so the caller
 * still produces a valid WHERE clause (and yields zero rows, matching intent).
 *
 * @param string[] $extra_excluded Additional event_type slugs to exclude on top of
 *                                 the global `wporgcd_get_excluded_event_types()` list.
 *                                 Typically the resolved value of the
 *                                 `exclude_event_types` filter.
 * @return string Prepared SQL fragment, e.g. "event_type IN ('a','b','c')".
 */
function wporgcd_get_event_type_filter_sql( $extra_excluded = array() ) {
	global $wpdb;

	$excluded = array_unique(
		array_merge(
			wporgcd_get_excluded_event_types(),
			array_map( 'strval', (array) $extra_excluded )
		)
	);

	$allowed = array_values(
		array_diff(
			array_keys( wporgcd_get_event_types() ),
			$excluded
		)
	);

	if ( empty( $allowed ) ) {
		return '1=0';
	}

	$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is built from a fixed-size array of '%s' tokens; the array IS the placeholder list.
	return $wpdb->prepare( "event_type IN ($placeholders)", $allowed );
}

/**
 * Build a `contributor_id IN (...)` WHERE fragment that limits to contributors
 * whose first matching event was the supplied $event_type.
 *
 * "First" is determined globally per contributor (subject to the standard
 * event-type allow-list and the cap-date) so the filter is a stable
 * per-contributor attribute — switching the registered_date or
 * contribution_date filter doesn't change a contributor's first event.
 * The inner derived table picks the smallest event_created_date per
 * contributor; the outer join keeps the row(s) on that minimum date and
 * the outer WHERE matches event_type. Implemented without window
 * functions so the predicate works on every MySQL version supported by WP.
 *
 * @param string   $events_table   Whitelisted events table name (from wporgcd_get_table()).
 * @param string   $event_type     Resolved first-event-type slug.
 * @param string   $cap_date       Yesterday-in-UTC cap (from wporgcd_get_query_cap_date()).
 * @param string[] $extra_excluded Optional per-request exclusion list (mirrors
 *                                  wporgcd_get_event_type_filter_sql()'s arg).
 * @return string Prepared SQL fragment, e.g. "contributor_id IN ( SELECT ... )".
 */
function wporgcd_get_first_event_type_filter_sql( $events_table, $event_type, $cap_date, $extra_excluded = array() ) {
	global $wpdb;

	$allowed_sql = wporgcd_get_event_type_filter_sql( $extra_excluded );
	$cap_clause  = $wpdb->prepare( 'event_created_date <= %s', $cap_date );
	$type_clause = $wpdb->prepare( 'e1.event_type = %s', $event_type );

	// Each interpolated fragment is itself a $wpdb->prepare() result, so this
	// is a literal SQL string by the time it returns (no further preparation
	// needed by callers — paste it straight into a WHERE clause).
	return "contributor_id IN (
		SELECT e1.contributor_id
		FROM $events_table e1
		INNER JOIN (
			SELECT contributor_id, MIN(event_created_date) AS min_date
			FROM $events_table
			WHERE $allowed_sql AND $cap_clause
			GROUP BY contributor_id
		) e2 ON e1.contributor_id = e2.contributor_id AND e1.event_created_date = e2.min_date
		WHERE $type_clause
	)";
}

/**
 * Get the default ladder definition.
 *
 * Single source of truth for the canonical ladder shipped with the plugin.
 * The active ladder for any given request is resolved by wporgcd_get_ladders()
 * in includes/ladders.php — that function returns the URL-supplied custom
 * ladder when present (and valid), or this default otherwise.
 *
 * @return array Ladders keyed by ID, in evaluation order.
 */
function wporgcd_get_default_ladders() {
	return array(
		'connect'    => array(
			'title'        => 'Connect',
			'requirements' => array(
				array(
					'event_type' => 'forum_reply_create',
					'min'        => 3,
				),
				array(
					'event_type' => 'forum_topic_create',
					'min'        => 1,
				),
				array(
					'event_type' => 'glotpress_translation_suggested',
					'min'        => 5,
				),
				array(
					'event_type' => 'learn_course_complete',
					'min'        => 1,
				),
				array(
					'event_type' => 'blog_comment_create',
					'min'        => 3,
				),
				array(
					'event_type' => 'wordcamp_attendee_add',
					'min'        => 1,
				),
				array(
					'event_type' => 'wordcamp_attendee_checked_in',
					'min'        => 1,
				),
				array(
					'event_type' => 'slack_props_given',
					'min'        => 2,
				),
			),
		),
		'contribute' => array(
			'title'        => 'Contribute',
			'requirements' => array(
				array(
					'event_type' => 'forum_reply_create',
					'min'        => 15,
				),
				array(
					'event_type' => 'forum_topic_create',
					'min'        => 3,
				),
				array(
					'event_type' => 'glotpress_translation_suggested',
					'min'        => 25,
				),
				array(
					'event_type' => 'glotpress_translation_approved',
					'min'        => 10,
				),
				array(
					'event_type' => 'glotpress_translation_reviewed',
					'min'        => 5,
				),
				array(
					'event_type' => 'learn_course_complete',
					'min'        => 3,
				),
				array(
					'event_type' => 'blog_comment_create',
					'min'        => 10,
				),
				array(
					'event_type' => 'blog_post_create',
					'min'        => 1,
				),
				array(
					'event_type' => 'blog_handbook_update',
					'min'        => 1,
				),
			),
		),
		'engage'     => array(
			'title'        => 'Engage',
			'requirements' => array(
				array(
					'event_type' => 'forum_reply_create',
					'min'        => 50,
				),
				array(
					'event_type' => 'glotpress_translation_approved',
					'min'        => 75,
				),
				array(
					'event_type' => 'glotpress_translation_reviewed',
					'min'        => 25,
				),
				array(
					'event_type' => 'blog_post_create',
					'min'        => 3,
				),
				array(
					'event_type' => 'blog_handbook_update',
					'min'        => 5,
				),
				array(
					'event_type' => 'blog_comment_create',
					'min'        => 30,
				),
				array(
					'event_type' => 'wordcamp_speaker_add',
					'min'        => 1,
				),
				array(
					'event_type' => 'slack_props_given',
					'min'        => 10,
				),
			),
		),
		'perform'    => array(
			'title'        => 'Perform',
			'requirements' => array(
				array(
					'event_type' => 'forum_reply_create',
					'min'        => 200,
				),
				array(
					'event_type' => 'glotpress_translation_approved',
					'min'        => 300,
				),
				array(
					'event_type' => 'glotpress_translation_reviewed',
					'min'        => 100,
				),
				array(
					'event_type' => 'blog_post_create',
					'min'        => 10,
				),
				array(
					'event_type' => 'blog_handbook_update',
					'min'        => 15,
				),
				array(
					'event_type' => 'wordcamp_speaker_add',
					'min'        => 2,
				),
				array(
					'event_type' => 'workshop_presenter_assign',
					'min'        => 1,
				),
				array(
					'event_type' => 'wordcamp_mentor_assign',
					'min'        => 1,
				),
			),
		),
	);
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
