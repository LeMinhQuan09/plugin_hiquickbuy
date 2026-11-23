<?php
/**
 * Admin Settings Page under WooCommerce submenu using WordPress Settings API.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy\Admin;

use HIQuickBuy\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WooCommerce submenu and Settings API fields.
 */
final class Admin_Settings {

	/**
	 * Option group slug.
	 */
	private const OPTION_GROUP = 'hiqb_settings_group';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'hi-quick-buy-settings';

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . HIQB_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Enqueue admin CSS strictly on the HI Quick-Buy settings screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_media();

		$css_file = HIQB_PLUGIN_DIR . 'assets/css/hi-quick-buy-admin.css';
		$js_file  = HIQB_PLUGIN_DIR . 'assets/js/hi-quick-buy-admin.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : HIQB_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : HIQB_VERSION;

		wp_enqueue_style(
			'hiqb-admin-settings',
			HIQB_PLUGIN_URL . 'assets/css/hi-quick-buy-admin.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'hiqb-admin-settings',
			HIQB_PLUGIN_URL . 'assets/js/hi-quick-buy-admin.js',
			array(),
			$js_ver,
			true
		);

		$primary_color = sanitize_hex_color( (string) Settings::get( 'primary_color', '#d70018' ) );
		if ( is_string( $primary_color ) && '' !== $primary_color ) {
			wp_add_inline_style(
				'hiqb-admin-settings',
				sprintf( ':root { --hiqb-admin-primary: %1$s; } .hiqb-switch input:checked + .hiqb-switch__slider { background: %1$s; }', $primary_color )
			);
		}
	}

	/**
	 * Add "HI Quick-Buy" submenu under WooCommerce.
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'HI Quick-Buy', 'hi-quick-buy' ),
			__( 'HI Quick-Buy', 'hi-quick-buy' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add quick link to settings on Plugins list table.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function add_settings_link( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Cài đặt', 'hi-quick-buy' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register settings, sections, and fields via Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::get_defaults(),
			)
		);

		// Section 1: General & Trigger buttons.
		add_settings_section(
			'hiqb_section_general',
			__( '1. Cài đặt chung & Nút Mua nhanh', 'hi-quick-buy' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$this->add_checkbox_field( 'enabled', __( 'Bật HI Quick-Buy', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Kích hoạt chức năng mua hàng nhanh trên toàn site.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'show_single_product', __( 'Hiện ở trang chi tiết sản phẩm', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Tự động hiển thị sau nút Thêm vào giỏ hàng.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'show_shop_loop', __( 'Hiện ở danh sách / danh mục sản phẩm', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Hiển thị nút Mua nhanh ngay dưới mỗi sản phẩm ở trang Cửa hàng, Danh mục và kết quả tìm kiếm.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'allow_no_price', __( 'Hiển thị với sản phẩm chưa có giá', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Vẫn hiển thị nút Mua nhanh và cho phép đặt hàng / yêu cầu báo giá khi sản phẩm chưa nhập giá.', 'hi-quick-buy' ) );
		$this->add_text_field( 'no_price_label', __( 'Nhãn hiển thị khi chưa có giá', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Ví dụ: Liên hệ, Báo giá sau...', 'hi-quick-buy' ) );
		$this->add_color_field( 'primary_color', __( 'Màu chủ đạo (Nút & Popup)', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Áp dụng cho nút Mua nhanh, thanh tiêu đề popup, radio chọn và nút Đặt hàng ngay. Mặc định: #d70018.', 'hi-quick-buy' ) );
		$this->add_text_field( 'button_title', __( 'Tiêu đề nút bấm', 'hi-quick-buy' ), 'hiqb_section_general' );
		$this->add_text_field( 'button_subtitle', __( 'Tiêu đề phụ nút bấm', 'hi-quick-buy' ), 'hiqb_section_general', __( 'Hiển thị bên dưới tiêu đề chính ở trang chi tiết sản phẩm.', 'hi-quick-buy' ) );

		// Section 2: Form fields & payment.
		add_settings_section(
			'hiqb_section_form',
			__( '2. Trường thông tin & Thanh toán', 'hi-quick-buy' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$this->add_select_field(
			'email_field_mode',
			__( 'Trường Email', 'hi-quick-buy' ),
			'hiqb_section_form',
			array(
				'disabled' => __( 'Ẩn trường Email', 'hi-quick-buy' ),
				'optional' => __( 'Hiển thị (không bắt buộc)', 'hi-quick-buy' ),
				'required' => __( 'Hiển thị (bắt buộc nhập)', 'hi-quick-buy' ),
			)
		);

		$this->add_checkbox_field( 'show_vn_locations', __( 'Dropdown 2 cấp Tỉnh / Thành → Phường / Xã', 'hi-quick-buy' ), 'hiqb_section_form', __( 'Sử dụng danh sách 34 Tỉnh/Thành phố và 3.321 Phường/Xã/Đặc khu kèm mã bưu chính.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'show_address_field', __( 'Trường địa chỉ chi tiết', 'hi-quick-buy' ), 'hiqb_section_form', __( 'Hiển thị ô nhập số nhà, tên đường nhận hàng.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'show_note_field', __( 'Trường ghi chú đơn hàng', 'hi-quick-buy' ), 'hiqb_section_form', __( 'Cho phép khách ghi chú thời gian giao hàng hoặc yêu cầu xuất hóa đơn.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'show_payment_methods', __( 'Cho phép chọn cổng thanh toán', 'hi-quick-buy' ), 'hiqb_section_form', __( 'Hiển thị lựa chọn phương thức thanh toán ngay trong popup.', 'hi-quick-buy' ) );
		$this->add_checkbox_field( 'enable_cod', __( 'Thanh toán khi nhận hàng (COD)', 'hi-quick-buy' ), 'hiqb_section_form', __( 'Cho phép khách hàng thanh toán tiền mặt khi nhận hàng.', 'hi-quick-buy' ) );

		$bacs_accounts = get_option( 'woocommerce_bacs_accounts', array() );
		$bacs_count    = is_array( $bacs_accounts ) ? count( $bacs_accounts ) : 0;
		$bacs_url      = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' );

		if ( $bacs_count > 0 ) {
			$bacs_hint = sprintf(
				/* translators: 1: number of configured bank accounts, 2: link to WooCommerce BACS settings */
				'<span class="hiqb-admin-hint hiqb-admin-hint--ok">&#10003; ' . esc_html__( 'Đã đồng bộ %1$d tài khoản ngân hàng từ WooCommerce BACS.', 'hi-quick-buy' ) . ' <a href="%2$s">' . esc_html__( 'Quản lý tài khoản', 'hi-quick-buy' ) . '</a></span>',
				$bacs_count,
				esc_url( $bacs_url )
			);
		} else {
			$bacs_hint = sprintf(
				/* translators: %s: link to WooCommerce BACS settings */
				'<span class="hiqb-admin-hint hiqb-admin-hint--warn">&#9888; ' . esc_html__( 'Chưa có tài khoản ngân hàng nào. Vui lòng thêm tài khoản tại %s (hoặc tải ảnh QR bên dưới) để popup hiển thị hướng dẫn chuyển khoản.', 'hi-quick-buy' ) . '</span>',
				'<a href="' . esc_url( $bacs_url ) . '">' . esc_html__( 'WooCommerce → Cài đặt → Thanh toán → Chuyển khoản ngân hàng (BACS)', 'hi-quick-buy' ) . '</a>'
			);
		}

		$this->add_checkbox_field(
			'enable_bacs',
			__( 'Chuyển khoản ngân hàng (BACS)', 'hi-quick-buy' ),
			'hiqb_section_form',
			__( 'Cho phép khách hàng thanh toán bằng chuyển khoản ngân hàng.', 'hi-quick-buy' ),
			$bacs_hint
		);

		$this->add_image_field(
			'bacs_qr_image_id',
			__( 'Ảnh mã QR chuyển khoản', 'hi-quick-buy' ),
			'hiqb_section_form',
			__( 'Tải lên ảnh mã QR chính thức xuất trực tiếp từ ứng dụng ngân hàng của bạn để hiển thị ngay trong popup khi khách chọn BACS.', 'hi-quick-buy' )
		);

		$transfer_hint = '<div class="hiqb-var-legend">'
			. '<div class="hiqb-var-legend__title">' . esc_html__( 'Giải thích các biến tự động (giữ nguyên dấu ngoặc nhọn {…}, nên giữ {order_id} để dễ đối soát):', 'hi-quick-buy' ) . '</div>'
			. '<ul class="hiqb-var-legend__list">'
			. '<li><code>{order_id}</code> — ' . esc_html__( 'Mã đơn hàng (nên giữ lại để nhận diện đúng đơn khi khách chuyển khoản).', 'hi-quick-buy' ) . '</li>'
			. '<li><code>{phone}</code> — ' . esc_html__( 'Số điện thoại người mua.', 'hi-quick-buy' ) . '</li>'
			. '<li><code>{customer_name}</code> — ' . esc_html__( 'Họ tên người mua (tự động bỏ dấu tiếng Việt).', 'hi-quick-buy' ) . '</li>'
			. '</ul>'
			. '<div class="hiqb-var-legend__example">' . esc_html__( 'Ví dụ: nhập', 'hi-quick-buy' ) . ' <code>THANHTOAN {order_id} - {phone}</code> &rarr; <strong>THANHTOAN 1025 - 0912345678</strong></div>'
			. '</div>';

		$this->add_text_field(
			'bacs_transfer_template',
			__( 'Mẫu nội dung chuyển khoản', 'hi-quick-buy' ),
			'hiqb_section_form',
			'',
			$transfer_hint
		);

		// Section 3: Independent shipping calculator.
		add_settings_section(
			'hiqb_section_shipping',
			__( '3. Tính phí vận chuyển độc lập', 'hi-quick-buy' ),
			static function (): void {
				echo '<p class="hiqb-admin-card__desc">' . esc_html__( 'Phí vận chuyển được tính bằng gói giả lập độc lập theo WooCommerce Shipping Zones và KHÔNG can thiệp vào giỏ hàng hiện tại của khách.', 'hi-quick-buy' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_checkbox_field( 'enable_shipping_calc', __( 'Bật tính phí vận chuyển', 'hi-quick-buy' ), 'hiqb_section_shipping', __( 'Tự động tính phí giao hàng theo Tỉnh/Thành và Phường/Xã đã chọn.', 'hi-quick-buy' ) );
		$this->add_text_field( 'fallback_shipping_label', __( 'Tên gói vận chuyển mặc định', 'hi-quick-buy' ), 'hiqb_section_shipping', __( 'Dùng khi khu vực giao hàng chưa khớp Shipping Zone nào trong WooCommerce.', 'hi-quick-buy' ) );
		$this->add_number_field( 'fallback_shipping_fee', __( 'Phí vận chuyển mặc định', 'hi-quick-buy' ), 'hiqb_section_shipping', __( 'Nhập 0 nếu muốn miễn phí vận chuyển mặc định.', 'hi-quick-buy' ) );

		// Section 4: Messages & behavior.
		add_settings_section(
			'hiqb_section_messages',
			__( '4. Hành vi sau đặt hàng & Thông báo', 'hi-quick-buy' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$this->add_select_field(
			'after_order_action',
			__( 'Sau khi đặt hàng thành công', 'hi-quick-buy' ),
			'hiqb_section_messages',
			array(
				'popup'    => __( 'Hiển thị thông báo chúc mừng ngay trong Popup', 'hi-quick-buy' ),
				'redirect' => __( 'Chuyển hướng sang trang Cảm ơn (Order Received)', 'hi-quick-buy' ),
			)
		);

		$success_hint = '<div class="hiqb-var-legend">'
			. '<div class="hiqb-var-legend__title">' . esc_html__( 'Giải thích các biến tự động (giữ nguyên dấu ngoặc nhọn {…}):', 'hi-quick-buy' ) . '</div>'
			. '<ul class="hiqb-var-legend__list">'
			. '<li><code>{order_id}</code> — ' . esc_html__( 'Mã đơn hàng vừa tạo.', 'hi-quick-buy' ) . '</li>'
			. '<li><code>{customer_name}</code> — ' . esc_html__( 'Danh xưng + Họ tên khách hàng (ví dụ: Anh Nguyễn Văn A).', 'hi-quick-buy' ) . '</li>'
			. '<li><code>{total}</code> — ' . esc_html__( 'Tổng tiền đơn hàng (hoặc nhãn Liên hệ nếu sản phẩm chưa có giá).', 'hi-quick-buy' ) . '</li>'
			. '</ul>'
			. '</div>';

		$this->add_textarea_field(
			'success_message',
			__( 'Mẫu thông báo thành công', 'hi-quick-buy' ),
			'hiqb_section_messages',
			'',
			$success_hint
		);

		$this->add_textarea_field(
			'error_message',
			__( 'Mẫu thông báo thất bại', 'hi-quick-buy' ),
			'hiqb_section_messages'
		);

		$this->add_checkbox_field(
			'delete_data_on_uninstall',
			__( 'Xóa dữ liệu khi gỡ bỏ plugin', 'hi-quick-buy' ),
			'hiqb_section_messages',
			__( 'Xóa toàn bộ cấu hình plugin khi gỡ cài đặt HI Quick-Buy.', 'hi-quick-buy' )
		);
	}

	/**
	 * Render the Settings page with modern card sections while preserving WordPress Settings API.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang cài đặt này.', 'hi-quick-buy' ) );
		}

		global $wp_settings_sections, $wp_settings_fields;
		$sections = $wp_settings_sections[ self::PAGE_SLUG ] ?? array();
		?>
		<div class="wrap hiqb-admin-wrap">
			<div class="hiqb-admin-header">
				<div>
					<div class="hiqb-admin-header__title-row">
						<h1 class="hiqb-admin-header__title"><?php esc_html_e( 'HI Quick-Buy', 'hi-quick-buy' ); ?></h1>
						<span class="hiqb-admin-badge">v<?php echo esc_html( HIQB_VERSION ); ?></span>
					</div>
					<p class="hiqb-admin-header__subtitle">
						<?php esc_html_e( 'Cấu hình nút Mua nhanh, form đặt hàng chuẩn Việt Nam và tính phí vận chuyển độc lập.', 'hi-quick-buy' ); ?>
					</p>
				</div>
				<div class="hiqb-admin-shortcode">
					<span><?php esc_html_e( 'Shortcode:', 'hi-quick-buy' ); ?></span>
					<code>[hi_quickbuy id="123"]</code>
				</div>
			</div>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<?php foreach ( $sections as $section ) : ?>
					<div class="hiqb-admin-card">
						<div class="hiqb-admin-card__head">
							<?php if ( ! empty( $section['title'] ) ) : ?>
								<h2 class="hiqb-admin-card__title"><?php echo esc_html( (string) $section['title'] ); ?></h2>
							<?php endif; ?>
							<?php
							if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
								call_user_func( $section['callback'], $section );
							}
							?>
						</div>
						<div class="hiqb-admin-card__body">
							<?php if ( ! empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section['id'] ] ) ) : ?>
								<table class="form-table" role="presentation">
									<?php do_settings_fields( self::PAGE_SLUG, $section['id'] ); ?>
								</table>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<div class="hiqb-admin-footer">
					<span class="hiqb-admin-footer__note">
						<?php esc_html_e( 'Các thay đổi sẽ có hiệu lực ngay trên trang cửa hàng sau khi lưu.', 'hi-quick-buy' ); ?>
					</span>
					<?php submit_button( __( 'Lưu cài đặt', 'hi-quick-buy' ), 'primary', 'submit', true ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Register a modern toggle-switch checkbox field.
	 */
	private function add_checkbox_field( string $key, string $label, string $section, string $description = '', string $hint_html = '' ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $description, $hint_html ): void {
				$value = (int) Settings::get( $key, 0 );
				printf(
					'<label class="hiqb-switch-wrap"><span class="hiqb-switch"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /><span class="hiqb-switch__slider" aria-hidden="true"></span></span><span class="hiqb-switch__text">%4$s</span></label>',
					esc_attr( Settings::OPTION_NAME ),
					esc_attr( $key ),
					checked( 1, $value, false ),
					esc_html( $description )
				);
				if ( '' !== $hint_html ) {
					echo '<div class="hiqb-admin-hint-wrap">' . wp_kses_post( $hint_html ) . '</div>';
				}
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a text field.
	 */
	private function add_text_field( string $key, string $label, string $section, string $description = '', string $hint_html = '' ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $description, $hint_html ): void {
				$value = (string) Settings::get( $key, '' );
				printf(
					'<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
					esc_attr( Settings::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $value )
				);
				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
				if ( '' !== $hint_html ) {
					echo wp_kses_post( $hint_html );
				}
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a WordPress Media Library image picker field.
	 */
	private function add_image_field( string $key, string $label, string $section, string $description = '' ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $description ): void {
				$image_id  = absint( Settings::get( $key, 0 ) );
				$image_url = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '';
				?>
				<div class="hiqb-media-field" data-hiqb-media-field>
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_NAME . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $image_id ); ?>" data-hiqb-media-input />
					<div class="hiqb-media-preview" data-hiqb-media-preview<?php echo '' === $image_url ? ' hidden' : ''; ?>>
						<?php if ( '' !== $image_url ) : ?>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php esc_attr_e( 'Mã QR chuyển khoản', 'hi-quick-buy' ); ?>" />
						<?php endif; ?>
					</div>
					<div class="hiqb-media-actions">
						<button type="button" class="button button-secondary" data-hiqb-media-select>
							<?php esc_html_e( 'Chọn ảnh mã QR', 'hi-quick-buy' ); ?>
						</button>
						<button type="button" class="button button-link-delete" data-hiqb-media-remove<?php echo '' === $image_url ? ' hidden' : ''; ?>>
							<?php esc_html_e( 'Xóa ảnh', 'hi-quick-buy' ); ?>
						</button>
					</div>
				</div>
				<?php
				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a color picker field.
	 */
	private function add_color_field( string $key, string $label, string $section, string $description = '' ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $description ): void {
				$value = (string) Settings::get( $key, '#d70018' );
				printf(
					'<div class="hiqb-color-field"><input type="color" id="hiqb_%2$s" name="%1$s[%2$s]" value="%3$s" oninput="this.nextElementSibling.textContent=this.value" /><code>%3$s</code></div>',
					esc_attr( Settings::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $value )
				);
				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a number field.
	 */
	private function add_number_field( string $key, string $label, string $section, string $description = '' ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $description ): void {
				$value = (string) Settings::get( $key, '0' );
				printf(
					'<input type="number" min="0" step="any" class="small-text" name="%1$s[%2$s]" value="%3$s" />',
					esc_attr( Settings::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $value )
				);
				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a select field.
	 *
	 * @param array<string, string> $options Key => label choices.
	 */
	private function add_select_field( string $key, string $label, string $section, array $options ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $options ): void {
				$current = (string) Settings::get( $key, '' );
				printf(
					'<select name="%1$s[%2$s]">',
					esc_attr( Settings::OPTION_NAME ),
					esc_attr( $key )
				);
				foreach ( $options as $opt_value => $opt_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $opt_value ),
						selected( $current, (string) $opt_value, false ),
						esc_html( (string) $opt_label )
					);
				}
				echo '</select>';
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a textarea field.
	 */
	private function add_textarea_field( string $key, string $label, string $section, string $description = '', string $hint_html = '' ): void {
		add_settings_field(
			'hiqb_' . $key,
			$label,
			static function () use ( $key, $description, $hint_html ): void {
				$value = (string) Settings::get( $key, '' );
				printf(
					'<textarea rows="3" class="large-text" name="%1$s[%2$s]">%3$s</textarea>',
					esc_attr( Settings::OPTION_NAME ),
					esc_attr( $key ),
					esc_textarea( $value )
				);
				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
				if ( '' !== $hint_html ) {
					echo wp_kses_post( $hint_html );
				}
			},
			self::PAGE_SLUG,
			$section
		);
	}
}

