<?php
/**
 * The header's "Browse equipment" menu (added in 0.5.0).
 *
 *   [aum_type_menu]                  A "Browse equipment" button that opens a full-width panel:
 *                                    types in two groups, each with an icon and its number of
 *                                    listings, plus a "Find a dealer near you" postcode box.
 *   [aum_type_menu layout="drawer"]  The same types as a plain list, for the phone menu.
 *   [aum_compare_link]               A "Compare" link with the number of picks (filled in by
 *                                    aum-front.js from the visitor's browser).
 *
 * Counts come from the equipment terms, which count published listings only (sold ones have
 * their own status), so new stock and new types show up without editing the header.
 * Types with no listings stay in the menu as "Coming soon".
 */

defined( 'ABSPATH' ) || exit;

/** The menu's two groups and the types in each, in display order. */
function aum_type_menu_groups(): array {
	return array(
		'Out and about'   => array( 'road-scooters', 'pavement-scooters', 'boot-scooters', 'powerchairs', 'wheelchairs' ),
		'Around the home' => array( 'stairlifts', 'riser-recliners', 'profiling-beds', 'hoists', 'bath-lifts' ),
	);
}

/** Shorter names for the panel tiles; the speed goes on the line underneath. */
function aum_type_menu_label( string $slug, string $fallback ): string {
	$labels = array(
		'road-scooters'     => 'Road scooters',
		'pavement-scooters' => 'Pavement scooters',
	);
	return $labels[ $slug ] ?? $fallback;
}

function aum_type_menu_note( string $slug ): string {
	$notes = array(
		'road-scooters'     => '8mph',
		'pavement-scooters' => '4mph',
		'stairlifts'        => 'Straight and curved',
	);
	return $notes[ $slug ] ?? '';
}

/** Line icons, drawn on a 24px grid with round ends. */
function aum_type_menu_icon( string $slug ): string {
	$scooter = '<circle cx="6" cy="18.5" r="2"/><circle cx="18" cy="18.5" r="2"/><path d="M3.5 15.5h13.7M18 18.5 15.4 6.5M13.4 6.5h4M7 15.5v-4M4.5 11.5h5M4.5 11.5V8"/>';
	$paths   = array(
		'road-scooters'     => $scooter,
		'pavement-scooters' => $scooter,
		'boot-scooters'     => '<circle cx="6" cy="18.5" r="2"/><circle cx="18" cy="18.5" r="2"/><path d="M3.5 15.5h5M11.5 15.5h5.7M8.5 13.5v4M11.5 13.5v4M18 18.5 15.4 6.5M13.4 6.5h4M6.5 15.5v-4M4.5 11.5h4M4.5 11.5V8"/>',
		'powerchairs'       => '<path d="M6 4.5V12h8M6 8.5h7M13 8.5V6.8M10 12v3.5M4.5 15.5h12M14 12l3.5 5"/><circle cx="13" cy="6" r=".8"/><circle cx="9.5" cy="18.5" r="2.5"/><circle cx="4.5" cy="19.5" r="1.4"/><circle cx="17.5" cy="19.5" r="1.4"/>',
		'wheelchairs'       => '<path d="M4 4h2v8h8l3 6.5h2"/><circle cx="9.5" cy="15.5" r="5"/><circle cx="9.5" cy="15.5" r="1"/><circle cx="19" cy="20.2" r="1.3"/>',
		'stairlifts'        => '<path d="M3 20h4v-4h4v-4h4V8h4V4M3.5 14 17 4.5M8.5 7.5v3.8M8.5 11.3h4"/>',
		'riser-recliners'   => '<path d="M5 4.5 7.2 14H16l3.5 4M7 10h6.5M9 14v5M14 14v5M7.5 19h8"/>',
		'profiling-beds'    => '<path d="M3 6v13M21 11v8M3 15.5h18M9.5 13.5 5 8M9.5 13.5h11"/>',
		'hoists'            => '<path d="M3.5 20h11M7 20V4.5M7 4.5l11 3M18 7.5v3M15 10.5h6M15.5 10.5l1.2 5.5h2.6l1.2-5.5"/>',
		'bath-lifts'        => '<path d="M3 12h18v3a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5zM8 12V5M8 9h6M11.5 9v3"/>',
	);
	if ( ! isset( $paths[ $slug ] ) ) {
		return '';
	}
	return '<svg class="aumd-tm-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $slug ] . '</svg>';
}

/** Each group's types with their names, links and live counts. */
function aum_type_menu_data(): array {
	$names = array();
	foreach ( aum_equipment_terms() as [ $slug, $name ] ) {
		$names[ $slug ] = $name;
	}
	$out = array();
	foreach ( aum_type_menu_groups() as $group => $slugs ) {
		foreach ( $slugs as $slug ) {
			$term  = get_term_by( 'slug', $slug, 'aum_equipment' );
			$count = ( $term && ! is_wp_error( $term ) ) ? (int) $term->count : 0;
			$url   = $count ? get_term_link( $term ) : '';
			$name  = ( $term && ! is_wp_error( $term ) && $term->name !== $slug ) ? $term->name : ( $names[ $slug ] ?? $slug );
			$out[ $group ][] = array(
				'slug'  => $slug,
				'name'  => $name,
				'label' => aum_type_menu_label( $slug, $name ),
				'note'  => aum_type_menu_note( $slug ),
				'count' => $count,
				'url'   => is_wp_error( $url ) ? '' : (string) $url,
			);
		}
	}
	return $out;
}

/** Live listings on the main site (clearance-only stock has its own section). */
function aum_type_menu_total(): int {
	return count( array_filter( aum_facet_rows(), static fn( $r ) => ! $r['clearance_only'] ) );
}

function aum_type_menu_listings_url(): string {
	$url = get_post_type_archive_link( 'aum_listing' );
	return $url ? (string) $url : home_url( '/listings/' );
}

function aum_type_menu_dealers_url(): string {
	$url = get_post_type_archive_link( 'aum_dealer' );
	return $url ? (string) $url : home_url( '/dealers/' );
}

/** "70 listings", "1 listing". */
function aum_type_menu_count_text( int $count ): string {
	/* translators: %s: number of listings */
	return sprintf( _n( '%s listing', '%s listings', $count, 'aum-directory' ), number_format_i18n( $count ) );
}

function aum_render_type_menu( array $atts = array() ): string {
	$atts = shortcode_atts( array( 'layout' => 'panel' ), $atts, 'aum_type_menu' );
	return 'drawer' === $atts['layout'] ? aum_render_type_menu_drawer() : aum_render_type_menu_panel();
}

function aum_render_type_menu_panel(): string {
	$data  = aum_type_menu_data();
	$total = aum_type_menu_total();
	$uid   = wp_unique_id( 'aumtm-' );
	ob_start();
	?>
	<div class="aumd-tm" data-aum-tm>
		<button type="button" class="aumd-tm-btn" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>">Browse equipment<svg class="aumd-tm-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg></button>
		<div class="aumd-tm-scrim" data-aum-tm-scrim hidden></div>
		<div class="aumd-tm-panel" id="<?php echo esc_attr( $uid ); ?>" hidden>
			<div class="aumd-tm-inner">
				<?php
				$i = 0;
				foreach ( $data as $group => $items ) :
					++$i;
					?>
					<div class="aumd-tm-group">
						<p class="aumd-tm-h" id="<?php echo esc_attr( $uid . '-g' . $i ); ?>"><?php echo esc_html( $group ); ?></p>
						<ul class="aumd-tm-list" aria-labelledby="<?php echo esc_attr( $uid . '-g' . $i ); ?>">
							<?php foreach ( $items as $it ) : ?>
								<?php
								$meta = $it['count'] ? trim( $it['note'] . ( $it['note'] ? ' · ' : '' ) . aum_type_menu_count_text( $it['count'] ) ) : 'Coming soon';
								$tag  = $it['count'] ? 'a' : 'span';
								?>
								<li>
									<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput -- 'a' or 'span' ?> class="aumd-tm-tile<?php echo $it['count'] ? '' : ' is-soon'; ?>"<?php echo $it['count'] ? ' href="' . esc_url( $it['url'] ) . '"' : ''; ?>>
										<span class="aumd-tm-ico"><?php echo aum_type_menu_icon( $it['slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup ?></span>
										<span class="aumd-tm-text"><span class="aumd-tm-name"><?php echo esc_html( $it['label'] ); ?></span><span class="aumd-tm-meta"><?php echo esc_html( $meta ); ?></span></span>
									</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
				<div class="aumd-tm-side">
					<p class="aumd-tm-side-h">Find a dealer near you</p>
					<p class="aumd-tm-side-p">Enter your postcode to see the nearest dealers and what they have in stock.</p>
					<form class="aumd-tm-form" action="<?php echo esc_url( aum_type_menu_dealers_url() ); ?>" method="get" data-aum-tm-near>
						<label class="aumd-tm-label" for="<?php echo esc_attr( $uid ); ?>-pc">Your postcode</label>
						<div class="aumd-tm-row">
							<input class="aumd-tm-input" id="<?php echo esc_attr( $uid ); ?>-pc" type="text" autocomplete="postal-code" placeholder="e.g. DT1 1UZ" spellcheck="false">
							<button type="submit" class="aumd-tm-go">Search</button>
						</div>
					</form>
					<div class="aumd-tm-sep" aria-hidden="true"></div>
					<a class="aumd-tm-side-link" href="<?php echo esc_url( aum_compare_base_url() ); ?>">Compare up to <?php echo (int) aum_compare_max(); ?> side by side<span aria-hidden="true"> →</span></a>
					<a class="aumd-tm-side-link" href="<?php echo esc_url( aum_type_menu_listings_url() ); ?>">Browse all <?php echo esc_html( number_format_i18n( $total ) ); ?> listings<span aria-hidden="true"> →</span></a>
					<?php $clearance = aum_clearance_total(); ?>
					<?php if ( $clearance ) : ?>
						<a class="aumd-tm-side-link aumd-tm-quiet" href="<?php echo esc_url( aum_clearance_base_url() ); ?>">Clearance and end of line (<?php echo esc_html( number_format_i18n( $clearance ) ); ?>)<span aria-hidden="true"> →</span></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

function aum_render_type_menu_drawer(): string {
	$data  = aum_type_menu_data();
	$total = aum_type_menu_total();
	$chev  = '<svg class="aumd-tmd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>';
	ob_start();
	?>
	<div class="aumd-tmd">
		<?php foreach ( $data as $group => $items ) : ?>
			<?php
			$live = array_filter( $items, static fn( $it ) => $it['count'] > 0 );
			$soon = array_filter( $items, static fn( $it ) => 0 === $it['count'] );
			?>
			<p class="aumd-tmd-h"><?php echo esc_html( $group ); ?></p>
			<?php if ( $live ) : ?>
				<ul class="aumd-tmd-list">
					<?php foreach ( $live as $it ) : ?>
						<li><a class="aumd-tmd-link" href="<?php echo esc_url( $it['url'] ); ?>"><span><?php echo esc_html( $it['name'] ); ?></span><span class="aumd-tmd-count"><span class="aumd-sr">, </span><?php echo esc_html( number_format_i18n( $it['count'] ) ); ?><span class="aumd-sr"> listings</span><?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $soon ) : ?>
				<p class="aumd-tmd-soon">Coming soon: <?php echo esc_html( aum_type_menu_join( array_map( static fn( $it ) => strtolower( $it['name'] ), $soon ) ) ); ?></p>
			<?php endif; ?>
		<?php endforeach; ?>
		<div class="aumd-tmd-sep" aria-hidden="true"></div>
		<a class="aumd-tmd-link" href="<?php echo esc_url( aum_type_menu_dealers_url() ); ?>"><span>Find a dealer near you</span><?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
		<a class="aumd-tmd-link" href="<?php echo esc_url( aum_compare_base_url() ); ?>"><span>Compare side by side</span><span class="aumd-cmp-count" data-aum-cmp-count hidden></span></a>
		<?php $clearance = aum_clearance_total(); ?>
		<?php if ( $clearance ) : ?>
			<a class="aumd-tmd-link aumd-tmd-quiet" href="<?php echo esc_url( aum_clearance_base_url() ); ?>"><span>Clearance and end of line</span><span class="aumd-tmd-count"><span class="aumd-sr">, </span><?php echo esc_html( number_format_i18n( $clearance ) ); ?><span class="aumd-sr"> listings</span><?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup ?></span></a>
		<?php endif; ?>
		<a class="aumd-btn aumd-tmd-cta" href="<?php echo esc_url( aum_type_menu_listings_url() ); ?>">Browse all <?php echo esc_html( number_format_i18n( $total ) ); ?> listings</a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/** "a, b and c". */
function aum_type_menu_join( array $words ): string {
	$words = array_values( $words );
	if ( count( $words ) < 2 ) {
		return implode( '', $words );
	}
	$last = array_pop( $words );
	return implode( ', ', $words ) . ' and ' . $last;
}

function aum_render_compare_link( array $atts = array() ): string {
	$atts = shortcode_atts( array( 'text' => 'Compare' ), $atts, 'aum_compare_link' );
	return '<a class="aum-nav-link aumd-cmp-link" href="' . esc_url( aum_compare_base_url() ) . '">' . esc_html( $atts['text'] ) . '<span class="aumd-cmp-count" data-aum-cmp-count hidden></span></a>';
}

add_shortcode( 'aum_type_menu', static fn( $atts ) => aum_render_type_menu( (array) $atts ) );
add_shortcode( 'aum_compare_link', static fn( $atts ) => aum_render_compare_link( (array) $atts ) );
