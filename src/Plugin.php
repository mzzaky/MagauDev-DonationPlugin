<?php
/** Lifecycle independent of active theme. @package MagaudevDonations */
namespace MagaudevDonations;

final class Plugin {
	public static function register(): void {
		add_action( 'init', [ self::class, 'upgrade' ], 3 );
		add_action( 'rest_api_init', [ Rest::class, 'routes' ] );
		add_action( Db::option( 'send_codes' ), [ Identity::class, 'deliver' ], 10, 2 );
		add_filter(
			'rest_post_dispatch',
			static function ( $response, $server, $request ) {
				if ( str_starts_with( $request->get_route(), '/' . Rest::NS . '/' ) ) {
					$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
					$response->header( 'Referrer-Policy', 'no-referrer' );
				}
				return $response;
			},
			10,
			3
		);
		add_action(
			'mgdd_reconcile',
			static function (): void {
				try {
					Jobs::run();
				} catch ( \Throwable $e ) {
					update_option( Db::option( 'cron_failure' ), Db::now(), false );
				}
			}
		);
		add_action( 'admin_menu', static fn() => add_options_page( 'Magaudev Donations', 'Magaudev Donations', 'mgdd_settings', 'mgdd', [ Admin::class, 'wp_page' ] ) );
		add_action(
			'admin_notices',
			static function (): void {
				if ( current_user_can( 'mgdd_settings' ) && ( ! defined( 'MAGAUDEV_VERSION' ) || version_compare( MAGAUDEV_VERSION, '1.0.5', '<' ) ) ) {
					echo '<div class="notice notice-warning"><p>Magaudev Donations: halaman dan panel memerlukan tema magaudev minimal 1.0.5. Backend dan webhook tetap aktif.</p></div>';
				}
			}
		);
		Privacy::register();
	}
	public static function activate(): void {
		if ( PHP_INT_SIZE < 8 ) {
			throw new Fault( 'mgdd_platform', esc_html__( 'PHP 64 bit diperlukan untuk nominal donasi.', 'magaudev-donations' ), 503 );
		}
		Schema::install();
		self::schedule();
	}
	public static function upgrade(): void {
		if ( (int) get_option( Db::option( 'schema' ), 0 ) < Schema::VERSION ) {
			Schema::install();
		}
		Schema::roles();
		self::schedule();
	}
	private static function schedule(): void {
		if ( ! wp_next_scheduled( 'mgdd_reconcile' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'mgdd_reconcile' );
		}
	}
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mgdd_reconcile' );
		wp_clear_scheduled_hook( Db::option( 'send_codes' ) );
	}
}
