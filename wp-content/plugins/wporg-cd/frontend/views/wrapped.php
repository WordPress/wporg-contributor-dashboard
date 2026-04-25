<?php
/**
 * Wrapped View
 *
 * A WordPress.org Wrapped-style recap of contributor activity for a chosen
 * period. Filters by event_created_date (not registration date), so each
 * section reflects what happened in that window rather than who registered.
 * The period selector lives in-page (no sidebar filter) and accepts either
 * "last12" (default) or a fully-completed calendar year via ?period=YYYY.
 */

if (!defined('ABSPATH')) exit;

/**
 * Resolve the active wrapped period from $_GET['period'] (or an explicit value).
 *
 * Returns the canonical key ('last12' or 'YYYY'), a human label, and the
 * resolved [start, end] date range. Year values that don't fit fully inside
 * [reference_start, reference_end] fall back to the default (last 12 months).
 *
 * @param string|null $raw Optional override; reads $_GET['period'] when null.
 * @return array { key: string, label: string, start: string, end: string }
 */
function wporgcd_resolve_wrapped_period($raw = null) {
    $reference_end   = wporgcd_get_reference_end_date();
    $reference_start = wporgcd_get_reference_start_date();

    if ($raw === null) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only, sanitized below
        $raw = isset($_GET['period']) ? sanitize_key(wp_unslash($_GET['period'])) : '';
    }

    if (preg_match('/^\d{4}$/', $raw)) {
        $year       = (int) $raw;
        $year_start = sprintf('%04d-01-01', $year);
        $year_end   = sprintf('%04d-12-31', $year);

        if (strtotime($year_start) >= strtotime($reference_start)
            && strtotime($year_end) <= strtotime($reference_end)) {
            return array(
                'key'   => (string) $year,
                'label' => (string) $year,
                'start' => $year_start,
                'end'   => $year_end,
            );
        }
    }

    // "Last 12 months" always spans 12 complete calendar months and excludes
    // the month containing reference_end, so the chart never shows a partial
    // bar at the right edge. Example with reference_end = 2026-04-25:
    //   end   = 2026-03-31 (last day of the previous full month)
    //   start = 2025-04-01 (first day of the month 11 months before end)
    $first_of_ref_month = gmdate('Y-m-01', strtotime($reference_end));
    $end                = gmdate('Y-m-d',  strtotime($first_of_ref_month . ' -1 day'));
    $start              = gmdate('Y-m-01', strtotime(gmdate('Y-m-01', strtotime($end)) . ' -11 months'));

    return array(
        'key'   => 'last12',
        'label' => 'Last 12 months',
        'start' => $start,
        'end'   => $end,
    );
}

/**
 * Get the list of fully-completed calendar years available for the period selector.
 *
 * A year is "available" only when both Jan 1 and Dec 31 of that year fall
 * inside [reference_start, reference_end].
 *
 * @return int[] Years in newest-first order.
 */
function wporgcd_get_wrapped_available_years() {
    $reference_end   = wporgcd_get_reference_end_date();
    $reference_start = wporgcd_get_reference_start_date();
    $ref_start_t     = strtotime($reference_start);
    $ref_end_t       = strtotime($reference_end);

    $start_year = (int) gmdate('Y', $ref_start_t);
    $end_year   = (int) gmdate('Y', $ref_end_t);

    $years = array();
    for ($y = $end_year; $y >= $start_year; $y--) {
        $year_start_t = strtotime(sprintf('%04d-01-01', $y));
        $year_end_t   = strtotime(sprintf('%04d-12-31', $y));
        if ($year_start_t >= $ref_start_t && $year_end_t <= $ref_end_t) {
            $years[] = $y;
        }
    }
    return $years;
}

/**
 * Build the footer label for the current wrapped period.
 *
 * Called from wporgcd_render_layout() when the wrapped view is active and no
 * date_range filter is available to source the footer label from.
 *
 * @return string Formatted label, e.g. "Last 12 months: Apr 25, 2025 – Apr 25, 2026".
 *                Returns a literal en-dash so callers can pass the result
 *                through esc_html() without double-encoding (matching the
 *                existing date_range footer label format).
 */
function wporgcd_get_wrapped_period_label() {
    $period = wporgcd_resolve_wrapped_period();
    return sprintf(
        '%s: %s – %s',
        $period['label'],
        gmdate('M j, Y', strtotime($period['start'])),
        gmdate('M j, Y', strtotime($period['end']))
    );
}

/**
 * Generate the list of YYYY-MM keys covered by [start, end].
 *
 * Iterates from the first of the start month to the first of the end month
 * to avoid month-arithmetic edge cases (e.g., adding a month to Jan 31).
 *
 * @param string $start YYYY-MM-DD
 * @param string $end   YYYY-MM-DD
 * @return string[] e.g. ['2025-05', '2025-06', ...]
 */
function wporgcd_wrapped_month_keys($start, $end) {
    $keys   = array();
    $cursor = strtotime(gmdate('Y-m-01', strtotime($start)));
    $end_t  = strtotime(gmdate('Y-m-01', strtotime($end)));
    while ($cursor <= $end_t) {
        $keys[] = gmdate('Y-m', $cursor);
        $cursor = strtotime(gmdate('Y-m-01', $cursor) . ' +1 month');
    }
    return $keys;
}

/**
 * Render the wrapped view.
 *
 * @param array $filters Resolved filter values (wrapped declares no filters).
 * @return string Rendered inner HTML (no <html>/<head>/layout wrapper).
 */
function wporgcd_render_wrapped_view($filters) {
    global $wpdb;
    $events_table = wporgcd_get_table('events');
    $event_types  = wporgcd_get_event_types();

    $period      = wporgcd_resolve_wrapped_period();
    $period_key  = $period['key'];
    $start_date  = $period['start'];
    $end_date    = $period['end'];
    $span_days   = max(1, (int) round((strtotime($end_date) - strtotime($start_date)) / DAY_IN_SECONDS) + 1);
    $month_keys  = wporgcd_wrapped_month_keys($start_date, $end_date);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    // $events_table comes from wporgcd_get_table() (internal whitelist) and every
    // dynamic value is bound via $wpdb->prepare() before being interpolated.
    //
    // Three narrow aggregation queries (each returning a small, bounded result
    // set) instead of one big per-(contributor, type, year, month) rollup that
    // materialises hundreds of thousands of rows in PHP and OOMs the request.
    // All three reuse the same WHERE clause and benefit from the composite
    // (event_created_date, event_type, contributor_id) index added in DB
    // migration 1.2.0 (see includes/events/schema.php).
    $where = array(wporgcd_get_event_type_filter_sql());
    $where[] = $wpdb->prepare('event_created_date >= %s', $start_date);
    $where[] = $wpdb->prepare('event_created_date <= %s', $end_date . ' 23:59:59');
    $where_sql = implode(' AND ', $where);

    // Q1 (1 row): per-contributor totals collapsed into the three scalars we
    // actually render — total events, distinct contributors, and contributors
    // with more than 10 events. The inner derived table groups per contributor;
    // the outer aggregate keeps the result shape constant regardless of size.
    $summary = $wpdb->get_row(
        "SELECT
            COALESCE(SUM(cnt), 0) AS total_events,
            COUNT(*)              AS total_contributors,
            COALESCE(SUM(CASE WHEN cnt > 10 THEN 1 ELSE 0 END), 0) AS ten_plus_contributors
         FROM (
            SELECT contributor_id, COUNT(*) AS cnt
            FROM $events_table
            WHERE $where_sql
            GROUP BY contributor_id
         ) AS sub"
    );

    // Q2 (≤ LIMIT rows): top event types for the activity-mix section.
    $type_rows = $wpdb->get_results(
        "SELECT event_type, COUNT(*) AS cnt
         FROM $events_table
         WHERE $where_sql
         GROUP BY event_type
         ORDER BY cnt DESC
         LIMIT 8"
    );

    // Q3 (≤ 13 rows for last12, 12 for a calendar year): monthly event count
    // and distinct contributor count. COUNT(DISTINCT contributor_id) is computed
    // server-side so PHP never sees the per-row data.
    $monthly_rows = $wpdb->get_results(
        "SELECT YEAR(event_created_date)  AS event_year,
                MONTH(event_created_date) AS event_month,
                COUNT(*)                       AS events,
                COUNT(DISTINCT contributor_id) AS contributors
         FROM $events_table
         WHERE $where_sql
         GROUP BY event_year, event_month"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

    $total_events       = $summary ? (int) $summary->total_events       : 0;
    $total_contributors = $summary ? (int) $summary->total_contributors : 0;
    $ten_plus           = $summary ? (int) $summary->ten_plus_contributors : 0;

    $avg_per_contrib = $total_contributors > 0 ? $total_events / $total_contributors : 0;
    $avg_per_day     = $total_events / $span_days;
    $ten_plus_pct    = $total_contributors > 0 ? round(($ten_plus / $total_contributors) * 100) : 0;

    $top_types = array();
    foreach ($type_rows as $tr) {
        $top_types[$tr->event_type] = (int) $tr->cnt;
    }

    // Pre-fill so months with zero activity still render a 0-bar in order.
    $monthly_events       = array_fill_keys($month_keys, 0);
    $monthly_contributors = array_fill_keys($month_keys, 0);
    foreach ($monthly_rows as $mr) {
        $key = sprintf('%04d-%02d', (int) $mr->event_year, (int) $mr->event_month);
        if (isset($monthly_events[$key])) {
            $monthly_events[$key]       = (int) $mr->events;
            $monthly_contributors[$key] = (int) $mr->contributors;
        }
    }

    $available_years = wporgcd_get_wrapped_available_years();
    $has_data        = $total_events > 0;

    ob_start();
    ?>
    <section class="wrapped-intro">
        <p class="tagline">A recap of how the community contributed during the chosen window.</p>
    </section>

    <nav class="period-buttons" aria-label="Time period">
        <a href="<?php echo esc_url('?view=wrapped&period=last12'); ?>" class="period-btn<?php echo $period_key === 'last12' ? ' active' : ''; ?>">Last 12 months</a>
        <?php foreach ($available_years as $y): ?>
        <a href="<?php echo esc_url('?view=wrapped&period=' . $y); ?>" class="period-btn<?php echo $period_key === (string) $y ? ' active' : ''; ?>"><?php echo esc_html($y); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (! $has_data): ?>
    <div class="view-placeholder card">
        <h2>No contributions in this period</h2>
        <p>Try a different period above.</p>
    </div>
    <?php else: ?>

    <div class="story-stack">
    <section class="story-section">
        <div class="story-text">
            <h2>The story of <?php echo esc_html($period['label']); ?></h2>
            <p>Across the WordPress community, contributors showed up for every part of the project &mdash; translating, supporting, organizing, and creating. Here&rsquo;s what that looked like.</p>
        </div>
        <div class="story-visual">
            <div class="story-stat">
                <div class="story-stat-val"><?php echo esc_html(number_format($total_events)); ?></div>
                <div class="story-stat-lbl">contributions</div>
            </div>
            <div class="story-stat-sub">
                from <strong><?php echo esc_html(number_format($total_contributors)); ?></strong> contributors
            </div>
        </div>
    </section>

    <section class="story-section">
        <div class="story-text">
            <h2>Every day counts</h2>
            <p>Contributions land on every kind of day &mdash; weekdays, weekends, holidays. The community kept moving, one entry at a time.</p>
        </div>
        <div class="story-visual">
            <div class="story-stat">
                <div class="story-stat-val"><?php echo esc_html(number_format($avg_per_day, 1)); ?></div>
                <div class="story-stat-lbl">contributions per day on average</div>
            </div>
            <div class="story-stat-sub">
                <strong><?php echo esc_html(number_format($avg_per_contrib, 1)); ?></strong> per contributor over the period
            </div>
        </div>
    </section>

    <section class="story-section">
        <div class="story-text">
            <h2>Month by month</h2>
            <p>Some months bring releases, events, or new initiatives. The rhythm of contributions tells its own story.</p>
        </div>
        <div class="story-visual">
            <?php
            $max_evt = max($monthly_events);
            ?>
            <div class="mini-chart" role="list" aria-label="Contributions by month">
                <?php foreach ($month_keys as $mkey):
                    $val = $monthly_events[$mkey] ?? 0;
                    $pct = $max_evt > 0 ? round(($val / $max_evt) * 100) : 0;
                ?>
                <div class="mini-chart-row" role="listitem">
                    <span class="mini-chart-label"><?php echo esc_html(gmdate('M Y', strtotime($mkey . '-01'))); ?></span>
                    <div class="mini-chart-bar-wrap">
                        <div class="mini-chart-bar" style="width: <?php echo esc_attr($pct); ?>%"></div>
                    </div>
                    <span class="mini-chart-value"><?php echo esc_html(number_format($val)); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="story-section">
        <div class="story-text">
            <h2>Unique contributors per month</h2>
            <p>How many distinct people contributed each month. New faces and returning regulars all count &mdash; but only once per month, no matter how many contributions they made.</p>
        </div>
        <div class="story-visual">
            <?php
            $max_ctr = max($monthly_contributors);
            ?>
            <div class="mini-chart" role="list" aria-label="Unique contributors per month">
                <?php foreach ($month_keys as $mkey):
                    $val = $monthly_contributors[$mkey] ?? 0;
                    $pct = $max_ctr > 0 ? round(($val / $max_ctr) * 100) : 0;
                ?>
                <div class="mini-chart-row" role="listitem">
                    <span class="mini-chart-label"><?php echo esc_html(gmdate('M Y', strtotime($mkey . '-01'))); ?></span>
                    <div class="mini-chart-bar-wrap">
                        <div class="mini-chart-bar" style="width: <?php echo esc_attr($pct); ?>%"></div>
                    </div>
                    <span class="mini-chart-value"><?php echo esc_html(number_format($val)); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="story-section">
        <div class="story-text">
            <h2>Above and beyond</h2>
            <p>Some contributors made many contributions across the period &mdash; sustained engagement that keeps Make teams humming.</p>
        </div>
        <div class="story-visual">
            <div class="story-stat">
                <div class="story-stat-val"><?php echo esc_html(number_format($ten_plus)); ?></div>
                <div class="story-stat-lbl">contributors with 10+ contributions</div>
            </div>
            <div class="story-stat-sub">
                that&rsquo;s <strong><?php echo esc_html($ten_plus_pct); ?>%</strong> of all contributors in this period
            </div>
        </div>
    </section>

    <section class="story-section">
        <div class="story-text">
            <h2>Where contributions land</h2>
            <p>Forums, translations, events, code, and more &mdash; every type of contribution shapes the project.</p>
        </div>
        <div class="story-visual">
            <?php
            $max_type = $top_types ? reset($top_types) : 0;
            $r = 0;
            ?>
            <div class="story-list">
                <?php foreach ($top_types as $type => $cnt): $r++;
                    $title = $event_types[$type]['title'] ?? $type;
                    $pct   = $max_type > 0 ? round(($cnt / $max_type) * 100) : 0;
                ?>
                <div class="item">
                    <span class="item-rank"><?php echo esc_html($r); ?></span>
                    <span class="item-name"><?php echo esc_html($title); ?></span>
                    <span class="item-count"><?php echo esc_html(number_format($cnt)); ?></span>
                    <div class="bar-wrap"><div class="bar" style="width: <?php echo esc_attr($pct); ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    </div><!-- /.story-stack -->

    <?php endif; ?>
    <?php
    return ob_get_clean();
}
