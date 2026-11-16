<?php
declare(strict_types=1);
/** Human-friendly codes and unrelated opaque URL tokens. All access still requires RBAC or receipt. */
function praan_random_code(int $length=20): string {
    $alphabet='0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $answer=''; for($i=0;$i<$length;$i++) $answer.=$alphabet[random_int(0,strlen($alphabet)-1)];
    return $answer;
}
function praan_next_number(PDO $pdo,string $scope): int {
    $q=$pdo->prepare('INSERT INTO praan_code_counters(scope,n) VALUES (?,1) ON CONFLICT(scope) DO UPDATE SET n=n+1');
    $q->execute([$scope]);
    $q=$pdo->prepare('SELECT n FROM praan_code_counters WHERE scope=?');$q->execute([$scope]);return (int)$q->fetchColumn();
}
function praan_next_volunteer_code(PDO $pdo,string $date=''): string {
    $stamp=valid_date($date)?substr(str_replace('-','',$date),2,4):date('ym');
    return 'PRAAN-VOL-'.$stamp.str_pad((string)praan_next_number($pdo,'volunteer:'.$stamp),2,'0',STR_PAD_LEFT);
}
function praan_next_user_code(PDO $pdo): string {
    $year=date('y');
    return 'USR-'.$year.str_pad((string)praan_next_number($pdo,'user:'.$year),2,'0',STR_PAD_LEFT);
}
function praan_next_form_no(PDO $pdo): string {
    $q=$pdo->prepare('SELECT 1 FROM volunteers WHERE form_no=? LIMIT 1');
    for($i=0;$i<80;$i++){
        $code='FOR-'.praan_random_code(5);
        $q->execute([$code]); if(!$q->fetchColumn())return $code;
    }
    throw new RuntimeException('ফর্ম নম্বর তৈরি করা যাচ্ছে না; আবার চেষ্টা করুন।');
}
function praan_new_public_token(PDO $pdo): string {
    $q=$pdo->prepare('SELECT 1 FROM volunteers WHERE public_token=? LIMIT 1');
    for($i=0;$i<5;$i++){
        // 20 random base32 characters ~= 100 bits; short, timestamp-truncated ULIDs are weaker.
        $value='PRAAN-VOL-'.praan_random_code(20);
        $q->execute([$value]);if(!$q->fetchColumn())return $value;
    }
    throw new RuntimeException('Secure URL token allocation failed');
}
function praan_identity_migrate(PDO $pdo): void {
    foreach(['volunteers'=>['public_token'=>'TEXT','legacy_code'=>'TEXT','legacy_form_no'=>'TEXT'],
             'users'=>['display_code'=>'TEXT']] as $table=>$items){
        $names=array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach($items as $key=>$type)if(!in_array($key,$names,true))$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$key.' '.$type);
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS praan_code_counters (scope TEXT PRIMARY KEY,n INTEGER NOT NULL);');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_praan_public_token ON volunteers(public_token);');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_praan_user_display_code ON users(display_code);');
    $manage=!$pdo->inTransaction(); if($manage)$pdo->beginTransaction();
    try {
        // Seed counters from any existing readable IDs before allocating numbers.
        $counterSet=$pdo->prepare('INSERT INTO praan_code_counters(scope,n) VALUES(?,?) ON CONFLICT(scope) DO UPDATE SET n=MAX(n,excluded.n)');
        foreach($pdo->query("SELECT volunteer_code FROM volunteers WHERE volunteer_code LIKE 'PRAAN-VOL-%'")->fetchAll(PDO::FETCH_COLUMN) as $existing){
            if(preg_match('/^PRAAN-VOL-([0-9]{4})([0-9]{2,})$/D',(string)$existing,$m))$counterSet->execute(['volunteer:'.$m[1],(int)$m[2]]);
        }
        foreach($pdo->query("SELECT display_code FROM users WHERE display_code LIKE 'USR-%'")->fetchAll(PDO::FETCH_COLUMN) as $existing){
            if(preg_match('/^USR-([0-9]{2})([0-9]{2,})$/D',(string)$existing,$m))$counterSet->execute(['user:'.$m[1],(int)$m[2]]);
        }
        $query=$pdo->query("SELECT id,volunteer_code,form_no,registered_on,payload,public_token FROM volunteers WHERE public_token IS NULL OR public_token='' OR volunteer_code NOT LIKE 'PRAAN-VOL-%' OR form_no NOT LIKE 'FOR-_____'");
        $update=$pdo->prepare('UPDATE volunteers SET public_token=?,volunteer_code=?,legacy_code=?,form_no=?,legacy_form_no=?,payload=? WHERE id=?');
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $v){
            $code=(string)$v['volunteer_code'];$fno=(string)$v['form_no'];
            $newCode=str_starts_with($code,'PRAAN-VOL-')?$code:praan_next_volunteer_code($pdo,(string)$v['registered_on']);
            $newForm=preg_match('/^FOR-[0-9A-HJKMNP-TV-Z]{5}$/D',$fno)?$fno:praan_next_form_no($pdo);
            $token=(string)$v['public_token']?:praan_new_public_token($pdo);
            $data=json_decode((string)$v['payload'],true);if(!is_array($data))$data=[];$data['form_no']=$newForm;
            $update->execute([$token,$newCode,($code!==$newCode?$code:null),$newForm,($fno!==$newForm?$fno:null),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),$v['id']]);
        }
        $q=$pdo->query("SELECT id FROM users WHERE display_code IS NULL OR display_code='' ORDER BY id");
        $userUpdate=$pdo->prepare('UPDATE users SET display_code=? WHERE id=?');
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$userUpdate->execute([praan_next_user_code($pdo),$id]);
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('github_repo','ronyopq/Volunteer-Hub'),('support_whatsapp_phone','8801718417417')");
        $pdo->exec("UPDATE settings SET value='ronyopq/Volunteer-Hub' WHERE key='github_repo' AND trim(value)=''");
        $pdo->exec("UPDATE settings SET value='8801718417417' WHERE key='support_whatsapp_phone' AND trim(value)=''");
        if($manage)$pdo->commit();
    } catch(Throwable $e){if($manage&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Exact NID or birth-registration number is a strong duplicate. Matching phone requires review. Names never identify duplicates. */
function praan_normalize_document(string $input): string {
    $bn=['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9'];
    $digits=preg_replace('/[^0-9]/','',strtr(trim($input),$bn))??'';
    // Only valid-length numeric NID/birth-registration candidates can be identity keys.
    return strlen($digits)>=9 && strlen($digits)<=20 && preg_match('/[1-9]/',$digits) ? $digits : '';
}
function praan_normalize_phone(string $phone): string {
    $number=preg_replace('/[^0-9]/','',strtr($phone,['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']))??'';
    if(str_starts_with($number,'00880'))$number=substr($number,2);
    if(str_starts_with($number,'880') && strlen($number)===13)$number='0'.substr($number,3);
    if(strlen($number)===10 && str_starts_with($number,'1'))$number='0'.$number;
    return $number;
}
function praan_duplicate_evidence(PDO $pdo,array $data,int $exclude=0): array {
    $id=praan_normalize_document((string)($data['id_number']??''));
    $phone=praan_normalize_phone((string)($data['phone']??''));
    if($id===''&&$phone==='')return ['document'=>[],'phone'=>[]];
    $sql='SELECT id,volunteer_code,phone,payload FROM volunteers WHERE deleted_at IS NULL'.($exclude?' AND id<>?':'');
    $q=$pdo->prepare($sql);$q->execute($exclude?[$exclude]:[]);
    $found=['document'=>[],'phone'=>[]];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $payload=json_decode((string)$row['payload'],true)?:[];
        if($id!==''&&praan_normalize_document((string)($payload['id_number']??''))===$id)$found['document'][]=(string)$row['volunteer_code'];
        if($phone!==''&&praan_normalize_phone((string)$row['phone'])===$phone)$found['phone'][]=(string)$row['volunteer_code'];
    }
    return $found;
}
