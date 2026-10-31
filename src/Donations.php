<?php
/** Intent reservations, ownership and retry. @package MagaudevDonations */
namespace MagaudevDonations;

final class Donations {
	public static function validate( array $input ): array {
		$c      = Config::all();
		$amount = Money::integer( $input['amount'] ?? null, $c['minimum'], $c['maximum'] );
		$name   = mb_substr( sanitize_text_field( $input['name'] ?? '' ), 0, 100 );
		$email  = sanitize_email( $input['email'] ?? '' );
		if ( '' === trim( $name ) || ! is_email( $email ) || strlen( $email ) > 254 ) {
			throw new Fault( 'mgdd_identity', esc_html__( 'Nama dan email yang valid diperlukan.', 'magaudev-donations' ) );
		}
		foreach ( [ 'public_name', 'public_message' ] as $key ) {
			if ( isset( $input[ $key ] ) && ! is_bool( $input[ $key ] ) ) {
				throw new Fault( 'mgdd_consent', esc_html__( 'Persetujuan tidak valid.', 'magaudev-donations' ) );
			}
		}
		return [
			'amount'         => $amount,
			'alias'          => $name,
			'email'          => strtolower( $email ),
			'message'        => mb_substr( sanitize_textarea_field( $input['message'] ?? '' ), 0, 500 ),
			'public_name'    => (int) ( $input['public_name'] ?? false ),
			'public_message' => (int) ( $input['public_message'] ?? false ),
		];
	}
	public static function checkout( array $input ): array {
		if ( ! Config::readiness()['accepting'] || apply_filters( 'mgdd_maintenance', false ) ) {
			throw new Fault( 'mgdd_paused', esc_html__( 'Penerimaan donasi sedang dijeda atau konfigurasi belum siap.', 'magaudev-donations' ), 503 );
		}
		if ( ! Session::current() ) {
			throw new Fault( 'mgdd_session', esc_html__( 'Sesi tidak tersedia.', 'magaudev-donations' ), 403 );
		}
		Security::checkout( $input );
		$clean = self::validate( $input );
		$key   = $input['idempotency_key'] ?? '';
		if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $key ) ) {
			throw new Fault( 'mgdd_idempotency', esc_html__( 'Kunci permintaan tidak valid.', 'magaudev-donations' ) );
		}
		$hash                         = hash( 'sha256', wp_json_encode( $clean ) );
		$scope                        = Session::scope();
		$key_hash                     = hash( 'sha256', $key );
		$reserved                     = Db::lock(
			'checkout:' . $scope . $key_hash,
			static function () use ( $clean, $hash, $scope, $key_hash ): array {
				return Db::tx(
					static function () use ( $clean, $hash, $scope, $key_hash ): array {
						$db       = Db::wp();
						$existing = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'intents' ) . ' WHERE scope_hash=%s AND idempotency_hash=%s', $scope, $key_hash ), ARRAY_A );
						if ( $existing ) {
							if ( ! hash_equals( $existing['payload_hash'], $hash ) ) {
								throw new Fault( 'mgdd_conflict', esc_html__( 'Kunci permintaan sudah dipakai dengan isi berbeda.', 'magaudev-donations' ), 409 );
							}
							return [ $existing, null, null ];
						}
						$donor = Identity::donor( $clean['email'], $clean['alias'] );
						$data  = $clean;
						unset( $data['email'] );
						$data      += [
							'reference'        => bin2hex( random_bytes( 16 ) ),
							'donor_id'         => $donor['id'],
							'scope_hash'       => $scope,
							'idempotency_hash' => $key_hash,
							'payload_hash'     => $hash,
							'created_at'       => Db::now(),
						];
						$data['id'] = Db::insert( 'intents', $data );
						$attempt    = self::reserve( $data, 1 );
						Audit::log( 'visitor', 'checkout.reserved', $attempt['id'] );
						return [ $data, $attempt, $donor ];
					}
				);
			}
		);
		[ $intent, $attempt, $donor ] = $reserved;
		if ( $attempt ) {
			self::create_session( $attempt, $intent, $donor );
		}
		return self::status( $intent['reference'] );
	}
	public static function reserve( array $intent, int $number ): array {
		$c          = Config::all();
		$data       = [
			'intent_id'   => $intent['id'],
			'environment' => $c['environment'],
			'merchant'    => Config::env( $c['environment'], 'merchant_id' ),
			'order_id'    => 'mgdd-' . $intent['reference'] . '-' . $number,
			'status'      => 'creating',
			'amount'      => $intent['amount'],
			'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'next_sync'   => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			'created_at'  => Db::now(),
			'updated_at'  => Db::now(),
		];
		$data['id'] = Db::insert( 'attempts', $data );
		return $data;
	}
	public static function create_session( array $attempt, array $intent, array $donor ): void {
		try {
			$snap = Gateway::create( $attempt, $intent, $donor );
			// A webhook could arrive first. Never overwrite a verified financial state.
			Db::tx(
				static function () use ( $attempt, $snap ): void {
					$db      = Db::wp();
					$current = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE id=%d FOR UPDATE', $attempt['id'] ), ARRAY_A );
					Db::update(
						'attempts',
						[
							'snap_secret' => Crypto::seal( wp_json_encode( $snap ) ),
							'status'      => 'creating' === $current['status'] ? 'created' : $current['status'],
							'updated_at'  => Db::now(),
						],
						[ 'id' => $attempt['id'] ]
					);
				}
			);
		} catch ( \Throwable $e ) {
			$db = Db::wp();
			Db::query( $db->prepare( 'UPDATE ' . Db::table( 'attempts' ) . " SET status='unknown',updated_at=%s WHERE id=%d AND status='creating'", Db::now(), $attempt['id'] ) );
			Audit::log( 'payment', 'session.unknown', $attempt['id'], '', true, 'warning' );
		}
	}
	public static function intent( string $reference, bool $owner = true ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $reference ) ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Donasi tidak ditemukan atau bukan milik sesi ini.', 'magaudev-donations' ), 404 );
		}
		$intent = Db::wp()->get_row( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'intents' ) . ' WHERE reference=%s', $reference ), ARRAY_A );
		if ( ! $intent || ( $owner && ! Session::owns( $intent ) ) ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Donasi tidak ditemukan atau bukan milik sesi ini.', 'magaudev-donations' ), 404 );
		}
		return $intent;
	}
	public static function latest( int $intent ): array {
		$row = Db::wp()->get_row( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE intent_id=%d ORDER BY id DESC LIMIT 1', $intent ), ARRAY_A );
		return is_array( $row ) ? $row : [];
	}
	public static function status( string $reference, bool $sync = false ): array {
		$intent  = self::intent( $reference );
		$attempt = self::latest( (int) $intent['id'] );
		if ( $sync ) {
			Limiter::enforce( 'sync', 8, 60, Session::scope() );
			$attempt = Payments::sync( $attempt );
		}
		$snap = null;
		if ( in_array( $attempt['status'], [ 'created', 'pending' ], true ) && $attempt['expires_at'] > Db::now() ) {
			$secret = Crypto::open( $attempt['snap_secret'] );
			$snap   = $secret ? json_decode( $secret, true ) : null;
		}
		return [
			'reference'      => $reference,
			'amount'         => (int) $intent['amount'],
			'status'         => $attempt['status'],
			'environment'    => $attempt['environment'],
			'method'         => $attempt['method'],
			'paid_at'        => $attempt['paid_at'],
			'refunded'       => (int) $attempt['refunded'],
			'public_name'    => (bool) $intent['public_name'],
			'public_message' => (bool) $intent['public_message'],
			'snap'           => $snap,
			'client_key'     => $snap ? Config::env( $attempt['environment'], 'client_key' ) : '',
			'snap_script'    => $snap ? Gateway::host( $attempt['environment'], true ) . '/snap/snap.js' : '',
			'can_retry'      => in_array( $attempt['status'], [ 'failed', 'expired', 'cancelled' ], true ) && ! $attempt['gross'],
			'updated_at'     => $attempt['updated_at'],
		];
	}
	public static function retry( string $reference ): array {
		$intent = self::intent( $reference );
		if ( ! Config::readiness()['accepting'] ) {
			throw new Fault( 'mgdd_paused', esc_html__( 'Donasi baru sedang dijeda.', 'magaudev-donations' ), 503 );
		}
		Limiter::enforce( 'retry', 3, 300, Session::scope() );
		$previous = self::latest( (int) $intent['id'] );
		Payments::sync( $previous, 'retry' );
		$attempt = Db::lock(
			'intent:' . $intent['id'],
			static function () use ( $intent ): array {
				return Db::tx(
					static function () use ( $intent ): array {
						$old = self::latest( (int) $intent['id'] );
						if ( ! in_array( $old['status'], [ 'failed', 'expired', 'cancelled' ], true ) || $old['gross'] ) {
							throw new Fault( 'mgdd_retry', esc_html__( 'Lanjutkan atau periksa pembayaran yang sudah ada.', 'magaudev-donations' ), 409 );
						}
						$db    = Db::wp();
						$count = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'attempts' ) . ' WHERE intent_id=%d', $intent['id'] ) );
						if ( $count >= 5 ) {
							throw new Fault( 'mgdd_retry_limit', esc_html__( 'Batas percobaan tercapai. Hubungi pengelola.', 'magaudev-donations' ), 409 );
						}
						return self::reserve( $intent, $count + 1 );
					}
				);
			}
		);
		$donor   = Db::wp()->get_row( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $intent['donor_id'] ), ARRAY_A );
		self::create_session( $attempt, $intent, $donor );
		return self::status( $reference );
	}
}
