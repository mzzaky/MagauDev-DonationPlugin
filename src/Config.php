<?php
/** Strict allowlisted nonsecret settings. @package MagaudevDonations */
namespace MagaudevDonations;

final class Config {
	public static function defaults(): array {
		return [
			'revision'        => 1,
			'paused'          => true,
			'environment'     => 'sandbox',
			'channels'        => [],
			'minimum'         => 10000,
			'maximum'         => 2000000,
			'presets'         => [ 10000, 25000, 50000, 100000 ],
			'title'           => 'Jaga api belajar tetap menyala.',
			'description'     => 'Dukunganmu membantu operasional dan pengembangan ruang belajar magaudev.',
			'goal'            => 0,
			'show_summary'    => true,
			'faq'             => "Donasi bersifat sukarela dan tidak pernah memberi akses kelas.\nDaftar pendukung hanya memuat kontribusi yang memilih tampil publik.\nRefund memperbaiki kontribusi pada periode pembayaran asal.",
			'rewards'         => Rewards::defaults(),
			'ip_days'         => 30,
			'log_days'        => 90,
			'create_limit'    => 6,
			'captcha'         => false,
			'trusted_proxies' => [],
		];
	}
	public static function all(): array {
		return array_replace( self::defaults(), (array) get_option( Db::option( 'settings' ), [] ) );
	}
	public static function env( string $environment, string $key ): string {
		$name = 'MGDD_' . strtoupper( $environment ) . '_' . strtoupper( $key );
		return defined( $name ) ? (string) constant( $name ) : '';
	}
	public static function readiness(): array {
		$c           = self::all();
		$env         = $c['environment'];
		$credentials = '' !== self::env( $env, 'server_key' ) && '' !== self::env( $env, 'client_key' ) && '' !== self::env( $env, 'merchant_id' );
		$production  = 'sandbox' === $env || ( is_ssl() && defined( 'MGDD_PRODUCTION_ENABLED' ) && MGDD_PRODUCTION_ENABLED );
		$captcha     = ! $c['captcha'] || ( defined( 'MGDD_TURNSTILE_SITEKEY' ) && defined( 'MGDD_TURNSTILE_SECRET' ) );
		$ready       = $credentials && $production && $captcha && count( $c['channels'] ) > 0 && function_exists( 'sodium_crypto_secretbox' );
		return [
			'ready'              => $ready,
			'accepting'          => $ready && ! $c['paused'],
			'credentials'        => $credentials,
			'production_allowed' => $production,
			'captcha_ready'      => $captcha,
			'ip_encryption'      => Crypto::ip_ready(),
		];
	}
	public static function save( array $input ): array {
		return Db::lock(
			'settings',
			static function () use ( $input ): array {
				$old = self::all();
				if ( (int) ( $input['revision'] ?? 0 ) !== (int) $old['revision'] ) {
					throw new Fault( 'mgdd_revision', esc_html__( 'Pengaturan sudah berubah. Muat ulang sebelum menyimpan.', 'magaudev-donations' ), 409 );
				}
				$c = array_replace( $old, array_intersect_key( $input, self::defaults() ) );
				if ( ! in_array( $c['environment'], [ 'sandbox', 'production' ], true ) ) {
					throw new Fault( 'mgdd_settings', esc_html__( 'Environment tidak valid.', 'magaudev-donations' ) );
				}
				foreach ( [
					'minimum'      => [ 1, 10000000 ],
					'maximum'      => [ 1, 10000000 ],
					'goal'         => [ 0, 1000000000000 ],
					'ip_days'      => [ 1, 90 ],
					'log_days'     => [ 30, 365 ],
					'create_limit' => [ 1, 30 ],
				] as $key => [ $min, $max ] ) {
					$c[ $key ] = Money::integer( $c[ $key ], $min, $max );
				}
				if ( $c['minimum'] > $c['maximum'] || ! is_array( $c['presets'] ) || count( $c['presets'] ) > 8 || ! is_array( $c['channels'] ) || array_diff( $c['channels'], [ 'qris', 'gopay', 'shopeepay' ] ) ) {
					throw new Fault( 'mgdd_settings', esc_html__( 'Batas nominal, preset atau kanal tidak valid.', 'magaudev-donations' ) );
				}
				$c['presets']  = array_values( array_unique( array_map( static fn( $v ) => Money::integer( $v, $c['minimum'], $c['maximum'] ), $c['presets'] ) ) );
				$c['channels'] = array_values( array_unique( $c['channels'] ) );
				foreach ( [ 'paused', 'show_summary', 'captcha' ] as $key ) {
					if ( ! is_bool( $c[ $key ] ) ) {
						throw new Fault( 'mgdd_settings', esc_html__( 'Nilai sakelar tidak valid.', 'magaudev-donations' ) );
					}
				}
				$c['title']       = mb_substr( sanitize_text_field( $c['title'] ), 0, 160 );
				$c['description'] = mb_substr( sanitize_textarea_field( $c['description'] ), 0, 2000 );
				$c['faq']         = mb_substr( sanitize_textarea_field( $c['faq'] ), 0, 4000 );
				if ( ! is_array( $c['trusted_proxies'] ) || count( $c['trusted_proxies'] ) > 32 ) {
					throw new Fault( 'mgdd_proxy', esc_html__( 'Daftar proxy tidak valid.', 'magaudev-donations' ) );
				}
				foreach ( $c['trusted_proxies'] as $cidr ) {
					if ( ! Ip::valid_cidr( $cidr ) ) {
						throw new Fault( 'mgdd_proxy', esc_html__( 'CIDR proxy tidak valid.', 'magaudev-donations' ) );
					}
				}
				if ( array_key_exists( 'rewards', $input ) ) {
						$c['rewards'] = Rewards::sanitize( $c['rewards'], (array) $old['rewards'] );
				}
					$c['revision'] = $old['revision'] + 1;
				if ( ! update_option( Db::option( 'settings' ), $c, false ) ) {
					throw new Fault( 'mgdd_settings_write', esc_html__( 'Pengaturan gagal disimpan.', 'magaudev-donations' ), 503 );
				}
				Audit::log( 'admin', 'settings.saved', 0, 'Pengaturan revisi ' . $c['revision'], true );
				return $c;
			}
		);
	}
	public static function public_data(): array {
		$c                      = self::all();
		$out                    = array_intersect_key( $c, array_flip( [ 'title', 'description', 'minimum', 'maximum', 'presets', 'goal', 'faq', 'show_summary', 'environment', 'channels' ] ) );
		$out['accepting']       = self::readiness()['accepting'];
		$out['rewards']         = Rewards::public_data();
		$out['captcha_sitekey'] = $c['captcha'] && defined( 'MGDD_TURNSTILE_SITEKEY' ) ? MGDD_TURNSTILE_SITEKEY : '';
		return $out;
	}
}
