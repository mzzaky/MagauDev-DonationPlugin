<?php
/** Midtrans Snap and status through WP HTTP API only. @package MagaudevDonations */
namespace MagaudevDonations;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Midtrans HTTP Basic authentication requires base64 encoding.

final class Gateway {
	public static function host( string $environment, bool $snap = false ): string {
		return 'https://' . ( $snap ? 'app' : 'api' ) . ( 'sandbox' === $environment ? '.sandbox' : '' ) . '.midtrans.com';
	}
	public static function request( array $attempt, string $path, ?array $body = null, bool $snap = false ): array {
		$key = Config::env( $attempt['environment'], 'server_key' );
		if ( ! $key || ! hash_equals( Config::env( $attempt['environment'], 'merchant_id' ), $attempt['merchant'] ) ) {
			throw new Fault( 'mgdd_gateway_config', esc_html__( 'Konfigurasi merchant untuk transaksi ini belum tersedia.', 'magaudev-donations' ), 503 );
		}
		$response = wp_remote_request(
			self::host( $attempt['environment'], $snap ) . $path,
			[
				'method'              => null === $body ? 'GET' : 'POST',
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 131072,
				'headers'             => [
					'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'                => null === $body ? null : wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new Fault( 'mgdd_gateway_unknown', esc_html__( 'Gateway belum merespons. Status akan diperiksa kembali; jangan membuat pembayaran pengganti.', 'magaudev-donations' ), 503 );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 404 === $code || ( is_array( $data ) && '404' === (string) ( $data['status_code'] ?? '' ) ) ) {
			return [ 'not_found' => true ];
		}
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			throw new Fault( 'mgdd_gateway_unknown', esc_html__( 'Hasil gateway belum dapat dipastikan. Periksa kembali status transaksi yang sama.', 'magaudev-donations' ), 503 );
		}
		return $data;
	}
	public static function create( array $attempt, array $intent, array $donor ): array {
		$c    = Config::all();
		$data = self::request(
			$attempt,
			'/snap/v1/transactions',
			[
				'transaction_details' => [
					'order_id'     => $attempt['order_id'],
					'gross_amount' => (int) $attempt['amount'],
				],
				'item_details'        => [
					[
						'id'       => 'donation',
						'price'    => (int) $attempt['amount'],
						'quantity' => 1,
						'name'     => 'Dukungan magaudev',
					],
				],
				'customer_details'    => [
					'first_name' => $intent['alias'],
					'email'      => $donor['email'],
				],
				'enabled_payments'    => $c['channels'],
				'callbacks'           => [ 'finish' => home_url( '/donasi/status/' ) ],
				'expiry'              => [
					'unit'     => 'hours',
					'duration' => 24,
				],
			],
			true
		);
		if ( empty( $data['token'] ) || ! is_string( $data['token'] ) || strlen( $data['token'] ) > 128 || ! self::redirect_valid( $data['redirect_url'] ?? '', $attempt['environment'] ) ) {
			throw new Fault( 'mgdd_gateway_unknown', esc_html__( 'Sesi pembayaran belum dapat dipastikan.', 'magaudev-donations' ), 503 );
		}
		return [
			'token'        => $data['token'],
			'redirect_url' => $data['redirect_url'],
		];
	}
	public static function redirect_valid( mixed $url, string $env ): bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return $parts && 'https' === ( $parts['scheme'] ?? '' ) && ( 'sandbox' === $env ? 'app.sandbox.midtrans.com' : 'app.midtrans.com' ) === ( $parts['host'] ?? '' ) && ! isset( $parts['user'], $parts['pass'] ) && ! isset( $parts['port'] ) && str_starts_with( $parts['path'] ?? '', '/snap/' );
	}
	public static function status( array $attempt ): array {
		return self::request( $attempt, '/v2/' . rawurlencode( $attempt['order_id'] ) . '/status' );
	}
	public static function signature( array $payload, array $attempt ): bool {
		foreach ( [ 'order_id', 'status_code', 'gross_amount', 'signature_key' ] as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_string( $payload[ $key ] ) ) {
				return false;
			}
		}
		$key = Config::env( $attempt['environment'], 'server_key' );
		return '' !== $key && hash_equals( hash( 'sha512', $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . $key ), $payload['signature_key'] );
	}
}
