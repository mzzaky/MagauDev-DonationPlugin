<?php
/** Capability protected operations and explicitly filtered DTOs. @package MagaudevDonations */
namespace MagaudevDonations;

final class Admin {
	public static function settings(): array {
		return Config::all() + [
			'health'       => Config::readiness(),
			'webhook'      => rest_url( Rest::NS . '/webhook' ),
			'callback'     => home_url( '/donasi/status/' ),
			'last_webhook' => get_option( Db::option( 'webhook_success' ), null ),
			'last_cron'    => get_option( Db::option( 'cron_last' ), null ),
			'schema'       => get_option( Db::option( 'schema' ), 0 ),
			'version'      => MGDD_VERSION,
			'caps'         => array_values( array_filter( Schema::CAPS, 'current_user_can' ) ),
		];
	}
	public static function overview( string $environment ): array {
		$env     = 'production' === $environment ? 'production' : 'sandbox';
		$db      = Db::wp();
		$a       = Db::table( 'attempts' );
		$totals  = $db->get_row( $db->prepare( "SELECT COALESCE(SUM(gross),0) gross,COALESCE(SUM(refunded),0) refunded,COALESCE(SUM(gross-refunded),0) supported,COUNT(*) attempts FROM $a WHERE environment=%s", $env ), ARRAY_A );
		$states  = $db->get_results( $db->prepare( "SELECT status,COUNT(*) total FROM $a WHERE environment=%s GROUP BY status", $env ), ARRAY_A );
		$trend   = $db->get_results( $db->prepare( "SELECT DATE(paid_at) day,SUM(gross-refunded) amount FROM $a WHERE environment=%s AND paid_at>=%s GROUP BY DATE(paid_at) ORDER BY day", $env, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), ARRAY_A );
		$backlog = (int) $db->get_var( "SELECT COUNT(*) FROM $a WHERE sync_count>=120 AND status IN ('unknown','pending','review','creating','created')" );
		return [
			'environment'  => $env,
			'totals'       => array_map( 'intval', $totals ),
			'states'       => $states,
			'trend'        => $trend,
			'last_cron'    => get_option( Db::option( 'cron_last' ), null ),
			'last_webhook' => get_option( Db::option( 'webhook_success' ), null ),
			'needs_review' => $backlog,
			'fees'         => null,
			'net'          => null,
			'timezone'     => Reports::timezone()->getName(),
		];
	}
	public static function listing( string $kind, array $args ): array {
		$db    = Db::wp();
		$page  = max( 1, min( 10000, (int) ( $args['page'] ?? 1 ) ) );
		$where = '1=1';
		if ( 'transactions' === $kind ) {
			$table  = Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . ' i ON i.id=a.intent_id JOIN ' . Db::table( 'donors' ) . ' d ON d.id=i.donor_id';
			$fields = 'a.id,i.reference,i.alias,a.environment,a.order_id,a.status,a.method,a.source,a.voided_at,a.reward,a.revision,d.user_id,a.amount,a.gross,a.refunded,a.paid_at,a.created_at';
			if ( empty( $args['voided'] ) ) {
				$where .= ' AND a.voided_at IS NULL';
			}
			foreach ( [ 'environment', 'status', 'method', 'source' ] as $key ) {
				if ( ! empty( $args[ $key ] ) ) {
					$where .= $db->prepare( " AND a.$key=%s", substr( sanitize_text_field( $args[ $key ] ), 0, 32 ) );
				}
			}
			if ( ! empty( $args['search'] ) ) {
				$like   = '%' . $db->esc_like( mb_substr( $args['search'], 0, 80 ) ) . '%';
				$where .= $db->prepare( ' AND (a.order_id LIKE %s OR i.alias LIKE %s OR i.reference LIKE %s)', $like, $like, $like );
			}
			foreach ( [
				'from' => '>=',
				'to'   => '<=',
			] as $key => $operator ) {
				if ( ! empty( $args[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $args[ $key ] ) ) {
					$where .= $db->prepare( " AND a.created_at $operator %s", $args[ $key ] . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' ) );
				}
			}
			$order = 'a.id';
		} elseif ( 'donors' === $kind ) {
			$table  = Db::table( 'donors' ) . ' d';
			$fields = 'd.id,d.user_id,d.alias,d.email,d.verified_at,d.is_manual,d.created_at,(SELECT COALESCE(SUM(a.gross-a.refunded),0) FROM ' . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . " i ON i.id=a.intent_id WHERE i.donor_id=d.id AND a.environment='production') supported";
			$order  = 'd.id';
			if ( ! empty( $args['manual'] ) ) {
				$where .= ' AND d.is_manual=1 AND d.user_id IS NULL';
			}
			if ( ! empty( $args['search'] ) ) {
				$like   = '%' . $db->esc_like( mb_substr( (string) $args['search'], 0, 80 ) ) . '%';
				$where .= $db->prepare( ' AND d.alias LIKE %s', $like );
			}
		} else {
			$table  = Db::table( 'audit' );
			$fields = 'id,actor_id,kind,action,target_id,severity,request_id,reason,ip_mask,created_at';
			$order  = 'id';
			foreach ( [ 'kind', 'severity', 'request_id', 'actor_id', 'target_id' ] as $key ) {
				if ( ! empty( $args[ $key ] ) ) {
					$where .= $db->prepare( " AND $key=%s", substr( sanitize_text_field( $args[ $key ] ), 0, 64 ) );
				}
			}
			foreach ( [
				'from' => '>=',
				'to'   => '<=',
			] as $key => $operator ) {
				if ( ! empty( $args[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $args[ $key ] ) ) {
					$where .= $db->prepare( " AND created_at $operator %s", $args[ $key ] . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' ) );
				}
			}
		}
		$total = (int) $db->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$items = $db->get_results( $db->prepare( "SELECT $fields FROM $table WHERE $where ORDER BY $order DESC LIMIT 30 OFFSET %d", ( $page - 1 ) * 30 ), ARRAY_A );
		if ( 'donors' === $kind ) {
			foreach ( $items as &$item ) {
				$item['email_mask'] = self::mask_email( (string) $item['email'] );
				$item['supported']  = (int) $item['supported'];
				unset( $item['email'] );
			}
			unset( $item );
		}
		return [
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $total / 30 ) ),
		];
	}
	public static function donor_detail( int $id ): array {
		$db      = Db::wp();
		$profile = $db->get_row( $db->prepare( 'SELECT id,user_id,alias,email,verified_at,is_manual,created_at FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $profile ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Profil tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		$profile['email_mask'] = self::mask_email( (string) $profile['email'] );
		unset( $profile['email'] );
		$history = $db->get_results( $db->prepare( 'SELECT i.reference,i.alias,i.public_name,i.public_message,a.id,a.environment,a.status,a.source,a.voided_at,a.gross,a.refunded,a.paid_at FROM ' . Db::table( 'intents' ) . ' i JOIN ' . Db::table( 'attempts' ) . ' a ON a.intent_id=i.id WHERE i.donor_id=%d ORDER BY a.id DESC LIMIT 100', $id ), ARRAY_A );
		return [
			'profile' => $profile,
			'history' => $history,
			'rewards' => Rewards::export_rows( $id ),
			'totals'  => $db->get_results( $db->prepare( 'SELECT a.environment,SUM(a.gross) gross,SUM(a.refunded) refunded,SUM(a.gross-a.refunded) supported FROM ' . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . ' i ON i.id=a.intent_id WHERE i.donor_id=%d GROUP BY a.environment', $id ), ARRAY_A ),
		];
	}
	public static function detail( int $id ): array {
		$db  = Db::wp();
		$a   = Db::table( 'attempts' );
		$row = $db->get_row( $db->prepare( "SELECT * FROM $a WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Transaksi tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		$intent   = $db->get_row( $db->prepare( 'SELECT id,reference,donor_id,amount,alias,message,note,public_name,public_message,moderated,created_at FROM ' . Db::table( 'intents' ) . ' WHERE id=%d', $row['intent_id'] ), ARRAY_A );
		$attempts = $db->get_results( $db->prepare( "SELECT id,order_id,environment,status,method,source,voided_at,amount,gross,refunded,paid_at,created_at FROM $a WHERE intent_id=%d ORDER BY id", $row['intent_id'] ), ARRAY_A );
		$events   = $db->get_results( $db->prepare( 'SELECT source,status,gross_delta,refund_delta,created_at FROM ' . Db::table( 'events' ) . ' WHERE attempt_id=%d ORDER BY id', $id ), ARRAY_A );
		$notes    = $db->get_results( $db->prepare( 'SELECT action,reason,actor_id,created_at FROM ' . Db::table( 'audit' ) . ' WHERE target_id=%d AND kind=%s ORDER BY id DESC LIMIT 100', $id, 'admin' ), ARRAY_A );
		$member   = null;
		$donor    = $db->get_row( $db->prepare( 'SELECT user_id FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $intent['donor_id'] ), ARRAY_A );
		if ( $donor && $donor['user_id'] && get_userdata( (int) $donor['user_id'] ) ) {
			$member = [
				'id'   => (int) $donor['user_id'],
				'name' => get_userdata( (int) $donor['user_id'] )->display_name,
			];
		}
		return [
			'attempt'          => array_intersect_key( $row, array_flip( [ 'id', 'source', 'status', 'voided_at', 'reward', 'revision', 'paid_at', 'gross', 'refunded', 'amount', 'environment', 'method' ] ) ),
			'member'           => $member,
			'intent'           => $intent,
			'attempts'         => $attempts,
			'events'           => $events,
			'notes'            => $notes,
			'refund_available' => false,
		];
	}
	public static function action( int $id, array $input ): array {
		$reason = mb_substr( sanitize_textarea_field( $input['reason'] ?? '' ), 0, 500 );
		if ( mb_strlen( $reason ) < 8 ) {
			throw new Fault( 'mgdd_reason', esc_html__( 'Alasan minimal 8 karakter diperlukan.', 'magaudev-donations' ) );
		}
		$db  = Db::wp();
		$row = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $row ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Transaksi tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		$action = $input['action'] ?? '';
		if ( 'sync' === $action ) {
			Payments::sync( $row, 'admin' );
		} elseif ( in_array( $action, [ 'moderation', 'hide', 'reject_message' ], true ) ) {
			Db::update(
				'intents',
				[
					'moderated'   => 'moderation' === $action ? 1 : ( 'reject_message' === $action ? 0 : self::detail( $id )['intent']['moderated'] ),
					'public_name' => 'hide' === $action ? 0 : self::detail( $id )['intent']['public_name'],
				],
				[ 'id' => $row['intent_id'] ]
			);
		} elseif ( 'note' !== $action ) {
			throw new Fault( 'mgdd_action', esc_html__( 'Tindakan tidak didukung.', 'magaudev-donations' ) );
		}
		Audit::log( 'admin', 'transaction.' . $action, $id, $reason, true );
		return self::detail( $id );
	}
	public static function mask_email( string $email ): string {
		if ( '' === $email || ! str_contains( $email, '@' ) ) {
			return '';
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		return mb_substr( $local, 0, 1 ) . '***@' . mb_substr( $domain, 0, 1 ) . '***' . ( str_contains( $domain, '.' ) ? substr( $domain, (int) strrpos( $domain, '.' ) ) : '' );
	}
	/** Full address only for mgdd_sensitive with a reason; every reveal is audited. */
	public static function donor_email( int $id, string $reason ): string {
		$reason = mb_substr( sanitize_textarea_field( $reason ), 0, 400 );
		if ( ! current_user_can( 'mgdd_sensitive' ) || mb_strlen( trim( $reason ) ) < 8 ) {
			throw new Fault( 'mgdd_reveal', esc_html__( 'Izin khusus dan alasan minimal 8 karakter diperlukan.', 'magaudev-donations' ), 403 );
		}
		Limiter::enforce( 'email_reveal', 20, 3600, (string) get_current_user_id() );
		$email = Db::wp()->get_var( Db::wp()->prepare( 'SELECT email FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $id ) );
		if ( null === $email ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Profil tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		Audit::log( 'admin', 'donor.email_revealed', $id, $reason, true );
		return (string) $email;
	}
	/** Cuts a donor profile loose from its identity; the financial ledger is untouched. */
	public static function anonymize_donor( int $id, array $input ): array {
		$reason = mb_substr( sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) ), 0, 400 );
		if ( mb_strlen( $reason ) < 8 ) {
			throw new Fault( 'mgdd_reason', esc_html__( 'Alasan minimal 8 karakter diperlukan.', 'magaudev-donations' ) );
		}
		$exists = Db::wp()->get_var( Db::wp()->prepare( 'SELECT id FROM ' . Db::table( 'donors' ) . ' WHERE id=%d', $id ) );
		if ( ! $exists ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Profil tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		Privacy::anonymize_profile( $id );
		Audit::log( 'admin', 'donor.anonymized', $id, $reason, true );
		Reports::flush();
		return self::donor_detail( $id );
	}
	public static function csv_cell( mixed $value ): string {
		$value = (string) $value;
		return preg_match( '/^[\s]*[=+@\-\t\r\n]/u', $value ) ? "'" . $value : $value;
	}
	public static function export( array $args ): array {
		Limiter::enforce( 'export', 3, 3600, (string) get_current_user_id() );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $args['from'] ?? '' );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $args['to'] ?? '' );
		if ( ! $start || ! $end || $start > $end || $end->getTimestamp() - $start->getTimestamp() > 93 * DAY_IN_SECONDS ) {
			throw new Fault( 'mgdd_export_range', esc_html__( 'Pilih rentang ekspor maksimal 93 hari.', 'magaudev-donations' ) );
		}
		$db   = Db::wp();
		$rows = $db->get_results( $db->prepare( 'SELECT order_id,environment,status,method,source,amount,gross,refunded,paid_at FROM ' . Db::table( 'attempts' ) . ' WHERE created_at>=%s AND created_at<=%s ORDER BY id LIMIT 1001', $start->format( 'Y-m-d 00:00:00' ), $end->format( 'Y-m-d 23:59:59' ) ), ARRAY_A );
		if ( count( $rows ) > 1000 ) {
			throw new Fault( 'mgdd_export_limit', esc_html__( 'Hasil lebih dari 1000 baris. Persempit rentang.', 'magaudev-donations' ) );
		}
		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bounded memory stream, not a file on disk.
		fputcsv( $stream, [ 'order', 'environment', 'status', 'method', 'source', 'requested_idr', 'gross_idr', 'refunded_idr', 'paid_at_utc' ], ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $stream, array_map( [ self::class, 'csv_cell' ], $row ), ',', '"', '' );
		}
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes only the CSV memory stream.
		Audit::log( 'admin', 'transactions.exported', 0, count( $rows ) . ' baris tanpa PII.', true );
		return [
			'csv'  => $csv,
			'rows' => count( $rows ),
		];
	}
	public static function export_logs( array $args ): array {
		Limiter::enforce( 'log_export', 3, 3600, (string) get_current_user_id() );
		$from = \DateTimeImmutable::createFromFormat( '!Y-m-d', $args['from'] ?? '' );
		$to   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $args['to'] ?? '' );
		if ( ! $from || ! $to || $from > $to || $to->getTimestamp() - $from->getTimestamp() > 93 * DAY_IN_SECONDS ) {
			throw new Fault( 'mgdd_export_range', esc_html__( 'Pilih rentang ekspor maksimal 93 hari.', 'magaudev-donations' ) );
		}
		$db   = Db::wp();
		$rows = $db->get_results( $db->prepare( 'SELECT kind,action,target_id,severity,request_id,reason,created_at FROM ' . Db::table( 'audit' ) . ' WHERE created_at>=%s AND created_at<=%s ORDER BY id LIMIT 1001', $from->format( 'Y-m-d 00:00:00' ), $to->format( 'Y-m-d 23:59:59' ) ), ARRAY_A );
		if ( count( $rows ) > 1000 ) {
			throw new Fault( 'mgdd_export_limit', esc_html__( 'Hasil lebih dari 1000 baris. Persempit rentang.', 'magaudev-donations' ) );
		}
		$csv = 'kind,action,target,severity,request_id,reason,created_at_utc' . "\r\n";
		foreach ( $rows as $row ) {
			$csv .= implode( ',', array_map( static fn( $v ) => '"' . str_replace( '"', '""', self::csv_cell( $v ) ) . '"', $row ) ) . "\r\n";
		}
		Audit::log( 'admin', 'logs.exported', 0, count( $rows ) . ' baris tanpa IP/rahasia.', true );
		return [
			'csv'  => $csv,
			'rows' => count( $rows ),
		];
	}
	public static function wp_page(): void {
		if ( ! current_user_can( 'mgdd_settings' ) ) {
			return;
		}
		if ( isset( $_POST['mgdd_pause'] ) ) {
			check_admin_referer( 'mgdd_pause' );
			Config::save(
				[
					'revision' => Config::all()['revision'],
					'paused'   => true,
				]
			);
		}
		echo '<div class="wrap"><h1>Magaudev Donations ' . esc_html( MGDD_VERSION ) . '</h1><p>Konfigurasi rahasia di wp-config.php. Panel lengkap tersedia pada tema magaudev 1.0.6.</p><table class="widefat">';
		foreach ( Config::readiness() as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ? 'Siap' : 'Belum siap / nonaktif' ) . '</td></tr>';
		}
		echo '</table><p>Webhook: <code>' . esc_html( rest_url( Rest::NS . '/webhook' ) ) . '</code></p><form method="post">';
		wp_nonce_field( 'mgdd_pause' );
		echo '<button class="button" name="mgdd_pause" value="1">Jeda donasi baru</button></form></div>';
	}
}
