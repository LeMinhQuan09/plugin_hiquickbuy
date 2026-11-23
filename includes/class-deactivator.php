<?php
/**
 * Plugin deactivation handler.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles deactivation tasks.
 */
final class Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		delete_transient( 'hiqb_vn_locations_cache' );
		delete_transient( Locations::CACHE_KEY );
		delete_transient( 'hiqb_github_release_cache' );
	}
}

