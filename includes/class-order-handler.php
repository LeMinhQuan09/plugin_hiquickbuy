<?php
/**
 * Backend AJAX Order Handler using WooCommerce CRUD API.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

use Throwable;
use WC_Geolocation;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Product_Variable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes Quick Buy submissions and creates WooCommerce orders securely.
 */
final class Order_Handler {

	/**
	 * Locations service.
	 */
	private Locations $locations;

	/**
	 * Shipping calculator service.
	 */
	private Shipping_Calculator $shipping_calculator;

	/**
	 * Constructor.
	 */
	public function __construct( Locations $locations, Shipping_Calculator $shipping_calculator ) {
		$this->locations           = $locations;
		$this->shipping_calculator = $shipping_calculator;
	}

	/**
	 * Register AJAX order submission endpoints.
	 */
	public function register(): void {
		add_action( 'wp_ajax_hiqb_create_order', array( $this, 'ajax_create_order' ) );
		add_action( 'wp_ajax_nopriv_hiqb_create_order', array( $this, 'ajax_create_order' ) );
	}

	/**
	 * Handle AJAX order creation request.
	 */
	public function ajax_create_order(): void {
		check_ajax_referer( 'hiqb_ajax_nonce', 'nonce' );

		$settings = Settings::get_all();
		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Chức năng đặt mua nhanh hiện đang tạm tắt.', 'hi-quick-buy' ) ),
				403
			);
		}

		if ( $this->is_rate_limited() ) {
			wp_send_json_error(
				array( 'message' => __( 'Bạn đã đặt quá nhiều đơn trong thời gian ngắn. Vui lòng thử lại sau ít phút.', 'hi-quick-buy' ) ),
				429
			);
		}

		$spam_error = Spam_Guard::validate(
			isset( $_POST[ Spam_Guard::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ Spam_Guard::HONEYPOT_FIELD ] ) ) : '',
			isset( $_POST[ Spam_Guard::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ Spam_Guard::TOKEN_FIELD ] ) ) : ''
		);
		if ( null !== $spam_error ) {
			wp_send_json_error(
				array(
					'code'    => 'spam_check_failed',
					'message' => $spam_error,
				),
				400
			);
		}

		// Explicitly read, unslash, and sanitize every field (never trust raw $_POST).
		$product_id      = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$variation_id    = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
		$quantity        = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;
		$salutation_raw  = isset( $_POST['salutation'] ) ? sanitize_key( wp_unslash( (string) $_POST['salutation'] ) ) : 'anh';
		$salutation      = in_array( $salutation_raw, array( 'anh', 'chi' ), true ) ? $salutation_raw : 'anh';
		$full_name       = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['full_name'] ) ) : '';
		$phone_raw       = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '';
		$phone           = $this->normalize_vn_phone( $phone_raw );
		$email           = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		$province_code   = isset( $_POST['province'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['province'] ) ) : '';
		$ward_code       = isset( $_POST['ward'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ward'] ) ) : '';
		$address_detail  = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['address'] ) ) : '';
		$order_note      = isset( $_POST['order_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['order_note'] ) ) : '';
		$payment_method  = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( (string) $_POST['payment_method'] ) ) : 'cod';
		$shipping_choice = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_method'] ) ) : '';

		// Cap free-text lengths so a public request cannot store oversized payloads.
		$full_name      = $this->truncate_text( $full_name, 100 );
		$address_detail = $this->truncate_text( $address_detail, 255 );
		$order_note     = $this->truncate_text( $order_note, 1000 );
		$province_code  = $this->truncate_text( $province_code, 20 );
		$ward_code      = $this->truncate_text( $ward_code, 20 );

		// Validate customer inputs.
		if ( '' === $full_name ) {
			wp_send_json_error(
				array( 'message' => __( 'Vui lòng nhập họ và tên.', 'hi-quick-buy' ) ),
				422
			);
		}

		if ( '' === $phone || 1 !== preg_match( '/^(0\d{9}|02\d{9})$/', $phone ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Số điện thoại Việt Nam không hợp lệ (hỗ trợ dạng 0912345678 hoặc +84 912345678).', 'hi-quick-buy' ) ),
				422
			);
		}

		$email_mode = (string) $settings['email_field_mode'];
		if ( 'required' === $email_mode && ( '' === $email || ! is_email( $email ) ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Vui lòng nhập địa chỉ email hợp lệ.', 'hi-quick-buy' ) ),
				422
			);
		}
		if ( 'optional' === $email_mode && '' !== $email && ! is_email( $email ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Địa chỉ email không đúng định dạng.', 'hi-quick-buy' ) ),
				422
			);
		}

		// Resolve location codes against the bundled dataset: unknown codes are never stored.
		$province_name = '' !== $province_code ? $this->locations->get_province_name( $province_code ) : '';
		$ward_name     = ( '' !== $province_name && '' !== $ward_code )
			? $this->locations->get_ward_name( $province_code, $ward_code )
			: '';

		if ( ! empty( $settings['show_vn_locations'] ) && ( '' === $province_name || '' === $ward_name ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Vui lòng chọn Tỉnh / Thành phố và Phường / Xã / Đặc khu.', 'hi-quick-buy' ) ),
				422
			);
		}

		if ( '' === $province_name ) {
			$province_code = '';
		}
		if ( '' === $ward_name ) {
			$ward_code = '';
		}

		if ( ! empty( $settings['show_address_field'] ) && '' === $address_detail ) {
			wp_send_json_error(
				array( 'message' => __( 'Vui lòng nhập địa chỉ nhận hàng.', 'hi-quick-buy' ) ),
				422
			);
		}

		// Validate product & variation.
		$parent_product = wc_get_product( $product_id );
		$allow_contact  = $parent_product instanceof WC_Product
			&& '' === $parent_product->get_price()
			&& ! empty( $settings['allow_no_price'] )
			&& ! $parent_product->is_type( array( 'external', 'grouped' ) );

		if (
			! $parent_product instanceof WC_Product
			|| ! Trigger::is_publicly_available( $parent_product )
			|| ( ! $parent_product->is_purchasable() && ! $allow_contact )
		) {
			wp_send_json_error(
				array( 'message' => __( 'Sản phẩm không hợp lệ hoặc không thể mua.', 'hi-quick-buy' ) ),
				404
			);
		}

		$order_product   = $parent_product;
		$variation_args  = array();

		if ( $parent_product->is_type( 'variable' ) && $parent_product instanceof WC_Product_Variable ) {
			if ( $variation_id <= 0 ) {
				wp_send_json_error(
					array( 'message' => __( 'Vui lòng chọn đầy đủ biến thể sản phẩm.', 'hi-quick-buy' ) ),
					422
				);
			}

			$variation_obj = wc_get_product( $variation_id );
			if ( ! $variation_obj instanceof WC_Product || ! Trigger::is_variation_orderable( $variation_obj, $parent_product ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Biến thể sản phẩm không hợp lệ.', 'hi-quick-buy' ) ),
					422
				);
			}

			$order_product  = $variation_obj;
			$variation_args = array(
				'variation' => $variation_obj->get_variation_attributes(),
			);
		}

		if ( ! $order_product->is_in_stock() && ! $order_product->backorders_allowed() ) {
			wp_send_json_error(
				array( 'message' => __( 'Sản phẩm hiện đã hết hàng.', 'hi-quick-buy' ) ),
				422
			);
		}

		if ( $order_product->is_sold_individually() && $quantity > 1 ) {
			wp_send_json_error(
				array( 'message' => __( 'Sản phẩm này chỉ được mua 1 sản phẩm cho mỗi đơn hàng.', 'hi-quick-buy' ) ),
				422
			);
		}

		/**
		 * Filter the maximum quantity accepted for a single Quick Buy order.
		 *
		 * @param int        $max_quantity  Maximum quantity per order.
		 * @param WC_Product $order_product Product or variation being ordered.
		 */
		$max_quantity = max( 1, (int) apply_filters( 'hiqb_max_order_quantity', 9999, $order_product ) );
		if ( $quantity > $max_quantity ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum quantity per order */
						__( 'Số lượng tối đa cho mỗi đơn hàng là %d.', 'hi-quick-buy' ),
						$max_quantity
					),
				),
				422
			);
		}

		if ( $order_product->managing_stock() && ! $order_product->backorders_allowed() ) {
			$stock_qty = $order_product->get_stock_quantity();
			if ( null !== $stock_qty && $quantity > (int) $stock_qty ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: available stock quantity */
							__( 'Số lượng tồn kho không đủ (chỉ còn %d sản phẩm).', 'hi-quick-buy' ),
							(int) $stock_qty
						),
					),
					422
				);
			}
		}

		// Validate allowed payment method.
		$allowed_gateways = array();
		if ( ! empty( $settings['enable_cod'] ) ) {
			$allowed_gateways[] = 'cod';
		}
		if ( ! empty( $settings['enable_bacs'] ) ) {
			$allowed_gateways[] = 'bacs';
		}
		if ( empty( $allowed_gateways ) ) {
			$allowed_gateways[] = 'cod';
		}
		if ( ! in_array( $payment_method, $allowed_gateways, true ) ) {
			$payment_method = $allowed_gateways[0];
		}

		// Resolve postal code from the validated Vietnamese 2-level address.
		$postcode      = ( '' !== $province_code && '' !== $ward_code )
			? $this->locations->get_ward_postcode( $province_code, $ward_code )
			: '';

		// Split Vietnamese full name into first/last name cleanly.
		$name_parts = preg_split( '/\s+/', trim( $full_name ) );
		$first_name = is_array( $name_parts ) && ! empty( $name_parts ) ? (string) array_pop( $name_parts ) : $full_name;
		$last_name  = is_array( $name_parts ) && ! empty( $name_parts ) ? implode( ' ', $name_parts ) : '';

		$salutation_label = 'chi' === $salutation ? __( 'Chị', 'hi-quick-buy' ) : __( 'Anh', 'hi-quick-buy' );

		$billing_address = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
			'phone'      => $phone,
			'address_1'  => $address_detail,
			'address_2'  => $ward_name,
			'city'       => '' !== $ward_name ? $ward_name : $province_name,
			'state'      => $province_code,
			'postcode'   => $postcode,
			'country'    => 'VN',
		);

		$shipping_address = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'address_1'  => $address_detail,
			'address_2'  => $ward_name,
			'city'       => '' !== $ward_name ? $ward_name : $province_name,
			'state'      => $province_code,
			'postcode'   => $postcode,
			'country'    => 'VN',
		);

		$is_contact_price = ( '' === $order_product->get_price() );
		$no_price_label   = (string) ( $settings['no_price_label'] ?? __( 'Liên hệ', 'hi-quick-buy' ) );

		// Independently recalculate shipping on the server (never trust client price).
		// Skip flat shipping calculation for no-price (contact/quote) products.
		$selected_rate = null;
		if ( ! $is_contact_price ) {
			$shipping_rates = $this->shipping_calculator->calculate(
				$order_product,
				$quantity,
				array(
					'country'  => 'VN',
					'state'    => $province_code,
					'ward'     => $ward_code,
					'postcode' => $postcode,
					'address'  => $address_detail,
				)
			);
			$selected_rate = $this->shipping_calculator->resolve_selected_rate( $shipping_rates, $shipping_choice );

			// The rate the customer saw no longer exists (zone/method changed): never silently swap it.
			if ( null === $selected_rate && ! empty( $shipping_rates ) ) {
				wp_send_json_error(
					array(
						'code'    => 'shipping_changed',
						'message' => __( 'Phương thức giao hàng đã thay đổi. Vui lòng kiểm tra lại phí vận chuyển rồi đặt hàng lại.', 'hi-quick-buy' ),
					),
					409
				);
			}
		}

		try {
			// 1. Create WooCommerce order via CRUD API.
			$order = wc_create_order(
				array(
					'customer_id' => get_current_user_id(),
					'created_via' => 'hi_quick_buy',
				)
			);

			if ( is_wp_error( $order ) ) {
				wp_send_json_error(
					array( 'message' => (string) $settings['error_message'] ),
					500
				);
			}

			$this->record_order_for_rate_limit();

			// 2. Add product / variation & quantity.
			if ( $is_contact_price ) {
				$variation_args['subtotal'] = 0;
				$variation_args['total']    = 0;
			}
			$order->add_product( $order_product, $quantity, $variation_args );

			// 3. Set billing & shipping addresses.
			$order->set_address( $billing_address, 'billing' );
			$order->set_address( $shipping_address, 'shipping' );

			if ( '' !== $order_note ) {
				$order->set_customer_note( $order_note );
			}

			// Store HI Quick-Buy structured meta (HPOS-compatible CRUD meta).
			$order->update_meta_data( '_hiqb_quick_buy', 'yes' );
			$order->update_meta_data( '_hiqb_contact_price', $is_contact_price ? 'yes' : 'no' );
			$order->update_meta_data( '_hiqb_salutation', $salutation_label );
			$order->update_meta_data( '_hiqb_province_code', $province_code );
			$order->update_meta_data( '_hiqb_province_name', $province_name );
			$order->update_meta_data( '_hiqb_ward_code', $ward_code );
			$order->update_meta_data( '_hiqb_ward_name', $ward_name );
			$order->update_meta_data( '_hiqb_postcode', $postcode );

			// 4. Set payment gateway & shipping item.
			$payment_titles = array(
				'cod'  => __( 'Thanh toán khi nhận hàng (COD)', 'hi-quick-buy' ),
				'bacs' => __( 'Chuyển khoản ngân hàng (BACS)', 'hi-quick-buy' ),
			);

			$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			if ( isset( $gateways[ $payment_method ] ) ) {
				$order->set_payment_method( $gateways[ $payment_method ] );
			} else {
				$order->set_payment_method( $payment_method );
				$order->set_payment_method_title( $payment_titles[ $payment_method ] ?? strtoupper( $payment_method ) );
			}

			if ( is_array( $selected_rate ) ) {
				$shipping_item = new WC_Order_Item_Shipping();
				$shipping_item->set_method_title( (string) $selected_rate['label'] );
				$shipping_item->set_method_id( (string) $selected_rate['method_id'] );
				$shipping_item->set_instance_id( (int) $selected_rate['instance_id'] );
				$shipping_item->set_total( (float) $selected_rate['cost'] );
				$order->add_item( $shipping_item );
			}

			// 5. Calculate totals & persist order.
			$order->calculate_totals();
			$order->save();

			// 6. Update status (default: processing for COD, on-hold for BACS; filterable).
			$target_status = 'bacs' === $payment_method ? 'on-hold' : 'processing';
			$target_status = (string) apply_filters( 'hiqb_new_order_status', $target_status, $order, $payment_method );

			$order_note_status = $is_contact_price
				? sprintf(
					/* translators: 1: customer salutation and name, 2: contact price label */
					__( 'Đơn yêu cầu báo giá (%2$s) tạo qua HI Quick-Buy (%1$s).', 'hi-quick-buy' ),
					$salutation_label . ' ' . $full_name,
					$no_price_label
				)
				: sprintf(
					/* translators: %s: customer salutation and name */
					__( 'Đơn hàng nhanh tạo qua HI Quick-Buy (%s).', 'hi-quick-buy' ),
					$salutation_label . ' ' . $full_name
				);

			$order->update_status( $target_status, $order_note_status );

			// 7. Reduce stock levels automatically.
			wc_reduce_stock_levels( $order->get_id() );

			// 8. Trigger standard WooCommerce checkout hook for emails, webhooks, CRM, etc.
			$posted_data = array(
				'billing_first_name' => $first_name,
				'billing_last_name'  => $last_name,
				'billing_phone'      => $phone,
				'billing_email'      => $email,
				'billing_address_1'  => $address_detail,
				'billing_address_2'  => $ward_name,
				'billing_city'       => $ward_name,
				'billing_state'      => $province_code,
				'billing_postcode'   => $postcode,
				'billing_country'    => 'VN',
				'payment_method'     => $payment_method,
				'order_comments'     => $order_note,
			);

			do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted_data, $order );

			$total_formatted = $is_contact_price
				? $no_price_label
				: html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total() ) ), ENT_QUOTES, 'UTF-8' );
			$thankyou_link   = $order->get_checkout_order_received_url();

			$message = strtr(
				(string) $settings['success_message'],
				array(
					'{order_id}'      => esc_html( (string) $order->get_order_number() ),
					'{customer_name}' => esc_html( $salutation_label . ' ' . $full_name ),
					'{total}'         => esc_html( $total_formatted ),
				)
			);

			$bacs_info = ( 'bacs' === $payment_method && ! $is_contact_price )
				? $this->build_bacs_info( $order )
				: null;

			wp_send_json_success(
				array(
					'order_id'        => $order->get_id(),
					'order_number'    => (string) $order->get_order_number(),
					'payment_method'  => $payment_method,
					'total_formatted' => $total_formatted,
					'message'         => wp_kses_post( $message ),
					'thankyou_link'   => esc_url_raw( $thankyou_link ),
					'redirect'        => 'redirect' === $settings['after_order_action'],
					'bacs_info'       => $bacs_info,
				)
			);
		} catch ( Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'HI Quick-Buy order error: ' . $e->getMessage(),
					array( 'source' => 'hi-quick-buy' )
				);
			}

			wp_send_json_error(
				array( 'message' => (string) $settings['error_message'] ),
				500
			);
		}
	}

	/**
	 * Transient key + limits used to throttle order creation per client IP.
	 *
	 * @return array{key: string, limit: int, window: int}|null Null when throttling is disabled.
	 */
	private function get_rate_limit_config(): ?array {
		/**
		 * Filter the maximum number of Quick Buy orders one IP may create per window (0 disables the limit).
		 *
		 * @param int $limit Orders allowed per window.
		 */
		$limit = (int) apply_filters( 'hiqb_order_rate_limit', 5 );

		/**
		 * Filter the rate limit window length in seconds.
		 *
		 * @param int $window Window length in seconds.
		 */
		$window = (int) apply_filters( 'hiqb_order_rate_limit_window', 10 * MINUTE_IN_SECONDS );

		if ( $limit <= 0 || $window <= 0 || current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}

		$ip = class_exists( WC_Geolocation::class ) ? (string) WC_Geolocation::get_ip_address() : '';
		if ( '' === $ip ) {
			return null;
		}

		return array(
			'key'    => 'hiqb_rl_' . md5( $ip ),
			'limit'  => $limit,
			'window' => $window,
		);
	}

	/**
	 * Whether the current client already created too many orders recently.
	 */
	private function is_rate_limited(): bool {
		$config = $this->get_rate_limit_config();

		return null !== $config && (int) get_transient( $config['key'] ) >= $config['limit'];
	}

	/**
	 * Count a successfully created order towards the client's rate limit.
	 */
	private function record_order_for_rate_limit(): void {
		$config = $this->get_rate_limit_config();
		if ( null === $config ) {
			return;
		}

		set_transient( $config['key'], (int) get_transient( $config['key'] ) + 1, $config['window'] );
	}

	/**
	 * Build BACS bank transfer details from WooCommerce BACS settings and admin-uploaded QR image.
	 *
	 * @param \WC_Order $order Created order.
	 * @return array<string, mixed>|null
	 */
	private function build_bacs_info( \WC_Order $order ): ?array {
		$raw_accounts  = get_option( 'woocommerce_bacs_accounts', array() );
		$bacs_settings = get_option( 'woocommerce_bacs_settings', array() );
		$qr_image_id   = absint( Settings::get( 'bacs_qr_image_id', 0 ) );
		$qr_url        = $qr_image_id > 0 ? (string) wp_get_attachment_image_url( $qr_image_id, 'medium' ) : '';

		/**
		 * Filter the uploaded QR image URL displayed for BACS orders.
		 *
		 * @param string    $qr_url Uploaded QR image URL.
		 * @param \WC_Order $order  Order object.
		 */
		$qr_url = (string) apply_filters( 'hiqb_bacs_qr_url', $qr_url, $order );

		$amount        = (int) round( (float) $order->get_total() );
		$order_number  = (string) $order->get_order_number();
		$customer_name = remove_accents( trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() ) );
		$template      = (string) Settings::get( 'bacs_transfer_template', 'DH {order_id}' );
		$transfer_ref  = strtr(
			$template,
			array(
				'{order_id}'      => $order_number,
				'{phone}'         => (string) $order->get_billing_phone(),
				'{customer_name}' => $customer_name,
			)
		);
		$transfer_ref  = trim( (string) preg_replace( '/\s+/', ' ', $transfer_ref ) );
		$transfer_ref  = (string) apply_filters( 'hiqb_bacs_transfer_ref', $transfer_ref, $order );
		$instructions = is_array( $bacs_settings ) && ! empty( $bacs_settings['instructions'] )
			? sanitize_text_field( (string) $bacs_settings['instructions'] )
			: '';

		$accounts = array();
		if ( is_array( $raw_accounts ) ) {
			foreach ( $raw_accounts as $acc ) {
				if ( ! is_array( $acc ) ) {
					continue;
				}
				$account_name   = isset( $acc['account_name'] ) ? sanitize_text_field( (string) $acc['account_name'] ) : '';
				$account_number = isset( $acc['account_number'] ) ? sanitize_text_field( (string) $acc['account_number'] ) : '';
				$bank_name      = isset( $acc['bank_name'] ) ? sanitize_text_field( (string) $acc['bank_name'] ) : '';

				if ( '' === $account_number && '' === $bank_name ) {
					continue;
				}

				$accounts[] = array(
					'bank_name'      => $bank_name,
					'account_name'   => $account_name,
					'account_number' => $account_number,
				);
			}
		}

		$accounts = (array) apply_filters( 'hiqb_bacs_accounts', $accounts, $order );

		if ( empty( $accounts ) && '' === $qr_url ) {
			return null;
		}

		return array(
			'instructions' => $instructions,
			'transfer_ref' => $transfer_ref,
			'amount'       => $amount,
			'qr_url'       => '' !== $qr_url ? esc_url_raw( $qr_url ) : '',
			'accounts'     => $accounts,
		);
	}

	/**
	 * Safely truncate UTF-8 text without requiring the mbstring extension.
	 */
	private function truncate_text( string $text, int $max_length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max_length, 'UTF-8' );
		}

		return substr( $text, 0, $max_length );
	}

	/**
	 * Normalize Vietnamese phone numbers (+84, 84, spaces, dots, dashes) to local 0-prefixed format.
	 */
	private function normalize_vn_phone( string $raw ): string {
		$cleaned = preg_replace( '/[\s.\-()]+/', '', trim( $raw ) );
		if ( ! is_string( $cleaned ) || '' === $cleaned ) {
			return '';
		}

		if ( str_starts_with( $cleaned, '+84' ) ) {
			$cleaned = '0' . substr( $cleaned, 3 );
		} elseif ( str_starts_with( $cleaned, '84' ) && strlen( $cleaned ) >= 11 ) {
			$cleaned = '0' . substr( $cleaned, 2 );
		}

		return $cleaned;
	}
}

