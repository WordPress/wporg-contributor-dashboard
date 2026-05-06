<?php
/**
 * Ladder Resolver + URL Encoding
 *
 * The contributor ladder is configurable per request via a `?ladder=` URL
 * parameter (base64url-encoded JSON). When present and valid, it overrides
 * the default ladder defined in config.php; otherwise the default applies.
 *
 * The resolved ladder flows through wporgcd_get_ladders() — the same function
 * the cache fingerprint hashes (see includes/cache.php). Each distinct custom
 * ladder therefore produces its own cache entry naturally; no special
 * cache-key code is needed for custom ladders.
 *
 * Validation is strict-but-silent: invalid payloads fall back to the default
 * with no error UI or exceptions. A bad share-link should never blow up the
 * page for a recipient.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hard guards to bound URL/payload abuse. These also feed the editor UI's
// client-side limits so the two stay in sync.
const WPORGCD_LADDER_MAX_PAYLOAD_BYTES = 32768;
const WPORGCD_LADDER_MAX_STEPS         = 20;
const WPORGCD_LADDER_MAX_REQS_PER_STEP = 50;
const WPORGCD_LADDER_MAX_TITLE_LEN     = 80;
const WPORGCD_LADDER_MAX_MIN_VALUE     = 1000000;

/**
 * Resolve the active ladder for this request.
 *
 * Reads `$_GET['ladder']` once per request: on a successful decode + validate,
 * returns the sanitized custom ladder; on any failure (or absence) returns
 * `wporgcd_get_default_ladders()`. Memoized via static for cheap repeat calls
 * (the cache-key composer and the ladder view both hit this).
 *
 * @return array Ladders keyed by id, in evaluation order.
 */
function wporgcd_get_ladders() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only override of public analytics view config; payload is base64+JSON-decoded and shape-validated below before any use.
	$raw = isset( $_GET['ladder'] ) ? (string) wp_unslash( $_GET['ladder'] ) : '';
	if ( '' !== $raw ) {
		$decoded = wporgcd_decode_ladders( $raw );
		if ( null !== $decoded ) {
			$validated = wporgcd_validate_ladders( $decoded );
			if ( null !== $validated && ! empty( $validated ) ) {
				$cached = $validated;
				return $cached;
			}
		}
	}

	$cached = wporgcd_get_default_ladders();
	return $cached;
}

/**
 * Whether the active ladder is a custom (URL-supplied) ladder.
 *
 * Re-runs decode + validate rather than caching its own flag so it stays
 * consistent with wporgcd_get_ladders() under any test reset of $_GET.
 *
 * @return bool True iff `?ladder=` decoded and validated to a non-empty array.
 */
function wporgcd_is_custom_ladder() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
	if ( empty( $_GET['ladder'] ) ) {
		return false;
	}
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only; payload is base64+JSON-decoded and shape-validated inside decode/validate below.
	$raw = (string) wp_unslash( $_GET['ladder'] );

	$decoded = wporgcd_decode_ladders( $raw );
	if ( null === $decoded ) {
		return false;
	}
	$validated = wporgcd_validate_ladders( $decoded );
	return null !== $validated && ! empty( $validated );
}

/**
 * Short fingerprint of the active ladder for display in UI badges.
 *
 * Stable across requests for the same ladder shape (it's just a prefix of the
 * md5 of the JSON-encoded array), so two users sharing the same custom ladder
 * see the same #abc12345 tag.
 *
 * @return string 8-hex-digit hash.
 */
function wporgcd_get_ladder_fingerprint() {
	return substr( md5( (string) wp_json_encode( wporgcd_get_ladders() ) ), 0, 8 );
}

/**
 * Encode a ladder array into a URL-safe base64 string.
 *
 * Format: base64url(wp_json_encode($ladders)). No padding, `+/` swapped to
 * `-_`. Pairs with wporgcd_decode_ladders() below.
 *
 * @param array $ladders Ladder array (typically post-validation).
 * @return string base64url-encoded JSON.
 */
function wporgcd_encode_ladders( $ladders ) {
	$json = (string) wp_json_encode( $ladders );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport of a structured config blob; not used to obfuscate code.
	$base64 = base64_encode( $json );
	return rtrim( strtr( $base64, '+/', '-_' ), '=' );
}

/**
 * Decode a base64url-encoded ladder string into a PHP array.
 *
 * Returns null on any decode failure or if the raw or decoded payload would
 * exceed WPORGCD_LADDER_MAX_PAYLOAD_BYTES — the guard runs both pre- and
 * post-decode so a small URL can't expand into something arbitrarily large.
 *
 * Validation lives separately in wporgcd_validate_ladders(); decode here is
 * concerned only with shape ("is it a JSON array?").
 *
 * @param string $blob URL-supplied string.
 * @return array|null Decoded array or null on failure.
 */
function wporgcd_decode_ladders( $blob ) {
	if ( ! is_string( $blob ) || '' === $blob ) {
		return null;
	}
	if ( strlen( $blob ) > WPORGCD_LADDER_MAX_PAYLOAD_BYTES ) {
		return null;
	}
	$base64 = strtr( $blob, '-_', '+/' );
	$pad    = strlen( $base64 ) % 4;
	if ( $pad ) {
		$base64 .= str_repeat( '=', 4 - $pad );
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the URL transport of wporgcd_encode_ladders(); strict mode catches non-base64 input.
	$json = base64_decode( $base64, true );
	if ( false === $json ) {
		return null;
	}
	if ( strlen( $json ) > WPORGCD_LADDER_MAX_PAYLOAD_BYTES ) {
		return null;
	}
	$decoded = json_decode( $json, true );
	if ( ! is_array( $decoded ) ) {
		return null;
	}
	return $decoded;
}

/**
 * Validate and sanitize a decoded ladder array.
 *
 * Returns a cleaned copy on success or null when the shape is so broken that
 * nothing can be salvaged (non-array top level, zero or > MAX_STEPS steps,
 * any step missing a non-empty title). Individual unrecognized event types
 * and out-of-range mins are silently dropped so a partially-stale link still
 * produces *something* meaningful — only structural failures fall through to
 * the default.
 *
 * Step IDs are derived from the input keys (sanitize_key'd) and uniquified
 * with a `-N` suffix on collision; this keeps the array shape compatible with
 * wporgcd_check_ladder_requirements() and the cache fingerprint.
 *
 * @param array $ladders Raw decoded ladder array.
 * @return array|null Sanitized ladders or null.
 */
function wporgcd_validate_ladders( $ladders ) {
	if ( ! is_array( $ladders ) ) {
		return null;
	}
	$step_count = count( $ladders );
	if ( $step_count === 0 || $step_count > WPORGCD_LADDER_MAX_STEPS ) {
		return null;
	}

	$event_types = wporgcd_get_event_types();
	$excluded    = array_flip( wporgcd_get_excluded_event_types() );
	$known_types = array_diff_key( $event_types, $excluded );

	$out      = array();
	$used_ids = array();

	foreach ( $ladders as $key => $step ) {
		if ( ! is_array( $step ) ) {
			return null;
		}

		$title = isset( $step['title'] ) ? (string) $step['title'] : '';
		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return null;
		}
		if ( strlen( $title ) > WPORGCD_LADDER_MAX_TITLE_LEN ) {
			$title = substr( $title, 0, WPORGCD_LADDER_MAX_TITLE_LEN );
		}

		$id = sanitize_key( (string) $key );
		if ( '' === $id ) {
			$id = sanitize_key( $title );
		}
		if ( '' === $id ) {
			$id = 'step';
		}
		// Uniquify with -N suffix on collision so identical sanitized keys
		// (e.g. two steps both named "Step") don't clobber each other.
		$base = $id;
		$i    = 2;
		while ( isset( $used_ids[ $id ] ) ) {
			$id = $base . '-' . $i;
			++$i;
		}
		$used_ids[ $id ] = true;

		$reqs_in = isset( $step['requirements'] ) && is_array( $step['requirements'] )
			? $step['requirements']
			: array();
		if ( count( $reqs_in ) > WPORGCD_LADDER_MAX_REQS_PER_STEP ) {
			$reqs_in = array_slice( $reqs_in, 0, WPORGCD_LADDER_MAX_REQS_PER_STEP );
		}

		$reqs_out = array();
		foreach ( $reqs_in as $req ) {
			if ( ! is_array( $req ) ) {
				continue;
			}
			$type = isset( $req['event_type'] ) ? (string) $req['event_type'] : '';
			if ( '' === $type || ! isset( $known_types[ $type ] ) ) {
				continue;
			}
			$min = isset( $req['min'] ) ? (int) $req['min'] : 0;
			if ( $min < 1 || $min > WPORGCD_LADDER_MAX_MIN_VALUE ) {
				continue;
			}
			$reqs_out[] = array(
				'event_type' => $type,
				'min'        => $min,
			);
		}

		$out[ $id ] = array(
			'title'        => $title,
			'requirements' => $reqs_out,
		);
	}

	return $out;
}
