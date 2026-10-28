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
        return $send($method,$path,$data===null?null:json_encode($data,JSON_THROW_ON_ERROR),['Authorization: Bearer '.$token['access_token'],'Content-Type: application/json','Accept: application/json']);
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
    public function process(?string $onlyId=null): array
    {
        $lock=fopen($this->directory.'/catalogue/publications.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['busy'=>true];}
        $results=[];
        try {
            $q=$this->store->db->prepare("SELECT * FROM publication_jobs WHERE state IN ('queued','retry','awaiting_report')".($onlyId!==null?' AND product_id=?':'').' ORDER BY updated_at LIMIT 20');$q->execute($onlyId!==null?[$onlyId]:[]);$jobs=$q->fetchAll(\PDO::FETCH_ASSOC);
            foreach($jobs as $job){
                $products=$this->store->all(true);$found=array_values(array_filter($products,fn($p)=>$p['id']===$job['product_id']));if(!$found)continue;$p=$found[0];$channel=$job['channel'];$changes=[];
                try {
                    if($channel==='flowwow')$changes=$this->flowwow()->publish($p,$this->config['site_url']??'https://botanikart.ru');
                    else {
                        $c=$p['channels']['avito'];
                        if(!$c['enabled']||!empty($p['deleted'])){
                            $active=array_filter($products,fn($q)=>!empty($q['channels']['avito']['enabled'])&&($q['channels']['avito']['management']??'')==='site'&&empty($q['deleted']));
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
                            $changes=['state'=>'awaiting_report','error'=>''];
                        }
                    }
                    $state=$channel==='avito'&&($changes['state']??'')==='awaiting_report'?'awaiting_report':'confirmed';
                    if(!$this->recordResult($job,$changes+['error'=>''],$state))continue;
                    $results[]=['id'=>$p['id'],'channel'=>$channel,'state'=>$changes['state']];
                }catch(\Throwable $error){
                    $this->recordResult($job,['state'=>'error','error'=>mb_substr($error->getMessage(),0,500)],'retry');
                    $results[]=['id'=>$p['id'],'channel'=>$channel,'state'=>'error'];
                }
            }
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
                $state=$ok?'published':($errors?'error':'awaiting_report');
                $this->recordResult($pending,['state'=>$state,'externalId'=>(string)($item['avito_id']??$c['externalId']),'url'=>$item['url']??'','error'=>$errors?mb_substr((string)$errors[0]['description'],0,500):''],$ok?'confirmed':($errors?'retry':'awaiting_report'));
                $seen++;
            }
        }
        return ['checked'=>$seen];
    }
}
