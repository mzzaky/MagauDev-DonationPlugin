<?php
/** WP privacy tools and theme account adapter. @package MagaudevDonations */
namespace MagaudevDonations;

final class Privacy {
	public static function register(): void {
		add_action( 'delete_user', [ self::class, 'detach_user' ] );
		add_filter(
			'magaudev_export_user_data',
			static function ( array $data, int $user ): array {
				$db                = Db::wp();
				$data['donations'] = $db->get_results( $db->prepare( 'SELECT i.reference,i.amount,i.public_name,i.public_message,i.created_at FROM ' . Db::table( 'intents' ) . ' i JOIN ' . Db::table( 'donors' ) . ' d ON d.id=i.donor_id WHERE d.user_id=%d ORDER BY i.id', $user ), ARRAY_A );
				return $data;
			},
			10,
			2
		);
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( array $items ): array {
				$items['mgdd'] = [
					'exporter_friendly_name' => 'Magaudev Donations',
					'callback'               => static function ( string $email, int $page = 1 ): array {
								$rows = self::export( $email, $page );
								return [
									'data' => array_map(
										static fn( $row ) => [
											'group_id'    => 'mgdd',
											'group_label' => 'Donasi',
											'item_id'     => 'mgdd-' . $row['reference'],
											'data'        => array_map(
												static fn( $key, $value ) => [
													'name' => $key,
													'value' => (string) $value,
												],
												array_keys( $row ),
												array_values( $row )
											),
										],
										$rows
									),
									'done' => count( $rows ) < 100,
								];
					},
				];
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( array $items ): array {
				$items['mgdd'] = [
					'eraser_friendly_name' => 'Magaudev Donations',
					'callback'             => static function ( string $email ): array {
								self::anonymize( $email );
								return [
									'items_removed'  => true,
									'items_retained' => true,
									'messages'       => [ 'Identitas publik diputus; ledger keuangan dipertahankan.' ],
									'done'           => true,
								];
					},
				];
				return $items;
			}
		);
	}
	public static function export( string $email, int $page = 1 ): array {
		$db = Db::wp();
		return $db->get_results( $db->prepare( 'SELECT i.reference,i.amount,i.public_name,i.public_message,i.created_at FROM ' . Db::table( 'intents' ) . ' i JOIN ' . Db::table( 'donors' ) . ' d ON d.id=i.donor_id WHERE d.email=%s ORDER BY i.id LIMIT 100 OFFSET %d', $email, max( 0, $page - 1 ) * 100 ), ARRAY_A );
	}
	public static function detach_user( int $user ): void {
		self::anonymize( '', $user );
	}
	public static function anonymize( string $email, int $user = 0 ): void {
		Db::tx(
			static function () use ( $email, $user ): void {
				$db  = Db::wp();
				$d   = Db::table( 'donors' );
				$ids = $user ? $db->get_col( $db->prepare( "SELECT id FROM $d WHERE user_id=%d FOR UPDATE", $user ) ) : $db->get_col( $db->prepare( "SELECT id FROM $d WHERE email=%s FOR UPDATE", $email ) );
				foreach ( $ids as $id ) {
					Db::update(
						'intents',
						[
							'public_name'    => 0,
							'public_message' => 0,
							'alias'          => 'Anonim',
							'message'        => '',
						],
						[ 'donor_id' => $id ]
					);
					Db::update(
						'donors',
						[
							'user_id'      => null,
							'session_hash' => null,
							'email'        => '',
							'alias'        => 'Anonim',
						],
						[ 'id' => $id ]
					);
					Db::update( 'tokens', [ 'used_at' => Db::now() ], [ 'donor_id' => $id ] );
					Audit::log( 'identity', 'profile.anonymized', (int) $id, '', true );
				}
			}
		);
	}
}
