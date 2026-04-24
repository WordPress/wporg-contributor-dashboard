<?php
/**
 * Overview View
 *
 * High-level contributor stats, key insights, and first-contribution breakdown.
 * The ladder funnel lives in the dedicated Ladder view.
 * Queries the profiles table directly on every request.
 */

if (!defined('ABSPATH')) exit;

/**
 * Render the overview view.
 *
 * @param array $filters Resolved filter values keyed by filter id. See wporgcd_resolve_filters().
 * @return string Rendered inner HTML (no <html>/<head>/layout wrapper).
 */
function wporgcd_render_overview_view($filters) {
    global $wpdb;
    $profiles_table = wporgcd_get_table('profiles');

    $date_start       = isset($filters['registered_date']['start']) ? $filters['registered_date']['start'] : null;
    $date_end         = isset($filters['registered_date']['end'])   ? $filters['registered_date']['end']   : null;
    $include_inactive = ! empty($filters['include_inactive']);

    $sql_filters = wporgcd_build_profile_filters(array(
        'include_inactive' => $include_inactive,
        'date_start'       => $date_start,
        'date_end'         => $date_end,
        'date_column'      => 'registered_date',
    ));
    $status_filter       = $sql_filters['where'];
    $combined_filter_and = $sql_filters['and'];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    // All queries below use $profiles_table from wporgcd_get_table() which is safe, and filters from wporgcd_build_profile_filters() which are safe
    $profile_count = $wpdb->get_var( "SELECT COUNT(*) FROM $profiles_table" . $status_filter );
    $total_contributors = (int) $profile_count;
    $total_events = (int) $wpdb->get_var( "SELECT SUM(total_events) FROM $profiles_table" . $status_filter );

    $status_counts = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM $profiles_table" . $status_filter . " GROUP BY status" );
    $active_contributors = $warning_contributors = $inactive_contributors = 0;
    foreach ($status_counts as $row) {
        switch ($row->status) {
            case 'active': $active_contributors = (int) $row->count; break;
            case 'warning': $warning_contributors = (int) $row->count; break;
            case 'inactive': $inactive_contributors = (int) $row->count; break;
        }
    }

    $avg_events = $wpdb->get_var( "SELECT AVG(total_events) FROM $profiles_table" . $status_filter );
    $single_event = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $profiles_table WHERE total_events = 1" . $combined_filter_and );
    $ten_plus_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $profiles_table WHERE total_events > 10" . $combined_filter_and );

    // Event distribution + first event counts
    $event_distribution = array();
    $first_event_counts = array();
    $profiles = $wpdb->get_results( "SELECT event_counts FROM $profiles_table" . $status_filter );
    foreach ($profiles as $p) {
        $counts = json_decode($p->event_counts, true);
        if (is_array($counts)) {
            $earliest_type = null;
            $earliest_date = null;
            foreach ($counts as $type => $data) {
                $event_distribution[$type] = ($event_distribution[$type] ?? 0) + $data['count'];
                if ($earliest_date === null || $data['first_date'] < $earliest_date) {
                    $earliest_date = $data['first_date'];
                    $earliest_type = $type;
                }
            }
            if ($earliest_type) {
                $first_event_counts[$earliest_type] = ($first_event_counts[$earliest_type] ?? 0) + 1;
            }
        }
    }
    arsort($first_event_counts);
    $first_event_counts = array_slice($first_event_counts, 0, 10, true);

    $avg_time_to_first = $wpdb->get_var(
        "SELECT AVG(DATEDIFF(first_activity, registered_date))
         FROM $profiles_table
         WHERE registered_date IS NOT NULL AND first_activity IS NOT NULL AND first_activity >= registered_date" . $combined_filter_and
    );

    // New contributors in last 30 days (relative to reference date)
    $reference_end = wporgcd_get_reference_end_date();
    $thirty_days_ago = gmdate( 'Y-m-d', strtotime( $reference_end . ' -30 days' ) );
    $new_contributors_30d = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $profiles_table WHERE registered_date >= %s" . $combined_filter_and,
        $thirty_days_ago
    ) );

    // Year-over-year comparison: last 90 days vs same period last year
    $reference_start = wporgcd_get_reference_start_date();
    $has_yoy_data = strtotime($reference_end) - strtotime($reference_start) > (365 + 90) * 86400;

    $new_contributors_90d = 0;
    $new_contributors_90d_lastyear = 0;

    if ( $has_yoy_data ) {
        $ninety_days_ago = gmdate( 'Y-m-d', strtotime( $reference_end . ' -90 days' ) );
        $new_contributors_90d = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $profiles_table
             WHERE registered_date >= %s AND registered_date <= %s
             AND first_activity >= %s AND first_activity <= %s",
            $ninety_days_ago, $reference_end, $ninety_days_ago, $reference_end
        ) );

        $last_year_end = gmdate( 'Y-m-d', strtotime( $reference_end . ' -1 year' ) );
        $last_year_start = gmdate( 'Y-m-d', strtotime( $last_year_end . ' -90 days' ) );
        $new_contributors_90d_lastyear = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $profiles_table
             WHERE registered_date >= %s AND registered_date <= %s
             AND first_activity >= %s AND first_activity <= %s",
            $last_year_start, $last_year_end, $last_year_start, $last_year_end
        ) );
    }

    $event_types = wporgcd_get_event_types();
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

    ob_start();
    ?>
    <section class="overview-intro">
        <p class="tagline">Visualize and track WordPress contributor activity across the community.</p>
        <a class="learn-more" onclick="this.classList.toggle('open');document.getElementById('details-panel').classList.toggle('open');">
            Learn more
            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4.5l3 3 3-3"/></svg>
        </a>
        <div id="details-panel" class="details-panel">
            <div class="details-content">
                <p>This dashboard responds to long-standing community requests for better visibility into contributor journeys&mdash;how people join, participate, and grow across Make teams.</p>
                <p>The contributor ladder framework maps activity into stages based on behavior patterns over time. It does not rank contributors or imply that some contributions matter more than others.</p>
                <p>Key features:</p>
                <ul>
                    <li>Track contributions across event types</li>
                    <li>Visualize progression through contributor ladders</li>
                    <li>Identify active, at-risk, and inactive contributors</li>
                    <li>Compare year-over-year trends</li>
                </ul>
                <p><a href="https://make.wordpress.org/handbook/contributor-dashboard/" target="_blank">Learn more in the handbook &rarr;</a></p>
            </div>
        </div>
    </section>

    <section>
        <div class="grid-4">
            <div class="card stat">
                <div class="stat-val blue"><?php echo number_format($total_events); ?></div>
                <div class="stat-lbl">Total Contributions</div>
            </div>
            <div class="card stat">
                <div class="stat-val blue"><?php echo number_format($total_contributors); ?></div>
                <div class="stat-lbl">Contributors</div>
            </div>
            <div class="card stat">
                <div class="stat-val blue"><?php echo number_format($avg_events ?? 0, 1); ?></div>
                <div class="stat-lbl">Avg Contributions/Contributor</div>
            </div>
            <div class="card stat">
                <div class="stat-val blue"><?php echo number_format($single_event); ?></div>
                <div class="stat-lbl">One-time Contributors</div>
                <div class="stat-detail"><?php echo esc_html( $total_contributors > 0 ? round( ( $single_event / $total_contributors ) * 100 ) : 0 ); ?>% drop-off risk</div>
            </div>
        </div>
    </section>

    <div class="grid-2">
        <?php if ($total_contributors > 0): ?>
        <div class="card">
            <h3>Key Insights</h3>
            <?php if ($avg_time_to_first !== null): ?>
            <div class="insight">
                <span>Average <strong><?php echo esc_html( round( $avg_time_to_first ) ); ?> days</strong> from account creation to first contribution.</span>
                <span class="info-icon">i<span class="info-tip">Days between WordPress.org account registration and first recorded contribution.</span></span>
            </div>
            <?php endif; ?>
            <div class="insight">
                <span><strong><?php echo esc_html( $active_contributors ); ?></strong> contributors active (<?php echo esc_html( round( ( $active_contributors / $total_contributors ) * 100 ) ); ?>%).<?php if ( $warning_contributors > 0 ) : ?> <strong><?php echo esc_html( $warning_contributors ); ?></strong> at risk.<?php endif; ?></span>
                <span class="info-icon">i<span class="info-tip"><strong>Active:</strong> contributed in the last 30 days.<br><strong>At risk:</strong> last activity was 30-90 days ago.</span></span>
            </div>
            <?php if ($ten_plus_events > 0): ?>
            <div class="insight">
                <span><strong><?php echo esc_html( number_format( $ten_plus_events ) ); ?></strong> contributors with 10+ contributions (<?php echo esc_html( round( ( $ten_plus_events / $total_contributors ) * 100 ) ); ?>%).</span>
                <span class="info-icon">i<span class="info-tip">Contributors who have made more than 10 contributions.</span></span>
            </div>
            <?php endif; ?>
            <?php if ($new_contributors_30d > 0): ?>
            <div class="insight">
                <span><strong><?php echo number_format($new_contributors_30d); ?></strong> new contributors in the last 30 days.</span>
                <span class="info-icon">i<span class="info-tip">Contributors whose first recorded activity was within the last 30 days of the data period.</span></span>
            </div>
            <?php endif; ?>
            <?php if ($new_contributors_90d_lastyear > 0):
                $yoy_change = $new_contributors_90d - $new_contributors_90d_lastyear;
                $yoy_pct = round(($yoy_change / $new_contributors_90d_lastyear) * 100);
                $yoy_color = $yoy_change >= 0 ? 'var(--green)' : 'var(--red)';
                $yoy_arrow = $yoy_change >= 0 ? '&uarr;' : '&darr;';
            ?>
            <div class="insight">
                <span><span style="color: <?php echo esc_attr( $yoy_color ); ?>"><?php echo $yoy_arrow; ?> <?php echo esc_html( abs( $yoy_pct ) ); ?>%</span> new contributors vs last year (last 90 days).</span>
                <span class="info-icon">i<span class="info-tip">Compares users who registered AND made their first contribution within each 90-day period. This ensures a fair comparison by giving both periods the same &ldquo;window of opportunity&rdquo; to contribute.</span></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h3>First User Contribution</h3>
            <?php if (!empty($first_event_counts)):
                $max_first = reset($first_event_counts); $r = 0;
                foreach ($first_event_counts as $type => $first_cnt): $r++;
                    $title = $event_types[$type]['title'] ?? $type;
                    $p = $max_first > 0 ? round(($first_cnt / $max_first) * 100) : 0;
                    $total_cnt = $event_distribution[$type] ?? 0;
            ?>
            <div class="item">
                <span class="item-rank"><?php echo esc_html( $r ); ?></span>
                <span class="item-name"><?php echo esc_html( $title ); ?></span>
                <span class="item-count"><?php echo esc_html( number_format( $first_cnt ) ); ?></span>
                <div class="bar-wrap"><div class="bar" style="width: <?php echo esc_attr( $p ); ?>%"></div></div>
                <span class="item-total" title="Total contributions of this type"><?php echo esc_html( number_format( $total_cnt ) ); ?> total</span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
