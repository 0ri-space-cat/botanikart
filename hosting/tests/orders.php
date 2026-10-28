<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/PaymentService.php';
use BotanikArt\{OrderStore,MerchantOrders};
$root=sys_get_temp_dir().'/ba-orders-'.bin2hex(random_bytes(6));
$config=['data_dir'=>$root,'merchant_email'=>'owner@example.com'];
$store=new OrderStore($root.'/live');$calls=[];
$mailer=function(...$args)use(&$calls){$calls[]=$args;return true;};
$manager=new MerchantOrders($config,$mailer);$checks=0;
function verify($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
 $order=['orderId'=>'ba-'.str_repeat('a',24),'productId'=>'test','title'=>'Флорариум','email'=>'buyer@example.com','phone'=>'+79990000000','status'=>'CONFIRMED','paid'=>true,'sold'=>true,'testMode'=>false,'createdAt'=>time(),'paidAt'=>time(),'merchantMailPendingAt'=>time(),'amount'=>50000,'paymentUrl'=>'secret','reference'=>'secret'];
 $store->transaction(function(&$s)use($order){$s['orders'][$order['orderId']]=$order;$s['orders']['demo']=array_replace($order,['orderId'=>'demo','testMode'=>true]);$s['orders']['unpaid']=array_replace($order,['orderId'=>'unpaid','paid'=>false,'sold'=>false,'status'=>'NEW']);$s['reservations']['test']=$order['orderId'];});
 verify(count($manager->all())===1,'Only real paid orders listed');
 verify(!isset($manager->all()[0]['reference'],$manager->all()[0]['paymentUrl']),'Secrets excluded');
 verify($manager->sendPending()===1&&count($calls)===1,'Only real paid order emailed');
 verify($calls[0][0]==='owner@example.com'&&str_contains($calls[0][2],'500,00'),'Owner and amount correct');
 verify($manager->sendPending()===0&&count($calls)===1,'Repeated callbacks do not resend accepted mail');
 $manager->complete($order['orderId'],true);verify($manager->all()[0]['fulfillment']==='completed','Complete order');
 $state=$store->transaction(fn(&$s)=>$s);verify($state['orders'][$order['orderId']]['paid']&&isset($state['reservations']['test']),'Completion never changes payment or stock');
 $manager->complete($order['orderId'],false);verify($manager->all()[0]['fulfillment']==='open','Reopen order');
 try{$manager->complete('unpaid',true);throw new RuntimeException('Unpaid accepted');}catch(OutOfBoundsException){$checks++;}
 $store->transaction(function(&$s)use($order){$id=$order['orderId'];unset($s['orders'][$id]['merchantMailSentAt']);});
 $failed=new MerchantOrders($config,fn()=>false);verify($failed->sendPending()===0,'Mail failure retained for retry');
 verify($manager->all()[0]['emailState']==='pending','Pending notification visible');
 verify($manager->sendPending()===0,'Retry backoff respected');
 $store->transaction(function(&$s)use($order){$s['orders'][$order['orderId']]['merchantMailRetryAt']=0;});
 verify($manager->sendPending()===1,'Failure retried');
 echo "Order tests passed: $checks assertions.\n";
}finally{foreach(glob($root.'/live/*') as $f)unlink($f);rmdir($root.'/live');rmdir($root);}
