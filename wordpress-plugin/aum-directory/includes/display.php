<?php
/**
 * Plain, accessible output for listing and dealer pages. It works with any theme
 * now, and the same pieces are shortcodes for Breakdance templates later:
 *
 *   [aum_listing_details]   price, facts, dealer box and the go-to-dealer button
 *   [aum_go_button]         just the button
 *   [aum_dealer_details]    address, phone and website for a dealer page
 *   [aum_listings equipment="road-scooters" dealer="mobility-world" limit="12"]
 *   [aum_listings section="clearance" limit="6"]   clearance stock (default: the main site's)
 *
 * Only figures the dealer states are shown. Missing ones say "Not stated".
 */

defined( 'ABSPATH' ) || exit;

// Breakdance keeps shortcodes in its own data rather than the post content, so the small
// stylesheet loads on every page instead of only where a shortcode is spotted.
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'aumd-directory', AUM_DIR_URL . 'assets/aum.css', array(), AUM_DIR_VERSION );
} );

function aum_page_has_shortcode(): bool {
	$post = get_post();
	return $post && str_contains( (string) $post->post_content, '[aum_' );
}

/* ------------------------------------------------------------------ helpers */

function aum_money( $value ): string {
	$v = (float) $value;
	return '£' . number_format( $v, floor( $v ) == $v ? 0 : 2 );
}

function aum_meta( int $id, string $key ) {
	$v = get_post_meta( $id, $key, true );
	return ( '' === $v || null === $v ) ? null : $v;
}

function aum_fmt_number( $v ): string {
	$f = (float) $v;
	return floor( $f ) == $f ? number_format( $f ) : rtrim( rtrim( number_format( $f, 1 ), '0' ), '.' );
}

function aum_last_checked_text( int $id ): string {
	$seen = aum_meta( $id, 'aum_last_seen' );
	if ( ! $seen ) {
		return '';
	}
	$ts = strtotime( $seen );
	return $ts ? 'Checked on the dealer\'s website on ' . wp_date( 'j F Y', $ts ) . '.' : '';
}

/* ------------------------------------------------------------------ listing */

function aum_render_go_button( int $id, string $placement = 'listing' ): string {
	$dealer_post = aum_dealer_post_for( (string) aum_meta( $id, 'aum_dealer_ext_id' ) );
	$dealer      = $dealer_post ? get_the_title( $dealer_post ) : 'the dealer';
	return sprintf(
		'<a class="aumd-go" href="%s" rel="nofollow noopener" target="_blank">%s<span class="screen-reader-text"> (opens in a new tab)</span></a>',
		esc_url( aum_go_url( $id, $placement ) ),
		esc_html( sprintf( 'See it on %s\'s website', $dealer ) )
	);
}

function aum_render_listing_details( int $id ): string {
	if ( 'aum_listing' !== get_post_type( $id ) ) {
		return '';
	}
	$price       = aum_meta( $id, 'aum_price_gbp' );
	$was         = aum_meta( $id, 'aum_was_price_gbp' );
	$from        = (bool) aum_meta( $id, 'aum_price_is_from' );
	$basis       = (string) aum_meta( $id, 'aum_price_basis' );
	$dealer_post = aum_dealer_post_for( (string) aum_meta( $id, 'aum_dealer_ext_id' ) );
	$image       = aum_meta( $id, 'aum_image_url' );
	$terms       = static function ( string $tax ) use ( $id ): string {
		$t = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );
		return ( ! is_wp_error( $t ) && $t ) ? $t[0] : '';
	};
	$condition = $terms( 'aum_condition' );

	$facts = array(
		'Type'            => $terms( 'aum_equipment' ),
		'Brand'           => $terms( 'aum_brand' ),
		'Year'            => aum_meta( $id, 'aum_year' ),
		'Condition'       => 'Condition not stated' === $condition ? '' : $condition,
		'Warranty'        => aum_meta( $id, 'aum_warranty_months' ) ? (int) aum_meta( $id, 'aum_warranty_months' ) . ' months' : '',
		'Max user weight' => aum_meta( $id, 'aum_max_user_weight_kg' ) ? aum_fmt_number( aum_meta( $id, 'aum_max_user_weight_kg' ) ) . ' kg' : '',
		'Range'           => aum_meta( $id, 'aum_range_miles' ) ? aum_fmt_number( aum_meta( $id, 'aum_range_miles' ) ) . ' miles' : '',
		'Top speed'       => aum_meta( $id, 'aum_top_speed_mph' ) ? aum_fmt_number( aum_meta( $id, 'aum_top_speed_mph' ) ) . ' mph' : '',
		'Heaviest part'   => aum_meta( $id, 'aum_heaviest_part_kg' ) ? aum_fmt_number( aum_meta( $id, 'aum_heaviest_part_kg' ) ) . ' kg' : '',
	);

	ob_start();
	?>
	<div class="aumd-listing">
		<?php if ( $image ) : ?>
			<figure class="aumd-photo">
				<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( get_the_title( $id ) ); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer">
				<figcaption>Photo: <?php echo esc_html( $dealer_post ? get_the_title( $dealer_post ) : 'dealer' ); ?></figcaption>
			</figure>
		<?php endif; ?>

		<div class="aumd-buy">
			<?php if ( $price ) : ?>
				<p class="aumd-price"><?php echo $from ? 'From ' : ''; ?><?php echo esc_html( aum_money( $price ) ); ?>
					<?php if ( $was ) : ?><span class="aumd-was">Was <?php echo esc_html( aum_money( $was ) ); ?></span><?php endif; ?>
				</p>
				<p class="aumd-basis"><?php echo esc_html( aum_price_basis_label( $basis ) ); ?></p>
			<?php endif; ?>
			<?php echo aum_render_go_button( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="aumd-checked"><?php echo esc_html( aum_last_checked_text( $id ) ); ?> Price and availability can change, so confirm with the dealer.</p>
		</div>

		<h2 class="aumd-h">Key facts</h2>
		<dl class="aumd-facts">
			<?php foreach ( $facts as $label => $value ) : ?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo $value ? esc_html( (string) $value ) : '<span class="aumd-ns">Not stated</span>'; ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<?php if ( aum_meta( $id, 'aum_spec_note' ) ) : ?>
			<p class="aumd-note" role="note"><?php echo esc_html( (string) aum_meta( $id, 'aum_spec_note' ) ); ?></p>
		<?php endif; ?>
		<?php if ( aum_meta( $id, 'aum_warranty_source' ) && 'Listing' !== aum_meta( $id, 'aum_warranty_source' ) ) : ?>
			<p class="aumd-small">Warranty: <?php echo esc_html( (string) aum_meta( $id, 'aum_warranty_source' ) ); ?></p>
		<?php endif; ?>

		<h2 class="aumd-h">Questions to ask the dealer</h2>
		<ul class="aumd-questions">
			<li>How old are the batteries, and have they been load-tested?</li>
			<li>What does the warranty cover, and who carries out repairs?</li>
			<li>Is delivery and a home demonstration included?</li>
			<li>When was it last serviced, and is there a service record?</li>
			<li>Can I return it if it doesn't suit me?</li>
		</ul>

		<?php if ( $dealer_post ) : ?>
			<?php echo aum_render_dealer_box( $dealer_post ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ------------------------------------------------------------------ dealer */

function aum_render_dealer_box( int $dealer_id, bool $with_link = true ): string {
	$m       = static fn( string $k ) => (string) get_post_meta( $dealer_id, $k, true );
	$address = implode( ', ', array_filter( array( $m( 'aum_address' ), $m( 'aum_town' ), $m( 'aum_postcode' ) ) ) );
	ob_start();
	?>
	<section class="aumd-dealer" aria-label="Dealer">
		<h2 class="aumd-h">Sold by <?php echo esc_html( get_the_title( $dealer_id ) ); ?></h2>
		<?php if ( $address ) : ?><p><?php echo esc_html( $address ); ?></p><?php endif; ?>
		<?php if ( $m( 'aum_other_branches' ) ) : ?><p class="aumd-small">Also: <?php echo esc_html( $m( 'aum_other_branches' ) ); ?></p><?php endif; ?>
		<?php if ( $m( 'aum_phone' ) ) : ?>
			<p><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $m( 'aum_phone' ) ) ); ?>">Call <?php echo esc_html( $m( 'aum_phone' ) ); ?></a></p>
		<?php endif; ?>
		<?php if ( $with_link ) : ?>
			<p><a href="<?php echo esc_url( get_permalink( $dealer_id ) ); ?>">All stock from this dealer</a></p>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

function aum_render_dealer_details( int $dealer_id ): string {
	$website = (string) get_post_meta( $dealer_id, 'aum_website', true );
	$out     = aum_render_dealer_box( $dealer_id, false );
	if ( $website ) {
		$out .= '<p><a href="' . esc_url( $website ) . '" rel="nofollow noopener" target="_blank">Visit the dealer\'s website<span class="screen-reader-text"> (opens in a new tab)</span></a></p>';
	}
	$out .= '<h2 class="aumd-h">Approved used stock</h2>';
	$out .= aum_render_listing_grid( array( 'dealer' => (string) get_post_meta( $dealer_id, 'aum_ext_id', true ), 'limit' => 60 ) );
	return $out;
}

/* ------------------------------------------------------------------ grid */

function aum_render_listing_grid( array $atts ): string {
	$atts = shortcode_atts( array( 'equipment' => '', 'brand' => '', 'dealer' => '', 'limit' => 12, 'orderby' => 'price', 'exclude' => '', 'section' => 'main', 'empty' => 'No listings at the moment.' ), $atts, 'aum_listings' );
	// "current" means: the same as the listing or dealer page being viewed.
	$here = get_queried_object_id();
	if ( 'current' === $atts['dealer'] ) {
		$atts['dealer'] = 'aum_dealer' === get_post_type( $here ) ? (string) get_post_meta( $here, 'aum_ext_id', true ) : (string) get_post_meta( $here, 'aum_dealer_ext_id', true );
	}
	if ( 'current' === $atts['equipment'] ) {
		$atts['equipment'] = 'aum_listing' === get_post_type( $here ) ? aum_first_term( $here, 'aum_equipment', 'slug' ) : '';
	}
	if ( 'current' === $atts['brand'] ) {
		$atts['brand'] = 'aum_listing' === get_post_type( $here ) ? aum_first_term( $here, 'aum_brand', 'slug' ) : '';
	}
	$query = array(
		'post_type'      => 'aum_listing',
		'post_status'    => 'publish',
		'posts_per_page' => max( 1, min( 100, (int) $atts['limit'] ) ),
		'no_found_rows'  => true,
		'aum_section'    => 'clearance' === $atts['section'] ? 'clearance' : 'main',
	);
	if ( 'current' === $atts['exclude'] && $here ) {
		$query['post__not_in'] = array( $here );
	}
	if ( 'newest' === $atts['orderby'] ) {
		$query['orderby'] = 'date';
		$query['order']   = 'DESC';
	}
	$tax = array();
	if ( $atts['equipment'] ) {
		$tax[] = array( 'taxonomy' => 'aum_equipment', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $atts['equipment'] ) ) );
	}
	if ( $atts['brand'] ) {
		$tax[] = array( 'taxonomy' => 'aum_brand', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $atts['brand'] ) ) );
	}
	if ( $tax ) {
		$query['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( $atts['dealer'] ) {
		$query['meta_query'] = array( array( 'key' => 'aum_dealer_ext_id', 'value' => sanitize_key( $atts['dealer'] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( 'price' === $atts['orderby'] ) {
		$query['meta_key'] = 'aum_price_gbp'; // phpcs:ignore WordPress.DB.SlowDBQuery
		$query['orderby']  = 'meta_value_num';
		$query['order']    = 'ASC';
	}
	$posts = get_posts( $query );
	if ( ! $posts ) {
		return '<p class="aumd-empty-line">' . esc_html( (string) $atts['empty'] ) . '</p>';
	}
	$out = '<ul class="aum-grid aumd-grid">';
	foreach ( $posts as $p ) {
		$out .= aum_render_card( $p->ID );
	}
	return $out . '</ul>';
}

/** The same card the Breakdance listing templates use, so both look alike. */
function aum_render_card( int $id ): string {
	$image = aum_meta( $id, 'aum_image_url' );
	$link  = get_permalink( $id );
	$v     = static fn( string $k ) => (string) aum_listing_virtual( $id, $k );
	ob_start();
	?>
	<li class="aum-card">
		<?php echo function_exists( 'aum_render_compare_button' ) ? aum_render_compare_button( $id ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		<a class="aum-card-media" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( $image ) : ?><img class="aum-card-img" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?>
		</a>
		<div class="aum-card-body">
			<div class="aum-card-tags">
				<span class="aum-pill-apricot"><?php echo esc_html( $v( 'aum_clearance_text' ) ); ?></span>
				<span class="aum-pill"><?php echo esc_html( $v( 'aum_condition_name' ) ); ?></span>
				<span class="aum-pill-green"><?php echo esc_html( $v( 'aum_warranty_text' ) ); ?></span>
				<span class="aum-pill-lilac"><?php echo esc_html( $v( 'aum_drop_text' ) ); ?></span>
			</div>
			<h3 class="aum-card-title"><a class="aum-card-link" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></h3>
			<p class="aum-card-specs"><?php echo esc_html( $v( 'aum_card_specs' ) ); ?></p>
			<p class="aum-card-meta"><?php echo esc_html( $v( 'aum_dealer_place' ) ); ?></p>
			<div class="aum-card-foot">
				<span class="aum-card-price"><?php echo esc_html( $v( 'aum_price_text' ) ); ?></span>
				<span class="aum-card-was"><?php echo esc_html( $v( 'aum_was_text' ) ); ?></span>
			</div>
		</div>
	</li>
	<?php
	return (string) ob_get_clean();
}

/* ------------------------------------------------------------------ hooks */

add_filter( 'the_content', function ( string $content ) {
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( is_singular( 'aum_listing' ) && ! has_shortcode( $content, 'aum_listing_details' ) ) {
		return $content . aum_render_listing_details( get_the_ID() );
	}
	if ( is_singular( 'aum_dealer' ) && ! has_shortcode( $content, 'aum_dealer_details' ) ) {
		return $content . aum_render_dealer_details( get_the_ID() );
	}
	return $content;
} );

add_shortcode( 'aum_listing_details', static fn( $atts ) => aum_render_listing_details( (int) ( $atts['id'] ?? get_the_ID() ) ) );
add_shortcode( 'aum_go_button', static fn( $atts ) => aum_render_go_button( (int) ( $atts['id'] ?? get_the_ID() ), sanitize_key( $atts['from'] ?? 'listing' ) ) );
add_shortcode( 'aum_dealer_details', static fn( $atts ) => aum_render_dealer_details( (int) ( $atts['id'] ?? get_the_ID() ) ) );
add_shortcode( 'aum_listings', static fn( $atts ) => aum_render_listing_grid( (array) $atts ) );

/** Cheapest first on listing archives and equipment/brand pages. */
add_action( 'pre_get_posts', function ( WP_Query $q ) {
	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}
	if ( $q->is_post_type_archive( 'aum_listing' ) || $q->is_tax( array( 'aum_equipment', 'aum_brand' ) ) ) {
		$q->set( 'meta_key', 'aum_price_gbp' );
		$q->set( 'orderby', 'meta_value_num' );
		$q->set( 'order', 'ASC' );
		$q->set( 'posts_per_page', 24 );
	}
} );
