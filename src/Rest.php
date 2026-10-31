<?php
/** Own permission map, no theme dependency. @package MagaudevDonations */
namespace MagaudevDonations;

final class Rest {
	public const NS                  = 'magaudev-donations/v1';
	public static array $permissions = [];
	public static function route( string $path, string $method, callable $work, string $permission = 'public' ): void {
		self::$permissions[ $method . ' ' . $path ] = $permission;
		register_rest_route(
			self::NS,
			$path,
			[
				'methods'             => $method,
				'permission_callback' => static function ( \WP_REST_Request $r ) use ( $permission ): bool|\WP_Error {
					try {
						if ( 'session' === $permission ) {
							Session::guard( $r );
						} elseif ( str_starts_with( $permission, 'mgdd_' ) ) {
							if ( ! is_user_logged_in() || ! wp_verify_nonce( $r->get_header( 'x-wp-nonce' ), 'wp_rest' ) || ! current_user_can( $permission ) ) {
								throw new Fault( 'mgdd_forbidden', esc_html__( 'Kamu tidak memiliki izin donasi untuk tindakan ini.', 'magaudev-donations' ), is_user_logged_in() ? 403 : 401 );
							}
							Limiter::enforce( 'admin', 120, 60, (string) get_current_user_id() );
						}
						return true;
					} catch ( Fault $e ) {
						return self::error( $e );
					}
				},
				'callback'            => static function ( \WP_REST_Request $r ) use ( $work, $path ): \WP_REST_Response|\WP_Error {
					try {
						if ( strlen( $r->get_body() ) > ( '/webhook' === $path ? 65536 : 16384 ) ) {
							throw new Fault( 'mgdd_body', esc_html__( 'Permintaan terlalu besar.', 'magaudev-donations' ), 413 );
						}
						Limiter::enforce( '/webhook' === $path ? 'webhook' : 'read', '/webhook' === $path ? 120 : 180 );
						$result = new \WP_REST_Response( $work( $r ) );
						$result->header( 'Cache-Control', 'private, no-store, max-age=0' );
						$result->header( 'Referrer-Policy', 'no-referrer' );
						$result->header( 'X-Request-ID', Audit::request_id() );
						return $result;
					} catch ( Fault $e ) {
						return self::error( $e );
					} catch ( \Throwable $e ) {
						return self::error( new Fault( 'mgdd_unavailable', esc_html__( 'Layanan sementara bermasalah. Coba lagi dengan referensi yang sama.', 'magaudev-donations' ), 503 ) );
					}
				},
			]
		);
	}
	public static function error( Fault $fault ): \WP_Error {
		return new \WP_Error(
			$fault->key,
			$fault->getMessage(),
			[
				'status'     => $fault->status,
				'request_id' => Audit::request_id(),
			]
		);
	}
	public static function body( \WP_REST_Request $r ): array {
		$data = $r->get_json_params();
		if ( ! is_array( $data ) ) {
			throw new Fault( 'mgdd_json', esc_html__( 'Isi JSON tidak valid.', 'magaudev-donations' ) );
		}
		return $data;
	}
	public static function routes(): void {
		self::route( '/config', 'GET', static fn() => Config::public_data() );
		self::route( '/summary', 'GET', static fn() => Reports::summary() );
		self::route( '/recent', 'GET', static fn() => [ 'items' => Reports::recent() ] );
		self::route( '/messages', 'GET', static fn() => [ 'items' => Reports::messages() ] );
		self::route( '/top', 'GET', static fn( $r ) => Reports::top( $r['period'] ?? 'month', (int) ( $r['page'] ?? 1 ) ) );
		self::route( '/session', 'POST', [ Session::class, 'bootstrap' ] );
		self::route(
			'/mine',
			'GET',
			static function (): array {
				if ( ! Session::current() ) {
					return [ 'references' => [] ];
				}
				$db    = Db::wp();
				$where = get_current_user_id() ? $db->prepare( 'd.user_id=%d', get_current_user_id() ) : $db->prepare( 'd.user_id IS NULL AND d.session_hash=%s', Session::hash() );
				return [ 'references' => $db->get_col( 'SELECT i.reference FROM ' . Db::table( 'intents' ) . ' i JOIN ' . Db::table( 'donors' ) . " d ON d.id=i.donor_id WHERE $where ORDER BY i.id DESC LIMIT 100" ) ];
			}
		);
		self::route( '/checkout', 'POST', static fn( $r ) => Donations::checkout( self::body( $r ) ), 'session' );
		$ref = '(?P<reference>[a-f0-9]{32})';
		self::route( '/status/' . $ref, 'GET', static fn( $r ) => Donations::status( $r['reference'] ) );
		self::route( '/status/' . $ref . '/sync', 'POST', static fn( $r ) => Donations::status( $r['reference'], true ), 'session' );
		self::route( '/status/' . $ref . '/retry', 'POST', static fn( $r ) => Donations::retry( $r['reference'] ), 'session' );
		self::route( '/privacy/' . $ref, 'POST', static fn( $r ) => Identity::privacy( $r['reference'], self::body( $r ) ), 'session' );
		self::route( '/verify/request', 'POST', static fn( $r ) => Identity::request( self::body( $r )['reference'] ?? '' ), 'session' );
		self::route( '/verify/consume', 'POST', static fn( $r ) => Identity::consume( self::body( $r )['code'] ?? '', ! empty( self::body( $r )['claim'] ) ), 'session' );
		self::route( '/recover', 'POST', static fn( $r ) => Identity::recover( self::body( $r )['email'] ?? '' ), 'session' );
		self::route( '/webhook', 'POST', static fn( $r ) => Payments::webhook( self::body( $r ) ) );
		self::route( '/admin/settings', 'GET', [ Admin::class, 'settings' ], 'mgdd_settings' );
		self::route(
			'/admin/settings',
			'POST',
			static function ( $r ) {
				$body = self::body( $r );
				foreach ( [ 'ip_days', 'log_days', 'trusted_proxies', 'create_limit', 'captcha' ] as $key ) {
					if ( isset( $body[ $key ] ) && $body[ $key ] !== Config::all()[ $key ] && ! current_user_can( 'mgdd_security' ) ) {
						throw new Fault( 'mgdd_forbidden', esc_html__( 'Perubahan keamanan memerlukan izin khusus.', 'magaudev-donations' ), 403 );
					}
				}
				Config::save( $body );
				return Admin::settings();
			},
			'mgdd_settings'
		);
		self::route( '/admin/rewards', 'GET', static fn() => Rewards::admin_data(), 'mgdd_settings' );
		self::route( '/admin/rewards', 'POST', static fn( $r ) => Rewards::save( self::body( $r ) ), 'mgdd_settings' );
		self::route( '/admin/rewards/log', 'GET', static fn( $r ) => Rewards::log( $r->get_params() ), 'mgdd_view' );
		self::route( '/admin/rewards/(?P<id>\d+)/retry', 'POST', static fn( $r ) => Rewards::retry( (int) $r['id'] ), 'mgdd_manage' );
		self::route( '/admin/member-search', 'GET', static fn( $r ) => Manual::search_members( (string) ( $r['q'] ?? '' ) ), 'mgdd_manual' );
		self::route( '/admin/manual', 'POST', static fn( $r ) => Manual::create( self::body( $r ) ), 'mgdd_manual' );
		self::route( '/admin/manual/(?P<id>\d+)', 'POST', static fn( $r ) => Manual::update( (int) $r['id'], self::body( $r ) ), 'mgdd_manual' );
		self::route( '/admin/manual/(?P<id>\d+)/void', 'POST', static fn( $r ) => Manual::void( (int) $r['id'], self::body( $r ) ), 'mgdd_manual' );
		self::route( '/admin/donors/(?P<id>\d+)/email', 'POST', static fn( $r ) => [ 'email' => Admin::donor_email( (int) $r['id'], (string) ( self::body( $r )['reason'] ?? '' ) ) ], 'mgdd_sensitive' );
		self::route( '/admin/donors/(?P<id>\d+)/anonymize', 'POST', static fn( $r ) => Admin::anonymize_donor( (int) $r['id'], self::body( $r ) ), 'mgdd_manage' );
		self::route( '/admin/overview', 'GET', static fn( $r ) => Admin::overview( $r['environment'] ?? 'sandbox' ), 'mgdd_view' );
		self::route( '/admin/donors/(?P<id>\d+)', 'GET', static fn( $r ) => Admin::donor_detail( (int) $r['id'] ), 'mgdd_view' );
		foreach ( [ 'transactions', 'donors', 'logs' ] as $kind ) {
			self::route( '/admin/' . $kind, 'GET', static fn( $r ) => Admin::listing( $kind, $r->get_params() ), 'mgdd_view' );
		}
		self::route( '/admin/transactions/(?P<id>\d+)', 'GET', static fn( $r ) => Admin::detail( (int) $r['id'] ), 'mgdd_view' );
		self::route( '/admin/transactions/(?P<id>\d+)', 'POST', static fn( $r ) => Admin::action( (int) $r['id'], self::body( $r ) ), 'mgdd_manage' );
		self::route( '/admin/reconcile', 'POST', static fn() => Jobs::run(), 'mgdd_manage' );
		self::route( '/admin/rebuild', 'POST', static fn() => [ 'rebuilt' => Payments::rebuild() ], 'mgdd_manage' );
		self::route( '/admin/ip/(?P<id>\d+)', 'POST', static fn( $r ) => [ 'ip' => Audit::reveal( (int) $r['id'], self::body( $r )['reason'] ?? '' ) ], 'mgdd_sensitive' );
		self::route( '/admin/security', 'GET', static fn() => [ 'blocks' => Db::wp()->get_results( Db::wp()->prepare( 'SELECT id,cidr,reason,expires_at FROM ' . Db::table( 'blocks' ) . ' WHERE expires_at>%s ORDER BY id DESC LIMIT 100', Db::now() ), ARRAY_A ) ], 'mgdd_security' );
		self::route( '/admin/security', 'POST', static fn( $r ) => Security::block( self::body( $r ) ), 'mgdd_security' );
		self::route( '/admin/export', 'POST', static fn( $r ) => Admin::export( self::body( $r ) ), 'mgdd_export' );
		self::route( '/admin/log-export', 'POST', static fn( $r ) => Admin::export_logs( self::body( $r ) ), 'mgdd_export' );
	}
}
