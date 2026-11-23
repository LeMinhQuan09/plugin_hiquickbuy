<?php
/**
 * Trigger layer: displays Quick Buy buttons on Single Product, Shop Loop, and Shortcode.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Quick Buy trigger buttons across WooCommerce templates and shortcodes.
 */
final class Trigger {

	/**
	 * Track product IDs already rendered on single product page to prevent duplicates
	 * across Classic Themes and WooCommerce Block Themes.
	 *
	 * @var array<int, bool>
	 */
	private array $rendered_single = array();

	/**
	 * Register WordPress & WooCommerce hooks.
	 */
	public function register(): void {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}

		if ( Settings::get( 'show_single_product' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_product_button' ), 35 );
			// Fallback for WooCommerce Block Themes (e.g. Twenty Twenty-Five) where single_product_summary does not fire.
			add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render_single_product_button' ), 20 );
			add_filter( 'render_block_woocommerce/add-to-cart-form', array( $this, 'append_button_to_block' ), 20 );
			add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( $this, 'append_button_to_block' ), 20 );
			add_filter( 'render_block_woocommerce/product-price', array( $this, 'append_button_to_price_block' ), 20 );
		}

		if ( Settings::get( 'show_shop_loop' ) ) {
			add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_shop_loop_button' ), 15 );
		}

		add_shortcode( 'hi_quickbuy', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render button on Single Product page (compatible with both Classic and Block themes).
	 */
	public function render_single_product_button(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();
		if ( ! empty( $this->rendered_single[ $product_id ] ) ) {
			return;
		}
		$this->rendered_single[ $product_id ] = true;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside build_button_html().
		echo $this->build_button_html(
			$product,
			array(
				'context' => 'single',
			)
		);
	}

	/**
	 * Append Quick Buy button after WooCommerce Block Theme add-to-cart blocks.
	 *
	 * @param string $block_content Rendered block HTML.
	 */
	public function append_button_to_block( string $block_content ): string {
		if ( ! is_product() ) {
			return $block_content;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product instanceof WC_Product ) {
			return $block_content;
		}

		$product_id = $product->get_id();
		if ( ! empty( $this->rendered_single[ $product_id ] ) ) {
			return $block_content;
		}
		$this->rendered_single[ $product_id ] = true;

		return $block_content . $this->build_button_html(
			$product,
			array(
				'context' => 'single',
			)
		);
	}

	/**
	 * Fallback for no-price products in Block Themes where add-to-cart blocks render empty.
	 *
	 * @param string $block_content Rendered block HTML.
	 */
	public function append_button_to_price_block( string $block_content ): string {
		if ( ! is_product() || ! Settings::get( 'allow_no_price' ) ) {
			return $block_content;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product instanceof WC_Product || '' !== $product->get_price() ) {
			return $block_content;
		}

		return $this->append_button_to_block( $block_content );
	}

	/**
	 * Render button on Shop / Archive product loop.
	 */
	public function render_shop_loop_button(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside build_button_html().
		echo $this->build_button_html(
			$product,
			array(
				'context' => 'loop',
			)
		);
	}

	/**
	 * Render shortcode [hi_quickbuy id="123" title="..." subtitle="..." class="..."].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function render_shortcode( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'title'    => '',
				'subtitle' => '',
				'class'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			'hi_quickbuy'
		);

		$product_id = absint( $atts['id'] );
		if ( 0 === $product_id ) {
			$product_id = get_the_ID() ? absint( get_the_ID() ) : 0;
		}

		if ( 0 === $product_id ) {
			return '';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		return $this->build_button_html(
			$product,
			array(
				'context'  => 'shortcode',
				'title'    => (string) $atts['title'],
				'subtitle' => (string) $atts['subtitle'],
				'class'    => (string) $atts['class'],
			)
		);
	}

	/**
	 * Build escaped HTML for a Quick Buy trigger button.
	 *
	 * @param WC_Product           $product Target product.
	 * @param array<string, mixed> $args    Optional overrides.
	 */
	public function build_button_html( WC_Product $product, array $args = array() ): string {
		if ( ! $this->is_product_eligible( $product ) ) {
			return '';
		}

		$context  = isset( $args['context'] ) ? sanitize_key( (string) $args['context'] ) : 'single';
		$title    = isset( $args['title'] ) && '' !== trim( (string) $args['title'] )
			? (string) $args['title']
			: (string) Settings::get( 'button_title', __( 'Mua ngay', 'hi-quick-buy' ) );
		$subtitle = isset( $args['subtitle'] ) && '' !== trim( (string) $args['subtitle'] )
			? (string) $args['subtitle']
			: ( 'loop' === $context ? '' : (string) Settings::get( 'button_subtitle', '' ) );

		$classes = array(
			'hiqb-trigger-btn',
			'hiqb-trigger-btn--' . $context,
		);

		if ( ! empty( $args['class'] ) ) {
			$extra_classes = preg_split( '/\s+/', (string) $args['class'] );
			if ( is_array( $extra_classes ) ) {
				foreach ( $extra_classes as $cls ) {
					$sanitized_cls = sanitize_html_class( $cls );
					if ( '' !== $sanitized_cls ) {
						$classes[] = $sanitized_cls;
					}
				}
			}
		}

		$html  = '<div class="hiqb-trigger-wrap hiqb-trigger-wrap--' . esc_attr( $context ) . '">';
		$html .= '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		$html .= ' data-product-id="' . esc_attr( (string) $product->get_id() ) . '"';
		$html .= ' data-product-type="' . esc_attr( $product->get_type() ) . '"';
		$html .= ' data-context="' . esc_attr( $context ) . '"';
		$html .= ' aria-haspopup="dialog" aria-controls="hiqb-modal">';
		$html .= '<span class="hiqb-trigger-title">' . esc_html( $title ) . '</span>';

		if ( '' !== $subtitle ) {
			$html .= '<span class="hiqb-trigger-subtitle">' . esc_html( $subtitle ) . '</span>';
		}

		$html .= '</button>';
		$html .= '</div>';

		/**
		 * Filter rendered Quick Buy trigger button HTML.
		 *
		 * @param string     $html    Button markup.
		 * @param WC_Product $product Product instance.
		 * @param string     $context Render context (single, loop, shortcode).
		 */
		return (string) apply_filters( 'hiqb_trigger_button_html', $html, $product, $context );
	}

	/**
	 * Determine whether a product supports Quick Buy.
	 */
	private function is_product_eligible( WC_Product $product ): bool {
		if ( $product->is_type( array( 'external', 'grouped' ) ) ) {
			return false;
		}

		if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
			return false;
		}

		$is_contact_price = ( '' === $product->get_price() );
		if ( ! $product->is_purchasable() ) {
			if ( ! ( $is_contact_price && Settings::get( 'allow_no_price' ) ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'hiqb_is_product_eligible', true, $product );
	}
}

