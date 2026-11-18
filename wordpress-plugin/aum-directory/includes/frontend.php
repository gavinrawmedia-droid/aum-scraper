<?php
/**
 * Front-end pieces for the Breakdance design (added in 0.2.0).
 *
 * 1. Ready-to-show fields. Breakdance templates bind these like any custom field. They are
 *    worked out when the page is shown, so nothing extra is stored.
 *      Listings: aum_price_text, aum_was_text, aum_saving_text, aum_year_text, aum_warranty_text,
 *                aum_card_specs, aum_condition_name, aum_equipment_name, aum_brand_name,
 *                aum_dealer_name, aum_dealer_town, aum_dealer_place, aum_dealer_link,
 *                aum_go_url, aum_photo_credit
 *      Dealers:  aum_address_full, aum_directions_url, aum_phone_link, aum_website_host,
 *                aum_listing_count_text, aum_stock_url
 *
 * 2. Filtering from the address bar on /listings/, /used/<type>/ and /brand/<brand>/:
 *      ?type=road-scooters&brand=pride&dealer=mobility-world&condition[]=ex-demo
 *      &min_price=500&max_price=2000&warranty=1&sort=price_asc|price_desc|newest|year
 *
 * 3. Shortcodes for templates:
 *      [aum_filters] [aum_active_filters] [aum_results_count] [aum_no_results]
 *      [aum_dealer_map] [aum_dealer_map dealer="current" search="no" list="no" height="320"]
 *      [aum_gallery] [aum_buy_box] [aum_key_facts] [aum_questions] [aum_dealer_box] [aum_dealer_contact]
 *      Compare (0.3.0, see compare.php): [aum_compare_button] [aum_compare]
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================== 1. ready-to-show fields */

function aum_virtual_keys(): array {
	static $keys = null;
	if ( null === $keys ) {
		$keys = array_flip(
			array(
				'aum_price_text', 'aum_was_text', 'aum_saving_text', 'aum_year_text', 'aum_warranty_text',
				'aum_card_specs', 'aum_condition_name', 'aum_equipment_name', 'aum_brand_name',
				'aum_dealer_name', 'aum_dealer_town', 'aum_dealer_place', 'aum_dealer_link', 'aum_go_url', 'aum_photo_credit',
				'aum_address_full', 'aum_directions_url', 'aum_phone_link', 'aum_website_host',
				'aum_listing_count_text', 'aum_stock_url',
			)
		);
	}
	return $keys;
}

add_filter(
	'get_post_metadata',
	function ( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || ! is_string( $meta_key ) || '' === $meta_key || ! isset( aum_virtual_keys()[ $meta_key ] ) ) {
			return $value;
		}
		$computed = aum_virtual_value( (int) $object_id, $meta_key );
		return null === $computed ? $value : array( $computed );
	},
	10,
	4
);

function aum_virtual_value( int $id, string $key ): ?string {
	$type = get_post_type( $id );
	if ( 'aum_listing' === $type ) {
		return aum_listing_virtual( $id, $key );
	}
	if ( 'aum_dealer' === $type ) {
		return aum_dealer_virtual( $id, $key );
	}
	return null;
}

function aum_num( int $id, string $key ): float {
	return (float) get_post_meta( $id, $key, true );
}

/** First term of a listing in a taxonomy, as 'name' or 'slug'. "Condition not stated" counts as none. */
function aum_first_term( int $id, string $tax, string $field = 'name' ): string {
	$terms = get_the_terms( $id, $tax );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	if ( 'aum_condition' === $tax && 'not-stated' === $terms[0]->slug ) {
		return '';
	}
	return 'slug' === $field ? $terms[0]->slug : html_entity_decode( $terms[0]->name, ENT_QUOTES );
}

function aum_listing_dealer( int $id ): int {
	return aum_dealer_post_for( (string) get_post_meta( $id, 'aum_dealer_ext_id', true ) );
}

function aum_card_specs( int $id ): string {
	$parts  = array();
	$detail = trim( (string) get_post_meta( $id, 'aum_type_detail', true ) );
	if ( $detail ) {
		$parts[] = $detail;
	}
	if ( aum_num( $id, 'aum_top_speed_mph' ) > 0 ) {
		$parts[] = aum_fmt_number( aum_num( $id, 'aum_top_speed_mph' ) ) . ' mph';
	}
	if ( aum_num( $id, 'aum_range_miles' ) > 0 ) {
		$parts[] = 'up to ' . aum_fmt_number( aum_num( $id, 'aum_range_miles' ) ) . ' miles';
	}
	if ( aum_num( $id, 'aum_max_user_weight_kg' ) > 0 ) {
		$parts[] = 'carries ' . aum_fmt_number( aum_num( $id, 'aum_max_user_weight_kg' ) ) . ' kg';
	}
	return implode( ' · ', $parts );
}

function aum_listing_virtual( int $id, string $key ): ?string {
	$price = aum_num( $id, 'aum_price_gbp' );
	$was   = aum_num( $id, 'aum_was_price_gbp' );
	switch ( $key ) {
		case 'aum_price_text':
			if ( $price <= 0 ) {
				return 'Price on request';
			}
			return ( get_post_meta( $id, 'aum_price_is_from', true ) ? 'From ' : '' ) . aum_money( $price );
		case 'aum_was_text':
			return ( $price > 0 && $was > $price ) ? 'Was ' . aum_money( $was ) : '';
		case 'aum_saving_text':
			return ( $price > 0 && $was > $price ) ? 'Save ' . aum_money( $was - $price ) : '';
		case 'aum_year_text':
			$year = (int) get_post_meta( $id, 'aum_year', true );
			return $year > 1990 ? (string) $year : '';
		case 'aum_warranty_text':
			$months = (int) get_post_meta( $id, 'aum_warranty_months', true );
			return $months > 0 ? $months . '-month warranty' : '';
		case 'aum_card_specs':
			return aum_card_specs( $id );
		case 'aum_condition_name':
			return aum_first_term( $id, 'aum_condition' );
		case 'aum_equipment_name':
			return aum_first_term( $id, 'aum_equipment' );
		case 'aum_brand_name':
			return aum_first_term( $id, 'aum_brand' );
	}
	$dealer = aum_listing_dealer( $id );
	switch ( $key ) {
		case 'aum_dealer_name':
			return $dealer ? html_entity_decode( get_the_title( $dealer ), ENT_QUOTES ) : '';
		case 'aum_dealer_town':
			return $dealer ? (string) get_post_meta( $dealer, 'aum_town', true ) : '';
		case 'aum_dealer_place':
			return $dealer ? implode( ', ', array_filter( array( html_entity_decode( get_the_title( $dealer ), ENT_QUOTES ), (string) get_post_meta( $dealer, 'aum_town', true ) ) ) ) : '';
		case 'aum_dealer_link':
			return $dealer ? (string) get_permalink( $dealer ) : '';
		case 'aum_go_url':
			return aum_go_url( $id, 'listing' );
		case 'aum_photo_credit':
			return 'Photo: ' . ( $dealer ? html_entity_decode( get_the_title( $dealer ), ENT_QUOTES ) : 'the dealer' );
	}
	return null;
}

function aum_dealer_virtual( int $id, string $key ): ?string {
	$m = static fn( string $k ) => trim( (string) get_post_meta( $id, $k, true ) );
	switch ( $key ) {
		case 'aum_address_full':
			return implode( ', ', array_filter( array( $m( 'aum_address' ), $m( 'aum_town' ), $m( 'aum_postcode' ) ) ) );
		case 'aum_directions_url':
			$lat = (float) $m( 'aum_lat' );
			$lng = (float) $m( 'aum_lng' );
			$to  = ( $lat && $lng ) ? $lat . ',' . $lng : implode( ', ', array_filter( array( html_entity_decode( get_the_title( $id ), ENT_QUOTES ), $m( 'aum_address' ), $m( 'aum_town' ), $m( 'aum_postcode' ) ) ) );
			return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $to );
		case 'aum_phone_link':
			return $m( 'aum_phone' ) ? 'tel:' . preg_replace( '/[^0-9+]/', '', $m( 'aum_phone' ) ) : '';
		case 'aum_website_host':
			$host = (string) wp_parse_url( $m( 'aum_website' ), PHP_URL_HOST );
			return preg_replace( '/^www\./', '', $host );
		case 'aum_listing_count_text':
			$n = (int) $m( 'aum_listing_count' );
			return $n . ( 1 === $n ? ' listing' : ' listings' );
		case 'aum_stock_url':
			return add_query_arg( 'dealer', rawurlencode( $m( 'aum_ext_id' ) ), (string) get_post_type_archive_link( 'aum_listing' ) );
	}
	return null;
}

/* ================================================================== 2. filtering */

function aum_sort_options(): array {
	return array(
		'price_asc'  => 'Price, lowest first',
		'price_desc' => 'Price, highest first',
		'newest'     => 'Newest listings',
		'year'       => 'Newest model year',
	);
}

function aum_price_steps(): array {
	return array( 250, 500, 750, 1000, 1500, 2000, 3000, 4000, 5000 );
}

/** The filters in the address bar, cleaned. */
function aum_request_filters(): array {
	static $f = null;
	if ( null !== $f ) {
		return $f;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filters.
	$get  = static fn( string $k ): string => ( isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
	$cond = isset( $_GET['condition'] ) ? wp_unslash( $_GET['condition'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
	// phpcs:enable
	$cond = is_array( $cond ) ? $cond : explode( ',', (string) $cond );
	$sort = $get( 'sort' );
	$f    = array(
		'type'      => sanitize_title( $get( 'type' ) ),
		'brand'     => sanitize_title( $get( 'brand' ) ),
		'dealer'    => sanitize_key( $get( 'dealer' ) ),
		'condition' => array_values( array_unique( array_filter( array_map( 'sanitize_title', array_map( 'strval', $cond ) ) ) ) ),
		'min_price' => max( 0, (int) $get( 'min_price' ) ),
		'max_price' => max( 0, (int) $get( 'max_price' ) ),
		'warranty'  => '1' === $get( 'warranty' ),
		'sort'      => isset( aum_sort_options()[ $sort ] ) ? $sort : 'price_asc',
	);
	if ( $f['max_price'] && $f['min_price'] > $f['max_price'] ) {
		$f['min_price'] = 0;
	}
	return $f;
}

/** The equipment type or brand fixed by the archive being viewed. */
function aum_context_terms( ?WP_Query $q = null ): array {
	$q = $q ?: ( $GLOBALS['wp_query'] ?? null );
	if ( ! $q instanceof WP_Query ) {
		return array( 'type' => '', 'brand' => '' );
	}
	return array(
		'type'  => $q->is_tax( 'aum_equipment' ) ? sanitize_title( (string) $q->get( 'aum_equipment' ) ) : '',
		'brand' => $q->is_tax( 'aum_brand' ) ? sanitize_title( (string) $q->get( 'aum_brand' ) ) : '',
	);
}

function aum_effective_filters( ?WP_Query $q = null ): array {
	$f   = aum_request_filters();
	$ctx = aum_context_terms( $q );
	foreach ( $ctx as $k => $v ) {
		if ( $v ) {
			$f[ $k ] = $v;
		}
	}
	return $f;
}

function aum_is_listing_archive( ?WP_Query $q = null ): bool {
	$q = $q ?: ( $GLOBALS['wp_query'] ?? null );
	return $q instanceof WP_Query && ( $q->is_post_type_archive( 'aum_listing' ) || $q->is_tax( array( 'aum_equipment', 'aum_brand' ) ) );
}

add_action(
	'pre_get_posts',
	function ( WP_Query $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! aum_is_listing_archive( $q ) ) {
			return;
		}
		$f   = aum_effective_filters( $q );
		$ctx = aum_context_terms( $q );
		$tax = array();
		if ( $f['type'] && ! $ctx['type'] ) {
			$tax[] = array( 'taxonomy' => 'aum_equipment', 'field' => 'slug', 'terms' => array( $f['type'] ) );
		}
		if ( $f['brand'] && ! $ctx['brand'] ) {
			$tax[] = array( 'taxonomy' => 'aum_brand', 'field' => 'slug', 'terms' => array( $f['brand'] ) );
		}
		if ( $f['condition'] ) {
			$tax[] = array( 'taxonomy' => 'aum_condition', 'field' => 'slug', 'terms' => $f['condition'] );
		}
		if ( $tax ) {
			$q->set( 'tax_query', array_merge( array( 'relation' => 'AND' ), $tax ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$meta = array();
		if ( $f['dealer'] ) {
			$meta[] = array( 'key' => 'aum_dealer_ext_id', 'value' => $f['dealer'] );
		}
		if ( $f['min_price'] ) {
			$meta[] = array( 'key' => 'aum_price_gbp', 'value' => $f['min_price'], 'type' => 'NUMERIC', 'compare' => '>=' );
		}
		if ( $f['max_price'] ) {
			$meta[] = array( 'key' => 'aum_price_gbp', 'value' => array( 1, $f['max_price'] ), 'type' => 'NUMERIC', 'compare' => 'BETWEEN' );
		}
		if ( $f['warranty'] ) {
			$meta[] = array( 'key' => 'aum_warranty_months', 'value' => 0, 'type' => 'NUMERIC', 'compare' => '>' );
		}
		if ( $meta ) {
			$q->set( 'meta_query', array_merge( array( 'relation' => 'AND' ), $meta ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		switch ( $f['sort'] ) {
			case 'price_desc':
				$q->set( 'meta_key', 'aum_price_gbp' ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$q->set( 'orderby', 'meta_value_num' );
				$q->set( 'order', 'DESC' );
				break;
			case 'newest':
				$q->set( 'meta_key', '' ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$q->set( 'orderby', 'date' );
				$q->set( 'order', 'DESC' );
				break;
			case 'year':
				$q->set( 'meta_key', 'aum_year' ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$q->set( 'orderby', array( 'meta_value_num' => 'DESC', 'date' => 'DESC' ) );
				break;
			default:
				$q->set( 'meta_key', 'aum_price_gbp' ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$q->set( 'orderby', 'meta_value_num' );
				$q->set( 'order', 'ASC' );
		}
	},
	20
);

/** Listings with no price ("price on request") go to the end when sorting by price. */
add_filter(
	'posts_orderby',
	function ( string $orderby, WP_Query $q ) {
		global $wpdb;
		if ( is_admin() || ! $q->is_main_query() || ! aum_is_listing_archive( $q ) ) {
			return $orderby;
		}
		$sort = aum_request_filters()['sort'];
		if ( 'price_asc' === $sort && str_contains( $orderby, "{$wpdb->postmeta}.meta_value+0" ) ) {
			return "({$wpdb->postmeta}.meta_value+0 <= 0) ASC, " . $orderby;
		}
		return $orderby;
	},
	10,
	2
);

/* ------------------------------------------------------------------ live counts */

/** One small row per live listing, cached until stock changes. */
function aum_facet_rows(): array {
	$ver    = md5( (string) wp_json_encode( get_option( 'aum_last_sync' ) ) . '|' . get_option( 'aum_facets_ver', '0' ) );
	$cached = get_transient( 'aum_facet_rows' );
	if ( is_array( $cached ) && ( $cached['ver'] ?? '' ) === $ver ) {
		return $cached['rows'];
	}
	$ids = get_posts(
		array(
			'post_type'      => 'aum_listing',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);
	update_meta_cache( 'post', $ids );
	update_object_term_cache( $ids, 'aum_listing' );
	$rows = array();
	foreach ( $ids as $id ) {
		$cond   = get_the_terms( $id, 'aum_condition' );
		$rows[] = array(
			'type'      => aum_first_term( $id, 'aum_equipment', 'slug' ),
			'brand'     => aum_first_term( $id, 'aum_brand', 'slug' ),
			'condition' => ( $cond && ! is_wp_error( $cond ) ) ? $cond[0]->slug : '',
			'dealer'    => (string) get_post_meta( $id, 'aum_dealer_ext_id', true ),
			'price'     => (float) get_post_meta( $id, 'aum_price_gbp', true ),
			'warranty'  => (int) get_post_meta( $id, 'aum_warranty_months', true ),
		);
	}
	set_transient( 'aum_facet_rows', array( 'ver' => $ver, 'rows' => $rows ), 12 * HOUR_IN_SECONDS );
	return $rows;
}

function aum_bump_facets(): void {
	update_option( 'aum_facets_ver', (string) microtime( true ), false );
}
add_action( 'save_post_aum_listing', 'aum_bump_facets' );
add_action( 'deleted_post', 'aum_bump_facets' );
add_action( 'set_object_terms', 'aum_bump_facets' );

function aum_row_matches( array $r, array $f, string $skip = '' ): bool {
	foreach ( array( 'type', 'brand', 'dealer' ) as $k ) {
		if ( $skip !== $k && $f[ $k ] && $r[ $k ] !== $f[ $k ] ) {
			return false;
		}
	}
	if ( 'condition' !== $skip && $f['condition'] && ! in_array( $r['condition'], $f['condition'], true ) ) {
		return false;
	}
	if ( 'price' !== $skip ) {
		if ( $f['min_price'] && $r['price'] < $f['min_price'] ) {
			return false;
		}
		if ( $f['max_price'] && ( $r['price'] <= 0 || $r['price'] > $f['max_price'] ) ) {
			return false;
		}
	}
	if ( 'warranty' !== $skip && $f['warranty'] && $r['warranty'] <= 0 ) {
		return false;
	}
	return true;
}

/** How many listings each option of one filter would give, with every other filter applied. */
function aum_facet_counts( array $f, string $facet ): array {
	$counts = array();
	foreach ( aum_facet_rows() as $r ) {
		if ( aum_row_matches( $r, $f, $facet ) ) {
			$key            = 'warranty' === $facet ? ( $r['warranty'] > 0 ? '1' : '0' ) : (string) $r[ $facet ];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}
	return $counts;
}

/* ------------------------------------------------------------------ filter shortcodes */

function aum_filters_action_url(): string {
	if ( is_tax( array( 'aum_equipment', 'aum_brand' ) ) ) {
		$link = get_term_link( get_queried_object() );
		if ( ! is_wp_error( $link ) ) {
			return $link;
		}
	}
	return (string) get_post_type_archive_link( 'aum_listing' );
}

/** The current filters as address-bar arguments, without page numbers. */
function aum_filter_args( array $f, array $drop = array() ): array {
	$ctx  = aum_context_terms();
	$args = array();
	foreach ( array( 'type', 'brand', 'dealer' ) as $k ) {
		$fixed = $ctx[ $k ] ?? '';
		if ( $f[ $k ] && ! $fixed ) {
			$args[ $k ] = $f[ $k ];
		}
	}
	if ( $f['condition'] ) {
		$args['condition'] = implode( ',', $f['condition'] );
	}
	foreach ( array( 'min_price', 'max_price' ) as $k ) {
		if ( $f[ $k ] ) {
			$args[ $k ] = $f[ $k ];
		}
	}
	if ( $f['warranty'] ) {
		$args['warranty'] = 1;
	}
	if ( 'price_asc' !== $f['sort'] ) {
		$args['sort'] = $f['sort'];
	}
	foreach ( $drop as $k ) {
		unset( $args[ $k ] );
	}
	return $args;
}

function aum_option_html( string $value, string $label, string $current, int $count = -1 ): string {
	$selected = selected( $current, $value, false );
	$disabled = ( 0 === $count && '' === $selected ) ? ' disabled' : '';
	$text     = $count >= 0 ? sprintf( '%s (%d)', $label, $count ) : $label;
	return sprintf( '<option value="%s"%s%s>%s</option>', esc_attr( $value ), $selected, $disabled, esc_html( $text ) );
}

function aum_render_filters(): string {
	$f        = aum_effective_filters();
	$ctx      = aum_context_terms();
	$action   = aum_filters_action_url();
	$uid      = wp_unique_id( 'aumf-' );
	$active   = count( aum_active_filter_list( $f ) );
	$all_link = (string) get_post_type_archive_link( 'aum_listing' );

	wp_enqueue_script( 'aumd-front' );

	ob_start();
	?>
	<form class="aumd-filters" method="get" action="<?php echo esc_url( $action ); ?>" aria-label="Filter listings">
		<details class="aumd-filters-panel" open data-aum-filters>
			<summary class="aumd-filters-summary">
				<span>Filter and sort</span>
				<?php if ( $active ) : ?><span class="aumd-filters-badge"><?php echo (int) $active; ?> on</span><?php endif; ?>
			</summary>
			<div class="aumd-filters-body">

				<?php if ( $ctx['type'] ) : ?>
					<div class="aumd-field">
						<span class="aumd-label">Equipment type</span>
						<p class="aumd-fixed"><?php echo esc_html( aum_term_name( 'aum_equipment', $ctx['type'] ) ); ?> <a href="<?php echo esc_url( add_query_arg( aum_filter_args( $f ), $all_link ) ); ?>">All types</a></p>
					</div>
				<?php else : ?>
					<div class="aumd-field">
						<label class="aumd-label" for="<?php echo esc_attr( $uid ); ?>-type">Equipment type</label>
						<select class="aumd-select" id="<?php echo esc_attr( $uid ); ?>-type" name="type">
							<?php
							$counts = aum_facet_counts( $f, 'type' );
							echo aum_option_html( '', 'All types', $f['type'], array_sum( $counts ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							foreach ( aum_equipment_terms() as [ $slug, $name ] ) {
								if ( ! empty( $counts[ $slug ] ) || $f['type'] === $slug ) {
									echo aum_option_html( $slug, $name, $f['type'], (int) ( $counts[ $slug ] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							}
							?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( $ctx['brand'] ) : ?>
					<div class="aumd-field">
						<span class="aumd-label">Brand</span>
						<p class="aumd-fixed"><?php echo esc_html( aum_term_name( 'aum_brand', $ctx['brand'] ) ); ?> <a href="<?php echo esc_url( add_query_arg( aum_filter_args( $f ), $all_link ) ); ?>">All brands</a></p>
					</div>
				<?php else : ?>
					<div class="aumd-field">
						<label class="aumd-label" for="<?php echo esc_attr( $uid ); ?>-brand">Brand</label>
						<select class="aumd-select" id="<?php echo esc_attr( $uid ); ?>-brand" name="brand">
							<?php
							$counts = aum_facet_counts( $f, 'brand' );
							echo aum_option_html( '', 'All brands', $f['brand'], array_sum( $counts ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							$brands = get_terms( array( 'taxonomy' => 'aum_brand', 'hide_empty' => false, 'orderby' => 'name' ) );
							foreach ( is_wp_error( $brands ) ? array() : $brands as $t ) {
								if ( ! empty( $counts[ $t->slug ] ) || $f['brand'] === $t->slug ) {
									echo aum_option_html( $t->slug, html_entity_decode( $t->name, ENT_QUOTES ), $f['brand'], (int) ( $counts[ $t->slug ] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							}
							?>
						</select>
					</div>
				<?php endif; ?>

				<div class="aumd-field">
					<label class="aumd-label" for="<?php echo esc_attr( $uid ); ?>-dealer">Dealer</label>
					<select class="aumd-select" id="<?php echo esc_attr( $uid ); ?>-dealer" name="dealer">
						<?php
						$counts = aum_facet_counts( $f, 'dealer' );
						echo aum_option_html( '', 'All dealers', $f['dealer'], array_sum( $counts ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						foreach ( aum_dealer_choices() as $ext => $label ) {
							if ( ! empty( $counts[ $ext ] ) || $f['dealer'] === $ext ) {
								echo aum_option_html( $ext, $label, $f['dealer'], (int) ( $counts[ $ext ] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
						}
						?>
					</select>
				</div>

				<fieldset class="aumd-field aumd-price">
					<legend class="aumd-label">Price</legend>
					<div class="aumd-price-row">
						<label class="aumd-sr" for="<?php echo esc_attr( $uid ); ?>-min">Lowest price</label>
						<select class="aumd-select" id="<?php echo esc_attr( $uid ); ?>-min" name="min_price">
							<?php
							echo aum_option_html( '0', 'No minimum', (string) $f['min_price'] ); // phpcs:ignore WordPress.Security.EscapeOutput
							foreach ( aum_price_steps() as $step ) {
								echo aum_option_html( (string) $step, aum_money( $step ), (string) $f['min_price'] ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</select>
						<span class="aumd-to" aria-hidden="true">to</span>
						<label class="aumd-sr" for="<?php echo esc_attr( $uid ); ?>-max">Highest price</label>
						<select class="aumd-select" id="<?php echo esc_attr( $uid ); ?>-max" name="max_price">
							<?php
							echo aum_option_html( '0', 'No maximum', (string) $f['max_price'] ); // phpcs:ignore WordPress.Security.EscapeOutput
							foreach ( aum_price_steps() as $step ) {
								echo aum_option_html( (string) $step, aum_money( $step ), (string) $f['max_price'] ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</select>
					</div>
				</fieldset>

				<?php
				$counts = aum_facet_counts( $f, 'condition' );
				$conds  = array_filter( aum_condition_terms(), static fn( $c ) => 'not-stated' !== $c[0] && ( ! empty( $counts[ $c[0] ] ) || in_array( $c[0], $f['condition'], true ) ) );
				if ( $conds ) :
					?>
					<fieldset class="aumd-field">
						<legend class="aumd-label">Condition</legend>
						<?php foreach ( $conds as [ $slug, $name ] ) : ?>
							<label class="aumd-check">
								<input type="checkbox" name="condition[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $f['condition'], true ) ); ?>>
								<span><?php echo esc_html( sprintf( '%s (%d)', $name, (int) ( $counts[ $slug ] ?? 0 ) ) ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

				<?php $counts = aum_facet_counts( $f, 'warranty' ); ?>
				<fieldset class="aumd-field">
					<legend class="aumd-label">Warranty</legend>
					<label class="aumd-check">
						<input type="checkbox" name="warranty" value="1" <?php checked( $f['warranty'] ); ?>>
						<span><?php echo esc_html( sprintf( 'Warranty stated by the dealer (%d)', (int) ( $counts['1'] ?? 0 ) ) ); ?></span>
					</label>
				</fieldset>

				<div class="aumd-field">
					<label class="aumd-label" for="<?php echo esc_attr( $uid ); ?>-sort">Sort by</label>
					<select class="aumd-select" id="<?php echo esc_attr( $uid ); ?>-sort" name="sort">
						<?php
						foreach ( aum_sort_options() as $value => $label ) {
							echo aum_option_html( $value, $label, $f['sort'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</select>
				</div>

				<div class="aumd-filter-actions">
					<button type="submit" class="aumd-btn">Show results</button>
					<?php if ( $active ) : ?>
						<a class="aumd-clear" href="<?php echo esc_url( $action ); ?>">Clear all filters</a>
					<?php endif; ?>
				</div>
			</div>
		</details>
	</form>
	<?php
	return (string) ob_get_clean();
}

function aum_term_name( string $tax, string $slug ): string {
	$t = get_term_by( 'slug', $slug, $tax );
	return $t ? html_entity_decode( $t->name, ENT_QUOTES ) : $slug;
}

/** Dealer ext id => "Name, Town", alphabetical. */
function aum_dealer_choices(): array {
	$out   = array();
	$posts = get_posts( array( 'post_type' => 'aum_dealer', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
	foreach ( $posts as $p ) {
		$ext = (string) get_post_meta( $p->ID, 'aum_ext_id', true );
		if ( $ext ) {
			$out[ $ext ] = implode( ', ', array_filter( array( html_entity_decode( $p->post_title, ENT_QUOTES ), (string) get_post_meta( $p->ID, 'aum_town', true ) ) ) );
		}
	}
	return $out;
}

/** Active filters as [label, address without that filter]. The archive's own type or brand is not removable. */
function aum_active_filter_list( array $f ): array {
	$ctx    = aum_context_terms();
	$action = aum_filters_action_url();
	$items  = array();
	$link   = static fn( array $drop ) => add_query_arg( aum_filter_args( $f, $drop ), $action );
	if ( $f['type'] && ! $ctx['type'] ) {
		$items[] = array( aum_term_name( 'aum_equipment', $f['type'] ), $link( array( 'type' ) ) );
	}
	if ( $f['brand'] && ! $ctx['brand'] ) {
		$items[] = array( aum_term_name( 'aum_brand', $f['brand'] ), $link( array( 'brand' ) ) );
	}
	if ( $f['dealer'] ) {
		$items[] = array( aum_dealer_choices()[ $f['dealer'] ] ?? $f['dealer'], $link( array( 'dealer' ) ) );
	}
	foreach ( $f['condition'] as $c ) {
		$rest       = $f;
		$rest['condition'] = array_values( array_diff( $f['condition'], array( $c ) ) );
		$items[]    = array( aum_term_name( 'aum_condition', $c ), add_query_arg( aum_filter_args( $rest ), $action ) );
	}
	if ( $f['min_price'] || $f['max_price'] ) {
		if ( $f['min_price'] && $f['max_price'] ) {
			$label = aum_money( $f['min_price'] ) . ' to ' . aum_money( $f['max_price'] );
		} elseif ( $f['max_price'] ) {
			$label = 'Up to ' . aum_money( $f['max_price'] );
		} else {
			$label = aum_money( $f['min_price'] ) . ' and over';
		}
		$items[] = array( $label, $link( array( 'min_price', 'max_price' ) ) );
	}
	if ( $f['warranty'] ) {
		$items[] = array( 'Warranty stated', $link( array( 'warranty' ) ) );
	}
	return $items;
}

function aum_render_active_filters(): string {
	$items = aum_active_filter_list( aum_effective_filters() );
	if ( ! $items ) {
		return '';
	}
	$out = '<div class="aumd-chips"><span class="aumd-sr">Filters on: </span><ul class="aumd-chip-list">';
	foreach ( $items as [ $label, $url ] ) {
		$out .= sprintf(
			'<li><a class="aumd-chip" href="%s"><span>%s</span><span class="aumd-chip-x" aria-hidden="true">×</span><span class="aumd-sr"> (remove this filter)</span></a></li>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
	if ( count( $items ) > 1 ) {
		$out .= '<li><a class="aumd-chip-clear" href="' . esc_url( aum_filters_action_url() ) . '">Clear all</a></li>';
	}
	return $out . '</ul></div>';
}

function aum_render_results_count(): string {
	global $wp_query;
	if ( ! aum_is_listing_archive() ) {
		return '';
	}
	$total = (int) $wp_query->found_posts;
	if ( ! $total ) {
		return '<p class="aumd-count" role="status">No listings match these filters</p>';
	}
	$per   = max( 1, (int) $wp_query->get( 'posts_per_page' ) );
	$page  = max( 1, (int) get_query_var( 'paged' ) );
	$first = ( $page - 1 ) * $per + 1;
	$last  = min( $total, $page * $per );
	$text  = $total <= $per ? sprintf( _n( '%d listing', '%d listings', $total, 'aum-directory' ), $total ) : sprintf( 'Showing %1$d to %2$d of %3$d listings', $first, $last, $total );
	return '<p class="aumd-count" role="status">' . esc_html( $text ) . '</p>';
}

function aum_render_no_results(): string {
	global $wp_query;
	if ( ! aum_is_listing_archive() || (int) $wp_query->found_posts > 0 ) {
		return '';
	}
	$active = aum_active_filter_list( aum_effective_filters() );
	$out    = '<div class="aumd-empty"><h2 class="aumd-empty-title">Nothing matches all of those filters</h2>';
	$out   .= $active ? '<p>Try removing a filter, or start again with everything shown.</p><p><a class="aumd-btn" href="' . esc_url( aum_filters_action_url() ) . '">Clear all filters</a></p>' : '<p>There are no listings here at the moment. New stock is added every morning.</p><p><a class="aumd-btn" href="' . esc_url( (string) get_post_type_archive_link( 'aum_listing' ) ) . '">See all listings</a></p>';
	return $out . '</div>';
}

/* ================================================================== 3. listing page blocks */

function aum_listing_facts( int $id ): array {
	$f      = static fn( string $k, string $unit ) => aum_num( $id, $k ) > 0 ? aum_fmt_number( aum_num( $id, $k ) ) . $unit : '';
	$slug   = aum_equipment_slug( $id );
	$detail = trim( (string) get_post_meta( $id, 'aum_type_detail', true ) );
	$facts  = array(
		'Type'            => aum_first_term( $id, 'aum_equipment' ),
		'Brand'           => aum_first_term( $id, 'aum_brand' ),
		'Year'            => aum_listing_virtual( $id, 'aum_year_text' ),
		'Condition'       => aum_first_term( $id, 'aum_condition' ),
		'Warranty'        => (int) get_post_meta( $id, 'aum_warranty_months', true ) > 0 ? (int) get_post_meta( $id, 'aum_warranty_months', true ) . ' months' : '',
		'Top speed'       => $f( 'aum_top_speed_mph', ' mph' ),
		'Range'           => $f( 'aum_range_miles', ' miles' ),
		'Max user weight' => $f( 'aum_max_user_weight_kg', ' kg' ),
		'Heaviest part'   => $f( 'aum_heaviest_part_kg', ' kg' ),
	);
	if ( in_array( $slug, array( 'stairlifts', 'riser-recliners' ), true ) || $detail ) {
		// Straight or curved / how many motors goes straight after the type.
		$facts = array_slice( $facts, 0, 1, true ) + array( aum_type_detail_label( $slug ) => $detail ) + array_slice( $facts, 1, null, true );
	}
	if ( aum_is_home_equipment( $id ) ) {
		// Speed, range and a heaviest part to lift mean nothing for a stairlift or a chair: show them only if stated.
		foreach ( array( 'Top speed', 'Range', 'Heaviest part' ) as $k ) {
			if ( '' === $facts[ $k ] ) {
				unset( $facts[ $k ] );
			}
		}
	}
	return $facts;
}

function aum_current_listing_id( $atts ): int {
	$id = (int) ( $atts['id'] ?? 0 );
	return $id ?: (int) get_the_ID();
}

function aum_render_gallery( int $id ): string {
	if ( 'aum_listing' !== get_post_type( $id ) ) {
		return '';
	}
	$urls = get_post_meta( $id, 'aum_image_urls', true );
	$urls = is_array( $urls ) ? $urls : array();
	if ( ! $urls && get_post_meta( $id, 'aum_image_url', true ) ) {
		$urls = array( (string) get_post_meta( $id, 'aum_image_url', true ) );
	}
	$urls   = array_slice( array_values( array_filter( $urls, 'aum_is_web_url' ) ), 0, 12 );
	$title  = get_the_title( $id );
	$credit = aum_listing_virtual( $id, 'aum_photo_credit' );
	if ( ! $urls ) {
		return '<div class="aumd-gallery aumd-gallery-none"><p>The dealer hasn\'t shared a photo of this one. Ask them for photos before you buy.</p></div>';
	}
	wp_enqueue_script( 'aumd-front' );
	$count = count( $urls );
	ob_start();
	?>
	<div class="aumd-gallery" data-aum-gallery>
		<figure class="aumd-gallery-main">
			<img src="<?php echo esc_url( $urls[0] ); ?>" alt="<?php echo esc_attr( $title ); ?>" decoding="async" fetchpriority="high" referrerpolicy="no-referrer" data-aum-main>
			<figcaption class="aumd-credit"><?php echo esc_html( $credit ); ?><?php echo $count > 1 ? esc_html( sprintf( ' · %d photos', $count ) ) : ''; ?></figcaption>
		</figure>
		<?php if ( $count > 1 ) : ?>
			<ul class="aumd-thumbs" aria-label="More photos">
				<?php foreach ( $urls as $i => $url ) : ?>
					<li>
						<button type="button" class="aumd-thumb" data-src="<?php echo esc_url( $url ); ?>" aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>">
							<img src="<?php echo esc_url( $url ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
							<span class="aumd-sr"><?php echo esc_html( sprintf( 'Show photo %1$d of %2$d', $i + 1, $count ) ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

function aum_render_buy_box( int $id ): string {
	if ( 'aum_listing' !== get_post_type( $id ) ) {
		return '';
	}
	$price  = aum_num( $id, 'aum_price_gbp' );
	$saving = aum_listing_virtual( $id, 'aum_saving_text' );
	$was    = aum_listing_virtual( $id, 'aum_was_text' );
	$dealer = aum_listing_dealer( $id );
	ob_start();
	?>
	<div class="aumd-buybox">
		<p class="aumd-buy-price"><?php echo esc_html( aum_listing_virtual( $id, 'aum_price_text' ) ); ?></p>
		<?php if ( $was ) : ?>
			<p class="aumd-buy-was"><span class="aumd-strike"><?php echo esc_html( $was ); ?></span> <span class="aumd-saving"><?php echo esc_html( $saving ); ?></span></p>
		<?php endif; ?>
		<?php if ( $price > 0 ) : ?>
			<p class="aumd-basis"><?php echo esc_html( aum_price_basis_label( (string) get_post_meta( $id, 'aum_price_basis', true ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( 'stairlifts' === aum_equipment_slug( $id ) ) : ?>
			<p class="aumd-note aumd-stairlift-note">A stairlift's price depends on your stairs, so this is a starting price. The dealer will check your staircase before giving a final price. Ask whether fitting is included.</p>
		<?php endif; ?>
		<?php echo aum_render_go_button( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php echo function_exists( 'aum_render_compare_button' ) ? aum_render_compare_button( $id, 'wide' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php if ( $dealer ) : ?>
			<p class="aumd-soldby">Sold by <a href="<?php echo esc_url( get_permalink( $dealer ) ); ?>"><?php echo esc_html( get_the_title( $dealer ) ); ?></a><?php echo get_post_meta( $dealer, 'aum_town', true ) ? ', ' . esc_html( (string) get_post_meta( $dealer, 'aum_town', true ) ) : ''; ?></p>
		<?php endif; ?>
		<p class="aumd-checked"><?php echo esc_html( trim( aum_last_checked_text( $id ) . ' Price and availability can change, so confirm with the dealer.' ) ); ?></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

function aum_render_key_facts( int $id ): string {
	if ( 'aum_listing' !== get_post_type( $id ) ) {
		return '';
	}
	ob_start();
	?>
	<section class="aumd-facts-block" aria-labelledby="aumd-facts-<?php echo (int) $id; ?>">
		<h2 class="aumd-h" id="aumd-facts-<?php echo (int) $id; ?>">Key facts</h2>
		<dl class="aumd-facts">
			<?php foreach ( aum_listing_facts( $id ) as $label => $value ) : ?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo $value ? esc_html( (string) $value ) : '<span class="aumd-ns">Not stated</span>'; ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<?php if ( get_post_meta( $id, 'aum_spec_note', true ) ) : ?>
			<p class="aumd-note" role="note"><?php echo esc_html( (string) get_post_meta( $id, 'aum_spec_note', true ) ); ?></p>
		<?php endif; ?>
		<?php if ( get_post_meta( $id, 'aum_warranty_source', true ) && 'Listing' !== get_post_meta( $id, 'aum_warranty_source', true ) ) : ?>
			<p class="aumd-small">Warranty: <?php echo esc_html( (string) get_post_meta( $id, 'aum_warranty_source', true ) ); ?></p>
		<?php endif; ?>
		<p class="aumd-small">Figures are as the dealer states them. "Not stated" means the dealer's listing doesn't say.</p>
	</section>
	<?php
	return (string) ob_get_clean();
}

/** Questions worth asking, by kind of equipment. */
function aum_questions_for( string $equipment_slug ): array {
	switch ( $equipment_slug ) {
		case 'stairlifts':
			return array(
				'Will you survey my stairs before confirming the price?',
				'Is fitting included, and how long does it take?',
				'Is the rail new or reconditioned, and how old is the lift?',
				'What does the warranty cover, and who carries out repairs and servicing?',
				'Do you offer a buy-back if we no longer need it?',
			);
		case 'riser-recliners':
			return array(
				'Is the seat height and width right for me? Can I try it first?',
				'What is the maximum user weight?',
				'Is delivery and setting it up in the room included?',
				'What does the warranty cover, including the motors and handset?',
				'Can I return it if it doesn\'t suit me?',
			);
		case 'profiling-beds':
		case 'hoists':
		case 'bath-lifts':
			return array(
				'Has it been fully serviced and safety-checked, and when?',
				'What is the maximum user weight?',
				'Is delivery, setting up and a demonstration included?',
				'What does the warranty cover, and who carries out repairs?',
				'Can I return it if it doesn\'t suit me?',
			);
		default:
			return array(
				'How old are the batteries, and have they been load-tested?',
				'What does the warranty cover, and who carries out repairs?',
				'Is delivery and a home demonstration included?',
				'When was it last serviced, and is there a service record?',
				'Can I return it if it doesn\'t suit me?',
			);
	}
}

function aum_render_questions( int $id = 0 ): string {
	$id    = $id ?: (int) get_the_ID();
	$items = aum_questions_for( $id ? aum_equipment_slug( $id ) : '' );
	return '<section class="aumd-questions-block"><h2 class="aumd-h">Questions to ask the dealer</h2><ul class="aumd-questions">'
		. implode( '', array_map( static fn( string $q ) => '<li>' . esc_html( $q ) . '</li>', $items ) )
		. '</ul></section>';
}

function aum_render_dealer_contact( int $dealer_id ): string {
	if ( 'aum_dealer' !== get_post_type( $dealer_id ) ) {
		return '';
	}
	$v       = static fn( string $k ) => (string) aum_dealer_virtual( $dealer_id, $k );
	$m       = static fn( string $k ) => trim( (string) get_post_meta( $dealer_id, $k, true ) );
	$website = $m( 'aum_website' );
	ob_start();
	?>
	<div class="aumd-contact">
		<?php if ( $v( 'aum_address_full' ) ) : ?>
			<div class="aumd-contact-row"><span class="aumd-label">Address</span><p><?php echo esc_html( $v( 'aum_address_full' ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $m( 'aum_other_branches' ) ) : ?>
			<div class="aumd-contact-row"><span class="aumd-label">Other branches</span><p><?php echo esc_html( $m( 'aum_other_branches' ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $m( 'aum_visit_note' ) ) : ?>
			<div class="aumd-contact-row"><span class="aumd-label">Before you visit</span><p><?php echo esc_html( $m( 'aum_visit_note' ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $m( 'aum_phone' ) ) : ?>
			<div class="aumd-contact-row"><span class="aumd-label">Phone</span><p><a href="<?php echo esc_attr( $v( 'aum_phone_link' ) ); ?>"><?php echo esc_html( $m( 'aum_phone' ) ); ?></a></p></div>
		<?php endif; ?>
		<div class="aumd-contact-actions">
			<?php if ( $website ) : ?>
				<a class="aumd-btn" href="<?php echo esc_url( $website ); ?>" rel="nofollow noopener" target="_blank">Visit <?php echo esc_html( $v( 'aum_website_host' ) ); ?><span class="aumd-sr"> (opens in a new tab)</span></a>
			<?php endif; ?>
			<a class="aumd-btn-outline" href="<?php echo esc_url( $v( 'aum_directions_url' ) ); ?>" rel="noopener" target="_blank">Get directions<span class="aumd-sr"> (opens Google Maps in a new tab)</span></a>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ================================================================== 4. dealer map */

function aum_dealer_map_data( string $which ): array {
	if ( 'current' === $which && is_singular( 'aum_dealer' ) ) {
		$posts = array( get_post( get_queried_object_id() ) );
	} else {
		$posts = get_posts( array( 'post_type' => 'aum_dealer', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC' ) );
	}
	$out = array();
	foreach ( array_filter( $posts ) as $p ) {
		$lat = (float) get_post_meta( $p->ID, 'aum_lat', true );
		$lng = (float) get_post_meta( $p->ID, 'aum_lng', true );
		$out[] = array(
			'id'         => $p->ID,
			'name'       => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
			'town'       => (string) get_post_meta( $p->ID, 'aum_town', true ),
			'address'    => (string) aum_dealer_virtual( $p->ID, 'aum_address_full' ),
			'other'      => (string) get_post_meta( $p->ID, 'aum_other_branches', true ),
			'visitNote'  => (string) get_post_meta( $p->ID, 'aum_visit_note', true ),
			'phone'      => (string) get_post_meta( $p->ID, 'aum_phone', true ),
			'phoneLink'  => (string) aum_dealer_virtual( $p->ID, 'aum_phone_link' ),
			'url'        => (string) get_permalink( $p ),
			'stockUrl'   => (string) aum_dealer_virtual( $p->ID, 'aum_stock_url' ),
			'count'      => (int) get_post_meta( $p->ID, 'aum_listing_count', true ),
			'directions' => (string) aum_dealer_virtual( $p->ID, 'aum_directions_url' ),
			'lat'        => $lat ?: null,
			'lng'        => $lng ?: null,
		);
	}
	return $out;
}

function aum_render_dealer_map( $atts ): string {
	$atts    = shortcode_atts( array( 'dealer' => '', 'search' => 'yes', 'list' => 'yes', 'height' => '480' ), (array) $atts, 'aum_dealer_map' );
	$dealers = aum_dealer_map_data( (string) $atts['dealer'] );
	if ( ! $dealers ) {
		return '';
	}
	wp_enqueue_style( 'aumd-leaflet' );
	wp_enqueue_script( 'aumd-map' );
	$uid    = wp_unique_id( 'aumm-' );
	$search = 'no' !== $atts['search'] && count( $dealers ) > 1;
	$list   = 'no' !== $atts['list'];
	$height = max( 220, min( 900, (int) $atts['height'] ) );
	ob_start();
	?>
	<div class="aumd-mapblock<?php echo $list ? ' aumd-mapblock-list' : ''; ?>" data-aum-map>
		<?php if ( $search ) : ?>
			<form class="aumd-near" data-aum-near>
				<label class="aumd-label" for="<?php echo esc_attr( $uid ); ?>-pc">Find the dealers nearest to you</label>
				<div class="aumd-near-row">
					<input class="aumd-input" id="<?php echo esc_attr( $uid ); ?>-pc" type="text" name="postcode" autocomplete="postal-code" placeholder="Your postcode, for example DT1 1UZ" spellcheck="false">
					<button type="submit" class="aumd-btn">Find nearest</button>
				</div>
				<p class="aumd-near-msg" role="status" aria-live="polite"></p>
				<p class="aumd-small">We look your postcode up with postcodes.io to work out distances. It isn't stored.</p>
			</form>
		<?php endif; ?>
		<div class="aumd-map-wrap">
			<div class="aumd-map" style="height:<?php echo (int) $height; ?>px" role="region" aria-label="Map of dealer locations"></div>
			<?php if ( $list ) : ?>
				<ol class="aumd-dealer-list" aria-label="Dealers">
					<?php foreach ( $dealers as $d ) : ?>
						<li class="aumd-dealer-item" data-id="<?php echo (int) $d['id']; ?>">
							<h3 class="aumd-dealer-name"><a href="<?php echo esc_url( $d['url'] ); ?>"><?php echo esc_html( $d['name'] ); ?></a></h3>
							<p class="aumd-dealer-addr"><?php echo esc_html( $d['address'] ); ?></p>
							<?php if ( $d['other'] ) : ?><p class="aumd-small">Also: <?php echo esc_html( $d['other'] ); ?></p><?php endif; ?>
							<?php if ( $d['visitNote'] ) : ?><p class="aumd-small aumd-visit-note"><?php echo esc_html( $d['visitNote'] ); ?></p><?php endif; ?>
							<p class="aumd-dealer-dist" hidden></p>
							<p class="aumd-dealer-links">
								<a href="<?php echo esc_url( $d['stockUrl'] ); ?>"><?php echo esc_html( sprintf( _n( 'See %d listing', 'See all %d listings', $d['count'], 'aum-directory' ), $d['count'] ) ); ?></a>
								<?php if ( $d['lat'] && $d['lng'] ) : ?>
									<button type="button" class="aumd-linkbtn" data-aum-show="<?php echo (int) $d['id']; ?>">Show on map</button>
								<?php endif; ?>
							</p>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<script type="application/json" class="aumd-map-data"><?php echo wp_json_encode( $dealers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ================================================================== assets and shortcodes */

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_register_style( 'aumd-leaflet', AUM_DIR_URL . 'assets/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'aumd-leaflet', AUM_DIR_URL . 'assets/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_register_script( 'aumd-map', AUM_DIR_URL . 'assets/aum-map.js', array( 'aumd-leaflet' ), AUM_DIR_VERSION, true );
		wp_register_script( 'aumd-front', AUM_DIR_URL . 'assets/aum-front.js', array(), AUM_DIR_VERSION, true );
		// Small (under 2 KB): card photo fallback, filter panel and gallery. Needed on most pages.
		wp_enqueue_script( 'aumd-front' );
	},
	5
);

add_shortcode( 'aum_filters', static fn() => aum_render_filters() );
add_shortcode( 'aum_active_filters', static fn() => aum_render_active_filters() );
add_shortcode( 'aum_results_count', static fn() => aum_render_results_count() );
add_shortcode( 'aum_no_results', static fn() => aum_render_no_results() );
add_shortcode( 'aum_dealer_map', static fn( $atts ) => aum_render_dealer_map( $atts ) );
add_shortcode( 'aum_gallery', static fn( $atts ) => aum_render_gallery( aum_current_listing_id( (array) $atts ) ) );
add_shortcode( 'aum_buy_box', static fn( $atts ) => aum_render_buy_box( aum_current_listing_id( (array) $atts ) ) );
add_shortcode( 'aum_key_facts', static fn( $atts ) => aum_render_key_facts( aum_current_listing_id( (array) $atts ) ) );
add_shortcode( 'aum_questions', static fn() => aum_render_questions() );
add_shortcode(
	'aum_dealer_box',
	static function ( $atts ) {
		$id = aum_current_listing_id( (array) $atts );
		if ( 'aum_listing' === get_post_type( $id ) ) {
			$dealer = aum_listing_dealer( $id );
			return $dealer ? aum_render_dealer_box( $dealer ) : '';
		}
		return 'aum_dealer' === get_post_type( $id ) ? aum_render_dealer_box( $id, false ) : '';
	}
);
add_shortcode( 'aum_dealer_contact', static fn( $atts ) => aum_render_dealer_contact( aum_current_listing_id( (array) $atts ) ) );
