<?php
/**
 * Plugin configuration: event types and ladders.
 *
 * Single source of truth for the event-type registry and the ladder
 * progression. Both functions return plain arrays in declaration order;
 * ladders are evaluated top-to-bottom.
 *
 * Edit the arrays below to add, rename, or remove entries. After changing
 * ladders, regenerate profiles from the Profiles admin page so the journey
 * reflects the new definitions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get configured event types.
 *
 * @return array Event types keyed by ID.
 */
function wporgcd_get_event_types() {
    return [
        'forum_reply_create'              => [ 'title' => 'Forum Reply Create' ],
        'glotpress_translation_approved'  => [ 'title' => 'GlotPress Translation Approved' ],
        'forum_topic_create'              => [ 'title' => 'Forum Topic Create' ],
        'updated_profile'                 => [ 'title' => 'Updated Profile' ],
        'glotpress_translation_suggested' => [ 'title' => 'GlotPress Translation Suggested' ],
        'learn_course_complete'           => [ 'title' => 'Learn Course Complete' ],
        'blog_comment_create'             => [ 'title' => 'Blog Comment Create' ],
        'blog_post_create'                => [ 'title' => 'Blog Post Create' ],
        'blog_handbook_update'            => [ 'title' => 'Blog Handbook Update' ],
        'wordcamp_attendee_add'           => [ 'title' => 'WordCamp Attendee Add' ],
        'glotpress_translation_reviewed'  => [ 'title' => 'GlotPress Translation Reviewed' ],
        'wordcamp_attendee_checked_in'    => [ 'title' => 'WordCamp Attendee Checked In' ],
        'wordcamp_organizer_add'          => [ 'title' => 'WordCamp Organizer Add' ],
        'wordcamp_speaker_add'            => [ 'title' => 'WordCamp Speaker Add' ],
        'wordcamp_mentor_assign'          => [ 'title' => 'WordCamp Mentor Assign' ],
        'slack_props_given'               => [ 'title' => 'Slack Props Given' ],
        'workshop_presenter_assign'       => [ 'title' => 'Workshop Presenter Assign' ],
        'commit'                          => [ 'title' => 'Commit' ],
        'review'                          => [ 'title' => 'Review' ],
        'plugin_review'                   => [ 'title' => 'Plugin Review' ],
        'activity_update'                 => [ 'title' => 'Activity Update' ],
        'github_issue_create'             => [ 'title' => 'Github Issue Create' ],
        'bbp_topic_create'                => [ 'title' => 'Bbp Topic Create' ],
        'bbp_reply_create'                => [ 'title' => 'Bbp Reply Create' ],
        'new_blog_comment'                => [ 'title' => 'New Blog Comment' ],
        'plugin_create'                   => [ 'title' => 'Plugin Create' ],
        'new_blog_post'                   => [ 'title' => 'New Blog Post' ],
        'bpc_page_edit'                   => [ 'title' => 'Bpc Page Edit' ],
        'activity_comment'                => [ 'title' => 'Activity Comment' ],
        'theme_create'                    => [ 'title' => 'Theme Create' ],
        'test_activity'                   => [ 'title' => 'Test Activity' ],
    ];
}

/**
 * Get configured ladders.
 *
 * @return array Ladders keyed by ID, in evaluation order.
 */
function wporgcd_get_ladders() {
    return [
        'step0' => [
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
        'step1' => [
            'title'        => 'Contribute',
            'requirements' => [
                [ 'event_type' => 'forum_reply_create',              'min' => 10 ],
                [ 'event_type' => 'glotpress_translation_suggested', 'min' => 5 ],
                [ 'event_type' => 'learn_course_complete',           'min' => 2 ],
                [ 'event_type' => 'blog_comment_create',             'min' => 2 ],
            ],
        ],
        'step2' => [
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
