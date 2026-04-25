<?php
/**
 * Ladder View
 *
 * Dedicated contributor-ladder progression funnel, computed live from the
 * events table. Ladder placement reflects the currently active filters and
 * the current ladder definition; changes to either show up on the next load.
 */

if (!defined('ABSPATH')) exit;

/**
 * Render the ladder view.
 *
 * @param array $filters Resolved filter values keyed by filter id. See wporgcd_resolve_filters().
 * @return string Rendered inner HTML (no layout wrapper).
 */
function wporgcd_render_ladder_view($filters) {
    global $wpdb;
    $events_table  = wporgcd_get_table('events');
    $ladders       = wporgcd_get_ladders();
    $event_types   = wporgcd_get_event_types();
    $reference_end = wporgcd_get_reference_end_date();

    $contrib_start    = isset($filters['contribution_date']['start']) ? $filters['contribution_date']['start'] : null;
    $contrib_end      = isset($filters['contribution_date']['end'])   ? $filters['contribution_date']['end']   : null;
    $reg_start        = isset($filters['registered_date']['start'])   ? $filters['registered_date']['start']   : null;
    $reg_end          = isset($filters['registered_date']['end'])     ? $filters['registered_date']['end']     : null;
    $include_inactive = ! empty($filters['include_inactive']);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    // $events_table comes from wporgcd_get_table() (internal whitelist) and every
    // dynamic value is bound via $wpdb->prepare() before being interpolated.
    // Event-type filter (excluded slugs come from wporgcd_get_excluded_event_types()).
    $where = array(wporgcd_get_event_type_filter_sql());
    if ($contrib_start !== null) {
        $where[] = $wpdb->prepare('event_created_date >= %s', $contrib_start);
    }
    if ($contrib_end !== null) {
        $where[] = $wpdb->prepare('event_created_date <= %s', $contrib_end);
    }
    if ($reg_start !== null) {
        $where[] = $wpdb->prepare('contributor_created_date >= %s', $reg_start);
    }
    if ($reg_end !== null) {
        $where[] = $wpdb->prepare('contributor_created_date <= %s', $reg_end);
    }
    $where_sql = implode(' AND ', $where);

    $rows = $wpdb->get_results(
        "SELECT contributor_id,
                event_type,
                COUNT(*) AS cnt,
                MAX(event_created_date) AS last_type_date
         FROM $events_table
         WHERE $where_sql
         GROUP BY contributor_id, event_type"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

    // Collapse rows into per-contributor counts + latest activity date.
    $contributors = array();
    foreach ($rows as $r) {
        $cid = $r->contributor_id;
        if (!isset($contributors[$cid])) {
            $contributors[$cid] = array('counts' => array(), 'last' => '');
        }
        $contributors[$cid]['counts'][$r->event_type] = (int) $r->cnt;
        if ($r->last_type_date > $contributors[$cid]['last']) {
            $contributors[$cid]['last'] = $r->last_type_date;
        }
    }

    // Evaluate ladder per contributor and tally per-stage active/warning/inactive.
    $ladder_stats = array();
    foreach ($contributors as $data) {
        $current = null;
        foreach ($ladders as $lid => $ladder) {
            if (wporgcd_check_ladder_requirements($ladder, $data['counts'])) {
                $current = $lid;
            }
        }
        $stage = $current ?: 'none';

        $days_since = (strtotime($reference_end) - strtotime($data['last'])) / DAY_IN_SECONDS;
        if ($days_since <= WPORGCD_STATUS_ACTIVE_DAYS) {
            $status = 'active';
        } elseif ($days_since <= WPORGCD_STATUS_WARNING_DAYS) {
            $status = 'warning';
        } else {
            $status = 'inactive';
        }

        if (!$include_inactive && $status === 'inactive') {
            continue;
        }

        if (!isset($ladder_stats[$stage])) {
            $ladder_stats[$stage] = array(
                'count'          => 0,
                'active_count'   => 0,
                'warning_count'  => 0,
                'inactive_count' => 0,
            );
        }
        $ladder_stats[$stage]['count']++;
        $ladder_stats[$stage][$status . '_count']++;
    }

    $total_contributors = array_sum(array_column($ladder_stats, 'count'));

    // Aggregate active/at-risk counts across every stage (including 'none')
    // for the "All users" summary row at the top of the funnel.
    $all_active  = 0;
    $all_warning = 0;
    foreach ($ladder_stats as $s) {
        $all_active  += isset($s['active_count'])  ? $s['active_count']  : 0;
        $all_warning += isset($s['warning_count']) ? $s['warning_count'] : 0;
    }

    // Square-root scaling anchored to the total. With a flat 15% minimum
    // (the previous approach), small steps were visually clamped together
    // and lost their relative ordering; sqrt keeps tiny steps readable
    // without flattening real differences. The "All users" total is always
    // the largest bucket, so it anchors the 100% bar.
    $sqrt_max = $total_contributors > 0 ? sqrt($total_contributors) : 1;
    $bar_width = function ($cnt) use ($sqrt_max) {
        if ($cnt <= 0) {
            return 0;
        }
        return max(3, (int) round((sqrt($cnt) / $sqrt_max) * 100));
    };

    ob_start();
    ?>
    <?php if (empty($ladders)): ?>
    <div class="view-placeholder card">
        <h2>No ladders configured</h2>
        <p>Define contributor ladders in the admin to see a progression funnel here.</p>
    </div>
    <?php elseif ($total_contributors === 0): ?>
    <div class="view-placeholder card">
        <h2>No contributors match these filters</h2>
        <p>Widen the date range or toggle &ldquo;Include inactive users&rdquo; in the filters sidebar.</p>
    </div>
    <?php else: ?>
    <section>
        <div class="card">
            <h2>Contributor Progression</h2>
            <div class="funnel">
                <div class="funnel-row funnel-row-total">
                    <div class="funnel-lbl-wrap">
                        <span class="funnel-lbl">All users</span>
                        <span class="info-icon">i<span class="info-tip">All contributors matching the current filters, regardless of ladder stage.</span></span>
                    </div>
                    <div class="funnel-bar-wrap">
                        <div class="funnel-bar" style="width: 100%;"><?php echo esc_html( number_format( $total_contributors ) ); ?></div>
                    </div>
                    <div class="funnel-info">
                        <span class="active"><?php echo esc_html( $all_active ); ?> active</span>
                        <?php if ( $all_warning > 0 ) : ?><span class="risk"><?php echo esc_html( $all_warning ); ?> at risk</span><?php endif; ?>
                    </div>
                </div>

                <?php
                $lids = array_keys($ladders);
                foreach ($lids as $i => $lid):
                    $l = $ladders[$lid];
                    $s = $ladder_stats[$lid] ?? array('count' => 0, 'active_count' => 0, 'warning_count' => 0);
                    $cnt = $s['count'];
                    $w = $bar_width($cnt);
                ?>
                <div class="funnel-row">
                    <div class="funnel-lbl-wrap">
                        <span class="funnel-lbl"><?php echo esc_html($l['title']); ?></span>
                        <?php if (!empty($l['requirements'])): ?>
                        <span class="info-icon">i<span class="info-tip"><strong>Requires any of:</strong><?php
                            foreach ($l['requirements'] as $req):
                                $et_title = $event_types[$req['event_type']]['title'] ?? $req['event_type'];
                            ?><span class="req">&bull; <?php echo esc_html($et_title); ?> &ge; <?php echo (int) $req['min']; ?></span><?php endforeach; ?></span></span>
                        <?php endif; ?>
                    </div>
                    <div class="funnel-bar-wrap">
                        <div class="funnel-bar" style="width: <?php echo esc_attr( $w ); ?>%"><?php echo esc_html( number_format( $cnt ) ); ?></div>
                    </div>
                    <div class="funnel-info">
                        <?php if ( $cnt > 0 ) : ?>
                            <span class="active"><?php echo esc_html( $s['active_count'] ); ?> active</span>
                            <?php if ( $s['warning_count'] > 0 ) : ?><span class="risk"><?php echo esc_html( $s['warning_count'] ); ?> at risk</span><?php endif; ?>
                        <?php else: ?>
                            <span style="font-style: italic;">No contributors yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($i < count($lids) - 1):
                    $ns = $ladder_stats[$lids[$i + 1]] ?? array('count' => 0);
                    $conv = $cnt > 0 ? round(($ns['count'] / $cnt) * 100) : 0;
                ?>
                <div class="funnel-arrow">&darr; <?php echo esc_html( $conv ); ?>% progress</div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
