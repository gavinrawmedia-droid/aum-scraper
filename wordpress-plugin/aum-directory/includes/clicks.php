<?php
/**
 * Outbound clicks. Every "See it on the dealer's website" link goes to
 * /go/<listing id>/, which records the click and sends the visitor on to the
 * dealer's page for that item. The count per dealer is the proof of value when
 * dealers are asked to pay.
 *
 * No personal data is stored: no IP address, no browser string, no cookie.
 */

defined( 'ABSPATH' ) || exit;

function aum_clicks_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'aum_clicks';
}

function aum_install_click_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = aum_clicks_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			clicked_at datetime NOT NULL,
			listing_id bigint(20) unsigned NOT NULL,
			dealer_ext_id varchar(64) NOT NULL DEFAULT '',
			placement varchar(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY dealer_time (dealer_ext_id, clicked_at),
			KEY listing_id (listing_id)
		) $charset;"
	);
}

function aum_clicks_add_rewrite(): void {
	add_rewrite_rule( '^go/([0-9]+)/?$', 'index.php?aum_go=$matches[1]', 'top' );
}
add_action( 'init', 'aum_clicks_add_rewrite' );

add_filter( 'query_vars', function ( array $vars ) {
	$vars[] = 'aum_go';
	return $vars;
} );

/** The link to use for a listing's "go to dealer" button. */
function aum_go_url( int $listing_id, string $placement = 'listing' ): string {
	$url = home_url( '/go/' . $listing_id . '/' );
	return $placement ? add_query_arg( 'from', rawurlencode( $placement ), $url ) : $url;
}

function aum_is_probably_bot(): bool {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|fetch|curl|wget|python|headless|monitor|facebookexternalhit|embedly|whatsapp/', $ua ) ) {
		return true;
	}
	$purpose = strtolower( (string) ( $_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '' ) );
	return str_contains( $purpose, 'prefetch' ) || str_contains( $purpose, 'prerender' );
}

add_action( 'template_redirect', function () {
	$listing_id = absint( get_query_var( 'aum_go' ) );
	if ( ! $listing_id ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );

	$post = get_post( $listing_id );
	$url  = $post && 'aum_listing' === $post->post_type ? (string) get_post_meta( $listing_id, 'aum_dealer_url', true ) : '';
	if ( ! $url || ! aum_is_web_url( $url ) ) {
		wp_safe_redirect( home_url( '/listings/' ), 302 );
		exit;
	}
	if ( 'publish' !== $post->post_status ) {
		// Sold: send them back to the listings rather than to a page the dealer took down.
		wp_safe_redirect( get_post_type_archive_link( 'aum_listing' ) ?: home_url( '/' ), 302 );
		exit;
	}

	if ( ! aum_is_probably_bot() ) {
		global $wpdb;
		$placement = isset( $_GET['from'] ) ? sanitize_key( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			aum_clicks_table(),
			array(
				'clicked_at'    => current_time( 'mysql', true ),
				'listing_id'    => $listing_id,
				'dealer_ext_id' => (string) get_post_meta( $listing_id, 'aum_dealer_ext_id', true ),
				'placement'     => substr( $placement, 0, 32 ),
			),
			array( '%s', '%d', '%s', '%s' )
		);
	}

	$target = add_query_arg(
		array(
			'utm_source'   => 'approvedusedmobility',
			'utm_medium'   => 'referral',
			'utm_campaign' => 'listing',
		),
		$url
	);
	// The dealer's address is stored data, not user input, so an external redirect is safe here.
	wp_redirect( $target, 302, 'Approved Used Mobility' ); // phpcs:ignore WordPress.Security.SafeRedirect
	exit;
} );

/** Keep search engines out of the redirect links. */
add_filter( 'robots_txt', function ( string $output ) {
	if ( str_contains( $output, 'Disallow: /go/' ) ) {
		return $output;
	}
	if ( preg_match( '/^User-agent: \*\s*$/mi', $output ) ) {
		return preg_replace( '/^(User-agent: \*\s*)$/mi', "$1\nDisallow: /go/", $output, 1 );
	}
	return "User-agent: *\nDisallow: /go/\n\n" . $output;
}, 20 );

/** Clicks per dealer over the last N days: [dealer_ext_id => count]. */
function aum_click_counts( int $days = 30 ): array {
	global $wpdb;
	$table = aum_clicks_table();
	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT dealer_ext_id, COUNT(*) AS n FROM $table WHERE clicked_at >= %s GROUP BY dealer_ext_id ORDER BY n DESC", $since ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['dealer_ext_id'] ] = (int) $r['n'];
	}
	return $out;
}
