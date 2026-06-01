<?php
/**
 * Query Output Cache
 *
 * Memoizes each dashboard view's rendered HTML in wp_options
 * (autoload=no, no expiration). The cache key incorporates the
 * resolved filter state, the "yesterday" cap-date used by every
 * events-table query, the reference end date, the wrapped period
 * (when applicable), and a fingerprint of the analytics config.
 *
 * The cap-date trick (see wporgcd_get_query_cap_date()) keeps every
 * cached entry's inputs immutable — today's still-arriving event
 * data never enters a key, and yesterday is treated as a closed day.
 * Each new day produces fresh entries; old ones remain valid forever.
 *
 * Bump WPORGCD_CACHE_VERSION whenever a view's HTML output changes
 * (markup tweaks, new sections, etc.) so existing entries are
 * orphaned and re-rendered on next access. Use
 * wporgcd_purge_query_cache() to drop every entry (manage_options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache schema version.
 *
 * Forms part of every cache key, so bumping this transparently
 * invalidates every entry without needing to delete rows. Bump on
 * any change to view rendering output that shouldn't be served
 * from a previously-cached version.
 */
define( 'WPORGCD_CACHE_VERSION', '6' );

/**
 * Look up a cached view-render result.
 *
 * Returns null on miss so callers can distinguish "not cached" from
 * a deliberately-cached empty string.
 *
 * @param string $key Full option name (typically built by wporgcd_cache_make_key()).
 * @return string|null Cached HTML, or null if no entry exists.
 */
function wporgcd_cache_get( $key ) {
	$value = get_option( $key, null );
	return null === $value ? null : (string) $value;
}

/**
 * Store a view-render result for forever-retrieval.
 *
 * Always written with autoload='no' so cached HTML (which can be
 * hundreds of KB) never inflates the autoloaded options payload.
 * add_option() respects the autoload flag on first insert; if the
 * option already exists (concurrent request, manual reset), fall
 * back to update_option() — that preserves the existing autoload
 * value, so the row stays autoload='no'.
 *
 * @param string $key   Full option name.
 * @param string $value Rendered HTML to cache.
 */
function wporgcd_cache_set( $key, $value ) {
	if ( ! add_option( $key, $value, '', 'no' ) ) {
		update_option( $key, $value );
	}
}

/**
 * Compose the cache key for the given view + resolved filters.
 *
 * The key incorporates every input that can affect the rendered
 * output for this view: filter values (already resolved + clamped
 * by wporgcd_resolve_filters()), the cap-date (anchors every query
 * and every status calculation), the wrapped period (only for the
 * wrapped view), and a fingerprint of the analytics config (event
 * types, ladders, status thresholds). Anything outside this list —
 * e.g. UI markup — must be invalidated by bumping
 * WPORGCD_CACHE_VERSION instead.
 *
 * Custom (URL-supplied) ladders need no special handling here:
 * wporgcd_get_ladders() returns the resolved structure (default OR
 * a validated `?ladder=` payload), so its JSON-encoded form below
 * naturally produces a distinct `cfg` md5 per ladder shape — each
 * shareable link therefore gets its own cache entries.
 *
 * reference_end is intentionally NOT included: its effect on filter
 * defaults flows through $filters, and its effect on the wrapped
 * period flows through the resolved $period — including it directly
 * would cause spurious cache misses every time an import advances
 * the option, even when the rendered output is byte-identical.
 *
 * Returned format: "wporgcd_qc_<32-hex>". Stays well under
 * wp_options.option_name's 191-char index limit.
 *
 * @param string $view_key View id (matches wporgcd_get_views() keys).
 * @param array  $filters  Resolved filter values from wporgcd_resolve_filters().
 * @return string Option-name key safe to pass to wporgcd_cache_get/_set.
 */
function wporgcd_cache_make_key( $view_key, $filters ) {
	$config_fp = (string) wp_json_encode(
		array(
			'event_types'    => wporgcd_get_event_types(),
			'excluded_types' => wporgcd_get_excluded_event_types(),
			'ladders'        => wporgcd_get_ladders(),
			'status_active'  => WPORGCD_STATUS_ACTIVE_DAYS,
			'status_warning' => WPORGCD_STATUS_WARNING_DAYS,
		)
	);

	$parts = array(
		'v'       => WPORGCD_CACHE_VERSION,
		'view'    => $view_key,
		'filters' => $filters,
		'cap'     => wporgcd_get_query_cap_date(),
		'period'  => 'wrapped' === $view_key && function_exists( 'wporgcd_resolve_wrapped_period' )
			? wporgcd_resolve_wrapped_period()
			: null,
		'cfg'     => md5( $config_fp ),
	);

	return 'wporgcd_qc_' . md5( (string) wp_json_encode( $parts ) );
}

/**
 * Drop every cached view-render row from wp_options.
 *
 * Capability-gated so the function is safe to expose from any
 * trigger (URL param, admin button, WP-CLI). No-ops for users
 * without manage_options.
 *
 * @return int|false Rows deleted, or false on capability mismatch / DB error.
 */
function wporgcd_purge_query_cache() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// Direct DELETE is the only way to clear options by prefix; there is no
	// core API for "delete every option whose name starts with X".
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'wporgcd_qc_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return $deleted;
}
