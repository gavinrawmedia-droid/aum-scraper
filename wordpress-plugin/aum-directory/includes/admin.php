<?php
/**
 * Admin: useful columns on the listings screen and a status page under
 * Tools > AUM Directory showing the last import and clicks per dealer.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'manage_aum_listing_posts_columns', function ( array $cols ) {
	$new = array();
	foreach ( $cols as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['aum_price']     = 'Price';
			$new['aum_dealer']    = 'Dealer';
			$new['aum_last_seen'] = 'Last seen';
		}
	}
	unset( $new['date'] );
	return $new;
} );

add_action( 'manage_aum_listing_posts_custom_column', function ( string $col, int $id ) {
	switch ( $col ) {
		case 'aum_price':
			$p = get_post_meta( $id, 'aum_price_gbp', true );
			echo $p ? esc_html( aum_money( $p ) ) : '&mdash;';
			break;
		case 'aum_dealer':
			$d = aum_dealer_post_for( (string) get_post_meta( $id, 'aum_dealer_ext_id', true ) );
			echo $d ? esc_html( get_the_title( $d ) ) : '&mdash;';
			break;
		case 'aum_last_seen':
			$s = get_post_meta( $id, 'aum_last_seen', true );
			echo $s ? esc_html( wp_date( 'j M Y', strtotime( $s ) ) ) : '&mdash;';
			break;
	}
}, 10, 2 );

/** Read-only box on the listing edit screen showing what the scraper recorded. */
add_action( 'add_meta_boxes_aum_listing', function () {
	add_meta_box( 'aum-scraped', 'From the dealer\'s website', function ( WP_Post $post ) {
		echo '<p>These figures are updated by the daily import. Edits here would be overwritten.</p><table class="widefat striped"><tbody>';
		foreach ( aum_listing_fields() as $key => [ $type, $label ] ) {
			$v = get_post_meta( $post->ID, $key, true );
			if ( is_array( $v ) ) {
				$v = implode( "\n", $v );
			}
			if ( '' === $v || null === $v ) {
				continue;
			}
			$shown = ( 'aum_dealer_url' === $key ) ? '<a href="' . esc_url( $v ) . '" target="_blank" rel="noopener">' . esc_html( $v ) . '</a>' : nl2br( esc_html( (string) $v ) );
			echo '<tr><th scope="row" style="width:30%">' . esc_html( $label ) . '</th><td>' . $shown . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
	}, 'aum_listing', 'normal', 'high' );
} );

add_action( 'admin_menu', function () {
	add_management_page( 'AUM Directory', 'AUM Directory', 'manage_options', 'aum-directory', 'aum_render_status_page' );
} );

function aum_render_status_page(): void {
	$sync    = get_option( 'aum_last_sync' );
	$counts  = wp_count_posts( 'aum_listing' );
	$clicks  = aum_click_counts( 30 );
	$dealers = get_posts( array( 'post_type' => 'aum_dealer', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	?>
	<div class="wrap">
		<h1>AUM Directory</h1>
		<h2>Stock</h2>
		<p><strong><?php echo (int) ( $counts->publish ?? 0 ); ?></strong> live listings,
			<?php echo (int) ( $counts->aum_sold ?? 0 ); ?> sold or removed, from <?php echo count( $dealers ); ?> dealers.</p>
		<?php if ( $sync ) : ?>
			<p>Last import: <?php echo esc_html( wp_date( 'j F Y, H:i', strtotime( $sync['received'] ) ) ); ?>.
				Removed <?php echo (int) ( $sync['result']['removed'] ?? 0 ); ?> listings that dealers no longer show.</p>
			<?php if ( ! empty( $sync['result']['skipped_dealers'] ) ) : ?>
				<div class="notice notice-warning inline"><p>Not removed, because so much stock vanished at once that the scraper may have failed:
					<?php echo esc_html( implode( '; ', $sync['result']['skipped_dealers'] ) ); ?>. Check the GitHub run.</p></div>
			<?php endif; ?>
		<?php else : ?>
			<p>No import yet.</p>
		<?php endif; ?>

		<h2>Clicks to dealers, last 30 days</h2>
		<table class="widefat striped" style="max-width:640px">
			<thead><tr><th>Dealer</th><th>Live listings</th><th>Clicks</th></tr></thead>
			<tbody>
			<?php foreach ( $dealers as $d ) : $ext = (string) get_post_meta( $d->ID, 'aum_ext_id', true ); ?>
				<tr><td><?php echo esc_html( $d->post_title ); ?></td>
					<td><?php echo (int) get_post_meta( $d->ID, 'aum_listing_count', true ); ?></td>
					<td><?php echo (int) ( $clicks[ $ext ] ?? 0 ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Clicks from search engines and link previews aren't counted. No personal data is stored.</p>

		<h2>Importer</h2>
		<p>The scraper sends stock to <code><?php echo esc_html( rest_url( 'aum-directory/v1/' ) ); ?></code>, signed in as a user with the
			<em>Stock importer</em> role using an application password. That role can import stock and nothing else.</p>
	</div>
	<?php
}
