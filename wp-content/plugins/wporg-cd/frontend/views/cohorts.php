<?php
/**
 * Cohorts View
 *
 * Registration-cohort heatmap. Each row is a calendar-month cohort
 * (contributors whose contributor_created_date falls in that month and who
 * have at least one matching event); each column is months-since-registration
 * (Month 1 = the registration calendar month); each cell is the average
 * cumulative contributions per contributor in the cohort up through the end
 * of that elapsed month.
 *
 * Cells beyond a cohort's elapsed window are blank (no data to show yet).
 * The bottom row is a population-weighted average across cohorts that have
 * data in each column. Cell tint is a normalized blue heatmap computed in
 * PHP and emitted as inline rgba so no JS pass is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the cohorts view.
 *
 * @param array $filters Resolved filter values keyed by filter id. See wporgcd_resolve_filters().
 * @return string Rendered inner HTML (no layout wrapper).
 */
function wporgcd_render_cohorts_view( $filters ) {
	global $wpdb;
	$events_table = wporgcd_get_table( 'events' );
	$cap_date     = wporgcd_get_query_cap_date();

	$reg_start           = isset( $filters['registered_date']['start'] ) ? $filters['registered_date']['start'] : null;
	$reg_end             = isset( $filters['registered_date']['end'] ) ? $filters['registered_date']['end'] : null;
	$first_event_filter  = isset( $filters['first_event_type'] ) ? (string) $filters['first_event_type'] : '';
	$exclude_event_types = isset( $filters['exclude_event_types'] ) && is_array( $filters['exclude_event_types'] )
		? $filters['exclude_event_types']
		: array();

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	// $events_table comes from wporgcd_get_table() (internal whitelist) and every
	// dynamic value is bound via $wpdb->prepare() before being interpolated.
	//
	// event_created_date <= $cap_date keeps the cached view output stable:
	// today's still-arriving imports never enter the query, and the same
	// $cap_date anchors the elapsed-month math below — so a given (filters,
	// day) tuple yields a deterministic table (see includes/cache.php).
	//
	// Both queries below share the same WHERE so cohort denominator (Q1) and
	// numerator (Q2) cover the same population — keep any predicate added
	// here in $where (not duplicated inline) to preserve that invariant.
	$where = array(
		wporgcd_get_event_type_filter_sql( $exclude_event_types ),
		$wpdb->prepare( 'event_created_date <= %s', $cap_date ),
		'contributor_created_date IS NOT NULL',
	);
	if ( $reg_start !== null ) {
		$where[] = $wpdb->prepare( 'contributor_created_date >= %s', $reg_start );
	}
	if ( $reg_end !== null ) {
		$where[] = $wpdb->prepare( 'contributor_created_date <= %s', $reg_end );
	}
	if ( '' !== $first_event_filter ) {
		// "First event type" is a per-contributor attribute (the type of
		// their globally-earliest event, subject to the allowed-types list
		// + cap_date). The helper emits a `contributor_id IN (…)` subquery
		// that we drop into both queries below so cohort sizes (Q1) and
		// per-cell numerators (Q2) stay over the same filtered population.
		$where[] = wporgcd_get_first_event_type_filter_sql(
			$events_table,
			$first_event_filter,
			$cap_date,
			$exclude_event_types
		);
	}
	$where_sql = implode( ' AND ', $where );

	// Query 1: cohort sizes (contributors per registration month). The
	// COUNT(DISTINCT) here is the denominator for every cell average; using
	// the same WHERE as Query 2 ensures denominator and numerator are over
	// the same population (contributors with at least one matching event in
	// the window).
	$cohort_rows = $wpdb->get_results(
		"SELECT YEAR(contributor_created_date)  AS reg_year,
                MONTH(contributor_created_date) AS reg_month,
                COUNT(DISTINCT contributor_id)  AS cohort_size
         FROM $events_table
         WHERE $where_sql
         GROUP BY reg_year, reg_month
         ORDER BY reg_year, reg_month"
	);

	// Query 2: events per (cohort, event-month) bucket. Pivoted in PHP into
	// per-cohort per-elapsed-month counts, then prefix-summed to get the
	// cumulative numerator for each cell.
	$event_rows = $wpdb->get_results(
		"SELECT YEAR(contributor_created_date)  AS reg_year,
                MONTH(contributor_created_date) AS reg_month,
                YEAR(event_created_date)        AS event_year,
                MONTH(event_created_date)       AS event_month,
                COUNT(*)                        AS events
         FROM $events_table
         WHERE $where_sql
         GROUP BY reg_year, reg_month, event_year, event_month"
	);
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	if ( empty( $cohort_rows ) ) {
		ob_start();
		?>
		<div class="view-placeholder card">
			<h2>No cohorts in this range</h2>
			<p>Widen the registration-date filter to see cohort progression. Each row is formed from contributors whose registration date falls within the selected window.</p>
		</div>
		<?php
		return ob_get_clean();
	}

	// Build cohort metadata indexed by "Y-M" so the event rows can look up
	// their cohort cheaply during the pivot below.
	$cohort_meta          = array();
	$cohort_keys_in_order = array();
	foreach ( $cohort_rows as $r ) {
		$y    = (int) $r->reg_year;
		$m    = (int) $r->reg_month;
		$size = (int) $r->cohort_size;
		if ( $size <= 0 ) {
			continue;
		}
		$key                    = $y . '-' . $m;
		$cohort_keys_in_order[] = $key;
		$cohort_meta[ $key ]    = array(
			'reg_year'  => $y,
			'reg_month' => $m,
			'size'      => $size,
		);
	}
	if ( empty( $cohort_meta ) ) {
		ob_start();
		?>
		<div class="view-placeholder card">
			<h2>No cohorts in this range</h2>
			<p>Widen the registration-date filter to see cohort progression.</p>
		</div>
		<?php
		return ob_get_clean();
	}

	// Anchor max-elapsed at $cap_date (not "today") so the displayed table
	// is stable for a given cache key — a cohort registered in 2025-01 has a
	// max elapsed of (cap_y - 2025) * 12 + (cap_m - 1) + 1 columns of data.
	$cap_ts = strtotime( $cap_date );
	$cap_y  = (int) gmdate( 'Y', $cap_ts );
	$cap_m  = (int) gmdate( 'n', $cap_ts );
	foreach ( $cohort_meta as $key => &$meta ) {
		$elapsed             = ( $cap_y - $meta['reg_year'] ) * 12 + ( $cap_m - $meta['reg_month'] ) + 1;
		$meta['max_elapsed'] = max( 1, $elapsed );
	}
	unset( $meta );

	// Pivot event rows into per-cohort, per-elapsed-month event counts.
	// Pre-registration anomalies (event date < cohort month start) are
	// clamped into Month 1 so a single bad row can't produce a negative
	// elapsed index; future-from-cap anomalies are clamped to max_elapsed
	// for the same reason (the SQL cap should already prevent these, this
	// is belt-and-suspenders).
	$cohort_events = array();
	foreach ( $event_rows as $r ) {
		$key = (int) $r->reg_year . '-' . (int) $r->reg_month;
		if ( ! isset( $cohort_meta[ $key ] ) ) {
			continue;
		}
		$elapsed = ( (int) $r->event_year - (int) $r->reg_year ) * 12
			+ ( (int) $r->event_month - (int) $r->reg_month ) + 1;
		if ( $elapsed < 1 ) {
			$elapsed = 1;
		}
		$max_e = $cohort_meta[ $key ]['max_elapsed'];
		if ( $elapsed > $max_e ) {
			$elapsed = $max_e;
		}
		if ( ! isset( $cohort_events[ $key ] ) ) {
			$cohort_events[ $key ] = array();
		}
		$cohort_events[ $key ][ $elapsed ] = ( $cohort_events[ $key ][ $elapsed ] ?? 0 ) + (int) $r->events;
	}

	// Prefix-sum per cohort: cumulative[N] = events in months 1..N. The
	// per-cell average is cumulative[N] / cohort_size. Tracks the global
	// min/max across body cells AND the weighted-avg row so a single
	// normalization scale colors the whole table.
	$cohort_cum_events  = array();
	$cohort_avg         = array();
	$global_min         = INF;
	$global_max         = -INF;
	$global_max_elapsed = 1;

	foreach ( $cohort_keys_in_order as $key ) {
		$meta    = $cohort_meta[ $key ];
		$size    = $meta['size'];
		$max_e   = $meta['max_elapsed'];
		$running = 0;
		if ( $max_e > $global_max_elapsed ) {
			$global_max_elapsed = $max_e;
		}
		$cohort_cum_events[ $key ] = array();
		$cohort_avg[ $key ]        = array();
		for ( $n = 1; $n <= $max_e; $n++ ) {
			$delta                           = $cohort_events[ $key ][ $n ] ?? 0;
			$running                        += $delta;
			$cohort_cum_events[ $key ][ $n ] = $running;
			$avg                             = $running / $size;
			$cohort_avg[ $key ][ $n ]        = $avg;
			if ( $avg < $global_min ) {
				$global_min = $avg;
			}
			if ( $avg > $global_max ) {
				$global_max = $avg;
			}
		}
	}

	// Per-column population-weighted average. For each column N, we only
	// include cohorts with max_elapsed >= N (younger cohorts simply don't
	// have data at that column yet) — so the weighted average never mixes
	// cells that exist with cells that don't.
	$weighted_avg = array();
	for ( $n = 1; $n <= $global_max_elapsed; $n++ ) {
		$sum_events = 0;
		$sum_size   = 0;
		foreach ( $cohort_keys_in_order as $key ) {
			if ( $cohort_meta[ $key ]['max_elapsed'] < $n ) {
				continue;
			}
			$sum_events += $cohort_cum_events[ $key ][ $n ];
			$sum_size   += $cohort_meta[ $key ]['size'];
		}
		if ( $sum_size > 0 ) {
			$w                  = $sum_events / $sum_size;
			$weighted_avg[ $n ] = $w;
			if ( $w < $global_min ) {
				$global_min = $w;
			}
			if ( $w > $global_max ) {
				$global_max = $w;
			}
		}
	}

	// Heatmap intensity helper: maps a value to a [0,1] alpha. A 0.05
	// floor keeps even the smallest cell visibly tinted (the screenshots
	// show a faint blue on the lowest values rather than pure white), and
	// the 0.85 ceiling avoids pure-blue cells that would hide the cell
	// label. range == 0 short-circuits to a flat mid-tint so a single
	// cohort × single-month table doesn't divide-by-zero.
	$range     = $global_max - $global_min;
	$intensity = function ( $value ) use ( $global_min, $range ) {
		if ( $range <= 0 ) {
			return 0.5;
		}
		$t = ( $value - $global_min ) / $range;
		if ( $t < 0 ) {
			$t = 0;
		} elseif ( $t > 1 ) {
			$t = 1;
		}
		return 0.05 + 0.85 * $t;
	};

	ob_start();
	?>
	<section>
		<div class="card cohort-card">
			<h2>Cohort progression</h2>
			<p class="cohort-card-help">Each row groups contributors by registration month. Each cell is the average number of contributions per contributor in that cohort, accumulated through that elapsed month (Month 1 is the registration month). Blanks are months that haven&rsquo;t elapsed yet for the cohort.</p>
			<div class="cohort-table-wrap">
				<table class="cohort-table">
					<thead>
						<tr>
							<th class="cohort-col cohort-col-sticky cohort-col-sticky-1" scope="col">Cohort</th>
							<th class="cohort-num cohort-col-sticky cohort-col-sticky-2" scope="col">Contributors</th>
							<?php for ( $n = 1; $n <= $global_max_elapsed; $n++ ) : ?>
								<th class="cohort-cell-h" scope="col">Month <?php echo (int) $n; ?></th>
							<?php endfor; ?>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $cohort_keys_in_order as $key ) :
							$meta  = $cohort_meta[ $key ];
							$label = gmdate( 'F Y', mktime( 0, 0, 0, $meta['reg_month'], 1, $meta['reg_year'] ) );
							$max_e = $meta['max_elapsed'];
							?>
							<tr>
								<td class="cohort-col cohort-col-sticky cohort-col-sticky-1"><?php echo esc_html( $label ); ?></td>
								<td class="cohort-num cohort-col-sticky cohort-col-sticky-2"><?php echo esc_html( number_format( $meta['size'] ) ); ?></td>
								<?php
								for ( $n = 1; $n <= $global_max_elapsed; $n++ ) :
									if ( $n > $max_e ) :
										?>
										<td class="cohort-cell cohort-cell-empty"></td>
										<?php
									else :
										$val   = $cohort_avg[ $key ][ $n ];
										$alpha = $intensity( $val );
										$style = 'background: rgba(56, 88, 233, ' . number_format( $alpha, 3, '.', '' ) . ');';
										?>
										<td class="cohort-cell" style="<?php echo esc_attr( $style ); ?>"><?php echo esc_html( number_format( $val, 1 ) ); ?></td>
										<?php
									endif;
								endfor;
								?>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr class="cohort-row-avg">
							<td class="cohort-col cohort-col-sticky cohort-col-sticky-1">Weighted Average</td>
							<td class="cohort-num cohort-col-sticky cohort-col-sticky-2">&mdash;</td>
							<?php
							for ( $n = 1; $n <= $global_max_elapsed; $n++ ) :
								if ( ! isset( $weighted_avg[ $n ] ) ) :
									?>
									<td class="cohort-cell cohort-cell-empty"></td>
									<?php
								else :
									$val   = $weighted_avg[ $n ];
									$alpha = $intensity( $val );
									$style = 'background: rgba(56, 88, 233, ' . number_format( $alpha, 3, '.', '' ) . ');';
									?>
									<td class="cohort-cell" style="<?php echo esc_attr( $style ); ?>"><?php echo esc_html( number_format( $val, 1 ) ); ?></td>
									<?php
								endif;
							endfor;
							?>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
	</section>
	<?php
	return ob_get_clean();
}
