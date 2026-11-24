<?php
/**
 * Plugin settings helper and sanitizer.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages default options, retrieval, and sanitization for HI Quick-Buy.
 */
final class Settings {

	/**
	 * WordPress option key.
	 */
	public const OPTION_NAME = 'hiqb_settings';

	/**
	 * Cached merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Return default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'enabled'                  => 1,
			'show_single_product'      => 1,
			'show_shop_loop'           => 1,
			'allow_no_price'           => 1,
			'no_price_label'           => __( 'Liên hệ', 'hi-quick-buy' ),
			'primary_color'            => '#d70018',
			'button_title'             => __( 'Mua ngay', 'hi-quick-buy' ),
			'button_subtitle'          => __( 'Giao tận nơi hoặc nhận tại cửa hàng', 'hi-quick-buy' ),
			'email_field_mode'         => 'optional', // disabled | optional | required.
			'show_address_field'       => 1,
			'show_note_field'          => 1,
			'show_vn_locations'        => 1,
			'show_payment_methods'     => 1,
			'enable_cod'               => 1,
			'enable_bacs'              => 1,
			'bacs_qr_image_id'         => 0,
			'bacs_transfer_template'   => 'DH {order_id}',
			'enable_shipping_calc'     => 1,
			'fallback_shipping_fee'    => 30000,
			'fallback_shipping_label'  => __( 'Giao hàng tiêu chuẩn', 'hi-quick-buy' ),
			'after_order_action'       => 'popup', // popup | redirect.
			'success_message'          => __( 'Cảm ơn {customer_name}! Đơn hàng #{order_id} với tổng giá trị {total} đã được tiếp nhận. Chúng tôi sẽ liên hệ xác nhận sớm nhất.', 'hi-quick-buy' ),
			'error_message'            => __( 'Có lỗi xảy ra khi đặt hàng. Vui lòng kiểm tra lại thông tin hoặc thử lại sau.', 'hi-quick-buy' ),
			'delete_data_on_uninstall' => 1,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		self::$cache = wp_parse_args( $saved, self::get_defaults() );

		return self::$cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::get_all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Reset in-memory cache.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Sanitize settings payload from Settings API.
	 *
	 * @param mixed $raw_input Raw option array.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $raw_input ): array {
		// Values arrive already unslashed from options.php / update_option(), so no wp_unslash() here.
		$input    = is_array( $raw_input ) ? $raw_input : array();
		$defaults = self::get_defaults();

		$checkbox_keys = array(
			'enabled',
			'show_single_product',
			'show_shop_loop',
			'allow_no_price',
			'show_address_field',
			'show_note_field',
			'show_vn_locations',
			'show_payment_methods',
			'enable_cod',
			'enable_bacs',
			'enable_shipping_calc',
			'delete_data_on_uninstall',
		);

		$sanitized = array();

		foreach ( $checkbox_keys as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		// When payment method selection is disabled, turn off both COD and BACS;
		// when enabled, keep at least COD active if both are unchecked.
		if ( 0 === $sanitized['show_payment_methods'] ) {
			$sanitized['enable_cod']  = 0;
			$sanitized['enable_bacs'] = 0;
		} elseif ( 0 === $sanitized['enable_cod'] && 0 === $sanitized['enable_bacs'] ) {
			$sanitized['enable_cod'] = 1;
		}

		$sanitized['button_title'] = isset( $input['button_title'] )
			? sanitize_text_field( (string) $input['button_title'] )
			: (string) $defaults['button_title'];

		if ( '' === $sanitized['button_title'] ) {
			$sanitized['button_title'] = (string) $defaults['button_title'];
		}

		$sanitized['button_subtitle'] = isset( $input['button_subtitle'] )
			? sanitize_text_field( (string) $input['button_subtitle'] )
			: '';

		$sanitized['no_price_label'] = isset( $input['no_price_label'] )
			? sanitize_text_field( (string) $input['no_price_label'] )
			: (string) $defaults['no_price_label'];

		if ( '' === $sanitized['no_price_label'] ) {
			$sanitized['no_price_label'] = (string) $defaults['no_price_label'];
		}

		$raw_color                 = isset( $input['primary_color'] )
			? sanitize_hex_color( (string) $input['primary_color'] )
			: '';
		$sanitized['primary_color'] = is_string( $raw_color ) && '' !== $raw_color
			? $raw_color
			: (string) $defaults['primary_color'];

		$allowed_email_modes = array( 'disabled', 'optional', 'required' );
		$email_mode          = isset( $input['email_field_mode'] )
			? sanitize_key( (string) $input['email_field_mode'] )
			: (string) $defaults['email_field_mode'];

		$sanitized['email_field_mode'] = in_array( $email_mode, $allowed_email_modes, true )
			? $email_mode
			: 'optional';

		$qr_image_id                   = isset( $input['bacs_qr_image_id'] ) ? absint( $input['bacs_qr_image_id'] ) : 0;
		$sanitized['bacs_qr_image_id'] = ( $qr_image_id > 0 && wp_attachment_is_image( $qr_image_id ) ) ? $qr_image_id : 0;

		$sanitized['bacs_transfer_template'] = isset( $input['bacs_transfer_template'] )
			? sanitize_text_field( (string) $input['bacs_transfer_template'] )
			: (string) $defaults['bacs_transfer_template'];

		if ( '' === $sanitized['bacs_transfer_template'] ) {
			$sanitized['bacs_transfer_template'] = (string) $defaults['bacs_transfer_template'];
		}

		$sanitized['fallback_shipping_fee'] = isset( $input['fallback_shipping_fee'] )
			? max( 0.0, (float) wc_format_decimal( (string) $input['fallback_shipping_fee'] ) )
			: (float) $defaults['fallback_shipping_fee'];

		$sanitized['fallback_shipping_label'] = isset( $input['fallback_shipping_label'] )
			? sanitize_text_field( (string) $input['fallback_shipping_label'] )
			: (string) $defaults['fallback_shipping_label'];

		if ( '' === $sanitized['fallback_shipping_label'] ) {
			$sanitized['fallback_shipping_label'] = (string) $defaults['fallback_shipping_label'];
		}

		$allowed_actions               = array( 'popup', 'redirect' );
		$after_action                  = isset( $input['after_order_action'] )
			? sanitize_key( (string) $input['after_order_action'] )
			: 'popup';
		$sanitized['after_order_action'] = in_array( $after_action, $allowed_actions, true )
			? $after_action
			: 'popup';

		$sanitized['success_message'] = isset( $input['success_message'] )
			? wp_kses_post( (string) $input['success_message'] )
			: (string) $defaults['success_message'];

		$sanitized['error_message'] = isset( $input['error_message'] )
			? sanitize_textarea_field( (string) $input['error_message'] )
			: (string) $defaults['error_message'];

		self::flush_cache();

		return $sanitized;
	}
}

