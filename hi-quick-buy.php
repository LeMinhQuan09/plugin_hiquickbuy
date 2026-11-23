<?php
/**
 * Plugin Name:       HI Quick-Buy
 * Plugin URI:        https://hiseo.vn
 * Update URI:        https://github.com/LeMinhQuan09/plugin_hiquickbuy
 * Description:       Thêm nút Mua nhanh và popup đặt hàng gọn nhẹ cho WooCommerce. Hỗ trợ sản phẩm biến thể, sản phẩm giá Liên hệ, chọn địa chỉ Việt Nam, tính phí giao hàng và chuyển khoản mã QR.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            hiseo
 * Author URI:        https://hiseo.vn
 * Text Domain:       hi-quick-buy
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 8.0
 * WC tested up to:   9.8
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HIQB_VERSION', '1.0.0' );
define( 'HIQB_PLUGIN_FILE', __FILE__ );
define( 'HIQB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIQB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HIQB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'HIQB_GITHUB_REPO' ) ) {
	define( 'HIQB_GITHUB_REPO', 'LeMinhQuan09/plugin_hiquickbuy' );
}

/**
 * Lightweight PSR-4-style autoloader for HIQuickBuy namespace.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'HIQuickBuy\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$sub_dir  = '';

		if ( ! empty( $parts ) ) {
			$sub_dir = strtolower( implode( DIRECTORY_SEPARATOR, $parts ) ) . DIRECTORY_SEPARATOR;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$file_path = HIQB_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $sub_dir . $file_name;

		if ( is_readable( $file_path ) ) {
			require_once $file_path;
		}
	}
);

register_activation_hook( __FILE__, array( \HIQuickBuy\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \HIQuickBuy\Deactivator::class, 'deactivate' ) );

/**
 * Declare WooCommerce High-Performance Order Storage (HPOS) compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				HIQB_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\HIQuickBuy\Plugin::instance()->init();
	},
	20
);

