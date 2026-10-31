<?php
/** Public SQL aggregation; private contributions never disclose identity. @package MagaudevDonations */
namespace MagaudevDonations;

final class Reports {
	/** Supporters wall: consenting display names only, newest first; no email, IP or origin marker. */
	public static function recent(): array {
		$i    = Db::table( 'intents' );
		$a    = Db::table( 'attempts' );
		$rows = Db::wp()->get_results( "SELECT i.alias name,a.gross-a.refunded amount,a.paid_at paid_at FROM $a a INNER JOIN $i i ON i.id=a.intent_id WHERE a.environment='production' AND a.gross>a.refunded AND a.voided_at IS NULL AND i.public_name=1 ORDER BY a.paid_at DESC,a.id DESC LIMIT 12", ARRAY_A );
		return array_map(
			static fn( $r ) => [
				'name'    => $r['name'],
				'amount'  => (int) $r['amount'],
				'paid_at' => $r['paid_at'],
			],
			$rows
		);
	}
	/** Public answers are computed live; this only bumps a revision and notifies any cache layer. */
	public static function flush(): void {
		update_option( Db::option( 'public_rev' ), time(), false );
		do_action( 'mgdd_public_changed' );
	}
	public static function messages(): array {
		$i = Db::table( 'intents' );
		$a = Db::table( 'attempts' );
		return Db::wp()->get_results( "SELECT i.message,CASE WHEN i.public_name=1 THEN i.alias ELSE 'Anonim' END name FROM $i i WHERE i.public_message=1 AND i.moderated=1 AND i.message<>'' AND EXISTS (SELECT 1 FROM $a a WHERE a.intent_id=i.id AND a.environment='production' AND a.gross>a.refunded AND a.voided_at IS NULL) ORDER BY i.id DESC LIMIT 12", ARRAY_A );
	}
	public static function timezone(): \DateTimeZone {
		$name = apply_filters( 'mgdd_timezone', wp_timezone_string() ? wp_timezone_string() : 'Asia/Makassar' );
		try {
			return new \DateTimeZone( $name );
		} catch ( \Throwable $e ) {
			return new \DateTimeZone( 'Asia/Makassar' );
		}
	}
	public static function month( ?\DateTimeImmutable $now = null ): array {
		$now   = ( $now ?? new \DateTimeImmutable( 'now', self::timezone() ) )->setTimezone( self::timezone() );
		$start = $now->modify( 'first day of this month' )->setTime( 0, 0 );
		return [ $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $start->modify( '+1 month' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ];
	}
	public static function summary(): array {
		$table = Db::table( 'attempts' );
		$row   = Db::wp()->get_row( "SELECT COALESCE(SUM(gross),0) gross,COALESCE(SUM(refunded),0) refunded,COALESCE(SUM(gross-refunded),0) total,COUNT(CASE WHEN gross>0 THEN 1 END) payments FROM $table WHERE environment='production'", ARRAY_A );
		return array_map( 'intval', $row ) + [
			'updated_at' => Db::now(),
			'timezone'   => self::timezone()->getName(),
			'fees'       => null,
			'net'        => null,
		];
	}
	public static function top( string $period = 'month', int $page = 1 ): array {
		$db    = Db::wp();
		$a     = Db::table( 'attempts' );
		$i     = Db::table( 'intents' );
		$where = "a.environment='production' AND a.gross>a.refunded AND a.voided_at IS NULL AND i.public_name=1";
		if ( 'month' === $period ) {
			[ $start, $end ] = self::month();
			$where          .= $db->prepare( ' AND a.paid_at>=%s AND a.paid_at<%s', $start, $end );
		} elseif ( 'all' !== $period ) {
			throw new Fault( 'mgdd_period', esc_html__( 'Periode tidak valid.', 'magaudev-donations' ) );
		}
		$group = "SELECT i.donor_id,MAX(i.alias) alias,SUM(a.gross-a.refunded) total FROM $a a INNER JOIN $i i ON i.id=a.intent_id WHERE $where GROUP BY i.donor_id";
		$total = (int) $db->get_var( "SELECT COUNT(*) FROM ($group) profiles" );
		$page  = max( 1, min( 10000, $page ) );
		$rows  = $db->get_results( $db->prepare( "SELECT alias,total,rank_number FROM (SELECT donor_id,alias,total,RANK() OVER (ORDER BY total DESC) rank_number FROM ($group) contributions) ranked ORDER BY total DESC,donor_id ASC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
		return [
			'items'      => array_map(
				static fn( $r ) => [
					'name'   => $r['alias'],
					'amount' => (int) $r['total'],
					'rank'   => (int) $r['rank_number'],
				],
				$rows
			),
			'profiles'   => $total,
			'pages'      => max( 1, (int) ceil( $total / 20 ) ),
			'page'       => $page,
			'period'     => $period,
			'timezone'   => self::timezone()->getName(),
			'updated_at' => Db::now(),
		];
	}
}
