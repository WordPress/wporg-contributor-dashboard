<?php
/**
 * Combined Admin Page
 *
 * Single top-level admin page that links to the public dashboard,
 * exposes profile generation controls, and lists recent events
 * received in the last 30 days (useful for verifying that webhooks
 * are flowing).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPORGCD_RECENT_EVENTS_LIMIT = 50;

add_action( 'admin_menu', 'wporgcd_register_admin_menu' );
add_action( 'admin_init', 'wporgcd_handle_profile_reset' );

function wporgcd_register_admin_menu() {
	add_menu_page(
		'Contributors',
		'Contributors',
		'manage_options',
		'contributor-dashboard',
		'wporgcd_render_admin_page',
		'dashicons-groups',
		30
	);
}

function wporgcd_handle_profile_reset() {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'contributor-dashboard' ) {
		return;
	}

	if ( isset( $_POST['wporgcd_reset_state'] ) && check_admin_referer( 'wporgcd_profiles_nonce' ) ) {
		wporgcd_reset_profile_generation();
		wp_safe_redirect( admin_url( 'admin.php?page=contributor-dashboard' ) );
		exit;
	}
}

function wporgcd_render_admin_page() {
	$message = '';

	if ( isset( $_POST['wporgcd_start_profiles'] ) && check_admin_referer( 'wporgcd_profiles_nonce' ) ) {
		// Delete all existing profiles first to ensure a clean regeneration
		wporgcd_delete_all_profiles();

		$result = wporgcd_start_profile_generation();
		if ( $result['success'] ) {
			$message = '<div class="notice notice-success"><p>Profile generation started! Processing ' . number_format( $result['profiles_needing_update'] ) . ' profiles.</p></div>';
		}
	}

	if ( isset( $_POST['wporgcd_stop_profiles'] ) && check_admin_referer( 'wporgcd_profiles_nonce' ) ) {
		wporgcd_stop_profile_generation();
		$message = '<div class="notice notice-warning"><p>Profile generation stopped.</p></div>';
	}

	$generation_status = wporgcd_get_profile_generation_status();
	?>
	<div class="wrap">
		<h1>Contributor Dashboard</h1>

		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message contains safe HTML from this function
		echo $message;
		?>

		<p>
			<a href="<?php echo esc_url( home_url() ); ?>" class="button button-primary" target="_blank">View Dashboard &rarr;</a>
		</p>

		<h2>Profile Generation</h2>
		<div style="background: #fff; border: 1px solid #ddd; padding: 20px; max-width: 600px;">

			<?php if ( $generation_status['is_running'] ) : ?>
				<h3 style="margin-top: 0;">Generation in Progress</h3>

				<div style="background: #ddd; border-radius: 4px; height: 24px; overflow: hidden; margin: 15px 0;">
					<div style="background: #0073aa; height: 100%; width: <?php echo esc_attr( $generation_status['progress'] ); ?>%;"></div>
				</div>

				<p>
					<strong><?php echo esc_html( $generation_status['progress'] ); ?>%</strong> complete
					(<?php echo number_format( $generation_status['processed'] ); ?> / <?php echo number_format( $generation_status['total_to_process'] ); ?>)
				</p>

				<p style="margin-top: 20px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=contributor-dashboard' ) ); ?>" class="button">Refresh</a>
					<form method="post" style="display: inline;">
						<?php wp_nonce_field( 'wporgcd_profiles_nonce' ); ?>
						<button type="submit" name="wporgcd_stop_profiles" class="button" style="color: #a00;" onclick="return confirm('Stop?')">Stop</button>
					</form>
				</p>

			<?php elseif ( $generation_status['status'] === 'completed' ) : ?>
				<h3 style="margin-top: 0; color: #46b450;">Complete</h3>

				<p>Processed <strong><?php echo number_format( $generation_status['processed'] ); ?></strong> profiles.</p>

				<form method="post">
					<?php wp_nonce_field( 'wporgcd_profiles_nonce' ); ?>
					<button type="submit" name="wporgcd_reset_state" class="button button-primary">Generate Again</button>
				</form>

			<?php else : ?>
				<p class="description">Deletes all existing profiles and regenerates them from events. Status (active/warning/inactive) is calculated based on last activity date.</p>

				<form method="post" style="margin-top: 20px;" onsubmit="return confirm('This will delete all existing profiles and regenerate them. Continue?');">
					<?php wp_nonce_field( 'wporgcd_profiles_nonce' ); ?>
					<button type="submit" name="wporgcd_start_profiles" class="button button-primary">Start Generation</button>
				</form>
			<?php endif; ?>

		</div>

		<h2 style="margin-top: 40px;">Recent Events (last 30 days)</h2>
		<?php wporgcd_render_recent_events_section(); ?>
	</div>
	<?php
}

/**
 * Render the "events from the last 30 days" table.
 *
 * The 30-day window is anchored to the reference end date (newest known
 * event date), matching how the rest of the plugin computes time ranges.
 */
function wporgcd_render_recent_events_section() {
	global $wpdb;
	$events_table = wporgcd_get_table( 'events' );

	$reference_end = wporgcd_get_reference_end_date();
	$cutoff        = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', strtotime( $reference_end ) ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events_table WHERE event_created_date >= %s",
		$cutoff
	) );

	if ( $total === 0 ) {
		echo '<div class="notice notice-info inline"><p>No events recorded in the last 30 days.</p></div>';
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT event_type, contributor_id, event_created_date
		 FROM $events_table
		 WHERE event_created_date >= %s
		 ORDER BY event_created_date DESC
		 LIMIT %d",
		$cutoff,
		WPORGCD_RECENT_EVENTS_LIMIT
	) );

	$event_types = wporgcd_get_event_types();
	$shown       = count( $rows );
	?>
	<p class="description">
		Showing the latest <?php echo number_format( $shown ); ?> of <?php echo number_format( $total ); ?> events received in the last 30 days.
		Range anchored to reference date <code><?php echo esc_html( $reference_end ); ?></code>.
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width: 35%;">Event Type</th>
				<th style="width: 25%;">Contributor ID</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) :
				$title = isset( $event_types[ $row->event_type ] ) ? $event_types[ $row->event_type ]['title'] : $row->event_type;
				?>
				<tr>
					<td>
						<?php echo esc_html( $title ); ?>
						<br><code><?php echo esc_html( $row->event_type ); ?></code>
					</td>
					<td><?php echo esc_html( $row->contributor_id ); ?></td>
					<td><?php echo esc_html( $row->event_created_date ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
