<?php
declare(strict_types=1);
// Kept only as a compatibility target for an existing hosting-panel schedule.
// Marketplace publication is retired; this task now retries owner order email only.
if(PHP_SAPI!=='cli'){http_response_code(410);exit;}
if(!is_file(__DIR__.'/config.php')){echo "Notifications are not configured.\n";exit;}
require_once __DIR__.'/PaymentService.php';
try{$sent=(new \BotanikArt\MerchantOrders(require __DIR__.'/config.php'))->sendPending();echo 'Merchant notifications accepted: '.$sent."\n";}
catch(\Throwable){fwrite(STDERR,"Merchant notifications will be retried.\n");exit(1);}
