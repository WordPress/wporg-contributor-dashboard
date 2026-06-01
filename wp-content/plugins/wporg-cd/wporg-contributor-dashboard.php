<?php
/**
 * Plugin Name: WordPress Contributor Dashboard
 * Description: Visualize and track WordPress contributor activity across the community
 * Version: 1.0.0
 * Author: WordPress.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bumped whenever the events table schema changes. Compared on plugins_loaded
// against the wporgcd_db_version option so existing installs pick up new keys
// (and drop removed ones) without needing a deactivate/reactivate cycle.
define( 'WPORGCD_DB_VERSION', '1.2.0' );

// Shared
require_once plugin_dir_path( __FILE__ ) . 'includes/database.php';
require_once plugin_dir_path( __FILE__ ) . 'config.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ladders.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/cache.php';

// Events
require_once plugin_dir_path( __FILE__ ) . 'includes/events/schema.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/events/import.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/events/reference.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/events/rest-api.php';

// Admin
require_once plugin_dir_path( __FILE__ ) . 'admin/dashboard.php';

// Frontend
require_once plugin_dir_path( __FILE__ ) . 'frontend/dashboard.php';
require_once plugin_dir_path( __FILE__ ) . 'frontend/views/wrapped.php';
require_once plugin_dir_path( __FILE__ ) . 'frontend/views/ladder.php';
require_once plugin_dir_path( __FILE__ ) . 'frontend/views/onboarding.php';
require_once plugin_dir_path( __FILE__ ) . 'frontend/views/cohorts.php';
require_once plugin_dir_path( __FILE__ ) . 'frontend/views/about.php';

// Activation
register_activation_hook( __FILE__, 'wporgcd_activate_plugin' );

/**
 * Plugin activation hook: create the events table and pin the DB version.
 */
function wporgcd_activate_plugin() {
	wporgcd_create_events_table();
	update_option( 'wporgcd_db_version', WPORGCD_DB_VERSION, false );
}

// Run schema migrations on every load (cheap when already up-to-date).
add_action( 'plugins_loaded', 'wporgcd_maybe_run_db_migrations' );
