<?php
declare(strict_types=1);
/** v1.5.0: authenticated/CSRF protected, token-bound, sequential 1 MiB resumable image uploads.
 * Temp files and final photos remain inside the private storage directory.
 */
const MEDIA_CHUNK_BYTES = 1048576;
const MEDIA_TEMP_DIR = __DIR__ . '/../storage/media_uploads';
const MEDIA_TTL = 86400; // 24 hours; abandoned uploads are removed on subsequent init requests

function media_migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS media_uploads (
 uid TEXT PRIMARY KEY,
 draft_uid TEXT NOT NULL,
 volunteer_id INTEGER NOT NULL DEFAULT 0,
 owner_admin_id INTEGER NOT NULL DEFAULT 0,
 token_hash TEXT NOT NULL,
 sha256 TEXT NOT NULL,
 byte_size INTEGER NOT NULL,
 received_bytes INTEGER NOT NULL DEFAULT 0,
 chunk_bytes INTEGER NOT NULL,
 photo_file TEXT,
 status TEXT NOT NULL DEFAULT 'uploading',
 created_at INTEGER NOT NULL,
 updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_media_draft ON media_uploads(draft_uid,updated_at);
CREATE INDEX IF NOT EXISTS idx_media_expiry ON media_uploads(updated_at,status);
SQL);
}
function media_error(string $message, int $status=422): never { media_json(['ok'=>false,'message'=>$message],$status); }
function media_json(array $data, int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;
}
function media_temp_path(string $uid): string {
    if (!praan_uid_valid($uid,'MED')) throw new RuntimeException('অবৈধ মিডিয়া আইডি।');
    return MEDIA_TEMP_DIR.'/'.$uid.'.part';
}
function media_check_access(): void {
    if(!praan_enabled('async_photo_enabled')||!praan_flag_enabled('media','async_upload'))media_error('ব্যাকগ্রাউন্ড ছবি আপলোড বন্ধ আছে।',403);
    if(!admin()&&!praan_enabled('public_registration_enabled'))media_error('পাবলিক নিবন্ধন বন্ধ।',403);
    if(admin()&&!praan_can('volunteers.create')&&!praan_can('volunteers.edit'))media_error('এই ছবি আপলোডের অনুমতি নেই।',403);
    verify_csrf();
}
function media_trim_expired(PDO $pdo): void {
    $cutoff=time()-MEDIA_TTL;
    $q=$pdo->prepare('SELECT uid,photo_file,status FROM media_uploads WHERE updated_at<? LIMIT 60');$q->execute([$cutoff]);
    foreach($q->fetchAll() as $row){
        try{
            $path=media_temp_path((string)$row['uid']);if(is_file($path))@unlink($path);
            $lock=MEDIA_TEMP_DIR.'/'.(string)$row['uid'].'.lock';if(is_file($lock))@unlink($lock);
            if((string)$row['status']==='ready' && !empty($row['photo_file']))@unlink(PHOTO_DIR.'/'.basename((string)$row['photo_file']));
            $pdo->prepare('DELETE FROM media_uploads WHERE uid=?')->execute([$row['uid']]);
        }catch(Throwable $e){error_log('PRAAN Media cleanup: '.$e->getMessage());}
    }
}
function media_init(PDO $pdo): never {
    media_check_access();
    if(!admin()&&!ip_throttle('media_init',20,3600))media_error('অতিরিক্ত ছবি আপলোডের চেষ্টা হয়েছে। পরে আবার চেষ্টা করুন।',429);
    if(!extension_loaded('gd'))media_error('সার্ভারে PHP GD extension নেই। সাধারণ ছবি আপলোড ব্যবহার করুন অথবা GD চালু করুন।',503);
    $draft=trim((string)($_POST['draft_uid']??''));
    $size=filter_var($_POST['size']??null,FILTER_VALIDATE_INT);
    $sha=strtolower(trim((string)($_POST['sha256']??'')));
    $recordId=(int)($_POST['record_id']??0);
    if(!praan_uid_valid($draft,'DRF'))media_error('ড্রাফট আইডি সঠিক নয়।');
    $max=(int)praan_option('max_photo_mb')*1048576;
    if(!$size||$size<256||$size>$max)media_error('ছবির সাইজ অনুমোদিত সীমার বাইরে।');
    if(!preg_match('/^[a-f0-9]{64}$/D',$sha))media_error('ছবির SHA-256 সঠিক নয়।');
    if($recordId){
        if(!admin()||!praan_can('volunteers.edit')||!fetch_volunteer($recordId))media_error('এই রেকর্ডের ছবি বদলানোর অনুমতি নেই।',403);
        $q=$pdo->prepare('SELECT draft_uid FROM volunteers WHERE id=? AND deleted_at IS NULL');$q->execute([$recordId]);
        $stored=$q->fetchColumn();if($stored && $stored!==$draft)media_error('ড্রাফট আইডি এই রেকর্ডের সঙ্গে মেলে না।',409);
    }else{
        // Never let a new/public form attach its upload to a volunteer that already exists.
        $q=$pdo->prepare('SELECT id FROM volunteers WHERE draft_uid=? LIMIT 1');$q->execute([$draft]);
        if($q->fetchColumn())media_error('এই ড্রাফটটি আগেই নিবন্ধিত। নতুন ফর্ম খুলুন।',409);
    }
    if(!is_dir(MEDIA_TEMP_DIR)&&!@mkdir(MEDIA_TEMP_DIR,0750,true)&&!is_dir(MEDIA_TEMP_DIR))media_error('প্রাইভেট আপলোড স্টোরেজ তৈরি হয়নি।',503);
    media_trim_expired($pdo);
    $uid=praan_uid('MED');$token=bin2hex(random_bytes(32));$now=time();
    $pdo->beginTransaction();
    try{
        $previous=$pdo->prepare("SELECT uid,photo_file,status FROM media_uploads WHERE draft_uid=? AND status IN ('uploading','ready')");$previous->execute([$draft]);
        foreach($previous->fetchAll() as $row){
            // Old media is discarded when a new file is selected for the same draft.
            $pdo->prepare("UPDATE media_uploads SET status='superseded',updated_at=? WHERE uid=?")->execute([$now,$row['uid']]);
            $oldPath=media_temp_path((string)$row['uid']);if(is_file($oldPath))@unlink($oldPath);
            if((string)$row['status']==='ready' && !empty($row['photo_file']))@unlink(PHOTO_DIR.'/'.basename((string)$row['photo_file']));
        }
        $q=$pdo->prepare('INSERT INTO media_uploads(uid,draft_uid,volunteer_id,owner_admin_id,token_hash,sha256,byte_size,received_bytes,chunk_bytes,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$uid,$draft,$recordId,admin_id()??0,hash('sha256',$token),$sha,$size,0,MEDIA_CHUNK_BYTES,'uploading',$now,$now]);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    media_json(['ok'=>true,'media_uid'=>$uid,'resume_token'=>$token,'draft_uid'=>$draft,'chunk_bytes'=>MEDIA_CHUNK_BYTES,'received_bytes'=>0,'status'=>'uploading']);
}
function media_authorized(PDO $pdo): array {
    $uid=trim((string)($_POST['media_uid']??''));$draft=trim((string)($_POST['draft_uid']??''));$token=trim((string)($_POST['resume_token']??''));
    if(!praan_uid_valid($uid,'MED')||!praan_uid_valid($draft,'DRF')||!preg_match('/^[a-f0-9]{64}$/D',$token))media_error('আপলোড সেশন পাওয়া যায়নি।',403);
    $q=$pdo->prepare('SELECT * FROM media_uploads WHERE uid=? AND draft_uid=?');$q->execute([$uid,$draft]);$row=$q->fetch();
    if(!$row||!hash_equals((string)$row['token_hash'],hash('sha256',$token)))media_error('আপলোড টোকেন ভুল বা মেয়াদোত্তীর্ণ।',403);
    if(time()-(int)$row['updated_at']>MEDIA_TTL)media_error('আপলোডের মেয়াদ শেষ। নতুন করে নির্বাচন করুন।',410);
    if((int)$row['owner_admin_id']>0 && (!admin() || (int)$row['owner_admin_id']!==(int)admin_id()))media_error('এই আপলোড আপনার নয়।',403);
    if(!in_array($row['status'],['uploading','ready','attached'],true))media_error('এই আপলোডটি বাতিল হয়েছে।',409);
    return $row;
}
function media_status(PDO $pdo): never {
    media_check_access();$row=media_authorized($pdo);
    if($row['status']==='ready') {
        $q=$pdo->prepare('SELECT id FROM volunteers WHERE draft_uid=? AND deleted_at IS NULL LIMIT 1');$q->execute([$row['draft_uid']]);$volunteerId=$q->fetchColumn();
        if($volunteerId){try{media_attach_ready($pdo,(string)$row['draft_uid'],(int)$volunteerId);}catch(Throwable $e){error_log('PRAAN image status attach: '.$e->getMessage());}
            $q=$pdo->prepare('SELECT status FROM media_uploads WHERE uid=?');$q->execute([$row['uid']]);$row['status']=$q->fetchColumn()?:$row['status'];}
    }
    media_json(['ok'=>true,'media_uid'=>$row['uid'],'received_bytes'=>(int)$row['received_bytes'],'size'=>(int)$row['byte_size'],'status'=>$row['status'],'chunk_bytes'=>(int)$row['chunk_bytes']]);
}
function media_convert(string $path, string $sha): string {
    if(!hash_equals($sha,hash_file('sha256',$path)))throw new RuntimeException('ছবির ডাটা মেলেনি। আবার আপলোড করুন।');
    if(!extension_loaded('gd'))throw new RuntimeException('হোস্টিংয়ে PHP GD extension চালু করুন।');
    $detected=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if(!in_array($detected,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('ছবির আসল ফরম্যাট অনুমোদিত নয়।');
    $info=@getimagesize($path);
    if(!$info||!in_array($info['mime']??'',['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('শুধু JPG, PNG বা WebP ছবি গ্রহণ করা হয়।');
    [$w,$h]=$info;
    if($w<20||$h<20||$w*$h>12000000||$w>6000||$h>6000)throw new RuntimeException('ছবির পিক্সেল সাইজ অনুমোদিত নয়।');
    $source=@imagecreatefromstring((string)file_get_contents($path));
    if(!$source)throw new RuntimeException('ছবিটি ডিকোড করা যায়নি।');
    // Keep portrait photos upright; re-encoding strips EXIF including GPS.
    if($detected==='image/jpeg' && function_exists('exif_read_data')) {
        $meta=@exif_read_data($path);$orientation=(int)($meta['Orientation']??1);
        if(function_exists('imageflip')){
            if(in_array($orientation,[2,5,7],true)) imageflip($source,IMG_FLIP_HORIZONTAL);
            if($orientation===4) imageflip($source,IMG_FLIP_VERTICAL);
        }
        $angle=match($orientation){3=>180,5=>-90,6=>-90,7=>90,8=>90,default=>0};
        if($angle!==0){$rotated=imagerotate($source,$angle,0);if($rotated!==false){imagedestroy($source);$source=$rotated;}}
        $w=imagesx($source);$h=imagesy($source);
    }
    $scale=min(1,1600/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
    $canvas=imagecreatetruecolor($nw,$nh);
    if(!$canvas){imagedestroy($source);throw new RuntimeException('ছবি প্রসেস করা যায়নি।');}
    imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));
    imagecopyresampled($canvas,$source,0,0,0,0,$nw,$nh,$w,$h);imagedestroy($source);
    if(!is_dir(PHOTO_DIR)&&!@mkdir(PHOTO_DIR,0750,true)&&!is_dir(PHOTO_DIR)){imagedestroy($canvas);throw new RuntimeException('ছবি সংরক্ষণের ফোল্ডার তৈরি হয়নি।');}
    $filename=bin2hex(random_bytes(16)).'.jpg';$dest=PHOTO_DIR.'/'.$filename;
    $done=imagejpeg($canvas,$dest,85);imagedestroy($canvas);
    if(!$done||!is_file($dest))throw new RuntimeException('ছবিটি সংরক্ষণ করা যায়নি।');
    @chmod($dest,0640);
    return $filename; // Re-encoding strips EXIF/GPS metadata.
}
function media_attach_ready(PDO $pdo, string $draft, int $saved): void {
    if(!praan_uid_valid($draft,'DRF'))return;
    $q=$pdo->prepare("SELECT * FROM media_uploads WHERE draft_uid=? AND status='ready' AND (volunteer_id=0 OR volunteer_id=?) ORDER BY updated_at DESC LIMIT 1");
    $q->execute([$draft,$saved]);$media=$q->fetch();if(!$media)return;
    $file=(string)$media['photo_file'];if($file===''||!is_file(PHOTO_DIR.'/'.$file))return;
    $q=$pdo->prepare('SELECT photo_file FROM volunteers WHERE id=? AND draft_uid=? AND deleted_at IS NULL');$q->execute([$saved,$draft]);$existing=$q->fetch();if(!$existing)return;
    $old=(string)$existing['photo_file'];
    $pdo->prepare('UPDATE volunteers SET photo_file=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND draft_uid=? AND deleted_at IS NULL')->execute([$file,$saved,$draft]);
    $pdo->prepare("UPDATE media_uploads SET status='attached',volunteer_id=?,updated_at=? WHERE uid=? AND status='ready'")->execute([$saved,time(),$media['uid']]);
    if($old!==''&&$old!==$file&&is_file(PHOTO_DIR.'/'.$old))@unlink(PHOTO_DIR.'/'.$old);
    log_action($saved,'photo_attached','Resumable media '.(string)$media['uid']);
}
function media_finalize(PDO $pdo, array $row, string $path): array {
    $uid=(string)$row['uid'];$file=media_convert($path,(string)$row['sha256']);
    try{
        $q=$pdo->prepare("UPDATE media_uploads SET status='ready',photo_file=?,received_bytes=?,updated_at=? WHERE uid=? AND status='uploading'");
        $q->execute([$file,(int)$row['byte_size'],time(),$uid]);
        if(!$q->rowCount())throw new RuntimeException('আপলোড বাতিল করা হয়েছে।');
        $find=$pdo->prepare('SELECT id FROM volunteers WHERE draft_uid=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $find->execute([$row['draft_uid']]);$vid=$find->fetchColumn();
        if($vid && ((int)$row['volunteer_id']===0 || (int)$row['volunteer_id']===(int)$vid)){
            try{media_attach_ready($pdo,(string)$row['draft_uid'],(int)$vid);}catch(Throwable $e){error_log('PRAAN media attach retryable: '.$e->getMessage());}
        }
        @unlink($path);
        $q=$pdo->prepare('SELECT status FROM media_uploads WHERE uid=?');$q->execute([$uid]);
        return ['ok'=>true,'media_uid'=>$uid,'received_bytes'=>(int)$row['byte_size'],'status'=>(string)$q->fetchColumn(),'message'=>'ছবি প্রস্তুত এবং রেকর্ড থাকলে সংযুক্ত হয়েছে।'];
    }catch(Throwable $e){@unlink(PHOTO_DIR.'/'.$file);throw $e;}
}
function media_chunk(PDO $pdo): never {
    media_check_access();$row=media_authorized($pdo);
    if($row['status']!=='uploading')media_json(['ok'=>true,'received_bytes'=>(int)$row['received_bytes'],'status'=>$row['status']]);
    $index=filter_var($_POST['chunk_index']??null,FILTER_VALIDATE_INT);
    if($index===false || $index<0)media_error('চাংক নম্বর ভুল।');
    $uploaded=$_FILES['chunk']??null;
    if(!$uploaded||(int)($uploaded['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($uploaded['tmp_name']??'')))media_error('চাংক পাওয়া যায়নি।');
    $len=(int)($uploaded['size']??0);
    $expectedLen=min(MEDIA_CHUNK_BYTES,(int)$row['byte_size']-$index*MEDIA_CHUNK_BYTES);
    if($expectedLen<=0||$len!==$expectedLen)media_error('চাংক সাইজ সঠিক নয়।',409);
    $path=media_temp_path((string)$row['uid']);
    $lock=fopen(MEDIA_TEMP_DIR.'/'.(string)$row['uid'].'.lock','c+');
    if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);media_error('আপলোড ব্যস্ত। আবার চেষ্টা করুন।',503);}
    try{
        $q=$pdo->prepare('SELECT * FROM media_uploads WHERE uid=?');$q->execute([$row['uid']]);$row=$q->fetch();
        if(!$row||$row['status']!=='uploading')throw new RuntimeException('আপলোডটি শেষ বা বাতিল হয়েছে।');
        if((int)$row['received_bytes']>0&&!is_file($path))throw new RuntimeException('আংশিক আপলোড পাওয়া যায়নি; আবার নতুন করে শুরু করুন।');
        $fp=fopen($path,'c+b');if(!$fp)throw new RuntimeException('আপলোড ফাইল খোলা যায়নি।');
        try{
            $actual=fstat($fp)['size']??0;$received=(int)$row['received_bytes'];
            if($actual>$received){ // Recover from an interrupted file-write: discard partial uncommitted chunk.
                ftruncate($fp,$received);
            }elseif($actual<$received){throw new RuntimeException('আপলোডের ফাইল অসম্পূর্ণ; নতুন করে শুরু করুন।');}
            $offset=$index*MEDIA_CHUNK_BYTES;
            if($offset<$received){
                fseek($fp,$offset);$existing=fread($fp,$len);$new=file_get_contents((string)$uploaded['tmp_name']);
                if(!is_string($existing)||!is_string($new)||!hash_equals(hash('sha256',$existing),hash('sha256',$new)))throw new RuntimeException('আগের চাংকটির সঙ্গে ডাটা মেলেনি।');
            }elseif($offset===$received){
                $input=fopen((string)$uploaded['tmp_name'],'rb');if(!$input)throw new RuntimeException('চাংক পড়া যায়নি।');
                fseek($fp,$received);$written=stream_copy_to_stream($input,$fp);fclose($input);fflush($fp);
                if($written!==$len){ftruncate($fp,$received);throw new RuntimeException('চাংক অসম্পূর্ণ লিখেছে।');}
                $received+=$len;
                $pdo->prepare("UPDATE media_uploads SET received_bytes=?,updated_at=? WHERE uid=? AND status='uploading'")->execute([$received,time(),$row['uid']]);
            }else{throw new RuntimeException('পূর্ববর্তী চাংক আগে আপলোড করুন।');}
            $q=$pdo->prepare('SELECT * FROM media_uploads WHERE uid=?');$q->execute([$row['uid']]);$row=$q->fetch();
            $out=['ok'=>true,'media_uid'=>$row['uid'],'received_bytes'=>(int)$row['received_bytes'],'status'=>'uploading'];
            if((int)$row['received_bytes']===(int)$row['byte_size'])$out=media_finalize($pdo,$row,$path);
        }finally{fclose($fp);}
    }catch(Throwable $e){
        error_log('PRAAN Media chunk: '.$e->getMessage());
        try{
            $check=$pdo->prepare('SELECT byte_size,received_bytes,status FROM media_uploads WHERE uid=?');$check->execute([$row['uid']]);$bad=$check->fetch();
            if($bad && (int)$bad['byte_size']===(int)$bad['received_bytes'] && $bad['status']==='uploading'){
                $pdo->prepare("UPDATE media_uploads SET status='failed',updated_at=? WHERE uid=?")->execute([time(),$row['uid']]);
                if(is_file($path))@unlink($path);
            }
        }catch(Throwable $ignored){}
        $out=['ok'=>false,'message'=>$e->getMessage()];
    }
    finally{flock($lock,LOCK_UN);fclose($lock);}
    media_json($out,($out['ok']??false)?200:422);
}
function media_api(string $page, PDO $pdo): never {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')media_error('POST প্রয়োজন।',405);
    try{
        if($page==='media_init')media_init($pdo);
        if($page==='media_status')media_status($pdo);
        if($page==='media_chunk')media_chunk($pdo);
        media_error('অজানা Media API',404);
    }catch(Throwable $e){error_log('PRAAN media API: '.$e->getMessage());media_error('মিডিয়া আপলোড ব্যর্থ। সার্ভার সেটিংস ও ফাইল পরীক্ষা করুন।',422);}
}
