<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$hiqb_settings = get_option( 'hiqb_settings', array() );

// Remove plugin options unless user explicitly opted to preserve data.
if ( ! is_array( $hiqb_settings ) || ! empty( $hiqb_settings['delete_data_on_uninstall'] ) ) {
	delete_option( 'hiqb_settings' );
	delete_option( 'hiqb_version' );
}

// Clear any cached location transients.
delete_transient( 'hiqb_vn_locations_cache' );
delete_transient( 'hiqb_vn_locations_v2_cache' );
delete_transient( 'hiqb_github_release_cache' );

// Remove short-lived per-IP rate limit transients (hiqb_rl_*).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_hiqb_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hiqb_rl_' ) . '%'
	)
);

