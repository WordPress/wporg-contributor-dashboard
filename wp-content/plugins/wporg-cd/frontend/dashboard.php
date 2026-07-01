<?php
/**
 * Frontend Dashboard Router + Layout + Filter Sidebar
 *
 * Routes the frontend URL to a registered view (?view=xxx), resolves that view's
 * filters from $_GET (or falls back to defaults), renders the view, and wraps it
 * in a shared layout with a left nav sidebar, a right filter sidebar, and footer.
 *
 * View output is memoized: each (view, resolved-filters, cap-date, wrapped-period,
 * config) tuple is cached via includes/cache.php in wp_options (autoload=no, no
 * expiration). Every events-table query caps event_created_date at "yesterday in
 * UTC" (see wporgcd_get_query_cap_date()), so cached entries are immutable under
 * the plugin's "imports never backfill" contract. Bump WPORGCD_CACHE_VERSION to
 * invalidate every entry after view-rendering changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'wporgcd_render_frontend_dashboard' );

/**
 * Get registered dashboard views.
 *
 * Each view declares its own filters via a `filters` array keyed by filter id.
 * Supported filter types: `date_range`, `checkbox`.
 *
 * @return array View registry keyed by view id.
 */
function wporgcd_get_views() {
	return array(
		'wrapped' => array(
			'title'   => 'Wrapped',
			'render'  => 'wporgcd_render_wrapped_view',
			'filters' => array(),
		),
		'ladder'  => array(
			'title'   => 'Ladder',
			'render'  => 'wporgcd_render_ladder_view',
			'filters' => array(
				'registered_date'     => array(
					'type'                      => 'date_range',
					'label'                     => 'User registered date',
					'column'                    => 'registered_date',
					'default_days'              => 180,
					'default_start_offset_days' => 365,
					'max_days'                  => 180,
				),
				'contribution_date'   => array(
					'type'         => 'date_range',
					'label'        => 'Contribution date',
					'column'       => 'event_created_date',
					'default_days' => 730,
					'max_days'     => 730,
				),
				'include_inactive'    => array(
					'type'    => 'checkbox',
					'label'   => 'Include inactive users',
					'default' => false,
				),
				'first_event_type'    => array(
					'type'        => 'event_type_select',
					'label'       => 'First activity type',
					'placeholder' => 'Any first activity…',
				),
				'exclude_event_types' => array(
					'type'        => 'event_type_multiselect',
					'label'       => 'Exclude activity types',
					'placeholder' => 'Exclude activity types…',
				),
			),
		),
		// Onboarding view temporarily disabled — uncomment to re-enable.
		// Hiding it from this registry removes it from the sidebar nav and
		// makes ?view=onboarding fall back to the default 'wrapped' view via
		// the whitelist check in wporgcd_render_frontend_dashboard().
		// 'onboarding' => array(
		// 'title'   => 'Onboarding',
		// 'render'  => 'wporgcd_render_onboarding_view',
		// 'filters' => array(
		// 'registered_date'     => array(
		// 'type'                      => 'date_range',
		// 'label'                     => 'User registered date',
		// 'column'                    => 'registered_date',
		// 'default_days'              => 180,
		// 'default_start_offset_days' => 365,
		// 'max_days'                  => 180,
		// ),
		// 'include_inactive'    => array(
		// 'type'    => 'checkbox',
		// 'label'   => 'Include inactive users',
		// 'default' => false,
		// ),
		// 'first_event_type'    => array(
		// 'type'        => 'event_type_select',
		// 'label'       => 'First activity type',
		// 'placeholder' => 'Any first activity…',
		// ),
		// 'exclude_event_types' => array(
		// 'type'        => 'event_type_multiselect',
		// 'label'       => 'Exclude activity types',
		// 'placeholder' => 'Exclude activity types…',
		// ),
		// ),
		// ),
		'cohorts' => array(
			'title'   => 'Cohorts',
			'render'  => 'wporgcd_render_cohorts_view',
			'filters' => array(
				'registered_date'     => array(
					'type'         => 'date_range',
					'label'        => 'User registered date',
					'column'       => 'registered_date',
					'default_days' => 365,
					'max_days'     => 730,
				),
				'first_event_type'    => array(
					'type'        => 'event_type_select',
					'label'       => 'First activity type',
					'placeholder' => 'Any first activity…',
				),
				'exclude_event_types' => array(
					'type'        => 'event_type_multiselect',
					'label'       => 'Exclude activity types',
					'placeholder' => 'Exclude activity types…',
				),
			),
		),
		// Static About page — no filters, so it renders without a right
		// sidebar (same as Wrapped). Kept last so it sits at the bottom of
		// the nav; the registry order is the menu order.
		'about'   => array(
			'title'   => 'About',
			'render'  => 'wporgcd_render_about_view',
			'filters' => array(),
		),
	);
}

/**
 * Build a URL to the given view, preserving all current $_GET params.
 *
 * Pass keys via $drop to strip them from the rebuilt URL — used e.g. by the
 * ladder view's "Reset to default" link, which navigates back to the same
 * view without the `?ladder=` override.
 *
 * @param string   $view Target view id.
 * @param string[] $drop Optional list of $_GET keys to omit from the result.
 * @return string Relative URL with query string.
 */
function wporgcd_build_view_url( $view, $drop = array() ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL construction
	$params = isset( $_GET ) && is_array( $_GET ) ? $_GET : array();
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Values are flattened to strings below via http_build_query
	$params['view'] = $view;
	foreach ( (array) $drop as $key ) {
		unset( $params[ $key ] );
	}
	$pairs = array();
	foreach ( $params as $k => $v ) {
		if ( is_scalar( $v ) ) {
			$pairs[ sanitize_key( (string) $k ) ] = (string) $v;
		}
	}
	return '?' . http_build_query( $pairs );
}

/**
 * Resolve filter values for the given view from $_GET, applying defaults.
 *
 * @param string $view_key View id.
 * @return array Resolved filter values keyed by filter id.
 */
function wporgcd_resolve_filters( $view_key ) {
	$views    = wporgcd_get_views();
	$schema   = isset( $views[ $view_key ]['filters'] ) ? $views[ $view_key ]['filters'] : array();
	$resolved = array();

	foreach ( $schema as $id => $def ) {
		$type = $def['type'];

		if ( $type === 'date_range' ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only, sanitized below
			$raw_start = isset( $_GET[ $id . '_start' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $id . '_start' ] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only, sanitized below
			$raw_end = isset( $_GET[ $id . '_end' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $id . '_end' ] ) ) : '';

			$valid = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_start )
				&& (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_end )
				&& strtotime( $raw_start ) !== false
				&& strtotime( $raw_end ) !== false
				&& strtotime( $raw_start ) <= strtotime( $raw_end );

			$ref_end = wporgcd_get_reference_end_date();

			// Filters whose schema column is event_created_date are bounded
			// above by the query cap-date (yesterday in UTC) so each cached
			// view output stays immutable for a given (filters, day) tuple
			// — see wporgcd_get_query_cap_date() and includes/cache.php.
			// For non-events columns the cap doesn't apply and effective_end
			// stays at ref_end.
			$effective_end = $ref_end;
			if ( isset( $def['column'] ) && 'event_created_date' === $def['column'] ) {
				$cap_date = wporgcd_get_query_cap_date();
				if ( strtotime( $cap_date ) < strtotime( $ref_end ) ) {
					$effective_end = $cap_date;
				}
			}

			$max_days    = isset( $def['max_days'] ) ? (int) $def['max_days'] : null;
			$was_clamped = false;

			if ( $valid ) {
				$start = $raw_start;
				$end   = $raw_end;

				// Enforce max_days by clamping the end forward from the user-supplied start.
				if ( $max_days !== null ) {
					$span_days = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS );
					if ( $span_days > $max_days ) {
						$end         = gmdate( 'Y-m-d', strtotime( $start . ' +' . $max_days . ' days' ) );
						$was_clamped = true;
					}
				}

				// Pull end back to effective_end whenever the user's submitted
				// range crosses it. Belt-and-suspenders for event_created_date
				// columns (the input's max attribute already caps the picker)
				// and a no-op for anything that already validates within range.
				if ( strtotime( $end ) > strtotime( $effective_end ) ) {
					$end         = $effective_end;
					$was_clamped = true;
				}

				$resolved[ $id ] = array(
					'start'       => $start,
					'end'         => $end,
					'is_default'  => false,
					'was_clamped' => $was_clamped,
				);
			} else {
				$days   = isset( $def['default_days'] ) ? (int) $def['default_days'] : 180;
				$offset = isset( $def['default_start_offset_days'] ) ? (int) $def['default_start_offset_days'] : null;

				if ( $offset !== null ) {
					// Anchor the default range N days before effective_end,
					// spanning forward by default_days (clamped to effective_end).
					$start = gmdate( 'Y-m-d', strtotime( $effective_end . ' -' . $offset . ' days' ) );
					$end   = gmdate( 'Y-m-d', strtotime( $start . ' +' . $days . ' days' ) );
					if ( strtotime( $end ) > strtotime( $effective_end ) ) {
						$end = $effective_end;
					}
				} else {
					// Default range ends at effective_end and spans back by default_days.
					$end   = $effective_end;
					$start = gmdate( 'Y-m-d', strtotime( $end . ' -' . $days . ' days' ) );
				}

				$resolved[ $id ] = array(
					'start'       => $start,
					'end'         => $end,
					'is_default'  => true,
					'was_clamped' => false,
				);
			}
		} elseif ( $type === 'checkbox' ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only boolean check
			$resolved[ $id ] = isset( $_GET[ $id ] ) && $_GET[ $id ] === '1';
		} elseif ( $type === 'event_type_select' || $type === 'event_type_multiselect' ) {
			// Allow-list: registered event types minus the global exclusion
			// list. Mirrors the catalog used by the ladder editor + the new
			// sidebar widgets, so an unknown or globally-excluded slug from
			// $_GET silently resolves to "no filter" rather than an error.
			$known    = wporgcd_get_event_types();
			$excluded = array_flip( wporgcd_get_excluded_event_types() );

			if ( $type === 'event_type_select' ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only, sanitized below
				$raw = isset( $_GET[ $id ] ) ? sanitize_key( wp_unslash( $_GET[ $id ] ) ) : '';
				if ( '' === $raw || ! isset( $known[ $raw ] ) || isset( $excluded[ $raw ] ) ) {
					$resolved[ $id ] = '';
				} else {
					$resolved[ $id ] = $raw;
				}
			} else {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry sanitized individually below
				$raw = isset( $_GET[ $id ] ) ? wp_unslash( $_GET[ $id ] ) : array();
				if ( ! is_array( $raw ) ) {
					$raw = array();
				}
				$seen = array();
				foreach ( $raw as $entry ) {
					if ( ! is_scalar( $entry ) ) {
						continue;
					}
					$slug = sanitize_key( (string) $entry );
					if ( '' === $slug || ! isset( $known[ $slug ] ) || isset( $excluded[ $slug ] ) ) {
						continue;
					}
					$seen[ $slug ] = true;
				}
				$values = array_keys( $seen );
				// Sort so {a,b} and {b,a} produce the same resolved array
				// — and therefore the same cache key downstream.
				sort( $values );
				$resolved[ $id ] = $values;
			}
		}
	}

	return $resolved;
}

/**
 * Route the frontend request: resolve view + filters, render, wrap in layout.
 */
function wporgcd_render_frontend_dashboard() {
	if ( is_admin() ) {
		return;
	}

	$views = wporgcd_get_views();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing; value is validated against the registered-views whitelist.
	$view_key = isset( $_GET['view'] ) && array_key_exists( $_GET['view'], $views )
		? sanitize_key( $_GET['view'] )
		: 'wrapped';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	$filters   = wporgcd_resolve_filters( $view_key );
	$render_fn = $views[ $view_key ]['render'];

	// View output cache: see includes/cache.php. The cache key incorporates
	// every input that affects the rendered HTML (filters, cap-date, wrapped
	// period, config fingerprint, WPORGCD_CACHE_VERSION), so a hit here means
	// the previous render is byte-identical to what we'd produce now. Misses
	// fall through to the live render and persist the result.
	$cache_key  = wporgcd_cache_make_key( $view_key, $filters );
	$inner_html = wporgcd_cache_get( $cache_key );
	if ( null === $inner_html ) {
		$inner_html = is_callable( $render_fn ) ? $render_fn( $filters ) : '';
		wporgcd_cache_set( $cache_key, $inner_html );
	}

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Layout + view output escaped at construction
	echo wporgcd_render_layout( $view_key, $filters, $inner_html );
	exit;
}

/**
 * Render a single filter widget based on its type.
 *
 * Emits HTML directly (called inside the output-buffered layout).
 *
 * @param string $id        Filter id.
 * @param array  $def       Filter schema entry.
 * @param mixed  $value     Resolved value (shape depends on type).
 * @param string $ref_start Reference start date (YYYY-MM-DD) for date bounds.
 * @param string $ref_end   Reference end date (YYYY-MM-DD) for date bounds.
 */
function wporgcd_render_filter_widget( $id, $def, $value, $ref_start, $ref_end ) {
	switch ( $def['type'] ) {
		case 'date_range':
			$start       = isset( $value['start'] ) ? $value['start'] : '';
			$end         = isset( $value['end'] ) ? $value['end'] : '';
			$was_clamped = ! empty( $value['was_clamped'] );
			$max_days    = isset( $def['max_days'] ) ? (int) $def['max_days'] : null;

			// Mirror the cap-date upper bound from wporgcd_resolve_filters() so
			// the date picker can't offer days beyond the cached query window.
			// For non-events columns picker_max stays at ref_end.
			$picker_max = $ref_end;
			if ( isset( $def['column'] ) && 'event_created_date' === $def['column'] ) {
				$cap_date = wporgcd_get_query_cap_date();
				if ( strtotime( $cap_date ) < strtotime( $picker_max ) ) {
					$picker_max = $cap_date;
				}
			}

			// Bound the end-input calendar so users can't pick a date more than
			// max_days past the current start. Without JS this only takes effect
			// after each submit, but it's still a helpful guardrail.
			$end_max = $picker_max;
			if ( $max_days !== null && $start !== '' && strtotime( $start ) !== false ) {
				$dynamic_max = gmdate( 'Y-m-d', strtotime( $start . ' +' . $max_days . ' days' ) );
				if ( strtotime( $dynamic_max ) < strtotime( $end_max ) ) {
					$end_max = $dynamic_max;
				}
			}
			?>
			<div class="filter">
				<label class="filter-label"><?php echo esc_html( $def['label'] ); ?></label>
				<div class="filter-date-range">
					<input type="date"
						name="<?php echo esc_attr( $id . '_start' ); ?>"
						value="<?php echo esc_attr( $start ); ?>"
						min="<?php echo esc_attr( $ref_start ); ?>"
						max="<?php echo esc_attr( $picker_max ); ?>"
						aria-label="<?php echo esc_attr( $def['label'] . ' start' ); ?>">
					<span class="date-range-sep">to</span>
					<input type="date"
						name="<?php echo esc_attr( $id . '_end' ); ?>"
						value="<?php echo esc_attr( $end ); ?>"
						min="<?php echo esc_attr( $ref_start ); ?>"
						max="<?php echo esc_attr( $end_max ); ?>"
						aria-label="<?php echo esc_attr( $def['label'] . ' end' ); ?>">
				</div>
				<?php if ( $max_days !== null ) : ?>
					<?php if ( $was_clamped ) : ?>
					<div class="filter-hint clamped">Range trimmed to max <?php echo esc_html( $max_days ); ?> days.</div>
					<?php else : ?>
					<div class="filter-hint">Max range: <?php echo esc_html( $max_days ); ?> days.</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
			break;

		case 'checkbox':
			$checked = ! empty( $value );
			?>
			<div class="filter">
				<label class="filter-checkbox">
					<input type="checkbox"
						name="<?php echo esc_attr( $id ); ?>"
						value="1"
						<?php checked( $checked ); ?>>
					<span><?php echo esc_html( $def['label'] ); ?></span>
				</label>
			</div>
			<?php
			break;

		case 'event_type_select':
		case 'event_type_multiselect':
			// Allow-list mirrors the resolver: registered event types minus
			// the global exclusion list, sorted by display title. Same
			// catalog the ladder editor uses (frontend/views/ladder.php),
			// so users see the same vocabulary across the dashboard.
			$catalog      = wporgcd_get_event_types();
			$excluded_set = array_flip( wporgcd_get_excluded_event_types() );
			$type_options = array();
			foreach ( $catalog as $et_id => $et ) {
				if ( isset( $excluded_set[ $et_id ] ) ) {
					continue;
				}
				$type_options[ $et_id ] = isset( $et['title'] ) ? (string) $et['title'] : (string) $et_id;
			}
			asort( $type_options );

			$is_multi    = ( 'event_type_multiselect' === $def['type'] );
			$placeholder = isset( $def['placeholder'] ) ? (string) $def['placeholder'] : '';
			$widget_id   = 'wporgcd-filter-' . $id;
			$selected    = $is_multi ? array_flip( (array) $value ) : array( (string) $value => true );
			?>
			<div class="filter">
				<label class="filter-label" for="<?php echo esc_attr( $widget_id ); ?>"><?php echo esc_html( $def['label'] ); ?></label>
				<select id="<?php echo esc_attr( $widget_id ); ?>"
					name="<?php echo esc_attr( $id . ( $is_multi ? '[]' : '' ) ); ?>"
					class="filter-event-type<?php echo $is_multi ? ' filter-event-type-multi' : ' filter-event-type-single'; ?>"
					data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
					<?php echo $is_multi ? 'multiple' : ''; ?>>
					<?php if ( ! $is_multi ) : ?>
						<option value=""<?php echo empty( $value ) ? ' selected' : ''; ?>><?php echo esc_html( '' === $placeholder ? '— Any —' : $placeholder ); ?></option>
					<?php endif; ?>
					<?php foreach ( $type_options as $et_id => $title ) : ?>
						<option value="<?php echo esc_attr( $et_id ); ?>"<?php echo isset( $selected[ $et_id ] ) ? ' selected' : ''; ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php
			break;
	}
}

/**
 * Render the right filter sidebar form.
 *
 * @param string $view_key Active view id (used as form's hidden input).
 * @param array  $schema   Filter schema from view registry.
 * @param array  $resolved Resolved filter values.
 * @return string HTML.
 */
function wporgcd_render_filter_sidebar( $view_key, $schema, $resolved ) {
	$ref_start = wporgcd_get_reference_start_date();
	$ref_end   = wporgcd_get_reference_end_date();

	// The Reset link drops only the filter form's input names — every other
	// $_GET param (notably ?ladder= on the ladder view) is preserved so a
	// user resetting filters doesn't also blow away an active custom ladder.
	// Date-range filters expand to <id>_start + <id>_end; everything else
	// uses the filter id as-is, plus a [] suffix which http_build_query
	// turns into the array-style query params used by multi-selects.
	$filter_param_keys = array();
	foreach ( $schema as $id => $def ) {
		if ( 'date_range' === $def['type'] ) {
			$filter_param_keys[] = $id . '_start';
			$filter_param_keys[] = $id . '_end';
		} else {
			$filter_param_keys[] = $id;
		}
	}
	$reset_url = wporgcd_build_view_url( $view_key, $filter_param_keys );

	// Form submissions (method=GET) only carry the form's own named fields,
	// so an active ?ladder= on the ladder view would silently revert to the
	// default the moment a user applied any filter. Mirror it as a hidden
	// input so Apply preserves the custom ladder.
	$ladder_passthrough = '';
	if ( 'ladder' === $view_key && function_exists( 'wporgcd_is_custom_ladder' ) && wporgcd_is_custom_ladder() ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- escaped at output below; payload is shape-validated upstream by wporgcd_is_custom_ladder().
		$ladder_passthrough = (string) wp_unslash( $_GET['ladder'] );
	}

	ob_start();
	?>
	<aside class="filter-sidebar" aria-label="Filters">
		<form method="get" action="" class="filter-form">
			<h3 class="filter-title">Filters</h3>
			<input type="hidden" name="view" value="<?php echo esc_attr( $view_key ); ?>">
			<?php if ( '' !== $ladder_passthrough ) : ?>
			<input type="hidden" name="ladder" value="<?php echo esc_attr( $ladder_passthrough ); ?>">
			<?php endif; ?>

			<?php
			foreach ( $schema as $id => $def ) :
				$value = isset( $resolved[ $id ] ) ? $resolved[ $id ] : null;
				wporgcd_render_filter_widget( $id, $def, $value, $ref_start, $ref_end );
			endforeach;
			?>

			<div class="filter-actions">
				<button type="submit" class="filter-apply">Apply</button>
				<a href="<?php echo esc_url( $reset_url ); ?>" class="filter-reset">Reset</a>
			</div>
		</form>
	</aside>
	<?php
	return ob_get_clean();
}

/**
 * Render a clickable element that opens a modal dialog by id.
 *
 * Pairs with wporgcd_render_modal() and the inline click-handler script at
 * the bottom of wporgcd_render_layout(). The output is a <button> styled to
 * inherit its surrounding text (no border, no background) so it can drop
 * into existing inline label contexts (e.g. "11 active" on the ladder
 * funnel) without visual noise — only a cursor-pointer + hover-underline
 * affordance.
 *
 * @param string $modal_id    Target dialog id (matches the $id passed to wporgcd_render_modal()).
 * @param string $label       Visible label text. Will be escaped.
 * @param string $extra_class Optional space-separated extra classes (e.g. 'active', 'risk', 'inactive').
 * @return string HTML.
 */
function wporgcd_render_modal_trigger( $modal_id, $label, $extra_class = '' ) {
	$classes = 'modal-trigger';
	if ( $extra_class !== '' ) {
		$classes .= ' ' . $extra_class;
	}
	return sprintf(
		'<button type="button" class="%s" data-modal-target="%s">%s</button>',
		esc_attr( $classes ),
		esc_attr( $modal_id ),
		esc_html( $label )
	);
}

/**
 * Render a hidden modal dialog using the native <dialog> element.
 *
 * The dialog is opened by clicking any element with
 * data-modal-target="<this id>" — see the inline click handler at the bottom
 * of wporgcd_render_layout(). Closes via the close button, the Escape key
 * (native <dialog> behavior), or a click on the backdrop.
 *
 * Caller is responsible for escaping HTML inside $body_html (matching the
 * existing wporgcd_render_layout() convention for $inner_html).
 *
 * @param string $id        Unique element id; targeted by triggers.
 * @param string $title     Modal title. Will be escaped.
 * @param string $body_html Pre-escaped modal body HTML.
 * @return string HTML.
 */
function wporgcd_render_modal( $id, $title, $body_html ) {
	ob_start();
	?>
	<dialog id="<?php echo esc_attr( $id ); ?>" class="wporgcd-modal" aria-labelledby="<?php echo esc_attr( $id . '-title' ); ?>">
		<div class="modal-header">
			<h3 id="<?php echo esc_attr( $id . '-title' ); ?>"><?php echo esc_html( $title ); ?></h3>
			<button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
		</div>
		<div class="modal-body">
			<?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller escapes $body_html
			echo $body_html;
			?>
		</div>
	</dialog>
	<?php
	return ob_get_clean();
}

/**
 * Render the shared layout that wraps every view.
 *
 * @param string $active_view Current view id.
 * @param array  $filters     Resolved filter values for the current view.
 * @param string $inner_html  Pre-rendered view HTML.
 * @return string Full HTML document.
 */
function wporgcd_render_layout( $active_view, $filters, $inner_html ) {
	$views       = wporgcd_get_views();
	$view        = isset( $views[ $active_view ] ) ? $views[ $active_view ] : array(
		'title'   => 'Dashboard',
		'filters' => array(),
	);
	$view_title  = $view['title'];
	$schema      = isset( $view['filters'] ) ? $view['filters'] : array();
	$has_filters = ! empty( $schema );

	$data_start_date = wporgcd_get_reference_start_date();
	$data_end_date   = wporgcd_get_reference_end_date();

	// Footer: show the first date_range filter range if present, falling back
	// to the wrapped period selector when active (since wrapped has no
	// sidebar filter to source the label from).
	$footer_date_label = '';
	foreach ( $schema as $id => $def ) {
		if ( $def['type'] === 'date_range' && isset( $filters[ $id ]['start'], $filters[ $id ]['end'] ) ) {
			$footer_date_label = sprintf(
				'%s: %s – %s',
				isset( $def['label'] ) ? $def['label'] : 'Date',
				gmdate( 'M j, Y', strtotime( $filters[ $id ]['start'] ) ),
				gmdate( 'M j, Y', strtotime( $filters[ $id ]['end'] ) )
			);
			break;
		}
	}
	if ( $footer_date_label === '' && $active_view === 'wrapped' && function_exists( 'wporgcd_get_wrapped_period_label' ) ) {
		$footer_date_label = wporgcd_get_wrapped_period_label();
	}

	// The Wrapped view is a story-style recap, so its page header is centered
	// and uses the full "WordPress.org Wrapped" name (not the sidebar's "Wrapped").
	// Other views keep the default left-aligned dashboard header.
	$is_wrapped        = $active_view === 'wrapped';
	$page_header_title = $is_wrapped ? 'WordPress.org Wrapped' : $view_title;
	$page_header_class = $is_wrapped ? 'page-header centered' : 'page-header';

	ob_start();
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo esc_html( $view_title ); ?> &ndash; WordPress Contributor Dashboard</title>
		<link rel="icon" href="<?php echo esc_url( plugins_url( 'favicon.png', __FILE__ ) ); ?>" type="image/png">
		<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone HTML document; no wp_head() to enqueue into. ?>
		<link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'assets/tom-select/tom-select.css', __FILE__ ) ); ?>">
		<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
:root { --bg: #f5f5f5; --card: #fff; --border: #e0e0e0; --text: #1a1a1a; --muted: #666; --light: #999; --blue: #3858e9; --blue-hover: #2c48c7; --green: #00a32a; --yellow: #dba617; --red: #dc3232; --purple: #826eb4; --sidebar-w: 240px; --filterbar-w: 260px; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
a { color: inherit; }

/* Layout: left nav + main + right filter sidebar */
.layout { display: flex; min-height: 100vh; }

.sidebar { width: var(--sidebar-w); flex-shrink: 0; background: var(--card); border-right: 1px solid var(--border); position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 20px 14px; }
.sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 4px 6px 18px; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
.sidebar-brand .wp-logo { width: 32px; height: 32px; object-fit: contain; border-radius: 6px; flex-shrink: 0; }
.sidebar-title { font-size: 13px; font-weight: 600; line-height: 1.3; }
.sidebar-nav { display: flex; flex-direction: column; gap: 2px; }
.sidebar-nav a { display: block; padding: 9px 12px; border-radius: 6px; font-size: 14px; color: var(--text); text-decoration: none; transition: background 0.15s, color 0.15s; }
.sidebar-nav a:hover { background: var(--bg); }
.sidebar-nav a.active { background: var(--blue); color: #fff; }

.main { flex: 1; min-width: 0; padding: 32px 24px 40px; }
.page-header { margin-bottom: 28px; }
.page-header.centered { text-align: center; }
.page-header h1 { font-size: 26px; font-weight: 700; margin: 0; letter-spacing: -0.02em; }

.filter-sidebar { width: var(--filterbar-w); flex-shrink: 0; background: var(--card); border-left: 1px solid var(--border); position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 20px 16px; }
.filter-form { display: flex; flex-direction: column; }
.filter-title { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.6px; margin: 0 0 14px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.filter { margin-bottom: 18px; }
.filter-label { font-size: 13px; font-weight: 600; display: block; margin-bottom: 8px; color: var(--text); }
.filter-date-range { display: flex; flex-direction: column; gap: 6px; }
.filter-date-range input[type=date] { font-family: inherit; font-size: 13px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: #fff; color: var(--text); width: 100%; }
.filter-date-range input[type=date]:focus { border-color: var(--blue); outline: none; box-shadow: 0 0 0 2px rgba(56, 88, 233, 0.15); }
.date-range-sep { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; padding-left: 4px; }
.filter-checkbox { display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--text); cursor: pointer; }
.filter-checkbox input[type=checkbox] { accent-color: var(--blue); width: 16px; height: 16px; cursor: pointer; margin: 0; }
.filter-hint { font-size: 11px; color: var(--light); margin-top: 6px; line-height: 1.4; }
.filter-hint.clamped { color: var(--yellow); }
.filter-actions { display: flex; gap: 8px; margin-top: 4px; padding-top: 14px; border-top: 1px solid var(--border); align-items: center; }
.filter-apply { background: var(--blue); color: #fff; border: none; padding: 10px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; flex: 1; transition: background 0.15s; font-family: inherit; }
.filter-apply:hover { background: var(--blue-hover); }
.filter-reset { color: var(--muted); font-size: 13px; text-decoration: none; padding: 8px 10px; transition: color 0.15s; white-space: nowrap; }
.filter-reset:hover { color: var(--blue); }

/* Event-type select / multi-select. The native rules cover the
	pre-enhancement state (Tom Select hides the underlying <select> and
	replaces it with .ts-wrapper); the .filter-sidebar .ts-* overrides
	below paint the enhanced UI to match the date pickers + checkboxes. */
.filter-event-type { width: 100%; font: inherit; font-size: 13px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: #fff; color: var(--text); }
.filter-event-type:focus { border-color: var(--blue); outline: none; box-shadow: 0 0 0 2px rgba(56, 88, 233, 0.15); }
.filter-event-type[multiple] { min-height: 90px; padding: 4px; }
.filter-sidebar .ts-wrapper { font: inherit; font-size: 13px; }
.filter-sidebar .ts-control { padding: 5px 10px; min-height: 36px; border: 1px solid var(--border); border-radius: 6px; background: #fff; color: var(--text); box-shadow: none; }
.filter-sidebar .ts-wrapper.focus .ts-control { border-color: var(--blue); box-shadow: 0 0 0 2px rgba(56, 88, 233, 0.15); }
.filter-sidebar .ts-control input::placeholder { color: var(--muted); }
.filter-sidebar .ts-wrapper.multi .ts-control > .item { background: #eef1fd; border: 1px solid #c5cff5; border-radius: 4px; color: var(--blue); padding: 1px 6px; margin: 1px 4px 1px 0; }
.filter-sidebar .ts-dropdown { font-size: 13px; border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-top: 2px; }
.filter-sidebar .ts-dropdown .option { padding: 6px 10px; }
.filter-sidebar .ts-dropdown .active { background: var(--blue); color: #fff; }
.filter-sidebar .ts-wrapper.plugin-clear_button .clear-button { color: var(--muted); top: 50%; transform: translateY(-50%); }
.filter-sidebar .ts-wrapper.plugin-clear_button .clear-button:hover { color: var(--red); }

/* Wrapped intro (tagline above the period selector) */
.wrapped-intro { margin-bottom: 20px; }
.wrapped-intro .tagline { font-size: 14px; color: var(--muted); max-width: 640px; line-height: 1.5; margin: 0 auto; text-align: center; }

/* Wrapped: in-page period selector */
.period-buttons { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin: 0 0 36px; }
.period-btn { display: inline-flex; align-items: center; padding: 8px 16px; border: 1px solid var(--border); border-radius: 999px; font-size: 13px; font-weight: 500; color: var(--text); background: var(--card); text-decoration: none; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.period-btn:hover { border-color: var(--blue); color: var(--blue); }
.period-btn.active { background: var(--blue); border-color: var(--blue); color: #fff; }
.period-btn.active:hover { background: var(--blue-hover); color: #fff; }

/* Wrapped: zigzag story sections (parity is scoped to .story-stack
	so it doesn't shift when sibling elements are added/removed above) */
.story-stack { display: flex; flex-direction: column; gap: 0; }
.story-section { display: flex; align-items: center; gap: 32px; margin-bottom: 24px; padding: 32px 28px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; }
.story-stack .story-section:nth-child(even) { flex-direction: row-reverse; }
.story-section .story-text { flex: 1; min-width: 0; }
.story-section .story-text h2 { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; margin-bottom: 10px; text-transform: none; color: var(--text); }
.story-section .story-text p { font-size: 14px; color: var(--muted); line-height: 1.6; max-width: 480px; }
.story-section .story-visual { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; }
.story-stat { text-align: center; }
.story-stat-val { font-size: 56px; font-weight: 700; line-height: 1; color: var(--blue); letter-spacing: -0.02em; }
.story-stat-lbl { font-size: 13px; color: var(--muted); margin-top: 6px; }
.story-stat-sub { font-size: 13px; color: var(--muted); text-align: center; }
.story-stat-sub strong { color: var(--text); }
.story-list { width: 100%; }

/* Wrapped: monthly mini bar chart */
.mini-chart { width: 100%; display: flex; flex-direction: column; gap: 6px; }
.mini-chart-row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
.mini-chart-label { width: 64px; flex-shrink: 0; color: var(--muted); }
.mini-chart-bar-wrap { flex: 1; height: 12px; background: var(--border); border-radius: 3px; overflow: hidden; }
.mini-chart-bar { height: 100%; background: var(--blue); border-radius: 3px; }
.mini-chart-value { width: 60px; flex-shrink: 0; text-align: right; color: var(--text); font-weight: 600; }

h2 { font-size: 18px; font-weight: 600; margin-bottom: 16px; }
h3 { font-size: 14px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
section { margin-bottom: 32px; }

.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
.grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 32px; }

.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
.stat { text-align: center; padding: 24px 20px; }
.stat-val { font-size: 36px; font-weight: 700; line-height: 1; margin-bottom: 8px; }
.stat-val.blue { color: var(--blue); }
.stat-val.green { color: var(--green); }
.stat-val.yellow { color: var(--yellow); }
.stat-val.purple { color: var(--purple); }
.stat-lbl { color: var(--muted); font-size: 13px; }
.stat-detail { font-size: 12px; color: var(--light); margin-top: 4px; }
.health-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
.health-row:last-child { border-bottom: none; }
.dot { width: 10px; height: 10px; border-radius: 50%; }
.dot.green { background: var(--green); }
.dot.yellow { background: var(--yellow); }
.dot.red { background: var(--red); }
.health-lbl { flex: 1; font-size: 14px; }
.health-val { font-size: 16px; font-weight: 600; }
.health-pct { font-size: 12px; color: var(--light); min-width: 45px; text-align: right; }
.item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.item:last-child { border-bottom: none; }
.item-rank { font-size: 12px; color: var(--light); width: 20px; }
.item-name { flex: 1; font-size: 14px; display: flex; align-items: center; gap: 8px; }
.item-count { font-size: 14px; font-weight: 600; color: var(--blue); min-width: 40px; }
.item-total { font-size: 12px; color: var(--light); min-width: 70px; text-align: right; }
.bar-wrap { width: 80px; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.bar { height: 100%; background: var(--blue); border-radius: 3px; }
.funnel { display: flex; flex-direction: column; gap: 8px; }
.funnel-row { display: flex; align-items: center; gap: 12px; }
.funnel-row-total .funnel-lbl { font-weight: 600; }
.funnel-lbl { font-size: 14px; font-weight: 500; text-align: right; }
.funnel-lbl-wrap { width: 120px; display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
.funnel-bar-wrap { flex: 1; height: 32px; display: flex; justify-content: center; }
.funnel-bar { height: 100%; background: var(--blue); border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; font-weight: 600; min-width: 50px; }
.funnel-info { display: flex; gap: 12px; font-size: 12px; min-width: 180px; color: var(--light); }
.funnel-info .active { color: var(--green); }
.funnel-info .risk { color: #d54e21; }
.funnel-info .inactive { color: var(--light); }
.funnel-arrow { text-align: center; color: var(--light); font-size: 12px; padding: 6px 0; margin-left: 132px; }
.info-icon { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; background: var(--border); color: var(--muted); font-size: 10px; font-weight: 600; font-style: italic; cursor: help; flex-shrink: 0; }
.info-icon:hover { background: var(--blue); color: #fff; }
.info-tip { position: absolute; left: 50%; top: calc(100% + 8px); transform: translateX(-50%); background: var(--text); color: #fff; padding: 10px 12px; border-radius: 6px; font-size: 12px; font-weight: 400; font-style: normal; width: max-content; max-width: 280px; opacity: 0; visibility: hidden; transition: all 0.15s; z-index: 100; pointer-events: none; line-height: 1.5; }
.info-tip::after { content: ''; position: absolute; left: 50%; bottom: 100%; transform: translateX(-50%); border: 5px solid transparent; border-bottom-color: var(--text); }
.info-icon:hover .info-tip { opacity: 1; visibility: visible; }
.info-tip strong { color: #fff; }
.info-tip .req { display: block; padding: 2px 0; }
.insights { background: #eef1fd; border: 1px solid #c5cff5; border-radius: 8px; padding: 20px; margin-bottom: 32px; }
.insights h3 { color: var(--blue); margin-bottom: 16px; }
.insight { padding: 8px 0; font-size: 14px; color: var(--muted); display: flex; align-items: flex-start; gap: 8px; }
.insight strong { color: var(--text); }
.insight .info-icon { margin-top: 2px; }

/* Modal: <button class="modal-trigger" data-modal-target="..."> opens a
	matching <dialog class="wporgcd-modal" id="...">. The trigger button
	inherits its surrounding text styling so it can drop in next to inline
	labels (e.g. "11 active" in the funnel) without visual noise — only a
	cursor-pointer + hover-underline affordance. Open/close wiring lives in
	the inline <script> emitted by wporgcd_render_layout(). */
.modal-trigger { background: none; border: none; padding: 0; margin: 0; font: inherit; color: inherit; cursor: pointer; }
.modal-trigger:hover { text-decoration: underline; }
.modal-trigger:focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; border-radius: 2px; }
/* margin: auto restores native <dialog> centering — the universal
	'* { margin: 0 }' rule above otherwise wipes the UA's margin: auto, and an
	explicit width (rather than width: 100% + max-width) leaves room for the
	auto margins to actually center the box. */
dialog.wporgcd-modal { margin: auto; border: none; border-radius: 12px; padding: 0; width: min(520px, 92vw); background: var(--card); color: var(--text); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18); }
dialog.wporgcd-modal::backdrop { background: rgba(0, 0, 0, 0.4); }
dialog.wporgcd-modal .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
dialog.wporgcd-modal .modal-header h3 { font-size: 15px; font-weight: 600; color: var(--text); text-transform: none; letter-spacing: 0; margin: 0; }
dialog.wporgcd-modal .modal-close { background: none; border: none; font: inherit; font-size: 22px; line-height: 1; color: var(--muted); cursor: pointer; padding: 4px 8px; border-radius: 6px; }
dialog.wporgcd-modal .modal-close:hover { background: var(--bg); color: var(--text); }
dialog.wporgcd-modal .modal-body { padding: 18px 20px 20px; max-height: 60vh; overflow-y: auto; font-size: 14px; line-height: 1.6; }
dialog.wporgcd-modal .modal-body p { color: var(--muted); margin: 0 0 12px; }
dialog.wporgcd-modal .modal-body p strong { color: var(--text); }
.modal-req-list { margin: -4px 0 14px; padding-left: 20px; color: var(--muted); font-size: 13px; list-style: disc; }
.modal-req-list li { padding: 1px 0; }
.modal-req-list li strong { color: var(--text); }
/* Contributor IDs are rendered as pill-style chips so the list reads as
	"tags" rather than a code block. Flex + gap handles spacing without
	needing literal commas in the markup. */
.modal-id-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.modal-id-list a { display: inline-block; padding: 3px 10px; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; font-size: 12px; color: var(--text); text-decoration: none; transition: border-color 0.15s, color 0.15s, background 0.15s; }
.modal-id-list a:hover { border-color: var(--blue); color: var(--blue); background: var(--card); }
.modal-id-list a:focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }

/* Ladder card header: the funnel card's title row hosts the H2, an
	optional fingerprint badge + reset link (when ?ladder= is active),
	and the modal trigger that opens the editor. The badge is purely
	informational — it matches the cache-key fingerprint, so two share
	links pointing at the same structure show the same #abc12345. */
.ladder-card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.ladder-card-header h2 { margin-bottom: 0; flex: 1 1 auto; min-width: 0; }
.ladder-badge { display: inline-flex; align-items: center; padding: 3px 10px; background: #eef1fd; color: var(--blue); border: 1px solid #c5cff5; border-radius: 999px; font-size: 12px; font-weight: 500; white-space: nowrap; }
.ladder-reset { color: var(--muted); font-size: 13px; text-decoration: none; white-space: nowrap; transition: color 0.15s; }
.ladder-reset:hover { color: var(--blue); text-decoration: underline; }
.ladder-customize-link.modal-trigger { border: 1px solid var(--border); background: var(--card); color: var(--muted); padding: 5px 12px; border-radius: 6px; font-size: 12px; transition: border-color 0.15s, color 0.15s; }
.ladder-customize-link.modal-trigger:hover { border-color: var(--blue); color: var(--blue); text-decoration: none; }

/* Ladder editor (rendered inside <dialog id="modal-ladder-editor">). The
	IIFE in wporgcd_render_layout() owns the live state and re-hydrates from
	<script id="wporgcd-ladder-editor-data"> on every dialog open. Sizing
	tokens stay in sync with .filter / .funnel-row to feel native. */
dialog.wporgcd-modal.wporgcd-modal-wide { width: min(720px, 92vw); }
.ladder-btn { background: var(--blue); color: #fff; border: 1px solid var(--blue); padding: 7px 14px; border-radius: 6px; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.ladder-btn:hover { background: var(--blue-hover); border-color: var(--blue-hover); }
.ladder-btn:focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
.ladder-btn-ghost { background: transparent; color: var(--text); border-color: var(--border); }
.ladder-btn-ghost:hover { background: var(--bg); color: var(--text); border-color: var(--border); }
.ladder-editor-help { font-size: 13px; color: var(--muted); line-height: 1.5; margin: 0 0 16px; }
.ladder-editor-noscript { font-size: 13px; color: var(--muted); padding: 12px 0; }
.ladder-editor-steps { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.ladder-editor-step { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; }
.ladder-editor-step-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.ladder-editor-step-header input[type="text"] { flex: 1; min-width: 0; font: inherit; font-size: 14px; font-weight: 600; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--card); color: var(--text); }
.ladder-editor-step-header input[type="text"]:focus { border-color: var(--blue); outline: none; box-shadow: 0 0 0 2px rgba(56, 88, 233, 0.15); }
.ladder-editor-icon-btn { background: var(--card); color: var(--muted); border: 1px solid var(--border); border-radius: 6px; padding: 4px 8px; font: inherit; font-size: 13px; cursor: pointer; line-height: 1; min-width: 28px; }
.ladder-editor-icon-btn:hover { color: var(--blue); border-color: var(--blue); }
.ladder-editor-icon-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.ladder-editor-icon-btn.danger:hover { color: var(--red); border-color: var(--red); }
.ladder-editor-reqs { display: flex; flex-direction: column; gap: 6px; }
.ladder-editor-req { display: flex; align-items: center; gap: 8px; }
.ladder-editor-req select { flex: 1; min-width: 0; font: inherit; font-size: 13px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--card); color: var(--text); }
.ladder-editor-req input[type="number"] { width: 90px; font: inherit; font-size: 13px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--card); color: var(--text); }
.ladder-editor-req .ladder-editor-req-label { font-size: 12px; color: var(--muted); }
.ladder-editor-step-footer { margin-top: 8px; }
.ladder-editor-actions { display: flex; align-items: center; gap: 10px; padding-top: 14px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.ladder-editor-actions-spacer { flex: 1 1 auto; }
.ladder-editor-error { font-size: 12px; color: var(--red); }

/* Cohorts heatmap (Cohorts view). The first two columns and the header
	row are sticky inside .cohort-table-wrap so the cohort label and size
	stay visible when the table is horizontally scrolled. Cell tints are
	emitted as inline rgba() backgrounds by the view (computed against the
	global cell min/max), so the heatmap doesn't need a JS pass to colorize. */
.cohort-card { padding: 20px; }
.cohort-card h2 { margin-bottom: 6px; }
.cohort-card-help { font-size: 13px; color: var(--muted); line-height: 1.5; margin: 0 0 16px; max-width: 720px; }
.cohort-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
.cohort-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; }
.cohort-table th, .cohort-table td { padding: 10px 14px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.cohort-table tbody tr:last-child td { border-bottom: none; }
.cohort-table thead th { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; background: var(--bg); position: sticky; top: 0; z-index: 1; }
.cohort-table th.cohort-col, .cohort-table td.cohort-col { text-align: left; font-weight: 500; color: var(--text); width: 140px; }
.cohort-table th.cohort-num, .cohort-table td.cohort-num { text-align: right; width: 110px; color: var(--text); font-variant-numeric: tabular-nums; }
.cohort-table th.cohort-cell-h, .cohort-table td.cohort-cell { text-align: center; min-width: 64px; font-variant-numeric: tabular-nums; color: var(--text); }
.cohort-table .cohort-cell-empty { background: var(--bg); color: transparent; }
.cohort-table .cohort-col-sticky { position: sticky; background: var(--card); z-index: 2; }
.cohort-table .cohort-col-sticky-1 { left: 0; }
.cohort-table .cohort-col-sticky-2 { left: 140px; box-shadow: 1px 0 0 var(--border); }
.cohort-table thead .cohort-col-sticky { background: var(--bg); z-index: 3; }
.cohort-table .cohort-row-avg td { font-weight: 600; border-top: 2px solid var(--border); }
.cohort-table .cohort-row-avg td.cohort-col { text-transform: uppercase; font-size: 11px; color: var(--muted); letter-spacing: 0.5px; }
.cohort-table .cohort-row-avg td.cohort-col-sticky { background: var(--bg); }

.view-placeholder { text-align: center; padding: 48px 24px; }
.view-placeholder h2 { margin-bottom: 12px; }
.view-placeholder p { color: var(--muted); max-width: 520px; margin: 0 auto 8px; font-size: 14px; }
.view-placeholder-note { color: var(--light) !important; font-size: 13px !important; font-style: italic; }

/* About view: static prose cards + the read-only event-type table. Reuses
	the shared .card / .insights tokens; the rules below just tighten the
	stacking gap, style the lead paragraphs, and paint the ladder-step pills
	and event-type table (which mirrors the cohort table's font sizing). */
.about-section { margin-bottom: 20px; }
.about-section:last-child { margin-bottom: 0; }
.about-section h2 { margin-bottom: 12px; }
.about-lead { font-size: 14px; color: var(--muted); line-height: 1.6; margin: 0 0 12px; max-width: 720px; }
.about-lead:last-child { margin-bottom: 0; }
.about-lead a { color: var(--blue); text-decoration: none; }
.about-lead a:hover { text-decoration: underline; }
.about-disclaimer { margin-bottom: 0; }
.about-disclaimer p { font-size: 14px; color: var(--muted); line-height: 1.6; margin: 0 0 10px; }
.about-disclaimer p:last-child { margin-bottom: 0; }
.about-disclaimer p strong { color: var(--text); }
.about-ladder-steps { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 4px; }
.about-ladder-step { display: inline-flex; align-items: center; padding: 6px 14px; background: #eef1fd; border: 1px solid #c5cff5; border-radius: 999px; font-size: 13px; font-weight: 600; color: var(--blue); }
.about-ladder-arrow { color: var(--light); font-size: 13px; }
.about-view-list, .about-link-list { margin: 4px 0 0; padding-left: 20px; color: var(--muted); font-size: 14px; line-height: 1.7; }
.about-view-list li, .about-link-list li { padding: 2px 0; }
.about-view-list strong { color: var(--text); }
.about-link-list a { color: var(--blue); text-decoration: none; }
.about-link-list a:hover { text-decoration: underline; }
.about-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; margin-top: 12px; }
.about-event-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; }
.about-event-table th, .about-event-table td { padding: 10px 14px; border-bottom: 1px solid var(--border); text-align: left; }
.about-event-table tbody tr:last-child td { border-bottom: none; }
.about-event-table thead th { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; background: var(--bg); }
.about-event-table code { font-size: 12px; color: var(--muted); }
.about-excluded-flag { display: inline-block; margin-left: 8px; padding: 1px 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; font-size: 11px; color: var(--light); white-space: nowrap; }
.about-footnote { font-size: 12px; color: var(--light); margin: 12px 0 0; line-height: 1.5; }

.ladder-card-help { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 0 0 12px; max-width: 720px; }
.ladder-card-help a { color: var(--blue); text-decoration: none; }
.ladder-card-help a:hover { text-decoration: underline; }
.ladder-status-legend { font-size: 12px; color: var(--muted); line-height: 1.6; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); }
.ladder-status-legend strong { color: var(--text); }

.footer { text-align: center; padding: 24px 20px 0; color: var(--light); font-size: 12px; border-top: 1px solid var(--border); margin-top: 40px; }
.footer a { color: var(--blue); text-decoration: none; padding: 4px 0; }
.footer a:hover { text-decoration: underline; }
.footer-meta { margin-bottom: 12px; line-height: 1.6; }
.footer-links { display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
.footer-links .sep { color: var(--border); }
.mobile-break { display: none; }
.desktop-sep { }

@media (max-width: 1200px) {
	.grid-4 { grid-template-columns: repeat(2, 1fr); }
}

/* Medium screens: right filter sidebar stacks below main; left sidebar stays */
@media (max-width: 1100px) {
	.layout { flex-wrap: wrap; }
	.sidebar { order: 0; }
	.main { order: 1; }
	.filter-sidebar {
	width: 100%;
	height: auto;
	position: static;
	overflow: visible;
	border-left: none;
	border-top: 1px solid var(--border);
	padding: 16px 24px 24px;
	order: 2;
	}
	.filter-form {
	max-width: 560px;
	}
	.filter-date-range { flex-direction: row; align-items: center; gap: 10px; }
	.filter-date-range input[type=date] { flex: 1; }
	.date-range-sep { padding-left: 0; }
}

@media (max-width: 992px) {
	.main { padding: 28px 20px 32px; }
	.page-header h1 { font-size: 24px; }
	.stat-val { font-size: 32px; }
	.funnel-lbl-wrap { width: 100px; }
	.funnel-info { min-width: 150px; font-size: 11px; }
}

/* Small screens: left sidebar becomes a horizontal bar on top */
@media (max-width: 768px) {
	.sidebar {
	width: 100%;
	height: auto;
	position: static;
	border-right: none;
	border-bottom: 1px solid var(--border);
	padding: 16px;
	}
	.sidebar-brand { padding-bottom: 14px; margin-bottom: 10px; }
	.sidebar-nav { flex-direction: row; flex-wrap: wrap; gap: 6px; align-items: center; }
	.sidebar-nav a { padding: 8px 12px; font-size: 13px; }
	.main { padding: 24px 16px 32px; }
	.grid-4, .grid-2 { grid-template-columns: 1fr; gap: 12px; }
	.page-header h1 { font-size: 22px; }
	.wrapped-intro .tagline { font-size: 13px; }
	.stat-val { font-size: 28px; }
	.stat { padding: 20px 16px; }
	.card { padding: 16px; }
	h2 { font-size: 16px; }
	.funnel-bar-wrap { flex: 1; }
	.funnel-bar { height: 28px; font-size: 13px; }
	.funnel-arrow { margin-left: 112px; font-size: 11px; }
	.filter-sidebar { padding: 16px; }
	.filter-form { max-width: none; }
	.story-section, .story-stack .story-section:nth-child(even) { flex-direction: column; padding: 24px 18px; gap: 20px; }
	.story-section .story-text h2 { font-size: 18px; }
	.story-section .story-text p { max-width: none; }
	.story-stat-val { font-size: 44px; }
	.mini-chart-label { width: 52px; }
	.mini-chart-value { width: 50px; }
	.period-btn { font-size: 12px; padding: 7px 12px; }
	.cohort-card { padding: 16px; }
	.cohort-table th, .cohort-table td { padding: 8px 10px; font-size: 12px; }
	.cohort-table th.cohort-col, .cohort-table td.cohort-col { width: 100px; }
	.cohort-table th.cohort-num, .cohort-table td.cohort-num { width: 80px; }
	.cohort-table .cohort-col-sticky-2 { left: 100px; }
	.cohort-table th.cohort-cell-h, .cohort-table td.cohort-cell { min-width: 52px; }
}

@media (max-width: 576px) {
	.main { padding: 20px 14px 28px; }
	.sidebar-brand .wp-logo { width: 28px; height: 28px; border-radius: 6px; }
	.page-header h1 { font-size: 20px; }
	.stat-val { font-size: 24px; }
	.stat-lbl { font-size: 12px; }
	.funnel-row { flex-wrap: wrap; gap: 8px; }
	.funnel-lbl-wrap { width: 100%; justify-content: flex-start; }
	.funnel-bar-wrap { width: 100%; order: 2; }
	.funnel-info { width: 100%; order: 3; justify-content: flex-start; min-width: auto; }
	.funnel-arrow { margin-left: 0; text-align: left; padding-left: 4px; }
	.footer { padding: 20px 16px 0; margin-top: 32px; }
	.footer-links { flex-direction: column; gap: 12px; }
	.footer-links .sep { display: none; }
	.footer-links a { padding: 8px 16px; }
	.mobile-break { display: block; }
	.desktop-sep { display: none; }
	.filter-date-range { flex-direction: column; align-items: stretch; gap: 6px; }
	.date-range-sep { text-align: left; padding-left: 2px; }
}

@media (max-width: 480px) {
	.main { padding: 16px 12px 24px; }
	.page-header h1 { font-size: 18px; }
	.wrapped-intro .tagline { font-size: 12px; }
	.stat-val { font-size: 22px; }
	.card { padding: 14px; border-radius: 10px; }
	.item { padding: 8px 0; gap: 8px; }
	.item-name { font-size: 13px; }
	.bar-wrap { width: 60px; }
	.footer { padding: 16px 12px 0; font-size: 11px; }
}

/* Top progress bar: thin indeterminate bar shown during full-page
	navigations (link clicks + filter form submits). It asymptotes
	toward ~90% so it feels responsive without falsely promising
	completion — the new document replaces this DOM on arrival, which
	is what actually makes the bar disappear. Wiring lives in the
	inline <script> emitted by wporgcd_render_layout(). */
.top-progress { position: fixed; top: 0; left: 0; height: 3px; width: 0; background: var(--blue); z-index: 9999; pointer-events: none; box-shadow: 0 0 8px rgba(56, 88, 233, 0.45); transition: width 0.2s ease-out, opacity 0.2s ease-out; }
body.is-navigating, body.is-navigating a, body.is-navigating button { cursor: progress; }
		</style>
	</head>
	<body>
		<div class="layout">
			<aside class="sidebar">
				<div class="sidebar-brand">
					<img
						src="<?php echo esc_url( plugins_url( 'wordpress-logo.webp', __FILE__ ) ); ?>"
						alt="WordPress logo"
						class="wp-logo"
					>
					<div class="sidebar-title">Contributor Dashboard</div>
				</div>
				<nav class="sidebar-nav" aria-label="Primary">
					<?php
					// Each menu click opens the report with its default filters —
					// we deliberately don't carry over $_GET (filters, ?ladder=,
					// ?period=) since each view declares its own filter set and
					// "menu = fresh start" is more predictable than silent carryover.
					foreach ( $views as $key => $v ) :
						$url = '?view=' . rawurlencode( $key );
						?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $active_view === $key ? 'active' : ''; ?>">
						<?php echo esc_html( $v['title'] ); ?>
					</a>
					<?php endforeach; ?>
				</nav>
			</aside>
			<main class="main">
				<div class="<?php echo esc_attr( $page_header_class ); ?>">
					<h1><?php echo esc_html( $page_header_title ); ?></h1>
				</div>

				<?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View output escaped at construction
				echo $inner_html;
				?>

				<div class="footer">
					<div class="footer-meta">
						<?php
						if ( $footer_date_label !== '' ) :
							?>
							<?php echo esc_html( $footer_date_label ); ?>
						<br class="mobile-break">
						<span class="desktop-sep">&middot;</span> <?php endif; ?>Data: <?php echo esc_html( gmdate( 'M j, Y', strtotime( $data_start_date ) ) ); ?> &ndash; <?php echo esc_html( gmdate( 'M j, Y', strtotime( $data_end_date ) ) ); ?>
					</div>
					<div class="footer-links">
						<a href="https://github.com/felipevelzani/wporg-cd" target="_blank">GitHub</a>
						<span class="sep">&middot;</span>
						<span>Interested in contributing?</span>
						<a href="https://make.wordpress.org/handbook/contributor-dashboard/" target="_blank">Learn more</a>
					</div>
				</div>
			</main>
			<?php
			if ( $has_filters ) :
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped internally
				echo wporgcd_render_filter_sidebar( $active_view, $schema, $filters );
			endif;
			?>
		</div>
		<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone HTML document; no wp_footer() to enqueue into. ?>
		<script src="<?php echo esc_url( plugins_url( 'assets/tom-select/tom-select.complete.min.js', __FILE__ ) ); ?>" defer></script>
		<script>
		// Tom Select enhances the .filter-event-type-* native <select>
		// elements emitted by wporgcd_render_filter_widget() into the
		// Metorik-style placeholder dropdowns ("Select event types…") with
		// search + chip removal. The native selects still submit normally
		// if Tom Select is unavailable (CSP, slow CDN, JS disabled), so
		// the filter form continues to work as a graceful fallback.
		document.addEventListener('DOMContentLoaded', function () {
			if (typeof TomSelect === 'undefined') return;
			document.querySelectorAll('.filter-event-type-single').forEach(function (el) {
				new TomSelect(el, {
					plugins: ['clear_button'],
					placeholder: el.dataset.placeholder || '',
					allowEmptyOption: true,
					maxOptions: 200
				});
			});
			document.querySelectorAll('.filter-event-type-multi').forEach(function (el) {
				new TomSelect(el, {
					plugins: ['remove_button'],
					placeholder: el.dataset.placeholder || '',
					hideSelected: true,
					maxOptions: 200
				});
			});
		});

		// Modal open/close wiring for wporgcd_render_modal_trigger() +
		// wporgcd_render_modal(). Single delegated click handler, no
		// dependencies. ESC-to-close and focus management come from the
		// native <dialog> element (showModal()).
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('[data-modal-target]');
			if (trigger) {
				var dlg = document.getElementById(trigger.getAttribute('data-modal-target'));
				if (dlg && typeof dlg.showModal === 'function') {
					dlg.showModal();
				}
				return;
			}
			if (e.target.closest('[data-modal-close]')) {
				var openDlg = e.target.closest('dialog');
				if (openDlg) { openDlg.close(); }
				return;
			}
			// Click on the dialog element itself (not a descendant) means the
			// user clicked the backdrop — close the modal.
			if (e.target.matches('dialog.wporgcd-modal')) {
				e.target.close();
			}
		});

		// Ladder editor: only initializes on the Ladder view, where the
		// view markup includes the modal-trigger buttons, the
		// <dialog id="modal-ladder-editor">, and the
		// <script id="wporgcd-ladder-editor-data"> JSON payload (server-side,
		// kept in sync with PHP-side validation in includes/ladders.php).
		// The modal is opened/closed by the generic data-modal-target /
		// data-modal-close handler above; this IIFE just owns the editor
		// state, re-hydrates on every open, and handles Apply (encode +
		// navigate). Cancel is just a data-modal-close button — no JS here.
		// Apply navigates to ?view=ladder&...&ladder=<base64url-json>; the
		// resolver in includes/ladders.php then decodes + validates and the
		// cache-fingerprint in includes/cache.php hashes the result.
		(function () {
			var dataEl = document.getElementById('wporgcd-ladder-editor-data');
			var stepsEl = document.querySelector('[data-role="ladder-editor-steps"]');
			var dialogEl = document.getElementById('modal-ladder-editor');
			if (!dataEl || !stepsEl || !dialogEl) return;

			var cfg;
			try { cfg = JSON.parse(dataEl.textContent); } catch (_) { return; }
			if (!cfg || !cfg.ladders || !cfg.eventTypes || !cfg.limits) return;

			var limits = cfg.limits;
			var eventTypeEntries = Object.keys(cfg.eventTypes).map(function (id) {
				return { id: id, title: String(cfg.eventTypes[id]) };
			});

			// Working state: kept as an array of { id, title, requirements }
			// (matching the validated server shape, but with array order so
			// reorder is just splice + re-render).
			var state = [];
			function loadFromConfig() {
				state = Object.keys(cfg.ladders).map(function (lid) {
					var step = cfg.ladders[lid] || {};
					var reqs = Array.isArray(step.requirements) ? step.requirements : [];
					return {
						id: String(lid),
						title: String(step.title || lid),
						requirements: reqs.map(function (r) {
							return { event_type: String(r.event_type || ''), min: parseInt(r.min, 10) || 1 };
						})
					};
				});
			}
			loadFromConfig();

			function el(tag, attrs, children) {
				var node = document.createElement(tag);
				if (attrs) {
					Object.keys(attrs).forEach(function (k) {
						if (k === 'className') node.className = attrs[k];
						else if (k === 'text') node.textContent = attrs[k];
						else if (k.indexOf('data-') === 0 || k === 'role' || k === 'aria-label' || k === 'title' || k === 'type' || k === 'min' || k === 'max' || k === 'maxLength' || k === 'value' || k === 'placeholder') node.setAttribute(k, attrs[k]);
						else node[k] = attrs[k];
					});
				}
				(children || []).forEach(function (c) {
					if (c == null) return;
					node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
				});
				return node;
			}

			function buildEventTypeSelect(selectedId) {
				var sel = el('select', { 'aria-label': 'Activity type' });
				// Lead with a placeholder so unknown ids surface visibly
				// (the resolver would silently drop them anyway, but we want
				// the UI to make the user re-pick rather than ship a broken
				// requirement upstream).
				if (!selectedId || !cfg.eventTypes.hasOwnProperty(selectedId)) {
					sel.appendChild(el('option', { value: '', text: '— Select activity —' }));
				}
				eventTypeEntries.forEach(function (et) {
					var opt = el('option', { value: et.id, text: et.title });
					if (et.id === selectedId) opt.selected = true;
					sel.appendChild(opt);
				});
				return sel;
			}

			function render() {
				stepsEl.innerHTML = '';
				if (state.length === 0) {
					stepsEl.appendChild(el('p', { className: 'ladder-editor-noscript', text: 'No steps yet — add one to start customizing.' }));
				}
				state.forEach(function (step, stepIdx) {
					var titleInput = el('input', { type: 'text', value: step.title, maxLength: String(limits.maxTitleLen), 'aria-label': 'Step title' });
					titleInput.addEventListener('input', function () { step.title = titleInput.value; });

					var upBtn = el('button', { type: 'button', className: 'ladder-editor-icon-btn', 'aria-label': 'Move step up', title: 'Move up', text: '\u2191' });
					if (stepIdx === 0) upBtn.disabled = true;
					upBtn.addEventListener('click', function () {
						if (stepIdx === 0) return;
						state.splice(stepIdx - 1, 0, state.splice(stepIdx, 1)[0]);
						render();
					});

					var downBtn = el('button', { type: 'button', className: 'ladder-editor-icon-btn', 'aria-label': 'Move step down', title: 'Move down', text: '\u2193' });
					if (stepIdx === state.length - 1) downBtn.disabled = true;
					downBtn.addEventListener('click', function () {
						if (stepIdx === state.length - 1) return;
						state.splice(stepIdx + 1, 0, state.splice(stepIdx, 1)[0]);
						render();
					});

					var removeBtn = el('button', { type: 'button', className: 'ladder-editor-icon-btn danger', 'aria-label': 'Remove step', title: 'Remove step', text: '\u00d7' });
					removeBtn.addEventListener('click', function () {
						state.splice(stepIdx, 1);
						render();
					});

					var stepHeader = el('div', { className: 'ladder-editor-step-header' }, [titleInput, upBtn, downBtn, removeBtn]);

					var reqsList = el('div', { className: 'ladder-editor-reqs' });
					step.requirements.forEach(function (req, reqIdx) {
						var sel = buildEventTypeSelect(req.event_type);
						sel.addEventListener('change', function () { req.event_type = sel.value; });

						var minInput = el('input', { type: 'number', min: '1', max: String(limits.maxMin), value: String(req.min), 'aria-label': 'Minimum count' });
						minInput.addEventListener('input', function () {
							var v = parseInt(minInput.value, 10);
							req.min = isNaN(v) ? 0 : v;
						});

						var label = el('span', { className: 'ladder-editor-req-label', text: '\u2265' });
						var rm = el('button', { type: 'button', className: 'ladder-editor-icon-btn danger', 'aria-label': 'Remove requirement', title: 'Remove requirement', text: '\u00d7' });
						rm.addEventListener('click', function () {
							step.requirements.splice(reqIdx, 1);
							render();
						});

						reqsList.appendChild(el('div', { className: 'ladder-editor-req' }, [sel, label, minInput, rm]));
					});

					var addReqBtn = el('button', { type: 'button', className: 'ladder-btn ladder-btn-ghost', text: 'Add requirement' });
					addReqBtn.addEventListener('click', function () {
						if (step.requirements.length >= limits.maxReqsPerStep) return;
						step.requirements.push({ event_type: '', min: 1 });
						render();
					});

					var stepFooter = el('div', { className: 'ladder-editor-step-footer' }, [addReqBtn]);
					stepsEl.appendChild(el('div', { className: 'ladder-editor-step' }, [stepHeader, reqsList, stepFooter]));
				});
			}

			function setError(msg) {
				var errEl = document.querySelector('[data-role="ladder-editor-error"]');
				if (!errEl) return;
				if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
				errEl.hidden = false;
				errEl.textContent = msg;
			}

			function buildPayload() {
				if (state.length === 0) return { error: 'Add at least one step.' };
				if (state.length > limits.maxSteps) return { error: 'Too many steps (max ' + limits.maxSteps + ').' };
				var out = {};
				var seen = {};
				for (var i = 0; i < state.length; i++) {
					var step = state[i];
					var title = String(step.title || '').trim();
					if (!title) return { error: 'Every step needs a title.' };
					if (title.length > limits.maxTitleLen) title = title.slice(0, limits.maxTitleLen);
					var slug = title.toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/(^-+|-+$)/g, '');
					if (!slug) slug = 'step';
					var base = slug, n = 2;
					while (seen[slug]) { slug = base + '-' + n; n++; }
					seen[slug] = true;
					var reqs = [];
					for (var j = 0; j < step.requirements.length; j++) {
						var r = step.requirements[j];
						if (!r.event_type || !cfg.eventTypes.hasOwnProperty(r.event_type)) continue;
						var min = parseInt(r.min, 10);
						if (!min || min < 1 || min > limits.maxMin) continue;
						reqs.push({ event_type: r.event_type, min: min });
					}
					out[slug] = { title: title, requirements: reqs };
				}
				return { ladders: out };
			}

			// base64url encoding to match wporgcd_encode_ladders() in
			// includes/ladders.php — same JSON, same alphabet, no padding.
			function encodeBase64Url(str) {
				var b64 = btoa(unescape(encodeURIComponent(str)));
				return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
			}

			function applyChanges() {
				setError('');
				var built = buildPayload();
				if (built.error) { setError(built.error); return; }
				var encoded = encodeBase64Url(JSON.stringify(built.ladders));
				var url = new URL(window.location.href);
				url.searchParams.set('view', 'ladder');
				url.searchParams.set('ladder', encoded);
				window.location.assign(url.toString());
			}

			// Initial render so the dialog's body has content even if some
			// caller invokes showModal() outside the trigger path.
			render();

			// Re-hydrate state from cfg every time the dialog opens so a
			// canceled-then-reopened session starts clean (rather than
			// silently retaining the last unsaved edits). Capture phase so
			// this runs before the generic showModal() handler above.
			document.addEventListener('click', function (e) {
				var t = e.target.closest('[data-modal-target="modal-ladder-editor"]');
				if (!t) return;
				setError('');
				loadFromConfig();
				render();
			}, true);

			// Editor-only actions: add-step + apply. Open/close + cancel are
			// handled by the generic modal trigger/close handler above.
			document.addEventListener('click', function (e) {
				var t = e.target.closest('[data-role]');
				if (!t || !dialogEl.contains(t)) return;
				var role = t.getAttribute('data-role');
				if (role === 'ladder-add-step') {
					if (state.length >= limits.maxSteps) { setError('Max ' + limits.maxSteps + ' steps.'); return; }
					state.push({ id: '', title: 'New step', requirements: [] });
					render();
				} else if (role === 'ladder-apply') {
					applyChanges();
				}
			});
		})();

		// Top progress bar: shows an animated bar at the top of the page
		// during full-page navigations (link clicks + filter form submits).
		// The bar asymptotes toward ~90% — the browser replacing the
		// document on arrival is what makes the bar visually disappear.
		// Paired with the .top-progress and .is-navigating CSS rules above.
		(function () {
			var bar = null, timer = null, pct = 0;
			function ensureBar() {
				if (bar) return;
				bar = document.createElement('div');
				bar.className = 'top-progress';
				document.body.appendChild(bar);
			}
			function start() {
				if (timer) return;
				ensureBar();
				document.body.classList.add('is-navigating');
				pct = 0;
				bar.style.opacity = '1';
				bar.style.width = '0';
				timer = setInterval(function () {
					pct += (90 - pct) * 0.06;
					bar.style.width = pct.toFixed(1) + '%';
				}, 180);
			}
			function stop() {
				if (timer) { clearInterval(timer); timer = null; }
				if (bar) { bar.style.opacity = '0'; bar.style.width = '0'; }
				document.body.classList.remove('is-navigating');
			}
			document.addEventListener('click', function (e) {
				if (e.defaultPrevented) return;
				// Only plain left-clicks trigger a same-tab navigation;
				// modifier-clicks open a new tab/window and leave this page intact.
				if (e.button !== 0) return;
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
				var a = e.target.closest('a[href]');
				if (!a) return;
				if (a.target && a.target !== '_self') return;
				if (a.host && a.host !== location.host) return;
				var href = a.getAttribute('href');
				// In-page anchors don't trigger a document load.
				if (!href || href.charAt(0) === '#') return;
				start();
			});
			document.addEventListener('submit', function (e) {
				if (e.defaultPrevented) return;
				start();
			});
			window.addEventListener('pagehide', stop);
			window.addEventListener('pageshow', function (e) {
				// bfcache restore: the previous document is reused, so the
				// bar from the prior navigation is still in the DOM — clear it.
				if (e.persisted) stop();
			});
		})();
		</script>
	</body>
	</html>
	<?php
	return ob_get_clean();
}
