<?php
/**
 * Background Queue Processor
 * 
 * Replaces WP-Cron with a heartbeat-based queue system.
 * Processes pending work every second while any user has a page open.
 * 
 * Other modules hook in via:
 *   - wporgcd_process_queue action (to process their work)
 *   - wporgcd_has_pending_work filter (to report pending work)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register AJAX endpoint (all users)
add_action( 'wp_ajax_wporgcd_heartbeat', 'wporgcd_ajax_heartbeat' );
add_action( 'wp_ajax_nopriv_wporgcd_heartbeat', 'wporgcd_ajax_heartbeat' );

// Output heartbeat script in footer
add_action( 'admin_footer', 'wporgcd_output_heartbeat_script' );
add_action( 'wp_footer', 'wporgcd_output_heartbeat_script' );

/**
 * AJAX endpoint for heartbeat
 */
function wporgcd_ajax_heartbeat() {
	wporgcd_maybe_process_queue();
	wp_send_json( array(
		'has_work' => wporgcd_has_pending_work(),
	) );
}

/**
 * Process pending work (with rate limiting)
 */
function wporgcd_maybe_process_queue() {
	$last_run = get_transient( 'wporgcd_queue_last_run' );
	
	if ( $last_run && ( microtime( true ) - $last_run ) < 1 ) {
		return;
	}
	
	set_transient( 'wporgcd_queue_last_run', microtime( true ), 60 );
	
	do_action( 'wporgcd_process_queue' );
}

/**
 * Check if there's pending work
 * 
 * @return bool
 */
function wporgcd_has_pending_work() {
	return apply_filters( 'wporgcd_has_pending_work', false );
}

/**
 * Output inline heartbeat script (only when there's work)
 */
function wporgcd_output_heartbeat_script() {
	if ( ! wporgcd_has_pending_work() ) {
		return;
	}
	
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>
	<script>
	(function(){
		var id = setInterval(function(){
			fetch('<?php echo esc_url( $ajax_url ); ?>?action=wporgcd_heartbeat', {
				method: 'POST',
				credentials: 'same-origin'
			})
			.then(function(r){ return r.json(); })
			.then(function(d){ if(!d.has_work) clearInterval(id); });
		}, 1000);
	})();
	</script>
	<?php
}
