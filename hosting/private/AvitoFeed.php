<?php
declare(strict_types=1);
namespace BotanikArt;

/** Read-only export: never calls a marketplace API or writes product identities. */
final class AvitoFeed
{
    public function __construct(private array $schema) {}
    public static function applicable(array $field,array $attributes): bool
    {
        if (empty($field['conditions'])) return true;
        foreach ($field['conditions'] as $condition) {
            if (($condition['clause']??'')==='value' && in_array($attributes[$condition['source_field_tag']]??'', $condition['values']??[],true)) return true;
        }
        return false;
    }
    public function check(array $product,string $site): array
    {
        $c=$product['channels']['avito'];$category=$c['category']??'';$attributes=$c['attributes']??[];$warnings=[];
        $definition=$this->schema[$category]??null;
        $title=$c['title']?:$product['title'];$description=$c['description']?:$product['description'];$price=$c['price']??$product['price'];
        $description=str_replace(['\\r\\n','\\n','\\r'],["\n","\n","\n"],$description);
        if (!$definition) $warnings[]='Выберите категорию Авито.';
        $fields=['Category'=>'Растения','Address'=>'Москва, 1-я Владимирская улица, 41','ListingFee'=>'Package','AdStatus'=>'Free'];
        foreach ($definition['fields']??[] as $field) {
            if (!self::applicable($field,$attributes)) continue;
            $value=count($field['values'])===1?$field['values'][0]:($attributes[$field['tag']]??'');
            if ($value==='' || ($field['values'] && !in_array($value,$field['values'],true))) $warnings[]='Заполните поле «'.$field['label'].'» для Авито.';
            else $fields[$field['tag']]=$value;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$title.$description)) $warnings[]='В тексте есть служебные символы. Удалите их.';
        if (mb_strlen($title)>100) $warnings[]='Название на Авито — не более 100 символов.';
        if ($description==='' || mb_strlen($description)>7500) $warnings[]='Описание на Авито — от 1 до 7500 символов.';
        if (!is_numeric($price)||(float)$price<1||(float)$price!==floor((float)$price)) $warnings[]='Цена Авито должна быть целым числом рублей.';
        if (($product['status']??'')==='sold') $warnings[]='Товар снят с продажи. Отключите его отправку на Авито.';
        if (!empty($product['sourceMissing'])) $warnings[]='Товар отсутствует в исходном каталоге.';
        $images=[];
        foreach ($product['images'] as $path) {
            $url=str_starts_with($path,'https://')?$path:rtrim($site,'/').'/'.ltrim($path,'/');
            $host=(string)parse_url($url,PHP_URL_HOST);
            if (!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||($host!==parse_url($site,PHP_URL_HOST)&&!preg_match('/(^|\.)flowwow-images\.com$/i',$host))) $warnings[]='Используйте фото нашего сайта или исходного товара Flowwow.';
            else $images[]=$url;
        }
        $images=array_slice($images,0,10);
        if (!$images) $warnings[]='Добавьте фотографию.';
        $fields['Availability']=($product['status']??'')==='ready'?'В наличии':'Под заказ';
        return ['warnings'=>array_values(array_unique($warnings)), 'fields'=>$fields,'product'=>['id'=>'ba-'.$product['id'],'title'=>$title,'description'=>$description,'price'=>$price,'images'=>$images]];
    }
    public function render(array $products,string $site): string
    {
        $escape=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_XML1,'UTF-8');
        $ads=[];
        foreach ($products as $product) {
            $channel=$product['channels']['avito']??[];
            if (($channel['management']??'')!=='site' || empty($channel['enabled'])) continue;
            $checked=$this->check($product,$site);
            // Never silently omit a broken selected product: Avito could interpret omission as withdrawal.
            if ($checked['warnings']) throw new \RuntimeException('invalid_feed_products');
            $p=$checked['product'];$ad='<Ad><Id>'.$escape($p['id']).'</Id>';
            foreach ($checked['fields'] as $key=>$value) $ad.='<'.$key.'>'.$escape($value).'</'.$key.'>';
            $ad.='<Title>'.$escape($p['title']).'</Title><Description><![CDATA['.str_replace(']]>',']]]]><![CDATA[>',$p['description']).']]></Description><Price>'.$escape($p['price']).'</Price><Images>';
            foreach ($p['images'] as $url) $ad.='<Image url="'.$escape($url).'"/>';
            $ads[]=$ad.'</Images></Ad>';
        }
        // An empty feed can remove existing feed-managed listings. Require an explicit future withdrawal flow.
        if (!$ads) throw new \RuntimeException('empty_feed');
        return '<?xml version="1.0" encoding="UTF-8"?><Ads formatVersion="3" target="Avito.ru">'.implode('',$ads).'</Ads>';
    }
}
