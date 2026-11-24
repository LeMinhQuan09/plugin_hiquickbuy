<?php
/**
 * Vietnam 2-level administrative divisions (34 Provinces/Cities -> 3,321 Wards/Communes/Special Zones).
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides Vietnam 2-tier location hierarchy, postal codes, and AJAX cascading endpoint.
 */
final class Locations {

	/**
	 * Transient cache key for the compiled 2-level location dataset.
	 */
	public const CACHE_KEY = 'hiqb_vn_locations_v2_cache';

	/**
	 * Cached location tree.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $data = null;

	/**
	 * Register AJAX endpoint for loading wards by province.
	 */
	public function register(): void {
		if ( ! Settings::get( 'enabled' ) || ! Settings::get( 'show_vn_locations' ) ) {
			return;
		}

		add_action( 'wp_ajax_hiqb_get_wards', array( $this, 'ajax_get_wards' ) );
		add_action( 'wp_ajax_nopriv_hiqb_get_wards', array( $this, 'ajax_get_wards' ) );
	}

	/**
	 * Load location dataset from local JSON with transient cache.
	 *
	 * @return array<string, mixed>
	 */
	private function load_data(): array {
		if ( null !== $this->data ) {
			return $this->data;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			$this->data = $cached;
			return $this->data;
		}

		$file = HIQB_PLUGIN_DIR . 'assets/data/vn-locations.json';
		if ( ! is_readable( $file ) ) {
			$this->data = array();
			return $this->data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin asset.
		$json = file_get_contents( $file );
		if ( false === $json ) {
			$this->data = array();
			return $this->data;
		}

		$decoded    = json_decode( $json, true );
		$this->data = is_array( $decoded ) ? $decoded : array();

		set_transient( self::CACHE_KEY, $this->data, DAY_IN_SECONDS );

		return $this->data;
	}

	/**
	 * Get all 34 provinces/cities as [ code => name ].
	 *
	 * @return array<string, string>
	 */
	public function get_provinces(): array {
		$provinces = array();
		$data      = $this->load_data();

		foreach ( $data as $code => $province ) {
			if ( isset( $province['name'] ) ) {
				$provinces[ (string) $code ] = (string) $province['name'];
			}
		}

		/**
		 * Filter Vietnam provinces list.
		 *
		 * @param array<string, string> $provinces Map of province code => label.
		 */
		return (array) apply_filters( 'hiqb_vietnam_provinces', $provinces );
	}

	/**
	 * Get wards/communes/special zones for a province as [ code => name ].
	 *
	 * @param string $province_code Province code (e.g. '01', '79').
	 * @return array<string, string>
	 */
	public function get_wards( string $province_code ): array {
		$wards = array();
		$data  = $this->load_data();

		if ( isset( $data[ $province_code ]['wards'] ) && is_array( $data[ $province_code ]['wards'] ) ) {
			foreach ( $data[ $province_code ]['wards'] as $code => $ward ) {
				if ( is_array( $ward ) && isset( $ward['name'] ) ) {
					$wards[ (string) $code ] = (string) $ward['name'];
				} elseif ( is_string( $ward ) ) {
					$wards[ (string) $code ] = $ward;
				}
			}
		}

		/**
		 * Filter Vietnam wards/communes for a given province.
		 *
		 * @param array<string, string> $wards         Map of ward code => label.
		 * @param string                $province_code Province code.
		 */
		return (array) apply_filters( 'hiqb_vietnam_wards', $wards, $province_code );
	}

	/**
	 * Resolve human-readable province name from code.
	 */
	public function get_province_name( string $province_code ): string {
		$provinces = $this->get_provinces();
		return $provinces[ $province_code ] ?? '';
	}

	/**
	 * Resolve human-readable ward/commune name from code.
	 */
	public function get_ward_name( string $province_code, string $ward_code ): string {
		$wards = $this->get_wards( $province_code );
		return $wards[ $ward_code ] ?? '';
	}

	/**
	 * Resolve postal code (ma_buu_chinh) for a given province + ward.
	 */
	public function get_ward_postcode( string $province_code, string $ward_code ): string {
		$data = $this->load_data();

		if (
			isset( $data[ $province_code ]['wards'][ $ward_code ] )
			&& is_array( $data[ $province_code ]['wards'][ $ward_code ] )
			&& ! empty( $data[ $province_code ]['wards'][ $ward_code ]['postcode'] )
		) {
			return (string) $data[ $province_code ]['wards'][ $ward_code ]['postcode'];
		}

		return '';
	}

	/**
	 * AJAX handler: return wards/communes/special zones for selected province.
	 */
	public function ajax_get_wards(): void {
		check_ajax_referer( 'hiqb_ajax_nonce', 'nonce' );

		$province_code = isset( $_POST['province'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['province'] ) )
			: '';

		if ( '' === $province_code ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$wards = $this->get_wards( $province_code );
		$items = array();

		foreach ( $wards as $code => $name ) {
			$items[] = array(
				'code'     => (string) $code,
				'name'     => (string) $name,
				'postcode' => $this->get_ward_postcode( $province_code, (string) $code ),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}
}
