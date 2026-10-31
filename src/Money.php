<?php
/** Integer IDR only. @package MagaudevDonations */
namespace MagaudevDonations;

final class Money {
	public static function integer( mixed $value, int $minimum = 0, int $maximum = 1000000000000 ): int {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^(0|[1-9][0-9]{0,12})$/D', (string) $value ) ) {
			throw new Fault( 'mgdd_amount', esc_html__( 'Nominal harus berupa rupiah bulat.', 'magaudev-donations' ) );
		}
		$number = (int) $value;
		if ( $number < $minimum || $number > $maximum ) {
			throw new Fault( 'mgdd_amount', esc_html__( 'Nominal di luar batas yang diizinkan.', 'magaudev-donations' ) );
		}
		return $number;
	}
	public static function gateway( mixed $value ): int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new Fault( 'mgdd_gateway_amount', esc_html__( 'Nominal gateway tidak valid.', 'magaudev-donations' ), 502 );
		}
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,12})(?:\.00)?$/D', (string) $value, $match ) ) {
			throw new Fault( 'mgdd_gateway_amount', esc_html__( 'Presisi nominal gateway tidak valid.', 'magaudev-donations' ), 502 );
		}
		return self::integer( $match[1] );
	}
}
