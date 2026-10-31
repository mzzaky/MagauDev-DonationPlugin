<?php
/** Authenticated encryption; no plaintext fallback for IP. @package MagaudevDonations */
namespace MagaudevDonations;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary authenticated encryption envelope and server key material, never executable content.

final class Crypto {
	public static function ip_ready(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'MGDD_IP_KEY_ID' ) && defined( 'MGDD_IP_KEYS' ) && isset( MGDD_IP_KEYS[ MGDD_IP_KEY_ID ] ) && 32 === strlen( (string) base64_decode( MGDD_IP_KEYS[ MGDD_IP_KEY_ID ], true ) );
	}
	public static function seal( string $text, bool $ip = false ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ( $ip && ! self::ip_ready() ) ) {
			if ( $ip ) {
				return null;
			}
			throw new Fault( 'mgdd_crypto', esc_html__( 'Ekstensi sodium diperlukan untuk checkout.', 'magaudev-donations' ), 503 );
		}
		$id    = $ip ? (string) MGDD_IP_KEY_ID : 'snap';
		$key   = $ip ? base64_decode( MGDD_IP_KEYS[ $id ], true ) : hash( 'sha256', wp_salt( 'secure_auth' ) . '|mgdd_snap', true );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return $id . ':' . base64_encode( $nonce . sodium_crypto_secretbox( $text, $nonce, $key ) );
	}
	public static function open( ?string $blob, bool $ip = false ): ?string {
		if ( ! $blob || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		[ $id, $encoded ] = array_pad( explode( ':', $blob, 2 ), 2, '' );
		$key              = $ip ? ( defined( 'MGDD_IP_KEYS' ) ? base64_decode( MGDD_IP_KEYS[ $id ] ?? '', true ) : false ) : hash( 'sha256', wp_salt( 'secure_auth' ) . '|mgdd_snap', true );
		$data             = base64_decode( $encoded, true );
		if ( ! $data || ! $key || 32 !== strlen( $key ) || strlen( $data ) < 40 ) {
			return null;
		}
		$result = sodium_crypto_secretbox_open( substr( $data, 24 ), substr( $data, 0, 24 ), $key );
		return false === $result ? null : $result;
	}
}
