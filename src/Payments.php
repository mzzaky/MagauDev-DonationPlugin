<?php
/** Atomic verified state machine and immutable financial events. @package MagaudevDonations */
namespace MagaudevDonations;

final class Payments {
	public static function normalize( array $data, array $attempt ): array {
		if ( ( $data['order_id'] ?? '' ) !== $attempt['order_id'] || Money::gateway( $data['gross_amount'] ?? '' ) !== (int) $attempt['amount'] || ( isset( $data['currency'] ) && 'IDR' !== $data['currency'] ) || ( isset( $data['merchant_id'] ) && $data['merchant_id'] !== $attempt['merchant'] ) || empty( $data['transaction_id'] ) || ! is_string( $data['transaction_id'] ) || strlen( $data['transaction_id'] ) > 64 ) {
			throw new Fault( 'mgdd_gateway_mismatch', esc_html__( 'Data gateway tidak cocok. Transaksi perlu diperiksa pengelola.', 'magaudev-donations' ), 502 );
		}
		$status = (string) ( $data['transaction_status'] ?? '' );
		$method = (string) ( $data['payment_type'] ?? '' );
		if ( ! in_array( $method, [ 'qris', 'gopay', 'shopeepay' ], true ) || ( isset( $data['fraud_status'] ) && ! in_array( $data['fraud_status'], [ 'accept', '' ], true ) && 'settlement' === $status ) ) {
			throw new Fault( 'mgdd_gateway_review', esc_html__( 'Metode atau hasil gateway memerlukan pemeriksaan.', 'magaudev-donations' ), 502 );
		}
		$mapped = match ( $status ) {
			'settlement' => 'paid', 'pending' => 'pending', 'expire' => 'expired', 'cancel' => 'cancelled', 'deny', 'failure' => 'failed', 'refund', 'chargeback' => 'refunded', 'partial_refund', 'partial_chargeback' => 'partial_refund', default => 'review',
		};
		$gross  = in_array( $mapped, [ 'paid', 'refunded', 'partial_refund' ], true ) ? (int) $attempt['amount'] : 0;
		$refund = 0;
		if ( 'refunded' === $mapped ) {
			$refund = $gross;
		} elseif ( 'partial_refund' === $mapped ) {
			$refund = Money::gateway( $data['refund_amount'] ?? $data['chargeback_amount'] ?? '' );
			if ( $refund > $gross || $refund < 1 ) {
				throw new Fault( 'mgdd_refund_amount', esc_html__( 'Nominal refund gateway tidak valid.', 'magaudev-donations' ), 502 );
			}
		}
		$paid = null;
		if ( $gross ) {
			$raw  = $data['settlement_time'] ?? $data['transaction_time'] ?? '';
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw, new \DateTimeZone( 'Asia/Jakarta' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $raw ) {
				throw new Fault( 'mgdd_gateway_time', esc_html__( 'Waktu pembayaran gateway tidak valid.', 'magaudev-donations' ), 502 );
			}
			$paid = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		return [
			'status'         => $mapped,
			'transaction_id' => $data['transaction_id'],
			'method'         => $method,
			'gross'          => $gross,
			'refunded'       => $refund,
			'paid_at'        => $paid,
		];
	}
	public static function transition( array $old, array $next ): array {
		if ( $old['transaction_id'] && $old['transaction_id'] !== $next['transaction_id'] ) {
			throw new Fault( 'mgdd_transaction_mismatch', esc_html__( 'Identitas transaksi gateway berubah; perlu pemeriksaan.', 'magaudev-donations' ), 502 );
		}
		if ( (int) $old['gross'] > 0 && in_array( $next['status'], [ 'pending', 'review', 'expired', 'cancelled' ], true ) ) {
			$next['status'] = $old['status'];
		}
		$next['gross']    = max( (int) $old['gross'], $next['gross'] );
		$next['refunded'] = max( (int) $old['refunded'], $next['refunded'] );
		if ( $next['gross'] && 'failed' === $next['status'] ) {
			// Verified reversal: retain the receipt and append the full reversal.
			$next['refunded'] = $next['gross'];
		}
		if ( $next['refunded'] && 'failed' !== $next['status'] ) {
			$next['status'] = $next['refunded'] === $next['gross'] ? 'refunded' : 'partial_refund';
		}
		$next['paid_at'] = $old['paid_at'] ? $old['paid_at'] : $next['paid_at'];
		return $next;
	}
	public static function apply( int $id, array $data, string $source ): array {
		return Db::lock( 'ledger', static fn(): array => self::apply_locked( $id, $data, $source ) );
	}
	private static function apply_locked( int $id, array $data, string $source ): array {
		return Db::tx(
			static function () use ( $id, $data, $source ): array {
				$db  = Db::wp();
				$old = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
				if ( ! $old ) {
					throw new Fault( 'mgdd_not_found', esc_html__( 'Transaksi tidak ditemukan.', 'magaudev-donations' ), 404 );
				}
				$next        = self::transition( $old, self::normalize( $data, $old ) );
				$fingerprint = hash( 'sha256', wp_json_encode( $next ) );
				$exists      = $db->get_var( $db->prepare( 'SELECT id FROM ' . Db::table( 'events' ) . ' WHERE attempt_id=%d AND fingerprint=%s', $id, $fingerprint ) );
				if ( ! $exists ) {
					Db::insert(
						'events',
						[
							'attempt_id'   => $id,
							'fingerprint'  => $fingerprint,
							'source'       => $source,
							'status'       => $next['status'],
							'gross_delta'  => $next['gross'] - (int) $old['gross'],
							'refund_delta' => $next['refunded'] - (int) $old['refunded'],
							'created_at'   => Db::now(),
						]
					);
					Db::update( 'attempts', $next + [ 'updated_at' => Db::now() ], [ 'id' => $id ] );
					Audit::log( 'payment', 'status.' . $next['status'], $id, '', true );
					if ( $next['gross'] > 0 ) {
						$duplicates = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'attempts' ) . ' WHERE intent_id=%d AND gross>0', $old['intent_id'] ) );
						if ( $duplicates > 1 ) {
							Audit::log( 'payment', 'multiple.receipts', $id, 'Beberapa pembayaran nyata pada satu intent; tidak ada refund otomatis.', true, 'warning' );
						}
					}
				}
				return array_replace( $old, $next );
			}
		);
	}
	public static function sync( array $attempt, string $source = 'status' ): array {
		$data = Gateway::status( $attempt );
		if ( ! empty( $data['not_found'] ) ) {
			return $attempt;
		}
		return self::apply( (int) $attempt['id'], $data, $source );
	}
	public static function webhook( array $payload ): array {
		$db    = Db::wp();
		$order = $payload['order_id'] ?? '';
		if ( ! is_string( $order ) || ! preg_match( '/^mgdd-[a-f0-9]{32}-[0-9]+$/D', $order ) ) {
			throw new Fault( 'mgdd_webhook', esc_html__( 'Notifikasi tidak valid.', 'magaudev-donations' ), 400 );
		}
		$attempt = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE order_id=%s', $order ), ARRAY_A );
		if ( ! $attempt || ! Gateway::signature( $payload, $attempt ) ) {
			if ( Limiter::take( 'webhook_log', Ip::hash(), 1, 300 ) ) {
				Audit::log( 'security', 'webhook.rejected', 0, '', false, 'warning' );
			}
			throw new Fault( 'mgdd_signature', esc_html__( 'Notifikasi tidak terverifikasi.', 'magaudev-donations' ), 403 );
		}
		self::normalize( $payload, $attempt );
		$data = Gateway::status( $attempt );
		if ( ! empty( $data['not_found'] ) || ( $data['transaction_id'] ?? '' ) !== $payload['transaction_id'] ) {
			throw new Fault( 'mgdd_webhook_retry', esc_html__( 'Status belum dapat diverifikasi. Kirim ulang nanti.', 'magaudev-donations' ), 503 );
		}
		self::apply( (int) $attempt['id'], $data, 'webhook' );
		Audit::log( 'webhook', 'notification.applied', (int) $attempt['id'] );
		update_option( Db::option( 'webhook_success' ), Db::now(), false );
		return [ 'received' => true ];
	}
	public static function rebuild(): int {
		return Db::lock(
			'ledger',
			static function (): int {
				return Db::tx(
					static function (): int {
						$a     = Db::table( 'attempts' );
						$e     = Db::table( 'events' );
						$count = Db::query( "UPDATE $a a LEFT JOIN (SELECT attempt_id,SUM(gross_delta) g,SUM(refund_delta) r FROM $e GROUP BY attempt_id) e ON e.attempt_id=a.id SET a.gross=COALESCE(e.g,0),a.refunded=COALESCE(e.r,0)" );
						Audit::log( 'admin', 'ledger.rebuilt', 0, 'Ringkasan dihitung dari event sumber.', true );
						return $count;
					}
				);
			}
		);
	}
}
