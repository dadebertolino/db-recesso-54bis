<?php
/**
 * Request guard for the guest shortcodes ([dbr54_recesso_guest],
 * [dbr54_garanzia_guest]).
 *
 * Logged-in users → nonce (their pages are not cached, nonce is session-bound).
 *
 * Anonymous visitors → NO nonce. The lookup form lives on a public page that
 * full-page caches (WP Rocket, LiteSpeed, Cloudflare APO, ...) keep serving
 * with a nonce expired after 12–24h: every submission then died on
 * check_admin_referer() and the consumer could no longer exercise the right.
 * For user 0 the nonce is identical for every visitor anyway, so it protected
 * nothing. It is replaced by a same-site check on Origin/Referer and a per-IP
 * rate limit (salted hash, never stored in clear) that also blocks enumeration
 * of order number + email pairs.
 *
 * Pattern: DBCM_Consent_API::verify_consent_request() (DB Cookie Manager 3.7.1).
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Guest_Guard {

	/** Default max guest POSTs per IP in the window (filter: dbr54_guest_rate_limit). */
	const RATE_LIMIT = 10;

	/** Window in seconds (filter: dbr54_guest_rate_window). */
	const RATE_WINDOW = 900;

	/**
	 * Verify a guest-flow POST.
	 *
	 * @param string $nonce_action Nonce action (checked for logged-in users only).
	 * @param string $nonce_field  POST field carrying the nonce.
	 * @return string Empty string if the request is valid, otherwise a
	 *                user-facing error message to display.
	 */
	public static function verify( string $nonce_action, string $nonce_field ): string {
		if ( is_user_logged_in() ) {
			$nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
				return __( 'La sessione è scaduta. Ricarica la pagina e riprova.', 'db-recesso-54bis' );
			}
			return '';
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- confrontati solo come host via wp_parse_url().
		$origin = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = wp_unslash( $_SERVER['HTTP_ORIGIN'] );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = wp_unslash( $_SERVER['HTTP_REFERER'] );
		}
		// phpcs:enable

		if ( ! self::origin_matches( $origin, array( home_url(), site_url() ) ) ) {
			return __( 'Richiesta non valida: invia il modulo dalla pagina del sito. Ricarica la pagina e riprova.', 'db-recesso-54bis' );
		}

		if ( self::is_rate_limited() ) {
			return __( 'Troppi tentativi in poco tempo. Attendi qualche minuto e riprova.', 'db-recesso-54bis' );
		}

		return '';
	}

	/**
	 * Print the nonce field only for logged-in users: for anonymous visitors
	 * it would end up in cached public HTML (see class docblock).
	 */
	public static function nonce_field( string $action, string $name ): void {
		if ( is_user_logged_in() ) {
			wp_nonce_field( $action, $name );
		}
	}

	/**
	 * Belt-and-braces: ask page caches not to store the page carrying a guest
	 * form. DONOTCACHEPAGE is honoured by WP Rocket, W3TC, WP Super Cache,
	 * LiteSpeed; headers are sent only if still possible.
	 */
	public static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- costante standard dei plugin di cache.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * On template_redirect (headers not sent yet): disable page caching for
	 * singular content carrying the given shortcode.
	 */
	public static function no_cache_if_shortcode( string $tag ): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, $tag ) ) {
			self::no_cache();
		}
	}

	/**
	 * True if the host of $origin matches the host of one of the site URLs.
	 *
	 * Host-only, case-insensitive comparison (scheme/port may differ behind
	 * proxies). Empty or 'null' origin → false.
	 *
	 * @param string $origin    Origin or Referer header value.
	 * @param array  $site_urls Site URLs (home_url, site_url).
	 */
	public static function origin_matches( $origin, $site_urls ): bool {
		$origin = is_string( $origin ) ? trim( $origin ) : '';
		if ( '' === $origin || 'null' === $origin ) {
			return false;
		}
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		foreach ( (array) $site_urls as $url ) {
			$site_host = wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( is_string( $site_host ) && strtolower( $site_host ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Per-IP rate limit (salted hash, never in clear) via transient.
	 *
	 * @return bool True if the request exceeds the limit.
	 */
	private static function is_rate_limited(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validato da FILTER_VALIDATE_IP.
		if ( ! $ip ) {
			return false;
		}
		$limit = (int) apply_filters( 'dbr54_guest_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return false;
		}
		$window = max( 60, (int) apply_filters( 'dbr54_guest_rate_window', self::RATE_WINDOW ) );
		$key    = 'dbr54_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 32 );
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}
}
