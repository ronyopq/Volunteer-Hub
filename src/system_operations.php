<?php
declare(strict_types=1);
/** PRAAN v1.9+ operations: conservative, additive migrations and private diagnostic state. */
const PRAAN_PROGRESS_FILE = UM_STORAGE . '/update-progress.json';
const PRAAN_TX_FILE = UM_STORAGE . '/update-transaction.json';

function praan_update_progress(string $step,string $message,int $percent=0,string $state='running'): void {
    um_atomic_file(PRAAN_PROGRESS_FILE,json_encode([
        'step'=>$step,'message'=>$message,'percent'=>max(0,min(100,$percent)),
        'state'=>$state,'at'=>gmdate('c'),'actor'=>admin_id(),
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function praan_update_progress_read(): array {
    $raw=is_file(PRAAN_PROGRESS_FILE)?@file_get_contents(PRAAN_PROGRESS_FILE):false;
    $data=$raw!==false?json_decode($raw,true):null;
    return is_array($data)?$data:['step'=>'idle','message'=>'এই মুহূর্তে কোনো আপডেট চলছে না','percent'=>0,'state'=>'idle'];
}
function praan_tx_write(array $data): void {
    $data['at']=gmdate('c');
    um_atomic_file(PRAAN_TX_FILE,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function praan_tx_read(): array {
    $raw=is_file(PRAAN_TX_FILE)?@file_get_contents(PRAAN_TX_FILE):false;
    $data=$raw!==false?json_decode($raw,true):null;
    return is_array($data)?$data:[];
}
function praan_migration_catalog(string $root=UM_ROOT): array {
    $dir=rtrim($root,'/').'/src/migrations';
    if(!is_dir($dir))return [];
    $found=[];
    foreach(new DirectoryIterator($dir) as $f){
        if($f->isDot()||!$f->isFile()||$f->isLink())continue;
        $name=$f->getFilename();
        if($name==='.htaccess')continue;
        if(!preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/D',$name))throw new RuntimeException('অজানা migration ফাইল: '.$name);
        if($f->getSize()>65536)throw new RuntimeException('Migration ফাইল অত্যন্ত বড়।');
        $body=(string)file_get_contents($f->getPathname());
        if(str_contains($body,"\0")||preg_match('~/\*|\*/~',$body))throw new RuntimeException('Migration SQL comment block সমর্থিত নয়।');
        // The signed manifest covers the whole file. Reject destructive or ambiguous statements.
        $clean=preg_replace('/--[^\r\n]*/','',$body);
        $statements=[];
        foreach(explode(';',$clean) as $stmt){
            $stmt=trim($stmt);
            if($stmt==='')continue;
            if(!preg_match('/^(?:CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(|CREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+[a-zA-Z_][a-zA-Z0-9_]*\s+ON\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(|ALTER\s+TABLE\s+[a-zA-Z_][a-zA-Z0-9_]*\s+ADD\s+COLUMN\s+[a-zA-Z_][a-zA-Z0-9_]*\s+)/i',$stmt)){
                throw new RuntimeException('শুধু additive CREATE/ALTER migration অনুমোদিত: '.$name);
            }
            $statements[]=$stmt;
        }
        if(!$statements)throw new RuntimeException('খালি migration: '.$name);
        $found[$name]=['sha256'=>hash('sha256',$body),'statements'=>$statements];
    }
    if(count($found)>60)throw new RuntimeException('অতিরিক্ত migration পাওয়া গেছে।');
    ksort($found,SORT_STRING);
    return $found;
}
function praan_migration_apply(PDO $pdo): array {
    $all=praan_migration_catalog();$applied=[];$skipped=[];
    $pdo->beginTransaction();
    try{
        $pdo->exec('CREATE TABLE IF NOT EXISTS praan_schema_migrations (name TEXT PRIMARY KEY, sha256 TEXT NOT NULL, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $seen=$pdo->query('SELECT name,sha256 FROM praan_schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($all as $name=>$meta){
            if(isset($seen[$name])){
                if(!hash_equals((string)$seen[$name],$meta['sha256']))throw new RuntimeException('আগের migration ফাইল পরিবর্তন করা হয়েছে: '.$name);
                $skipped[]=$name;continue;
            }
            foreach($meta['statements'] as $sql)$pdo->exec($sql);
            $q=$pdo->prepare('INSERT INTO praan_schema_migrations(name,sha256) VALUES(?,?)');
            $q->execute([$name,$meta['sha256']]);$applied[]=$name;
        }
        if($pdo->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new RuntimeException('SQLite quick_check ব্যর্থ।');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['applied'=>$applied,'skipped'=>$skipped];
}
function praan_sqlite_health(PDO $pdo): array {
    try{
        $quick=(string)$pdo->query('PRAGMA quick_check')->fetchColumn();
        $bad=$pdo->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_NUM);
        $tables=(int)$pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        return ['ok'=>$quick==='ok'&&$bad===false,'detail'=>$quick==='ok'&&$bad===false?'Integrity ও Foreign Keys ঠিক আছে · '.$tables.'টি টেবিল':'SQLite integrity/foreign key ত্রুটি'];
    }catch(Throwable $e){return ['ok'=>false,'detail'=>'SQLite পরীক্ষা সম্ভব হয়নি'];}
}
function praan_doctor(PDO $pdo): array {
    $rows=[];$add=static function(string $group,string $name,bool $ok,string $detail,string $kind='required')use(&$rows):void{
        $rows[]=['group'=>$group,'name'=>$name,'ok'=>$ok,'detail'=>$detail,'kind'=>$kind];
    };
    $add('Server','PHP 8.1+',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION);
    foreach(['pdo_sqlite'=>'SQLite','zip'=>'ZIP / Backup','sodium'=>'Digital Signature','curl'=>'GitHub HTTPS','fileinfo'=>'Image Validation','gd'=>'Image Processing'] as $ext=>$name){
        $active=extension_loaded($ext);$add('Server',$name,$active,$active?'সক্রিয়':'PHP extension সক্রিয় করুন',$ext==='gd'?'optional':'required');
    }
    $add('Security','HTTPS',!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','হোস্টিংয়ে HTTPS ও HSTS যাচাই করুন');
    $add('Security','Private storage rule',is_file(UM_ROOT.'/.htaccess')&&str_contains((string)file_get_contents(UM_ROOT.'/.htaccess'),'storage'),'Apache নিয়ম পাওয়া গেছে; লাইভ HTTP 403 পরীক্ষা করুন');
    $add('Storage','SQLite directory writable',is_dir(UM_STORAGE)&&is_writable(UM_STORAGE),'storage/ ডিরেক্টরি');
    $add('Storage','Photo directory writable',is_dir(PHOTO_DIR)?is_writable(PHOTO_DIR):is_writable(UM_STORAGE),'storage/photos/');
    $free=@disk_free_space(UM_STORAGE);
    $add('Storage','Disk space',is_numeric($free)&&$free>104857600,is_numeric($free)?round($free/1048576,1).' MiB (ফাইলসিস্টেমের আনুমানিক পরিমাণ)':'Storage তথ্য পাওয়া যায়নি','optional');
    $sqlite=praan_sqlite_health($pdo);$add('Database','SQLite integrity + foreign keys',$sqlite['ok'],$sqlite['detail']);
    try{$pending=praan_migration_catalog();$add('Database','Migration SQL validation',true,count($pending).'টি অনুমোদিত additive migration');}
    catch(Throwable $e){$add('Database','Migration SQL validation',false,$e->getMessage());}
    $backups=um_backup_names();$age=$backups?time()-(int)$backups[0]['time']:null;
    $add('Recovery','Recent backup',$age!==null&&$age<=7*86400,$age===null?'Backup নেই':round($age/3600,1).' ঘণ্টা আগে','optional');
    $add('Recovery','Signing public key',um_key()!==null,um_key()!==null?'Trusted key configured':'Public key এখনো কনফিগার করা হয়নি','optional');
    $tx=praan_tx_read();$needsRecovery=in_array((string)($tx['state']??''),['installing','rollback_failed','migration_failed'],true);
    $add('Recovery','Update transaction state',!$needsRecovery,$needsRecovery?'Recovery প্রয়োজন: '.($tx['state']??''):'কোনো অসমাপ্ত আপডেট নেই');
    $add('PWA','Service worker',is_file(UM_ROOT.'/service-worker.js'),is_file(UM_ROOT.'/service-worker.js')?'ফাইল পাওয়া গেছে':'ফাইল নেই','optional');
    $ok=0;$bad=0;$warn=0;foreach($rows as $r){if($r['ok'])$ok++;elseif($r['kind']==='optional')$warn++;else $bad++;}
    return ['version'=>APP_VERSION,'timestamp'=>gmdate('c'),'checks'=>$rows,'passed'=>$ok,'failed'=>$bad,'warnings'=>$warn];
}
function praan_update_clean_new_files(array $paths): void {
    foreach($paths as $rel){
        if(!is_string($rel)||!um_valid_code_path($rel))continue;
        $full=um_assert_safe_target($rel);
        if(is_file($full)&&!is_link($full)&&!@unlink($full))throw new RuntimeException('নতুন ফাইল সরানো যায়নি: '.$rel);
    }
}
