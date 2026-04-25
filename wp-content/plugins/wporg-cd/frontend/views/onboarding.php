<?php
/**
 * Onboarding View
 *
 * Registration-cohort metrics: time from account creation to first
 * contribution, activity status, one-time contributors, and the breakdown of
 * which event types onboard contributors. Filters by contributor_created_date
 * so each insight reflects contributors who registered in the selected window,
 * not just contributors who happened to be active in it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the onboarding view.
 *
 * @param array $filters Resolved filter values keyed by filter id. See wporgcd_resolve_filters().
 * @return string Rendered inner HTML (no <html>/<head>/layout wrapper).
 */
function wporgcd_render_onboarding_view( $filters ) {
	global $wpdb;
	$events_table  = wporgcd_get_table( 'events' );
	$event_types   = wporgcd_get_event_types();
	$reference_end = wporgcd_get_reference_end_date();

	$date_start       = isset( $filters['registered_date']['start'] ) ? $filters['registered_date']['start'] : null;
	$date_end         = isset( $filters['registered_date']['end'] ) ? $filters['registered_date']['end'] : null;
	$include_inactive = ! empty( $filters['include_inactive'] );

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	// $events_table comes from wporgcd_get_table() (internal whitelist) and every
	// dynamic value is bound via $wpdb->prepare() before being interpolated.
	// The event_type filter is sourced from wporgcd_get_event_type_filter_sql()
	// so the noise list (e.g. updated_profile) lives in config.php instead of
	// being hardcoded across views.
	$where = array( wporgcd_get_event_type_filter_sql() );
	if ( $date_start !== null ) {
		$where[] = $wpdb->prepare( 'contributor_created_date >= %s', $date_start );
	}
	if ( $date_end !== null ) {
		$where[] = $wpdb->prepare( 'contributor_created_date <= %s', $date_end );
	}
	$where_sql = implode( ' AND ', $where );

	// One aggregation query: per (contributor, event_type) row with first/last
	// activity dates and the contributor's registration date.
	$rows = $wpdb->get_results(
		"SELECT contributor_id,
                MIN(contributor_created_date) AS contributor_created_date,
                event_type,
                COUNT(*) AS cnt,
                MIN(event_created_date) AS first_type_date,
                MAX(event_created_date) AS last_type_date
         FROM $events_table
         WHERE $where_sql
         GROUP BY contributor_id, event_type"
	);

	// Roll up rows into a per-contributor map.
	$contributors = array();
	foreach ( $rows as $r ) {
		$cid = $r->contributor_id;
		if ( ! isset( $contributors[ $cid ] ) ) {
			$contributors[ $cid ] = array(
				'registered_date'  => $r->contributor_created_date,
				'total_events'     => 0,
				'first_activity'   => null,
				'last_activity'    => null,
				'event_counts'     => array(),
				'first_event_type' => null,
			);
		}
		$contributors[ $cid ]['total_events']                  += (int) $r->cnt;
		$contributors[ $cid ]['event_counts'][ $r->event_type ] = (int) $r->cnt;
		if ( $contributors[ $cid ]['first_activity'] === null || $r->first_type_date < $contributors[ $cid ]['first_activity'] ) {
			$contributors[ $cid ]['first_activity']   = $r->first_type_date;
			$contributors[ $cid ]['first_event_type'] = $r->event_type;
		}
		if ( $contributors[ $cid ]['last_activity'] === null || $r->last_type_date > $contributors[ $cid ]['last_activity'] ) {
			$contributors[ $cid ]['last_activity'] = $r->last_type_date;
		}
	}

	// Compute status per contributor (using the WPORGCD_STATUS_* thresholds
	// from config.php) and apply the include_inactive filter in PHP.
	$reference_time = strtotime( $reference_end );
	foreach ( $contributors as $cid => $c ) {
		$days = ( $reference_time - strtotime( $c['last_activity'] ) ) / DAY_IN_SECONDS;
		if ( $days <= WPORGCD_STATUS_ACTIVE_DAYS ) {
			$contributors[ $cid ]['status'] = 'active';
		} elseif ( $days <= WPORGCD_STATUS_WARNING_DAYS ) {
			$contributors[ $cid ]['status'] = 'warning';
		} else {
			$contributors[ $cid ]['status'] = 'inactive';
		}
	}
	if ( ! $include_inactive ) {
		$contributors = array_filter(
			$contributors,
			static function ( $c ) {
				return $c['status'] !== 'inactive';
			}
		);
	}

	// Aggregate stats from the filtered contributor map.
	$total_contributors    = count( $contributors );
	$total_events          = 0;
	$active_contributors   = 0;
	$warning_contributors  = 0;
	$inactive_contributors = 0;
	$single_event          = 0;
	$ten_plus_events       = 0;
	$event_distribution    = array();
	$first_event_counts    = array();
	$time_to_first_total   = 0.0;
	$time_to_first_count   = 0;
	$thirty_days_ago       = gmdate( 'Y-m-d', strtotime( $reference_end . ' -30 days' ) );
	$new_contributors_30d  = 0;

	foreach ( $contributors as $c ) {
		$total_events += $c['total_events'];

		switch ( $c['status'] ) {
			case 'active':
				++$active_contributors;
				break;
			case 'warning':
				++$warning_contributors;
				break;
			default:
				++$inactive_contributors;
				break;
		}

		if ( $c['total_events'] === 1 ) {
			++$single_event;
		}
		if ( $c['total_events'] > 10 ) {
			++$ten_plus_events;
		}

		foreach ( $c['event_counts'] as $type => $cnt ) {
			$event_distribution[ $type ] = ( $event_distribution[ $type ] ?? 0 ) + $cnt;
		}
		if ( $c['first_event_type'] !== null ) {
			$first_event_counts[ $c['first_event_type'] ] = ( $first_event_counts[ $c['first_event_type'] ] ?? 0 ) + 1;
		}

		// Same guard as the previous SQL: registered_date and first_activity
		// both present, and first_activity not earlier than registration.
		if ( ! empty( $c['registered_date'] ) && ! empty( $c['first_activity'] )
			&& strtotime( $c['first_activity'] ) >= strtotime( $c['registered_date'] ) ) {
			$time_to_first_total += ( strtotime( $c['first_activity'] ) - strtotime( $c['registered_date'] ) ) / DAY_IN_SECONDS;
			++$time_to_first_count;
		}

		if ( ! empty( $c['registered_date'] ) && $c['registered_date'] >= $thirty_days_ago ) {
			++$new_contributors_30d;
		}
	}

	arsort( $first_event_counts );
	$first_event_counts = array_slice( $first_event_counts, 0, 10, true );

	$avg_events        = $total_contributors > 0 ? $total_events / $total_contributors : 0;
	$avg_time_to_first = $time_to_first_count > 0 ? $time_to_first_total / $time_to_first_count : null;
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	ob_start();
	?>
	<section>
		<div class="grid-4">
			<div class="card stat">
				<div class="stat-val blue"><?php echo number_format( $total_contributors ); ?></div>
				<div class="stat-lbl">Contributors</div>
			</div>
			<div class="card stat">
				<div class="stat-val blue"><?php echo number_format( $avg_events ?? 0, 1 ); ?></div>
				<div class="stat-lbl">Avg Contributions/Contributor</div>
			</div>
			<div class="card stat">
				<div class="stat-val blue"><?php echo number_format( $single_event ); ?></div>
				<div class="stat-lbl">One-time Contributors</div>
				<div class="stat-detail"><?php echo esc_html( $total_contributors > 0 ? round( ( $single_event / $total_contributors ) * 100 ) : 0 ); ?>% drop-off risk</div>
			</div>
			<div class="card stat">
				<div class="stat-val blue"><?php echo $avg_time_to_first !== null ? esc_html( number_format( round( $avg_time_to_first ) ) ) : '&mdash;'; ?></div>
				<div class="stat-lbl">Avg Days to First Contribution</div>
			</div>
		</div>
	</section>

	<div class="grid-2">
		<?php if ( $total_contributors > 0 ) : ?>
		<div class="card">
			<h3>Key Insights</h3>
			<div class="insight">
				<span><strong><?php echo esc_html( $active_contributors ); ?></strong> contributors active (<?php echo esc_html( round( ( $active_contributors / $total_contributors ) * 100 ) ); ?>%).
				<?php
				if ( $warning_contributors > 0 ) :
					?>
					<strong><?php echo esc_html( $warning_contributors ); ?></strong> at risk.<?php endif; ?></span>
				<span class="info-icon">i<span class="info-tip"><strong>Active:</strong> contributed in the last 30 days.<br><strong>At risk:</strong> last activity was 30-90 days ago.</span></span>
			</div>
			<?php if ( $ten_plus_events > 0 ) : ?>
			<div class="insight">
				<span><strong><?php echo esc_html( number_format( $ten_plus_events ) ); ?></strong> contributors with 10+ contributions (<?php echo esc_html( round( ( $ten_plus_events / $total_contributors ) * 100 ) ); ?>%).</span>
				<span class="info-icon">i<span class="info-tip">Contributors who have made more than 10 contributions.</span></span>
			</div>
			<?php endif; ?>
			<?php if ( $new_contributors_30d > 0 ) : ?>
			<div class="insight">
				<span><strong><?php echo number_format( $new_contributors_30d ); ?></strong> new contributors in the last 30 days.</span>
				<span class="info-icon">i<span class="info-tip">Contributors whose registration date was within the last 30 days of the data period.</span></span>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div class="card">
			<h3>First Contribution Event</h3>
			<?php
			if ( ! empty( $first_event_counts ) ) :
				$max_first = reset( $first_event_counts );
				$r         = 0;
				foreach ( $first_event_counts as $type => $first_cnt ) :
					++$r;
					$title     = $event_types[ $type ]['title'] ?? $type;
					$p         = $max_first > 0 ? round( ( $first_cnt / $max_first ) * 100 ) : 0;
					$total_cnt = $event_distribution[ $type ] ?? 0;
					?>
			<div class="item">
				<span class="item-rank"><?php echo esc_html( $r ); ?></span>
				<span class="item-name"><?php echo esc_html( $title ); ?></span>
				<span class="item-count"><?php echo esc_html( number_format( $first_cnt ) ); ?></span>
				<div class="bar-wrap"><div class="bar" style="width: <?php echo esc_attr( $p ); ?>%"></div></div>
				<span class="item-total" title="Total contributions of this type"><?php echo esc_html( number_format( $total_cnt ) ); ?> total</span>
			</div>
					<?php
			endforeach;
endif;
			?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
