<?php
/**
 * Structured data (schema.org JSON-LD) so search engines understand listings
 * as used products for sale and dealers as local businesses.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', function () {
	if ( is_singular( 'aum_listing' ) ) {
		$data = aum_listing_schema( get_queried_object_id() );
	} elseif ( is_singular( 'aum_dealer' ) ) {
		$data = aum_dealer_schema( get_queried_object_id() );
	} else {
		return;
	}
	if ( $data ) {
		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
} );

function aum_listing_schema( int $id ): array {
	$price = get_post_meta( $id, 'aum_price_gbp', true );
	if ( '' === $price ) {
		return array();
	}
	$conditions  = wp_get_post_terms( $id, 'aum_condition', array( 'fields' => 'slugs' ) );
	$condition   = in_array( 'reconditioned', (array) $conditions, true ) ? 'RefurbishedCondition' : 'UsedCondition';
	$brands      = wp_get_post_terms( $id, 'aum_brand', array( 'fields' => 'names' ) );
	$equipment   = wp_get_post_terms( $id, 'aum_equipment', array( 'fields' => 'names' ) );
	$dealer_post = aum_dealer_post_for( (string) get_post_meta( $id, 'aum_dealer_ext_id', true ) );
	$image       = get_post_meta( $id, 'aum_image_url', true );

	$data = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'Product',
		'name'        => get_the_title( $id ),
		'description' => get_the_excerpt( $id ),
		'url'         => get_permalink( $id ),
		'offers'      => array(
			'@type'         => 'Offer',
			'price'         => number_format( (float) $price, 2, '.', '' ),
			'priceCurrency' => 'GBP',
			'itemCondition' => 'https://schema.org/' . $condition,
			'availability'  => 'https://schema.org/InStock',
			'url'           => get_permalink( $id ),
		),
	);
	if ( $image ) {
		$data['image'] = $image;
	}
	if ( ! is_wp_error( $brands ) && $brands ) {
		$data['brand'] = array( '@type' => 'Brand', 'name' => $brands[0] );
	}
	if ( ! is_wp_error( $equipment ) && $equipment ) {
		$data['category'] = $equipment[0];
	}
	if ( $dealer_post ) {
		$data['offers']['seller'] = array(
			'@type' => 'LocalBusiness',
			'name'  => get_the_title( $dealer_post ),
			'url'   => get_permalink( $dealer_post ),
		);
	}
	return $data;
}

function aum_dealer_schema( int $id ): array {
	$m    = static fn( string $k ) => (string) get_post_meta( $id, $k, true );
	$data = array(
		'@context' => 'https://schema.org',
		'@type'    => 'LocalBusiness',
		'name'     => get_the_title( $id ),
		'url'      => $m( 'aum_website' ) ?: get_permalink( $id ),
		'address'  => array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $m( 'aum_address' ),
				'addressLocality' => $m( 'aum_town' ),
				'postalCode'      => $m( 'aum_postcode' ),
				'addressCountry'  => 'GB',
			)
		),
	);
	if ( $m( 'aum_phone' ) ) {
		$data['telephone'] = $m( 'aum_phone' );
	}
	if ( $m( 'aum_lat' ) && $m( 'aum_lng' ) ) {
		$data['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $m( 'aum_lat' ), 'longitude' => (float) $m( 'aum_lng' ) );
	}
	return $data;
}
