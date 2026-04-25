<?php
/**
 * Plugin Name: WordPress Contributor Dashboard
 * Description: Visualize and track WordPress contributor activity across the community
 * Version: 1.0.0
 * Author: WordPress.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// Shared
require_once plugin_dir_path(__FILE__) . 'includes/database.php';
require_once plugin_dir_path(__FILE__) . 'config.php';

// Events
require_once plugin_dir_path(__FILE__) . 'includes/events/schema.php';
require_once plugin_dir_path(__FILE__) . 'includes/events/import.php';
require_once plugin_dir_path(__FILE__) . 'includes/events/reference.php';
require_once plugin_dir_path(__FILE__) . 'includes/events/rest-api.php';

// Admin
require_once plugin_dir_path(__FILE__) . 'admin/dashboard.php';

// Frontend
require_once plugin_dir_path(__FILE__) . 'frontend/dashboard.php';
require_once plugin_dir_path(__FILE__) . 'frontend/views/overview.php';
require_once plugin_dir_path(__FILE__) . 'frontend/views/ladder.php';
require_once plugin_dir_path(__FILE__) . 'frontend/views/cohorts.php';

// Activation
register_activation_hook(__FILE__, 'wporgcd_activate_plugin');

function wporgcd_activate_plugin() {
    wporgcd_create_events_table();
}

