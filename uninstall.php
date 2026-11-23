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

