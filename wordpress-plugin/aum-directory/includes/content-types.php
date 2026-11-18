<?php
/**
 * Listings, dealers, their taxonomies and fields.
 *
 * URLs:  /listing/<slug>/   /listings/   /used/<equipment>/   /brand/<brand>/   /dealers/<slug>/
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'aum_register_content_types' );

function aum_register_content_types(): void {
	register_post_type(
		'aum_listing',
		array(
			'labels'       => array(
				'name'          => 'Listings',
				'singular_name' => 'Listing',
				'all_items'     => 'All listings',
				'search_items'  => 'Search listings',
				'not_found'     => 'No listings found',
			),
			'public'       => true,
			'has_archive'  => 'listings',
			'rewrite'      => array( 'slug' => 'listing', 'with_front' => false ),
			'menu_icon'    => 'dashicons-universal-access',
			'supports'     => array( 'title', 'excerpt', 'custom-fields' ),
			'show_in_rest' => true,
			'taxonomies'   => array( 'aum_equipment', 'aum_condition', 'aum_brand' ),
		)
	);

	register_post_type(
		'aum_dealer',
		array(
			'labels'       => array(
				'name'          => 'Dealers',
				'singular_name' => 'Dealer',
				'all_items'     => 'All dealers',
			),
			'public'       => true,
			'has_archive'  => 'dealers',
			'rewrite'      => array( 'slug' => 'dealers', 'with_front' => false ),
			'menu_icon'    => 'dashicons-store',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			'show_in_rest' => true,
		)
	);

	// Sold or removed by the dealer: kept for history and price data, never shown publicly.
	register_post_status(
		'aum_sold',
		array(
			'label'                     => 'Sold / removed',
			'public'                    => false,
			'internal'                  => false,
			'protected'                 => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of listings */
			'label_count'               => _n_noop( 'Sold / removed <span class="count">(%s)</span>', 'Sold / removed <span class="count">(%s)</span>', 'aum-directory' ),
		)
	);

	$tax_common = array(
		'hierarchical'      => false,
		'show_admin_column' => true,
		'show_in_rest'      => true,
	);
	register_taxonomy(
		'aum_equipment',
		'aum_listing',
		$tax_common + array(
			'labels'  => array( 'name' => 'Equipment types', 'singular_name' => 'Equipment type' ),
			'public'  => true,
			'rewrite' => array( 'slug' => 'used', 'with_front' => false ),
		)
	);
	register_taxonomy(
		'aum_condition',
		'aum_listing',
		$tax_common + array(
			'labels'             => array( 'name' => 'Conditions', 'singular_name' => 'Condition' ),
			'public'             => false,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'rewrite'            => false,
		)
	);
	register_taxonomy(
		'aum_brand',
		'aum_listing',
		$tax_common + array(
			'labels'  => array( 'name' => 'Brands', 'singular_name' => 'Brand' ),
			'public'  => true,
			'rewrite' => array( 'slug' => 'brand', 'with_front' => false ),
		)
	);

	foreach ( aum_listing_fields() as $key => [ $type ] ) {
		aum_register_meta( 'aum_listing', $key, $type );
	}
	foreach ( aum_dealer_fields() as $key => [ $type ] ) {
		aum_register_meta( 'aum_dealer', $key, $type );
	}
}

function aum_register_meta( string $post_type, string $key, string $type ): void {
	$args = array(
		'type'          => $type,
		'single'        => true,
		'show_in_rest'  => true,
		'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
	);
	if ( 'array' === $type ) {
		$args['show_in_rest'] = array(
			'schema' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string', 'format' => 'uri' ),
			),
		);
		$args['default']      = array();
	}
	register_post_meta( $post_type, $key, $args );
}

/**
 * Make sure every equipment type and condition exists with its proper name.
 * A term that was created on the fly gets its web address as its name ("riser-recliners"),
 * so such a name is put right; a name a person has changed by hand is left alone.
 */
function aum_create_default_terms(): void {
	foreach ( array( 'aum_equipment' => aum_equipment_terms(), 'aum_condition' => aum_condition_terms() ) as $tax => $terms ) {
		foreach ( $terms as [ $slug, $name ] ) {
			$term = get_term_by( 'slug', $slug, $tax );
			if ( ! $term ) {
				wp_insert_term( $name, $tax, array( 'slug' => $slug ) );
			} elseif ( $term->name === $slug && $name !== $slug ) {
				wp_update_term( $term->term_id, $tax, array( 'name' => $name ) );
			}
		}
	}
}

/** Uploading a new version doesn't run the activation step, so check the terms once per version. */
add_action(
	'init',
	function () {
		if ( get_option( 'aum_terms_version' ) !== AUM_DIR_VERSION && taxonomy_exists( 'aum_equipment' ) ) {
			aum_create_default_terms();
			update_option( 'aum_terms_version', AUM_DIR_VERSION );
		}
	},
	30
);

/**
 * The scraper signs in as its own user with the "Stock importer" role. That role can
 * import stock and nothing else: it can't edit pages, settings or other users.
 */
function aum_add_roles_and_caps(): void {
	if ( ! get_role( 'aum_importer' ) ) {
		add_role( 'aum_importer', 'Stock importer', array( 'read' => true, 'aum_import_stock' => true ) );
	}
	$admin = get_role( 'administrator' );
	if ( $admin && ! $admin->has_cap( 'aum_import_stock' ) ) {
		$admin->add_cap( 'aum_import_stock' );
	}
}

/** Find a post by one of our ID fields. */
function aum_find_post_id( string $post_type, string $meta_key, string $value ): int {
	$ids = get_posts(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'any',
			'meta_key'               => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'             => $value,    // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);
	if ( ! $ids ) {
		// 'any' leaves out statuses that aren't in admin lists, such as our sold status.
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'aum_sold', 'draft', 'trash' ),
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $value,    // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
	}
	return $ids ? (int) $ids[0] : 0;
}

function aum_dealer_post_for( string $ext_id ): int {
	static $cache = array();
	if ( ! isset( $cache[ $ext_id ] ) ) {
		$cache[ $ext_id ] = aum_find_post_id( 'aum_dealer', 'aum_ext_id', $ext_id );
	}
	return $cache[ $ext_id ];
}
