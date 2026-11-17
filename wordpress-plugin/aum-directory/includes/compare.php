<?php
/**
 * Compare up to three listings side by side (added in 0.3.0).
 *
 * How it fits together:
 *   [aum_compare_button]  A "Compare" tick box. Goes on listing cards (Breakdance card block)
 *                         and is added to [aum_buy_box] on the listing page automatically.
 *   aum-front.js          Keeps the visitor's picks in their browser and shows a bar along
 *                         the bottom of the screen with a link to the compare page.
 *   [aum_compare]         The side-by-side table, for a page at /compare/. It reads the picks
 *                         from the address (/compare/?ids=12,34,56), so a comparison can be
 *                         bookmarked or sent to a relative.
 *
 * Nothing is stored on the server and no cookies are set.
 */

defined( 'ABSPATH' ) || exit;

function aum_compare_max(): int {
	return 3;
}

/** The page holding [aum_compare]: the page with the address /compare/. */
function aum_compare_page_id(): int {
	static $id = null;
	if ( null === $id ) {
		$page = get_page_by_path( 'compare' );
		$id   = ( $page && 'publish' === $page->post_status ) ? (int) $page->ID : 0;
	}
	return $id;
}

function aum_compare_base_url(): string {
	return aum_compare_page_id() ? (string) get_permalink( aum_compare_page_id() ) : home_url( '/compare/' );
}

/** The compare page for these listings. With none, ?ids= is left empty so the page shows as cleared. */
function aum_compare_url( array $ids = array() ): string {
	return add_query_arg( 'ids', implode( ',', array_map( 'intval', $ids ) ), aum_compare_base_url() );
}

/** Listing IDs from ?ids=, in the order given. Returns [ids found, how many were asked for]. */
function aum_compare_request_ids(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only.
	$raw   = isset( $_GET['ids'] ) && is_string( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
	$asked = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
	$asked = array_slice( $asked, 0, aum_compare_max() );
	$found = array_values(
		array_filter(
			$asked,
			static fn( int $id ) => 'aum_listing' === get_post_type( $id ) && 'publish' === get_post_status( $id )
		)
	);
	return array( $found, count( $asked ) );
}

function aum_compare_title( int $id ): string {
	return html_entity_decode( get_the_title( $id ), ENT_QUOTES );
}

/** What the browser needs to show a pick in the bottom bar. */
function aum_compare_item( int $id ): array {
	return array(
		'id' => $id,
		't'  => aum_compare_title( $id ),
		'p'  => (string) aum_listing_virtual( $id, 'aum_price_text' ),
	);
}

/* ================================================================== the tick box */

function aum_render_compare_button( int $id, string $variant = 'card' ): string {
	if ( 'aum_listing' !== get_post_type( $id ) ) {
		return '';
	}
	$item  = aum_compare_item( $id );
	$label = 'card' === $variant ? 'Compare' : 'Compare with others';
	// Hidden until aum-front.js runs, so it never shows as a box that does nothing.
	return sprintf(
		'<label class="aumd-cmp aumd-cmp-%1$s" hidden><input type="checkbox" class="aumd-cmp-input" data-aum-cmp="%2$d" data-title="%3$s" data-price="%4$s"><span class="aumd-cmp-text">%5$s</span><span class="aumd-sr"> %6$s</span></label>',
		esc_attr( sanitize_html_class( $variant ) ),
		$id,
		esc_attr( $item['t'] ),
		esc_attr( $item['p'] ),
		esc_html( $label ),
		esc_html( $item['t'] )
	);
}

/* ================================================================== the comparison table */

/**
 * Number-based rows that can earn a "best" label. 'dir' says which way is better.
 * Top speed and model year are left out on purpose: faster or newer isn't always better.
 */
function aum_compare_metrics(): array {
	return array(
		'aum_price_gbp'          => array( 'best' => 'Lowest price', 'dir' => 'min' ),
		'aum_range_miles'        => array( 'best' => 'Furthest on a charge', 'dir' => 'max' ),
		'aum_max_user_weight_kg' => array( 'best' => 'Carries the most', 'dir' => 'max' ),
		'aum_heaviest_part_kg'   => array( 'best' => 'Lightest to lift', 'dir' => 'min' ),
		'aum_warranty_months'    => array( 'best' => 'Longest warranty', 'dir' => 'max' ),
	);
}

/** Which listings win each metric. Needs at least two stated values that differ. */
function aum_compare_winners( array $ids ): array {
	$out = array();
	foreach ( aum_compare_metrics() as $key => $m ) {
		$vals = array();
		foreach ( $ids as $id ) {
			$v = aum_num( $id, $key );
			if ( $v > 0 ) {
				$vals[ $id ] = $v;
			}
		}
		if ( count( $vals ) < 2 || count( array_unique( array_map( 'strval', $vals ) ) ) < 2 ) {
			continue;
		}
		$best        = 'min' === $m['dir'] ? min( $vals ) : max( $vals );
		$out[ $key ] = array_keys( array_filter( $vals, static fn( $v ) => $v == $best ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- floats from meta.
	}
	return $out;
}

function aum_compare_badge( string $text ): string {
	return '<span class="aumd-best">' . esc_html( $text ) . '</span>';
}

function aum_compare_not_stated(): string {
	return '<span class="aumd-ns">Not stated</span>';
}

/**
 * The rows, each: label => [ per-listing [html, plain text for spotting differences] ].
 * Rows where nothing is stated for any listing are dropped.
 */
function aum_compare_rows( array $ids, array $winners ): array {
	$unit = static function ( int $id, string $key, string $suffix ): string {
		return aum_num( $id, $key ) > 0 ? aum_fmt_number( aum_num( $id, $key ) ) . $suffix : '';
	};
	$cell = static function ( string $text, int $id, string $metric = '' ) use ( $winners ): array {
		if ( '' === $text ) {
			return array( aum_compare_not_stated(), '' );
		}
		$html = esc_html( $text );
		if ( $metric && in_array( $id, $winners[ $metric ] ?? array(), true ) ) {
			$html .= ' ' . aum_compare_badge( aum_compare_metrics()[ $metric ]['best'] );
		}
		return array( $html, $text );
	};

	$rows = array();
	foreach ( $ids as $id ) {
		// Price, with the saving underneath.
		$price = (string) aum_listing_virtual( $id, 'aum_price_text' );
		$html  = '<span class="aumd-cmp-price">' . esc_html( $price ) . '</span>';
		$was   = (string) aum_listing_virtual( $id, 'aum_was_text' );
		if ( $was ) {
			$html .= '<span class="aumd-cmp-was"><span class="aumd-strike">' . esc_html( $was ) . '</span> <span class="aumd-saving">' . esc_html( (string) aum_listing_virtual( $id, 'aum_saving_text' ) ) . '</span></span>';
		}
		if ( in_array( $id, $winners['aum_price_gbp'] ?? array(), true ) ) {
			$html .= aum_compare_badge( aum_compare_metrics()['aum_price_gbp']['best'] );
		}
		$rows['Price'][ $id ] = array( $html, $price . $was );

		$rows['Condition'][ $id ]       = $cell( aum_first_term( $id, 'aum_condition' ), $id );
		$months                         = (int) get_post_meta( $id, 'aum_warranty_months', true );
		$rows['Warranty'][ $id ]        = $cell( $months > 0 ? $months . ( 1 === $months ? ' month' : ' months' ) : '', $id, 'aum_warranty_months' );
		$rows['Model year'][ $id ]      = $cell( (string) aum_listing_virtual( $id, 'aum_year_text' ), $id );
		$rows['Top speed'][ $id ]       = $cell( $unit( $id, 'aum_top_speed_mph', ' mph' ), $id );
		$rows['Range'][ $id ]           = $cell( $unit( $id, 'aum_range_miles', ' miles' ), $id, 'aum_range_miles' );
		$rows['Max user weight'][ $id ] = $cell( $unit( $id, 'aum_max_user_weight_kg', ' kg' ), $id, 'aum_max_user_weight_kg' );
		$rows['Heaviest part'][ $id ]   = $cell( $unit( $id, 'aum_heaviest_part_kg', ' kg' ), $id, 'aum_heaviest_part_kg' );
		$rows['Brand'][ $id ]           = $cell( aum_first_term( $id, 'aum_brand' ), $id );
		$rows['Type'][ $id ]            = $cell( aum_first_term( $id, 'aum_equipment' ), $id );

		$dealer = aum_listing_dealer( $id );
		if ( $dealer ) {
			$town = (string) get_post_meta( $dealer, 'aum_town', true );
			$html = '<a href="' . esc_url( get_permalink( $dealer ) ) . '">' . esc_html( html_entity_decode( get_the_title( $dealer ), ENT_QUOTES ) ) . '</a>' . ( $town ? '<span class="aumd-cmp-sub">' . esc_html( $town ) . '</span>' : '' );
			$rows['Sold by'][ $id ] = array( $html, (string) $dealer );
		} else {
			$rows['Sold by'][ $id ] = array( aum_compare_not_stated(), '' );
		}

		$basis              = (string) get_post_meta( $id, 'aum_price_basis', true );
		$rows['VAT'][ $id ] = aum_num( $id, 'aum_price_gbp' ) > 0 ? array( esc_html( aum_price_basis_label( $basis ) ), $basis ) : array( '<span class="aumd-ns">Ask the dealer</span>', '' );

		$seen                       = aum_meta( $id, 'aum_last_seen' );
		$ts                         = $seen ? strtotime( (string) $seen ) : 0;
		$rows['Last checked'][ $id ] = $cell( $ts ? wp_date( 'j F Y', $ts ) : '', $id );
	}

	return array_filter(
		$rows,
		static fn( array $cells ) => count( array_filter( $cells, static fn( array $c ) => '' !== $c[1] ) ) > 0
	);
}

function aum_render_compare(): string {
	list( $ids, $asked ) = aum_compare_request_ids();
	$missing             = $asked - count( $ids );
	$listings_url        = (string) get_post_type_archive_link( 'aum_listing' );
	$data                = wp_json_encode( array_map( 'aum_compare_item', $ids ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );

	ob_start();
	echo '<div class="aumd-cmpage" data-aum-compare-page data-items="' . esc_attr( (string) $data ) . '">';

	if ( $missing > 0 ) {
		printf(
			'<p class="aumd-note">%s</p>',
			esc_html( 1 === $missing ? 'One of the machines you picked is no longer listed. It may have sold.' : $missing . ' of the machines you picked are no longer listed. They may have sold.' )
		);
	}

	if ( ! $ids ) {
		?>
		<div class="aumd-empty">
			<h2 class="aumd-empty-title">Nothing to compare yet</h2>
			<p>Tick <strong>Compare</strong> on up to <?php echo esc_html( (string) aum_compare_max() ); ?> machines while you browse. They'll appear here side by side, so you can see the price, range, weight and warranty at a glance.</p>
			<p><a class="aumd-btn" href="<?php echo esc_url( $listings_url ); ?>">Browse listings</a></p>
		</div>
		<?php
		echo '</div>';
		return (string) ob_get_clean();
	}

	$count   = count( $ids );
	$winners = aum_compare_winners( $ids );
	$rows    = aum_compare_rows( $ids, $winners );
	$types   = array_unique( array_filter( array_map( static fn( int $id ) => aum_first_term( $id, 'aum_equipment' ), $ids ) ) );
	$more    = $listings_url;
	if ( 1 === count( $types ) ) {
		$term = get_the_terms( $ids[0], 'aum_equipment' );
		$link = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term[0] ) : '';
		$more = ( $link && ! is_wp_error( $link ) ) ? $link : $listings_url;
	}
	?>
	<div class="aumd-cmp-bar">
		<p class="aumd-cmp-count">
			<?php echo esc_html( sprintf( 'Comparing %1$d of %2$d', $count, aum_compare_max() ) ); ?>
			<?php if ( $count < aum_compare_max() ) : ?>
				· <a href="<?php echo esc_url( $more ); ?>"><?php echo esc_html( 1 === $count ? 'Add another machine' : 'Add one more' ); ?></a>
			<?php endif; ?>
		</p>
		<div class="aumd-cmp-tools">
			<?php if ( $count > 1 ) : ?>
				<label class="aumd-check aumd-cmp-diff" hidden><input type="checkbox" data-aum-diff> Only show differences</label>
			<?php endif; ?>
			<a class="aumd-cmp-clear" href="<?php echo esc_url( aum_compare_url() ); ?>">Clear all</a>
		</div>
	</div>

	<?php if ( count( $types ) > 1 ) : ?>
		<p class="aumd-note">You're comparing different kinds of equipment, so some details won't line up.</p>
	<?php endif; ?>

	<?php
	if ( $count > 1 && $winners ) :
		$metrics = aum_compare_metrics();
		?>
		<section class="aumd-glance" aria-labelledby="aumd-glance-h">
			<h2 class="aumd-h" id="aumd-glance-h">At a glance</h2>
			<ul class="aumd-glance-list">
				<?php
				foreach ( $winners as $key => $win ) :
					$value = 'aum_price_gbp' === $key ? aum_money( aum_num( $win[0], $key ) ) : '';
					if ( ! $value ) {
						$suffix = array(
							'aum_range_miles'        => ' miles',
							'aum_max_user_weight_kg' => ' kg',
							'aum_heaviest_part_kg'   => ' kg',
							'aum_warranty_months'    => ' months',
						)[ $key ];
						$value  = aum_fmt_number( aum_num( $win[0], $key ) ) . $suffix;
					}
					$names = implode( ' and ', array_map( 'aum_compare_title', $win ) );
					?>
					<li class="aumd-glance-item">
						<span class="aumd-glance-label"><?php echo esc_html( $metrics[ $key ]['best'] ); ?></span>
						<span class="aumd-glance-value"><?php echo esc_html( $value ); ?></span>
						<span class="aumd-glance-name"><?php echo esc_html( $names ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<div class="aumd-cmp-wrap" data-aum-cmp-table>
		<?php // Roles are spelled out because phones show this table as a grid, which can hide that it is a table from screen readers. ?>
		<table class="aumd-cmp-table" role="table" style="--cols: <?php echo (int) $count; ?>">
			<caption class="aumd-sr"><?php echo esc_html( 1 === $count ? 'One listing' : sprintf( 'Comparison of %d listings', $count ) ); ?></caption>
			<thead role="rowgroup">
				<tr role="row">
					<td class="aumd-cmp-corner" role="cell"></td>
					<?php
					foreach ( $ids as $id ) :
						$image = (string) get_post_meta( $id, 'aum_image_url', true );
						$rest  = array_values( array_diff( $ids, array( $id ) ) );
						$title = aum_compare_title( $id );
						?>
						<th scope="col" class="aumd-cmp-head" role="columnheader">
							<a class="aumd-cmp-photo" href="<?php echo esc_url( get_permalink( $id ) ); ?>" tabindex="-1" aria-hidden="true">
								<?php if ( aum_is_web_url( $image ) ) : ?>
									<img class="aumd-cmp-img" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
								<?php endif; ?>
							</a>
							<a class="aumd-cmp-title" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( $title ); ?></a>
							<a class="aumd-cmp-remove" href="<?php echo esc_url( aum_compare_url( $rest ) ); ?>" data-aum-remove="<?php echo (int) $id; ?>">Remove<span class="aumd-sr"> <?php echo esc_html( $title ); ?> from the comparison</span></a>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody role="rowgroup">
				<?php
				foreach ( $rows as $label => $cells ) :
					$plain = array_map( static fn( array $c ) => $c[1], $cells );
					$same  = $count > 1 && 1 === count( array_unique( $plain ) );
					?>
					<tr role="row"<?php echo $same ? ' data-same' : ''; ?>>
						<th scope="row" role="rowheader"><?php echo esc_html( $label ); ?></th>
						<?php foreach ( $ids as $id ) : ?>
							<td role="cell"><?php echo $cells[ $id ][0]; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot role="rowgroup">
				<tr role="row">
					<th scope="row" role="rowheader"><span class="aumd-sr">Next step</span></th>
					<?php foreach ( $ids as $id ) : ?>
						<td role="cell">
							<a class="aumd-go aumd-go-sm" href="<?php echo esc_url( aum_go_url( $id, 'compare' ) ); ?>" rel="nofollow noopener" target="_blank">View at dealer<span class="aumd-sr"> <?php echo esc_html( aum_compare_title( $id ) ); ?> (opens in a new tab)</span></a>
							<a class="aumd-cmp-more" href="<?php echo esc_url( get_permalink( $id ) ); ?>">Full details<span class="aumd-sr"> for <?php echo esc_html( aum_compare_title( $id ) ); ?></span></a>
						</td>
					<?php endforeach; ?>
				</tr>
			</tfoot>
		</table>
	</div>
	<p class="aumd-small aumd-cmp-foot">Prices and details come from each dealer's website and can change. Always confirm with the dealer before you travel or buy.</p>
	<?php
	echo '</div>';
	return (string) ob_get_clean();
}

/* ================================================================== wiring */

add_action(
	'wp_enqueue_scripts',
	function () {
		$config = array(
			'url' => aum_compare_base_url(),
			'max' => aum_compare_max(),
		);
		wp_add_inline_script( 'aumd-front', 'window.aumCompare = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	},
	6
);

add_shortcode( 'aum_compare', static fn() => aum_render_compare() );
add_shortcode(
	'aum_compare_button',
	static function ( $atts ) {
		$atts = (array) $atts;
		return aum_render_compare_button( aum_current_listing_id( $atts ), sanitize_key( $atts['style'] ?? 'card' ) );
	}
);

/** A comparison is a personal page, not something to show in search results. */
add_filter(
	'wp_robots',
	function ( array $robots ) {
		if ( aum_compare_page_id() && is_page( aum_compare_page_id() ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
);
