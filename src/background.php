<?php
declare(strict_types=1);
require_once __DIR__.'/settings_hub.php';
function praan_background_file(): string { return __DIR__.'/../storage/branding/home.jpg'; }
function praan_background_upload(array $file): array {
    if(!extension_loaded('gd')) throw new RuntimeException('PHP GD extension প্রয়োজন।');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || (int)($file['size']??0)>3145728 || (int)($file['size']??0)<100)throw new RuntimeException('সর্বোচ্চ ৩ MB JPG/PNG/WebP ছবি নির্বাচন করুন।');
    $source=(string)($file['tmp_name']??'');
    if(!is_uploaded_file($source))throw new RuntimeException('বৈধ আপলোড পাওয়া যায়নি।');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($source);
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('JPG, PNG অথবা WebP দিন।');
    $info=@getimagesize($source);if(!$info||$info[0]<150||$info[1]<150||$info[0]>6000||$info[1]>6000)throw new RuntimeException('ছবির মাত্রা গ্রহণযোগ্য নয়।');
    $raw=@file_get_contents($source);$image=is_string($raw)?@imagecreatefromstring($raw):false;
    if(!$image)throw new RuntimeException('ছবি খোলা সম্ভব হয়নি।');
    $maxW=1920;$maxH=1080;$scale=min(1,$maxW/imagesx($image),$maxH/imagesy($image));
    $w=max(1,(int)round(imagesx($image)*$scale));$h=max(1,(int)round(imagesy($image)*$scale));
    $output=imagecreatetruecolor($w,$h);
    imagefill($output,0,0,imagecolorallocate($output,246,250,250));
    imagecopyresampled($output,$image,0,0,0,0,$w,$h,imagesx($image),imagesy($image));
    $path=praan_background_file();if(!is_dir(dirname($path)) && !mkdir(dirname($path),0750,true))throw new RuntimeException('Storage permission নেই।');
    $temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
    $ok=imagejpeg($output,$temporary,83);imagedestroy($image);imagedestroy($output);
    if(!$ok||!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('ছবি সংরক্ষণ ব্যর্থ।');}
    @chmod($path,0640);log_action(null,'site_background_changed','image re-encoded; EXIF removed');
    return ['ok'=>true,'message'=>'ব্যাকগ্রাউন্ড আপলোড সম্পন্ন হয়েছে।','url'=>url('background_asset',['v'=>time()])];
}
