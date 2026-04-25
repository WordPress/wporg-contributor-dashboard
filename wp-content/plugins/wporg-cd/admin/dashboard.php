<?php
/**
 * Combined Admin Page
 *
 * Single top-level admin page that links to the public dashboard and lists
 * recent events received in the last 30 days (useful for verifying that
 * webhooks are flowing).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPORGCD_RECENT_EVENTS_LIMIT = 50;

add_action( 'admin_menu', 'wporgcd_register_admin_menu' );

/**
 * Register the top-level "Contributors" admin menu page.
 */
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

/**
 * Render the top-level admin page (link to public dashboard + recent events).
 */
function wporgcd_render_admin_page() {
	?>
	<div class="wrap">
		<h1>Contributor Dashboard</h1>

		<p>
			<a href="<?php echo esc_url( home_url() ); ?>" class="button button-primary" target="_blank">View Dashboard &rarr;</a>
		</p>

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

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $events_table WHERE event_created_date >= %s",
			$cutoff
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $total === 0 ) {
		echo '<div class="notice notice-info inline"><p>No events recorded in the last 30 days.</p></div>';
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe from wporgcd_get_table()
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_type, contributor_id, event_created_date
		 FROM $events_table
		 WHERE event_created_date >= %s
		 ORDER BY event_created_date DESC
		 LIMIT %d",
			$cutoff,
			WPORGCD_RECENT_EVENTS_LIMIT
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
			<?php
			foreach ( $rows as $row ) :
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
