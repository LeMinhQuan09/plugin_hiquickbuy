<?php
/**
 * Popup Modal Shell & Lazy-loaded AJAX Form Renderer.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

use WC_Product;
use WC_Product_Variable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the empty modal shell and serves the lazy-loaded Quick Buy form via AJAX.
 */
final class Popup_Form {

	/**
	 * Locations provider.
	 */
	private Locations $locations;

	/**
	 * Shipping calculator.
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
	 * Register hooks and AJAX endpoints.
	 */
	public function register(): void {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render_modal_shell' ) );
		add_action( 'wp_ajax_hiqb_load_form', array( $this, 'ajax_load_form' ) );
		add_action( 'wp_ajax_nopriv_hiqb_load_form', array( $this, 'ajax_load_form' ) );
	}

	/**
	 * Print lightweight, accessible modal container in footer (form HTML is lazy-loaded).
	 */
	public function render_modal_shell(): void {
		?>
		<div id="hiqb-modal" class="hiqb-modal" role="dialog" aria-modal="true" aria-labelledby="hiqb-modal-title" hidden>
			<div class="hiqb-modal__backdrop" data-hiqb-close></div>
			<div class="hiqb-modal__dialog" role="document">
				<button type="button" class="hiqb-modal__close" data-hiqb-close aria-label="<?php esc_attr_e( 'Đóng cửa sổ', 'hi-quick-buy' ); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
				<div id="hiqb-modal-body" class="hiqb-modal__body"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: lazy-load Quick Buy form HTML for a given product.
	 */
	public function ajax_load_form(): void {
		check_ajax_referer( 'hiqb_ajax_nonce', 'nonce' );

		$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;

		$product = wc_get_product( $product_id );
		$allow_contact = $product instanceof WC_Product
			&& '' === $product->get_price()
			&& (bool) Settings::get( 'allow_no_price' )
			&& ! $product->is_type( array( 'external', 'grouped' ) );

		if ( ! $product instanceof WC_Product || ( ! $product->is_purchasable() && ! $allow_contact ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sản phẩm không tồn tại hoặc không thể đặt mua.', 'hi-quick-buy' ) ),
				404
			);
		}

		$preselected_attrs = array();
		if ( isset( $_POST['attributes'] ) && is_string( $_POST['attributes'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['attributes'] ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $attr_key => $attr_val ) {
					$preselected_attrs[ sanitize_title( (string) $attr_key ) ] = sanitize_text_field( (string) $attr_val );
				}
			}
		}

		$html = $this->render_form_html( $product, $variation_id, $quantity, $preselected_attrs );

		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	/**
	 * Build the complete popup form HTML.
	 *
	 * @param WC_Product            $product           Product instance.
	 * @param int                   $variation_id      Optional preselected variation ID.
	 * @param int                   $quantity          Initial quantity.
	 * @param array<string, string> $preselected_attrs Preselected attributes from Single Product page.
	 */
	private function render_form_html( WC_Product $product, int $variation_id, int $quantity, array $preselected_attrs ): string {
		$settings          = Settings::get_all();
		$no_price_label    = (string) ( $settings['no_price_label'] ?? __( 'Liên hệ', 'hi-quick-buy' ) );
		$is_variable       = $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable;
		$variations_data   = array();
		$variation_attrs   = array();
		$active_product    = $product;
		$selected_var_id   = 0;

		if ( $is_variable ) {
			$variation_attrs = $product->get_variation_attributes();
			$allow_no_price  = ! empty( $settings['allow_no_price'] );
			$child_ids       = $product->get_children();

			foreach ( $child_ids as $var_id ) {
				$var_obj = wc_get_product( absint( $var_id ) );
				if ( ! $var_obj instanceof WC_Product || 'publish' !== $var_obj->get_status() ) {
					continue;
				}

				$var_has_price = ( '' !== $var_obj->get_price() );
				if ( ! $var_obj->is_purchasable() && ! ( $allow_no_price && ! $var_has_price ) ) {
					continue;
				}

				$var_price   = $var_has_price ? (float) wc_get_price_to_display( $var_obj ) : 0.0;
				$var_img_id  = $var_obj->get_image_id();
				$img_src     = $var_img_id ? (string) wp_get_attachment_image_url( $var_img_id, 'woocommerce_thumbnail' ) : '';
				$raw_attrs   = $var_obj->get_variation_attributes();
				$norm_attrs  = array();
				foreach ( $raw_attrs as $k => $v ) {
					$key                = str_starts_with( (string) $k, 'attribute_' ) ? (string) $k : 'attribute_' . sanitize_title( (string) $k );
					$norm_attrs[ $key ] = (string) $v;
				}

				$max_qty = $var_obj->get_max_purchase_quantity();

				$variations_data[] = array(
					'variation_id' => $var_obj->get_id(),
					'attributes'   => $norm_attrs,
					'has_price'    => $var_has_price,
					'price'        => $var_price,
					'price_html'   => $var_has_price
						? html_entity_decode( wp_strip_all_tags( wc_price( $var_price ) ), ENT_QUOTES, 'UTF-8' )
						: $no_price_label,
					'is_in_stock'  => $var_obj->is_in_stock() || $var_obj->backorders_allowed(),
					'max_qty'      => $max_qty > 0 ? $max_qty : 0,
					'image_src'    => $img_src,
					'sku'          => (string) $var_obj->get_sku(),
				);
			}

			if ( $variation_id > 0 ) {
				$maybe_var = wc_get_product( $variation_id );
				if ( $maybe_var instanceof WC_Product && $maybe_var->get_parent_id() === $product->get_id() ) {
					$active_product  = $maybe_var;
					$selected_var_id = $variation_id;
				}
			}
		}

		$has_price  = ( '' !== $active_product->get_price() );
		$unit_price = $has_price ? (float) wc_get_price_to_display( $active_product ) : 0.0;
		$image_id   = $active_product->get_image_id() ?: $product->get_image_id();
		$image_url  = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );

		$provinces     = ! empty( $settings['show_vn_locations'] ) ? $this->locations->get_provinces() : array();
		$initial_rates = $has_price
			? $this->shipping_calculator->calculate(
				$active_product,
				$quantity,
				array( 'country' => 'VN' )
			)
			: array();

		$initial_shipping = ! empty( $initial_rates ) ? (float) $initial_rates[0]['cost'] : 0.0;
		$initial_subtotal = $unit_price * $quantity;
		$initial_total    = $initial_subtotal + $initial_shipping;

		$unit_price_display = $has_price
			? html_entity_decode( wp_strip_all_tags( wc_price( $unit_price ) ), ENT_QUOTES, 'UTF-8' )
			: $no_price_label;
		$subtotal_display   = $has_price
			? html_entity_decode( wp_strip_all_tags( wc_price( $initial_subtotal ) ), ENT_QUOTES, 'UTF-8' )
			: $no_price_label;
		$shipping_display   = ! $has_price
			? $no_price_label
			: ( $initial_shipping > 0
				? html_entity_decode( wp_strip_all_tags( wc_price( $initial_shipping ) ), ENT_QUOTES, 'UTF-8' )
				: __( 'Miễn phí', 'hi-quick-buy' ) );
		$total_display      = $has_price
			? html_entity_decode( wp_strip_all_tags( wc_price( $initial_total ) ), ENT_QUOTES, 'UTF-8' )
			: $no_price_label;

		ob_start();
		?>
		<div class="hiqb-form-wrapper"
			data-product-id="<?php echo esc_attr( (string) $product->get_id() ); ?>"
			data-is-variable="<?php echo esc_attr( $is_variable ? '1' : '0' ); ?>"
			data-has-price="<?php echo esc_attr( $has_price ? '1' : '0' ); ?>"
			data-unit-price="<?php echo esc_attr( (string) $unit_price ); ?>"
			data-default-image="<?php echo esc_attr( $image_url ); ?>"
			data-variations="<?php echo esc_attr( wp_json_encode( $variations_data ) ?: '[]' ); ?>">

			<div class="hiqb-modal__header">
				<h2 id="hiqb-modal-title" class="hiqb-modal__title">
					<?php
					printf(
						/* translators: %s: product name */
						esc_html__( 'Đặt mua %s', 'hi-quick-buy' ),
						esc_html( $product->get_name() )
					);
					?>
				</h2>
			</div>

			<form id="hiqb-order-form" class="hiqb-form" novalidate>
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product->get_id() ); ?>">
				<input type="hidden" name="variation_id" value="<?php echo esc_attr( (string) $selected_var_id ); ?>">

				<div class="hiqb-layout">
					<!-- Left column: Product info, variations & confirmation note -->
					<div class="hiqb-col-left">
						<div class="hiqb-product-card">
							<div class="hiqb-product-card__thumb">
								<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" class="hiqb-product-image" loading="lazy">
							</div>
							<div class="hiqb-product-card__info">
								<div class="hiqb-product-card__name"><?php echo esc_html( $product->get_name() ); ?></div>
								<div class="hiqb-product-card__price" data-hiqb-unit-price>
									<?php echo esc_html( $unit_price_display ); ?>
								</div>
								<div class="hiqb-qty-control" data-hiqb-qty-control<?php echo ! $has_price ? ' hidden' : ''; ?>>
									<label for="hiqb-quantity" class="hiqb-qty-control__label"><?php esc_html_e( 'Số lượng:', 'hi-quick-buy' ); ?></label>
									<div class="hiqb-qty-box">
										<button type="button" class="hiqb-qty-btn" data-hiqb-qty="-1" aria-label="<?php esc_attr_e( 'Giảm số lượng', 'hi-quick-buy' ); ?>">&minus;</button>
										<input type="number" id="hiqb-quantity" name="quantity" class="hiqb-qty-input" value="<?php echo esc_attr( (string) $quantity ); ?>" min="1" step="1" inputmode="numeric" required>
										<button type="button" class="hiqb-qty-btn" data-hiqb-qty="1" aria-label="<?php esc_attr_e( 'Tăng số lượng', 'hi-quick-buy' ); ?>">+</button>
									</div>
								</div>
							</div>
						</div>

						<!-- Variable product attributes -->
						<?php if ( $is_variable && ! empty( $variation_attrs ) ) : ?>
							<div class="hiqb-section hiqb-variations" data-hiqb-variations>
								<?php foreach ( $variation_attrs as $attribute_name => $options ) : ?>
									<?php
									$attr_key   = 'attribute_' . sanitize_title( (string) $attribute_name );
									$attr_label = wc_attribute_label( (string) $attribute_name );
									$selected   = $preselected_attrs[ $attr_key ] ?? $product->get_variation_default_attribute( (string) $attribute_name );
									?>
									<div class="hiqb-field">
										<label class="hiqb-label" for="hiqb-<?php echo esc_attr( $attr_key ); ?>">
											<?php echo esc_html( $attr_label ); ?> <span class="hiqb-required">*</span>
										</label>
										<select id="hiqb-<?php echo esc_attr( $attr_key ); ?>"
											name="<?php echo esc_attr( $attr_key ); ?>"
											class="hiqb-select hiqb-variation-select"
											data-attribute-name="<?php echo esc_attr( $attr_key ); ?>"
											required>
											<option value=""><?php echo esc_html( sprintf( /* translators: %s: attribute label */ __( 'Chọn %s', 'hi-quick-buy' ), $attr_label ) ); ?></option>
											<?php foreach ( $options as $option ) : ?>
												<?php
												$option_slug  = (string) $option;
												$option_label = $option_slug;
												if ( taxonomy_exists( (string) $attribute_name ) ) {
													$term = get_term_by( 'slug', $option_slug, (string) $attribute_name );
													if ( $term && ! is_wp_error( $term ) ) {
														$option_label = $term->name;
													}
												}
												?>
												<option value="<?php echo esc_attr( $option_slug ); ?>" <?php selected( $selected, $option_slug ); ?>>
													<?php echo esc_html( $option_label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								<?php endforeach; ?>
								<div class="hiqb-variation-status" data-hiqb-variation-status hidden></div>
							</div>
						<?php endif; ?>

						<!-- Shipping methods (left column to balance priced-product layout) -->
						<?php if ( ! empty( $settings['enable_shipping_calc'] ) && $product->needs_shipping() && ( $has_price || $is_variable ) ) : ?>
							<div class="hiqb-section hiqb-section--shipping" data-hiqb-shipping-section<?php echo ! $has_price ? ' hidden' : ''; ?>>
								<div class="hiqb-section__title"><?php esc_html_e( 'Phương thức vận chuyển', 'hi-quick-buy' ); ?></div>
								<div class="hiqb-shipping-list" data-hiqb-shipping-list>
									<?php foreach ( $initial_rates as $index => $rate ) : ?>
										<label class="hiqb-option-card">
											<input type="radio"
												name="shipping_method"
												value="<?php echo esc_attr( (string) $rate['id'] ); ?>"
												data-cost="<?php echo esc_attr( (string) $rate['cost'] ); ?>"
												<?php checked( 0, $index ); ?>>
											<span class="hiqb-option-card__label"><?php echo esc_html( (string) $rate['label'] ); ?></span>
											<span class="hiqb-option-card__price"><?php echo esc_html( (string) $rate['cost_formatted'] ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<!-- Order summary (left column) -->
						<div class="hiqb-summary" data-hiqb-summary-section<?php echo ! $has_price ? ' hidden' : ''; ?>>
							<div class="hiqb-summary__row">
								<span><?php esc_html_e( 'Tạm tính:', 'hi-quick-buy' ); ?></span>
								<strong data-hiqb-subtotal><?php echo esc_html( $subtotal_display ); ?></strong>
							</div>
							<?php if ( ! empty( $settings['enable_shipping_calc'] ) && $product->needs_shipping() ) : ?>
								<div class="hiqb-summary__row" data-hiqb-shipping-row<?php echo ! $has_price ? ' hidden' : ''; ?>>
									<span><?php esc_html_e( 'Phí vận chuyển:', 'hi-quick-buy' ); ?></span>
									<strong data-hiqb-shipping-cost>
										<?php echo esc_html( $shipping_display ); ?>
									</strong>
								</div>
							<?php endif; ?>
							<div class="hiqb-summary__row hiqb-summary__row--total">
								<span><?php esc_html_e( 'Tổng cộng:', 'hi-quick-buy' ); ?></span>
								<strong data-hiqb-grand-total><?php echo esc_html( $total_display ); ?></strong>
							</div>
						</div>

						<p class="hiqb-confirm-note">
							<?php esc_html_e( 'Bạn vui lòng nhập đúng số điện thoại để chúng tôi sẽ gọi xác nhận đơn hàng trước khi giao hàng. Xin cảm ơn!', 'hi-quick-buy' ); ?>
						</p>
					</div>

					<!-- Right column: Customer details, payment & submit -->
					<div class="hiqb-col-right">
						<div class="hiqb-section">
							<div class="hiqb-section__title hiqb-section__title--main"><?php esc_html_e( 'Thông tin người mua', 'hi-quick-buy' ); ?></div>

							<div class="hiqb-salutation" role="radiogroup" aria-label="<?php esc_attr_e( 'Danh xưng', 'hi-quick-buy' ); ?>">
								<label class="hiqb-radio">
									<input type="radio" name="salutation" value="anh" checked>
									<span><?php esc_html_e( 'Anh', 'hi-quick-buy' ); ?></span>
								</label>
								<label class="hiqb-radio">
									<input type="radio" name="salutation" value="chi">
									<span><?php esc_html_e( 'Chị', 'hi-quick-buy' ); ?></span>
								</label>
							</div>

							<div class="hiqb-grid hiqb-grid--2">
								<div class="hiqb-field">
									<input type="text" id="hiqb-full-name" name="full_name" class="hiqb-input" value="" placeholder="<?php esc_attr_e( 'Họ và tên', 'hi-quick-buy' ); ?>" aria-label="<?php esc_attr_e( 'Họ và tên', 'hi-quick-buy' ); ?>" autocomplete="name" required>
									<span class="hiqb-field-error" data-error-for="full_name"></span>
								</div>

								<div class="hiqb-field">
									<input type="tel" id="hiqb-phone" name="phone" class="hiqb-input" value="" placeholder="<?php esc_attr_e( 'Số điện thoại', 'hi-quick-buy' ); ?>" aria-label="<?php esc_attr_e( 'Số điện thoại', 'hi-quick-buy' ); ?>" maxlength="20" inputmode="tel" autocomplete="tel" required>
									<span class="hiqb-field-error" data-error-for="phone"></span>
								</div>
							</div>

							<?php if ( 'disabled' !== $settings['email_field_mode'] ) : ?>
								<?php
								$email_required    = 'required' === $settings['email_field_mode'];
								$email_placeholder = $email_required
									? __( 'Địa chỉ email', 'hi-quick-buy' )
									: __( 'Địa chỉ email (Không bắt buộc)', 'hi-quick-buy' );
								?>
								<div class="hiqb-field">
									<input type="email" id="hiqb-email" name="email" class="hiqb-input" value="" placeholder="<?php echo esc_attr( $email_placeholder ); ?>" aria-label="<?php echo esc_attr( $email_placeholder ); ?>" autocomplete="email" <?php echo $email_required ? 'required' : ''; ?>>
									<span class="hiqb-field-error" data-error-for="email"></span>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $settings['show_vn_locations'] ) ) : ?>
								<div class="hiqb-grid hiqb-grid--2">
									<div class="hiqb-field">
										<select id="hiqb-province" name="province" class="hiqb-select" data-hiqb-searchable data-search-placeholder="<?php esc_attr_e( 'Tìm Tỉnh / Thành phố...', 'hi-quick-buy' ); ?>" aria-label="<?php esc_attr_e( 'Tỉnh / Thành phố', 'hi-quick-buy' ); ?>" required>
											<option value=""><?php esc_html_e( 'Chọn Tỉnh / Thành phố', 'hi-quick-buy' ); ?></option>
											<?php foreach ( $provinces as $code => $name ) : ?>
												<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( (string) $name ); ?></option>
											<?php endforeach; ?>
										</select>
										<span class="hiqb-field-error" data-error-for="province"></span>
									</div>

									<div class="hiqb-field">
										<select id="hiqb-ward" name="ward" class="hiqb-select" data-hiqb-searchable data-search-placeholder="<?php esc_attr_e( 'Tìm Phường / Xã / Đặc khu...', 'hi-quick-buy' ); ?>" aria-label="<?php esc_attr_e( 'Phường / Xã / Đặc khu', 'hi-quick-buy' ); ?>" disabled required>
											<option value=""><?php esc_html_e( 'Chọn Phường / Xã', 'hi-quick-buy' ); ?></option>
										</select>
										<span class="hiqb-field-error" data-error-for="ward"></span>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $settings['show_address_field'] ) ) : ?>
								<div class="hiqb-field">
									<input type="text" id="hiqb-address" name="address" class="hiqb-input" placeholder="<?php esc_attr_e( 'Địa chỉ (Số nhà, tên đường...)', 'hi-quick-buy' ); ?>" aria-label="<?php esc_attr_e( 'Địa chỉ chi tiết', 'hi-quick-buy' ); ?>" autocomplete="street-address" required>
									<span class="hiqb-field-error" data-error-for="address"></span>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $settings['show_note_field'] ) ) : ?>
								<div class="hiqb-field">
									<textarea id="hiqb-note" name="order_note" class="hiqb-textarea" rows="2" placeholder="<?php esc_attr_e( 'Ghi chú đơn hàng (Không bắt buộc)', 'hi-quick-buy' ); ?>" aria-label="<?php esc_attr_e( 'Ghi chú đơn hàng', 'hi-quick-buy' ); ?>"></textarea>
								</div>
							<?php endif; ?>
						</div>

						<!-- Payment methods -->
						<?php if ( ! empty( $settings['show_payment_methods'] ) && ( $has_price || $is_variable ) ) : ?>
							<?php
							$allow_cod  = ! empty( $settings['enable_cod'] );
							$allow_bacs = ! empty( $settings['enable_bacs'] );
							?>
							<div class="hiqb-section" data-hiqb-payment-section<?php echo ! $has_price ? ' hidden' : ''; ?>>
								<div class="hiqb-section__title"><?php esc_html_e( 'Phương thức thanh toán', 'hi-quick-buy' ); ?></div>
								<div class="hiqb-payment-list">
									<?php if ( $allow_cod ) : ?>
										<label class="hiqb-option-card">
											<input type="radio" name="payment_method" value="cod" checked>
											<span class="hiqb-option-card__label"><?php esc_html_e( 'Thanh toán khi nhận hàng (COD)', 'hi-quick-buy' ); ?></span>
										</label>
									<?php endif; ?>
									<?php if ( $allow_bacs ) : ?>
										<label class="hiqb-option-card">
											<input type="radio" name="payment_method" value="bacs" <?php checked( ! $allow_cod ); ?>>
											<span class="hiqb-option-card__label"><?php esc_html_e( 'Chuyển khoản ngân hàng (BACS)', 'hi-quick-buy' ); ?></span>
										</label>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>

						<div class="hiqb-form-alert" data-hiqb-form-alert hidden></div>

						<button type="submit" class="hiqb-submit-btn" data-hiqb-submit>
							<?php esc_html_e( 'Đặt hàng ngay', 'hi-quick-buy' ); ?>
						</button>
					</div>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

