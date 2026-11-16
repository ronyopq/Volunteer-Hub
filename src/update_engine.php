<?php
declare(strict_types=1);
/** PRAAN verified updater (legacy signed manifest namespace is PRAN). Requires authenticated admin and CSRF at HTTP entrypoint. */
const UM_ROOT = __DIR__ . '/..';
const UM_STORAGE = __DIR__ . '/../storage';
const UM_BACKUPS = __DIR__ . '/../storage/update_backups';
const UM_WORK = __DIR__ . '/../storage/update_work';
const UM_KEYFILE = __DIR__ . '/../storage/update_signer.pub';
const UM_MAX_ARCHIVE = 26214400; // 25 MiB maximum signed update archive
const UM_MAX_FILE = 6291456; // 6 MiB maximum per installed file

function um_safe_mkdir(string $path): void {
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) throw new RuntimeException('ফোল্ডার তৈরি করা সম্ভব হয়নি।');
}
function um_atomic_file(string $path, string $content): void {
    um_safe_mkdir(dirname($path));
    $tmp = dirname($path).'/.writing_'.bin2hex(random_bytes(8));
    if (file_put_contents($tmp,$content,LOCK_EX) === false) throw new RuntimeException('ফাইল লেখা সম্ভব হয়নি।');
    @chmod($tmp,0640);
    if (!@rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('ফাইল বদল করা সম্ভব হয়নি।'); }
}
function um_lock(callable $operation): mixed {
    um_safe_mkdir(UM_STORAGE);
    $h = fopen(UM_STORAGE.'/update.lock', 'c+');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) { if ($h) fclose($h); throw new RuntimeException('অন্য একটি ব্যাকআপ/আপডেট চলছে।'); }
    try { return $operation(); } finally { flock($h,LOCK_UN);fclose($h); }
}
function um_maintenance(): array {
    $file=UM_STORAGE.'/maintenance.json';
    if(!is_file($file)) return ['enabled'=>false];
    $data=json_decode((string)@file_get_contents($file),true);
    return is_array($data) && !empty($data['enabled']) ? $data : ['enabled'=>false];
}
function um_set_maintenance(bool $enabled, string $reason=''): void {
    um_atomic_file(UM_STORAGE.'/maintenance.json',json_encode([
        'enabled'=>$enabled,'reason'=>$reason,'updated_at'=>gmdate('c'), 'user_id'=>admin_id()
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    um_audit($enabled?'maintenance_on':'maintenance_off',$reason);
}
function um_gate(string $page, string $method): void {
    if(empty(um_maintenance()['enabled'])) return;
    if(!admin() && !in_array($page,['login','logout'],true)) {
        http_response_code(503); header('Retry-After: 600'); header('Cache-Control: no-store');
        echo '<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PRAAN Maintenance</title><body style="font-family:system-ui,sans-serif;background:#eef5f6;color:#14384b;padding:8vh 6vw;text-align:center"><h1>সাইটের রক্ষণাবেক্ষণ চলছে</h1><p>ডাটা নিরাপদ রাখতে সাময়িকভাবে নিবন্ধন বন্ধ আছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।</p><a href="index.php?page=login">অ্যাডমিন লগইন</a></body></html>';
        exit;
    }
    if ($method==='POST' && !in_array($page,['login','update_maintenance','update_backup_create','update_install','update_rollback','update_recover','update_migrate','update_key_save','update_repo_save','logout'],true)) {
        http_response_code(503); header('Retry-After: 600'); exit('রক্ষণাবেক্ষণের সময় তথ্য পরিবর্তন বন্ধ। Update Center থেকে Maintenance Mode বন্ধ করুন।');
    }
}
function um_audit(string $action,string $detail=''): void {
    um_safe_mkdir(UM_STORAGE);
    $row=json_encode(['time'=>gmdate('c'),'user_id'=>admin_id(),'action'=>$action,'version'=>APP_VERSION,'detail'=>mb_safe_limit($detail,400)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    file_put_contents(UM_STORAGE.'/update-history.jsonl',$row,FILE_APPEND|LOCK_EX);
    if (function_exists('log_action')) {try {log_action(null,'update_'.$action,$detail);}catch(Throwable $e){error_log('PRAN update audit db: '.$e->getMessage());}}
}
function um_history(int $limit=35): array {
    $p=UM_STORAGE.'/update-history.jsonl'; if(!is_file($p))return [];
    $lines=@file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
    $rows=[];
    foreach(array_reverse(array_slice($lines,-$limit)) as $line){$obj=json_decode($line,true); if(is_array($obj))$rows[]=$obj;}
    return $rows;
}
function um_key(): ?string {
    if(!is_file(UM_KEYFILE))return null;
    $raw=base64_decode(trim((string)file_get_contents(UM_KEYFILE)),true);
    return is_string($raw) && strlen($raw)===32 ? $raw : null;
}
function um_install_key(string $b64): void {
    if(is_file(UM_KEYFILE)) throw new RuntimeException('সাইনিং কী ইতিমধ্যে সেট করা হয়েছে; পরিবর্তনের জন্য cPanel দিয়ে storage/update_signer.pub পরিবর্তন করুন।');
    $raw=base64_decode(trim($b64),true);
    if(!is_string($raw)||strlen($raw)!==32||!function_exists('sodium_crypto_sign_verify_detached'))throw new RuntimeException('বৈধ Ed25519 public key প্রয়োজন (base64)।');
    um_atomic_file(UM_KEYFILE,base64_encode($raw)."\n");
    um_audit('public_key_configured',substr(hash('sha256',$raw),0,16));
}
function um_require_password(string $password): void {
    $q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([admin_id()]);
    if(!password_verify($password,(string)$q->fetchColumn()))throw new RuntimeException('অ্যাডমিন পাসওয়ার্ড সঠিক নয়।');
}
function um_require_extensions(bool $network=false): void {
    if(!class_exists('ZipArchive'))throw new RuntimeException('PHP ZipArchive extension চালু করুন।');
    if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('pdo_sqlite extension চালু করুন।');
    if($network && (!function_exists('curl_init')||!function_exists('sodium_crypto_sign_verify_detached')))throw new RuntimeException('One-Click Update-এর জন্য PHP cURL এবং sodium extension লাগবে।');
}
function um_code_paths(): array {
    $paths=[];
    foreach (['index.php','.htaccess','README.md','CHANGELOG.md','THIRD_PARTY_NOTICES.md','INSTALL_CPANEL_BN.txt','manifest.webmanifest','service-worker.js','offline.html'] as $rel) {
        if(is_file(UM_ROOT.'/'.$rel)&&!is_link(UM_ROOT.'/'.$rel)) $paths[]=$rel;
    }
    foreach(['src','assets','public','resources','tools','modules'] as $folder){
        $base=UM_ROOT.'/'.$folder;if(!is_dir($base))continue;
        $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
        foreach($iter as $item){
            if(!$item->isFile()||$item->isLink())continue;
            $rel=substr($item->getPathname(),strlen(UM_ROOT)+1);
            if(um_valid_code_path($rel))$paths[]=$rel;
        }
    }
    sort($paths,SORT_STRING);return array_values(array_unique($paths));
}
function um_valid_code_path(string $path): bool {
    if($path==='index.php'||$path==='.htaccess'||in_array($path,['README.md','CHANGELOG.md','THIRD_PARTY_NOTICES.md','INSTALL_CPANEL_BN.txt','manifest.webmanifest','service-worker.js','offline.html'],true))return true;
    if(strlen($path)>230||str_contains($path,'\\')||str_contains($path,'..')||str_starts_with($path,'/'))return false;
    return (bool)preg_match('~^(?:src|assets|public|resources|tools|modules)/(?:[a-zA-Z0-9._-]+/)*[a-zA-Z0-9._-]+$~D',$path);
}
function um_photo_paths(): array {
    $dir=PHOTO_DIR;if(!is_dir($dir))return [];
    $files=[];foreach(new DirectoryIterator($dir) as $item){
        if($item->isFile()&&!$item->isLink()&&preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.(jpg|jpeg|png|webp)$/Di',$item->getFilename()))$files[]=$item->getFilename();
    }sort($files);return $files;
}
function um_db_snapshot(PDO $pdo,string $destination): array {
    if(is_file($destination))throw new RuntimeException('ব্যাকআপের গন্তব্য আগে থেকেই আছে।');
    $quoted=str_replace("'","''",$destination);
    $pdo->exec("VACUUM INTO '$quoted'");
    if(!is_file($destination)||filesize($destination)<512)throw new RuntimeException('SQLite snapshot তৈরি হয়নি।');
    $test=new PDO('sqlite:'.$destination,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $integrity=$test->query('PRAGMA integrity_check')->fetchColumn();
    if($integrity!=='ok')throw new RuntimeException('SQLite integrity_check ব্যর্থ।');
    $count=(int)$test->query('SELECT COUNT(*) FROM volunteers')->fetchColumn();
    $users=(int)$test->query('SELECT COUNT(*) FROM users')->fetchColumn();
    unset($test);
    return ['volunteers'=>$count,'users'=>$users];
}
function um_archive_stream_hash(ZipArchive $zip,string $name): string {
    $stream=$zip->getStream($name);if(!$stream)throw new RuntimeException('আর্কাইভ ফাইল পড়তে সমস্যা: '.$name);
    $ctx=hash_init('sha256');while(!feof($stream)){
        $chunk=fread($stream,65536);if($chunk===false){fclose($stream);throw new RuntimeException('ZIP এন্ট্রি পড়া যায়নি।');}
        hash_update($ctx,$chunk);
    }fclose($stream);return hash_final($ctx);
}
function um_assert_safe_target(string $rel): string {
    if(!um_valid_code_path($rel))throw new RuntimeException('অবৈধ কোড ফাইল পাথ।');
    $root=realpath(UM_ROOT);
    if($root===false)throw new RuntimeException('Project root পাওয়া যায়নি।');
    $parts=explode('/',$rel);array_pop($parts);$walk=$root;
    foreach($parts as $component){$walk.='/'.$component;if(is_link($walk))throw new RuntimeException('Symlink directory-তে ইনস্টল বন্ধ।');}
    $destination=$root.'/'.$rel;
    if(is_link($destination))throw new RuntimeException('Symlink file-এ ইনস্টল বন্ধ।');
    return $destination;
}
function um_archive_copy(ZipArchive $zip,string $name,string $dest): void {
    $stream=$zip->getStream($name);if(!$stream)throw new RuntimeException('ZIP ফাইলটি পাওয়া যায়নি।');
    um_safe_mkdir(dirname($dest));$out=fopen($dest,'wb');if(!$out){fclose($stream);throw new RuntimeException('গন্তব্য ফাইল খোলা যায়নি।');}
    while(!feof($stream)){
        $chunk=fread($stream,65536);
        if($chunk===false||($chunk===''&&!feof($stream))){fclose($out);fclose($stream);throw new RuntimeException('ZIP stream পড়া যায়নি।');}
        $size=strlen($chunk);$offset=0;while($offset<$size){$n=fwrite($out,substr($chunk,$offset));if($n===false||$n===0){fclose($out);fclose($stream);throw new RuntimeException('ZIP ফাইল কপি করা যায়নি।');}$offset+=$n;}
    }fclose($out);fclose($stream);
}
function um_create_backup(PDO $pdo,string $reason='manual'): array {
    um_require_extensions(); um_safe_mkdir(UM_BACKUPS);um_safe_mkdir(UM_WORK);
    $token=bin2hex(random_bytes(6));
    $dbtmp=UM_WORK.'/db-'.$token.'.sqlite';
    $name='pran-'.gmdate('Ymd-His').'-'.$token.'.zip';
    $path=UM_BACKUPS.'/'.$name;
    try {
        $counts=um_db_snapshot($pdo,$dbtmp);
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('ZIP ব্যাকআপ খোলা যায়নি।');
        $items=[];
        $add=function(string $inside,string $source)use($zip,&$items):void{
            if(!is_file($source)||is_link($source))throw new RuntimeException('ব্যাকআপের উৎস ফাইল নেই।');
            if(!$zip->addFile($source,$inside))throw new RuntimeException('ZIP-এ ফাইল যোগ করা যায়নি।');
            $items[$inside]=hash_file('sha256',$source);
        };
        $add('snapshot/database.sqlite',$dbtmp);
        foreach(um_photo_paths() as $p)$add('snapshot/photos/'.$p,PHOTO_DIR.'/'.$p);
        foreach(um_code_paths() as $p)$add('snapshot/code/'.$p,UM_ROOT.'/'.$p);
        $manifest=['format'=>'PRAN-BACKUP-1','version'=>APP_VERSION,'reason'=>$reason,'created_at'=>gmdate('c'),'counts'=>$counts,'files'=>$items];
        if(!$zip->addFromString('manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)))throw new RuntimeException('Manifest লেখা যায়নি।');
        if(!$zip->close())throw new RuntimeException('ZIP ব্যাকআপ বন্ধ হয়নি।');
        $check=um_verify_backup($path);
        if(!$check['ok'])throw new RuntimeException('ব্যাকআপ যাচাই ব্যর্থ: '.$check['error']);
        um_audit('backup_verified',$name.' '.$reason.' '.($counts['volunteers']??0).' volunteers');
        return ['name'=>$name,'path'=>$path,'manifest'=>$manifest];
    }catch(Throwable $e){@unlink($path);throw $e;}finally{@unlink($dbtmp);}
}
function um_verify_backup(string $path): array {
    try{
        um_require_extensions();
        if(!is_file($path))throw new RuntimeException('ব্যাকআপ ফাইল নেই।');
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('ZIP পড়া যায়নি।');
        try{
            $raw=$zip->getFromName('manifest.json');
            $manifest=is_string($raw)?json_decode($raw,true):null;
            if(!is_array($manifest)||($manifest['format']??'')!=='PRAN-BACKUP-1'||!is_array($manifest['files']??null))throw new RuntimeException('Manifest সঠিক নয়।');
            um_safe_mkdir(UM_WORK);
            $files=$manifest['files'];
            if(!isset($files['snapshot/database.sqlite'],$files['snapshot/code/index.php'],$files['snapshot/code/src/bootstrap.php']))throw new RuntimeException('ডাটাবেইস বা মূল কোড নেই।');
            if(count($files)>20000)throw new RuntimeException('ব্যাকআপে অতিরিক্ত ফাইল।');
            foreach($files as $entry=>$sha){
                if(!is_string($entry)||!is_string($sha)||!preg_match('/^[0-9a-f]{64}$/D',$sha))throw new RuntimeException('ফাইল তালিকা অবৈধ।');
                if($entry!=='snapshot/database.sqlite' && !preg_match('~^snapshot/(?:photos/[a-zA-Z0-9._-]+|code/.+)$~D',$entry))throw new RuntimeException('অবৈধ ব্যাকআপ পাথ।');
                if(!hash_equals($sha,um_archive_stream_hash($zip,$entry)))throw new RuntimeException('SHA-256 মিলছে না: '.$entry);
            }
            $temp=UM_WORK.'/verify-'.bin2hex(random_bytes(8)).'.sqlite';
            um_archive_copy($zip,'snapshot/database.sqlite',$temp);
            try{
                $db=new PDO('sqlite:'.$temp,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('SQLite integrity_check ব্যর্থ।');
                $count=(int)$db->query('SELECT COUNT(*) FROM volunteers')->fetchColumn();
                $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
                unset($db);
                if($count!==(int)($manifest['counts']['volunteers']??-1)||$users!==(int)($manifest['counts']['users']??-1))throw new RuntimeException('Snapshot রেকর্ডের সংখ্যা মেলেনি।');
            }finally{@unlink($temp);}
            return ['ok'=>true,'manifest'=>$manifest];
        }finally{$zip->close();}
    }catch(Throwable $e){return ['ok'=>false,'error'=>$e->getMessage()];}
}
function um_backup_names(): array {
    if(!is_dir(UM_BACKUPS))return [];$found=[];
    foreach(new DirectoryIterator(UM_BACKUPS) as $f){
        if($f->isFile()&&preg_match('/^pran-\d{8}-\d{6}-[a-f0-9]{12}\.zip$/D',$f->getFilename()))$found[]=['name'=>$f->getFilename(),'size'=>$f->getSize(),'time'=>$f->getMTime()];
    }
    usort($found,fn($a,$b)=>$b['time']<=>$a['time']);return array_slice($found,0,30);
}
function um_backup_path(string $name): string {
    if(!preg_match('/^pran-\d{8}-\d{6}-[a-f0-9]{12}\.zip$/D',$name))throw new RuntimeException('ব্যাকআপের নাম সঠিক নয়।');
    $path=UM_BACKUPS.'/'.$name;if(!is_file($path)||is_link($path))throw new RuntimeException('ব্যাকআপ পাওয়া যায়নি।');
    return $path;
}
function um_restore_code(string $backupPath): string {
    $check=um_verify_backup($backupPath);
    if(!$check['ok'])throw new RuntimeException('ব্যাকআপ অক্ষত নয়; Rollback বন্ধ: '.$check['error']);
    $zip=new ZipArchive();if($zip->open($backupPath)!==true)throw new RuntimeException('ব্যাকআপ ZIP পাওয়া যায়নি।');
    $restored=0;
    try{
        foreach($check['manifest']['files'] as $name=>$hash){
            if(!str_starts_with($name,'snapshot/code/'))continue;
            $rel=substr($name,strlen('snapshot/code/'));
            if(!um_valid_code_path($rel))throw new RuntimeException('ব্যাকআপে অবৈধ কোড পাথ।');
            $dest=um_assert_safe_target($rel);
            um_safe_mkdir(dirname($dest));
            $tmp=dirname($dest).'/.restore_'.bin2hex(random_bytes(8));
            try{um_archive_copy($zip,$name,$tmp);if(!hash_equals($hash,hash_file('sha256',$tmp)))throw new RuntimeException('Restore checksum ভুল।');@chmod($tmp,0644);if(!@rename($tmp,$dest))throw new RuntimeException('Rollback ফাইল লেখা যায়নি।');if(function_exists('opcache_invalidate')&&str_ends_with($dest,'.php'))@opcache_invalidate($dest,true);$restored++;}finally{@unlink($tmp);}
        }
    }finally{$zip->close();}
    if($restored===0)throw new RuntimeException('Rollback-এর জন্য কোনো কোড ফাইল নেই।');
    return 'Restored '.$restored.' code files';
}
function um_release_asset_url(array $asset,string $repo,string $tag): string {
    $url=(string)($asset['browser_download_url']??'');
    $parts=parse_url($url);
    if(!is_array($parts)||($parts['scheme']??'')!=='https'||($parts['host']??'')!=='github.com'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||(isset($parts['port'])&&(int)$parts['port']!==443))throw new RuntimeException('GitHub asset URL নিরাপদ নয়।');
    if(!str_starts_with((string)($parts['path']??''),'/'.$repo.'/releases/download/'.rawurlencode($tag).'/'))throw new RuntimeException('অচেনা GitHub Release asset।');
    return $url;
}
function um_download_github(string $url,string $out,int $limit): void {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension নেই।');
    $allowed=['github.com','release-assets.githubusercontent.com','objects.githubusercontent.com'];
    for($redirects=0;$redirects<5;$redirects++){
        $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));
        if(($parts['scheme']??'')!=='https'||!in_array($host,$allowed,true)||isset($parts['user'])||isset($parts['pass'])||(isset($parts['port'])&&(int)$parts['port']!==443))throw new RuntimeException('Asset redirect অনুমোদিত নয়।');
        $location='';$written=0;$file=fopen($out,'wb');if(!$file)throw new RuntimeException('ডাউনলোড ফাইল তৈরি করা যায়নি।');
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>90,CURLOPT_USERAGENT=>'PRAN-Secure-Updater/'.APP_VERSION,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($ch,$data)use($file,$limit,&$written){$length=strlen($data);$written+=$length;return $written<=$limit?fwrite($file,$data):0;},
        ]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);fclose($file);
        if($ok===false){@unlink($out);throw new RuntimeException('ডাউনলোড ব্যর্থ অথবা ফাইল অস্বাভাবিক বড়: '.$err);}
        if($status===200)return;
        @unlink($out);
        if(in_array($status,[301,302,303,307,308],true)&&$location!==''&&str_starts_with($location,'https://')){$url=$location;continue;}
        throw new RuntimeException('GitHub থেকে ফাইল পাওয়া যায়নি (HTTP '.$status.').');
    }
    throw new RuntimeException('অনেকবার redirect হয়েছে; ডাউনলোড বন্ধ।');
}
function um_verify_release_package(string $zipfile,string $shaFile,string $sigFile,string $version): array {
    um_require_extensions(true);
    $key=um_key();if($key===null)throw new RuntimeException('Trusted Ed25519 public key এখনো সেট করা হয়নি।');
    $checksum=trim((string)file_get_contents($shaFile));
    if(!preg_match('/^([0-9a-fA-F]{64})(?:\s+\*?pran-update-v[0-9.]+\.zip)?\s*$/D',$checksum,$m))throw new RuntimeException('SHA-256 ফাইলের ফরম্যাট ভুল।');
    $actual=hash_file('sha256',$zipfile);
    if(!hash_equals(strtolower($m[1]),$actual))throw new RuntimeException('ZIP-এর SHA-256 মিলছে না।');
    $signature=base64_decode(trim((string)file_get_contents($sigFile)),true);
    if(!is_string($signature)||strlen($signature)!==64||!sodium_crypto_sign_verify_detached($signature,"PRAN-UPDATE\0".hex2bin($actual),$key))throw new RuntimeException('Ed25519 ডিজিটাল স্বাক্ষর যাচাই ব্যর্থ।');
    $zip=new ZipArchive();if($zip->open($zipfile)!==true)throw new RuntimeException('Release ZIP খুলতে সমস্যা।');
    try{
        $raw=$zip->getFromName('update-manifest.json');$manifest=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($manifest)||($manifest['format']??'')!=='PRAN-UPDATE-1'||($manifest['version']??'')!==$version||!is_array($manifest['files']??null))throw new RuntimeException('Release manifest/version অসঙ্গত।');
        if(version_compare($version,APP_VERSION,'<='))throw new RuntimeException('শুধু বর্তমানের চেয়ে নতুন সংস্করণ ইনস্টল করা যাবে।');
        if(isset($manifest['requires_php']) && (!is_string($manifest['requires_php']) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D',$manifest['requires_php']) || version_compare(PHP_VERSION,$manifest['requires_php'],'<')))throw new RuntimeException('Release-এর জন্য আরও নতুন PHP প্রয়োজন।');
        if(isset($manifest['requires_extensions'])){
            if(!is_array($manifest['requires_extensions'])||count($manifest['requires_extensions'])>20)throw new RuntimeException('Extension শর্ত ভুল।');
            foreach($manifest['requires_extensions'] as $ext){if(!is_string($ext)||!preg_match('/^[a-z_]{2,35}$/D',$ext)||!extension_loaded($ext))throw new RuntimeException('প্রয়োজনীয় PHP Extension নেই।');}
        }
        $files=$manifest['files'];if(count($files)<5||count($files)>500||!isset($files['index.php'],$files['src/bootstrap.php']))throw new RuntimeException('অসম্পূর্ণ Release ফাইল।');
        if($zip->numFiles!==count($files)+1)throw new RuntimeException('অপ্রত্যাশিত ZIP এন্ট্রি।');
        $total=0;$seen=[];
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);$name=(string)($stat['name']??'');
            if($name==='update-manifest.json')continue;
            if(!um_valid_code_path($name)||!isset($files[$name])||isset($seen[$name]))throw new RuntimeException('অনুমোদিত নয়/ডুপ্লিকেট ফাইল: '.$name);
            $seen[$name]=true;
            $size=(int)($stat['size']??0);$total+=$size;
            if($size>UM_MAX_FILE||$total>67108864)throw new RuntimeException('Release ফাইল অতিরিক্ত বড়।');
            if(!preg_match('/^[0-9a-f]{64}$/D',(string)$files[$name])||!hash_equals($files[$name],um_archive_stream_hash($zip,$name)))throw new RuntimeException('Release ফাইলের checksum ভুল: '.$name);
            // Unix special files (symlink etc.) are not accepted.
            $opsys=0;$attrs=0;
            if($zip->getExternalAttributesIndex($i,$opsys,$attrs) && $opsys===ZipArchive::OPSYS_UNIX){$mode=($attrs >> 16) & 0170000; if($mode!==0 && $mode!==0100000)throw new RuntimeException('ZIP-এ বিশেষ ফাইল/সিমলিংক আছে।');}
        }
        if(count($seen)!==count($files))throw new RuntimeException('Release ফাইল অসম্পূর্ণ।');
        if(!str_contains((string)$zip->getFromName('src/bootstrap.php'),"const APP_VERSION = '".$version."';"))throw new RuntimeException('কোডের APP_VERSION প্রকাশিত ভার্সনের সঙ্গে মেলেনি।');
        return ['ok'=>true,'version'=>$version,'files'=>$files,'sha256'=>$actual,'manifest'=>$manifest];
    }finally{$zip->close();}
}
function um_apply_signed_zip(string $zipfile,array $validated): int {
    $zip=new ZipArchive();if($zip->open($zipfile)!==true)throw new RuntimeException('ZIP খোলা যায়নি।');
    $written=0;
    try{
        foreach($validated['files'] as $relative=>$hash){
            if(!um_valid_code_path($relative))throw new RuntimeException('অবৈধ আপডেট পাথ।');
            $target=um_assert_safe_target($relative);
            um_safe_mkdir(dirname($target));
            $tmp=dirname($target).'/.updating_'.bin2hex(random_bytes(8));
            try{
                um_archive_copy($zip,$relative,$tmp);
                if(!hash_equals($hash,hash_file('sha256',$tmp)))throw new RuntimeException('ইনস্টল checksum যাচাই ব্যর্থ।');
                @chmod($tmp,0644);
                if(!@rename($tmp,$target))throw new RuntimeException('ফাইল লেখা যায়নি: '.$relative);
                if(function_exists('opcache_invalidate') && str_ends_with($target,'.php'))@opcache_invalidate($target,true);
                $written++;
            }finally{@unlink($tmp);}
        }
    }finally{$zip->close();}
    return $written;
}
function um_install_latest(PDO $pdo): array {
    if(function_exists('set_time_limit')) @set_time_limit(240);
    um_require_extensions(true);
    $prior=praan_tx_read();
    if(in_array((string)($prior['state']??''),['installing','rollback_failed','migration_failed'],true)){
        throw new RuntimeException('অসমাপ্ত আপডেট আছে; আগে Update Center থেকে Recovery করুন।');
    }
    praan_update_progress('preflight','Server এবং release পরীক্ষা হচ্ছে',5);
    $health=praan_sqlite_health($pdo);
    if(!$health['ok'])throw new RuntimeException('SQLite preflight ব্যর্থ; আপডেট বন্ধ।');
    praan_migration_catalog();
    $repo=trim(setting('github_repo'));
    if(!valid_github_repository($repo))throw new RuntimeException('প্রথমে GitHub Repository সংযুক্ত করুন।');
    $release=check_github_latest_release($repo);
    if(isset($release['error']))throw new RuntimeException((string)$release['error']);
    if(empty($release['newer']))throw new RuntimeException('নতুন সংস্করণ নেই।');
    $version=(string)$release['version'];$assets=$release['assets']??[];
    $prefix='pran-update-v'.$version.'.zip';
    foreach([$prefix,$prefix.'.sha256',$prefix.'.sig'] as $name){if(!isset($assets[$name]))throw new RuntimeException('Release-এ signed asset নেই: '.$name);}
    um_safe_mkdir(UM_WORK);
    $tmp=UM_WORK.'/install-'.bin2hex(random_bytes(8));um_safe_mkdir($tmp);
    $backupPath='';$modeOn=false;$modified=false;$newFiles=[];$tx=[];
    try{
        praan_update_progress('download','GitHub Release-এর তিনটি ফাইল নামানো হচ্ছে',14);
        $files=[];
        foreach([$prefix,$prefix.'.sha256',$prefix.'.sig'] as $name){
            $files[$name]=$tmp.'/'.$name;
            um_download_github(um_release_asset_url($assets[$name],$repo,(string)$release['tag']),$files[$name],$name===$prefix?UM_MAX_ARCHIVE:8192);
        }
        praan_update_progress('verify','SHA-256 ও Ed25519 signature যাচাই হচ্ছে',28);
        $validated=um_verify_release_package($files[$prefix],$files[$prefix.'.sha256'],$files[$prefix.'.sig'],$version);
        // Before changing any code, check filesystem and avoid a partial update due to permissions.
        foreach(array_keys($validated['files']) as $path){
            $dest=um_assert_safe_target($path);
            $parent=dirname($dest);
            while(!is_dir($parent)&&$parent!==UM_ROOT)$parent=dirname($parent);
            if(!is_writable($parent)||(is_file($dest)&&!is_writable($dest)))throw new RuntimeException('ফাইল লিখবার অনুমতি নেই: '.$path);
        }
        praan_update_progress('maintenance','Maintenance Mode চালু',39);
        um_set_maintenance(true,'নিরাপদ v'.$version.' আপডেট চলছে');$modeOn=true;
        praan_update_progress('backup','Verified SQLite + Photo + Code Backup চলছে',47);
        $backup=um_create_backup($pdo,'before-v'.$version);$backupPath=$backup['path'];
        $existing=array_fill_keys(um_code_paths(),true);
        $newFiles=array_values(array_filter(array_keys($validated['files']),static fn($rel)=>!isset($existing[$rel])));
        $tx=['state'=>'installing','from'=>APP_VERSION,'to'=>$version,'backup'=>$backup['name'],'new_files'=>$newFiles,'sha256'=>$validated['sha256'],'actor'=>admin_id()];
        praan_tx_write($tx);
        praan_update_progress('install','যাচাইকৃত কোড ইনস্টল হচ্ছে',63);
        $modified=true;
        $count=um_apply_signed_zip($files[$prefix],$validated);
        foreach($validated['files'] as $relative=>$sha){
            $dest=um_assert_safe_target($relative);
            if(!is_file($dest)||!hash_equals($sha,(string)hash_file('sha256',$dest)))throw new RuntimeException('ইনস্টল হওয়া ফাইলে checksum mismatch: '.$relative);
        }
        praan_update_progress('migration','Additive SQLite migration (transactional)',81);
        $migration=praan_migration_apply($pdo);
        $health=praan_sqlite_health($pdo);
        if(!$health['ok'])throw new RuntimeException('Post-update SQLite health check ব্যর্থ।');
        praan_update_progress('finish','Update history ও Restore Point সংরক্ষণ হচ্ছে',93);
        $pdo->prepare('INSERT INTO praan_update_receipts(from_version,to_version,status,backup_name) VALUES(?,?,?,?)')->execute([APP_VERSION,$version,'installed',$backup['name']]);
        um_audit('install_success','v'.APP_VERSION.' -> v'.$version.' | backup '.$backup['name'].' | files '.$count.' | migration '.count($migration['applied']));
        $tx['state']='installed';$tx['installed_files']=$count;$tx['migrations']=$migration['applied'];praan_tx_write($tx);
        um_set_maintenance(false,'আপডেট সম্পন্ন');$modeOn=false;
        praan_update_progress('done','Signed update সম্পন্ন; নতুন সংস্করণ দেখতে Reload করুন',100,'success');
        return ['version'=>$version,'backup'=>$backup['name'],'count'=>$count,'migrations'=>$migration['applied']];
    }catch(Throwable $e){
        um_audit('install_failed',substr($e->getMessage(),0,250));
        praan_update_progress('error','ইনস্টল ব্যর্থ; Recovery পরীক্ষা করুন',95,'error');
        if($modified&&$backupPath!==''){
            try{
                um_restore_code($backupPath);
                praan_update_clean_new_files($newFiles);
                if($tx){$tx['state']='rolled_back';$tx['error']=mb_safe_limit($e->getMessage(),170);praan_tx_write($tx);}
                um_audit('auto_rollback_ok',basename($backupPath));
            }catch(Throwable $restore){
                if($tx){$tx['state']='rollback_failed';$tx['error']=mb_safe_limit($restore->getMessage(),170);praan_tx_write($tx);}
                um_audit('auto_rollback_failed',$restore->getMessage());
                throw new RuntimeException('আপডেট ও Auto-Rollback ব্যর্থ। Maintenance চালু আছে; Verified Backup থেকে cPanel Recovery প্রয়োজন।');
            }
        }elseif($tx){$tx['state']='failed_before_install';praan_tx_write($tx);}
        if($modeOn)um_set_maintenance(false,'ব্যর্থ আপডেট বাতিল');
        throw $e;
    }finally{
        foreach(glob($tmp.'/*')?:[] as $file)if(is_file($file))@unlink($file);
        @rmdir($tmp);
    }
}

function um_notification(): ?array {
    if(!admin())return null;
    $repo=trim(setting('github_repo'));if(!valid_github_repository($repo))return null;
    $cacheFile=UM_STORAGE.'/update-cache.json';$raw=is_file($cacheFile)?json_decode((string)@file_get_contents($cacheFile),true):null;
    if(!is_array($raw)||($raw['repo']??'')!==$repo||time()-(int)($raw['checked_at']??0)>43200){
        try{
            $res=check_github_latest_release($repo);
            $raw=['repo'=>$repo,'checked_at'=>time(),'release'=>$res];
            um_atomic_file($cacheFile,json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }catch(Throwable $e){error_log('PRAN update notification: '.$e->getMessage());return null;}
    }
    $release=$raw['release']??null;
    return is_array($release)&&!isset($release['error'])&&!empty($release['newer'])?$release:null;
}
function um_prerequisites(): array {
    return [
        ['PHP 8.1+',version_compare(PHP_VERSION,'8.1','>='),PHP_VERSION],
        ['PDO SQLite',extension_loaded('pdo_sqlite'),'SQLite database'],
        ['ZIP (verified backups)',class_exists('ZipArchive'),'ZipArchive'],
        ['Sodium (Ed25519)',function_exists('sodium_crypto_sign_verify_detached'),'Signature check'],
        ['cURL (GitHub releases)',function_exists('curl_init'),'HTTPS download'],
        ['Storage writable',is_writable(UM_STORAGE),'Backup/maintenance state'],
        ['Trusted public key',um_key()!==null,'Pinned Ed25519'],
        ['Configured GitHub repo',valid_github_repository(trim(setting('github_repo'))),'Public release'],
    ];
}
