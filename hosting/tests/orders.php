<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/PaymentService.php';require dirname(__DIR__).'/private/CatalogStore.php';
use BotanikArt\{OrderStore,MerchantOrders,CatalogStore,ApiError};
$root=sys_get_temp_dir().'/ba-orders-'.bin2hex(random_bytes(6));
$config=['data_dir'=>$root,'merchant_email'=>'owner@example.com'];
$store=new OrderStore($root.'/live');$calls=[];
$mailer=function(...$args)use(&$calls){$calls[]=$args;return true;};
$manager=new MerchantOrders($config,$mailer);$checks=0;
function verify($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
 $order=['orderId'=>'ba-'.str_repeat('a',24),'productId'=>'flowwow-1','title'=>'Флорариум','email'=>'buyer@example.com','phone'=>'+79990000000','status'=>'CONFIRMED','paid'=>true,'sold'=>true,'testMode'=>false,'createdAt'=>time(),'paidAt'=>time(),'merchantMailPendingAt'=>time(),'amount'=>50000,'paymentUrl'=>'secret','reference'=>'secret'];
 $store->transaction(function(&$s)use($order){$s['orders'][$order['orderId']]=$order;$s['orders']['demo']=array_replace($order,['orderId'=>'demo','testMode'=>true]);$s['orders']['unpaid']=array_replace($order,['orderId'=>'unpaid','paid'=>false,'sold'=>false,'status'=>'NEW']);$s['reservations']['flowwow-1']=$order['orderId'];});
 verify(count($manager->all())===1,'Only real paid orders listed');
 verify(!isset($manager->all()[0]['reference'],$manager->all()[0]['paymentUrl']),'Secrets excluded');
 verify($manager->sendPending()===1&&count($calls)===1,'Only real paid order emailed');
 verify($calls[0][0]==='owner@example.com'&&str_contains($calls[0][2],'500,00'),'Owner and amount correct');
 verify($manager->sendPending()===0&&count($calls)===1,'Repeated callbacks do not resend accepted mail');
 try{$manager->complete($order['orderId'],true);throw new LogicException('Delivery completed before sale confirmation');}catch(ApiError $e){verify($e->reason==='sale_confirmation_required','Wrong completion guard');}
 mkdir($root.'/public/data',0700,true);$snapshot=$root.'/public/data/catalog.json';
 $unit=['id'=>'flowwow-1','source'=>'flowwow','flowwowProductId'=>123,'title'=>'Флорариум','price'=>500,'description'=>'Описание','catalogGroup'=>'florarium','status'=>'ready','available'=>true,'images'=>['https://content2.flowwow-images.com/fixture.jpg']];
 file_put_contents($snapshot,json_encode(['products'=>[$unit,array_replace($unit,['id'=>'flowwow-2','flowwowProductId'=>124,'catalogGroup'=>'plant','title'=>'Растение'])],'featured'=>[]]));
 $catalog=new CatalogStore($root.'/public/api/.private/catalogue',$snapshot);
 $sale=$manager->confirmSale($order['orderId'],$catalog);verify($sale['kind']==='single','Florarium not treated as a unique item');
 $public=array_column($catalog->publicCatalog()['products'],null,'id');verify(!$public['flowwow-1']['available']&&$public['flowwow-1']['canOrder']&&$public['flowwow-1']['status']==='sold','Sold item is not visible as made-to-order');
 verify($catalog->channels('flowwow-1')['flowwow']['enabled'],'Sale silently removed external listing');
 verify((int)$catalog->db->query('SELECT count(*) FROM publication_jobs')->fetchColumn()===0,'Website sale unexpectedly queued platform writes');
 $admin=array_column($catalog->all(true),null,'id');$edit=$admin['flowwow-1'];$edit['status']='sold';$edit['description']='Уточнённое описание';$catalog->save($edit);
 $admin=array_column($catalog->all(true),null,'id');verify($admin['flowwow-1']['status']==='ready'&&$admin['flowwow-1']['soldOnSite'],'Editing website-sold card corrupted external availability');
 $manager->confirmSale($order['orderId'],$catalog);verify($manager->all()[0]['saleConfirmedAt']>0,'Sale confirmation did not persist');
 $repeat=array_replace($order,['orderId'=>'ba-'.str_repeat('b',24),'productId'=>'flowwow-2','title'=>'Растение']);$store->transaction(function(&$s)use($repeat){$s['orders'][$repeat['orderId']]=$repeat;$s['reservations']['flowwow-2']=$repeat['orderId'];});
 verify($manager->confirmSale($repeat['orderId'],$catalog)['kind']==='repeat','Plant not classified as repeatable');
 $state=$store->transaction(fn(&$s)=>$s);verify(!isset($state['reservations']['flowwow-2']),'Plant cannot be bought again after sale confirmation');
 $store->transaction(function(&$s){$s['reservations']['flowwow-2']='another-pending-order';});$manager->confirmSale($repeat['orderId'],$catalog);verify($store->transaction(fn(&$s)=>$s['reservations']['flowwow-2'])==='another-pending-order','Repeated confirmation released a newer buyer reservation');
 $public=array_column($catalog->publicCatalog()['products'],null,'id');verify($public['flowwow-2']['available'],'Plant was hidden after sale');
 try{$manager->withdrawSale($repeat['orderId'],$catalog,['flowwow']);throw new LogicException('Repeatable product removed from platforms');}catch(OutOfBoundsException){$checks++;}
 $manager->withdrawSale($order['orderId'],$catalog,['flowwow']);verify(!$catalog->channels('flowwow-1')['flowwow']['enabled'],'Explicit external withdrawal not queued');
 $store->transaction(function(&$s)use($repeat){unset($s['orders'][$repeat['orderId']],$s['reservations']['flowwow-2']);});
 $manager->complete($order['orderId'],true);verify($manager->all()[0]['fulfillment']==='completed','Complete order');
 $state=$store->transaction(fn(&$s)=>$s);verify($state['orders'][$order['orderId']]['paid']&&isset($state['reservations']['flowwow-1']),'Completion never changes payment or stock');
 $manager->complete($order['orderId'],false);verify($manager->all()[0]['fulfillment']==='open','Reopen order');
 try{$manager->complete('unpaid',true);throw new RuntimeException('Unpaid accepted');}catch(OutOfBoundsException){$checks++;}
 $store->transaction(function(&$s)use($order){$id=$order['orderId'];unset($s['orders'][$id]['merchantMailSentAt']);});
 $failed=new MerchantOrders($config,fn()=>false);verify($failed->sendPending()===0,'Mail failure retained for retry');
 verify($manager->all()[0]['emailState']==='pending','Pending notification visible');
 verify($manager->sendPending()===0,'Retry backoff respected');
 $store->transaction(function(&$s)use($order){$s['orders'][$order['orderId']]['merchantMailRetryAt']=0;});
 verify($manager->sendPending()===1,'Failure retried');
 echo "Order tests passed: $checks assertions.\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
