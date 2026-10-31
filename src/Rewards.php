<?php
/** Reward tiers, exactly-once evaluation and hand-off to the theme adapter. @package MagaudevDonations */
namespace MagaudevDonations;

final class Rewards {
	public const MAX_TIERS = 12;
	public const MAX_TRIES = 8;
	public static function defaults(): array {
		return [
			'enabled' => false,
			'since'   => null,
			'tiers'   => [],
		];
	}
	/** Registry of grantable item types; the theme adapter may extend or relabel it. */
	public static function types(): array {
		$types = [
			'xp'   => [
				'label' => 'XP',
				'max'   => 100000,
			],
			'coin' => [
				'label' => 'Koin',
				'max'   => 100000,
			],
		];
		$types = apply_filters( 'mgdd_reward_types', $types );
		$clean = [];
		foreach ( (array) $types as $id => $type ) {
			if ( is_string( $id ) && preg_match( '/^[a-z_]{1,16}$/D', $id ) && is_array( $type ) ) {
				$clean[ $id ] = [
					'label' => mb_substr( sanitize_text_field( (string) ( $type['label'] ?? $id ) ), 0, 24 ),
					'max'   => max( 1, min( 100000, (int) ( $type['max'] ?? 100000 ) ) ),
				];
			}
		}
		return $clean;
	}
	/** Whole number inside bounds, reported as a reward configuration error. */
	private static function whole( mixed $value, int $min, int $max ): int {
		try {
			return Money::integer( $value, $min, $max );
		} catch ( Fault $e ) {
			throw new Fault( 'mgdd_rewards', esc_html__( 'Nominal atau jumlah hadiah di luar batas yang diizinkan.', 'magaudev-donations' ) );
		}
	}
	private static function items( mixed $input, array $types ): array {
		if ( ! is_array( $input ) || count( $input ) > count( $types ) ) {
			throw new Fault( 'mgdd_rewards', esc_html__( 'Daftar item hadiah tidak valid.', 'magaudev-donations' ) );
		}
		$items = [];
		foreach ( $input as $item ) {
			$type = is_array( $item ) ? ( $item['type'] ?? null ) : null;
			if ( ! is_string( $type ) || ! isset( $types[ $type ] ) || isset( $items[ $type ] ) ) {
				throw new Fault( 'mgdd_rewards', esc_html__( 'Jenis hadiah tidak dikenal atau ganda.', 'magaudev-donations' ) );
			}
			$amount = self::whole( $item['amount'] ?? null, 0, $types[ $type ]['max'] );
			if ( $amount > 0 ) {
				$items[ $type ] = [
					'type'   => $type,
					'amount' => $amount,
				];
			}
		}
		if ( ! $items ) {
			throw new Fault( 'mgdd_rewards', esc_html__( 'Setiap tingkat hadiah harus memberi minimal satu item lebih dari nol.', 'magaudev-donations' ) );
		}
		return array_values( $items );
	}
	/** Validate and normalise the reward section. Tier ids are server issued and stable. */
	public static function sanitize( mixed $input, array $old ): array {
		if ( ! is_array( $input ) || ! is_bool( $input['enabled'] ?? false ) || ! is_array( $input['tiers'] ?? [] ) || count( $input['tiers'] ?? [] ) > self::MAX_TIERS || ! array_is_list( $input['tiers'] ?? [] ) ) {
			throw new Fault( 'mgdd_rewards', esc_html__( 'Pengaturan hadiah tidak valid atau melebihi 12 tingkat.', 'magaudev-donations' ) );
		}
		$enabled = (bool) ( $input['enabled'] ?? false );
		$known   = array_column( (array) ( $old['tiers'] ?? [] ), 'id' );
		$types   = self::types();
		$tiers   = [];
		$ids     = [];
		$unique  = [];
		foreach ( $input['tiers'] ?? [] as $tier ) {
			if ( ! is_array( $tier ) || ! is_bool( $tier['active'] ?? true ) ) {
				throw new Fault( 'mgdd_rewards', esc_html__( 'Tingkat hadiah tidak valid.', 'magaudev-donations' ) );
			}
			$label = mb_substr( sanitize_text_field( (string) ( $tier['label'] ?? '' ) ), 0, 40 );
			$basis = $tier['basis'] ?? '';
			if ( '' === trim( $label ) || ! in_array( $basis, [ 'single', 'total' ], true ) ) {
				throw new Fault( 'mgdd_rewards', esc_html__( 'Nama dan dasar hitung tingkat hadiah wajib valid.', 'magaudev-donations' ) );
			}
			$min = self::whole( $tier['min_amount'] ?? null, 1, 10000000000 );
			if ( isset( $unique[ $basis . ':' . $min ] ) ) {
				throw new Fault( 'mgdd_rewards', esc_html__( 'Nominal minimum harus unik untuk setiap dasar hitung.', 'magaudev-donations' ) );
			}
			$unique[ $basis . ':' . $min ] = true;
			$id                            = $tier['id'] ?? '';
			if ( ! is_string( $id ) || ! in_array( $id, $known, true ) || isset( $ids[ $id ] ) ) {
				$id = 't' . bin2hex( random_bytes( 6 ) );
			}
			$ids[ $id ] = true;
			$tiers[]    = [
				'id'         => $id,
				'label'      => $label,
				'basis'      => $basis,
				'min_amount' => $min,
				'active'     => (bool) ( $tier['active'] ?? true ),
				'items'      => self::items( $tier['items'] ?? null, $types ),
			];
		}
		$since = $old['since'] ?? null;
		if ( $enabled && ( empty( $old['enabled'] ) || ! $since ) ) {
			$since = Db::now();
		}
		return [
			'enabled' => $enabled,
			'since'   => $since,
			'tiers'   => $tiers,
		];
	}
	/** Advisory only: the editor shows these, saving is never blocked by them. */
	public static function warnings( array $rewards, array $config ): array {
		$out    = [];
		$tiers  = array_values( array_filter( $rewards['tiers'], static fn( $t ) => $t['active'] ) );
		$single = array_values( array_filter( $tiers, static fn( $t ) => 'single' === $t['basis'] ) );
		usort( $single, static fn( $a, $b ) => $a['min_amount'] <=> $b['min_amount'] );
		foreach ( $single as $i => $tier ) {
			if ( $tier['min_amount'] > $config['maximum'] ) {
				$out[] = [
					'tier' => $tier['id'],
					'code' => 'unreachable',
				];
			}
			if ( $i > 0 && self::weight( $tier ) < self::weight( $single[ $i - 1 ] ) ) {
				$out[] = [
					'tier' => $tier['id'],
					'code' => 'lower_than_previous',
				];
			}
		}
		return $out;
	}
	private static function weight( array $tier ): int {
		return array_sum( array_column( $tier['items'], 'amount' ) );
	}
	/** Public ladder, only while the feature is on and has at least one active tier. */
	public static function public_data(): ?array {
		$r = Config::all()['rewards'];
		if ( empty( $r['enabled'] ) ) {
			return null;
		}
		$tiers = [];
		foreach ( $r['tiers'] as $tier ) {
			if ( $tier['active'] ) {
				$tiers[] = array_intersect_key( $tier, array_flip( [ 'id', 'label', 'basis', 'min_amount', 'items' ] ) );
			}
		}
		usort( $tiers, static fn( $a, $b ) => [ $a['basis'], $a['min_amount'] ] <=> [ $b['basis'], $b['min_amount'] ] );
		return $tiers ? [ 'tiers' => $tiers ] : null;
	}
	public static function admin_data(): array {
		$c     = Config::all();
		$types = [];
		foreach ( self::types() as $id => $type ) {
			$types[] = [
				'id'    => $id,
				'label' => $type['label'],
				'max'   => $type['max'],
			];
		}
		return $c['rewards'] + [
			'revision' => (int) $c['revision'],
			'types'    => $types,
			'limits'   => [
				'max_tiers' => self::MAX_TIERS,
				'minimum'   => $c['minimum'],
				'maximum'   => $c['maximum'],
			],
			'warnings' => self::warnings( $c['rewards'], $c ),
			'adapter'  => false !== has_filter( 'mgdd_reward_grant' ),
		];
	}
	public static function save( array $input ): array {
		Config::save(
			[
				'revision' => $input['revision'] ?? 0,
				'rewards'  => $input['rewards'] ?? null,
			]
		);
		return self::admin_data();
	}
	/** The attempt row joined to its intent and donor, or null. */
	private static function context( int $attempt_id ): ?array {
		$db  = Db::wp();
		$row = $db->get_row( $db->prepare( 'SELECT a.*,i.donor_id,i.reference,d.user_id FROM ' . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . ' i ON i.id=a.intent_id JOIN ' . Db::table( 'donors' ) . ' d ON d.id=i.donor_id WHERE a.id=%d', $attempt_id ), ARRAY_A );
		return $row ? $row : null;
	}
	private static function net( array $row ): int {
		return max( 0, (int) $row['gross'] - (int) $row['refunded'] );
	}
	private static function eligible( array $row ): bool {
		if ( 'production' !== $row['environment'] || self::net( $row ) < 1 || $row['voided_at'] ) {
			return false;
		}
		return 'manual' !== $row['source'] || ( (int) $row['reward'] && $row['user_id'] );
	}
	/** Lifetime net support of one donor profile that counts toward reward totals. */
	public static function total( int $donor_id ): int {
		$db = Db::wp();
		return (int) $db->get_var( $db->prepare( 'SELECT COALESCE(SUM(a.gross-a.refunded),0) FROM ' . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . " i ON i.id=a.intent_id WHERE i.donor_id=%d AND a.environment='production' AND a.gross>a.refunded AND a.voided_at IS NULL AND (a.source='gateway' OR a.reward=1)", $donor_id ) );
	}
	private static function create( array $row, array $tier, string $owner ): int {
		$db  = Db::wp();
		$uid = $row['user_id'] ? (int) $row['user_id'] : 'NULL';
		$db->query(
			$db->prepare(
				'INSERT IGNORE INTO ' . Db::table( 'rewards' ) . " (attempt_id,intent_id,donor_id,user_id,tier_id,label,basis,min_amount,owner_key,items,status,next_try,created_at) VALUES (%d,%d,%d,$uid,%s,%s,%s,%d,%s,%s,%s,%s,%s)",
				$row['id'],
				$row['intent_id'],
				$row['donor_id'],
				$tier['id'],
				$tier['label'],
				$tier['basis'],
				$tier['min_amount'],
				$owner,
				wp_json_encode( $tier['items'] ),
				$row['user_id'] ? 'pending' : 'pending_claim',
				Db::now(),
				Db::now()
			)
		);
		return 1 === (int) $db->rows_affected ? (int) $db->insert_id : 0;
	}
	/** Create reward rows for one verified attempt; idempotent. Returns ids created now. */
	public static function evaluate( int $attempt_id ): array {
		$rewards = Config::all()['rewards'];
		if ( empty( $rewards['enabled'] ) ) {
			return [];
		}
		$row = self::context( $attempt_id );
		if ( ! $row || ! self::eligible( $row ) ) {
			return [];
		}
		$tiers = array_values( array_filter( $rewards['tiers'], static fn( $t ) => $t['active'] ) );
		usort( $tiers, static fn( $a, $b ) => $b['min_amount'] <=> $a['min_amount'] );
		$created = [];
		$net     = self::net( $row );
		$fresh   = 'manual' === $row['source'] || ( $rewards['since'] && $row['paid_at'] >= $rewards['since'] );
		if ( $fresh ) {
			foreach ( $tiers as $tier ) {
				if ( 'single' === $tier['basis'] && $tier['min_amount'] <= $net ) {
					$id = self::create( $row, $tier, 'a' . $attempt_id );
					if ( $id ) {
						$created[] = $id;
					}
					break;
				}
			}
		}
		$total = self::total( (int) $row['donor_id'] );
		$owner = $row['user_id'] ? 'u' . $row['user_id'] : 'd' . $row['donor_id'];
		foreach ( $tiers as $tier ) {
			if ( 'total' === $tier['basis'] && $tier['min_amount'] <= $total ) {
				$id = self::create( $row, $tier, $owner );
				if ( $id ) {
					$created[] = $id;
				}
			}
		}
		foreach ( $created as $id ) {
			Audit::log( 'payment', 'reward.created', $id, '', true );
			if ( $row['user_id'] ) {
				self::dispatch( $id );
			}
		}
		return $created;
	}
	/** Never lets a reward failure break payment, webhook or admin processing. */
	public static function evaluate_safe( int $attempt_id ): void {
		try {
			self::evaluate( $attempt_id );
		} catch ( \Throwable $e ) {
			try {
				Audit::log( 'payment', 'reward.error', $attempt_id, 'Evaluasi hadiah gagal; pembayaran tidak terpengaruh.', true, 'warning' );
			} catch ( \Throwable $inner ) {
				unset( $inner );
			}
		}
	}
	/** Hand one pending/failed reward to the adapter. Returns the resulting status. */
	public static function dispatch( int $id ): string {
		return Db::lock(
			'reward:' . $id,
			static function () use ( $id ): string {
				$db  = Db::wp();
				$r   = Db::table( 'rewards' );
				$row = $db->get_row( $db->prepare( "SELECT * FROM $r WHERE id=%d", $id ), ARRAY_A );
				if ( ! $row ) {
					throw new Fault( 'mgdd_not_found', esc_html__( 'Hadiah tidak ditemukan.', 'magaudev-donations' ), 404 );
				}
				if ( ! in_array( $row['status'], [ 'pending', 'failed' ], true ) || ! $row['user_id'] ) {
					return $row['status'];
				}
				$context = self::context( (int) $row['attempt_id'] );
				$reason  = '';
				if ( ! $context ) {
					$reason = 'transaksi_hilang';
				} elseif ( 'single' === $row['basis'] && ! self::eligible( $context ) ) {
					$reason = 'tidak_layak_saat_pemberian';
				} elseif ( 'total' === $row['basis'] && self::total( (int) $row['donor_id'] ) < (int) $row['min_amount'] ) {
					$reason = 'total_di_bawah_ambang';
				}
				if ( '' !== $reason ) {
					Db::update(
						'rewards',
						[
							'status' => 'skipped',
							'reason' => $reason,
						],
						[ 'id' => $id ]
					);
					return 'skipped';
				}
				$payload = [
					'id'        => (int) $row['id'],
					'user_id'   => (int) $row['user_id'],
					'tier_id'   => $row['tier_id'],
					'label'     => $row['label'],
					'basis'     => $row['basis'],
					'items'     => json_decode( $row['items'], true ),
					'reference' => $context['reference'],
					'amount'    => self::net( $context ),
				];
				try {
					$result = apply_filters( 'mgdd_reward_grant', null, $payload );
				} catch ( \Throwable $e ) {
					$result = false;
				}
				if ( true === $result ) {
					Db::update(
						'rewards',
						[
							'status'     => 'granted',
							'reason'     => '',
							'granted_at' => Db::now(),
						],
						[ 'id' => $id ]
					);
					Audit::log( 'payment', 'reward.granted', $id, '', true );
					return 'granted';
				}
				$tries = (int) $row['tries'] + ( false === $result ? 1 : 0 );
				Db::update(
					'rewards',
					[
						'status'   => false === $result ? 'failed' : 'pending',
						'reason'   => false === $result ? 'adapter_gagal' : 'menunggu_adapter_tema',
						'tries'    => $tries,
						'next_try' => gmdate( 'Y-m-d H:i:s', time() + min( 21600, 300 * ( 2 ** min( $tries, 6 ) ) ) ),
					],
					[ 'id' => $id ]
				);
				if ( false === $result ) {
					Audit::log( 'payment', 'reward.failed', $id, '', true, 'warning' );
				}
				return false === $result ? 'failed' : 'pending';
			}
		);
	}
	/** Called inside the identity claim transaction: move reward ownership to the member profile. */
	public static function reassign( int $from, int $to, int $user ): void {
		$db   = Db::wp();
		$r    = Db::table( 'rewards' );
		$rows = $db->get_results( $db->prepare( "SELECT id,tier_id,basis,status FROM $r WHERE donor_id=%d FOR UPDATE", $from ), ARRAY_A );
		foreach ( $rows as $row ) {
			$data = [
				'donor_id' => $to,
				'user_id'  => $user,
			];
			if ( 'total' === $row['basis'] ) {
				$taken = $db->get_var( $db->prepare( "SELECT id FROM $r WHERE tier_id=%s AND owner_key=%s", $row['tier_id'], 'u' . $user ) );
				if ( $taken ) {
					$data['status'] = 'skipped';
					$data['reason'] = 'sudah_diberikan_ke_akun';
				} else {
					$data['owner_key'] = 'u' . $user;
				}
			}
			if ( ! isset( $data['status'] ) && 'pending_claim' === $row['status'] ) {
				$data['status'] = 'pending';
			}
			Db::update( 'rewards', $data, [ 'id' => $row['id'] ] );
		}
	}
	/** After a history claim: hand over pending rewards and re-check cumulative tiers for the member. */
	public static function after_claim( int $donor_id ): void {
		try {
			$db   = Db::wp();
			$ids  = $db->get_col( $db->prepare( 'SELECT id FROM ' . Db::table( 'rewards' ) . " WHERE donor_id=%d AND status IN ('pending','failed') ORDER BY id LIMIT 50", $donor_id ) );
			$last = (int) $db->get_var( $db->prepare( 'SELECT a.id FROM ' . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . ' i ON i.id=a.intent_id WHERE i.donor_id=%d AND a.gross>a.refunded ORDER BY a.id DESC LIMIT 1', $donor_id ) );
			if ( $last ) {
				self::evaluate( $last );
			}
			foreach ( $ids as $id ) {
				self::dispatch( (int) $id );
			}
		} catch ( \Throwable $e ) {
			Audit::log( 'payment', 'reward.error', $donor_id, 'Penyerahan hadiah setelah klaim gagal.', true, 'warning' );
		}
	}
	/** Reconciliation job: retry rows the adapter has not accepted yet. */
	public static function run_pending(): array {
		$db   = Db::wp();
		$ids  = $db->get_col( $db->prepare( 'SELECT id FROM ' . Db::table( 'rewards' ) . " WHERE status IN ('pending','failed') AND user_id IS NOT NULL AND next_try<=%s AND tries<%d ORDER BY next_try,id LIMIT 20", Db::now(), self::MAX_TRIES ) );
		$done = 0;
		foreach ( $ids as $id ) {
			try {
				if ( 'granted' === self::dispatch( (int) $id ) ) {
					++$done;
				}
			} catch ( \Throwable $e ) {
				Audit::log( 'payment', 'reward.error', (int) $id, 'Percobaan ulang hadiah gagal.', false, 'warning' );
			}
		}
		return [
			'checked' => count( $ids ),
			'granted' => $done,
		];
	}
	/** Refunds never revoke rewards (append only ledger); admins must correct by hand. */
	public static function note_refund( int $attempt_id ): void {
		$db      = Db::wp();
		$granted = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'rewards' ) . " WHERE status='granted' AND (attempt_id=%d OR donor_id=(SELECT i.donor_id FROM " . Db::table( 'attempts' ) . ' a JOIN ' . Db::table( 'intents' ) . ' i ON i.id=a.intent_id WHERE a.id=%d))', $attempt_id, $attempt_id ) );
		if ( $granted > 0 ) {
			Audit::log( 'payment', 'reward.refund_after_grant', $attempt_id, 'Refund setelah hadiah diberikan; hadiah tidak ditarik otomatis, koreksi manual lewat detail member.', true, 'warning' );
		}
	}
	/** Void of a manual entry: pending rewards are skipped, granted ones only produce a warning. */
	public static function note_void( int $attempt_id ): void {
		$db = Db::wp();
		$db->query( $db->prepare( 'UPDATE ' . Db::table( 'rewards' ) . " SET status='skipped',reason='dibatalkan' WHERE attempt_id=%d AND status IN ('pending','pending_claim','failed')", $attempt_id ) );
		if ( (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'rewards' ) . " WHERE attempt_id=%d AND status='granted'", $attempt_id ) ) > 0 ) {
			Audit::log( 'admin', 'reward.void_after_grant', $attempt_id, 'Entri dibatalkan setelah hadiah diberikan; koreksi manual lewat detail member.', true, 'warning' );
		}
	}
	/** Owner view for the status endpoint. */
	public static function status_for( array $intent ): array {
		$db    = Db::wp();
		$rows  = $db->get_results( $db->prepare( 'SELECT r.status,r.label,r.items FROM ' . Db::table( 'rewards' ) . ' r JOIN ' . Db::table( 'attempts' ) . ' a ON a.id=r.attempt_id WHERE a.intent_id=%d AND r.status<>%s ORDER BY r.id', $intent['id'], 'skipped' ), ARRAY_A );
		$state = 'none';
		$sum   = [];
		$label = '';
		foreach ( [ 'granted', 'pending', 'pending_claim', 'failed' ] as $want ) {
			foreach ( $rows as $row ) {
				if ( $row['status'] === $want ) {
					$state = 'failed' === $want ? 'pending' : $want;
					$label = '' === $label ? $row['label'] : $label . ', ' . $row['label'];
					foreach ( (array) json_decode( $row['items'], true ) as $item ) {
						$sum[ $item['type'] ] = ( $sum[ $item['type'] ] ?? 0 ) + (int) $item['amount'];
					}
				}
			}
			if ( 'none' !== $state ) {
				break;
			}
		}
		$items = [];
		foreach ( $sum as $type => $amount ) {
			$items[] = [
				'type'   => $type,
				'amount' => $amount,
			];
		}
		return [
			'state'      => $state,
			'tier_label' => $label,
			'items'      => $items,
			'xp'         => $sum['xp'] ?? 0,
			'coin'       => $sum['coin'] ?? 0,
		];
	}
	public static function log( array $args ): array {
		$db    = Db::wp();
		$page  = max( 1, min( 10000, (int) ( $args['page'] ?? 1 ) ) );
		$where = '1=1';
		if ( ! empty( $args['status'] ) && in_array( $args['status'], [ 'pending_claim', 'pending', 'granted', 'failed', 'skipped' ], true ) ) {
			$where .= $db->prepare( ' AND r.status=%s', $args['status'] );
		}
		$r     = Db::table( 'rewards' );
		$join  = 'JOIN ' . Db::table( 'intents' ) . ' i ON i.id=r.intent_id';
		$total = (int) $db->get_var( "SELECT COUNT(*) FROM $r r $join WHERE $where" );
		$items = $db->get_results( $db->prepare( "SELECT r.id,r.attempt_id,r.tier_id,r.label,r.basis,r.min_amount,r.items,r.status,r.reason,r.tries,r.user_id,r.created_at,r.granted_at,i.alias FROM $r r $join WHERE $where ORDER BY r.id DESC LIMIT 30 OFFSET %d", ( $page - 1 ) * 30 ), ARRAY_A );
		foreach ( $items as &$item ) {
			$item['items'] = json_decode( $item['items'], true );
		}
		unset( $item );
		return [
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $total / 30 ) ),
		];
	}
	public static function retry( int $id ): array {
		$row = Db::wp()->get_row( Db::wp()->prepare( 'SELECT id,status,user_id FROM ' . Db::table( 'rewards' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $row ) {
			throw new Fault( 'mgdd_not_found', esc_html__( 'Hadiah tidak ditemukan.', 'magaudev-donations' ), 404 );
		}
		if ( ! in_array( $row['status'], [ 'pending', 'failed' ], true ) || ! $row['user_id'] ) {
			throw new Fault( 'mgdd_reward_state', esc_html__( 'Hanya hadiah menunggu atau gagal yang punya member tujuan yang dapat dicoba ulang.', 'magaudev-donations' ), 409 );
		}
		Limiter::enforce( 'reward_retry', 20, 60, (string) get_current_user_id() );
		Db::update( 'rewards', [ 'tries' => 0 ], [ 'id' => $id ] );
		$status = self::dispatch( $id );
		Audit::log( 'admin', 'reward.retry', $id, '', true );
		return [ 'status' => $status ];
	}
	/** Rows for WordPress privacy export/erasure. */
	public static function export_rows( int $donor_id ): array {
		$db = Db::wp();
		return $db->get_results( $db->prepare( 'SELECT id,label,basis,status,created_at,granted_at FROM ' . Db::table( 'rewards' ) . ' WHERE donor_id=%d ORDER BY id LIMIT 200', $donor_id ), ARRAY_A );
	}
}
