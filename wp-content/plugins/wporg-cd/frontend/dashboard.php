<?php
/**
 * Frontend Dashboard Router + Layout + Filter Sidebar
 *
 * Routes the frontend URL to a registered view (?view=xxx), resolves that view's
 * filters from $_GET (or falls back to defaults), renders the view, and wraps it
 * in a shared layout with a left nav sidebar, a right filter sidebar, and footer.
 *
 * No caching: every request runs the view's DB queries live.
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
		'wrapped'    => array(
			'title'   => 'Wrapped',
			'render'  => 'wporgcd_render_wrapped_view',
			'filters' => array(),
		),
		'ladder'     => array(
			'title'   => 'Ladder',
			'render'  => 'wporgcd_render_ladder_view',
			'filters' => array(
				'registered_date'   => array(
					'type'                      => 'date_range',
					'label'                     => 'User registered date',
					'column'                    => 'registered_date',
					'default_days'              => 90,
					'default_start_offset_days' => 365,
					'max_days'                  => 90,
				),
				'contribution_date' => array(
					'type'         => 'date_range',
					'label'        => 'Contribution date',
					'column'       => 'event_created_date',
					'default_days' => 365,
					'max_days'     => 365,
				),
				'include_inactive'  => array(
					'type'    => 'checkbox',
					'label'   => 'Include inactive users',
					'default' => false,
				),
			),
		),
		'onboarding' => array(
			'title'   => 'Onboarding',
			'render'  => 'wporgcd_render_onboarding_view',
			'filters' => array(
				'registered_date'  => array(
					'type'                      => 'date_range',
					'label'                     => 'User registered date',
					'column'                    => 'registered_date',
					'default_days'              => 90,
					'default_start_offset_days' => 365,
					'max_days'                  => 90,
				),
				'include_inactive' => array(
					'type'    => 'checkbox',
					'label'   => 'Include inactive users',
					'default' => false,
				),
			),
		),
		'cohorts'    => array(
			'title'   => 'Cohorts',
			'render'  => 'wporgcd_render_cohorts_view',
			'filters' => array(),
		),
	);
}

/**
 * Build a URL to the given view, preserving all current $_GET params.
 *
 * @param string $view Target view id.
 * @return string Relative URL with query string.
 */
function wporgcd_build_view_url( $view ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL construction
	$params = isset( $_GET ) && is_array( $_GET ) ? $_GET : array();
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Values are flattened to strings below via http_build_query
	$params['view'] = $view;
	$pairs          = array();
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

			$ref_end     = wporgcd_get_reference_end_date();
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
					// Anchor the default range N days before the reference end,
					// spanning forward by default_days (clamped to reference_end).
					$start = gmdate( 'Y-m-d', strtotime( $ref_end . ' -' . $offset . ' days' ) );
					$end   = gmdate( 'Y-m-d', strtotime( $start . ' +' . $days . ' days' ) );
					if ( strtotime( $end ) > strtotime( $ref_end ) ) {
						$end = $ref_end;
					}
				} else {
					// Default range ends at reference_end and spans back by default_days.
					$end   = $ref_end;
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

	$filters    = wporgcd_resolve_filters( $view_key );
	$render_fn  = $views[ $view_key ]['render'];
	$inner_html = is_callable( $render_fn ) ? $render_fn( $filters ) : '';

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

			// Bound the end-input calendar so users can't pick a date more than
			// max_days past the current start. Without JS this only takes effect
			// after each submit, but it's still a helpful guardrail.
			$end_max = $ref_end;
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
						max="<?php echo esc_attr( $ref_end ); ?>"
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

	ob_start();
	?>
	<aside class="filter-sidebar" aria-label="Filters">
		<form method="get" action="" class="filter-form">
			<h3 class="filter-title">Filters</h3>
			<input type="hidden" name="view" value="<?php echo esc_attr( $view_key ); ?>">

			<?php
			foreach ( $schema as $id => $def ) :
				$value = isset( $resolved[ $id ] ) ? $resolved[ $id ] : null;
				wporgcd_render_filter_widget( $id, $def, $value, $ref_start, $ref_end );
			endforeach;
			?>

			<div class="filter-actions">
				<button type="submit" class="filter-apply">Apply</button>
				<a href="<?php echo esc_url( '?view=' . rawurlencode( $view_key ) ); ?>" class="filter-reset">Reset</a>
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
.funnel-row-total { padding-bottom: 14px; margin-bottom: 6px; border-bottom: 1px solid var(--border); }
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

.view-placeholder { text-align: center; padding: 48px 24px; }
.view-placeholder h2 { margin-bottom: 12px; }
.view-placeholder p { color: var(--muted); max-width: 520px; margin: 0 auto 8px; font-size: 14px; }
.view-placeholder-note { color: var(--light) !important; font-size: 13px !important; font-style: italic; }

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
					foreach ( $views as $key => $v ) :
						$url = wporgcd_build_view_url( $key );
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
		<script>
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
		</script>
	</body>
	</html>
	<?php
	return ob_get_clean();
}
