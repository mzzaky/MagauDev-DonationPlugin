<?php
// Invoked only by WP-CLI during browser tests. Auth cookie never printed or committed.
$mgdd_fixture_path=__DIR__.'/../test-results/panel-auth.json';
if(($args[0]??'setup')==='teardown'){
 if(is_file($mgdd_fixture_path)){$mgdd_fixture=json_decode(file_get_contents($mgdd_fixture_path),true);$mgdd_user=get_userdata((int)($mgdd_fixture['id']??0));if($mgdd_user&&$mgdd_user->user_login===($mgdd_fixture['login']??'')&&str_starts_with($mgdd_user->user_login,'mgdd_browser_')){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($mgdd_user->ID);}unlink($mgdd_fixture_path);}
 echo "Panel fixture removed.\n";return;
}
if(is_file($mgdd_fixture_path))throw new RuntimeException('Existing fixture must be cleaned before setup');
$mgdd_login='mgdd_browser_'.bin2hex(random_bytes(6));
$mgdd_id=wp_insert_user(['user_login'=>$mgdd_login,'user_pass'=>wp_generate_password(40),'user_email'=>$mgdd_login.'@example.invalid','display_name'=>'Admin Uji Donasi','role'=>'administrator']);
if(is_wp_error($mgdd_id))throw new RuntimeException('Cannot create fixture');
update_user_meta($mgdd_id,'mgd_email_verified','1');update_user_meta($mgdd_id,'mgd_onboarded','1');
if(!is_dir(dirname($mgdd_fixture_path)))mkdir(dirname($mgdd_fixture_path),0700,true);
file_put_contents($mgdd_fixture_path,json_encode(['id'=>$mgdd_id,'login'=>$mgdd_login,'cookie'=>['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($mgdd_id,time()+3600,'logged_in'),'url'=>home_url('/'),'httpOnly'=>true,'sameSite'=>'Lax']]));
echo "Panel fixture ready (cookie stored privately).\n";
