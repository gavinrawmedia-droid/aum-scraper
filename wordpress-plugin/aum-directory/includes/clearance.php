<?php
/**
 * The Clearance section (added in 0.6.0).
 *
 * Dealers mark some stock as clearance. Approved-used items they mark this way (used,
 * reconditioned, ex-demo) stay on the main site and also appear in Clearance, with a
 * "Clearance" label. Anything else they are clearing (new end-of-line stock, or items whose
 * condition isn't stated) is "clearance-only": it appears in the Clearance section and on its
 * own listing page, and never in the main browse pages, filters, counts, menus or home page.
 *
 *   /clearance/                 A page holding the shortcodes below (address /clearance/).
 *   [aum_clearance_grid]        The clearance cards and page numbers. The page's
 *                               [aum_filters], [aum_active_filters], [aum_results_count] and
 *                               [aum_no_results] switch to clearance stock on that page.
 *   [aum_try_clearance]         "Not found what you need? Try clearance", for the end of the
 *                               main results. Shows on the last page of results only.
 *
 * A query can ask for a section with 'aum_section' => 'main' (the default for listing
 * queries on the site), 'clearance' or 'all' (imports, admin and internal lookups).
 */

defined( 'ABSPATH' ) || exit;

const AUM_PRICE_DROP_SHOW_DAYS = 14;

/* ================================================================== where we are */

/** The page with the address /clearance/. */
function aum_clearance_page_id(): int {
	static $id = null;
	if ( null === $id ) {
		$page = get_page_by_path( 'clearance' );
		$id   = ( $page && 'publish' === $page->post_status ) ? (int) $page->ID : 0;
	}
	return $id;
}

function aum_clearance_base_url(): string {
	return aum_clearance_page_id() ? (string) get_permalink( aum_clearance_page_id() ) : home_url( '/clearance/' );
}

/** The Clearance page with these filters, e.g. array( 'type' => 'road-scooters' ). */
function aum_clearance_url( array $args = array() ): string {
	return $args ? add_query_arg( $args, aum_clearance_base_url() ) : aum_clearance_base_url();
}

/** "clearance" on the Clearance page, "main" everywhere else. */
function aum_view(): string {
	$id = aum_clearance_page_id();
	return ( $id && did_action( 'wp' ) && is_page( $id ) ) ? 'clearance' : 'main';
}

/* ================================================================== keeping the two apart */

function aum_query_is_listings( WP_Query $q ): bool {
	$types = (array) $q->get( 'post_type' );
	if ( in_array( 'aum_listing', $types, true ) ) {
		return true;
	}
	return ! array_filter( $types ) && ( $q->is_tax( array( 'aum_equipment', 'aum_brand', 'aum_condition' ) ) || $q->is_post_type_archive( 'aum_listing' ) );
}

/**
 * Every listing query on the site shows the main (approved-used) stock unless it asks for
 * another section. This covers the archives, Breakdance loops (home page, "More like this",
 * a dealer's stock) and [aum_listings] alike. Single listing pages, compare (which asks for
 * listings by ID) and the WordPress admin are left alone.
 */
add_action(
	'pre_get_posts',
	function ( WP_Query $q ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! aum_query_is_listings( $q ) ) {
			return;
		}
		$section = (string) $q->get( 'aum_section' );
		if ( 'all' === $section || $q->is_singular() || $q->get( 'p' ) || $q->get( 'name' ) || $q->get( 'post__in' ) ) {
			return;
		}
		$clause = 'clearance' === $section
			? array( 'key' => 'aum_clearance', 'value' => '1' )
			: array( 'key' => 'aum_clearance_only', 'compare' => 'NOT EXISTS' );
		$meta   = $q->get( 'meta_query' );
		$q->set( 'meta_query', $meta ? array( 'relation' => 'AND', $meta, $clause ) : array( $clause ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	},
	30
);

/** Clearance listing pages are real pages, so they stay in the sitemap. */
add_filter(
	'wp_sitemaps_posts_query_args',
	static function ( array $args, string $post_type ): array {
		if ( 'aum_listing' === $post_type ) {
			$args['aum_section'] = 'all';
		}
		return $args;
	},
	10,
	2
);

/* ================================================================== the Clearance page */

function aum_clearance_page_num(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only.
	return isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
}

/** The clearance stock for the filters in the address bar (one query per page view). */
function aum_clearance_query(): WP_Query {
	static $q = null;
	if ( null === $q ) {
		$f = aum_effective_filters();
		$q = new WP_Query(
			array(
				'post_type'      => 'aum_listing',
				'post_status'    => 'publish',
				'aum_section'    => 'clearance',
				'posts_per_page' => 24,
				'paged'          => aum_clearance_page_num(),
			) + aum_filter_query_args( $f )
		);
	}
	return $q;
}

/** The query the results count and "no results" message describe on this page, if any. */
function aum_results_query(): ?WP_Query {
	if ( 'clearance' === aum_view() ) {
		return aum_clearance_query();
	}
	return aum_is_listing_archive() ? $GLOBALS['wp_query'] : null;
}

function aum_results_page(): int {
	return 'clearance' === aum_view() ? aum_clearance_page_num() : max( 1, (int) get_query_var( 'paged' ) );
}

function aum_render_clearance_grid(): string {
	if ( 'clearance' !== aum_view() ) {
		return '';
	}
	$q = aum_clearance_query();
	if ( ! $q->have_posts() ) {
		return '';
	}
	$out = '<ul class="aum-grid aum-arch-grid aumd-grid aumd-cl-grid">';
	foreach ( $q->posts as $p ) {
		$out .= aum_render_card( (int) $p->ID );
	}
	$out .= '</ul>';
	if ( $q->max_num_pages > 1 ) {
		$big   = 999999999;
		$base  = add_query_arg( array_merge( aum_filter_args( aum_effective_filters() ), array( 'pg' => $big ) ), aum_clearance_base_url() );
		$links = paginate_links(
			array(
				'base'      => str_replace( (string) $big, '%#%', esc_url_raw( $base ) ),
				'format'    => '',
				'current'   => aum_clearance_page_num(),
				'total'     => (int) $q->max_num_pages,
				'prev_text' => 'Previous',
				'next_text' => 'Next',
			)
		);
		$out  .= '<nav class="aumd-pages" aria-label="More clearance stock">' . $links . '</nav>';
	}
	return $out;
}

/* ================================================================== "try clearance" prompts */

/** Clearance stock matching these filters (any condition), as facet rows. */
function aum_clearance_rows_for( array $f ): array {
	$f['view']      = 'clearance';
	$f['condition'] = array();   // clearance stock has conditions the main site doesn't
	return array_values( array_filter( aum_facet_rows(), static fn( $r ) => aum_row_matches( $r, $f ) ) );
}

function aum_clearance_total(): int {
	return count( array_filter( aum_facet_rows(), static fn( $r ) => $r['clearance'] ) );
}

/** The filters worth carrying across to the Clearance page. */
function aum_clearance_args_for( array $f ): array {
	$args = array();
	foreach ( array( 'type', 'brand', 'dealer' ) as $k ) {
		if ( $f[ $k ] ) {
			$args[ $k ] = $f[ $k ];
		}
	}
	foreach ( array( 'min_price', 'max_price' ) as $k ) {
		if ( $f[ $k ] ) {
			$args[ $k ] = $f[ $k ];
		}
	}
	if ( $f['warranty'] ) {
		$args['warranty'] = 1;
	}
	if ( ! empty( $f['reduced'] ) ) {
		$args['reduced'] = 1;
	}
	return $args;
}

/** "3 road scooters (8mph)", "1 item". */
function aum_clearance_count_label( int $n, string $type ): string {
	if ( 1 === $n ) {
		return '1 item';
	}
	$name = $type ? strtolower( aum_term_name( 'aum_equipment', $type ) ) : 'items';
	return number_format_i18n( $n ) . ' ' . $name;
}

/**
 * The clearance prompt. $where is "end" (below the last page of results) or "empty" (inside
 * the "nothing matches" message). Empty string when there's nothing worth suggesting.
 *
 * Below the results it counts only the clearance stock people haven't just seen: approved-used
 * items a dealer marks as clearance are already in the main results.
 */
function aum_clearance_suggestion( array $f, string $where = 'end' ): string {
	if ( 'clearance' === ( $f['view'] ?? 'main' ) ) {
		return '';
	}
	$rows    = aum_clearance_rows_for( $f );
	$unseen  = count( array_filter( $rows, static fn( $r ) => $r['clearance_only'] ) );
	$elsewhere = count( array_filter( aum_facet_rows(), static fn( $r ) => $r['clearance_only'] ) );
	if ( 'empty' === $where ) {
		if ( ! $rows ) {
			return '';
		}
		$n    = count( $rows );
		$text = sprintf( _n( '%s that a dealer is clearing matches these filters.', '%s that dealers are clearing match these filters.', $n, 'aum-directory' ), aum_clearance_count_label( $n, $f['type'] ) );
		return '<p class="aumd-try-inline"><span class="aum-pill-apricot aumd-try-pill">Clearance</span> ' . esc_html( $text )
			. ' <a href="' . esc_url( aum_clearance_url( aum_clearance_args_for( $f ) ) ) . '">' . esc_html( 1 === $n ? 'See it in clearance' : 'See them in clearance' ) . '</a></p>';
	}
	if ( $unseen ) {
		$url  = aum_clearance_url( aum_clearance_args_for( $f ) );
		$text = sprintf( _n( '%s matching your search is only listed in Clearance, such as new end-of-line stock.', '%s matching your search are only listed in Clearance, such as new end-of-line stock.', $unseen, 'aum-directory' ), aum_clearance_count_label( $unseen, $f['type'] ) );
		$cta  = 'See clearance';
	} elseif ( $elsewhere ) {
		$url  = aum_clearance_base_url();
		$text = 'Dealers are also clearing other stock that isn\'t in these listings, such as new end-of-line items.';
		$cta  = 'Browse clearance';
	} else {
		return '';   // all clearance stock is approved used, so it's already in the results above
	}
	return '<aside class="aumd-try" aria-label="Clearance stock">'
		. '<div class="aumd-try-copy"><p class="aumd-try-h">Not found what you need? Try clearance.</p>'
		. '<p class="aumd-try-p">' . esc_html( $text ) . ' Clearance is kept apart from the approved-used stock, and each item says what condition it\'s in.</p></div>'
		. '<a class="aumd-btn-outline aumd-try-btn" href="' . esc_url( $url ) . '">' . esc_html( $cta ) . '</a>'
		. '</aside>';
}

/** [aum_try_clearance]: below the main results, on their last page only. */
function aum_render_try_clearance(): string {
	global $wp_query;
	if ( 'main' !== aum_view() || ! aum_is_listing_archive() || ! (int) $wp_query->found_posts ) {
		return '';
	}
	if ( aum_results_page() < (int) $wp_query->max_num_pages ) {
		return '';   // people see the prompt once they've been through the main stock
	}
	return aum_clearance_suggestion( aum_effective_filters(), 'end' );
}

/* ================================================================== listing pages */

/** A price cut we saw ourselves, while it's recent: [amount, from, at (timestamp)] or null. */
function aum_price_drop( int $id ): ?array {
	$from  = (float) get_post_meta( $id, 'aum_price_drop_from_gbp', true );
	$price = (float) get_post_meta( $id, 'aum_price_gbp', true );
	$at    = strtotime( (string) get_post_meta( $id, 'aum_price_dropped_at', true ) );
	if ( $from <= $price || $price <= 0 || ! $at || time() - $at > AUM_PRICE_DROP_SHOW_DAYS * DAY_IN_SECONDS ) {
		return null;
	}
	return array( 'amount' => $from - $price, 'from' => $from, 'at' => $at );
}

/** The clearance box at the top of a listing's buy box. */
function aum_render_clearance_note( int $id ): string {
	if ( ! get_post_meta( $id, 'aum_clearance', true ) ) {
		return '';
	}
	$link = '<a href="' . esc_url( aum_clearance_base_url() ) . '">See all clearance stock</a>';
	if ( ! get_post_meta( $id, 'aum_clearance_only', true ) ) {
		return '<div class="aumd-cl-note is-used"><p><span class="aum-pill-apricot">Clearance</span> The dealer has marked this as clearance stock. ' . $link . '</p></div>';
	}
	$is_new = 'new-end-of-line' === aum_first_term( $id, 'aum_condition', 'slug' );
	$text   = $is_new
		? 'The dealer is clearing this as a new, end-of-line item.'
		: 'The dealer is clearing this item and doesn\'t say whether it\'s new or used, so ask before you buy.';
	return '<div class="aumd-cl-note"><p class="aumd-cl-note-h"><span class="aum-pill-apricot">Clearance</span></p>'
		. '<p>' . esc_html( $text ) . ' It\'s listed in our separate Clearance section, not with the approved-used stock.</p>'
		. '<p>' . $link . '</p></div>';
}

/* ================================================================== shortcodes */

add_shortcode( 'aum_clearance_grid', static fn() => aum_render_clearance_grid() );
add_shortcode( 'aum_try_clearance', static fn() => aum_render_try_clearance() );
