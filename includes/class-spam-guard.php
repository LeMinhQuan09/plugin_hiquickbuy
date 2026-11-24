<?php
/**
 * Lightweight anti-spam checks (honeypot + signed form timestamp) for the public order form.
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops naive bots without adding friction (no CAPTCHA) for real customers.
 */
final class Spam_Guard {

	public const HONEYPOT_FIELD = 'hiqb_website';
	public const TOKEN_FIELD    = 'hiqb_form_token';

	/**
	 * Whether spam checks are active.
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter whether the honeypot / form-timing checks run on Quick Buy orders.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'hiqb_enable_spam_guard', true );
	}

	/**
	 * Hidden honeypot input + signed timestamp token, printed inside the order form.
	 */
	public static function render_fields(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$issued_at = time();
		$token     = $issued_at . '.' . self::sign( $issued_at );

		$html  = '<div class="hiqb-hp" aria-hidden="true">';
		$html .= '<label>' . esc_html__( 'Để trống trường này', 'hi-quick-buy' );
		$html .= ' <input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off"></label>';
		$html .= '</div>';
		$html .= '<input type="hidden" name="' . esc_attr( self::TOKEN_FIELD ) . '" value="' . esc_attr( $token ) . '">';

		return $html;
	}

	/**
	 * Validate the submitted honeypot and form token.
	 *
	 * @param string $honeypot Submitted honeypot value (must be empty).
	 * @param string $token    Submitted "timestamp.signature" token.
	 * @return string|null Error message, or null when the request looks human.
	 */
	public static function validate( string $honeypot, string $token ): ?string {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$invalid = __( 'Không thể xử lý yêu cầu. Vui lòng tải lại trang và thử lại.', 'hi-quick-buy' );

		if ( '' !== $honeypot ) {
			return $invalid;
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return $invalid;
		}

		$issued_at = (int) $parts[0];
		if ( ! hash_equals( self::sign( $issued_at ), $parts[1] ) ) {
			return $invalid;
		}

		$age = time() - $issued_at;

		/**
		 * Filter the minimum number of seconds between loading the form and submitting it.
		 *
		 * @param int $seconds Default 3.
		 */
		$min_seconds = max( 0, (int) apply_filters( 'hiqb_min_form_fill_seconds', 3 ) );

		/**
		 * Filter how long (seconds) a loaded order form stays valid.
		 *
		 * @param int $seconds Default one day.
		 */
		$lifetime = max( 60, (int) apply_filters( 'hiqb_form_token_lifetime', DAY_IN_SECONDS ) );

		if ( $age < -60 || $age > $lifetime ) {
			return __( 'Phiên đặt hàng đã hết hạn. Vui lòng tải lại trang và thử lại.', 'hi-quick-buy' );
		}

		if ( $age < $min_seconds ) {
			return __( 'Bạn thao tác quá nhanh. Vui lòng đợi vài giây rồi bấm đặt hàng lại.', 'hi-quick-buy' );
		}

		return null;
	}

	/**
	 * HMAC signature for a form timestamp, keyed by the site's salts.
	 *
	 * @param int $issued_at Unix timestamp.
	 */
	private static function sign( int $issued_at ): string {
		return wp_hash( 'hiqb_form|' . $issued_at, 'nonce' );
	}
}
