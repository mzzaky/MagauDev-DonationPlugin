<?php
/** Idempotent non destructive schema, independent of theme. @package MagaudevDonations */
namespace MagaudevDonations;

final class Schema {
	public const VERSION = 1;
	public const CAPS    = [ 'mgdd_view', 'mgdd_manage', 'mgdd_settings', 'mgdd_sensitive', 'mgdd_export', 'mgdd_security', 'mgdd_refund' ];
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$definitions = [
			'donors'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned DEFAULT NULL, session_hash char(64) DEFAULT NULL, email varchar(254) NOT NULL, alias varchar(100) NOT NULL, verified_at datetime DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY member (user_id), KEY guest (session_hash), KEY email (email(191))',
			'intents'  => 'id bigint unsigned NOT NULL AUTO_INCREMENT, reference char(32) NOT NULL, donor_id bigint unsigned NOT NULL, scope_hash char(64) NOT NULL, idempotency_hash char(64) NOT NULL, payload_hash char(64) NOT NULL, amount bigint unsigned NOT NULL, alias varchar(100) NOT NULL, message text NOT NULL, public_name tinyint NOT NULL DEFAULT 0, public_message tinyint NOT NULL DEFAULT 0, moderated tinyint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY reference (reference), UNIQUE KEY idempotency (scope_hash,idempotency_hash), KEY donor (donor_id)',
			'attempts' => "id bigint unsigned NOT NULL AUTO_INCREMENT, intent_id bigint unsigned NOT NULL, environment varchar(12) NOT NULL, merchant varchar(64) NOT NULL, order_id varchar(50) NOT NULL, transaction_id varchar(64) DEFAULT NULL, status varchar(24) NOT NULL, method varchar(32) NOT NULL DEFAULT '', amount bigint unsigned NOT NULL, gross bigint unsigned NOT NULL DEFAULT 0, refunded bigint unsigned NOT NULL DEFAULT 0, snap_secret text DEFAULT NULL, paid_at datetime DEFAULT NULL, expires_at datetime NOT NULL, next_sync datetime NOT NULL, sync_count int unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY order_ref (environment,merchant,order_id), UNIQUE KEY gateway_ref (environment,merchant,transaction_id), KEY intent (intent_id), KEY reconcile (status,next_sync), KEY paid (environment,paid_at)",
			'events'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, attempt_id bigint unsigned NOT NULL, fingerprint char(64) NOT NULL, source varchar(24) NOT NULL, status varchar(24) NOT NULL, gross_delta bigint NOT NULL DEFAULT 0, refund_delta bigint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event (attempt_id,fingerprint), KEY attempt (attempt_id)',
			'audit'    => "id bigint unsigned NOT NULL AUTO_INCREMENT, actor_id bigint unsigned NOT NULL DEFAULT 0, kind varchar(24) NOT NULL, action varchar(64) NOT NULL, target_id bigint unsigned NOT NULL DEFAULT 0, severity varchar(12) NOT NULL, request_id char(24) NOT NULL, reason varchar(500) NOT NULL DEFAULT '', ip_hash char(64) NOT NULL, ip_mask varchar(64) NOT NULL, ip_secret text DEFAULT NULL, important tinyint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY filter (kind,created_at), KEY target (target_id), KEY request (request_id)",
			'sessions' => 'hash char(64) NOT NULL, user_id bigint unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (hash), KEY expiry (expires_at)',
			'tokens'   => 'hash char(64) NOT NULL, donor_id bigint unsigned NOT NULL, purpose varchar(16) NOT NULL, expires_at datetime NOT NULL, used_at datetime DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY  (hash), KEY donor (donor_id), KEY expiry (expires_at)',
			'limits'   => 'bucket char(64) NOT NULL, hits int unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY  (bucket), KEY expiry (expires_at)',
			'blocks'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, cidr varchar(64) NOT NULL, reason varchar(500) NOT NULL, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY expiry (expires_at)',
		];
		foreach ( $definitions as $name => $body ) {
			$body = str_replace( ', ', ",\n", $body );
			dbDelta( 'CREATE TABLE ' . Db::table( $name ) . " (\n" . $body . "\n) ENGINE=InnoDB " . Db::wp()->get_charset_collate() . ';' );
			if ( Db::wp()->last_error ) {
				throw new Fault( 'mgdd_migration', esc_html__( 'Migrasi donasi gagal. Periksa diagnostik database.', 'magaudev-donations' ), 503 );
			}
		}
		update_option( Db::option( 'schema' ), self::VERSION, false );
		self::roles();
	}
	public static function roles(): void {
		foreach ( [ 'administrator', 'mgd_admin' ] as $slug ) {
			$role = get_role( $slug );
			if ( $role ) {
				foreach ( self::CAPS as $cap ) {
					if ( ! $role->has_cap( $cap ) ) {
						$role->add_cap( $cap );
					}
				}
			}
		}
	}
}
