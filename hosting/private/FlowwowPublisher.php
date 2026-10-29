<?php
declare(strict_types=1);
namespace BotanikArt;
require_once __DIR__.'/CatalogPhoto.php';

final class FlowwowPublisher
{
    private array $cache=[];
    public function __construct(private array $config,private string $directory,private $transport=null)
    {
        $file=$directory.'/flowwow-source.json';
        if(is_file($file))$this->cache=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    }
    public function request(string $path,array $payload=[],string $method='POST'): array
    {
        if(empty($this->config['token'])||empty($this->config['shop_id']))throw new \RuntimeException('Flowwow: доступ не настроен.');
        $path.=(str_contains($path,'?')?'&':'?').'shopId='.(int)$this->config['shop_id'];
        if($this->transport)return ($this->transport)($method,$path,$payload);
        $h=curl_init('https://apis.flowwow.com'.$path);
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->config['token'],'Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
        if($status<200||$status>=300||!is_string($raw)){
            $decoded=is_string($raw)?json_decode($raw,true):null;
            $details=is_array($decoded)?($decoded['errors'][0]['message']??$decoded['message']??null):null;
            $reason=is_string($details)?$this->safeError($details):'запрос не выполнен';
            throw new \RuntimeException('Flowwow: '.$reason.' (HTTP '.$status.').');
        }
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \RuntimeException('Flowwow: неожиданный ответ.');
        return $data;
    }
    public function categories(): array
    {
        $tree=$this->cache['tree']??$this->request('/apiseller/categories/tree');$items=[];
        foreach($tree['data']??[] as $group)foreach($group['subCategories']??[] as $sub)$items[]=['id'=>(string)$sub['subCategoryId'],'name'=>$group['name'].' · '.$sub['name']];
        return $items;
    }
    public function schema(int $id): array
    {
        $schema=$this->cache['schemas'][(string)$id]??null;
        if(!$schema)$schema=$this->request('/apiseller/categories/subCategoryProperties?subCategoryId='.$id.'&productType=1');
        if(!isset($schema['data'])||!is_array($schema['data']))throw new \RuntimeException('Flowwow: не удалось получить поля категории.');
        return $schema['data'];
    }
    public function cachedProduct(array $p): ?array
    {
        $id=(string)($p['channels']['flowwow']['externalId']??$p['flowwowProductId']??'');
        foreach($this->cache['items']??[] as $item)if((string)$item['productId']===$id)return $item;
        return null;
    }
    public function editor(array $p,?int $subCategory=null): array
    {
        $source=$this->cachedProduct($p);
        if(!$source && !empty($p['channels']['flowwow']['externalId']))$source=$this->findProduct($p['channels']['flowwow']['externalId']);
        $id=$subCategory??(int)($source['subCategoryId']??$p['channels']['flowwow']['category']??0);
        return ['categories'=>$this->categories(),'category'=>(string)$id,'fields'=>$id?$this->schema($id):[],
            'properties'=>$source?$this->baseFields($source,$id?$this->schema($id):[]):[],
            'immutableCategory'=>(bool)$source,'unsupported'=>($source['type']??1)===2];
    }
    public function findProduct(string $id, bool $byOffer=false): ?array
    {
        $response=$this->request('/apiseller/products',[$byOffer?'offerIds':'productIds'=>[$byOffer?$id:(int)$id],'withArchive'=>true,'extended'=>true,'limit'=>10]);
        foreach($response['items']??[] as $p)if((string)($p[$byOffer?'offerId':'productId']??'')===$id)return $p;
        return null;
    }
    private function baseFields(array $source,array $schema): array
    {
        $fields=$source['properties']??[];
        $allowed=json_decode(file_get_contents(__DIR__.'/flowwow-fields.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($allowed as $name)if(array_key_exists($name,$source)&&$source[$name]!==null)$fields[$name]=$source[$name];
        $fields['productType']=$source['type'];$fields['subCategoryId']=$source['subCategoryId'];
        if(($source['minOrder']??0)>0)$fields['minOrderCount']=(int)$source['minOrder'];else unset($fields['minOrderCount']);
        $fields['isPostDelivery']=$source['isDeliveryPost']??false;
        unset($fields['stock'],$fields['productionTime']);
        if(($source['stock']??0)>0)$fields['stock']=(int)$source['stock'];
        elseif(($source['productionTime']??0)>0){
            foreach($schema as $field)if($field['field']==='productionTime')foreach($field['measure']??[] as $measure){
                $name=mb_strtolower($measure['name']??'');
                if(str_contains($name,'мин')){$fields['productionTime']=['val'=>$source['productionTime'],'measure'=>$measure['id']];break 2;}
                if(str_contains($name,'час')){$fields['productionTime']=['val'=>$source['productionTime']/60,'measure'=>$measure['id']];break 2;}
            }
        }
        return array_intersect_key($fields,array_flip($allowed));
    }
    public function payload(array $p,?array $source,array $schema,string $site,?string $offerId=null): array
    {
        $c=$p['channels']['flowwow'];
        if(($source['type']??1)===2)throw new \RuntimeException('Flowwow: этот специальный тип товара нельзя редактировать через API.');
        $body=$source?$this->baseFields($source,$schema):[];
        $allowed=json_decode(file_get_contents(__DIR__.'/flowwow-fields.json'),true,512,JSON_THROW_ON_ERROR);
        $properties=$c['properties']??[];
        $body=array_replace($body,array_intersect_key($properties,array_flip($allowed)));
        // Set the stable seller-side identifier before category-required validation.
        $body['offerId']=$offerId ?: (string)($source['offerId']??$c['offerId']??('ba-'.$p['id']));
        $body['productType']=1;
        $body['subCategoryId']=(int)($source['subCategoryId']??$c['category']??0);
        if($body['subCategoryId']<=0)throw new \RuntimeException('Flowwow: выберите категорию.');
        $body['name']=$c['title']?:$p['title'];$body['description']=str_replace(['\\r\\n','\\n','\\r'],["\n","\n","\n"],$c['description']?:$p['description']);
        $price=(float)($c['price']??$p['price']);
        $oldNet=$source?round((float)$source['price']*(1-(float)($source['discount']??0)/100),2):null;
        if($oldNet===null||abs($oldNet-$price)>0.001){$body['price']=number_format($price,2,'.','');$body['discount']='0';}
        $root=dirname($this->directory,2);
        $body['images']=array_map(function($path)use($site,$root){
            if(str_starts_with($path,'https://'))return $path;
            return rtrim($site,'/').'/'.CatalogPhoto::forFlowwow($root,$path);
        },$p['images']);
        if(mb_strlen($body['description'])>5000)throw new \RuntimeException('Flowwow: описание не должно превышать 5000 символов.');
        if(isset($properties['stock'])&&$properties['stock']!=='')unset($body['productionTime']);
        if(isset($properties['productionTime'])&&$properties['productionTime']!==null)unset($body['stock']);
        // Optional unset values must be absent, rather than empty payload properties.
        foreach($body as $key=>$value)if($value===null||$value===''||$value===[])unset($body[$key]);
        if(!empty($c['stock'])){$body['stock']=$c['stock'];unset($body['productionTime']);}
        if($p['status']==='preorder'&&!isset($body['productionTime']))throw new \RuntimeException('Flowwow: для товара под заказ укажите время изготовления вместо количества.');
        if($p['status']==='ready'&&!isset($body['stock']))throw new \RuntimeException('Flowwow: для товара в наличии укажите количество вместо времени изготовления.');
        if(!isset($body['stock'])&&!isset($body['productionTime']))throw new \RuntimeException('Flowwow: укажите количество или время изготовления.');
        foreach($schema as $f){
            $key=$f['field'];$value=$body[$key]??null;$filled=$value!==null&&$value!==''&&$value!==[];
            $partner=false;foreach($f['dependsOn']??[] as $other)if(isset($body[$other])&&$body[$other]!==''&&$body[$other]!==[])$partner=true;
            if(!empty($f['required'])&&!$filled&&!$partner)throw new \RuntimeException('Flowwow: заполните «'.$f['name'].'».');
            if(!$filled)continue;
            if($partner)throw new \RuntimeException('Flowwow: выберите одно из взаимоисключающих полей «'.$f['name'].'».');
            $number=$f['type']==='measure'?($value['val']??null):$value;
            if(in_array($f['type'],['decimal','measure'],true)){
                if(!is_numeric($number)||!is_finite((float)$number)||isset($f['minValue'])&&(float)$number<$f['minValue']||isset($f['maxValue'])&&(float)$number>$f['maxValue'])throw new \RuntimeException('Flowwow: проверьте значение «'.$f['name'].'».');
                if($f['type']==='measure'&&!in_array($value['measure']??null,array_column($f['measure']??[],'id'),true))throw new \RuntimeException('Flowwow: выберите единицу для «'.$f['name'].'».');
            }
            if($f['type']==='select'&&!in_array($value,array_column($f['values']??[],'id'),true))throw new \RuntimeException('Flowwow: выберите значение «'.$f['name'].'».');
        }
        return $body;
    }
    private function safeError(string $message): string
    {
        $message=trim(strip_tags($message));
        $message=preg_replace('~https?://\\S+~u','[ссылка]', $message);
        $message=preg_replace('/\\b(Bearer|token|password|client_secret|api_key)\\s*[:= ]\\s*[^\\s,;]+/iu','$1 [скрыто]',$message);
        return mb_substr($message,0,350)?:'товар не принят';
    }
    private function ensureSuccess(array $response): void
    {
        if(!empty($response['errors'])||($response['summary']['failed']??0)>0||($response['success']??true)===false){
            $details=$response['errors'][0]['message']??$response['errors'][0]['error']??$response['message']??'товар не принят';
            throw new \RuntimeException('Flowwow: '.$this->safeError(is_string($details)?$details:'товар не принят'));
        }
    }
    public function publish(array $p,string $site): array
    {
        $c=$p['channels']['flowwow'];$remoteId=(string)($c['externalId']??'');$offer=(string)($c['offerId']??'') ?: 'ba-'.$p['id'];
        $source=$remoteId!==''?$this->findProduct($remoteId):$this->findProduct($offer,true);
        if(!$c['enabled'] && !$source)return ['state'=>'off'];
        if($remoteId!==''&&!$source)throw new \RuntimeException('Flowwow: связанный товар не найден. Новая карточка автоматически не создаётся.');
        if($source){
            if(($source['type']??1)===2)throw new \RuntimeException('Flowwow: этот тип товара изменяется только в кабинете Flowwow.');
            if(!empty($source['originalId']))throw new \RuntimeException('Flowwow: арендованный товар нельзя изменять из каталога.');
            $remoteId=(string)$source['productId'];
            if(!empty($source['offerId']))$offer=(string)$source['offerId'];
            // Legacy remote goods need an offer mapping, but only after local payload validation.
        }
        if(!$c['enabled']||$p['status']==='sold'){
            if($source && empty($source['offerId'])){
                $this->ensureSuccess($this->request('/apiseller/products/offersMappings',['offers'=>[['offerId'=>$offer,'productId'=>(int)$remoteId]]]));
            }
            $this->ensureSuccess($this->request('/apiseller/products/hide',['offers'=>[['offerId'=>$offer]]]));
            $check=$this->findProduct($offer,true);
            if(!$check||!empty($check['isActive']))throw new \RuntimeException('Flowwow: скрытие пока не подтверждено.');
            return ['state'=>'hidden','externalId'=>$remoteId,'offerId'=>$offer,'url'=>$check['url']??''];
        }
        $category=(int)($source['subCategoryId']??$c['category']??0);$schema=$this->schema($category);
        $body=$this->payload($p,$source,$schema,$site,$offer);
        if($source && !empty($source['isArchived']))throw new \RuntimeException('Flowwow: товар находится в архиве. Сначала восстановите его в Flowwow.');
        if($source && empty($source['offerId'])){
            $mapping=$this->request('/apiseller/products/offersMappings',['offers'=>[['offerId'=>$offer,'productId'=>(int)$remoteId]]]);
            $this->ensureSuccess($mapping);
        }
        $result=$this->request($source?'/apiseller/products/update':'/apiseller/products/create',['products'=>[$body]]);$this->ensureSuccess($result);
        $this->ensureSuccess($this->request('/apiseller/products/unhide',['offers'=>[['offerId'=>$offer]]]));
        $check=$this->findProduct($offer,true);
        if(!$check || empty($check['isActive'])||$check['name']!==$body['name'])throw new \RuntimeException('Flowwow ещё не подтвердил изменения. Попробуйте позже.');
        $net=round((float)$check['price']*(1-(float)($check['discount']??0)/100),2);
        if(abs($net-(float)($c['price']??$p['price']))>0.009)throw new \RuntimeException('Flowwow: цена ещё не подтвердилась.');
        return ['state'=>'published','externalId'=>(string)$check['productId'],'offerId'=>$offer,'url'=>$check['url']??''];
    }
}
