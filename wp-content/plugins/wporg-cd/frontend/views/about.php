<?php
/**
 * About View
 *
 * A static, filter-free page that explains what the dashboard is, how it
 * works, and which contribution event types it currently understands. The
 * event-type list is built live from wporgcd_get_event_types() so it never
 * drifts from config.php; everything else is prose drawn from the README.
 *
 * It leads with a reliability disclaimer: the dashboard only reflects events
 * that have been imported, and several major contribution channels (Core,
 * Meta, GitHub, Slack, …) are not integrated yet — so the numbers elsewhere
 * are directional rather than complete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the about view.
 *
 * @param array $filters Resolved filter values. Unused — the view declares no
 *                       filters — but kept for signature parity with the other
 *                       view render functions called from the router.
 * @return string Rendered inner HTML (no layout wrapper).
 */
function wporgcd_render_about_view( $filters ) {
	unset( $filters );

	// Ladder step titles come straight from the default ladder so the
	// progression shown here matches what the Ladder view evaluates.
	$ladder_steps = array();
	foreach ( wporgcd_get_default_ladders() as $step ) {
		if ( isset( $step['title'] ) ) {
			$ladder_steps[] = (string) $step['title'];
		}
	}

	// Event-type catalog, sorted by display title. Excluded slugs are still
	// listed (they're stored), but flagged as omitted from analytics.
	$event_types  = wporgcd_get_event_types();
	$excluded_set = array_flip( wporgcd_get_excluded_event_types() );
	$type_rows    = array();
	foreach ( $event_types as $slug => $et ) {
		$type_rows[ $slug ] = isset( $et['title'] ) ? (string) $et['title'] : (string) $slug;
	}
	asort( $type_rows );
	$has_excluded = false;

	ob_start();
	?>
	<section class="about-section">
		<div class="insights about-disclaimer">
			<h3>About the data</h3>
			<p>This dashboard reflects only the contribution events that have been imported into it. Coverage is <strong>not fully reliable yet</strong>: major contribution channels &mdash; including WordPress <strong>Core</strong>, <strong>Meta</strong>, <strong>GitHub</strong>, and <strong>Slack</strong> &mdash; are not comprehensively integrated.</p>
			<p>Treat the totals, trends, and ladder placement across the other views as <strong>directional</strong> rather than a complete picture of anyone&rsquo;s contributions.</p>
		</div>
	</section>

	<section class="about-section">
		<div class="card">
			<h2>About the project</h2>
			<p class="about-lead">The WordPress Contributor Dashboard responds to long-standing community requests for better visibility into contributor journeys &mdash; how people join, participate, and grow across Make teams. Contribution activity, especially non-code work, is spread across many tools and systems, which makes it hard to recognize contributors, understand engagement over time, and see where support is needed.</p>
			<p class="about-lead">It uses existing WordPress.org accounts and activity data, and does not display personal or sensitive information.</p>
		</div>
	</section>

	<section class="about-section">
		<div class="card">
			<h2>Contributor ladder framework</h2>
			<p class="about-lead">The dashboard maps contributor activity into a shared, behavior-based framework that describes patterns of participation over time. It does not rank contributors or imply that some contributions matter more than others &mdash; all contribution types and all contributors matter.</p>
			<?php if ( ! empty( $ladder_steps ) ) : ?>
			<div class="about-ladder-steps">
				<?php
				$last = count( $ladder_steps ) - 1;
				foreach ( $ladder_steps as $i => $title ) :
					?>
					<span class="about-ladder-step"><?php echo esc_html( $title ); ?></span>
					<?php if ( $i < $last ) : ?>
						<span class="about-ladder-arrow" aria-hidden="true">&rarr;</span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="about-section">
		<div class="card">
			<h2>How it works</h2>
			<p class="about-lead">Raw activity records are imported via a REST API and stored as immutable events. Every view aggregates those events live in PHP on each request &mdash; there are no precomputed tables, so newly imported events show up immediately.</p>
			<p class="about-lead">Contributor status (active, warning, inactive) is calculated relative to the <strong>reference date</strong> &mdash; the newest event date in the data &mdash; rather than today&rsquo;s wall-clock date. This keeps status meaningful even when imports arrive with a delay.</p>
		</div>
	</section>

	<section class="about-section">
		<div class="card">
			<h2>Dashboard views</h2>
			<ul class="about-view-list">
				<li><strong>Wrapped</strong> &mdash; a story-style recap of a chosen period: total contributions and contributors, per-day and per-contributor averages, and monthly trends.</li>
				<li><strong>Ladder</strong> &mdash; a contributor progression funnel, live-computed per request, with active and warning counts per step.</li>
				<li><strong>Cohorts</strong> &mdash; a heatmap of average cumulative contributions per contributor across registration-month cohorts.</li>
			</ul>
		</div>
	</section>

	<section class="about-section">
		<div class="card">
			<h2>Contribution types in scope</h2>
			<p class="about-lead">These are the event types the dashboard currently recognizes. As more contribution channels are integrated, this list will grow.</p>
			<div class="about-table-wrap">
				<table class="about-event-table">
					<thead>
						<tr>
							<th scope="col">Contribution</th>
							<th scope="col">Event type</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $type_rows as $slug => $title ) :
							$is_excluded = isset( $excluded_set[ $slug ] );
							if ( $is_excluded ) {
								$has_excluded = true;
							}
							?>
							<tr>
								<td>
									<?php echo esc_html( $title ); ?>
									<?php if ( $is_excluded ) : ?>
										<span class="about-excluded-flag" title="Stored, but excluded from analytics">excluded from analytics</span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $has_excluded ) : ?>
			<p class="about-footnote">Types flagged <em>excluded from analytics</em> are still stored, but filtered out of the engagement views as noise.</p>
			<?php endif; ?>
		</div>
	</section>

	<section class="about-section">
		<div class="card">
			<h2>Get involved</h2>
			<ul class="about-link-list">
				<li><a href="https://make.wordpress.org/handbook/contributor-dashboard/" target="_blank" rel="noopener">Project handbook</a></li>
				<li><a href="https://wordpress.slack.com/archives/C0AHJA81PDE" target="_blank" rel="noopener">#contributor-dashboard on Slack</a></li>
				<li><a href="https://github.com/felipevelzani/wporg-cd" target="_blank" rel="noopener">Source on GitHub</a></li>
			</ul>
		</div>
	</section>
	<?php
	return ob_get_clean();
}
