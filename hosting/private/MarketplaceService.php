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
            $result[$channel]=['configured'=>$hasCredentials,'outboundEnabled'=>false,'health'=>$health,
                'message'=>$hasCredentials?'Доступ сохранён. Отправка ещё не активирована.':'Отправка: ожидает подключения'];
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
            if ($c['management']==='external') { $c['message']='Существующее объявление. Управляется на площадке.';continue; }
            if (!$c['enabled'] && $c['externalId']==='') { $c['state']='off';$c['message']='Не выбрана';continue; }
            $c['state']='waiting_connection';
            $c['message']=$c['enabled']?'Выбрана. Отправка начнётся после подключения и проверки.':'Ожидает снятия с площадки после подключения.';
        }
        unset($c);return $p;
    }
    public function preview(string $id,string $channel): array
    {
        if (!in_array($channel,['avito','flowwow'],true)) throw new \InvalidArgumentException('invalid_channel');
        $found=array_values(array_filter($this->store->all(true),fn($p)=>$p['id']===$id));
        if (!$found) throw new \OutOfBoundsException('product_missing');
        $p=$found[0];$c=$p['channels'][$channel];
        $site=rtrim($this->config['site_url']??'https://botanikart.ru','/');
        $images=array_map(fn($url)=>str_starts_with($url,'https://')?$url:$site.'/'.ltrim($url,'/'),$p['images']);
        $payload=['id'=>$p['id'],'title'=>$c['title']?:$p['title'],'description'=>$c['description']?:$p['description'],'price'=>$c['price']??$p['price'],'images'=>$images,'status'=>$p['status']];
        $warnings=[];
        if ($c['management']==='external') $warnings[]='Это существующее объявление: оно не будет создано заново.';
        if (!$c['enabled']) $warnings[]='Площадка не выбрана.';
        if (!$this->connections()[$channel]['configured']) $warnings[]='Доступ к площадке ещё не подключён.';
        if ($p['sourceMissing']) $warnings[]='Товар больше не приходит из исходного каталога.';
        if ($channel==='avito') $warnings[]='Перед отправкой требуется подтвердить шаблон категории и настройки автозагрузки.';
        else {
            if (mb_strlen($payload['description'])>5000) $warnings[]='Описание Flowwow должно быть не длиннее 5 000 символов.';
            $warnings[]='Перед отправкой нужно выбрать подкатегорию Flowwow и заполнить её обязательные характеристики.';
            foreach ($p['images'] as $path) {
                if (!str_starts_with($path,'assets/uploads/catalogue/')) continue;
                $size=@getimagesize(dirname($this->directory,2).'/'.$path);
                if ($size && min($size[0],$size[1])<1200) { $warnings[]='Для Flowwow нужны фотографии не меньше 1200 × 1200 пикселей.';break; }
            }
        }
        return ['channel'=>$channel,'ready'=>false,'action'=>$c['management']==='external'?'existing':($c['externalId']!==''?'update':'create'),'product'=>$payload,'warnings'=>$warnings];
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
