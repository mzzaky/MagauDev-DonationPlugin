<?php
require __DIR__.'/bootstrap.php';
use MagaudevDonations\{Db,Donations,Limiter,Payments,Rewards,Session};
$_COOKIE[Session::COOKIE]=str_repeat('d',64);
$_SERVER['REMOTE_ADDR']='127.0.0.4';
add_filter('pre_http_request',function($pre,$args,$url){
 if(!str_contains($url,'midtrans.com'))return new WP_Error('fixture','network disabled');
 Limiter::take('concurrent_gateway_count','one',1000,3600);
 usleep(150000);
 return ['headers'=>[],'body'=>json_encode(['token'=>'fixture-concurrent','redirect_url'=>'https://app.sandbox.midtrans.com/snap/v2/vtweb/fixture']),'response'=>['code'=>201,'message'=>'fixture'],'cookies'=>[]];
},10,3);
try {
 $action=$argv[1]??'';
 if($action==='checkout'){
  $r=Donations::checkout(['amount'=>10000,'name'=>'Concurrent fixture','email'=>'concurrent@example.invalid','message'=>'','public_name'=>false,'public_message'=>false,'idempotency_key'=>'fixture_concurrent_key_123']);
  echo json_encode(['reference'=>$r['reference']]);
 }elseif($action==='limiter'){
  echo json_encode(['allowed'=>Limiter::take('parallel_limit','fixture',2,3600)]);
 }elseif($action==='payment'){
  $id=(int)($argv[2]??0);$a=Db::wp()->get_row(Db::wp()->prepare('SELECT * FROM '.Db::table('attempts').' WHERE id=%d',$id),ARRAY_A);
  Payments::apply($id,['order_id'=>$a['order_id'],'gross_amount'=>$a['amount'].'.00','transaction_id'=>'concurrent-transaction-'.$id,'transaction_status'=>'settlement','payment_type'=>'qris','merchant_id'=>$a['merchant'],'currency'=>'IDR','settlement_time'=>'2026-10-31 12:00:00','transaction_time'=>'2026-10-31 11:00:00'],'fixture-concurrent');
  echo json_encode(['applied'=>true]);
 }elseif($action==='reward'){
  $called=0;
  add_filter('mgdd_reward_grant',function($result,$payload)use(&$called){$called++;return true;},10,2);
  Rewards::evaluate((int)($argv[2]??0));
  echo json_encode(['called'=>$called]);
 }else throw new RuntimeException('Unknown worker');
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);}
