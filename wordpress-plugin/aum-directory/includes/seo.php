<?php
/**
 * Page titles, search descriptions and share tags (added in 0.7.0).
 *
 * Every listing, equipment type, brand and dealer page gets its own title and description,
 * written from its live data (price, dealer, town, how many listings, the lowest price), so
 * nobody has to type them in and they stay right as stock changes. Fixed pages (home, Clearance,
 * the legal pages) get set wording, and any page can be given its own in the "Search engines"
 * box when editing it.
 *
 * Filtered and sorted versions of a listings page (?brand=, ?sort= and so on) and empty type
 * pages are marked "noindex, follow", and archives point their canonical address at the plain
 * page, so search engines index each page once.
 *
 * Steps aside if a dedicated SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework)
 * is switched on, so the two never both write titles.
 */

defined( 'ABSPATH' ) || exit;

const AUM_SEO_SITE = 'Approved Used Mobility';

function aum_seo_enabled(): bool {
	$other = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' )
		|| defined( 'AIOSEO_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	return (bool) apply_filters( 'aum_seo_enabled', ! $other );
}

/* ================================================================== helpers */

/** Cut text to a length at a word boundary, adding an ellipsis when something was cut. */
function aum_seo_clip( string $text, int $max ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut  = mb_substr( $text, 0, $max - 1 );
	$last = mb_strrpos( $cut, ' ' );
	if ( false !== $last && $last > $max * 0.6 ) {
		$cut = mb_substr( $cut, 0, $last );
	}
	return rtrim( $cut, " ,.;:–-" ) . '…';
}

/** "Title | Approved Used Mobility", leaving the site name off when the title is already long. */
function aum_seo_title_with_site( string $title ): string {
	$title = trim( $title );
	$full  = $title . ' | ' . AUM_SEO_SITE;
	return mb_strlen( $full ) <= 70 ? $full : aum_seo_clip( $title, 70 );
}

/** "Road scooters (8mph)" => "road scooters (8mph)"; keeps capitals that start a name. */
function aum_seo_lower_first( string $text ): string {
	return preg_match( '/^[A-Z][a-z]/', $text ) ? mb_strtolower( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 ) : $text;
}

/** One item of an equipment type, for listing descriptions. */
function aum_seo_type_singular( string $slug ): string {
	$names = array(
		'road-scooters'     => 'road scooter',
		'pavement-scooters' => 'pavement scooter',
		'boot-scooters'     => 'boot scooter',
		'mobility-scooters' => 'mobility scooter',
		'powerchairs'       => 'powerchair',
		'wheelchairs'       => 'wheelchair',
		'stairlifts'        => 'stairlift',
		'riser-recliners'   => 'riser recliner chair',
		'profiling-beds'    => 'profiling bed',
		'hoists'            => 'hoist',
		'bath-lifts'        => 'bath lift',
	);
	return $names[ $slug ] ?? 'mobility equipment';
}

/** How people search for each type, used in type page titles. */
function aum_seo_type_title( string $slug, string $name ): string {
	$titles = array(
		'road-scooters'     => 'Used road mobility scooters (8mph) for sale',
		'pavement-scooters' => 'Used pavement mobility scooters (4mph) for sale',
		'boot-scooters'     => 'Used boot and folding mobility scooters',
		'mobility-scooters' => 'Used mobility scooters for sale',
		'powerchairs'       => 'Used and ex-demo powerchairs for sale',
		'wheelchairs'       => 'Used wheelchairs for sale',
		'stairlifts'        => 'Reconditioned stairlifts for sale',
		'riser-recliners'   => 'Ex-display and used riser recliner chairs',
		'profiling-beds'    => 'Used profiling beds for sale',
		'hoists'            => 'Used hoists for sale',
		'bath-lifts'        => 'Used bath lifts for sale',
	);
	return $titles[ $slug ] ?? 'Used ' . aum_seo_lower_first( $name ) . ' for sale';
}

/** Live main-site stock (not clearance-only), optionally narrowed by type, brand or dealer. */
function aum_seo_rows( array $where = array(), bool $clearance = false ): array {
	return array_values(
		array_filter(
			aum_facet_rows(),
			static function ( array $r ) use ( $where, $clearance ): bool {
				if ( $clearance ? empty( $r['clearance'] ) : ! empty( $r['clearance_only'] ) ) {
					return false;
				}
				foreach ( $where as $k => $v ) {
					if ( ( $r[ $k ] ?? '' ) !== $v ) {
						return false;
					}
				}
				return true;
			}
		)
	);
}

/** [count, dealers, lowest price] for a set of rows. */
function aum_seo_stats( array $rows ): array {
	$prices = array_filter( array_column( $rows, 'price' ), static fn( $p ) => $p > 0 );
	return array(
		count( $rows ),
		count( array_unique( array_filter( array_column( $rows, 'dealer' ) ) ) ),
		$prices ? (float) min( $prices ) : 0.0,
	);
}

/** "18 UK mobility dealers", "1 UK mobility dealer". */
function aum_seo_dealers_text( int $n ): string {
	return $n . ' UK mobility ' . ( 1 === $n ? 'dealer' : 'dealers' );
}

/** The filter and sort arguments that make a listings page a variation of the plain page. */
function aum_seo_variation_args(): array {
	return array( 'type', 'brand', 'dealer', 'condition', 'min_price', 'max_price', 'warranty', 'sort', 'reduced', 'pg', 'ids' );
}

function aum_seo_is_variation(): bool {
	foreach ( aum_seo_variation_args() as $k ) {
		if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
			return true;
		}
	}
	return false;
}

function aum_seo_page_suffix(): string {
	$page = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	return $page > 1 ? ' – page ' . $page : '';
}

/* ================================================================== what each page says */

/**
 * Title, description and index rules for the page being viewed.
 *
 * @return array{title:string,description:string,noindex:bool,canonical:string,type:string}
 */
function aum_seo_current(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$out = array( 'title' => '', 'description' => '', 'noindex' => false, 'canonical' => '', 'type' => 'website' );

	if ( is_front_page() ) {
		[ $n, $d ] = aum_seo_stats( aum_seo_rows() );
		$out['title']       = 'Approved Used Mobility: used mobility equipment from UK dealers';
		$out['description'] = sprintf( 'Compare %s used and ex-demo mobility scooters, powerchairs, stairlifts and riser chairs from %d UK dealers side by side, then buy direct from the dealer.', number_format_i18n( $n ), $d );
	} elseif ( is_singular( 'aum_listing' ) ) {
		$out = array_merge( $out, aum_seo_listing( get_queried_object_id() ) );
	} elseif ( is_singular( 'aum_dealer' ) ) {
		$out = array_merge( $out, aum_seo_dealer( get_queried_object_id() ) );
	} elseif ( is_tax( 'aum_equipment' ) ) {
		$out = array_merge( $out, aum_seo_equipment( get_queried_object() ) );
	} elseif ( is_tax( 'aum_brand' ) ) {
		$out = array_merge( $out, aum_seo_brand( get_queried_object() ) );
	} elseif ( is_post_type_archive( 'aum_listing' ) ) {
		[ $n, $d, $min ] = aum_seo_stats( aum_seo_rows() );
		$out['title']       = aum_seo_title_with_site( 'Used mobility equipment for sale' . aum_seo_page_suffix() );
		$out['description'] = sprintf( 'Browse %s used and ex-demo mobility scooters, powerchairs, stairlifts and more from %d UK dealers%s, cheapest first. Filter by type, brand and price.', number_format_i18n( $n ), $d, $min ? ', from ' . aum_money( $min ) : '' );
		$out['canonical']   = (string) get_post_type_archive_link( 'aum_listing' );
	} elseif ( is_post_type_archive( 'aum_dealer' ) ) {
		$dealers            = (int) wp_count_posts( 'aum_dealer' )->publish;
		$out['title']       = aum_seo_title_with_site( 'Mobility dealers near you' );
		$out['description'] = sprintf( 'Find %s on a map, see which are nearest to your postcode, and browse each dealer\'s used and ex-demo stock.', aum_seo_dealers_text( $dealers ) );
		$out['canonical']   = (string) get_post_type_archive_link( 'aum_dealer' );
	} elseif ( is_page() ) {
		$out = array_merge( $out, aum_seo_page( get_queried_object_id() ) );
	}

	// A filtered or sorted version of a listings page, or a compare link: one indexed copy only.
	if ( aum_seo_is_variation() && ( is_post_type_archive( 'aum_listing' ) || is_tax( array( 'aum_equipment', 'aum_brand' ) ) || is_page( array( 'clearance', 'compare' ) ) ) ) {
		$out['noindex'] = true;
	}
	return $cache = $out;
}

function aum_seo_listing( int $id ): array {
	$v      = static fn( string $k ) => (string) aum_listing_virtual( $id, $k );
	$type   = aum_equipment_slug( $id );
	$dealer = $v( 'aum_dealer_name' );
	$town   = $v( 'aum_dealer_town' );
	$price  = $v( 'aum_price_text' );
	$cond   = $v( 'aum_condition_name' );
	if ( get_post_meta( $id, 'aum_clearance_only', true ) ) {
		$cond = 'New (end of line)' === $cond ? 'End-of-line' : 'Clearance';
	}
	$cond = $cond ? preg_replace( '/\s*\(end of line\)/i', ' end-of-line', $cond ) : 'Used';

	// "Model, £1,295 | Dealer", dropping the dealer, then the price, when the model name is long.
	$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES );
	foreach ( array( 'Price on request' !== $price ? ', ' . $price : '', $dealer ? ' | ' . $dealer : '' ) as $extra ) {
		if ( '' !== $extra && mb_strlen( $title . $extra ) <= 70 ) {
			$title .= $extra;
		}
	}

	$first = sprintf( '%s %s for sale at %s%s', $cond, aum_seo_type_singular( $type ), $dealer ?: 'a UK mobility dealer', $town ? ', ' . $town : '' );
	$first .= ': ' . ( 'Price on request' === $price ? 'price on request' : $price );
	if ( $v( 'aum_was_text' ) ) {
		$first .= ', ' . aum_seo_lower_first( $v( 'aum_was_text' ) );
	}
	$parts = array( $first . '.' );
	if ( $v( 'aum_card_specs' ) ) {
		$parts[] = ucfirst( str_replace( ' · ', ', ', $v( 'aum_card_specs' ) ) ) . '.';
	}
	if ( $v( 'aum_warranty_text' ) ) {
		$parts[] = ucfirst( $v( 'aum_warranty_text' ) ) . '.';
	}
	$parts[] = 'Compare it, then buy direct from the dealer.';

	return array(
		'title'       => aum_seo_clip( $title, 70 ),
		'description' => implode( ' ', $parts ),
		'noindex'     => 'publish' !== get_post_status( $id ),
		'type'        => 'product',
	);
}

function aum_seo_dealer( int $id ): array {
	$name  = html_entity_decode( get_the_title( $id ), ENT_QUOTES );
	$town  = trim( (string) get_post_meta( $id, 'aum_town', true ) );
	$count = (int) get_post_meta( $id, 'aum_listing_count', true );
	$other = trim( (string) get_post_meta( $id, 'aum_other_branches', true ) );
	$addr  = (string) aum_dealer_virtual( $id, 'aum_address_full' );
	$where = $name . ( $town ? ' in ' . $town : '' );

	if ( $count ) {
		$desc = sprintf( 'See %s used and ex-demo %s from %s, with prices and warranty.', number_format_i18n( $count ), 1 === $count ? 'item' : 'items', $where );
	} else {
		$desc = sprintf( '%s has no used stock listed at the moment. New stock is added every morning.', $where );
	}
	$desc .= $addr ? ' Showroom: ' . $addr . '.' : '';
	$desc .= $other ? ' Also ' . $other . '.' : '';

	return array(
		'title'       => aum_seo_title_with_site( $name . ( $town ? ', ' . $town : '' ) . ': used mobility equipment' ),
		'description' => $desc,
	);
}

function aum_seo_equipment( $term ): array {
	if ( ! $term instanceof WP_Term ) {
		return array();
	}
	$name               = html_entity_decode( $term->name, ENT_QUOTES );
	[ $n, $d, $min ]    = aum_seo_stats( aum_seo_rows( array( 'type' => $term->slug ) ) );
	$plural             = aum_seo_lower_first( $name );
	$home               = in_array( $term->slug, aum_home_equipment_slugs(), true );
	$facts              = $home ? 'price, condition and warranty' : 'price, range, weight limit and warranty';
	$phrases            = array(
		'stairlifts'      => 'reconditioned and used stairlifts',
		'riser-recliners' => 'ex-display and used riser recliner chairs',
	);
	$phrase             = $phrases[ $term->slug ] ?? 'used and ex-demo ' . $plural;
	$out                = array(
		'title'     => aum_seo_title_with_site( aum_seo_type_title( $term->slug, $name ) . aum_seo_page_suffix() ),
		'canonical' => (string) get_term_link( $term ),
	);
	if ( $n ) {
		$out['description'] = sprintf( 'Compare %s %s from %s%s. See the %s side by side.', number_format_i18n( $n ), $phrase, aum_seo_dealers_text( $d ), $min ? ', from ' . aum_money( $min ) : '', $facts );
	} else {
		$out['description'] = sprintf( 'There are no used %s listed at the moment. New stock from UK mobility dealers is added every morning.', $plural );
		$out['noindex']     = true;
	}
	return $out;
}

function aum_seo_brand( $term ): array {
	if ( ! $term instanceof WP_Term ) {
		return array();
	}
	$name            = html_entity_decode( $term->name, ENT_QUOTES );
	$rows            = aum_seo_rows( array( 'brand' => $term->slug ) );
	[ $n, $d, $min ] = aum_seo_stats( $rows );
	$types           = array_count_values( array_filter( array_column( $rows, 'type' ) ) );
	arsort( $types );
	$names = array();
	foreach ( array_slice( array_keys( $types ), 0, 3 ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'aum_equipment' );
		if ( $t ) {
			$names[] = aum_seo_lower_first( preg_replace( '/\s*\(\d+mph\)/', '', html_entity_decode( $t->name, ENT_QUOTES ) ) );
		}
	}
	$what = $names ? ( count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names ) : $names[0] ) : 'mobility equipment';
	$out  = array(
		'title'     => aum_seo_title_with_site( 'Used ' . $name . ' mobility equipment' . aum_seo_page_suffix() ),
		'canonical' => (string) get_term_link( $term ),
	);
	if ( $n ) {
		$out['description'] = sprintf( 'Compare %s used and ex-demo %s %s from %s%s.', number_format_i18n( $n ), $name, $what, aum_seo_dealers_text( $d ), $min ? ', from ' . aum_money( $min ) : '' );
	} else {
		$out['description'] = sprintf( 'There is no used %s equipment listed at the moment. New stock from UK mobility dealers is added every morning.', $name );
		$out['noindex']     = true;
	}
	return $out;
}

/** Set wording for the fixed pages, unless the page has its own in the "Search engines" box. */
function aum_seo_page( int $id ): array {
	$slug  = (string) get_post_field( 'post_name', $id );
	$title = trim( (string) get_post_meta( $id, 'aum_seo_title', true ) );
	$desc  = trim( (string) get_post_meta( $id, 'aum_seo_description', true ) );
	$out   = array();

	switch ( $slug ) {
		case 'clearance':
			[ $n, $d, $min ]    = aum_seo_stats( aum_seo_rows( array(), true ) );
			$out['title']       = aum_seo_title_with_site( 'Clearance and end-of-line mobility equipment' );
			$out['description'] = sprintf( 'Clearance, ex-display and end-of-line mobility equipment UK dealers are clearing, kept apart from approved-used stock: %s items from %s%s.', number_format_i18n( $n ), aum_seo_dealers_text( $d ), $min ? ', from ' . aum_money( $min ) : '' );
			break;
		case 'compare':
			$out['noindex']     = true;   // each comparison is personal; the page itself has nothing to index
			$out['title']       = aum_seo_title_with_site( 'Compare mobility equipment side by side' );
			$out['description'] = 'Compare the price, range, weight limit and warranty of up to three used mobility scooters, powerchairs or stairlifts side by side.';
			break;
		case 'privacy-policy':
			$out['title']       = aum_seo_title_with_site( 'Privacy policy' );
			$out['description'] = 'How Approved Used Mobility handles your data: no account needed, no data sold, and analytics only with your consent.';
			break;
		case 'cookie-policy':
			$out['title']       = aum_seo_title_with_site( 'Cookie policy' );
			$out['description'] = 'The cookies and browser storage Approved Used Mobility uses, what each one does, and how to change your choices.';
			break;
		case 'terms-of-use':
			$out['title']       = aum_seo_title_with_site( 'Terms of use' );
			$out['description'] = 'How Approved Used Mobility works: we list UK dealers\' used and ex-demo stock, we\'re not the seller, and what "approved used" means here.';
			break;
		case 'coming-soon':
			$out['noindex'] = true;
			break;
	}
	if ( $title ) {
		$out['title'] = $title;
	}
	if ( $desc ) {
		$out['description'] = $desc;
	}
	return $out;
}

/* ================================================================== output */

add_filter(
	'pre_get_document_title',
	static function ( $title ) {
		if ( ! aum_seo_enabled() || is_admin() ) {
			return $title;
		}
		$ours = aum_seo_current()['title'];
		return '' !== $ours ? $ours : $title;
	},
	20
);

add_filter(
	'wp_robots',
	static function ( array $robots ): array {
		if ( aum_seo_enabled() && aum_seo_current()['noindex'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}
);

// WordPress's own canonical link on single pages is left off when the page is marked noindex.
add_action(
	'wp',
	static function () {
		if ( ! is_admin() && aum_seo_enabled() && aum_seo_current()['noindex'] ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}
);

add_action(
	'wp_head',
	static function () {
		if ( ! aum_seo_enabled() ) {
			return;
		}
		$seo   = aum_seo_current();
		$title = '' !== $seo['title'] ? $seo['title'] : wp_get_document_title();
		$desc  = '' !== $seo['description'] ? aum_seo_clip( $seo['description'], 158 ) : '';
		$url   = $seo['canonical'];
		if ( '' === $url && is_singular() ) {
			$url = (string) wp_get_canonical_url();
		}

		echo "\n<!-- AUM Directory: titles and descriptions -->\n";
		if ( $desc ) {
			printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $desc ) );
		}
		// WordPress only adds a canonical link to single pages, so archives get theirs here.
		// A page marked noindex gets none: search engines treat the two together as mixed signals.
		if ( '' !== $seo['canonical'] && ! is_singular() && ! $seo['noindex'] ) {
			$canonical = $seo['canonical'];
			$page      = (int) get_query_var( 'paged' );
			if ( $page > 1 && ! aum_seo_is_variation() ) {
				$canonical = trailingslashit( $canonical ) . user_trailingslashit( 'page/' . $page, 'paged' );
			}
			printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $canonical ) );
		}
		printf( "<meta property=\"og:site_name\" content=\"%s\">\n", esc_attr( AUM_SEO_SITE ) );
		echo "<meta property=\"og:locale\" content=\"en_GB\">\n";
		printf( "<meta property=\"og:type\" content=\"%s\">\n", esc_attr( $seo['type'] ) );
		printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $title ) );
		if ( $desc ) {
			printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( $desc ) );
		}
		if ( $url ) {
			printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $url ) );
		}
		echo "<meta name=\"twitter:card\" content=\"summary\">\n";
	},
	2
);

/* ================================================================== sitemap and robots.txt */

// The built-in sitemap at /wp-sitemap.xml: no list of user names, and no pages that aren't for search.
add_filter(
	'wp_sitemaps_add_provider',
	static fn( $provider, string $name ) => 'users' === $name ? false : $provider,
	10,
	2
);

add_filter(
	'wp_sitemaps_posts_query_args',
	static function ( array $args, string $post_type ): array {
		if ( 'page' === $post_type ) {
			$skip = array();
			foreach ( array( 'compare', 'coming-soon' ) as $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page ) {
					$skip[] = (int) $page->ID;
				}
			}
			$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $skip );
		}
		return $args;
	},
	10,
	2
);

add_filter(
	'wp_sitemaps_taxonomies',
	static function ( array $taxonomies ): array {
		unset( $taxonomies['aum_condition'] );
		return $taxonomies;
	}
);

// Clicks through to dealers are redirects, not pages.
add_filter(
	'robots_txt',
	static function ( string $output, $public ): string {
		if ( ! $public ) {
			return $output;
		}
		$anchor = "Allow: /wp-admin/admin-ajax.php\n";
		return str_contains( $output, $anchor ) ? str_replace( $anchor, $anchor . "Disallow: /go/\n", $output ) : $output . "Disallow: /go/\n";
	},
	10,
	2
);

/* ================================================================== "Search engines" box on pages */

add_action(
	'add_meta_boxes_page',
	static function () {
		if ( aum_seo_enabled() ) {
			add_meta_box( 'aum-seo', 'Search engines', 'aum_seo_meta_box', 'page', 'normal', 'low' );
		}
	}
);

function aum_seo_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'aum_seo_save', 'aum_seo_nonce' );
	$title = (string) get_post_meta( $post->ID, 'aum_seo_title', true );
	$desc  = (string) get_post_meta( $post->ID, 'aum_seo_description', true );
	?>
	<p><label for="aum-seo-title"><strong>Title in search results</strong></label><br>
		<input type="text" id="aum-seo-title" name="aum_seo_title" value="<?php echo esc_attr( $title ); ?>" class="widefat" maxlength="90"></p>
	<p><label for="aum-seo-desc"><strong>Description in search results</strong></label><br>
		<textarea id="aum-seo-desc" name="aum_seo_description" rows="3" class="widefat" maxlength="300"><?php echo esc_textarea( $desc ); ?></textarea></p>
	<p class="description">Leave blank to use the site's standard wording. Aim for a title under 60 characters and a description under 155.</p>
	<?php
}

add_action(
	'save_post_page',
	static function ( int $post_id ) {
		if ( ! isset( $_POST['aum_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aum_seo_nonce'] ) ), 'aum_seo_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		foreach ( array( 'aum_seo_title', 'aum_seo_description' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
);
