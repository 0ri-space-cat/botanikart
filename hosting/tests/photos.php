<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/CatalogPhoto.php';
use BotanikArt\CatalogPhoto;
function photoCheck(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/ba-photos-'.bin2hex(random_bytes(5));mkdir($root.'/assets',0700,true);
try{
 $plan=CatalogPhoto::geometry(1800,6000);photoCheck($plan['photoWidth']===1200&&$plan['photoHeight']===4000&&!$plan['padded'],'Portrait was reduced below the marketplace minimum');
 $plan=CatalogPhoto::geometry(6000,4000);photoCheck($plan['width']===2400&&$plan['height']===1600,'Large originals must retain adequate detail');
 $plan=CatalogPhoto::geometry(30000000,1);photoCheck($plan['width']*$plan['height']<=12000000,'Extreme panorama could exhaust memory');
 $im=imagecreatetruecolor(200,400);imagefill($im,0,0,imagecolorallocate($im,240,10,10));imagejpeg($im,$root.'/assets/original.jpg',92);$original=hash_file('sha256',$root.'/assets/original.jpg');
 $derived=CatalogPhoto::forFlowwow($root,'assets/original.jpg');photoCheck($derived!=='assets/original.jpg','Small existing photo was not adapted');
 $size=getimagesize($root.'/'.$derived);photoCheck($size[0]===1200&&$size[1]===1200,'Prepared image does not meet Flowwow dimensions');
 $out=imagecreatefromjpeg($root.'/'.$derived);$center=imagecolorsforindex($out,imagecolorat($out,600,600));$edge=imagecolorsforindex($out,imagecolorat($out,0,0));$outside=imagecolorsforindex($out,imagecolorat($out,490,600));
 photoCheck($center['red']>200&&$center['green']<50&&$edge['green']>245&&$outside['green']>245,'Photo was stretched/cropped instead of centered on a light background');
 photoCheck(hash_file('sha256',$root.'/assets/original.jpg')===$original,'Existing website photograph was changed');
 photoCheck(CatalogPhoto::forFlowwow($root,'assets/original.jpg')===$derived,'Repeated publication creates unnecessary photo copies');
 photoCheck(CatalogPhoto::forFlowwow($root,$derived)===$derived,'Already suitable image was reprocessed');
 try{CatalogPhoto::forFlowwow($root,'../outside.jpg');throw new LogicException('Invalid path accepted');}catch(RuntimeException){}
 echo "Photo checks passed: dimensions, portrait detail, bounded memory, no crop/stretch, preserved originals and cached platform copies.\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
