<?php
/**
 * One place that defines every field the scraper sends, so registering,
 * importing and displaying all agree.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Listing fields: meta key => [type, label, scraper key].
 * Meta keys have no leading underscore so page builders (Breakdance) can pick them.
 */
function aum_listing_fields(): array {
	return array(
		'aum_source_id'          => array( 'string', 'Scraper ID', 'id' ),
		'aum_dealer_ext_id'      => array( 'string', 'Dealer ID', 'dealer_id' ),
		'aum_title_as_listed'    => array( 'string', "Dealer's own title", 'title' ),
		'aum_year'               => array( 'integer', 'Year', 'year' ),
		'aum_type_detail'        => array( 'string', 'Type detail (e.g. curved, dual motor)', 'type_detail' ),
		'aum_price_gbp'          => array( 'number', 'Price (£)', 'price_gbp' ),
		'aum_price_is_from'      => array( 'boolean', '"From" price', 'price_is_from' ),
		'aum_was_price_gbp'      => array( 'number', 'Was price (£)', 'was_price_gbp' ),
		'aum_previous_price_gbp' => array( 'number', 'Previous price (£)', 'previous_price_gbp' ),
		'aum_price_basis'        => array( 'string', 'Price basis', 'price_basis' ),
		'aum_clearance'          => array( 'flag', 'Clearance (the dealer marks it so)', 'clearance' ),
		'aum_clearance_only'     => array( 'flag', 'Clearance section only (not approved used)', 'clearance_only' ),
		'aum_reduced'            => array( 'flag', 'Reduced (was price or a recent price drop)', 'reduced' ),
		'aum_price_drop_from_gbp' => array( 'number', 'Price before the drop (£)', 'price_drop_from_gbp' ),
		'aum_price_dropped_at'   => array( 'string', 'Price dropped on', 'price_dropped_at' ),
		'aum_warranty_months'    => array( 'integer', 'Warranty (months)', 'warranty_months' ),
		'aum_warranty_source'    => array( 'string', 'Warranty source', 'warranty_source' ),
		'aum_max_user_weight_kg' => array( 'number', 'Max user weight (kg)', 'max_user_weight_kg' ),
		'aum_range_miles'        => array( 'number', 'Range (miles)', 'range_miles' ),
		'aum_top_speed_mph'      => array( 'number', 'Top speed (mph)', 'top_speed_mph' ),
		'aum_heaviest_part_kg'   => array( 'number', 'Heaviest part (kg)', 'heaviest_part_kg' ),
		'aum_spec_note'          => array( 'string', 'Spec note', 'spec_note' ),
		'aum_dealer_url'         => array( 'string', "Dealer's page for this item", 'url' ),
		'aum_image_url'          => array( 'string', 'Main photo URL', null ),
		'aum_image_urls'         => array( 'array', 'Photo URLs', 'image_urls' ),
		'aum_first_seen'         => array( 'string', 'First seen', 'first_seen' ),
		'aum_last_seen'          => array( 'string', 'Last seen', 'last_seen' ),
		'aum_removed_at'         => array( 'string', 'Removed at', null ),
	);
}

function aum_dealer_fields(): array {
	return array(
		'aum_ext_id'         => array( 'string', 'Dealer ID', 'id' ),
		'aum_website'        => array( 'string', 'Website', 'website' ),
		'aum_address'        => array( 'string', 'Address', 'address' ),
		'aum_town'           => array( 'string', 'Town', 'town' ),
		'aum_postcode'       => array( 'string', 'Postcode', 'postcode' ),
		'aum_phone'          => array( 'string', 'Phone', 'phone' ),
		'aum_other_branches' => array( 'string', 'Other branches', 'other_branches' ),
		'aum_visit_note'     => array( 'string', 'Before visiting', 'visit_note' ),
		'aum_price_basis'    => array( 'string', 'Price basis', 'price_basis' ),
		'aum_lat'            => array( 'number', 'Latitude', 'lat' ),
		'aum_lng'            => array( 'number', 'Longitude', 'lng' ),
		'aum_listing_count'  => array( 'integer', 'Live listings', 'listing_count' ),
		'aum_clearance_count' => array( 'integer', 'Clearance listings', 'clearance_count' ),
	);
}

/** Scraper category text => equipment term slug and name. */
function aum_equipment_terms(): array {
	return array(
		'Road scooter (8mph)'                 => array( 'road-scooters', 'Road scooters (8mph)' ),
		'Pavement scooter (4mph)'             => array( 'pavement-scooters', 'Pavement scooters (4mph)' ),
		'Boot / folding scooter'              => array( 'boot-scooters', 'Boot and folding scooters' ),
		'Mobility scooter (speed not stated)' => array( 'mobility-scooters', 'Mobility scooters' ),
		'Powerchair'                          => array( 'powerchairs', 'Powerchairs' ),
		'Manual wheelchair'                   => array( 'wheelchairs', 'Wheelchairs' ),
		'Stairlift'                           => array( 'stairlifts', 'Stairlifts' ),
		'Riser recliner chair'                => array( 'riser-recliners', 'Riser recliner chairs' ),
		'Profiling bed'                       => array( 'profiling-beds', 'Profiling beds' ),
		'Patient hoist'                       => array( 'hoists', 'Hoists' ),
		'Bath lift'                           => array( 'bath-lifts', 'Bath lifts' ),
		'Other'                               => array( 'other', 'Other equipment' ),
	);
}

function aum_condition_terms(): array {
	return array(
		'Ex-demo'       => array( 'ex-demo', 'Ex-demo' ),
		'Used'          => array( 'used', 'Used' ),
		'Reconditioned' => array( 'reconditioned', 'Reconditioned' ),
		// Only ever on clearance stock, which the site keeps in its own section.
		'New'           => array( 'new-end-of-line', 'New (end of line)' ),
		'Not stated'    => array( 'not-stated', 'Condition not stated' ),
	);
}

/** How each dealer's prices should be described to buyers. */
function aum_price_basis_label( string $basis ): string {
	switch ( $basis ) {
		case 'vat_relief':
			return 'Price with VAT relief. If you don\'t qualify, the dealer adds 20% VAT.';
		case 'no_vat':
			return 'The dealer says there is no VAT to add.';
		default:
			return 'Check with the dealer whether VAT is added.';
	}
}


/**
 * Equipment you ride (scooters, chairs) has speed, range and a heaviest part to lift.
 * Equipment for the home (stairlifts, riser recliners, beds, hoists, bath lifts) doesn't.
 */
function aum_home_equipment_slugs(): array {
	return array( 'stairlifts', 'riser-recliners', 'profiling-beds', 'hoists', 'bath-lifts' );
}

function aum_equipment_slug( int $listing_id ): string {
	$terms = get_the_terms( $listing_id, 'aum_equipment' );
	return ( $terms && ! is_wp_error( $terms ) ) ? (string) $terms[0]->slug : '';
}

function aum_is_home_equipment( int $listing_id ): bool {
	return in_array( aum_equipment_slug( $listing_id ), aum_home_equipment_slugs(), true );
}

/** What the type-detail field means for this kind of equipment. */
function aum_type_detail_label( string $equipment_slug ): string {
	switch ( $equipment_slug ) {
		case 'stairlifts':
			return 'Stairlift type';
		case 'riser-recliners':
			return 'Motors';
		default:
			return 'Details';
	}
}
