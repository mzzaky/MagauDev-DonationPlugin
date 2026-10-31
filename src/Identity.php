<?php
/** Explicit mailbox proof and transactional identity claims. @package MagaudevDonations */
namespace MagaudevDonations;

final class Identity {
	public static function donor( string $email, string $alias ): array {
		$db    = Db::wp();
		$table = Db::table( 'donors' );
		$user  = get_current_user_id();
		$row   = $user ? $db->get_row( $db->prepare( "SELECT * FROM $table WHERE user_id=%d", $user ), ARRAY_A ) : $db->get_row( $db->prepare( "SELECT * FROM $table WHERE session_hash=%s AND email=%s AND user_id IS NULL ORDER BY id LIMIT 1", Session::hash(), $email ), ARRAY_A );
		if ( $row ) {
			return $row;
		}
		$data       = [
			'user_id'      => $user ? $user : null,
			'session_hash' => $user ? null : Session::hash(),
			'email'        => $email,
			'alias'        => $alias,
			'created_at'   => Db::now(),
		];
		$data['id'] = Db::insert( 'donors', $data );
		return $data;
	}
	public static function send( array $donor, string $purpose ): void {
		$code = bin2hex( random_bytes( 32 ) );
		Db::insert(
			'tokens',
			[
				'hash'       => hash( 'sha256', $code ),
				'donor_id'   => $donor['id'],
				'purpose'    => $purpose,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 1800 ),
				'created_at' => Db::now(),
			]
		);
		$sent = wp_mail( $donor['email'], 'Kode akses donasi magaudev', "Tempel kode berikut pada halaman status donasi magaudev:\n\n" . $code . "\n\nBerlaku 30 menit dan sekali pakai. Kode ini memberi akses riwayat profil donasi terkait. Jangan bagikan. Jika ingin mengaitkan ke akun, masuk dahulu sebelum memakai kode. Abaikan jika bukan permintaan Anda." );
		Audit::log( 'identity', $sent ? 'mail.sent' : 'mail.failed', (int) $donor['id'], '', false, $sent ? 'info' : 'warning' );
	}
	public static function request( string $reference ): array {
		Limiter::enforce( 'verify', 3, 3600, Session::scope() );
		$intent = Donations::intent( $reference );
		$donor  = Db::wp()->get_row( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $intent['donor_id'] ), ARRAY_A );
		self::queue( [ (int) $donor['id'] ], 'verify' );
		return [ 'accepted' => true ];
	}
	public static function recover( string $email ): array {
		Limiter::enforce( 'recover_ip', 3, 3600 );
		$email = strtolower( sanitize_email( $email ) );
		Limiter::enforce( 'recover_email', 3, 3600, hash( 'sha256', $email ) );
		$donors = Db::wp()->get_results( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'donors' ) . ' WHERE email=%s AND user_id IS NULL ORDER BY id DESC LIMIT 20', $email ), ARRAY_A );
		// Always schedule one job, including absent addresses; no mailbox-dependent SMTP timing.
		self::queue( array_map( 'intval', array_column( $donors, 'id' ) ), 'recover' );
		return [ 'accepted' => true ];
	}
	private static function queue( array $ids, string $purpose ): void {
		$result = wp_schedule_single_event( time() + 5, Db::option( 'send_codes' ), [ $ids, $purpose ], true );
		if ( is_wp_error( $result ) ) {
			Audit::log( 'identity', 'mail.queue_failed', 0, '', false, 'warning' );
		}
	}
	public static function deliver( array $ids, string $purpose ): void {
		if ( ! in_array( $purpose, [ 'verify', 'recover' ], true ) ) {
			return;
		}
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$donor = Db::wp()->get_row( Db::wp()->prepare( 'SELECT * FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', (int) $id ), ARRAY_A );
			if ( $donor && is_email( $donor['email'] ) && ( 'verify' === $purpose || ! $donor['user_id'] ) ) {
				self::send( $donor, $purpose );
			}
		}
	}
	public static function consume( string $code, bool $claim ): array {
		Limiter::enforce( 'consume', 6, 900, Session::scope() );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $code ) || ( $claim && ! get_current_user_id() ) ) {
			throw new Fault( 'mgdd_token', esc_html__( 'Kode tidak valid atau sesi akun belum tersedia.', 'magaudev-donations' ), 400 );
		}
		return Db::lock(
			'claim:' . ( get_current_user_id() ? get_current_user_id() : Session::hash() ),
			static function () use ( $code, $claim ): array {
				return Db::tx(
					static function () use ( $code, $claim ): array {
						$db    = Db::wp();
						$token = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'tokens' ) . ' WHERE hash=%s FOR UPDATE', hash( 'sha256', $code ) ), ARRAY_A );
						if ( ! $token || $token['used_at'] || $token['expires_at'] <= Db::now() ) {
							throw new Fault( 'mgdd_token', esc_html__( 'Kode tidak valid atau sudah kedaluwarsa.', 'magaudev-donations' ) );
						}
						$donor = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'donors' ) . ' WHERE id=%d FOR UPDATE', $token['donor_id'] ), ARRAY_A );
						if ( ! $donor || ( $donor['user_id'] && (int) $donor['user_id'] !== get_current_user_id() ) ) {
							throw new Fault( 'mgdd_token', esc_html__( 'Kode tidak valid.', 'magaudev-donations' ) );
						}
						$destination = (int) $donor['id'];
						if ( $claim && ! $donor['user_id'] ) {
							$member      = self::donor( wp_get_current_user()->user_email, wp_get_current_user()->display_name );
							$destination = (int) $member['id'];
							Db::update( 'intents', [ 'donor_id' => $destination ], [ 'donor_id' => $donor['id'] ] );
							Db::update(
								'donors',
								[
									'session_hash' => null,
									'email'        => '',
									'alias'        => 'Profil digabung',
									'verified_at'  => Db::now(),
								],
								[ 'id' => $donor['id'] ]
							);
						} else {
							Db::update(
								'donors',
								[
									'verified_at'  => Db::now(),
									'session_hash' => $donor['user_id'] ? null : Session::hash(),
								],
								[ 'id' => $donor['id'] ]
							);
						}
						Db::update( 'tokens', [ 'used_at' => Db::now() ], [ 'donor_id' => $donor['id'] ] );
						Audit::log( 'identity', $claim ? 'history.claimed' : 'email.verified', $destination, '', true );
						$references = $db->get_col( $db->prepare( 'SELECT reference FROM ' . Db::table( 'intents' ) . ' WHERE donor_id=%d ORDER BY id DESC LIMIT 100', $destination ) );
						return [
							'verified'   => true,
							'references' => $references,
						];
					}
				);
			}
		);
	}
	public static function privacy( string $reference, array $input ): array {
		$intent = Donations::intent( $reference );
		foreach ( [ 'public_name', 'public_message' ] as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_bool( $input[ $key ] ) ) {
				throw new Fault( 'mgdd_consent', esc_html__( 'Persetujuan tidak valid.', 'magaudev-donations' ) );
			}
		}
		Db::tx(
			static function () use ( $intent, $input ): void {
				Db::update(
					'intents',
					[
						'public_name'    => (int) $input['public_name'],
						'public_message' => (int) $input['public_message'],
					],
					[ 'id' => $intent['id'] ]
				);
				Audit::log( 'identity', 'privacy.changed', (int) $intent['id'], '', true );
			}
		);
		return [ 'saved' => true ];
	}
}
