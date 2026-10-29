<?php
declare(strict_types=1);
namespace BotanikArt;

/** Publication preparation and connection monitoring. External writes require separate activation. */
final class MarketplaceService
{
    private array $config;
    public function __construct(private CatalogStore $store, private string $directory, ?array $config=null)
    {
        $file=$directory.'/marketplaces-config.php';
        $this->config=$config??(is_file($file)?require $file:[]);
    }
    private function meta(string $key, mixed $default=null): mixed
    {
        $get=$this->store->db->prepare('SELECT value FROM metadata WHERE name=?');$get->execute([$key]);$raw=$get->fetchColumn();
        return $raw===false?$default:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    private function setMeta(string $key,mixed $value): void
    {
        $put=$this->store->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
        $put->execute([$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    public function connections(?\DateTimeImmutable $now=null): array
    {
        $result=[];
        foreach (['avito','flowwow'] as $channel) {
            $cfg=$this->config[$channel]??[];
            $hasCredentials=$channel==='avito'?(!empty($cfg['client_id'])&&!empty($cfg['client_secret'])):(!empty($cfg['token'])&&!empty($cfg['shop_id']));
            $health=$this->meta('health:'.$channel,['state'=>'unknown','checkedAt'=>null]);
            $result[$channel]=['configured'=>$hasCredentials,'outboundEnabled'=>$hasCredentials,'health'=>$health,
                'message'=>$hasCredentials?match($health['state']??'unknown'){'ok'=>'Подключено','access_denied'=>'Нет доступа','unreachable'=>'Нет связи','rate_limited'=>'Временно недоступно',default=>'Настройки сохранены'}:'Нужна настройка'];
        }
        $date=$this->meta('avito:renewalDate','');$reminder=null;
        if ($date!=='') {
            $today=($now??new \DateTimeImmutable('now',new \DateTimeZone('Europe/Moscow')))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0,0);
            $end=new \DateTimeImmutable($date,new \DateTimeZone('Europe/Moscow'));$days=(int)$today->diff($end)->format('%r%a');
            $stage=$days<0?'expired':($days<=3?'3':($days<=7?'7':null));
            if ($stage!==null) {
                $id=$date.':'.$stage;
                $text=$days<0?'Указанная дата продления Авито прошла. Проверьте, оплачена ли подписка.':($days===0?'Сегодня дата продления Авито. Не забудьте проверить оплату.':'Подписка Авито заканчивается '. $end->format('d.m.Y').'. Не забудьте продлить тариф.');
                $reminder=['id'=>$id,'days'=>$days,'text'=>$text,'seen'=>$this->meta('reminder:'.$id,false)===true];
            }
        }
        $result['avito']['renewalDate']=$date;$result['avito']['reminder']=$reminder;
        return $result;
    }
    public function saveRenewalDate(mixed $date): void
    {
        if (!is_string($date)) throw new \InvalidArgumentException('invalid_date');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/Moscow'));
        if ($date!=='' && (!$parsed||$parsed->format('Y-m-d')!==$date)) throw new \InvalidArgumentException('invalid_date');
        $this->setMeta('avito:renewalDate',$date);
    }
    public function acknowledgeReminder(mixed $id): void
    {
        $current=$this->connections()['avito']['reminder'];
        if (!is_string($id)||!$current||$current['id']!==$id) throw new \InvalidArgumentException('invalid_reminder');
        $this->setMeta('reminder:'.$id,true);
    }
    public function reportHealth(string $channel,string $state): void
    {
        if (!in_array($channel,['avito','flowwow'],true)||!in_array($state,['ok','access_denied','unreachable','rate_limited'],true)) throw new \InvalidArgumentException('invalid_health');
        $this->setMeta('health:'.$channel,['state'=>$state,'checkedAt'=>gmdate('c')]);
    }
    public function checkConnections(?callable $transport=null): array
    {
        $connections=$this->connections();
        if (!$connections['avito']['configured']) return $connections;
        $checked=$connections['avito']['health']['checkedAt']??null;
        if ($checked && strtotime($checked)>time()-300) return $connections;
        $lock=fopen($this->directory.'/catalogue/avito-health.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock);return $connections; }
        try {
            $transport??=function(string $method,string $path,?string $body,array $headers): array {
                $curl=curl_init('https://api.avito.ru'.$path);
                curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
                    CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
                if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
                $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
                return ['status'=>$status,'json'=>is_string($raw)?json_decode($raw,true):null];
            };
            $cfg=$this->config['avito'];
            $token=$transport('POST','/token',http_build_query(['grant_type'=>'client_credentials','client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret']]),['Content-Type: application/x-www-form-urlencoded','Accept: application/json']);
            $response=$token;
            if (($token['status']??0)===200 && is_string($token['json']['access_token']??null)) {
                $response=$transport('GET','/autoload/v4/uploads',null,['Accept: application/json','Authorization: Bearer '.$token['json']['access_token']]);
            }
            $code=$response['status']??0;
            $state=$code===200&&is_array($response['json']??null)?'ok':(in_array($code,[401,403],true)?'access_denied':($code===429?'rate_limited':'unreachable'));
            // A token response alone is never proof of autoload access.
            if ($response===$token && $state==='ok') $state='unreachable';
            $this->reportHealth('avito',$state);
        } catch (\Throwable) { $this->reportHealth('avito','unreachable'); }
        finally { flock($lock,LOCK_UN);fclose($lock); }
        return $this->connections();
    }

    public function productStatus(array $p): array
    {
        foreach ($p['channels'] as $channel=>&$c) {
            if ($channel==='avito' && !empty($c['externalId'])) {
                $legacyFile=__DIR__.'/avito-legacy.json';$legacy=is_file($legacyFile)?json_decode(file_get_contents($legacyFile),true):[];
                $base=$legacy[(string)$c['externalId']]??null;
                if ($base) {
                    $c['category']=($c['category']??'')?:$base['category'];$c['attributes']=array_merge($base['attributes'],$c['attributes']??[]);
                    $c['listingTitle']=$base['title'];$c['listingDescription']=$base['description'];$c['listingPrice']=$base['price'];
                    $q=$this->store->db->prepare("SELECT COUNT(*) FROM products WHERE json_extract(document,'$.avitoItemId')=?");$q->execute([(string)$c['externalId']]);$c['sharedCount']=(int)$q->fetchColumn();
                }
            }
            if (!empty($c['error'])) { $c['message']=$c['error'];continue; }
            if (in_array($c['state']??'', ['published','hidden','manual_removal'],true)) { $c['message']=['published'=>'Опубликовано','hidden'=>'Скрыто на площадке','manual_removal'=>'Отправка выключена. Снимите объявление на Авито вручную.'][$c['state']];continue; }

            if ($c['management']==='external') { $c['message']='Существующее объявление. Управляется на площадке.';continue; }
            if (!$c['enabled'] && $c['externalId']==='') { $c['state']='off';$c['message']='Не выбрана';continue; }
            $c['message']=match($c['state']??'off') {'queued'=>'Ожидает публикации','awaiting_report'=>'Ожидаем ответа Авито','awaiting_confirmation'=>$c['message']??'Flowwow: ждём подтверждения изменений','off'=>'Отправка выключена.',default=>$c['enabled']?'Сохраните товар, чтобы опубликовать его.':'Отправка выключена.'};
        }
        unset($c);return $p;
    }
    public function preview(string $id,string $channel): array
    {
        if(!in_array($channel,['avito','flowwow'],true))throw new \InvalidArgumentException('invalid_channel');
        $found=array_values(array_filter($this->store->all(true),fn($p)=>$p['id']===$id));if(!$found)throw new \OutOfBoundsException('product_missing');
        $p=$found[0];$c=$p['channels'][$channel];$site=rtrim($this->config['site_url']??'https://botanikart.ru','/');$warnings=[];
        $payload=['id'=>$p['id'],'title'=>$c['title']?:$p['title'],'description'=>$c['description']?:$p['description'],'price'=>$c['price']??$p['price'],'images'=>$p['images']];
        if(!$c['enabled'])$warnings[]='Площадка выключена.';
        if(!$this->connections()[$channel]['configured'])$warnings[]='Доступ к площадке не настроен.';
        if($channel==='avito'){
            require_once __DIR__.'/AvitoFeed.php';$file=dirname($this->directory,2).'/catalogue/avito-schema.json';
            if(is_file($file)){
                $feed=new AvitoFeed(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR));
                try{$xml=$feed->render([$p],$site);$doc=new \DOMDocument();$doc->loadXML($xml);$payload['title']=$doc->getElementsByTagName('Title')->item(0)->textContent;$payload['price']=(float)$doc->getElementsByTagName('Price')->item(0)->textContent;}
                catch(\Throwable){$warnings=array_merge($warnings,$feed->check($this->productStatus($p),$site)['warnings']);if(!$warnings)$warnings[]='Проверьте настройки общего объявления Авито.';}
            }else $warnings[]='Шаблоны Авито не загружены.';
        }else{
            require_once __DIR__.'/FlowwowPublisher.php';$fw=new FlowwowPublisher($this->config['flowwow']??[],$this->directory);
            try{$source=$fw->cachedProduct($p);if(!$source&&!empty($c['externalId']))$source=$fw->findProduct($c['externalId']);$category=(int)($source['subCategoryId']??$c['category']??0);$fw->payload($p,$source,$fw->schema($category),$site,(string)($source['offerId']??$c['offerId']??('ba-'.$p['id'])));}catch(\Throwable $e){$warnings[]=$e->getMessage();}
        }
        return ['channel'=>$channel,'ready'=>!$warnings,'action'=>$c['externalId']!==''?'update':'create','product'=>$payload,'warnings'=>$warnings];
    }
    /** Valid Avito structure for the connected category template; not exposed or activated yet. */
    public static function avitoXml(array $products,array $categoryFields): string
    {
        if (!$products || !$categoryFields) throw new \InvalidArgumentException('incomplete_feed');
        $escape=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_XML1,'UTF-8');
        $xml='<?xml version="1.0" encoding="UTF-8"?><Ads formatVersion="3" target="Avito.ru">';
        foreach ($products as $p) {
            $fields=$categoryFields[$p['catalogGroup']]??null;
            if (!$fields || empty($fields['Category']) || empty($fields['Address'])) throw new \InvalidArgumentException('missing_category_template');
            $xml.='<Ad><Id>'.$escape($p['id']).'</Id>';
            foreach ($fields as $key=>$value) {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D',$key)||in_array($key,['Id','AvitoId','Title','Description','Price','Images','DateEnd'],true)||!is_scalar($value)) throw new \InvalidArgumentException('invalid_template');
                $xml.='<'.$key.'>'.$escape($value).'</'.$key.'>';
            }
            $xml.='<Title>'.$escape($p['title']).'</Title><Description>'.$escape($p['description']).'</Description><Price>'.$escape($p['price']).'</Price><Images>';
            foreach ($p['images'] as $url) {
                if (!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https') throw new \InvalidArgumentException('invalid_image_url');
                $xml.='<Image url="'.$escape($url).'"/>';
            }
            $xml.='</Images></Ad>';
        }
        return $xml.'</Ads>';
    }
}
