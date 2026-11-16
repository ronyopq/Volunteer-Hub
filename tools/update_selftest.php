<?php
declare(strict_types=1);
/** CLI-only security checks. No volunteer database needed. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/update_engine.php';
require_once __DIR__.'/../src/system_operations.php';
function assert_test(bool $cond,string $message): void {if(!$cond){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
assert_test(APP_VERSION==='2.2.0','software version');
$paths=um_code_paths();
assert_test(in_array('src/system_operations.php',$paths,true),'operations part of signed/backup code');
assert_test(in_array('src/migrations/001_update_receipts.sql',$paths,true),'migrations included in signed/backup code');
assert_test(um_valid_code_path('src/migrations/001_update_receipts.sql'),'safe migration path');
foreach(['storage/pran.sqlite','../../storage/secrets.txt','src/../index.php','/var/www/index.php','src/exploit.php/../'] as $bad)assert_test(!um_valid_code_path($bad),'unsafe path rejected: '.$bad);
$catalog=praan_migration_catalog();
assert_test(isset($catalog['001_update_receipts.sql']),'migration catalog');
assert_test(count($catalog['001_update_receipts.sql']['statements'])===2,'migration statements validated');
$work=sys_get_temp_dir().'/praan-migrate-'.bin2hex(random_bytes(6));mkdir($work);mkdir($work.'/src');mkdir($work.'/src/migrations');
file_put_contents($work.'/src/migrations/001_bad.sql','DROP TABLE users;');
try{praan_migration_catalog($work);assert_test(false,'destructive SQL rejected');}
catch(RuntimeException $e){assert_test(str_contains($e->getMessage(),'additive'),'destructive SQL blocked');}
unlink($work.'/src/migrations/001_bad.sql');rmdir($work.'/src/migrations');rmdir($work.'/src');rmdir($work);
assert_test(!extension_loaded('pdo_sqlite') || class_exists(PDO::class),'PHP runtime sanity');
