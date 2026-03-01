<?php
/**
 * Plugin Name: Contributor Dashboard
 * Description: Store contributor events and assign them to contributor ladders
 * Version: 1.0.0
 * Author: WordPress.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// Shared
require_once plugin_dir_path(__FILE__) . 'includes/database.php';
require_once plugin_dir_path(__FILE__) . 'includes/config.php';
require_once plugin_dir_path(__FILE__) . 'includes/queue.php';

// Events
require_once plugin_dir_path(__FILE__) . 'includes/events/schema.php';
require_once plugin_dir_path(__FILE__) . 'includes/events/import.php';
require_once plugin_dir_path(__FILE__) . 'includes/events/reference.php';
require_once plugin_dir_path(__FILE__) . 'includes/events/rest-api.php';

// Profiles
require_once plugin_dir_path(__FILE__) . 'includes/profiles/schema.php';
require_once plugin_dir_path(__FILE__) . 'includes/profiles/compute.php';
require_once plugin_dir_path(__FILE__) . 'includes/profiles/filters.php';

// Admin
require_once plugin_dir_path(__FILE__) . 'admin/settings.php';
require_once plugin_dir_path(__FILE__) . 'admin/import.php';
require_once plugin_dir_path(__FILE__) . 'admin/profiles.php';

// Frontend
require_once plugin_dir_path(__FILE__) . 'frontend/dashboard.php';

// Activation
register_activation_hook(__FILE__, 'wporgcd_activate_plugin');

function wporgcd_activate_plugin() {
    wporgcd_create_events_table();
    wporgcd_create_profiles_table();
}

// Admin Menu
add_action('admin_menu', 'wporgcd_admin_menu');

function wporgcd_admin_menu() {
    add_menu_page('Contributors', 'Contributors', 'manage_options', 'contributor-dashboard', 'wporgcd_render_admin_dashboard', 'dashicons-groups', 30);
    add_submenu_page('contributor-dashboard', 'Event Types', 'Event Types', 'manage_options', 'contributor-event-types', 'wporgcd_render_event_types_page');
    add_submenu_page('contributor-dashboard', 'Ladders', 'Ladders', 'manage_options', 'contributor-ladders', 'wporgcd_render_ladders_page');
}

function wporgcd_render_admin_dashboard() {
    ?>
    <div class="wrap">
        <h1>Contributor Dashboard</h1>
        <p><a href="<?php echo esc_url( home_url() ); ?>" class="button button-primary" target="_blank">View Dashboard →</a></p>
    </div>
    <?php
}
