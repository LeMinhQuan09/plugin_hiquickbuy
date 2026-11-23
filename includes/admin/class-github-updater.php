<?php
/**
 * GitHub Releases Auto-Updater for HI Quick-Buy (supports Public & Private repositories).
 *
 * @package HIQuickBuy
 */

declare(strict_types=1);

namespace HIQuickBuy\Admin;

use WP_Error;
use WP_Upgrader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates GitHub Releases with WordPress core plugin update system.
 */
final class GitHub_Updater {

	/**
	 * Transient key for caching latest GitHub release metadata.
	 */
	public const CACHE_KEY = 'hiqb_github_release_cache';

	/**
	 * Cache lifetime for valid release responses (6 hours).
	 */
	private const CACHE_TTL = 21600;

	/**
	 * Short cache lifetime when GitHub API returns an error (15 minutes).
	 */
	private const ERROR_TTL = 900;

	/**
	 * Plugin slug (directory name or fallback slug).
	 */
	private string $slug;

	/**
	 * Repository in "owner/repo" format.
	 */
	private string $repo;

	/**
	 * Constructor.
	 *
	 * @param string|null $repo Optional override for "owner/repo".
	 */
	public function __construct( ?string $repo = null ) {
		$this->repo = trim( (string) ( $repo ?? ( defined( 'HIQB_GITHUB_REPO' ) ? HIQB_GITHUB_REPO : '' ) ), "/ \t\n\r\0\x0B" );
		$dir        = dirname( HIQB_PLUGIN_BASENAME );
		$this->slug = ( '.' !== $dir && '' !== $dir ) ? $dir : 'hi-quick-buy';
	}

	/**
	 * Register WordPress update hooks.
	 */
	public function register(): void {
		if ( '' === $this->repo || ! str_contains( $this->repo, '/' ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_info' ), 20, 3 );
		add_filter( 'http_request_args', array( $this, 'inject_auth_headers' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_extracted_directory' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache_after_upgrade' ), 10, 2 );

		if ( is_admin() ) {
			add_filter( 'plugin_row_meta', array( $this, 'add_check_update_link' ), 10, 2 );
			add_action( 'admin_post_hiqb_check_update', array( $this, 'handle_manual_check' ) );
			add_action( 'admin_notices', array( $this, 'render_manual_check_notice' ) );
		}
	}

	/**
	 * Inject update info into WordPress update_plugins transient.
	 *
	 * @param mixed $transient Update transient object.
	 * @return mixed
	 */
	public function check_for_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) || empty( $release['version'] ) ) {
			return $transient;
		}

		$update_obj = (object) array(
			'id'           => 'https://github.com/' . $this->repo,
			'slug'         => $this->slug,
			'plugin'       => HIQB_PLUGIN_BASENAME,
			'new_version'  => $release['version'],
			'url'          => $release['html_url'],
			'package'      => $release['package'],
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '8.1',
			'requires'     => '6.9',
		);

		if ( version_compare( $release['version'], HIQB_VERSION, '>' ) && '' !== $release['package'] ) {
			$transient->response[ HIQB_PLUGIN_BASENAME ] = $update_obj;
			unset( $transient->no_update[ HIQB_PLUGIN_BASENAME ] );
		} else {
			$transient->no_update[ HIQB_PLUGIN_BASENAME ] = $update_obj;
			unset( $transient->response[ HIQB_PLUGIN_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Populate the "View version X.Y.Z details" modal in WordPress Admin.
	 *
	 * @param mixed  $result Default plugins_api result.
	 * @param string $action Requested API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function filter_plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$requested_slug = isset( $args->slug ) ? (string) $args->slug : '';
		if ( $requested_slug !== $this->slug && 'hi-quick-buy' !== $requested_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) || empty( $release['version'] ) ) {
			return $result;
		}

		$changelog = '' !== $release['changelog']
			? nl2br( esc_html( $release['changelog'] ) )
			: esc_html__( 'Cập nhật từ GitHub Release.', 'hi-quick-buy' );

		return (object) array(
			'name'          => 'HI Quick-Buy',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( $this->repo ) . '" target="_blank" rel="noopener noreferrer">HI Quick-Buy Team</a>',
			'homepage'      => 'https://github.com/' . $this->repo,
			'requires'      => '6.9',
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '8.1',
			'last_updated'  => $release['published_at'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Thêm nút Mua nhanh và popup đặt hàng gọn nhẹ cho WooCommerce. Hỗ trợ sản phẩm biến thể, sản phẩm giá Liên hệ, chọn địa chỉ Việt Nam, tính phí giao hàng và chuyển khoản mã QR.', 'hi-quick-buy' ),
				'changelog'   => wp_kses_post( $changelog ),
			),
		);
	}

	/**
	 * Attach GitHub authentication headers strictly to requests targeting our GitHub repo.
	 *
	 * @param array<string, mixed> $args HTTP request arguments.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function inject_auth_headers( array $args, string $url ): array {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( 'api.github.com' !== $host && 'github.com' !== $host ) {
			return $args;
		}

		$repo_lower = strtolower( $this->repo );
		$path_lower = strtolower( $path );

		if ( ! str_contains( $path_lower, '/' . $repo_lower . '/' ) ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$token = $this->get_github_token();
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// GitHub Private Release Asset downloads require Accept: application/octet-stream.
		if ( 'api.github.com' === $host && str_contains( $path_lower, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		$args['headers']['User-Agent'] = 'HI-Quick-Buy/' . HIQB_VERSION;

		return $args;
	}

	/**
	 * Ensure the extracted ZIP directory matches the installed plugin folder name
	 * (prevents GitHub zipball "owner-repo-commithash/" from deactivating the plugin).
	 *
	 * @param string|WP_Error      $source        File source location.
	 * @param string               $remote_source Remote file source location.
	 * @param WP_Upgrader          $upgrader      WP_Upgrader instance.
	 * @param array<string, mixed> $hook_extra    Extra arguments passed to hooked filters.
	 * @return string|WP_Error
	 */
	public function normalize_extracted_directory( string|WP_Error $source, string $remote_source, WP_Upgrader $upgrader, array $hook_extra ): string|WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$is_our_plugin = ( isset( $hook_extra['plugin'] ) && HIQB_PLUGIN_BASENAME === $hook_extra['plugin'] );
		if ( ! $is_our_plugin ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$trimmed_source   = untrailingslashit( $source );
		$current_dir_name = basename( $trimmed_source );

		if ( $current_dir_name === $this->slug ) {
			return $source;
		}

		$corrected_source = trailingslashit( dirname( $trimmed_source ) ) . $this->slug;

		if ( $wp_filesystem->exists( $corrected_source ) ) {
			$wp_filesystem->delete( $corrected_source, true );
		}

		if ( $wp_filesystem->move( $trimmed_source, $corrected_source, true ) ) {
			return trailingslashit( $corrected_source );
		}

		return new WP_Error(
			'hiqb_rename_failed',
			__( 'Không thể chuẩn hóa thư mục plugin sau khi giải nén từ GitHub.', 'hi-quick-buy' )
		);
	}

	/**
	 * Clear cached release data immediately after the plugin is upgraded.
	 *
	 * @param WP_Upgrader          $upgrader   Upgrader instance.
	 * @param array<string, mixed> $hook_extra Upgrade metadata.
	 */
	public function purge_cache_after_upgrade( WP_Upgrader $upgrader, array $hook_extra ): void {
		if (
			isset( $hook_extra['action'], $hook_extra['type'] )
			&& 'update' === $hook_extra['action']
			&& 'plugin' === $hook_extra['type']
		) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Add a "Kiểm tra cập nhật" link to the plugin row on Plugins screen.
	 *
	 * @param array<int, string> $links Existing row meta links.
	 * @param string             $file  Plugin basename.
	 * @return array<int, string>
	 */
	public function add_check_update_link( array $links, string $file ): array {
		if ( HIQB_PLUGIN_BASENAME !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$check_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=hiqb_check_update' ),
			'hiqb_check_update'
		);

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $check_url ),
			esc_html__( 'Kiểm tra cập nhật', 'hi-quick-buy' )
		);

		return $links;
	}

	/**
	 * Handle manual "Check for updates" action securely.
	 */
	public function handle_manual_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền kiểm tra cập nhật plugin.', 'hi-quick-buy' ), 403 );
		}

		check_admin_referer( 'hiqb_check_update' );

		delete_transient( self::CACHE_KEY );
		$release = $this->get_latest_release( true );

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$status = 'up_to_date';
		$ver    = HIQB_VERSION;

		if ( null === $release ) {
			$status = 'error';
		} elseif ( version_compare( $release['version'], HIQB_VERSION, '>' ) ) {
			$status = 'available';
			$ver    = $release['version'];
		}

		$redirect_url = add_query_arg(
			array(
				'hiqb_update_check' => $status,
				'hiqb_remote_ver'   => $ver,
			),
			admin_url( 'plugins.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Display admin notice after manual update check on Plugins screen.
	 */
	public function render_manual_check_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag after redirect.
		$status = isset( $_GET['hiqb_update_check'] ) ? sanitize_key( wp_unslash( (string) $_GET['hiqb_update_check'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display string after redirect.
		$remote_ver = isset( $_GET['hiqb_remote_ver'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['hiqb_remote_ver'] ) ) : '';

		if ( 'available' === $status ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p><strong>%1$s:</strong> %2$s</p></div>',
				esc_html__( 'HI Quick-Buy', 'hi-quick-buy' ),
				esc_html(
					sprintf(
						/* translators: %s: new version number */
						__( 'Đã có phiên bản mới %s trên GitHub. Bạn có thể bấm "Cập nhật ngay" ở bên dưới.', 'hi-quick-buy' ),
						$remote_ver
					)
				)
			);
		} elseif ( 'up_to_date' === $status ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%1$s:</strong> %2$s</p></div>',
				esc_html__( 'HI Quick-Buy', 'hi-quick-buy' ),
				esc_html(
					sprintf(
						/* translators: %s: current version number */
						__( 'Bạn đang dùng phiên bản mới nhất (%s).', 'hi-quick-buy' ),
						HIQB_VERSION
					)
				)
			);
		} elseif ( 'error' === $status ) {
			$has_token = '' !== $this->get_github_token();
			$hint      = $has_token
				? __( 'Không thể kết nối GitHub Releases (vui lòng kiểm tra HIQB_GITHUB_TOKEN hoặc đã tạo ít nhất 1 Release trên GitHub).', 'hi-quick-buy' )
				: __( 'Không thể đọc GitHub Release. Với Private Repository, vui lòng khai báo HIQB_GITHUB_TOKEN trong wp-config.php.', 'hi-quick-buy' );

			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%1$s:</strong> %2$s</p></div>',
				esc_html__( 'HI Quick-Buy', 'hi-quick-buy' ),
				esc_html( $hint )
			);
		}
	}

	/**
	 * Fetch and normalize latest GitHub release metadata (with transient caching).
	 *
	 * @param bool $force_refresh Bypass transient cache.
	 * @return array<string, string>|null
	 */
	public function get_latest_release( bool $force_refresh = false ): ?array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['error'] ) ? null : $cached;
			}
		}

		$api_url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'HI-Quick-Buy/' . HIQB_VERSION,
		);

		$token = $this->get_github_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::CACHE_KEY, array( 'error' => true ), self::ERROR_TTL );
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			set_transient( self::CACHE_KEY, array( 'error' => true ), self::ERROR_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, array( 'error' => true ), self::ERROR_TTL );
			return null;
		}

		$release = $this->parse_release_payload( $body );
		if ( null === $release ) {
			set_transient( self::CACHE_KEY, array( 'error' => true ), self::ERROR_TTL );
			return null;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Normalize a GitHub Release API payload into plugin update metadata.
	 *
	 * @param array<string, mixed> $payload Decoded GitHub API JSON.
	 * @return array<string, string>|null
	 */
	public function parse_release_payload( array $payload ): ?array {
		$raw_tag = isset( $payload['tag_name'] ) ? trim( (string) $payload['tag_name'] ) : '';
		$version = ltrim( $raw_tag, 'vV' );

		if ( '' === $version || ! preg_match( '/^\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}

		$has_token = '' !== $this->get_github_token();
		$package   = '';

		// Prefer an explicitly attached .zip release asset (built cleanly without dev/CSV files).
		if ( ! empty( $payload['assets'] ) && is_array( $payload['assets'] ) ) {
			foreach ( $payload['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
					continue;
				}
				if ( str_ends_with( strtolower( (string) $asset['name'] ), '.zip' ) ) {
					// On Private repos, browser_download_url returns 404; use API asset URL with octet-stream header.
					$candidate = $has_token
						? (string) ( $asset['url'] ?? '' )
						: (string) ( $asset['browser_download_url'] ?? '' );

					if ( $this->is_allowed_github_url( $candidate ) ) {
						$package = $candidate;
						break;
					}
				}
			}
		}

		// Fallback to GitHub auto-generated zipball_url.
		if ( '' === $package && ! empty( $payload['zipball_url'] ) ) {
			$zipball = (string) $payload['zipball_url'];
			if ( $this->is_allowed_github_url( $zipball ) ) {
				$package = $zipball;
			}
		}

		return array(
			'version'      => $version,
			'tag_name'     => $raw_tag,
			'package'      => $package,
			'html_url'     => isset( $payload['html_url'] ) ? esc_url_raw( (string) $payload['html_url'] ) : 'https://github.com/' . $this->repo,
			'published_at' => isset( $payload['published_at'] ) ? sanitize_text_field( (string) $payload['published_at'] ) : '',
			'changelog'    => isset( $payload['body'] ) ? (string) $payload['body'] : '',
		);
	}

	/**
	 * Return GitHub Personal Access Token from wp-config.php constant if configured.
	 */
	private function get_github_token(): string {
		if ( defined( 'HIQB_GITHUB_TOKEN' ) && is_string( HIQB_GITHUB_TOKEN ) ) {
			return trim( HIQB_GITHUB_TOKEN );
		}

		return '';
	}

	/**
	 * Verify a download URL belongs strictly to GitHub over HTTPS.
	 */
	private function is_allowed_github_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return 'https' === $scheme && in_array(
			$host,
			array( 'api.github.com', 'github.com', 'objects.githubusercontent.com' ),
			true
		);
	}
}

