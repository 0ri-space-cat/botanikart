<?php
declare(strict_types=1);
namespace BotanikArt;

final class CatalogPhoto
{
    public static function geometry(int $width,int $height): array
    {
        if($width<1||$height<1||$width*$height>30000000)throw new \InvalidArgumentException('invalid_image');
        $scale=min(1,2400/max($width,$height));
        if(min($width,$height)>=1200)$scale=min(1,max($scale,1200/min($width,$height)));
        if($width*$height*$scale*$scale>12000000)$scale=min(1,2400/max($width,$height));
        $w=max(1,(int)round($width*$scale));$h=max(1,(int)round($height*$scale));
        return ['width'=>max(1200,$w),'height'=>max(1200,$h),'photoWidth'=>$w,'photoHeight'=>$h,'padded'=>$w<1200||$h<1200];
    }
    public static function write(\GdImage $image,string $target): array
    {
        $g=self::geometry(imagesx($image),imagesy($image));$out=imagecreatetruecolor($g['width'],$g['height']);
        imagefill($out,0,0,imagecolorallocate($out,255,255,255));
        imagecopyresampled($out,$image,(int)(($g['width']-$g['photoWidth'])/2),(int)(($g['height']-$g['photoHeight'])/2),0,0,$g['photoWidth'],$g['photoHeight'],imagesx($image),imagesy($image));
        $tmp=$target.'.tmp-'.bin2hex(random_bytes(5));
        try{if(!imagejpeg($out,$tmp,92))throw new \RuntimeException('Image save failed');chmod($tmp,0644);if(!rename($tmp,$target))throw new \RuntimeException('Image replace failed');}
        finally{if(is_file($tmp))unlink($tmp);}
        return $g;
    }
    public static function forFlowwow(string $root,string $path): string
    {
        $file=realpath($root.'/'.$path);$base=realpath($root.'/assets');
        if(!$file||!$base||!str_starts_with($file,$base.DIRECTORY_SEPARATOR))throw new \RuntimeException('Flowwow: фотография не найдена. Загрузите её в карточку заново.');
        $size=@getimagesize($file);
        if(!$size||$size[0]*$size[1]>30000000||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new \RuntimeException('Flowwow: не удалось прочитать фотографию.');
        if(min($size[0],$size[1])>=1200)return $path;
        $relative='assets/uploads/catalogue/'.substr(hash('sha256','flowwow-frame-v1:'.$path.':'.hash_file('sha256',$file)),0,32).'.jpg';
        $target=$root.'/'.$relative;
        if(!is_file($target)){
            if(filesize($file)>12*1024*1024)throw new \RuntimeException('Flowwow: фотография слишком большая.');
            $image=@imagecreatefromstring(file_get_contents($file));if(!$image)throw new \RuntimeException('Flowwow: не удалось подготовить фотографию.');
            if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);
            self::write($image,$target);
        }
        return $relative;
    }
}
