<?php
/** Atomic fixed window limiter. @package MagaudevDonations */
namespace MagaudevDonations;

final class Limiter {
	public static function take( string $scope, string $identity, int $maximum, int $seconds = 60 ): bool {
		$window = intdiv( time(), $seconds );
		$key    = hash_hmac( 'sha256', $scope . '|' . $identity . '|' . $window, wp_salt( 'nonce' ) );
		$db     = Db::wp();
		$table  = Db::table( 'limits' );
		Db::query( $db->prepare( "INSERT INTO $table (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)", $key, gmdate( 'Y-m-d H:i:s', ( $window + 1 ) * $seconds ) ) );
		// Reading after the atomic increment can only reject more conservatively.
		return (int) $db->get_var( $db->prepare( "SELECT hits FROM $table WHERE bucket=%s", $key ) ) <= $maximum;
	}
	public static function enforce( string $scope, int $maximum, int $seconds = 60, ?string $identity = null ): void {
		if ( ! self::take( $scope, $identity ?? Ip::hash(), $maximum, $seconds ) ) {
			if ( self::take( 'log_abuse', Ip::hash(), 1, 300 ) ) {
				Audit::log( 'security', 'rate.rejected', 0, '', false, 'warning' );
			}
			throw new Fault( 'mgdd_rate', esc_html__( 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.', 'magaudev-donations' ), 429 );
		}
	}
}
