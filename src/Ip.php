<?php
/** Peer/proxy trust with IPv4 and IPv6 CIDR. @package MagaudevDonations */
namespace MagaudevDonations;

final class Ip {
	public static function valid_cidr( mixed $cidr ): bool {
		if ( ! is_string( $cidr ) ) {
			return false;
		}
		[ $ip, $bits ] = array_pad( explode( '/', $cidr ), 2, null );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ( null === $bits || ( ctype_digit( $bits ) && (int) $bits <= ( str_contains( $ip, ':' ) ? 128 : 32 ) ) );
	}
	public static function contains( string $ip, string $cidr ): bool {
		if ( ! self::valid_cidr( $cidr ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		[ $network, $bits ] = array_pad( explode( '/', $cidr ), 2, null );
		$a                  = inet_pton( $ip );
		$b                  = inet_pton( $network );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits  = null === $bits ? strlen( $a ) * 8 : (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$tail  = $bits % 8;
		return substr( $a, 0, $bytes ) === substr( $b, 0, $bytes ) && ( ! $tail || ( ord( $a[ $bytes ] ) & ( 255 << ( 8 - $tail ) ) ) === ( ord( $b[ $bytes ] ) & ( 255 << ( 8 - $tail ) ) ) );
	}
	public static function resolve( string $peer, string $forwarded = '', array $trusted = [] ): string {
		if ( ! filter_var( $peer, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}
		$list    = array_map( 'trim', explode( ',', substr( $forwarded, 0, 2048 ) ) );
		$current = $peer;
		while ( $list && array_filter( $trusted, static fn( $cidr ) => self::contains( $current, $cidr ) ) ) {
			$next = array_pop( $list );
			if ( ! filter_var( $next, FILTER_VALIDATE_IP ) ) {
				break;
			}
			$current = $next;
		}
		return inet_ntop( inet_pton( $current ) );
	}
	public static function current(): string {
		return self::resolve( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ), Config::all()['trusted_proxies'] );
	}
	public static function hash( ?string $ip = null ): string {
		return hash_hmac( 'sha256', $ip ?? self::current(), wp_salt( 'auth' ) . '|mgdd_ip' );
	}
	public static function mask( string $ip ): string {
		if ( str_contains( $ip, ':' ) ) {
			return implode( ':', array_slice( explode( ':', inet_ntop( inet_pton( $ip ) ) ), 0, 2 ) ) . ':…';
		}
		return preg_replace( '/\.[0-9]+$/', '.xxx', $ip );
	}
}
