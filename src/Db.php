<?php
/** Checked SQL and short transactions. @package MagaudevDonations */
namespace MagaudevDonations;

final class Db {
	public static function wp(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
	public static function table( string $name ): string {
		$ns = defined( 'MGDD_TABLE_NS' ) ? MGDD_TABLE_NS : 'mgdd_';
		if ( ! preg_match( '/^[a-z0-9_]+$/', $ns . $name ) ) {
			throw new \LogicException( 'Invalid table namespace' );
		}
		return self::wp()->prefix . $ns . $name;
	}
	public static function option( string $name ): string {
		return ( defined( 'MGDD_OPTION_NS' ) ? MGDD_OPTION_NS : 'mgdd_' ) . $name;
	}
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
	public static function check( mixed $result ): mixed {
		if ( false === $result ) {
			throw new Fault( 'mgdd_database', esc_html__( 'Penyimpanan sementara bermasalah. Silakan coba lagi.', 'magaudev-donations' ), 503 );
		}
		return $result;
	}
	public static function query( string $sql ): int {
		return (int) self::check( self::wp()->query( $sql ) );
	}
	public static function insert( string $table, array $data ): int {
		self::check( self::wp()->insert( self::table( $table ), $data ) );
		return (int) self::wp()->insert_id;
	}
	public static function update( string $table, array $data, array $where ): int {
		return (int) self::check( self::wp()->update( self::table( $table ), $data, $where ) );
	}
	public static function tx( callable $work ): mixed {
		self::query( 'START TRANSACTION' );
		try {
			$result = $work();
			self::query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			self::wp()->query( 'ROLLBACK' );
			throw $e;
		}
	}
	public static function lock( string $key, callable $work ): mixed {
		$db  = self::wp();
		$key = 'mgdd_' . substr( hash( 'sha256', self::table( 'intents' ) . $key ), 0, 50 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
			throw new Fault( 'mgdd_busy', esc_html__( 'Permintaan sedang diproses. Coba lagi sebentar.', 'magaudev-donations' ), 409 );
		}
		try {
			return $work();
		} finally {
			$db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}
}
