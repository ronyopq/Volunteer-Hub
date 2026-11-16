<?php
declare(strict_types=1);
require_once __DIR__.'/../src/import_tools.php';
$index=['by_code'=>['PRAN-000002'=>[22=>true]],'by_document'=>['1234567890'=>[22=>true]],'by_phone'=>['01700000000'=>[22=>true]],'rows'=>[22=>['id'=>22,'payload'=>json_encode(['id_number'=>'1234567890']),'deleted_at'=>null]]];
$seen=[];
$a=import_classify_row(['_import_code'=>'PRAN-000002','id_number'=>'1234567890','phone'=>'01700000000'],$index,$seen);
if($a['type']!=='update'||$a['id']!==22)throw new RuntimeException('Existing ID not matched');
$seen=[];$b=import_classify_row(['_import_code'=>'NEW-VOL','id_number'=>'2345678901','phone'=>'01700000000'],$index,$seen);
if($b['type']!=='new'||!$b['phone_warning'])throw new RuntimeException('Shared phone incorrectly rejected');
$old=['payload'=>json_encode(['name_en'=>'Original','name_bn'=>'Original BN','phone'=>'01700000000','status'=>'সক্রিয়'])];
$merged=import_merge_existing($old,['name_bn'=>'Updated BN','phone'=>'','_import_present_keys'=>['name_bn','phone']],'system');
if($merged['phone']!=='01700000000'||$merged['name_en']!=='Original'||$merged['name_bn']!=='Updated BN')throw new RuntimeException('Import merge lost data');
echo "PASS: existing match, shared phone, non-destructive field merge\n";
