<?php
/**
 * Events REST API
 * 
 * REST endpoint for batch event imports from internal systems.
 */

if (!defined('ABSPATH')) exit;

// Register REST routes
add_action('rest_api_init', 'wporgcd_register_events_rest_routes');

/**
 * Register REST API routes
 */
function wporgcd_register_events_rest_routes() {
    register_rest_route('wporgcd/v1', '/events/import', array(
        'methods'  => 'POST',
        'callback' => 'wporgcd_rest_import_events',
        'permission_callback' => 'wporgcd_rest_import_permission',
        'args' => array(
            'events' => array(
                'required' => true,
                'type' => 'array',
                'description' => 'Array of events to import',
                'validate_callback' => 'wporgcd_rest_validate_events',
            ),
        ),
    ));
}

/**
 * Permission callback for import endpoint
 * 
 * Requires manage_options capability (WordPress Application Passwords work here)
 * 
 * @return bool|WP_Error
 */
function wporgcd_rest_import_permission() {
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to import events.',
            array('status' => 403)
        );
    }
    return true;
}

/**
 * Validate events array
 * 
 * @param array $events Events to validate
 * @return bool|WP_Error
 */
function wporgcd_rest_validate_events($events) {
    if (!is_array($events)) {
        return new WP_Error(
            'invalid_events',
            'Events must be an array.',
            array('status' => 400)
        );
    }

    $max_events = 5000;
    if (count($events) > $max_events) {
        return new WP_Error(
            'too_many_events',
            sprintf('Maximum %d events per request. Received %d.', $max_events, count($events)),
            array('status' => 400)
        );
    }

    if (empty($events)) {
        return new WP_Error(
            'empty_events',
            'Events array cannot be empty.',
            array('status' => 400)
        );
    }

    return true;
}

/**
 * Handle import request
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function wporgcd_rest_import_events($request) {
    $events = $request->get_param('events');

    // Import events synchronously
    $results = wporgcd_import_events($events);

    // Update reference dates after import
    wporgcd_set_reference_date_from_events();

    return new WP_REST_Response(array(
        'success' => true,
        'imported' => $results['imported'],
        'skipped' => $results['skipped'],
        'errors' => $results['errors'],
    ), 200);
}
