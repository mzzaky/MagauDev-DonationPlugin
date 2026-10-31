<?php
/** Independent abuse controls, not applied to webhook/admin recovery. @package MagaudevDonations */
namespace MagaudevDonations;

final class Security {
	public static function checkout( array $input ): void {
		$c = Config::all();
		Limiter::enforce( 'create_ip', $c['create_limit'], 900 );
		Limiter::enforce( 'create_session', $c['create_limit'], 900, Session::scope() );
		if ( ! empty( $input['website'] ) ) {
			throw new Fault( 'mgdd_abuse', esc_html__( 'Permintaan tidak dapat diproses.', 'magaudev-donations' ), 400 );
		}
		$blocks = Db::wp()->get_col( Db::wp()->prepare( 'SELECT cidr FROM ' . Db::table( 'blocks' ) . ' WHERE expires_at>%s LIMIT 1000', Db::now() ) );
		foreach ( $blocks as $cidr ) {
			if ( Ip::contains( Ip::current(), $cidr ) ) {
				throw new Fault( 'mgdd_blocked', esc_html__( 'Pembuatan pembayaran dari jaringan ini dibatasi sementara.', 'magaudev-donations' ), 403 );
			}
		}
		if ( $c['captcha'] ) {
			$token = $input['captcha'] ?? '';
			if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 || ! defined( 'MGDD_TURNSTILE_SECRET' ) ) {
				throw new Fault( 'mgdd_captcha', esc_html__( 'Selesaikan verifikasi keamanan.', 'magaudev-donations' ), 403 );
			}
			$res  = wp_remote_post(
				'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				[
					'timeout'   => 8,
					'sslverify' => true,
					'body'      => [
						'secret'   => MGDD_TURNSTILE_SECRET,
						'response' => $token,
						'remoteip' => Ip::current(),
					],
				]
			);
			$data = is_wp_error( $res ) ? [] : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( empty( $data['success'] ) || ( $data['hostname'] ?? '' ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				throw new Fault( 'mgdd_captcha', esc_html__( 'Verifikasi keamanan gagal. Coba lagi.', 'magaudev-donations' ), 403 );
			}
		}
	}
	public static function block( array $input ): array {
		$cidr   = $input['cidr'] ?? '';
		$reason = sanitize_text_field( $input['reason'] ?? '' );
		$hours  = Money::integer( $input['hours'] ?? 1, 1, 168 );
		if ( ! Ip::valid_cidr( $cidr ) || mb_strlen( $reason ) < 8 ) {
			throw new Fault( 'mgdd_block', esc_html__( 'CIDR valid dan alasan minimal 8 karakter diperlukan.', 'magaudev-donations' ) );
		}
		$id = Db::insert(
			'blocks',
			[
				'cidr'       => $cidr,
				'reason'     => mb_substr( $reason, 0, 500 ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS ),
				'created_at' => Db::now(),
			]
		);
		Audit::log( 'admin', 'block.created', $id, $reason, true );
		return [ 'saved' => true ];
	}
	public static function cleanup(): void {
		$db    = Db::wp();
		$c     = Config::all();
		$audit = Db::table( 'audit' );
		Db::query( $db->prepare( "UPDATE $audit SET ip_secret=NULL WHERE ip_secret IS NOT NULL AND created_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $c['ip_days'] * DAY_IN_SECONDS ) ) );
		Db::query( $db->prepare( "DELETE FROM $audit WHERE important=0 AND created_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $c['log_days'] * DAY_IN_SECONDS ) ) );
		foreach ( [ 'sessions', 'tokens', 'limits', 'blocks' ] as $table ) {
			Db::query( $db->prepare( 'DELETE FROM ' . Db::table( $table ) . ' WHERE expires_at<%s LIMIT 1000', Db::now() ) );
		}
	}
}
