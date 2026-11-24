<?php
/**
 * Main plugin orchestrator.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

use HIQuickBuy\Admin\Admin_Settings;
use HIQuickBuy\Admin\GitHub_Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates dependency checks, module initialization, and asset enqueueing.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether init() has already run.
	 */
	private bool $initialized = false;

	/**
	 * Return singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Initialize plugin modules.
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! $this->is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			}
			return;
		}

		add_action( 'init', array( Activator::class, 'maybe_upgrade' ), 5 );

		// Admin-only module stays strictly behind is_admin().
		if ( is_admin() ) {
			$admin_settings = new Admin_Settings();
			$admin_settings->register();
		}

		// GitHub Auto-Updater runs only in Admin and WP-Cron update checks.
		if ( is_admin() || wp_doing_cron() ) {
			$updater = new GitHub_Updater();
			$updater->register();
		}

		// Register frontend/AJAX modules on init so translations load at the proper WP 6.7+ lifecycle stage.
		add_action( 'init', array( $this, 'register_modules' ), 20 );
	}

	/**
	 * Load plugin translations on init (WP 6.7+ compliant).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'hi-quick-buy',
			false,
			dirname( HIQB_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register core runtime modules after init.
	 */
	public function register_modules(): void {
		$locations           = new Locations();
		$shipping_calculator = new Shipping_Calculator( $locations );
		$trigger             = new Trigger();
		$popup_form          = new Popup_Form( $locations, $shipping_calculator );
		$order_handler       = new Order_Handler( $locations, $shipping_calculator );

		$locations->register();
		$shipping_calculator->register();
		$trigger->register();
		$popup_form->register();
		$order_handler->register();

		if ( Settings::get( 'enabled' ) ) {
			add_action( 'wp_ajax_hiqb_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
			add_action( 'wp_ajax_nopriv_hiqb_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
		}

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		}
	}

	/**
	 * AJAX handler: hand out a fresh nonce when the one embedded in a cached page has expired.
	 *
	 * The nonce only protects against CSRF; it is not a secret, so issuing it publicly is safe.
	 */
	public function ajax_refresh_nonce(): void {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'hiqb_ajax_nonce' ) ) );
	}

	/**
	 * Check whether WooCommerce is loaded and active.
	 */
	public function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' );
	}

	/**
	 * Render admin notice when WooCommerce is missing.
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong>: %2$s</p></div>',
			esc_html__( 'HI Quick-Buy', 'hi-quick-buy' ),
			esc_html__( 'Plugin yêu cầu cài đặt và kích hoạt WooCommerce để hoạt động.', 'hi-quick-buy' )
		);
	}

	/**
	 * Register frontend assets and enqueue them early only on pages that are known to need them.
	 *
	 * Any other page gets the assets on demand, when a Quick Buy button is actually rendered
	 * (see request_frontend_assets()), so nothing is loaded site-wide.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}

		$this->register_frontend_assets();

		if ( $this->should_enqueue_frontend_assets() ) {
			wp_enqueue_style( 'hiqb-frontend' );
			wp_enqueue_script( 'hiqb-frontend' );
		}
	}

	/**
	 * Enqueue frontend assets because a Quick Buy button is being rendered on the current page.
	 */
	public function request_frontend_assets(): void {
		if ( is_admin() || wp_doing_ajax() || ! Settings::get( 'enabled' ) ) {
			return;
		}

		$this->register_frontend_assets();
		wp_enqueue_style( 'hiqb-frontend' );
		wp_enqueue_script( 'hiqb-frontend' );
	}

	/**
	 * Whether the current request is a page where Quick Buy buttons are expected.
	 */
	private function should_enqueue_frontend_assets(): bool {
		$needed = function_exists( 'is_woocommerce' ) && is_woocommerce();

		if ( ! $needed && is_singular() ) {
			$post   = get_post();
			$needed = $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'hi_quickbuy' );
		}

		/**
		 * Filter whether Quick Buy assets are enqueued in the page head for the current request.
		 *
		 * Return true to force loading (e.g. buttons injected by a page builder or cached fragments).
		 *
		 * @param bool $needed Whether assets are enqueued early.
		 */
		return (bool) apply_filters( 'hiqb_enqueue_frontend_assets', $needed );
	}

	/**
	 * Register Vanilla JS & CSS assets for the frontend (idempotent).
	 */
	private function register_frontend_assets(): void {
		if ( wp_script_is( 'hiqb-frontend', 'registered' ) ) {
			return;
		}

		$css_file = HIQB_PLUGIN_DIR . 'assets/css/hi-quick-buy.css';
		$js_file  = HIQB_PLUGIN_DIR . 'assets/js/hi-quick-buy.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : HIQB_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : HIQB_VERSION;

		wp_register_style(
			'hiqb-frontend',
			HIQB_PLUGIN_URL . 'assets/css/hi-quick-buy.css',
			array(),
			$css_ver
		);

		$primary_color = sanitize_hex_color( (string) Settings::get( 'primary_color', '#d70018' ) );
		if ( is_string( $primary_color ) && '' !== $primary_color ) {
			wp_add_inline_style(
				'hiqb-frontend',
				sprintf( ':root { --hiqb-primary: %s; }', $primary_color )
			);
		}

		wp_register_script(
			'hiqb-frontend',
			HIQB_PLUGIN_URL . 'assets/js/hi-quick-buy.js',
			array(), // Zero jQuery dependency.
			$js_ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'hiqb-frontend',
			'hiqbConfig',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'hiqb_ajax_nonce' ),
				'afterOrderAction' => (string) Settings::get( 'after_order_action', 'popup' ),
				'currency'         => array(
					'symbol'            => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
					'position'          => (string) get_option( 'woocommerce_currency_pos', 'right' ),
					'decimals'          => (int) wc_get_price_decimals(),
					'decimalSeparator'  => (string) wc_get_price_decimal_separator(),
					'thousandSeparator' => (string) wc_get_price_thousand_separator(),
				),
				'i18n'             => array(
					'loading'           => __( 'Đang tải thông tin sản phẩm...', 'hi-quick-buy' ),
					'submitting'        => __( 'Đang xử lý đơn hàng...', 'hi-quick-buy' ),
					'loadError'         => __( 'Không thể tải form đặt hàng. Vui lòng thử lại.', 'hi-quick-buy' ),
					'genericError'      => (string) Settings::get( 'error_message' ),
					'selectVariation'   => __( 'Vui lòng chọn đầy đủ thuộc tính sản phẩm.', 'hi-quick-buy' ),
					'outOfStock'        => __( 'Sản phẩm hiện đã hết hàng.', 'hi-quick-buy' ),
					'invalidName'       => __( 'Vui lòng nhập họ và tên.', 'hi-quick-buy' ),
					'invalidPhone'      => __( 'Số điện thoại Việt Nam không hợp lệ (ví dụ: 0912345678 hoặc +84 912345678).', 'hi-quick-buy' ),
					'invalidEmail'      => __( 'Địa chỉ email không hợp lệ.', 'hi-quick-buy' ),
					'selectProvince'    => __( 'Chọn Tỉnh / Thành phố', 'hi-quick-buy' ),
					'selectWard'        => __( 'Chọn Phường / Xã / Đặc khu', 'hi-quick-buy' ),
					'requiredProvince'  => __( 'Vui lòng chọn Tỉnh / Thành phố.', 'hi-quick-buy' ),
					'requiredWard'      => __( 'Vui lòng chọn Phường / Xã / Đặc khu.', 'hi-quick-buy' ),
					'requiredAddress'   => __( 'Vui lòng nhập địa chỉ nhận hàng.', 'hi-quick-buy' ),
					'freeShipping'      => __( 'Miễn phí', 'hi-quick-buy' ),
					'noPriceLabel'      => (string) Settings::get( 'no_price_label', __( 'Liên hệ', 'hi-quick-buy' ) ),
					'calculatingShip'   => __( 'Đang tính phí vận chuyển...', 'hi-quick-buy' ),
					'orderSuccessTitle' => __( 'Đặt hàng thành công!', 'hi-quick-buy' ),
					'orderNumberLabel'  => __( 'Mã đơn hàng:', 'hi-quick-buy' ),
					'viewOrderDetail'   => __( 'Xem chi tiết đơn hàng', 'hi-quick-buy' ),
					'continueShopping'  => __( 'Tiếp tục mua sắm', 'hi-quick-buy' ),
					'searchPlaceholder' => __( 'Tìm kiếm...', 'hi-quick-buy' ),
					'noResults'         => __( 'Không tìm thấy kết quả', 'hi-quick-buy' ),
					'qrAlt'             => __( 'Mã QR chuyển khoản', 'hi-quick-buy' ),
					'qrCaption'         => __( 'Quét mã QR để chuyển khoản', 'hi-quick-buy' ),
					'bankName'          => __( 'Ngân hàng:', 'hi-quick-buy' ),
					'accountName'       => __( 'Chủ tài khoản:', 'hi-quick-buy' ),
					'accountNumber'     => __( 'Số tài khoản:', 'hi-quick-buy' ),
					'transferAmount'    => __( 'Số tiền:', 'hi-quick-buy' ),
					'transferRef'       => __( 'Nội dung CK:', 'hi-quick-buy' ),
					'bacsTitle'         => __( 'Thông tin chuyển khoản ngân hàng', 'hi-quick-buy' ),
					'copy'              => __( 'Sao chép', 'hi-quick-buy' ),
					'copied'            => __( 'Đã chép', 'hi-quick-buy' ),
				),
			)
		);
	}
}

