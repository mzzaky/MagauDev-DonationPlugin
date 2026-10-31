<?php
/** Manual donor entries: append only through ledger events, void instead of delete. @package MagaudevDonations */
namespace MagaudevDonations;

final class Manual {
	public const MAX_AMOUNT = 1000000000;
	private static function flag( array $input, string $key, bool $fallback = false ): bool {
		if ( ! array_key_exists( $key, $input ) ) {
			return $fallback;
		}
		if ( ! is_bool( $input[ $key ] ) ) {
			throw new Fault( 'mgdd_manual', esc_html__( 'Nilai sakelar tidak valid.', 'magaudev-donations' ) );
		}
		return $input[ $key ];
	}
	private static function reason( array $input ): string {
		$reason = mb_substr( sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) ), 0, 400 );
		if ( mb_strlen( $reason ) < 8 ) {
			throw new Fault( 'mgdd_reason', esc_html__( 'Alasan minimal 8 karakter diperlukan.', 'magaudev-donations' ) );
		}
		return $reason;
	}
	/** App-timezone local time (Y-m-d H:i[:s], optional T) to UTC; never in the future. */
	public static function paid_at( mixed $value ): string {
		$text = is_string( $value ) ? str_replace( 'T', ' ', trim( $value ) ) : '';
		$date = false;
		foreach ( [ '!Y-m-d H:i:s', '!Y-m-d H:i' ] as $format ) {
			$try = \DateTimeImmutable::createFromFormat( $format, $text, Reports::timezone() );
			if ( $try && $try->format( substr( $format, 1 ) ) === $text ) {
				$date = $try;
				break;
			}
		}
		if ( ! $date || $date->getTimestamp() > time() + 300 || $date->getTimestamp() < 1420070400 ) {
			throw new Fault( 'mgdd_manual_date', esc_html__( 'Tanggal pembayaran tidak valid atau berada di masa depan.', 'magaudev-donations' ) );
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
	private static function visibility( array $input, array $current ): array {
		$public_name    = self::flag( $input, 'public_name', (bool) $current['public_name'] );
		$public_message = self::flag( $input, 'public_message', (bool) $current['public_message'] );
		$changed        = $public_name !== (bool) $current['public_name'] || $public_message !== (bool) $current['public_message'];
		if ( ( $public_name || $public_message ) && $changed && ! self::flag( $input, 'attest' ) ) {
			throw new Fault( 'mgdd_attest', esc_html__( 'Tegaskan bahwa donatur menyetujui tampil publik sebelum menyimpan.', 'magaudev-donations' ) );
		}
		return [ (int) $public_name, (int) $public_message ];
	}
	private static function donor( array $input, string $alias, string $email ): array {
		$db = Db::wp();
		$d  = Db::table( 'donors' );
		if ( isset( $input['member_id'] ) ) {
			$user = get_userdata( Money::integer( $input['member_id'], 1 ) );
			if ( ! $user ) {
				throw new Fault( 'mgdd_manual_member', esc_html__( 'Member tidak ditemukan.', 'magaudev-donations' ), 404 );
			}
			$row = $db->get_row( $db->prepare( "SELECT * FROM $d WHERE user_id=%d FOR UPDATE", $user->ID ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
			$row       = [
				'user_id'      => $user->ID,
				'session_hash' => null,
				'email'        => strtolower( (string) $user->user_email ),
				'alias'        => mb_substr( $user->display_name, 0, 100 ),
				'created_at'   => Db::now(),
			];
			$row['id'] = Db::insert( 'donors', $row );
			return $row;
		}
		if ( isset( $input['donor_id'] ) ) {
			$row = $db->get_row( $db->prepare( "SELECT * FROM $d WHERE id=%d AND is_manual=1 AND user_id IS NULL FOR UPDATE", Money::integer( $input['donor_id'], 1 ) ), ARRAY_A );
			if ( ! $row ) {
				throw new Fault( 'mgdd_manual_donor', esc_html__( 'Profil donatur manual tidak ditemukan.', 'magaudev-donations' ), 404 );
			}
			return $row;
		}
		if ( '' !== $email ) {
			$row = $db->get_row( $db->prepare( "SELECT * FROM $d WHERE email=%s AND is_manual=1 AND user_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE", $email ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}
		$row       = [
			'user_id'      => null,
			'session_hash' => null,
			'email'        => $email,
			'alias'        => $alias,
			'is_manual'    => 1,
			'created_at'   => Db::now(),
		];
		$row['id'] = Db::insert( 'donors', $row );
		return $row;
	}
	public static function create( array $input ): array {
		Limiter::enforce( 'manual', 30, 60, (string) get_current_user_id() );
		$reason = self::reason( $input );
		$alias  = mb_substr( sanitize_text_field( (string) ( $input['alias'] ?? '' ) ), 0, 100 );
		$email  = '';
		if ( ! empty( $input['email'] ) ) {
			$email = strtolower( sanitize_email( (string) $input['email'] ) );
			if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
				throw new Fault( 'mgdd_manual_email', esc_html__( 'Email tidak valid.', 'magaudev-donations' ) );
			}
		}
		if ( '' === trim( $alias ) ) {
			throw new Fault( 'mgdd_manual_alias', esc_html__( 'Nama atau alias donatur wajib diisi.', 'magaudev-donations' ) );
		}
		$amount                           = Money::integer( $input['amount'] ?? null, 1, self::MAX_AMOUNT );
		$paid_at                          = self::paid_at( $input['paid_at'] ?? null );
		$message                          = mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 500 );
		$note                             = mb_substr( sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ), 0, 1000 );
		[ $public_name, $public_message ] = self::visibility(
			$input,
			[
				'public_name'    => 0,
				'public_message' => 0,
			]
		);
		$reward                           = self::flag( $input, 'reward' );
		if ( $reward && empty( $input['member_id'] ) ) {
			throw new Fault( 'mgdd_manual_reward', esc_html__( 'Hadiah hanya dapat diberikan kepada member yang ditautkan.', 'magaudev-donations' ) );
		}
		$key = $input['request_key'] ?? null;
		if ( null !== $key && ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $key ) ) ) {
			throw new Fault( 'mgdd_idempotency', esc_html__( 'Kunci permintaan tidak valid.', 'magaudev-donations' ) );
		}
		$scope = hash( 'sha256', 'manual:' . get_current_user_id() );
		$idem  = hash( 'sha256', $key ?? bin2hex( random_bytes( 16 ) ) );
		$id    = Db::lock(
			'manual:' . $scope . $idem,
			static function () use ( $input, $alias, $email, $amount, $paid_at, $message, $note, $public_name, $public_message, $reward, $scope, $idem, $reason ): int {
				return Db::tx(
					static function () use ( $input, $alias, $email, $amount, $paid_at, $message, $note, $public_name, $public_message, $reward, $scope, $idem, $reason ): int {
						$db       = Db::wp();
						$existing = $db->get_var( $db->prepare( 'SELECT a.id FROM ' . Db::table( 'intents' ) . ' i JOIN ' . Db::table( 'attempts' ) . ' a ON a.intent_id=i.id WHERE i.scope_hash=%s AND i.idempotency_hash=%s', $scope, $idem ) );
						if ( $existing ) {
							return (int) $existing;
						}
						$donor     = self::donor( $input, $alias, $email );
						$intent_id = Db::insert(
							'intents',
							[
								'reference'        => bin2hex( random_bytes( 16 ) ),
								'donor_id'         => $donor['id'],
								'scope_hash'       => $scope,
								'idempotency_hash' => $idem,
								'payload_hash'     => hash( 'sha256', wp_json_encode( [ $amount, $alias, $paid_at ] ) ),
								'amount'           => $amount,
								'alias'            => $alias,
								'message'          => $message,
								'note'             => $note,
								'public_name'      => $public_name,
								'public_message'   => $public_message,
								'moderated'        => $public_message,
								'created_at'       => Db::now(),
							]
						);
						$id        = Db::insert(
							'attempts',
							[
								'intent_id'   => $intent_id,
								'environment' => 'production',
								'merchant'    => 'manual',
								'order_id'    => 'manual-' . bin2hex( random_bytes( 12 ) ),
								'status'      => 'paid',
								'method'      => 'manual',
								'amount'      => $amount,
								'gross'       => $amount,
								'paid_at'     => $paid_at,
								'expires_at'  => Db::now(),
								'next_sync'   => Db::now(),
								'sync_count'  => 120,
								'source'      => 'manual',
								'reward'      => (int) $reward,
								'created_at'  => Db::now(),
								'updated_at'  => Db::now(),
							]
						);
						Db::insert(
							'events',
							[
								'attempt_id'   => $id,
								'fingerprint'  => hash( 'sha256', 'manual-created:' . $id ),
								'source'       => 'manual',
								'status'       => 'paid',
								'gross_delta'  => $amount,
								'refund_delta' => 0,
								'created_at'   => Db::now(),
							]
						);
						Audit::log( 'admin', 'manual.created', $id, $reason, true );
						return $id;
					}
				);
			}
		);
		if ( ! empty( $input['send_receipt'] ) && '' !== $email ) {
			self::receipt( $id, $email, $amount );
		}
		Rewards::evaluate_safe( $id );
		Reports::flush();
		return Admin::detail( $id );
	}
	private static function receipt( int $id, string $email, int $amount ): void {
		$sent = wp_mail( $email, 'Konfirmasi dukungan magaudev', sprintf( 'Terima kasih atas dukungan Anda untuk magaudev. Dukungan tercatat oleh pengelola: Rp%s.', number_format( $amount, 0, ',', '.' ) ) );
		Audit::log( 'payment', $sent ? 'receipt.sent' : 'receipt.failed', $id, '', $sent, $sent ? 'info' : 'warning' );
	}
	/** Locks and returns the editable manual attempt, or throws a precise conflict. */
	private static function row( int $id, mixed $revision ): array {
		$db  = Db::wp();
		$row = $db->get_row( $db->prepare( 'SELECT a.*,i.alias,i.message,i.note,i.public_name,i.public_message FROM ' . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . ' i ON i.id=a.intent_id WHERE a.id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $row ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Transaksi tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		if ( 'manual' !== $row['source'] ) {
			throw new Fault( 'mgdd_not_manual', esc_html__( 'Hanya entri manual yang dapat diubah atau dibatalkan di sini.', 'magaudev-donations' ), 409 );
		}
		if ( $row['voided_at'] ) {
			throw new Fault( 'mgdd_voided', esc_html__( 'Entri ini sudah dibatalkan.', 'magaudev-donations' ), 409 );
		}
		if ( (int) $revision !== (int) $row['revision'] ) {
			throw new Fault( 'mgdd_revision', esc_html__( 'Entri sudah berubah. Muat ulang sebelum menyimpan.', 'magaudev-donations' ), 409 );
		}
		return $row;
	}
	public static function update( int $id, array $input ): array {
		Limiter::enforce( 'manual', 30, 60, (string) get_current_user_id() );
		$reason = self::reason( $input );
		Db::lock(
			'ledger',
			static function () use ( $id, $input, $reason ): void {
				Db::tx(
					static function () use ( $id, $input, $reason ): void {
						$row     = self::row( $id, $input['revision'] ?? 0 );
						$intent  = [];
						$attempt = [];
						$changed = [];
						if ( array_key_exists( 'alias', $input ) ) {
							$alias = mb_substr( sanitize_text_field( (string) $input['alias'] ), 0, 100 );
							if ( '' === trim( $alias ) ) {
								throw new Fault( 'mgdd_manual_alias', esc_html__( 'Nama atau alias donatur wajib diisi.', 'magaudev-donations' ) );
							}
							$intent['alias'] = $alias;
						}
						if ( array_key_exists( 'message', $input ) ) {
							$intent['message'] = mb_substr( sanitize_textarea_field( (string) $input['message'] ), 0, 500 );
						}
						if ( array_key_exists( 'note', $input ) ) {
							$intent['note'] = mb_substr( sanitize_textarea_field( (string) $input['note'] ), 0, 1000 );
						}
						[ $intent['public_name'], $intent['public_message'] ] = self::visibility( $input, $row );
						$intent['moderated']                                  = $intent['public_message'];
						if ( array_key_exists( 'paid_at', $input ) ) {
							$attempt['paid_at'] = self::paid_at( $input['paid_at'] );
						}
						$delta = 0;
						if ( array_key_exists( 'amount', $input ) ) {
							$amount = Money::integer( $input['amount'], 1, self::MAX_AMOUNT );
							$delta  = $amount - (int) $row['gross'];
							if ( 0 !== $delta ) {
								$attempt['amount']   = $amount;
								$intent['amount']    = $amount;
								$attempt['gross']    = $amount;
								$attempt['revision'] = (int) $row['revision'] + 1;
							}
						}
						foreach ( [ 'alias', 'message', 'note', 'public_name', 'public_message' ] as $key ) {
							if ( isset( $intent[ $key ] ) && (string) $intent[ $key ] !== (string) $row[ $key ] ) {
								$changed[] = $key;
							}
						}
						if ( isset( $attempt['paid_at'] ) && $attempt['paid_at'] !== $row['paid_at'] ) {
							$changed[] = 'paid_at';
						}
						if ( 0 !== $delta ) {
							$changed[] = 'amount';
						}
						$attempt['revision']   = (int) $row['revision'] + 1;
						$attempt['updated_at'] = Db::now();
						Db::update( 'intents', $intent, [ 'id' => $row['intent_id'] ] );
						Db::update( 'attempts', $attempt, [ 'id' => $id ] );
						if ( 0 !== $delta ) {
							Db::insert(
								'events',
								[
									'attempt_id'   => $id,
									'fingerprint'  => hash( 'sha256', 'manual-adjust:' . $id . ':' . $attempt['revision'] ),
									'source'       => 'manual',
									'status'       => 'paid',
									'gross_delta'  => $delta,
									'refund_delta' => 0,
									'created_at'   => Db::now(),
								]
							);
						}
						Audit::log( 'admin', 'manual.updated', $id, $reason . ' [' . implode( ',', $changed ) . ']', true );
					}
				);
			}
		);
		Reports::flush();
		return Admin::detail( $id );
	}
	public static function void( int $id, array $input ): array {
		Limiter::enforce( 'manual', 30, 60, (string) get_current_user_id() );
		$reason = self::reason( $input );
		Db::lock(
			'ledger',
			static function () use ( $id, $input, $reason ): void {
				Db::tx(
					static function () use ( $id, $input, $reason ): void {
						$row      = self::row( $id, $input['revision'] ?? 0 );
						$revision = (int) $row['revision'] + 1;
						Db::insert(
							'events',
							[
								'attempt_id'   => $id,
								'fingerprint'  => hash( 'sha256', 'manual-void:' . $id . ':' . $revision ),
								'source'       => 'manual',
								'status'       => 'voided',
								'gross_delta'  => - (int) $row['gross'],
								'refund_delta' => - (int) $row['refunded'],
								'created_at'   => Db::now(),
							]
						);
						Db::update(
							'attempts',
							[
								'status'     => 'voided',
								'gross'      => 0,
								'refunded'   => 0,
								'voided_at'  => Db::now(),
								'revision'   => $revision,
								'updated_at' => Db::now(),
							],
							[ 'id' => $id ]
						);
						Audit::log( 'admin', 'manual.voided', $id, $reason, true );
					}
				);
			}
		);
		Rewards::note_void( $id );
		Reports::flush();
		return Admin::detail( $id );
	}
	/** Member picker: id, display name and login only, never email. */
	public static function search_members( string $query ): array {
		Limiter::enforce( 'member_search', 60, 60, (string) get_current_user_id() );
		$query = trim( mb_substr( sanitize_text_field( $query ), 0, 60 ) );
		if ( mb_strlen( $query ) < 2 ) {
			return [ 'items' => [] ];
		}
		$users = get_users(
			[
				'search'         => '*' . $query . '*',
				'search_columns' => [ 'user_login', 'user_nicename', 'display_name' ],
				'number'         => 10,
				'orderby'        => 'display_name',
				'fields'         => [ 'ID', 'display_name', 'user_login' ],
			]
		);
		return [
			'items' => array_map(
				static fn( $u ) => [
					'id'    => (int) $u->ID,
					'name'  => $u->display_name,
					'login' => $u->user_login,
				],
				$users
			),
		];
	}
}
