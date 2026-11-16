<?php
/**
 * The scraper's way in. Three calls, all signed in with the importer's
 * application password:
 *
 *   POST /wp-json/aum-directory/v1/dealers   { "dealers": [...] }
 *   POST /wp-json/aum-directory/v1/listings  { "listings": [...] }   (up to 100 per call)
 *   POST /wp-json/aum-directory/v1/finalize  { "dealer_ids": [...], "active_ids": [...], "run_at": "..." }
 *   GET  /wp-json/aum-directory/v1/status
 *
 * Listings are matched on the scraper's ID, so sending the same data twice changes nothing.
 */

defined( 'ABSPATH' ) || exit;

const AUM_MAX_LISTINGS_PER_CALL = 100;

add_action( 'rest_api_init', function () {
	$can_import = static fn() => current_user_can( 'aum_import_stock' );
	$ns         = 'aum-directory/v1';
	register_rest_route( $ns, '/dealers', array( 'methods' => 'POST', 'callback' => 'aum_rest_dealers', 'permission_callback' => $can_import ) );
	register_rest_route( $ns, '/listings', array( 'methods' => 'POST', 'callback' => 'aum_rest_listings', 'permission_callback' => $can_import ) );
	register_rest_route( $ns, '/finalize', array( 'methods' => 'POST', 'callback' => 'aum_rest_finalize', 'permission_callback' => $can_import ) );
	register_rest_route( $ns, '/status', array( 'methods' => 'GET', 'callback' => 'aum_rest_status', 'permission_callback' => $can_import ) );
} );

/* ------------------------------------------------------------------ helpers */

function aum_clean_value( $value, string $type ) {
	if ( null === $value || '' === $value ) {
		return null;
	}
	switch ( $type ) {
		case 'number':
			return is_numeric( $value ) ? (float) $value : null;
		case 'integer':
			return is_numeric( $value ) ? (int) $value : null;
		case 'boolean':
			return (bool) $value;
		case 'array':
			$out = array();
			foreach ( (array) $value as $url ) {
				$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
				if ( $url ) {
					$out[] = $url;
				}
			}
			return array_slice( $out, 0, 6 );
		default:
			return sanitize_text_field( (string) $value );
	}
}

function aum_save_meta( int $post_id, string $key, $value ): void {
	if ( null === $value || array() === $value ) {
		delete_post_meta( $post_id, $key );
	} else {
		update_post_meta( $post_id, $key, $value );
	}
}

/** A well-formed public http(s) address. (No DNS lookup: that's for fetching, and slow.) */
function aum_is_web_url( $url ): bool {
	if ( ! is_string( $url ) || strlen( $url ) > 2000 || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	return in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
		&& ! empty( $parts['host'] ) && str_contains( $parts['host'], '.' );
}

/** A plain one-line summary used as the excerpt (and so as the search-result description). */
function aum_listing_summary( array $l, string $dealer_name, string $town ): string {
	$parts   = array();
	$cond    = $l['condition'] ?? '';
	$cat     = trim( preg_replace( '/\s*\(speed not stated\)/', '', strtolower( $l['category'] ?? '' ) ) );
	$title   = $l['clean_title'] ?? $l['title'] ?? '';
	$lead    = ( in_array( $cond, array( 'Ex-demo', 'Used', 'Reconditioned' ), true ) ? $cond . ' ' : '' ) . $title;
	$parts[] = trim( $lead ) . ( $cat && 'other' !== $cat ? " ($cat)" : '' );
	if ( ! empty( $l['price_gbp'] ) ) {
		$parts[] = ( ! empty( $l['price_is_from'] ) ? 'from ' : 'for ' ) . '£' . number_format( (float) $l['price_gbp'], ( floor( (float) $l['price_gbp'] ) == (float) $l['price_gbp'] ) ? 0 : 2 );
	}
	$where = trim( $dealer_name . ( $town ? ", $town" : '' ) );
	$text  = implode( ' ', $parts ) . ( $where ? " at $where." : '.' );
	if ( ! empty( $l['warranty_months'] ) ) {
		$text .= ' ' . (int) $l['warranty_months'] . '-month warranty.';
	}
	return ucfirst( $text );
}

/* ------------------------------------------------------------------ dealers */

function aum_rest_dealers( WP_REST_Request $req ) {
	$dealers = $req->get_param( 'dealers' );
	if ( ! is_array( $dealers ) ) {
		return new WP_Error( 'aum_bad_request', 'Send {"dealers": [...]}.', array( 'status' => 400 ) );
	}
	$result = array( 'created' => 0, 'updated' => 0, 'errors' => array() );
	foreach ( $dealers as $i => $d ) {
		$ext_id = isset( $d['id'] ) ? sanitize_key( $d['id'] ) : '';
		$name   = isset( $d['name'] ) ? sanitize_text_field( $d['name'] ) : '';
		if ( ! $ext_id || ! $name ) {
			$result['errors'][] = "dealer $i: id and name are required";
			continue;
		}
		$post_id = aum_find_post_id( 'aum_dealer', 'aum_ext_id', $ext_id );
		$data    = array( 'post_type' => 'aum_dealer', 'post_title' => $name );
		if ( $post_id ) {
			// Keep anything a person wrote on the dealer page (description, photo); refresh the facts.
			$data['ID'] = $post_id;
			wp_update_post( $data );
			$result['updated']++;
		} else {
			$data['post_status'] = 'publish';
			$data['post_name']   = sanitize_title( $name . ' ' . ( $d['town'] ?? '' ) );
			$post_id             = wp_insert_post( $data, true );
			if ( is_wp_error( $post_id ) ) {
				$result['errors'][] = "dealer $ext_id: " . $post_id->get_error_message();
				continue;
			}
			$result['created']++;
		}
		$d['id'] = $ext_id;
		if ( isset( $d['other_branches'] ) && is_array( $d['other_branches'] ) ) {
			$d['other_branches'] = implode( '; ', $d['other_branches'] );
		}
		if ( isset( $d['website'] ) && ! aum_is_web_url( $d['website'] ) ) {
			$d['website'] = null;
		}
		foreach ( aum_dealer_fields() as $key => [ $type, , $src ] ) {
			if ( $src && array_key_exists( $src, $d ) ) {
				aum_save_meta( $post_id, $key, aum_clean_value( $d[ $src ], $type ) );
			}
		}
	}
	return rest_ensure_response( $result );
}

/* ------------------------------------------------------------------ listings */

function aum_rest_listings( WP_REST_Request $req ) {
	$listings = $req->get_param( 'listings' );
	if ( ! is_array( $listings ) ) {
		return new WP_Error( 'aum_bad_request', 'Send {"listings": [...]}.', array( 'status' => 400 ) );
	}
	if ( count( $listings ) > AUM_MAX_LISTINGS_PER_CALL ) {
		return new WP_Error( 'aum_too_many', 'Send at most ' . AUM_MAX_LISTINGS_PER_CALL . ' listings per call.', array( 'status' => 413 ) );
	}
	wp_defer_term_counting( true );
	$result = array( 'created' => 0, 'updated' => 0, 'republished' => 0, 'errors' => array() );
	foreach ( $listings as $i => $l ) {
		$outcome = aum_upsert_listing( is_array( $l ) ? $l : array() );
		if ( is_wp_error( $outcome ) ) {
			$result['errors'][] = ( $l['id'] ?? "item $i" ) . ': ' . $outcome->get_error_message();
		} else {
			$result[ $outcome ]++;
		}
	}
	wp_defer_term_counting( false );
	return rest_ensure_response( $result );
}

/** @return string|WP_Error 'created', 'updated' or 'republished' */
function aum_upsert_listing( array $l ) {
	$source_id = isset( $l['id'] ) ? sanitize_text_field( (string) $l['id'] ) : '';
	$dealer_id = isset( $l['dealer_id'] ) ? sanitize_key( $l['dealer_id'] ) : '';
	$title     = sanitize_text_field( (string) ( $l['clean_title'] ?? $l['title'] ?? '' ) );
	if ( ! $source_id || ! $dealer_id || ! $title ) {
		return new WP_Error( 'aum_missing', 'id, dealer_id and title are required' );
	}
	if ( ! aum_is_web_url( $l['url'] ?? null ) ) {
		return new WP_Error( 'aum_url', 'url must be the dealer\'s http(s) page for the item' );
	}
	if ( ! isset( $l['price_gbp'] ) || ! is_numeric( $l['price_gbp'] ) || (float) $l['price_gbp'] <= 0 ) {
		return new WP_Error( 'aum_price', 'price_gbp must be a positive number' );
	}
	$dealer_post = aum_dealer_post_for( $dealer_id );
	if ( ! $dealer_post ) {
		return new WP_Error( 'aum_dealer', "unknown dealer $dealer_id - send it to /dealers first" );
	}
	$dealer_name = get_the_title( $dealer_post );
	$town        = (string) get_post_meta( $dealer_post, 'aum_town', true );

	$post_id = aum_find_post_id( 'aum_listing', 'aum_source_id', $source_id );
	$data    = array(
		'post_type'    => 'aum_listing',
		'post_title'   => $title,
		'post_excerpt' => aum_listing_summary( $l, $dealer_name, $town ),
		'post_status'  => 'publish',
	);
	$outcome = 'created';
	if ( $post_id ) {
		$outcome    = in_array( get_post_status( $post_id ), array( 'aum_sold', 'draft' ), true ) ? 'republished' : 'updated';
		$data['ID'] = $post_id;
		if ( 'trash' === get_post_status( $post_id ) ) {
			return 'updated'; // someone binned it by hand: leave it binned
		}
		$saved = wp_update_post( $data, true );
	} else {
		$data['post_name'] = sanitize_title( $title . ' ' . $dealer_name );
		$saved             = wp_insert_post( $data, true );
	}
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$post_id = (int) $saved;

	$l['id']        = $source_id;
	$l['dealer_id'] = $dealer_id;
	foreach ( aum_listing_fields() as $key => [ $type, , $src ] ) {
		if ( $src && array_key_exists( $src, $l ) ) {
			aum_save_meta( $post_id, $key, aum_clean_value( $l[ $src ], $type ) );
		}
	}
	$images = aum_clean_value( $l['image_urls'] ?? array(), 'array' ) ?? array();
	aum_save_meta( $post_id, 'aum_image_url', $images[0] ?? null );
	delete_post_meta( $post_id, 'aum_removed_at' );

	$eq   = aum_equipment_terms()[ $l['category'] ?? 'Other' ] ?? aum_equipment_terms()['Other'];
	$cond = aum_condition_terms()[ $l['condition'] ?? 'Not stated' ] ?? aum_condition_terms()['Not stated'];
	wp_set_object_terms( $post_id, $eq[0], 'aum_equipment' );
	wp_set_object_terms( $post_id, $cond[0], 'aum_condition' );
	$brand = isset( $l['brand'] ) ? sanitize_text_field( (string) $l['brand'] ) : '';
	wp_set_object_terms( $post_id, $brand ? array( $brand ) : array(), 'aum_brand' );

	return $outcome;
}

/* ------------------------------------------------------------------ finalize */

/**
 * After all listings are sent: any published listing of these dealers that the
 * scraper no longer reports has been sold or taken down, so it leaves the site.
 * Only the dealers named here are touched, so one broken dealer can't empty the others.
 */
function aum_rest_finalize( WP_REST_Request $req ) {
	$dealer_ids = array_map( 'sanitize_key', (array) $req->get_param( 'dealer_ids' ) );
	$active     = array_flip( array_map( 'strval', (array) $req->get_param( 'active_ids' ) ) );
	$run_at     = sanitize_text_field( (string) ( $req->get_param( 'run_at' ) ?: gmdate( 'c' ) ) );
	$force      = (bool) $req->get_param( 'force' );
	$result     = array( 'removed' => 0, 'kept' => 0, 'skipped_dealers' => array() );

	foreach ( $dealer_ids as $dealer_id ) {
		$published = get_posts(
			array(
				'post_type'      => 'aum_listing',
				'post_status'    => 'publish',
				'meta_key'       => 'aum_dealer_ext_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $dealer_id,          // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$gone = array();
		foreach ( $published as $post_id ) {
			if ( isset( $active[ (string) get_post_meta( $post_id, 'aum_source_id', true ) ] ) ) {
				$result['kept']++;
			} else {
				$gone[] = $post_id;
			}
		}
		// Safety net: if most of a dealer's stock vanished at once, the scraper probably broke.
		if ( ! $force && count( $gone ) > 10 && count( $gone ) > 0.5 * count( $published ) ) {
			$result['skipped_dealers'][] = $dealer_id . ' (would remove ' . count( $gone ) . ' of ' . count( $published ) . ')';
			continue;
		}
		foreach ( $gone as $post_id ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'aum_sold' ) );
			update_post_meta( $post_id, 'aum_removed_at', $run_at );
			$result['removed']++;
		}
		$dealer_post = aum_dealer_post_for( $dealer_id );
		if ( $dealer_post ) {
			update_post_meta( $dealer_post, 'aum_listing_count', count( $published ) - count( $gone ) );
		}
	}
	update_option(
		'aum_last_sync',
		array(
			'run_at'   => $run_at,
			'received' => gmdate( 'c' ),
			'result'   => $result,
		),
		false
	);
	return rest_ensure_response( $result );
}

function aum_rest_status() {
	$counts = wp_count_posts( 'aum_listing' );
	return rest_ensure_response(
		array(
			'plugin_version' => AUM_DIR_VERSION,
			'live_listings'  => (int) ( $counts->publish ?? 0 ),
			'sold_listings'  => (int) ( $counts->aum_sold ?? 0 ),
			'dealers'        => (int) ( wp_count_posts( 'aum_dealer' )->publish ?? 0 ),
			'last_sync'      => get_option( 'aum_last_sync', null ),
		)
	);
}
