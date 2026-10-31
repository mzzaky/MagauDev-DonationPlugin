<?php
/** Bounded reconciliation and retention, never needs active checkout. @package MagaudevDonations */
namespace MagaudevDonations;

final class Jobs {
	public static function run(): array {
		return Db::lock(
			'reconcile',
			static function (): array {
				$db     = Db::wp();
				$table  = Db::table( 'attempts' );
				$rows   = $db->get_results( $db->prepare( "SELECT * FROM $table WHERE next_sync<=%s AND sync_count<120 AND source='gateway' AND (status IN ('creating','created','unknown','pending','review') OR (gross>0 AND updated_at>=%s)) ORDER BY next_sync,id LIMIT 20", Db::now(), gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ), ARRAY_A );
				$ok     = 0;
				$failed = 0;
				foreach ( $rows as $row ) {
					try {
						Payments::sync( $row, 'reconcile' );
						++$ok;
					} catch ( \Throwable $e ) {
						++$failed;
						Audit::log( 'payment', 'sync.retry', (int) $row['id'], '', false, 'warning' );
					}
					$count = (int) $row['sync_count'] + 1;
					$delay = $row['gross'] ? DAY_IN_SECONDS : min( 21600, 60 * ( 2 ** min( $count, 8 ) ) );
					Db::update(
						'attempts',
						[
							'sync_count' => $count,
							'next_sync'  => gmdate( 'Y-m-d H:i:s', time() + $delay ),
						],
						[ 'id' => $row['id'] ]
					);
				}
				self::receipts();
				try {
					$rewards = Rewards::run_pending();
				} catch ( \Throwable $e ) {
					$rewards = [];
					Audit::log( 'payment', 'reward.error', 0, 'Pemrosesan ulang hadiah gagal.', false, 'warning' );
				}
				Security::cleanup();
				update_option( Db::option( 'cron_last' ), Db::now(), false );
				return [
					'checked' => count( $rows ),
					'success' => $ok,
					'retry'   => $failed,
					'rewards' => $rewards,
				];
			}
		);
	}
	private static function receipts(): void {
		$a    = Db::table( 'attempts' );
		$i    = Db::table( 'intents' );
		$d    = Db::table( 'donors' );
		$l    = Db::table( 'audit' );
		$rows = Db::wp()->get_results( "SELECT a.id,a.environment,a.gross,a.refunded,d.email FROM $a a JOIN $i i ON i.id=a.intent_id JOIN $d d ON d.id=i.donor_id WHERE a.source='gateway' AND a.gross>0 AND d.email<>'' AND NOT EXISTS (SELECT 1 FROM $l l WHERE l.target_id=a.id AND l.action='receipt.sent') ORDER BY a.id LIMIT 20", ARRAY_A );
		foreach ( $rows as $row ) {
			$body = sprintf( 'Terima kasih atas dukungan Anda untuk magaudev. Bruto terverifikasi: Rp%s. Refund/reversal terverifikasi saat ini: Rp%s. Lingkungan: %s. Periksa status terbaru melalui halaman status dengan sesi Anda atau pemulihan email: %s', number_format( (int) $row['gross'], 0, ',', '.' ), number_format( (int) $row['refunded'], 0, ',', '.' ), $row['environment'], home_url( '/donasi/status/' ) );
			$sent = wp_mail( $row['email'], 'Konfirmasi dukungan magaudev', $body );
			Audit::log( 'payment', $sent ? 'receipt.sent' : 'receipt.failed', (int) $row['id'], '', $sent, $sent ? 'info' : 'warning' );
		}
	}
}
