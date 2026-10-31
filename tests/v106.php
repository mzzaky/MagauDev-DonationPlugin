<?php
// Included by run.php (global scope): helpers and tests for schema 2, rewards and manual donors.
use MagaudevDonations\{Admin,Audit,Config,Db,Donations,Fault,Identity,Jobs,Manual,Payments,Privacy,Reports,Rest,Rewards,Schema};

$GLOBALS['v106_grant'] = 'ok';
$GLOBALS['v106_calls'] = [];
add_filter(
	'mgdd_reward_grant',
	static function ( $result, $payload ) {
		$mode = $GLOBALS['v106_grant'];
		if ( 'absent' === $mode ) {
			return $result;
		}
		$GLOBALS['v106_calls'][] = $payload;
		if ( 'throw' === $mode ) {
			throw new RuntimeException( 'adapter down' );
		}
		return 'ok' === $mode;
	},
	10,
	2
);
/** Lepas sementara adapter tema agar skenario "tanpa adapter" benar-benar tanpa handler. */
function v106_theme_off() {
	$callback = [ 'Magaudev\Donations\Rewards', 'grant' ];
	$had      = class_exists( $callback[0] ) && false !== has_filter( 'mgdd_reward_grant', $callback );
	if ( $had ) {
		remove_filter( 'mgdd_reward_grant', $callback, 10 );
	}
	return $had;
}
function v106_theme_on( $had ) {
	if ( $had ) {
		add_filter( 'mgdd_reward_grant', [ 'Magaudev\Donations\Rewards', 'grant' ], 10, 2 );
	}
}
function v106_user( $role = 'mgd_member' ) {
	global $fixtureUsers;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$id = wp_insert_user( [ 'user_login' => 'mgdd_v106_' . bin2hex( random_bytes( 5 ) ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'mgdd-v106-' . bin2hex( random_bytes( 5 ) ) . '@example.invalid', 'display_name' => 'Pendukung ' . bin2hex( random_bytes( 2 ) ), 'role' => $role ] );
	check( ! is_wp_error( $id ), 'fixture user' );
	$fixtureUsers[] = $id;
	return $id;
}
function v106_donor_for( $user_id ) {
	wp_set_current_user( $user_id );
	$donor = Db::tx( fn() => Identity::donor( 'donor-' . $user_id . '@example.invalid', 'Member ' . $user_id ) );
	wp_set_current_user( 0 );
	return $donor;
}
function v106_guest_donor() {
	return Db::tx( fn() => Identity::donor( 'guest-' . bin2hex( random_bytes( 4 ) ) . '@example.invalid', 'Tamu Uji' ) );
}
function v106_attempt( array $donor, $amount, $env = 'production', $public = 1 ) {
	$ref = bin2hex( random_bytes( 16 ) );
	$iid = Db::insert( 'intents', [ 'reference' => $ref, 'donor_id' => $donor['id'], 'scope_hash' => hash( 'sha256', 's' . $ref ), 'idempotency_hash' => hash( 'sha256', 'i' . $ref ), 'payload_hash' => hash( 'sha256', 'p' . $ref ), 'amount' => $amount, 'alias' => 'Uji ' . substr( $ref, 0, 4 ), 'message' => '', 'public_name' => $public, 'public_message' => 0, 'created_at' => Db::now() ] );
	$a   = Donations::reserve( [ 'id' => $iid, 'reference' => $ref, 'amount' => $amount ], 1 );
	if ( 'production' === $env ) {
		Db::update( 'attempts', [ 'environment' => 'production', 'merchant' => 'fixture-production' ], [ 'id' => $a['id'] ] );
		$a['environment'] = 'production';
		$a['merchant']    = 'fixture-production';
	}
	return $a;
}
function v106_pay( array $a, $state = 'settlement', $refund = 0 ) {
	$p                    = gateway_payload( $a, $state, $refund );
	$p['settlement_time'] = $p['transaction_time'] = gmdate( 'Y-m-d H:i:s', time() + 7 * 3600 );
	return Payments::apply( (int) $a['id'], $p, 'fixture' );
}
function v106_tier( $label, $basis, $min, $xp, $coin, $active = true ) {
	return [ 'label' => $label, 'basis' => $basis, 'min_amount' => $min, 'active' => $active, 'items' => [ [ 'type' => 'xp', 'amount' => $xp ], [ 'type' => 'coin', 'amount' => $coin ] ] ];
}
function v106_config( array $tiers, $enabled = true ) {
	return Config::save( [ 'revision' => Config::all()['revision'], 'rewards' => [ 'enabled' => $enabled, 'tiers' => $tiers ] ] );
}
function v106_local( $seconds_ago = 0 ) {
	return ( new DateTimeImmutable( 'now', Reports::timezone() ) )->modify( '-' . (int) $seconds_ago . ' seconds' )->format( 'Y-m-d H:i:s' );
}
function v106_top_names() {
	$names = [];
	$pages = 1;
	for ( $page = 1; $page <= $pages; $page++ ) {
		$top   = Reports::top( 'all', $page );
		$pages = $top['pages'];
		$names = array_merge( $names, array_column( $top['items'], 'name' ) );
	}
	return $names;
}
function v106_rows( $where = '1=1' ) {
	return Db::wp()->get_results( 'SELECT * FROM ' . Db::table( 'rewards' ) . " WHERE $where ORDER BY id", ARRAY_A );
}
function v106_rebuilt_equals( $attempt_id ) {
	$before = Db::wp()->get_row( Db::wp()->prepare( 'SELECT gross,refunded FROM ' . Db::table( 'attempts' ) . ' WHERE id=%d', $attempt_id ), ARRAY_A );
	Payments::rebuild();
	$after = Db::wp()->get_row( Db::wp()->prepare( 'SELECT gross,refunded FROM ' . Db::table( 'attempts' ) . ' WHERE id=%d', $attempt_id ), ARRAY_A );
	return $before === $after;
}

test( 'schema 2 migrates from 1 non destructively, twice, and rewrites only the untouched 1.0.5 FAQ', function () {
	$db   = Db::wp();
	$keep = v106_attempt( v106_guest_donor(), 10000 );
	$db->query( 'ALTER TABLE ' . Db::table( 'attempts' ) . ' DROP KEY origin, DROP COLUMN source, DROP COLUMN voided_at, DROP COLUMN reward, DROP COLUMN revision' );
	$db->query( 'ALTER TABLE ' . Db::table( 'donors' ) . ' DROP COLUMN is_manual' );
	$db->query( 'ALTER TABLE ' . Db::table( 'intents' ) . ' DROP COLUMN note' );
	$db->query( 'DROP TABLE ' . Db::table( 'rewards' ) );
	update_option( Db::option( 'schema' ), 1, false );
	update_option( Db::option( 'settings' ), array_replace( Config::all(), [ 'faq' => Schema::FAQ_105 ] ), false );
	$revision = Config::all()['revision'];
	Schema::install();
	Schema::install();
	foreach ( [ 'attempts' => [ 'source', 'voided_at', 'reward', 'revision' ], 'donors' => [ 'is_manual' ], 'intents' => [ 'note' ] ] as $table => $columns ) {
		foreach ( $columns as $column ) {
			check( (bool) $db->get_var( $db->prepare( 'SHOW COLUMNS FROM ' . Db::table( $table ) . ' LIKE %s', $column ) ), "column $table.$column" );
		}
	}
	check( (bool) $db->get_var( 'SHOW TABLES LIKE "' . Db::table( 'rewards' ) . '"' ), 'rewards table' );
	check( 'gateway' === $db->get_var( 'SELECT source FROM ' . Db::table( 'attempts' ) . ' WHERE id=' . (int) $keep['id'] ), 'old rows stay gateway' );
	check( (int) get_option( Db::option( 'schema' ) ) === 2, 'version 2' );
	check( ! str_contains( Config::all()['faq'], 'tidak memberi XP' ) && Config::all()['revision'] === $revision + 1, 'identical FAQ replaced once' );
	update_option( Db::option( 'settings' ), array_replace( Config::all(), [ 'faq' => 'FAQ kustom admin' ] ), false );
	Schema::install();
	check( 'FAQ kustom admin' === Config::all()['faq'], 'custom FAQ untouched' );
	check( in_array( 'mgdd_manual', Schema::CAPS, true ), 'new capability declared' );
} );
test( 'reward config: defaults off, strict validation, optimistic revision, stable server ids', function () {
	check( false === Config::all()['rewards']['enabled'] && [] === Config::all()['rewards']['tiers'], 'default off without sample tiers' );
	check( null === Config::public_data()['rewards'], 'nothing public while off' );
	$bad = [
		[ 'label' => '', 'basis' => 'single', 'min_amount' => 1000, 'items' => [ [ 'type' => 'xp', 'amount' => 1 ] ] ],
		[ 'label' => 'A', 'basis' => 'weekly', 'min_amount' => 1000, 'items' => [ [ 'type' => 'xp', 'amount' => 1 ] ] ],
		[ 'label' => 'A', 'basis' => 'single', 'min_amount' => 0, 'items' => [ [ 'type' => 'xp', 'amount' => 1 ] ] ],
		[ 'label' => 'A', 'basis' => 'single', 'min_amount' => 1000, 'items' => [ [ 'type' => 'xp', 'amount' => 0 ] ] ],
		[ 'label' => 'A', 'basis' => 'single', 'min_amount' => 1000, 'items' => [ [ 'type' => 'badge', 'amount' => 1 ] ] ],
		[ 'label' => 'A', 'basis' => 'single', 'min_amount' => 1000, 'items' => [ [ 'type' => 'xp', 'amount' => 100001 ] ] ],
		[ 'label' => 'A', 'basis' => 'single', 'min_amount' => 1000, 'items' => [ [ 'type' => 'xp', 'amount' => 1 ], [ 'type' => 'xp', 'amount' => 2 ] ] ],
	];
	foreach ( $bad as $tier ) {
		rejects( fn() => v106_config( [ $tier ] ), 'mgdd_rewards' );
	}
	rejects( fn() => v106_config( [ v106_tier( 'A', 'single', 1000, 1, 1 ), v106_tier( 'B', 'single', 1000, 2, 2 ) ] ), 'mgdd_rewards' );
	rejects( fn() => v106_config( array_map( fn( $i ) => v106_tier( 'T' . $i, 'single', 1000 + $i, 1, 0 ), range( 1, 13 ) ) ), 'mgdd_rewards' );
	$saved = v106_config( [ v106_tier( 'Satu', 'single', 1000, 5, 0 ), v106_tier( 'Satu total', 'total', 1000, 5, 1 ) ] );
	check( count( $saved['rewards']['tiers'] ) === 2 && null !== $saved['rewards']['since'], 'same minimum allowed across bases; since set on enable' );
	$ids = array_column( $saved['rewards']['tiers'], 'id' );
	$t   = $saved['rewards']['tiers'];
	$t[0]['id'] = 'forged-id';
	$again      = Config::save( [ 'revision' => $saved['revision'], 'rewards' => [ 'enabled' => true, 'tiers' => $t ] ] );
	check( $again['rewards']['tiers'][1]['id'] === $ids[1] && 'forged-id' !== $again['rewards']['tiers'][0]['id'], 'known ids stable, forged ids replaced' );
	check( $again['rewards']['since'] === $saved['rewards']['since'], 'since kept' );
	rejects( fn() => Config::save( [ 'revision' => $saved['revision'], 'rewards' => [ 'enabled' => false, 'tiers' => [] ] ] ), 'mgdd_revision' );
	$public = Config::public_data()['rewards'];
	check( count( $public['tiers'] ) === 2 && ! isset( $public['since'] ) && ! array_key_exists( 'active', $public['tiers'][0] ), 'public ladder is filtered' );
	$warn = Rewards::warnings( array_replace( Config::all()['rewards'], [ 'tiers' => [ array_replace( $again['rewards']['tiers'][0], [ 'min_amount' => 9000000 ] ) ] ] ), Config::all() );
	check( 'unreachable' === $warn[0]['code'], 'unreachable tier warned, not rejected' );
	Config::save( [ 'revision' => Config::all()['revision'], 'paused' => false ] );
	check( count( Config::all()['rewards']['tiers'] ) === 2, 'unrelated saves keep rewards' );
} );
test( 'single basis grants only the highest tier, once; duplicates and sandbox never reward', function () {
	v106_config( [ v106_tier( 'Kecil', 'single', 10000, 10, 1 ), v106_tier( 'Besar', 'single', 50000, 60, 5 ) ] );
	$user  = v106_user();
	$donor = v106_donor_for( $user );
	$GLOBALS['v106_grant'] = 'ok';
	$GLOBALS['v106_calls'] = [];
	$a = v106_attempt( $donor, 60000 );
	v106_pay( $a );
	v106_pay( $a );
	Payments::apply( (int) $a['id'], gateway_payload( $a, 'pending' ), 'fixture' );
	Rewards::evaluate_safe( (int) $a['id'] );
	$rows = v106_rows( 'attempt_id=' . (int) $a['id'] );
	check( count( $rows ) === 1 && 'Besar' === $rows[0]['label'] && 'granted' === $rows[0]['status'], 'only highest tier, granted' );
	check( count( $GLOBALS['v106_calls'] ) === 1 && $GLOBALS['v106_calls'][0]['user_id'] === $user && 60 === $GLOBALS['v106_calls'][0]['items'][0]['amount'], 'adapter called once with minimal payload' );
	check( ! isset( $GLOBALS['v106_calls'][0]['email'] ), 'payload has no email' );
	$small = v106_attempt( $donor, 20000 );
	v106_pay( $small );
	check( v106_rows( 'attempt_id=' . (int) $small['id'] )[0]['label'] === 'Kecil', 'smaller donation gets smaller tier' );
	$none = v106_attempt( $donor, 5000 );
	Db::update( 'attempts', [ 'amount' => 5000 ], [ 'id' => $none['id'] ] );
	$sand = v106_attempt( $donor, 99000, 'sandbox' );
	v106_pay( $sand );
	check( count( v106_rows( 'attempt_id=' . (int) $sand['id'] ) ) === 0, 'sandbox payment never rewards' );
	$status = Rewards::status_for( Donations::intent( Db::wp()->get_var( 'SELECT reference FROM ' . Db::table( 'intents' ) . ' WHERE id=' . (int) $a['intent_id'] ), false ) );
	check( 'granted' === $status['state'] && 60 === $status['xp'] && 5 === $status['coin'], 'owner status summarises the grant' );
	check( true === v106_rebuilt_equals( (int) $a['id'] ), 'rebuild unaffected by rewards' );
} );
test( 'rewards ignore donations made before the feature was enabled, and a disabled feature creates nothing', function () {
	$donor  = v106_donor_for( v106_user() );
	$old    = v106_attempt( $donor, 70000 );
	$cfg    = Config::all();
	update_option( Db::option( 'settings' ), array_replace( $cfg, [ 'rewards' => array_replace( $cfg['rewards'], [ 'since' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] ) ] ), false );
	v106_pay( $old );
	check( count( v106_rows( 'attempt_id=' . (int) $old['id'] ) ) === 0, 'paid before since: no single reward' );
	v106_config( Config::all()['rewards']['tiers'], false );
	$off = v106_attempt( $donor, 70000 );
	v106_pay( $off );
	check( count( v106_rows( 'attempt_id=' . (int) $off['id'] ) ) === 0, 'disabled: nothing created' );
	v106_config( Config::all()['rewards']['tiers'], true );
	$cfg = Config::all();
	update_option( Db::option( 'settings' ), array_replace( $cfg, [ 'rewards' => array_replace( $cfg['rewards'], [ 'since' => '2000-01-01 00:00:00' ] ) ] ), false );
} );
test( 'total basis grants each crossed threshold once per member', function () {
	v106_config( [ v106_tier( 'Kumulatif 100', 'total', 100000, 100, 10 ), v106_tier( 'Kumulatif 200', 'total', 200000, 250, 20 ) ] );
	$user  = v106_user();
	$donor = v106_donor_for( $user );
	$GLOBALS['v106_calls'] = [];
	$first = v106_attempt( $donor, 60000 );
	v106_pay( $first );
	check( count( v106_rows( 'donor_id=' . (int) $donor['id'] ) ) === 0, 'below threshold' );
	$second = v106_attempt( $donor, 60000 );
	v106_pay( $second );
	$rows = v106_rows( 'donor_id=' . (int) $donor['id'] );
	check( count( $rows ) === 1 && 'Kumulatif 100' === $rows[0]['label'] && 'u' . $user === $rows[0]['owner_key'], 'first threshold crossed' );
	$big = v106_attempt( $donor, 500000 );
	v106_pay( $big );
	v106_pay( $big );
	check( count( v106_rows( 'donor_id=' . (int) $donor['id'] ) ) === 2 && count( $GLOBALS['v106_calls'] ) === 2, 'second threshold once, first not repeated' );
} );
test( 'guest rewards wait as pending_claim and reach the member only after the history claim', function () {
	v106_config( [ v106_tier( 'Tamu', 'single', 10000, 30, 3 ), v106_tier( 'Total tamu', 'total', 40000, 80, 8 ) ] );
	$guest = v106_guest_donor();
	$a     = v106_attempt( $guest, 50000 );
	$GLOBALS['v106_calls'] = [];
	v106_pay( $a );
	$rows = v106_rows( 'donor_id=' . (int) $guest['id'] );
	check( count( $rows ) === 2 && array_unique( array_column( $rows, 'status' ) ) === [ 'pending_claim' ] && ! $GLOBALS['v106_calls'], 'held for claim, adapter untouched' );
	$user = v106_user();
	wp_set_current_user( $user );
	$member_total = v106_attempt( v106_donor_for( $user ), 45000 );
	wp_set_current_user( 0 );
	v106_pay( $member_total );
	$code = bin2hex( random_bytes( 32 ) );
	Db::insert( 'tokens', [ 'hash' => hash( 'sha256', $code ), 'donor_id' => $guest['id'], 'purpose' => 'verify', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'created_at' => Db::now() ] );
	session_fixture( 'g' );
	wp_set_current_user( $user );
	Identity::consume( $code, true );
	wp_set_current_user( 0 );
	$after = v106_rows( 'attempt_id=' . (int) $a['id'] );
	check( count( $after ) >= 1 && 'granted' === $after[0]['status'] && (int) $after[0]['user_id'] === $user, 'single reward granted to the claiming member' );
	$statuses = array_column( $after, 'status', 'label' );
	check( in_array( $statuses['Total tamu'] ?? 'skipped', [ 'granted', 'skipped' ], true ), 'cumulative tier either granted or skipped as duplicate, never pending_claim' );
	check( ! v106_rows( "status='pending_claim' AND donor_id=" . (int) $guest['id'] ), 'nothing left waiting' );
	session_fixture();
} );
test( 'adapter failure, absence and exceptions never affect payments; retry and job complete the grant', function () {
	$off = v106_theme_off();
	v106_config( [ v106_tier( 'Uji adapter', 'single', 10000, 10, 1 ) ] );
	$user = v106_user();
	foreach ( [ 'throw' => 'failed', 'fail' => 'failed', 'absent' => 'pending' ] as $mode => $expected ) {
		$GLOBALS['v106_grant'] = $mode;
		$donor                 = v106_donor_for( $user );
		$a                     = v106_attempt( $donor, 20000 );
		$row                   = v106_pay( $a );
		check( 'paid' === $row['status'] && (int) $row['gross'] === 20000, "payment intact ($mode)" );
		$reward = v106_rows( 'attempt_id=' . (int) $a['id'] )[0];
		check( $expected === $reward['status'], "status $expected for $mode" );
		if ( 'absent' !== $mode ) {
			check( (bool) Db::wp()->get_var( 'SELECT id FROM ' . Db::table( 'audit' ) . " WHERE action='reward.failed' AND target_id=" . (int) $reward['id'] ), 'failure audited' );
		}
		$GLOBALS['v106_grant'] = 'ok';
		check( 'granted' === Rewards::retry( (int) $reward['id'] )['status'], "admin retry grants ($mode)" );
		rejects( fn() => Rewards::retry( (int) $reward['id'] ), 'mgdd_reward_state' );
	}
	$GLOBALS['v106_grant'] = 'fail';
	$a                     = v106_attempt( v106_donor_for( $user ), 20000 );
	v106_pay( $a );
	$id = (int) v106_rows( 'attempt_id=' . (int) $a['id'] )[0]['id'];
	Db::update( 'rewards', [ 'next_try' => '2000-01-01 00:00:00' ], [ 'id' => $id ] );
	$GLOBALS['v106_grant'] = 'ok';
	check( Jobs::run()['rewards']['granted'] >= 1 && 'granted' === v106_rows( 'id=' . $id )[0]['status'], 'reconciliation retries failed rows' );
	v106_theme_on( $off );
} );
test( 'refund after grant is audited and never revoked; refund before delivery skips the reward', function () {
	v106_config( [ v106_tier( 'Refund', 'single', 10000, 10, 1 ) ] );
	$GLOBALS['v106_grant'] = 'ok';
	$a = v106_attempt( v106_donor_for( v106_user() ), 30000 );
	v106_pay( $a );
	v106_pay( $a, 'refund' );
	check( 'granted' === v106_rows( 'attempt_id=' . (int) $a['id'] )[0]['status'], 'ledger append only: still granted' );
	check( (bool) Db::wp()->get_var( 'SELECT id FROM ' . Db::table( 'audit' ) . " WHERE action='reward.refund_after_grant' AND target_id=" . (int) $a['id'] ), 'warning audited' );
	$off = v106_theme_off();
	$GLOBALS['v106_grant'] = 'absent';
	$b = v106_attempt( v106_donor_for( v106_user() ), 30000 );
	v106_pay( $b );
	v106_pay( $b, 'refund' );
	$GLOBALS['v106_grant'] = 'ok';
	$id = (int) v106_rows( 'attempt_id=' . (int) $b['id'] )[0]['id'];
	check( 'skipped' === Rewards::dispatch( $id ), 'fully refunded before delivery is skipped' );
	v106_theme_on( $off );
} );
test( 'parallel evaluation of one payment creates one reward and calls the adapter once', function () {
	v106_config( [ v106_tier( 'Paralel', 'single', 10000, 10, 1 ) ] );
	$donor = v106_donor_for( v106_user() );
	$a     = v106_attempt( $donor, 20000 );
	$cfg   = Config::all();
	$p     = gateway_payload( $a );
	$p['settlement_time'] = $p['transaction_time'] = gmdate( 'Y-m-d H:i:s', time() + 7 * 3600 );
	Db::query( 'SET @mgdd_ignore = 0' );
	Payments::apply( (int) $a['id'], $p, 'fixture' );
	Db::query( 'DELETE FROM ' . Db::table( 'rewards' ) . ' WHERE attempt_id=' . (int) $a['id'] );
	$results = parallel_workers( 'reward', [ (string) $a['id'] ] );
	check( array_sum( array_column( $results, 'called' ) ) === 1, 'adapter called exactly once across workers' );
	check( count( v106_rows( 'attempt_id=' . (int) $a['id'] ) ) === 1, 'one row' );
} );

$manual_user = v106_user();
$manual_ref  = null;
test( 'manual donor: validation, attestation, member link, idempotency and ledger event', function () use ( $manual_user, &$manual_ref ) {
	wp_set_current_user( v106_user( 'administrator' ) );
	$base = [ 'alias' => 'Donatur Tunai', 'amount' => 150000, 'paid_at' => v106_local( 0 ), 'reason' => 'Donasi tunai dicatat bendahara', 'public_name' => true, 'attest' => true ];
	foreach ( [ [ 'reason' => 'pendek' ], [ 'alias' => '' ], [ 'amount' => 0 ], [ 'amount' => 1000000001 ], [ 'amount' => 1.5 ], [ 'email' => 'bukan-email' ], [ 'attest' => false ], [ 'paid_at' => v106_local( -86400 ) ], [ 'paid_at' => 'kemarin' ], [ 'reward' => true ] ] as $change ) {
		$input = array_replace( $base, $change );
		try {
			Manual::create( $input );
			throw new RuntimeException( 'Expected rejection ' . wp_json_encode( $change ) );
		} catch ( Fault $e ) {
			check( str_starts_with( $e->key, 'mgdd_' ), 'rejected ' . wp_json_encode( $change ) );
		}
	}
	$key     = bin2hex( random_bytes( 12 ) );
	$created = Manual::create( $base + [ 'email' => 'Tunai@Example.Invalid', 'note' => 'Diterima langsung', 'request_key' => $key ] );
	$again   = Manual::create( $base + [ 'email' => 'Tunai@Example.Invalid', 'note' => 'Diterima langsung', 'request_key' => $key ] );
	check( $created['attempt']['id'] === $again['attempt']['id'], 'idempotent double submit' );
	$a = $created['attempt'];
	check( 'manual' === $a['source'] && 'paid' === $a['status'] && 150000 === (int) $a['gross'] && 'production' === $a['environment'], 'stored as paid production manual' );
	$row = Db::wp()->get_row( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE id=' . (int) $a['id'], ARRAY_A );
	check( null === $row['transaction_id'] && 'manual' === $row['merchant'] && 120 === (int) $row['sync_count'] && str_starts_with( $row['order_id'], 'manual-' ), 'manual markers' );
	check( $created['intent']['note'] === 'Diterima langsung', 'internal note kept for admin' );
	$manual_ref = (int) $a['id'];
	check( true === v106_rebuilt_equals( $manual_ref ), 'rebuild reproduces the manual amount' );
	$linked = Manual::create( [ 'alias' => 'Member Tertaut', 'amount' => 40000, 'paid_at' => v106_local( 600 ), 'reason' => 'Transfer bank dicatat manual', 'member_id' => $manual_user, 'reward' => true ] );
	check( (int) $linked['member']['id'] === $manual_user && 1 === (int) $linked['attempt']['reward'], 'linked to the member profile' );
	check( (int) Db::wp()->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'donors' ) . ' WHERE user_id=' . $manual_user ) === 1, 'UNIQUE(user_id) respected' );
	wp_set_current_user( 0 );
} );
test( 'manual donor update and void use ledger events, revisions and keep gateway rows immutable', function () use ( &$manual_ref ) {
	$admin = v106_user( 'administrator' );
	wp_set_current_user( $admin );
	$d = Admin::detail( $manual_ref );
	rejects( fn() => Manual::update( $manual_ref, [ 'revision' => $d['attempt']['revision'] + 5, 'reason' => 'koreksi nominal', 'amount' => 200000 ] ), 'mgdd_revision' );
	$u = Manual::update( $manual_ref, [ 'revision' => $d['attempt']['revision'], 'reason' => 'koreksi nominal bendahara', 'amount' => 200000, 'alias' => 'Donatur Tunai Baru' ] );
	check( 200000 === (int) $u['attempt']['gross'] && (int) $u['attempt']['revision'] === (int) $d['attempt']['revision'] + 1, 'amount changed, revision bumped' );
	$events = Db::wp()->get_results( 'SELECT gross_delta FROM ' . Db::table( 'events' ) . ' WHERE attempt_id=' . $manual_ref . ' ORDER BY id', ARRAY_A );
	check( array_column( $events, 'gross_delta' ) === [ '150000', '50000' ], 'adjustment is an event, not an overwrite' );
	check( true === v106_rebuilt_equals( $manual_ref ), 'rebuild equals after adjustment' );
	check( in_array( 'Donatur Tunai Baru', v106_top_names(), true ) && in_array( 'Donatur Tunai Baru', array_column( Reports::recent(), 'name' ), true ), 'public ranking and wall include the valid manual entry' );
	check( ! str_contains( wp_json_encode( [ Reports::top( 'all' ), Reports::recent(), Reports::messages(), Reports::summary(), Config::public_data() ] ), 'manual' ), 'public payloads never reveal the manual origin' );
	$gateway = Db::wp()->get_var( 'SELECT id FROM ' . Db::table( 'attempts' ) . " WHERE source='gateway' ORDER BY id DESC LIMIT 1" );
	rejects( fn() => Manual::update( (int) $gateway, [ 'revision' => 1, 'reason' => 'mencoba ubah gateway', 'amount' => 1 ] ), 'mgdd_not_manual' );
	rejects( fn() => Payments::sync( Db::wp()->get_row( 'SELECT * FROM ' . Db::table( 'attempts' ) . ' WHERE id=' . $manual_ref, ARRAY_A ) ), 'mgdd_manual_gateway' );
	rejects( fn() => Admin::action( $manual_ref, [ 'action' => 'sync', 'reason' => 'sinkron manual dilarang' ] ), 'mgdd_manual_gateway' );
	Jobs::run();
	check( 120 === (int) Db::wp()->get_var( 'SELECT sync_count FROM ' . Db::table( 'attempts' ) . ' WHERE id=' . $manual_ref ), 'reconciliation never touches manual entries' );
	check( 0 === (int) Db::wp()->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'audit' ) . " WHERE action='receipt.sent' AND target_id=" . $manual_ref ), 'no automatic receipt' );
	$list = Admin::listing( 'transactions', [ 'source' => 'manual' ] );
	check( count( $list['items'] ) >= 1 && 'manual' === $list['items'][0]['source'], 'listing exposes source to admins' );
	$csv = Admin::export( [ 'from' => gmdate( 'Y-m-d', time() - 86400 ), 'to' => gmdate( 'Y-m-d' ) ] )['csv'];
	check( str_contains( $csv, 'source' ) && str_contains( $csv, 'manual' ), 'CSV carries the source column' );
	$void_rev = Admin::detail( $manual_ref )['attempt']['revision'];
	$v        = Manual::void( $manual_ref, [ 'revision' => $void_rev, 'reason' => 'salah catat, dibatalkan' ] );
	check( null !== $v['attempt']['voided_at'] && 0 === (int) $v['attempt']['gross'] && 'voided' === $v['attempt']['status'], 'voided, gross zeroed by event' );
	check( true === v106_rebuilt_equals( $manual_ref ), 'rebuild equals after void' );
	check( ! in_array( 'Donatur Tunai Baru', v106_top_names(), true ) && ! in_array( 'Donatur Tunai Baru', array_column( Reports::recent(), 'name' ), true ), 'voided entry leaves ranking and wall' );
	rejects( fn() => Manual::update( $manual_ref, [ 'revision' => $void_rev + 1, 'reason' => 'ubah setelah dibatalkan' ] ), 'mgdd_voided' );
	check( ! in_array( $manual_ref, array_map( 'intval', array_column( Admin::listing( 'transactions', [] )['items'], 'id' ) ), true ), 'hidden from the default list' );
	check( in_array( $manual_ref, array_map( 'intval', array_column( Admin::listing( 'transactions', [ 'voided' => '1' ] )['items'], 'id' ) ), true ), 'visible with the voided filter' );
	check( (bool) Db::wp()->get_var( 'SELECT id FROM ' . Db::table( 'audit' ) . " WHERE action='manual.voided' AND target_id=" . $manual_ref ), 'trace kept in audit' );
	wp_set_current_user( 0 );
} );
test( 'manual rewards reach the linked member; void warns instead of revoking', function () use ( $manual_user ) {
	v106_config( [ v106_tier( 'Manual', 'single', 10000, 15, 2 ) ] );
	$GLOBALS['v106_grant'] = 'ok';
	wp_set_current_user( v106_user( 'administrator' ) );
	$m = Manual::create( [ 'alias' => 'Hadiah Manual', 'amount' => 30000, 'paid_at' => v106_local( 60 ), 'reason' => 'Hadiah untuk setoran tunai', 'member_id' => $manual_user, 'reward' => true ] );
	$rows = v106_rows( 'attempt_id=' . (int) $m['attempt']['id'] );
	check( count( $rows ) === 1 && 'granted' === $rows[0]['status'], 'manual reward granted to the linked member' );
	$other = Manual::create( [ 'alias' => 'Tanpa Hadiah', 'amount' => 30000, 'paid_at' => v106_local( 60 ), 'reason' => 'Setoran tanpa hadiah', 'member_id' => $manual_user ] );
	check( count( v106_rows( 'attempt_id=' . (int) $other['attempt']['id'] ) ) === 0, 'reward flag off: nothing granted' );
	Manual::void( (int) $m['attempt']['id'], [ 'revision' => $m['attempt']['revision'], 'reason' => 'setoran dibatalkan penuh' ] );
	check( 'granted' === v106_rows( 'attempt_id=' . (int) $m['attempt']['id'] )[0]['status'] && (bool) Db::wp()->get_var( 'SELECT id FROM ' . Db::table( 'audit' ) . " WHERE action='reward.void_after_grant'" ), 'granted reward kept, warning audited' );
	wp_set_current_user( 0 );
} );
test( 'new routes: permission map, every role, member search, masked and audited email reveal, privacy', function () use ( $manual_user ) {
	Rest::routes();
	$expected = [ 'GET /admin/rewards' => 'mgdd_settings', 'POST /admin/rewards' => 'mgdd_settings', 'GET /admin/rewards/log' => 'mgdd_view', 'POST /admin/rewards/(?P<id>\d+)/retry' => 'mgdd_manage', 'GET /admin/member-search' => 'mgdd_manual', 'POST /admin/manual' => 'mgdd_manual', 'POST /admin/manual/(?P<id>\d+)' => 'mgdd_manual', 'POST /admin/manual/(?P<id>\d+)/void' => 'mgdd_manual', 'POST /admin/donors/(?P<id>\d+)/email' => 'mgdd_sensitive', 'POST /admin/donors/(?P<id>\d+)/anonymize' => 'mgdd_manage', 'GET /recent' => 'public' ];
	foreach ( $expected as $key => $permission ) {
		check( ( Rest::$permissions[ $key ] ?? null ) === $permission, "permission $key" );
	}
	$denied = [ 'GET /admin/rewards', 'POST /admin/manual', 'GET /admin/member-search' ];
	foreach ( [ 'mgd_member', 'mgd_mentor', 'mgd_moderator' ] as $role ) {
		wp_set_current_user( v106_user( $role ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		foreach ( rest_get_server()->get_routes() as $path => $routes ) {
			if ( ! preg_match( '#/admin/(rewards|manual|member-search)#', $path ) && ! preg_match( '#/admin/donors/.*/(email|anonymize)#', $path ) ) {
				continue;
			}
			foreach ( $routes as $route ) {
				$r = new WP_REST_Request( 'POST', $path );
				$r->set_header( 'X-WP-Nonce', $nonce );
				check( is_wp_error( $route['permission_callback']( $r ) ), "$role denied $path" );
			}
		}
	}
	$viewer = v106_user( 'subscriber' );
	get_userdata( $viewer )->add_cap( 'mgdd_view' );
	wp_set_current_user( $viewer );
	$nonce = wp_create_nonce( 'wp_rest' );
	foreach ( rest_get_server()->get_routes() as $path => $routes ) {
		if ( ! str_contains( $path, '/admin/manual' ) && ! str_contains( $path, '/admin/rewards' ) ) {
			continue;
		}
		foreach ( $routes as $route ) {
			$r = new WP_REST_Request( 'POST', $path );
			$r->set_header( 'X-WP-Nonce', $nonce );
			$ok = ! is_wp_error( $route['permission_callback']( $r ) );
			check( $ok === str_contains( $path, 'rewards/log' ), 'view-only cap opens only the reward log: ' . $path );
		}
	}
	wp_set_current_user( v106_user( 'administrator' ) );
	$found = Manual::search_members( 'Pendukung' )['items'];
	check( count( $found ) >= 1 && count( $found ) <= 10 && array_keys( $found[0] ) === [ 'id', 'name', 'login' ], 'member search returns id, name, login only' );
	check( array() === Manual::search_members( 'p' )['items'], 'short query returns nothing' );
	$donor = Db::wp()->get_row( 'SELECT * FROM ' . Db::table( 'donors' ) . " WHERE email<>'' AND is_manual=1 LIMIT 1", ARRAY_A );
	check( (bool) $donor, 'manual donor profile exists' );
	$list = Admin::listing( 'donors', [ 'manual' => '1' ] );
	check( ! array_key_exists( 'email', $list['items'][0] ) && str_contains( $list['items'][0]['email_mask'], '***' ), 'email masked in the list' );
	rejects( fn() => Admin::donor_email( (int) $donor['id'], 'pendek' ), 'mgdd_reveal' );
	check( $donor['email'] === Admin::donor_email( (int) $donor['id'], 'verifikasi bukti transfer' ) && (bool) Db::wp()->get_var( 'SELECT id FROM ' . Db::table( 'audit' ) . " WHERE action='donor.email_revealed' AND target_id=" . (int) $donor['id'] ), 'reveal needs a reason and is audited' );
	$email  = strtolower( get_userdata( $manual_user )->user_email );
	check( count( Privacy::export( $email ) ) >= 2 && count( Privacy::export_rewards( $email ) ) >= 1, 'privacy export covers manual entries and rewards' );
	$mine = apply_filters( 'magaudev_export_user_data', [], $manual_user );
	check( count( $mine['donations'] ) >= 2 && count( $mine['donation_rewards'] ) >= 1, 'member data export covers manual entries and rewards' );
	Admin::anonymize_donor( (int) $donor['id'], [ 'reason' => 'permintaan hapus identitas' ] );
	$left = Db::wp()->get_row( 'SELECT email,alias,user_id FROM ' . Db::table( 'donors' ) . ' WHERE id=' . (int) $donor['id'], ARRAY_A );
	check( '' === (string) $left['email'] && 'Anonim' === $left['alias'], 'profile anonymised, ledger kept: ' . wp_json_encode( $left ) );
	wp_set_current_user( 0 );
} );
test( 'adapter tema nyata memberi XP dan koin tepat sekali lewat ledger tema, tanpa mengganggu pembayaran', function () {
	if ( ! class_exists( 'Magaudev\Donations\Rewards' ) || ! class_exists( 'Magaudev\Points\Ledger' ) ) {
		echo "  (tema 1.0.6 tidak tersedia; dilewati)\n";
		return;
	}
	Magaudev\Donations\Rewards::register();
	$GLOBALS['v106_grant'] = 'absent';
	v106_config( [ v106_tier( 'Nyata', 'single', 10000, 40, 4 ) ] );
	$user  = v106_user();
	$donor = v106_donor_for( $user );
	$a     = v106_attempt( $donor, 20000 );
	$row   = v106_pay( $a );
	v106_pay( $a );
	check( 'paid' === $row['status'] && 20000 === (int) $row['gross'], 'pembayaran utuh' );
	$reward = v106_rows( 'attempt_id=' . (int) $a['id'] )[0];
	check( 'granted' === $reward['status'], 'diserahkan ke adapter tema' );
	$stats = Magaudev\Points\Ledger::stats( $user );
	check( 40 === $stats['xp_total'] && 4 === $stats['coin_balance'], 'XP dan koin masuk sekali' );
	check( Magaudev\Points\Ledger::recorded( $user, 'donation_reward', 'donation', 'mgdd:' . $reward['id'] ), 'kunci ledger mgdd:<id>' );
	foreach ( [ 'points_ledger', 'user_stats', 'notifications' ] as $table ) {
		Db::wp()->delete( Magaudev\Core\Db::t( $table ), [ 'user_id' => $user ] );
	}
	$GLOBALS['v106_grant'] = 'ok';
} );
