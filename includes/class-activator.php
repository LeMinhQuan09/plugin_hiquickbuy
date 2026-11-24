<?php
/**
 * Plugin activation handler.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation tasks.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::upgrade( (string) get_option( 'hiqb_version', '' ) );
	}

	/**
	 * Run the upgrade routine once when the stored version differs from the code version.
	 *
	 * Needed because updates (GitHub updater, manual ZIP upload) never fire the activation hook.
	 */
	public static function maybe_upgrade(): void {
		if ( HIQB_VERSION !== (string) get_option( 'hiqb_version', '' ) ) {
			self::upgrade( (string) get_option( 'hiqb_version', '' ) );
		}
	}

	/**
	 * Bring stored data in line with the current version.
	 *
	 * @param string $from_version Previously installed version ('' on first install).
	 */
	private static function upgrade( string $from_version ): void {
		if ( false === get_option( Settings::OPTION_NAME, false ) ) {
			add_option( Settings::OPTION_NAME, Settings::get_defaults() );
		}

		// Cached data may have a new format/content in the new version.
		delete_transient( 'hiqb_vn_locations_cache' );
		delete_transient( Locations::CACHE_KEY );
		delete_transient( 'hiqb_github_release_cache' );
		Settings::flush_cache();

		update_option( 'hiqb_version', HIQB_VERSION );

		/**
		 * Fires after HI Quick-Buy data has been upgraded.
		 *
		 * @param string $from_version Previously installed version ('' on first install).
		 * @param string $to_version   Current version.
		 */
		do_action( 'hiqb_upgraded', $from_version, HIQB_VERSION );
	}
}

