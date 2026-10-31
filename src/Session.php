<?php
/** Strong guest cookie plus session bound CSRF. @package MagaudevDonations */
namespace MagaudevDonations;

final class Session {
	public const COOKIE = 'mgdd_session';
	public static function origin( \WP_REST_Request $request ): void {
		$origin   = (string) $request->get_header( 'origin' );
		$home     = wp_parse_url( home_url( '/' ) );
		$expected = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( ! hash_equals( $expected, $origin ) || ! str_starts_with( strtolower( (string) $request->get_header( 'content-type' ) ), 'application/json' ) ) {
			throw new Fault( 'mgdd_origin', esc_html__( 'Asal permintaan tidak valid. Muat ulang halaman donasi.', 'magaudev-donations' ), 403 );
		}
	}
	public static function hash(): string {
		$raw = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		return preg_match( '/^[a-f0-9]{64}$/D', $raw ) ? hash( 'sha256', $raw ) : '';
	}
	public static function current(): ?array {
		$hash = self::hash();
		return $hash ? Db::wp()->get_row( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'sessions' ) . ' WHERE hash=%s AND expires_at>%s', $hash, Db::now() ), ARRAY_A ) : null;
	}
	public static function bootstrap( \WP_REST_Request $request ): array {
		self::origin( $request );
		Limiter::enforce( 'session', 20 );
		$session = self::current();
		if ( ! $session ) {
			$raw  = bin2hex( random_bytes( 32 ) );
			$hash = hash( 'sha256', $raw );
			Db::insert(
				'sessions',
				[
					'hash'       => $hash,
					'user_id'    => get_current_user_id(),
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
					'created_at' => Db::now(),
				]
			);
			setcookie(
				self::COOKIE,
				$raw,
				[
					'expires'  => time() + 30 * DAY_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
			$_COOKIE[ self::COOKIE ] = $raw;
		}
		return [
			'csrf'   => self::csrf(),
			'member' => is_user_logged_in(),
		];
	}
	public static function csrf(): string {
		return hash_hmac( 'sha256', self::hash() . '|csrf', wp_salt( 'nonce' ) );
	}
	public static function guard( \WP_REST_Request $request ): void {
		self::origin( $request );
		if ( ! self::current() || ! hash_equals( self::csrf(), (string) $request->get_header( 'x-mgdd-csrf' ) ) ) {
			throw new Fault( 'mgdd_csrf', esc_html__( 'Sesi kedaluwarsa. Muat ulang lalu coba lagi.', 'magaudev-donations' ), 403 );
		}
	}
	public static function scope(): string {
		return hash( 'sha256', get_current_user_id() ? 'member:' . get_current_user_id() : 'guest:' . self::hash() );
	}
	public static function owns( array $intent ): bool {
		$donor = Db::wp()->get_row( Db::wp()->prepare( 'SELECT user_id,session_hash FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $intent['donor_id'] ), ARRAY_A );
		if ( ! $donor ) {
			return false;
		}
		if ( $donor['user_id'] ) {
			return get_current_user_id() > 0 && (int) $donor['user_id'] === get_current_user_id();
		}
		return self::current() && hash_equals( (string) $donor['session_hash'], self::hash() );
	}
}
