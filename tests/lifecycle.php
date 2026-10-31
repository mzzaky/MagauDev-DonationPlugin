<?php
$mgdd_mode=$args[0]??'backend';
if($mgdd_mode==='backend'){
 if(!class_exists('MagaudevDonations\Plugin')||class_exists('Magaudev\Core\Router'))throw new RuntimeException('Backend must boot without theme');
 MagaudevDonations\Rest::routes();
 if(!isset(MagaudevDonations\Rest::$permissions['POST /webhook']))throw new RuntimeException('Webhook missing');
 echo "Plugin backend and webhook boot without theme: OK\n";
}else{
 if(Magaudev\Donations\Module::available())throw new RuntimeException('Companion should be absent');
 $mgdd_data=Magaudev\Donations\Module::controller('donation');
 if($mgdd_data['data']['available']||in_array('donasi',array_column(Magaudev\Admin\Guard::menu(),'id'),true))throw new RuntimeException('Unavailable contract broken');
 echo "Theme without plugin: unavailable state and hidden menu OK\n";
}
