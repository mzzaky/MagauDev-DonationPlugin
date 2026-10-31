<?php
/** Filtered append only operational audit. @package MagaudevDonations */
namespace MagaudevDonations;

final class Audit {
	private static ?string $request = null;
	public static function request_id(): string {
		return self::$request ??= bin2hex( random_bytes( 12 ) );
	}
	public static function log( string $kind, string $action, int $target = 0, string $reason = '', bool $important = false, string $severity = 'info' ): int {
		$ip = Ip::current();
		foreach ( [ 'sandbox', 'production' ] as $environment ) {
			foreach ( [ 'server_key', 'client_key' ] as $key ) {
				$secret = Config::env( $environment, $key );
				if ( strlen( $secret ) >= 4 ) {
					$reason = str_replace( $secret, '[rahasia]', $reason );
				}
			}
		}
		$reason = preg_replace( '/\b(?:authorization|server[_ -]?key|signature|token|cookie)\s*[:=]\s*\S+/i', '[rahasia]', $reason );
		$reason = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $reason );
		return Db::insert(
			'audit',
			[
				'actor_id'   => get_current_user_id(),
				'kind'       => $kind,
				'action'     => $action,
				'target_id'  => $target,
				'severity'   => $severity,
				'request_id' => self::request_id(),
				'reason'     => mb_substr( sanitize_textarea_field( $reason ), 0, 500 ),
				'ip_hash'    => Ip::hash( $ip ),
				'ip_mask'    => Ip::mask( $ip ),
				'ip_secret'  => Crypto::seal( $ip, true ),
				'important'  => (int) $important,
				'created_at' => Db::now(),
			]
		);
	}
	public static function reveal( int $id, string $reason ): string {
		if ( ! current_user_can( 'mgdd_sensitive' ) || mb_strlen( trim( $reason ) ) < 8 ) {
			throw new Fault( 'mgdd_reveal', esc_html__( 'Izin khusus dan alasan minimal 8 karakter diperlukan.', 'magaudev-donations' ), 403 );
		}
		$row = Db::wp()->get_row( Db::wp()->prepare( 'SELECT ip_secret,created_at FROM ' . Db::table( 'audit' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $row || $row['created_at'] < gmdate( 'Y-m-d H:i:s', time() - Config::all()['ip_days'] * DAY_IN_SECONDS ) ) {
			throw new Fault( 'mgdd_ip_expired', esc_html__( 'IP lengkap tidak tersedia atau sudah melewati retensi.', 'magaudev-donations' ), 410 );
		}
		self::log( 'admin', 'ip.reveal', $id, $reason, true );
		$ip = Crypto::open( $row['ip_secret'], true );
		if ( null === $ip ) {
			throw new Fault( 'mgdd_ip_key', esc_html__( 'IP lengkap tidak tersedia; periksa kunci enkripsi.', 'magaudev-donations' ), 503 );
		}
		return $ip;
	}
}
