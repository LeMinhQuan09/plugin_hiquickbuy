<?php
/**
 * Independent shipping calculator that NEVER mutates WC()->cart or user session.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

use WC_Product;
use WC_Shipping_Rate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculates shipping rates using a standalone simulated shipping package.
 */
final class Shipping_Calculator {

	/**
	 * Locations service.
	 */
	private Locations $locations;

	/**
	 * Constructor.
	 */
	public function __construct( Locations $locations ) {
		$this->locations = $locations;
	}

	/**
	 * Register AJAX endpoint for live shipping calculation.
	 */
	public function register(): void {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}

		add_action( 'wp_ajax_hiqb_calculate_shipping', array( $this, 'ajax_calculate_shipping' ) );
		add_action( 'wp_ajax_nopriv_hiqb_calculate_shipping', array( $this, 'ajax_calculate_shipping' ) );
	}

	/**
	 * Calculate available shipping rates for a single product + quantity + destination
	 * without touching WC()->cart.
	 *
	 * @param WC_Product            $product     Product or variation instance.
	 * @param int                   $quantity    Item quantity.
	 * @param array<string, string> $destination Destination keys (country, state, city, ward, address, postcode).
	 * @return array<int, array<string, mixed>>
	 */
	public function calculate( WC_Product $product, int $quantity, array $destination ): array {
		if ( ! Settings::get( 'enable_shipping_calc' ) || ! $product->needs_shipping() ) {
			return array();
		}

		$quantity   = max( 1, $quantity );
		$unit_price = (float) wc_get_price_excluding_tax( $product );
		$line_total = $unit_price * $quantity;

		$country  = ! empty( $destination['country'] ) ? strtoupper( sanitize_text_field( $destination['country'] ) ) : 'VN';
		$state    = isset( $destination['state'] ) ? sanitize_text_field( $destination['state'] ) : '';
		$ward     = isset( $destination['ward'] ) ? sanitize_text_field( $destination['ward'] ) : '';
		$address  = isset( $destination['address'] ) ? sanitize_text_field( $destination['address'] ) : '';
		$postcode = isset( $destination['postcode'] ) ? sanitize_text_field( $destination['postcode'] ) : '';

		// Resolve ward name and postal code from the 2-level VN dataset.
		$city_name = isset( $destination['city'] ) ? sanitize_text_field( $destination['city'] ) : '';
		$ward_name = '';
		if ( 'VN' === $country && '' !== $state && '' !== $ward ) {
			$ward_name = $this->locations->get_ward_name( $state, $ward );
			if ( '' !== $ward_name && '' === $city_name ) {
				$city_name = $ward_name;
			}
			if ( '' === $postcode ) {
				$postcode = $this->locations->get_ward_postcode( $state, $ward );
			}
		}

		// Build a standalone virtual shipping package (does NOT touch WC()->cart).
		$item_key = 'hiqb_' . $product->get_id();
		$package  = array(
			'contents'        => array(
				$item_key => array(
					'key'               => $item_key,
					'product_id'        => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
					'variation_id'      => $product->is_type( 'variation' ) ? $product->get_id() : 0,
					'variation'         => array(),
					'quantity'          => $quantity,
					'data'              => $product,
					'line_total'        => $line_total,
					'line_subtotal'     => $line_total,
					'line_tax'          => 0.0,
					'line_subtotal_tax' => 0.0,
				),
			),
			'contents_cost'   => $line_total,
			'applied_coupons' => array(),
			'user'            => array(
				'ID' => get_current_user_id(),
			),
			'destination'     => array(
				'country'   => $country,
				'state'     => $state,
				'postcode'  => $postcode,
				'city'      => $city_name,
				'address'   => $address,
				'address_1' => $address,
				'address_2' => $ward,
			),
			'cart_subtotal'   => $line_total,
			'hiqb_isolated'   => true,
		);

		$rates = array();

		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			$shipping = WC()->shipping();
			// Dedicated package key so the session rate cache of the real cart (package 0) is never overwritten.
			$result   = $shipping->calculate_shipping_for_package( $package, 'hiqb' );

			if ( is_array( $result ) && ! empty( $result['rates'] ) ) {
				foreach ( $result['rates'] as $rate_id => $rate ) {
					if ( ! $rate instanceof WC_Shipping_Rate ) {
						continue;
					}

					$cost     = (float) $rate->get_cost();
					$tax_sum  = array_sum( array_map( 'floatval', $rate->get_taxes() ) );
					$total    = $cost + $tax_sum;

					$rates[] = array(
						'id'             => (string) $rate_id,
						'method_id'      => (string) $rate->get_method_id(),
						'instance_id'    => (int) $rate->get_instance_id(),
						'label'          => (string) $rate->get_label(),
						'cost'           => $total,
						'cost_formatted' => $total > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $total ) ), ENT_QUOTES, 'UTF-8' ) : __( 'Miễn phí', 'hi-quick-buy' ),
					);
				}
			}
		}

		// Fallback to configured flat fee if no WooCommerce shipping zone matched.
		if ( empty( $rates ) ) {
			$fallback_fee   = (float) Settings::get( 'fallback_shipping_fee', 30000 );
			$fallback_label = (string) Settings::get( 'fallback_shipping_label', __( 'Giao hàng tiêu chuẩn', 'hi-quick-buy' ) );

			$rates[] = array(
				'id'             => 'hiqb_flat_rate:0',
				'method_id'      => 'flat_rate',
				'instance_id'    => 0,
				'label'          => $fallback_label,
				'cost'           => max( 0.0, $fallback_fee ),
				'cost_formatted' => $fallback_fee > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $fallback_fee ) ), ENT_QUOTES, 'UTF-8' ) : __( 'Miễn phí', 'hi-quick-buy' ),
			);
		}

		/**
		 * Filter calculated shipping rates for HI Quick-Buy.
		 *
		 * @param array<int, array<string, mixed>> $rates       Calculated rates.
		 * @param WC_Product                       $product     Target product/variation.
		 * @param int                              $quantity    Quantity.
		 * @param array<string, string>            $destination Destination info.
		 */
		return (array) apply_filters( 'hiqb_calculated_shipping_rates', $rates, $product, $quantity, $destination );
	}

	/**
	 * Find a specific rate by ID from calculated rates, defaulting to the first available rate.
	 *
	 * @param array<int, array<string, mixed>> $rates   Calculated rates.
	 * @param string                           $rate_id Requested shipping rate ID.
	 * @return array<string, mixed>|null
	 */
	public function resolve_selected_rate( array $rates, string $rate_id ): ?array {
		if ( empty( $rates ) ) {
			return null;
		}

		foreach ( $rates as $rate ) {
			if ( isset( $rate['id'] ) && (string) $rate['id'] === $rate_id ) {
				return $rate;
			}
		}

		// A specific rate was requested but is no longer offered.
		if ( '' !== $rate_id ) {
			return null;
		}

		return $rates[0];
	}

	/**
	 * AJAX handler: calculate shipping rates without modifying WC()->cart.
	 */
	public function ajax_calculate_shipping(): void {
		check_ajax_referer( 'hiqb_ajax_nonce', 'nonce' );

		$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? min( 9999, max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) ) : 1;
		$province     = isset( $_POST['province'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['province'] ) ) : '';
		$ward         = isset( $_POST['ward'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ward'] ) ) : '';

		$target_id = $variation_id > 0 ? $variation_id : $product_id;
		$product   = wc_get_product( $target_id );

		if ( ! $product instanceof WC_Product || ! Trigger::is_publicly_available( $product ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sản phẩm không hợp lệ.', 'hi-quick-buy' ) ),
				400
			);
		}

		$rates = $this->calculate(
			$product,
			$quantity,
			array(
				'country' => 'VN',
				'state'   => $province,
				'ward'    => $ward,
			)
		);

		wp_send_json_success(
			array(
				'rates' => $rates,
			)
		);
	}
}

