<?php
/**
 * Plugin Name: Magaudev Donations
 * Description: Donasi guest/member melalui Midtrans Snap, ledger, hadiah XP/koin dan panel magaudev.
 * Version: 1.0.6
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Aithor
 * License: GPL-2.0-or-later
 * Text Domain: magaudev-donations
 *
 * @package MagaudevDonations
 */

defined( 'ABSPATH' ) || exit;
define( 'MGDD_VERSION', '1.0.6' );
define( 'MGDD_FILE', __FILE__ );
define( 'MGDD_DIR', __DIR__ );
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'MagaudevDonations\\' ) ) {
			$file = __DIR__ . '/src/' . substr( $class_name, strlen( 'MagaudevDonations\\' ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);
register_activation_hook( __FILE__, [ MagaudevDonations\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ MagaudevDonations\Plugin::class, 'deactivate' ] );
MagaudevDonations\Plugin::register();
