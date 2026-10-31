<?php
declare(strict_types=1);
namespace BotanikArt;
require_once __DIR__.'/FlowwowPublisher.php';
require_once __DIR__.'/AvitoFeed.php';

final class PublicationRunner
{
    private array $config;
    public function __construct(private CatalogStore $store,private string $directory,private $transport=null,private $avitoTransport=null)
    { $file=$directory.'/marketplaces-config.php';$this->config=is_file($file)?require $file:[]; }
    public function flowwow(): FlowwowPublisher {return new FlowwowPublisher($this->config['flowwow']??[],$this->directory,$this->transport);}
    private function avito(string $method,string $path,?array $data=null): array
    {
        if($this->avitoTransport)return ($this->avitoTransport)($method,$path,$data);
        $cfg=$this->config['avito']??[];
        if(empty($cfg['client_id'])||empty($cfg['client_secret']))throw new \RuntimeException('Авито: доступ не настроен.');
        $send=function(string $method,string $path,?string $body,array $headers):array {
            $h=curl_init('https://api.avito.ru'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers]);
            if($body!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$body);$raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
            if($status<200||$status>=300||$raw===false)throw new \RuntimeException('Авито: запрос не выполнен (HTTP '.$status.').');
            return $raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        };
        $token=$send('POST','/token',http_build_query(['grant_type'=>'client_credentials','client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret']]),['Content-Type: application/x-www-form-urlencoded']);
        if(empty($token['access_token']))throw new \RuntimeException('Авито: авторизация не выполнена.');
        return $send($method,$path,$data===null?null:json_encode($data?:new \stdClass(),JSON_THROW_ON_ERROR),['Authorization: Bearer '.$token['access_token'],'Content-Type: application/json','Accept: application/json']);
    }
    private function setState(string $id,string $channel,array $changes): void
    {
        $q=$this->store->db->prepare('SELECT document FROM product_channels WHERE product_id=? AND channel=?');$q->execute([$id,$channel]);$raw=$q->fetchColumn();if(!$raw)return;
        $state=array_replace(json_decode($raw,true,512,JSON_THROW_ON_ERROR),$changes,['checkedAt'=>gmdate('c')]);
        $q=$this->store->db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');$q->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$channel]);
    }
    private function recordResult(array $job,array $changes,string $state): bool
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->store->db->prepare('SELECT revision FROM publication_jobs WHERE product_id=? AND channel=?');$q->execute([$job['product_id'],$job['channel']]);
            if($q->fetchColumn()!==$job['revision']){$this->store->db->exec('ROLLBACK');return false;}
            $this->setState($job['product_id'],$job['channel'],$changes);
            $q=$this->store->db->prepare('UPDATE publication_jobs SET state=? WHERE product_id=? AND channel=? AND revision=?');$q->execute([$state,$job['product_id'],$job['channel'],$job['revision']]);
            $this->store->db->exec('COMMIT');return true;
        }catch(\Throwable $e){try{$this->store->db->exec('ROLLBACK');}catch(\Throwable){}throw $e;}
    }
    /** Withdrawal must also work before Avito has returned an external listing ID. */
    public function stopEmptyAvitoFeed(): array
    {
        $lock=fopen($this->directory.'/catalogue/publications.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['pending'=>true];}
        try{
            foreach($this->store->all(true) as $p)if(empty($p['assortmentId'])&&empty($p['deleted'])&&!empty($p['channels']['avito']['enabled'])&&($p['channels']['avito']['management']??'')==='site')return ['needed'=>false];
            if(!$this->avitoTransport&&(empty($this->config['avito']['client_id'])||empty($this->config['avito']['client_secret'])))return ['needed'=>false];
            $profile=$this->avito('GET','/autoload/v2/profile');
            $expected=rtrim($this->config['site_url']??'https://botanikart.ru','/').'/api/avito-feed.php';
            if(array_column($profile['feeds_data']??[],'feed_url')!==[$expected])return ['pending'=>true];
            if(!empty($profile['autoload_enabled'])){
                $settings=array_intersect_key($profile,array_flip(['feeds_data','schedule','report_email']));
                $settings['autoload_enabled']=false;$settings['allow_pay_over_limit']=false;
                $this->avito('POST','/autoload/v2/profile',$settings);
                $profile=$this->avito('GET','/autoload/v2/profile');
            }
            return empty($profile['autoload_enabled'])?['paused'=>true]:['pending'=>true];
        }catch(\Throwable){return ['pending'=>true];}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    /** Read authoritative removals before exporting; never infer removal from absence or a failed request. */
    public function reconcileAvitoRemovals(): array
    {
        $eligible=[];foreach($this->store->all(true) as $p){$c=$p['channels']['avito'];if(empty($p['deleted'])&&empty($p['assortmentId'])&&!empty($c['enabled'])&&ctype_digit((string)$c['externalId'])&&in_array($c['state']??'',['published','external'],true))$eligible[$p['id']]=['id'=>(string)$c['externalId'],'version'=>$p['version']];}
        if(!$eligible)return ['checked'=>0,'disabled'=>0];
        $lock=fopen($this->directory.'/catalogue/publications.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new \RuntimeException('Авито: проверка статусов уже выполняется.');}
        try{
            $removed=[];$page=1;
            do{$result=$this->avito('GET','/core/v1/items?status=removed,old&per_page=100&page='.$page);if(!isset($result['resources'])||!is_array($result['resources']))throw new \RuntimeException('Авито: не удалось проверить снятые объявления.');foreach($result['resources'] as $item)if(in_array($item['status']??'',['removed','old'],true)&&isset($item['id']))$removed[(string)$item['id']]=true;$more=count($result['resources'])===100;if($more&&$page>=100)throw new \RuntimeException('Авито: список снятых объявлений слишком большой.');$page++;}while($more);
            $disabled=0;$handled=[];$db=$this->store->db;$db->exec('BEGIN IMMEDIATE');
            try{foreach($this->store->all(true) as $p){$expected=$eligible[$p['id']]??null;$c=$p['channels']['avito'];if(!$expected||isset($handled[$expected['id']])||!isset($removed[$expected['id']])||$p['version']!==$expected['version']||!$c['enabled']||(string)$c['externalId']!==$expected['id'])continue;
                // Include assortment members, without touching website visibility or Flowwow.
                foreach($this->store->all(true) as $member){$mc=$member['channels']['avito'];if((string)$mc['externalId']!==$expected['id'])continue;$this->setState($member['id'],'avito',['enabled'=>false,'state'=>'removed_external','error'=>'','message'=>'Снято на Авито. Отправка отключена.']);$q=$db->prepare("UPDATE publication_jobs SET state='confirmed' WHERE product_id=? AND channel='avito'");$q->execute([$member['id']]);$db->prepare('UPDATE products SET version=version+1,updated_at=? WHERE id=?')->execute([gmdate('c'),$member['id']]);}$handled[$expected['id']]=true;$disabled++;
            }$db->exec('COMMIT');}catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
            return ['checked'=>count($eligible),'disabled'=>$disabled];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function uploadMeta(string $name,?int $value=null): int
    {
        if($value!==null){$q=$this->store->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');$q->execute([$name,json_encode($value)]);return $value;}
        $q=$this->store->db->prepare('SELECT value FROM metadata WHERE name=?');$q->execute([$name]);return (int)json_decode($q->fetchColumn()?:'0');
    }
    private function flushAvitoUpload(): void
    {
        $selected=array_filter($this->store->all(true),fn($p)=>empty($p['deleted'])&&empty($p['assortmentId'])&&!empty($p['channels']['avito']['enabled'])&&($p['channels']['avito']['management']??'')==='site');if(!$selected){$this->uploadMeta('avito:uploadPending',0);return;}
        if(!$this->uploadMeta('avito:uploadPending')||$this->uploadMeta('avito:uploadAttempt')>time()-3600)return;
        // Persist before sending: an ambiguous timeout must never cause a rapid replay.
        $this->uploadMeta('avito:uploadAttempt',time());
        try{$this->avito('POST','/autoload/v1/upload',[]);$this->uploadMeta('avito:uploadPending',0);}catch(\Throwable){/* Retain one coalesced request; daily schedule remains a fallback. */}
    }
    public function configureAvitoDailySchedule(): array
    {
        $profile=$this->avito('GET','/autoload/v2/profile');$expected=rtrim($this->config['site_url']??'https://botanikart.ru','/').'/api/avito-feed.php';
        if(array_column($profile['feeds_data']??[],'feed_url')!==[$expected])throw new \RuntimeException('Авито: подключена другая ссылка автозагрузки.');
        $settings=array_intersect_key($profile,array_flip(['feeds_data','report_email','autoload_enabled']));$settings['schedule']=[['rate'=>-1,'weekdays'=>[0,1,2,3,4,5,6],'time_slots'=>[23]]];$settings['allow_pay_over_limit']=false;
        $this->avito('POST','/autoload/v2/profile',$settings);return $this->avito('GET','/autoload/v2/profile');
    }
    public function process(?string $onlyId=null,?string $onlyChannel=null): array
    {
        $lock=fopen($this->directory.'/catalogue/publications.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['busy'=>true];}
        $results=[];
        try {
            $query="SELECT * FROM publication_jobs WHERE state IN ('queued','retry','awaiting_report','awaiting_confirmation','moderation')";$args=[];
            if($onlyId!==null){$query.=' AND product_id=?';$args[]=$onlyId;}
            if($onlyChannel!==null){$query.=' AND channel=?';$args[]=$onlyChannel;}
            $q=$this->store->db->prepare($query.' ORDER BY updated_at LIMIT 20');$q->execute($args);$jobs=$q->fetchAll(\PDO::FETCH_ASSOC);
            foreach($jobs as $job){
                $products=$this->store->all(true);$found=array_values(array_filter($products,fn($p)=>$p['id']===$job['product_id']));if(!$found)continue;$p=$found[0];$channel=$job['channel'];$changes=[];
                try {
                    if($channel==='flowwow'){
                        $oldConfirmationError=$job['state']==='retry'&&str_contains((string)($p['channels']['flowwow']['error']??''),'не подтвердил изменения');
                        $changes=(in_array($job['state'],['awaiting_confirmation','moderation'],true)||$oldConfirmationError)
                            ?$this->flowwow()->confirmPublished($p)
                            :$this->flowwow()->publish($p,$this->config['site_url']??'https://botanikart.ru');
                    }
                    else {
                        $c=$p['channels']['avito'];if(!empty($p['assortmentId']))continue;
                        if($job['state']==='awaiting_report'&&in_array(($c['state']??''),['awaiting_report','moderation'],true)){
                            $results[]=['id'=>$p['id'],'channel'=>'avito','state'=>$c['state']];continue;
                        }
                        if(!$c['enabled']||!empty($p['deleted'])){
                            $active=array_filter($products,fn($q)=>empty($q['assortmentId'])&&!empty($q['channels']['avito']['enabled'])&&($q['channels']['avito']['management']??'')==='site'&&empty($q['deleted']));
                            if(!$active){$profile=$this->avito('GET','/autoload/v2/profile');$expected=rtrim($this->config['site_url']??'https://botanikart.ru','/').'/api/avito-feed.php';if(array_column($profile['feeds_data']??[],'feed_url')===[$expected]&&!empty($profile['autoload_enabled'])){$settings=array_intersect_key($profile,array_flip(['feeds_data','schedule','report_email']));$settings['autoload_enabled']=false;$settings['allow_pay_over_limit']=false;$this->avito('POST','/autoload/v2/profile',$settings);}}
                            $changes=['state'=>'manual_removal','error'=>'Отправка выключена. Снимите объявление на Авито вручную.'];
                        }
                        else {
                            $schema=json_decode(file_get_contents(dirname($this->directory,2).'/catalogue/avito-schema.json'),true,512,JSON_THROW_ON_ERROR);
                            $feed=new AvitoFeed($schema);$feed->render($products,$this->config['site_url']??'https://botanikart.ru');
                            $profile=$this->avito('GET','/autoload/v2/profile');$urls=array_column($profile['feeds_data']??[],'feed_url');
                            $expected=rtrim($this->config['site_url']??'https://botanikart.ru','/').'/api/avito-feed.php';
                            if($urls!==[$expected])throw new \RuntimeException('Авито: в кабинете должна быть подключена ссылка нашего каталога.');
                            if(empty($profile['autoload_enabled'])){
                                $settings=array_intersect_key($profile,array_flip(['feeds_data','schedule','report_email']));$settings['autoload_enabled']=true;$settings['allow_pay_over_limit']=false;
                                $this->avito('POST','/autoload/v2/profile',$settings);
                            }
                            $this->uploadMeta('avito:uploadPending',time());
                            $changes=['state'=>'awaiting_report','error'=>''];
                        }
                    }
                    $state=$channel==='avito'&&($changes['state']??'')==='awaiting_report'?'awaiting_report':(in_array($changes['state']??'', ['awaiting_confirmation','moderation'],true)?($changes['state']):'confirmed');
                    if(!$this->recordResult($job,$changes+['error'=>''],$state))continue;
                    $results[]=['id'=>$p['id'],'channel'=>$channel,'state'=>$changes['state']];
                }catch(\Throwable $error){
                    $this->recordResult($job,['state'=>'error','error'=>mb_substr($error->getMessage(),0,500)],'retry');
                    $results[]=['id'=>$p['id'],'channel'=>$channel,'state'=>'error','message'=>mb_substr($error->getMessage(),0,500)];
                }
            }
            $this->flushAvitoUpload();
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return ['results'=>$results];
    }
    public function reports(): array
    {
        $lock=fopen($this->directory.'/catalogue/reports.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['busy'=>true];}
        try{
            $q=$this->store->db->prepare('SELECT value FROM metadata WHERE name=?');$q->execute(['avito:reportCheckAt']);$last=(int)json_decode($q->fetchColumn()?:'0');
            if($last>time()-60)return ['cached'=>true];
            $q=$this->store->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');$q->execute(['avito:reportCheckAt',json_encode(time())]);
            $uploadLock=fopen($this->directory.'/catalogue/publications.lock','c');if($uploadLock&&flock($uploadLock,LOCK_EX|LOCK_NB)){try{$this->flushAvitoUpload();}finally{flock($uploadLock,LOCK_UN);}}if($uploadLock)fclose($uploadLock);
            return $this->readReports();
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function readReports(): array
    {
        $header=$this->avito('GET','/autoload/v4/uploads/current');$started=strtotime($header['started_at']??'')?:0;
        $items=[];$page=1;
        do{$result=$this->avito('GET','/autoload/v4/uploads/current/items?perPage=100&page='.$page);$items=array_merge($items,$result['items']??[]);$pages=(int)($result['meta']['pages']??1);if($pages>100)throw new \RuntimeException('Авито: отчёт слишком большой, обработка остановлена.');$page++;}while($page<=$pages);
        $seen=0;
        foreach($items as $item){
            foreach($this->store->all(true) as $p){
                $c=$p['channels']['avito'];if(!$c['enabled']||$c['management']!=='site')continue;
                $legacyFile=__DIR__.'/avito-legacy.json';$legacy=is_file($legacyFile)?json_decode(file_get_contents($legacyFile),true):[];
                $feedId=$legacy[(string)($c['externalId']??'')]['id']??('ba-'.$p['id']);
                if(($item['ad_id']??'')!==$feedId)continue;
                $job=$this->store->db->prepare("SELECT * FROM publication_jobs WHERE product_id=? AND channel='avito'");$job->execute([$p['id']]);$pending=$job->fetch(\PDO::FETCH_ASSOC);if(!$pending||$started<strtotime($pending['updated_at']))continue;
                $ok=($item['avito_status']??'')==='active'&&($item['section']['slug']??'')==='success_published';
                $errors=array_values(array_filter($item['messages']??[],fn($m)=>($m['type']??'')==='error'));
                $accepted=!$errors&&in_array((string)($item['avito_status']??''),['moderation','moderated','pending','processing','waiting','active'],true)&&($item['section']['slug']??'')!=='success_published';
                $state=$ok?'published':($errors?'error':($accepted?'moderation':'awaiting_report'));
                $this->recordResult($pending,['state'=>$state,'externalId'=>(string)($item['avito_id']??$c['externalId']),'url'=>$item['url']??'','error'=>$errors?mb_substr((string)$errors[0]['description'],0,500):''],$ok?'confirmed':($errors?'retry':'awaiting_report'));
                $seen++;
            }
        }
        return ['checked'=>$seen];
    }
}
