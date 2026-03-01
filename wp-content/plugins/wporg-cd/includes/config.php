<?php
/**
 * Configuration Helpers
 * 
 * Functions for retrieving plugin configuration options.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get configured event types
 * 
 * @return array Event types keyed by ID
 */
function wporgcd_get_event_types() {
    return get_option('wporgcd_event_types', array());
}

/**
 * Get configured ladders
 * 
 * @return array Ladders keyed by ID
 */
function wporgcd_get_ladders() {
    return get_option('wporgcd_ladders', array());
}
