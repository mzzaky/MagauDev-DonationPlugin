<?php
/** Idempotent non destructive schema, independent of theme. @package MagaudevDonations */
namespace MagaudevDonations;

final class Schema {
	public const VERSION = 2;
	public const CAPS    = [ 'mgdd_view', 'mgdd_manage', 'mgdd_settings', 'mgdd_sensitive', 'mgdd_export', 'mgdd_security', 'mgdd_refund', 'mgdd_manual' ];
	/** FAQ bawaan 1.0.5 yang menyatakan donasi tidak pernah memberi XP/koin; hanya teks identik yang dimigrasi. */
	public const FAQ_105 = "Donasi bersifat sukarela dan tidak memberi XP, koin, level atau akses kelas.\nDaftar pendukung hanya memuat kontribusi yang memilih tampil publik.\nRefund memperbaiki kontribusi pada periode pembayaran asal.";
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$definitions = [
			'donors'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned DEFAULT NULL, session_hash char(64) DEFAULT NULL, email varchar(254) NOT NULL, alias varchar(100) NOT NULL, verified_at datetime DEFAULT NULL, is_manual tinyint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY member (user_id), KEY guest (session_hash), KEY email (email(191))',
			'intents'  => 'id bigint unsigned NOT NULL AUTO_INCREMENT, reference char(32) NOT NULL, donor_id bigint unsigned NOT NULL, scope_hash char(64) NOT NULL, idempotency_hash char(64) NOT NULL, payload_hash char(64) NOT NULL, amount bigint unsigned NOT NULL, alias varchar(100) NOT NULL, message text NOT NULL, note text DEFAULT NULL, public_name tinyint NOT NULL DEFAULT 0, public_message tinyint NOT NULL DEFAULT 0, moderated tinyint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY reference (reference), UNIQUE KEY idempotency (scope_hash,idempotency_hash), KEY donor (donor_id)',
			'attempts' => "id bigint unsigned NOT NULL AUTO_INCREMENT, intent_id bigint unsigned NOT NULL, environment varchar(12) NOT NULL, merchant varchar(64) NOT NULL, order_id varchar(50) NOT NULL, transaction_id varchar(64) DEFAULT NULL, status varchar(24) NOT NULL, method varchar(32) NOT NULL DEFAULT '', amount bigint unsigned NOT NULL, gross bigint unsigned NOT NULL DEFAULT 0, refunded bigint unsigned NOT NULL DEFAULT 0, snap_secret text DEFAULT NULL, paid_at datetime DEFAULT NULL, expires_at datetime NOT NULL, next_sync datetime NOT NULL, sync_count int unsigned NOT NULL DEFAULT 0, source varchar(12) NOT NULL DEFAULT 'gateway', voided_at datetime DEFAULT NULL, reward tinyint NOT NULL DEFAULT 0, revision int unsigned NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY order_ref (environment,merchant,order_id), UNIQUE KEY gateway_ref (environment,merchant,transaction_id), KEY intent (intent_id), KEY reconcile (status,next_sync), KEY paid (environment,paid_at), KEY origin (source,voided_at)",
			'events'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, attempt_id bigint unsigned NOT NULL, fingerprint char(64) NOT NULL, source varchar(24) NOT NULL, status varchar(24) NOT NULL, gross_delta bigint NOT NULL DEFAULT 0, refund_delta bigint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event (attempt_id,fingerprint), KEY attempt (attempt_id)',
			'audit'    => "id bigint unsigned NOT NULL AUTO_INCREMENT, actor_id bigint unsigned NOT NULL DEFAULT 0, kind varchar(24) NOT NULL, action varchar(64) NOT NULL, target_id bigint unsigned NOT NULL DEFAULT 0, severity varchar(12) NOT NULL, request_id char(24) NOT NULL, reason varchar(500) NOT NULL DEFAULT '', ip_hash char(64) NOT NULL, ip_mask varchar(64) NOT NULL, ip_secret text DEFAULT NULL, important tinyint NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY filter (kind,created_at), KEY target (target_id), KEY request (request_id)",
			'sessions' => 'hash char(64) NOT NULL, user_id bigint unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (hash), KEY expiry (expires_at)',
			'tokens'   => 'hash char(64) NOT NULL, donor_id bigint unsigned NOT NULL, purpose varchar(16) NOT NULL, expires_at datetime NOT NULL, used_at datetime DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY  (hash), KEY donor (donor_id), KEY expiry (expires_at)',
			'limits'   => 'bucket char(64) NOT NULL, hits int unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY  (bucket), KEY expiry (expires_at)',
			'blocks'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, cidr varchar(64) NOT NULL, reason varchar(500) NOT NULL, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY expiry (expires_at)',
			'rewards'  => "id bigint unsigned NOT NULL AUTO_INCREMENT, attempt_id bigint unsigned NOT NULL, intent_id bigint unsigned NOT NULL, donor_id bigint unsigned NOT NULL, user_id bigint unsigned DEFAULT NULL, tier_id varchar(32) NOT NULL, label varchar(80) NOT NULL DEFAULT '', basis varchar(8) NOT NULL, min_amount bigint unsigned NOT NULL DEFAULT 0, owner_key varchar(24) NOT NULL, items text NOT NULL, status varchar(16) NOT NULL, reason varchar(190) NOT NULL DEFAULT '', tries int unsigned NOT NULL DEFAULT 0, next_try datetime NOT NULL, created_at datetime NOT NULL, granted_at datetime DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY attempt_tier (attempt_id,tier_id), UNIQUE KEY owner_tier (tier_id,owner_key), KEY work (status,next_try), KEY donor (donor_id), KEY user (user_id)",
		];
		foreach ( $definitions as $name => $body ) {
			$body = str_replace( ', ', ",\n", $body );
			dbDelta( 'CREATE TABLE ' . Db::table( $name ) . " (\n" . $body . "\n) ENGINE=InnoDB " . Db::wp()->get_charset_collate() . ';' );
			if ( Db::wp()->last_error ) {
				throw new Fault( 'mgdd_migration', esc_html__( 'Migrasi donasi gagal. Periksa diagnostik database.', 'magaudev-donations' ), 503 );
			}
		}
		self::migrate_faq();
		update_option( Db::option( 'schema' ), self::VERSION, false );
		self::roles();
	}
	/** Replace the stored 1.0.5 FAQ only when it is still byte-identical to the shipped default. */
	private static function migrate_faq(): void {
		$stored = get_option( Db::option( 'settings' ), null );
		if ( is_array( $stored ) && isset( $stored['faq'] ) && self::FAQ_105 === $stored['faq'] ) {
			$stored['faq']      = Config::defaults()['faq'];
			$stored['revision'] = (int) ( $stored['revision'] ?? 1 ) + 1;
			update_option( Db::option( 'settings' ), $stored, false );
		}
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
