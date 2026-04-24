<?php
/**
 * Cohorts View (placeholder)
 *
 * Placeholder for a future cohort-analysis view.
 */

if (!defined('ABSPATH')) exit;

/**
 * Render the cohorts view.
 *
 * @param array $filters Resolved filter values (unused for now; placeholder view).
 * @return string Rendered inner HTML (no layout wrapper).
 */
function wporgcd_render_cohorts_view($filters) {
    ob_start();
    ?>
    <div class="view-placeholder card">
        <h2>Cohorts</h2>
        <p>Cohort analysis &mdash; grouping contributors by registration period and tracking their activity over time &mdash; is coming soon.</p>
        <p class="view-placeholder-note">This is a placeholder to verify sidebar navigation and view routing.</p>
    </div>
    <?php
    return ob_get_clean();
}
