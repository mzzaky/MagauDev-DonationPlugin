<?php
// Only isolated tables/options; no production credentials or external gateway traffic.
define('MGDD_TABLE_NS', 'mgddt_');
define('MGDD_OPTION_NS', 'mgddt_');
define('MAGAUDEV_TABLE_NS', 'mgdt_');
define('MAGAUDEV_TESTING', true);
define('MGDD_SANDBOX_SERVER_KEY', 'fixture-server-not-a-credential');
define('MGDD_SANDBOX_CLIENT_KEY', 'fixture-client-not-a-credential');
define('MGDD_SANDBOX_MERCHANT_ID', 'fixture-merchant');
define('MGDD_PRODUCTION_SERVER_KEY', 'fixture-production-not-a-credential');
define('MGDD_PRODUCTION_CLIENT_KEY', 'fixture-production-client');
define('MGDD_PRODUCTION_MERCHANT_ID', 'fixture-production');
define('MGDD_IP_KEY_ID', 'fixture-v1');
define('MGDD_IP_KEYS', ['fixture-v1'=>base64_encode(str_repeat('f',32))]);
$_SERVER['HTTP_HOST']='magaudev.ai';
$_SERVER['SERVER_NAME']='magaudev.ai';
$_SERVER['REQUEST_URI']='/';
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$root=getenv('MGD_WP_ROOT');
if(!$root||!is_file($root.'/wp-load.php')){fwrite(STDERR,"Set MGD_WP_ROOT to the test WordPress installation.\n");exit(1);}
// Preinitialized WP hook: skip the installed release copy in this PHP process only.
// Integration tests and concurrent workers must exercise the repository source.
$GLOBALS['wp_filter']['option_active_plugins'][1][]=['function'=>static fn($plugins)=>array_values(array_filter($plugins,static fn($plugin)=>$plugin!=='magaudev-donations/magaudev-donations.php')),'accepted_args'=>1];
require $root.'/wp-load.php';
if(!defined('MGDD_VERSION')) require dirname(__DIR__).'/magaudev-donations.php';
if(realpath(MGDD_FILE)!==realpath(dirname(__DIR__).'/magaudev-donations.php')){fwrite(STDERR,"Tests must load repository source, not installed package.\n");exit(1);}
