<?php
/**
 * Ladder View
 *
 * Dedicated contributor-ladder progression funnel, computed live from the
 * events table. Ladder placement reflects the currently active filters and
 * the current ladder definition; changes to either show up on the next load.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the ladder view.
 *
 * @param array $filters Resolved filter values keyed by filter id. See wporgcd_resolve_filters().
 * @return string Rendered inner HTML (no layout wrapper).
 */
function wporgcd_render_ladder_view( $filters ) {
	global $wpdb;
	$events_table = wporgcd_get_table( 'events' );
	$ladders      = wporgcd_get_ladders();
	$event_types  = wporgcd_get_event_types();
	// Activity-status thresholds are anchored on $cap_date (yesterday in
	// UTC) instead of the moving wporgcd_get_reference_end_date() so the
	// rendered output is stable for a given cache key — see
	// wporgcd_get_query_cap_date() and includes/cache.php.
	$cap_date = wporgcd_get_query_cap_date();

	$contrib_start    = isset( $filters['contribution_date']['start'] ) ? $filters['contribution_date']['start'] : null;
	$contrib_end      = isset( $filters['contribution_date']['end'] ) ? $filters['contribution_date']['end'] : null;
	$reg_start        = isset( $filters['registered_date']['start'] ) ? $filters['registered_date']['start'] : null;
	$reg_end          = isset( $filters['registered_date']['end'] ) ? $filters['registered_date']['end'] : null;
	$include_inactive = ! empty( $filters['include_inactive'] );

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	// $events_table comes from wporgcd_get_table() (internal whitelist) and every
	// dynamic value is bound via $wpdb->prepare() before being interpolated.
	// Event-type filter (excluded slugs come from wporgcd_get_excluded_event_types()).
	$where = array( wporgcd_get_event_type_filter_sql() );
	if ( $contrib_start !== null ) {
		$where[] = $wpdb->prepare( 'event_created_date >= %s', $contrib_start );
	}
	if ( $contrib_end !== null ) {
		$where[] = $wpdb->prepare( 'event_created_date <= %s', $contrib_end );
	}
	if ( $reg_start !== null ) {
		$where[] = $wpdb->prepare( 'contributor_created_date >= %s', $reg_start );
	}
	if ( $reg_end !== null ) {
		$where[] = $wpdb->prepare( 'contributor_created_date <= %s', $reg_end );
	}
	$where_sql = implode( ' AND ', $where );

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
	foreach ( $rows as $r ) {
		$cid = $r->contributor_id;
		if ( ! isset( $contributors[ $cid ] ) ) {
			$contributors[ $cid ] = array(
				'counts' => array(),
				'last'   => '',
			);
		}
		$contributors[ $cid ]['counts'][ $r->event_type ] = (int) $r->cnt;
		if ( $r->last_type_date > $contributors[ $cid ]['last'] ) {
			$contributors[ $cid ]['last'] = $r->last_type_date;
		}
	}

	// Evaluate ladder per contributor and tally per-stage active/warning/inactive.
	// The per-status ID lists power the modal that opens when a user clicks
	// a count in the funnel-info column (see wporgcd_render_modal_trigger()).
	$ladder_stats = array();
	foreach ( $contributors as $cid => $data ) {
		$current = null;
		foreach ( $ladders as $lid => $ladder ) {
			if ( wporgcd_check_ladder_requirements( $ladder, $data['counts'] ) ) {
				$current = $lid;
			}
		}
		$stage = null !== $current ? $current : 'none';

		$days_since = ( strtotime( $cap_date ) - strtotime( $data['last'] ) ) / DAY_IN_SECONDS;
		if ( $days_since <= WPORGCD_STATUS_ACTIVE_DAYS ) {
			$status = 'active';
		} elseif ( $days_since <= WPORGCD_STATUS_WARNING_DAYS ) {
			$status = 'warning';
		} else {
			$status = 'inactive';
		}

		if ( ! $include_inactive && $status === 'inactive' ) {
			continue;
		}

		if ( ! isset( $ladder_stats[ $stage ] ) ) {
			$ladder_stats[ $stage ] = array(
				'count'          => 0,
				'active_count'   => 0,
				'warning_count'  => 0,
				'inactive_count' => 0,
				'ids'            => array(
					'active'   => array(),
					'warning'  => array(),
					'inactive' => array(),
				),
			);
		}
		++$ladder_stats[ $stage ]['count'];
		++$ladder_stats[ $stage ][ $status . '_count' ];
		$ladder_stats[ $stage ]['ids'][ $status ][] = $cid;
	}

	$total_contributors = array_sum( array_column( $ladder_stats, 'count' ) );

	// Aggregate active/at-risk/inactive counts across every stage (including
	// 'none') for the "All users" summary row at the top of the funnel.
	// Inactive is only ever non-zero when the include_inactive filter is on
	// (otherwise inactive contributors are skipped above). The matching ID
	// lists power the All users modal triggers.
	$all_active       = 0;
	$all_warning      = 0;
	$all_inactive     = 0;
	$all_active_ids   = array();
	$all_warning_ids  = array();
	$all_inactive_ids = array();
	foreach ( $ladder_stats as $s ) {
		$all_active   += isset( $s['active_count'] ) ? $s['active_count'] : 0;
		$all_warning  += isset( $s['warning_count'] ) ? $s['warning_count'] : 0;
		$all_inactive += isset( $s['inactive_count'] ) ? $s['inactive_count'] : 0;
		if ( ! empty( $s['ids']['active'] ) ) {
			$all_active_ids = array_merge( $all_active_ids, $s['ids']['active'] ); }
		if ( ! empty( $s['ids']['warning'] ) ) {
			$all_warning_ids = array_merge( $all_warning_ids, $s['ids']['warning'] ); }
		if ( ! empty( $s['ids']['inactive'] ) ) {
			$all_inactive_ids = array_merge( $all_inactive_ids, $s['ids']['inactive'] ); }
	}

	// Square-root scaling anchored to the total. With a flat 15% minimum
	// (the previous approach), small steps were visually clamped together
	// and lost their relative ordering; sqrt keeps tiny steps readable
	// without flattening real differences. The "All users" total is always
	// the largest bucket, so it anchors the 100% bar.
	$sqrt_max  = $total_contributors > 0 ? sqrt( $total_contributors ) : 1;
	$bar_width = function ( $cnt ) use ( $sqrt_max ) {
		if ( $cnt <= 0 ) {
			return 0;
		}
		return max( 3, (int) round( ( sqrt( $cnt ) / $sqrt_max ) * 100 ) );
	};

	// Build the explanation + ID list for a status modal. The caller passes a
	// pre-rendered $scope_intro_html (so the same closure handles both the
	// All users modals and the per-step modals); this function appends the
	// status-definition paragraph and the contributor pill list.
	//
	// The status wording references WPORGCD_STATUS_* directly so it stays in
	// sync with the activity-status calculation above.
	$build_modal_body = function ( $status, $ids, $scope_intro_html ) {
		if ( $status === 'active' ) {
			$status_intro = sprintf(
				/* translators: 1: active-window threshold in days. */
				'<p>They are marked <strong>active</strong> because their last activity was within the last %1$d days.</p>',
				WPORGCD_STATUS_ACTIVE_DAYS
			);
		} elseif ( $status === 'warning' ) {
			$status_intro = sprintf(
				/* translators: 1: active-window threshold, 2: warning-window threshold. */
				'<p>They are marked <strong>at risk</strong> because their last activity was between %1$d and %2$d days ago.</p>',
				WPORGCD_STATUS_ACTIVE_DAYS,
				WPORGCD_STATUS_WARNING_DAYS
			);
		} else {
			$status_intro = sprintf(
				/* translators: 1: warning-window threshold. */
				'<p>They are marked <strong>inactive</strong> because their last activity was more than %1$d days ago.</p>',
				WPORGCD_STATUS_WARNING_DAYS
			);
		}
		sort( $ids );
		$links = array();
		foreach ( $ids as $id ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://profiles.wordpress.org/' . $id . '/' ),
				esc_html( $id )
			);
		}
		return $scope_intro_html . $status_intro . '<div class="modal-id-list">' . implode( '', $links ) . '</div>';
	};

	// Toolbar + editor state. Computed once so we don't re-decode the URL
	// override on every is-custom check inside the rendered HTML.
	$is_custom_ladder = wporgcd_is_custom_ladder();
	$ladder_fp        = $is_custom_ladder ? wporgcd_get_ladder_fingerprint() : '';
	$reset_url        = wporgcd_build_view_url( 'ladder', array( 'ladder' ) );

	// Event-type catalog for the editor's <select> options. Excluded types are
	// hidden because analytics queries already filter them out — exposing them
	// in the editor would let users build ladders that can never trigger.
	// Sorted by display title for usability.
	$excluded_types     = array_flip( wporgcd_get_excluded_event_types() );
	$editor_event_types = array();
	foreach ( $event_types as $et_id => $et ) {
		if ( isset( $excluded_types[ $et_id ] ) ) {
			continue;
		}
		$editor_event_types[ $et_id ] = isset( $et['title'] ) ? (string) $et['title'] : (string) $et_id;
	}
	asort( $editor_event_types );

	ob_start();
	?>
	<?php if ( empty( $ladders ) ) : ?>
	<div class="view-placeholder card">
		<h2>No ladders configured</h2>
		<p>Define contributor ladders in the admin to see a progression funnel here.</p>
	</div>
	<?php else : ?>
		<?php if ( $total_contributors === 0 ) : ?>
	<div class="view-placeholder card">
		<h2>No contributors match these filters</h2>
		<p>Widen the date range or toggle &ldquo;Include inactive users&rdquo; in the filters sidebar &mdash; or <button type="button" class="modal-trigger" data-modal-target="modal-ladder-editor">customize the ladder</button> to match different requirements.</p>
			<?php if ( $is_custom_ladder ) : ?>
			<p class="view-placeholder-note"><span class="ladder-badge">Custom ladder &middot; #<?php echo esc_html( $ladder_fp ); ?></span> &middot; <a class="ladder-reset" href="<?php echo esc_url( $reset_url ); ?>">Reset to default</a></p>
		<?php endif; ?>
	</div>
		<?php else : ?>
	<section>
		<div class="card">
			<div class="ladder-card-header">
				<h2>Contributor Progression</h2>
				<?php if ( $is_custom_ladder ) : ?>
					<span class="ladder-badge" title="Fingerprint of the active ladder structure">Custom ladder &middot; #<?php echo esc_html( $ladder_fp ); ?></span>
					<a class="ladder-reset" href="<?php echo esc_url( $reset_url ); ?>">Reset to default</a>
				<?php endif; ?>
				<button type="button" class="modal-trigger ladder-customize-link" data-modal-target="modal-ladder-editor">Customize ladder</button>
			</div>
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
						<?php if ( $all_active > 0 ) : ?>
							<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes label and attrs.
							echo wporgcd_render_modal_trigger( 'modal-ladder-all-active', $all_active . ' active', 'active' );
							?>
						<?php else : ?>
							<span class="active">0 active</span>
						<?php endif; ?>
						<?php if ( $all_warning > 0 ) : ?>
							<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes label and attrs.
							echo wporgcd_render_modal_trigger( 'modal-ladder-all-warning', $all_warning . ' at risk', 'risk' );
							?>
						<?php endif; ?>
						<?php if ( $include_inactive && $all_inactive > 0 ) : ?>
							<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes label and attrs.
							echo wporgcd_render_modal_trigger( 'modal-ladder-all-inactive', $all_inactive . ' inactive', 'inactive' );
							?>
						<?php endif; ?>
					</div>
				</div>

				<?php
				$lids = array_keys( $ladders );
				foreach ( $lids as $lid ) :
					$l   = $ladders[ $lid ];
					$s   = $ladder_stats[ $lid ] ?? array(
						'count'          => 0,
						'active_count'   => 0,
						'warning_count'  => 0,
						'inactive_count' => 0,
					);
					$cnt = $s['count'];
					$w   = $bar_width( $cnt );

					// Share of total: % of all contributors who landed at this step.
					// Anchored to $total_contributors (the "All users" bucket) rather
					// than the previous step's count because contributors are bucketed
					// into their highest matching stage only — a step's count excludes
					// everyone who advanced beyond it, so a step-to-step ratio would
					// overstate drop-off whenever later steps are populated.
					$share = $total_contributors > 0 ? round( ( $cnt / $total_contributors ) * 100 ) : 0;
					?>
				<div class="funnel-arrow">&darr; <?php echo esc_html( $share ); ?>% of users</div>
				<div class="funnel-row">
					<div class="funnel-lbl-wrap">
						<span class="funnel-lbl"><?php echo esc_html( $l['title'] ); ?></span>
						<?php if ( ! empty( $l['requirements'] ) ) : ?>
						<span class="info-icon">i<span class="info-tip"><strong>Requires any of:</strong>
							<?php
							foreach ( $l['requirements'] as $req ) :
								$et_title = $event_types[ $req['event_type'] ]['title'] ?? $req['event_type'];
								?>
							<span class="req">&bull; <?php echo esc_html( $et_title ); ?> &ge; <?php echo (int) $req['min']; ?></span><?php endforeach; ?></span></span>
						<?php endif; ?>
					</div>
					<div class="funnel-bar-wrap">
						<div class="funnel-bar" style="width: <?php echo esc_attr( $w ); ?>%"><?php echo esc_html( number_format( $cnt ) ); ?></div>
					</div>
					<div class="funnel-info">
						<?php if ( $cnt > 0 ) : ?>
							<?php if ( $s['active_count'] > 0 ) : ?>
								<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes label and attrs.
								echo wporgcd_render_modal_trigger( 'modal-ladder-' . $lid . '-active', $s['active_count'] . ' active', 'active' );
								?>
							<?php else : ?>
								<span class="active">0 active</span>
							<?php endif; ?>
							<?php if ( $s['warning_count'] > 0 ) : ?>
								<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes label and attrs.
								echo wporgcd_render_modal_trigger( 'modal-ladder-' . $lid . '-warning', $s['warning_count'] . ' at risk', 'risk' );
								?>
							<?php endif; ?>
							<?php if ( $include_inactive && $s['inactive_count'] > 0 ) : ?>
								<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes label and attrs.
								echo wporgcd_render_modal_trigger( 'modal-ladder-' . $lid . '-inactive', $s['inactive_count'] . ' inactive', 'inactive' );
								?>
							<?php endif; ?>
						<?php else : ?>
							<span style="font-style: italic;">No contributors yet</span>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

			<?php
			// Emit one <dialog> per populated funnel-info trigger above. The IDs on
			// these dialogs match the data-modal-target on each
			// wporgcd_render_modal_trigger() call; the inline click handler in
			// wporgcd_render_layout() wires them together.
			$status_label  = array(
				'active'   => 'Active',
				'warning'  => 'At-risk',
				'inactive' => 'Inactive',
			);
			$all_users_ids = array(
				'active'   => $all_active_ids,
				'warning'  => $all_warning_ids,
				'inactive' => $all_inactive_ids,
			);

			// All users modals: contributors span every step, including the implicit
			// "none" stage (contributors that don't meet any ladder requirement yet).
			foreach ( $all_users_ids as $status => $ids ) {
				if ( empty( $ids ) ) {
					continue;
				}
				$scope_intro = sprintf(
					'<p>These <strong>%d</strong> contributors are spread across every ladder step, including those who haven&rsquo;t met any step&rsquo;s requirements yet.</p>',
					count( $ids )
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes id/title; body is built from constants + esc_html'd ids.
				echo wporgcd_render_modal(
					'modal-ladder-all-' . $status,
					$status_label[ $status ] . ' contributors (all steps)',
					$build_modal_body( $status, $ids, $scope_intro )
				);
			}

			// Per-step modals: the scope intro lists the actual any-of requirements
			// for that step (matching the funnel header tooltip's content) so users
			// can see exactly why these contributors qualified.
			foreach ( $lids as $lid ) {
				if ( ! isset( $ladder_stats[ $lid ]['ids'] ) ) {
					continue;
				}

				$req_html = '';
				if ( ! empty( $ladders[ $lid ]['requirements'] ) ) {
					$req_items = array();
					foreach ( $ladders[ $lid ]['requirements'] as $req ) {
						$et_title    = $event_types[ $req['event_type'] ]['title'] ?? $req['event_type'];
						$req_items[] = sprintf(
							'<li><strong>%s</strong> &ge; %d</li>',
							esc_html( $et_title ),
							(int) $req['min']
						);
					}
					$req_html = '<ul class="modal-req-list">' . implode( '', $req_items ) . '</ul>';
				}

				foreach ( $ladder_stats[ $lid ]['ids'] as $status => $ids ) {
					if ( empty( $ids ) ) {
						continue;
					}
					$scope_intro = sprintf(
						'<p>These <strong>%d</strong> contributors have reached the <strong>%s</strong> step of the contributor ladder by meeting at least one of these activity requirements:</p>%s',
						count( $ids ),
						esc_html( $ladders[ $lid ]['title'] ),
						$req_html
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes id/title; body is built from constants + esc_html'd ids.
					echo wporgcd_render_modal(
						'modal-ladder-' . $lid . '-' . $status,
						$status_label[ $status ] . ' contributors at ' . $ladders[ $lid ]['title'],
						$build_modal_body( $status, $ids, $scope_intro )
					);
				}
			}
			?>
		<?php endif; ?>

	<dialog id="modal-ladder-editor" class="wporgcd-modal wporgcd-modal-wide" aria-labelledby="modal-ladder-editor-title">
		<div class="modal-header">
			<h3 id="modal-ladder-editor-title">Customize ladder</h3>
			<button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
		</div>
		<div class="modal-body">
			<p class="ladder-editor-help">Edit titles, reorder steps, and add or remove activity requirements. Apply navigates to a shareable URL that encodes your ladder definition &mdash; copy that URL to share.</p>
			<div class="ladder-editor-steps" data-role="ladder-editor-steps" aria-live="polite">
				<p class="ladder-editor-noscript">Editing the ladder requires JavaScript. You can still share the current URL as-is.</p>
			</div>
			<div class="ladder-editor-actions">
				<button type="button" class="ladder-btn ladder-btn-ghost" data-role="ladder-add-step">Add step</button>
				<div class="ladder-editor-actions-spacer"></div>
				<span class="ladder-editor-error" data-role="ladder-editor-error" hidden></span>
				<button type="button" class="ladder-btn ladder-btn-ghost" data-modal-close>Cancel</button>
				<button type="button" class="ladder-btn" data-role="ladder-apply">Apply</button>
			</div>
		</div>
	</dialog>
	<script type="application/json" id="wporgcd-ladder-editor-data">
		<?php
		// Escape `</` so any future event-type title containing the sequence
		// can't terminate the surrounding <script> tag (the browser's tag-soup
		// parser closes on `</script` regardless of MIME type).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in <script type=application/json>; sequence escaping handled inline.
		echo str_replace(
			'</',
			'<\/',
			(string) wp_json_encode(
				array(
					'ladders'    => $ladders,
					'eventTypes' => $editor_event_types,
					'limits'     => array(
						'maxSteps'       => WPORGCD_LADDER_MAX_STEPS,
						'maxReqsPerStep' => WPORGCD_LADDER_MAX_REQS_PER_STEP,
						'maxTitleLen'    => WPORGCD_LADDER_MAX_TITLE_LEN,
						'maxMin'         => WPORGCD_LADDER_MAX_MIN_VALUE,
					),
				)
			)
		);
		?>
	</script>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}
