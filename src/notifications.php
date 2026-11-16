<?php
declare(strict_types=1);
/** PRAAN v2.2.0 notifications: private per-account messages, no volunteer PII. */
function praan_notifications_migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS praan_notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 type TEXT NOT NULL DEFAULT 'system',
 severity TEXT NOT NULL DEFAULT 'info',
 title TEXT NOT NULL,
 message TEXT NOT NULL,
 target_page TEXT NOT NULL DEFAULT 'dashboard',
 dedupe_key TEXT,
 read_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_praan_notice_dedupe ON praan_notifications(user_id,dedupe_key);
CREATE INDEX IF NOT EXISTS idx_praan_notice_inbox ON praan_notifications(user_id,read_at,created_at DESC);
SQL);
}
function praan_notification_user(PDO $pdo,int $userId,string $title,string $message,string $type='system',string $severity='info',string $page='notifications',?string $key=null): void {
    if($userId<=0)return;
    if(function_exists('praan_enabled')&&!praan_enabled('notifications_enabled'))return;
    $pages=['dashboard','notifications','updates','system_doctor','volunteers','reports','users','security_center','modules'];
    if(!in_array($page,$pages,true))$page='notifications';
    if(!in_array($severity,['info','success','warning','danger'],true))$severity='info';
    $stmt=$pdo->prepare('INSERT OR IGNORE INTO praan_notifications(user_id,type,severity,title,message,target_page,dedupe_key) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$userId,mb_safe_limit($type,35),$severity,mb_safe_limit($title,125),mb_safe_limit($message,1200),$page,$key===null?null:mb_safe_limit($key,160)]);
}
function praan_notification_superadmins(PDO $pdo,string $title,string $message,string $type,string $severity,string $page,?string $key=null): void {
    $stmt=$pdo->query("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='super_admin' AND u.status='active' AND u.deleted_at IS NULL");
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) praan_notification_user($pdo,(int)$id,$title,$message,$type,$severity,$page,$key);
}
function praan_notification_broadcast(PDO $pdo,array $input): int {
    $title=trim((string)($input['title']??''));$message=trim((string)($input['message']??''));
    $target=(string)($input['recipient']??'all');$level=(string)($input['severity']??'info');
    if(praan_str_length($title)<3||praan_str_length($title)>125||praan_str_length($message)<5||praan_str_length($message)>1200)throw new RuntimeException('শিরোনাম ৩–১২৫ এবং বার্তা ৫–১২০০ অক্ষরের হতে হবে।');
    if(!in_array($target,['all','super_admin'],true))throw new RuntimeException('প্রাপক সঠিক নয়।');
    if(!in_array($level,['info','success','warning','danger'],true))throw new RuntimeException('নোটিফিকেশন টাইপ সঠিক নয়।');
    $sql="SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.status='active' AND u.deleted_at IS NULL";
    if($target==='super_admin')$sql="SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='super_admin' AND u.status='active' AND u.deleted_at IS NULL";
    $ids=$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    // No arbitrary external links: user announcements navigate only to the inbox.
    $pdo->beginTransaction();
    try{
        foreach($ids as $id)praan_notification_user($pdo,(int)$id,$title,$message,'announcement',$level,'notifications');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('log_action'))log_action(null,'notification_broadcast','recipients='.count($ids).', severity='.$level);
    return count($ids);
}
function praan_notification_count(PDO $pdo,int $userId): int {
    $q=$pdo->prepare('SELECT COUNT(*) FROM praan_notifications WHERE user_id=? AND read_at IS NULL');$q->execute([$userId]);return (int)$q->fetchColumn();
}
function praan_notification_recent(PDO $pdo,int $userId,int $limit=30): array {
    $q=$pdo->prepare('SELECT id,type,severity,title,message,target_page,read_at,created_at FROM praan_notifications WHERE user_id=? ORDER BY id DESC LIMIT ?');
    $q->bindValue(1,$userId,PDO::PARAM_INT);$q->bindValue(2,max(1,min($limit,100)),PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
}
function praan_notification_payload(PDO $pdo,int $userId): array {
    $recent=praan_notification_recent($pdo,$userId,5);
    return ['ok'=>true,'count'=>praan_notification_count($pdo,$userId),'items'=>array_map(static fn(array $item)=>['id'=>(int)$item['id'],'title'=>$item['title'],'severity'=>$item['severity'],'read'=>$item['read_at']!==null,'created_at'=>$item['created_at']],$recent)];
}
function praan_notification_bell_html(PDO $pdo,int $userId): string {
    $count=praan_notification_count($pdo,$userId);$shown=min($count,99);
    return '<a class="praan-notice-bell" href="'.e(url('notifications')).'" title="নোটিফিকেশন" aria-label="নোটিফিকেশন '.(int)$count.'টি অপঠিত"><i class="bi bi-bell"></i><span class="praan-notice-count" data-praan-notice-count '.($count===0?'hidden':'').'>'.($shown===99?'99+':$shown).'</span></a>';
}
function praan_notifications_page(PDO $pdo): void {
    require_admin();$list=praan_notification_recent($pdo,(int)admin_id(),80);
    page_start('Notification Center','notifications',true);
    $unread=praan_notification_count($pdo,(int)admin_id());
    ?>
    <div class="praan-notice-hero"><div><span class="eyebrow">IN-APP NOTIFICATIONS</span><h2><i class="bi bi-bell"></i> নোটিফিকেশন সেন্টার</h2><p>সফটওয়্যার আপডেট, নিরাপত্তা বার্তা ও অ্যাডমিনের ঘোষণাগুলো এখানে পাবেন।</p></div><span class="praan-notice-total"><?= (int)$unread ?> অপঠিত</span></div>
    <div class="d-flex flex-wrap gap-2 mt-3 mb-3">
       <form method="post" action="<?=e(url('notification_read_all'))?>"><?=csrf_field()?><button type="submit" class="btn btn-outline-brand btn-sm" <?=$unread?'':'disabled'?>>সব পড়া হয়েছে চিহ্নিত করুন</button></form>
       <?php if(praan_can('updates.manage')):?><a class="btn btn-outline-secondary btn-sm" href="<?=e(url('updates',['check'=>'1']))?>"><i class="bi bi-arrow-clockwise"></i> GitHub এখন যাচাই করুন</a><?php endif;?>
    </div>
    <div class="card dashboard-card"><div class="card-body p-0">
    <?php if(!$list):?><div class="p-5 text-center text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> এখনো কোনো নোটিফিকেশন নেই।</div><?php endif;?>
    <?php foreach($list as $row):?><article class="praan-notice-item <?=empty($row['read_at'])?'is-unread':''?>">
        <span class="praan-notice-symbol <?=e($row['severity'])?>"><i class="bi <?= $row['severity']==='danger'?'bi-exclamation-octagon':($row['severity']==='warning'?'bi-exclamation-triangle':'bi-bell') ?>"></i></span>
        <div class="praan-notice-content"><div class="d-flex flex-wrap align-items-center gap-2"><strong><?=e($row['title'])?></strong><?php if(empty($row['read_at'])):?><span class="badge text-bg-info">নতুন</span><?php endif;?></div><p><?=nl2br(e($row['message']))?></p><small><?=e($row['created_at'])?> · <?=e($row['type'])?></small></div>
        <div class="praan-notice-actions"><a class="btn btn-outline-secondary btn-sm" href="<?=e(url($row['target_page']))?>">বিস্তারিত</a><?php if(empty($row['read_at'])):?><form method="post" action="<?=e(url('notification_read'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-brand btn-sm" type="submit">পড়েছি</button></form><?php endif;?></div>
    </article><?php endforeach;?></div></div>
    <?php if(praan_is_super()):?>
    <div class="card dashboard-card mt-4"><div class="card-header"><h3 class="card-title"><i class="bi bi-megaphone me-2"></i> নতুন ঘোষণা পাঠান</h3></div><div class="card-body"><p class="small text-muted">শুধু Super Admin অন্য অ্যাডমিনদের ইন-অ্যাপ ঘোষণা পাঠাতে পারবেন। এতে ইমেইল/SMS পাঠানো হয় না।</p>
    <form method="post" action="<?=e(url('notification_send'))?>" class="row g-3"><?=csrf_field()?>
      <div class="col-md-8"><label class="form-label">শিরোনাম</label><input class="form-control" name="title" required maxlength="125" minlength="3"></div>
      <div class="col-md-4"><label class="form-label">প্রাপক</label><select class="form-select" name="recipient"><option value="all">সব সক্রিয় অ্যাডমিন ইউজার</option><option value="super_admin">শুধু Super Admin</option></select></div>
      <div class="col-md-8"><label class="form-label">বার্তা</label><textarea class="form-control" name="message" rows="3" minlength="5" maxlength="1200" required></textarea></div>
      <div class="col-md-4"><label class="form-label">গুরুত্ব</label><select class="form-select mb-2" name="severity"><option value="info">তথ্য</option><option value="success">সফলতা</option><option value="warning">সতর্কতা</option><option value="danger">জরুরি</option></select><label class="form-label">বর্তমান পাসওয়ার্ড</label><input class="form-control" type="password" name="admin_password" required autocomplete="current-password"></div>
      <div class="col-12"><button class="btn btn-brand" type="submit"><i class="bi bi-send"></i> ঘোষণা পাঠান</button></div>
    </form></div></div><?php endif;?>
    <?php page_end(true);
}
/** Safe API fetch: fixed api.github.com destination, strict repo syntax, no redirects, short timeouts. */
function praan_github_commit(string $repo): array {
    if(!function_exists('curl_init'))return ['error'=>'cURL extension নিষ্ক্রিয়'];
    if(!function_exists('valid_github_repository') || !valid_github_repository($repo))return ['error'=>'Invalid repository'];
    $ch=curl_init('https://api.github.com/repos/'.$repo.'/commits/main');
    if($ch===false)return ['error'=>'GitHub connection failed'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_USERAGENT=>'PRAAN-Update-Monitor/'.APP_VERSION,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!is_string($body)||$code!==200||strlen($body)>250000)return ['error'=>'GitHub commit check unavailable (HTTP '.$code.')'];
    $data=json_decode($body,true);$sha=$data['sha']??null;
    if(!is_string($sha)||!preg_match('/^[a-f0-9]{40}$/D',$sha))return ['error'=>'Invalid GitHub commit response'];
    return ['sha'=>$sha,'url'=>'https://github.com/'.$repo.'/commit/'.$sha];
}
function praan_github_cached_status(): array {
    $path=__DIR__.'/../storage/github-status-v22.json';
    $raw=is_file($path)?@file_get_contents($path):false;
    $data=is_string($raw)?json_decode($raw,true):null;
    return is_array($data)?$data:[];
}
/** A browser visit checks no more than once per configured interval; optional cPanel cron can invoke separately. */
function praan_github_refresh(PDO $pdo,bool $force=false): array {
    $repo=trim(setting('github_repo','ronyopq/Volunteer-Hub'));
    if(!function_exists('valid_github_repository')||!valid_github_repository($repo))return ['error'=>'GitHub repository সঠিক নয়।'];
    $old=praan_github_cached_status();$hours=in_array(praan_option('github_check_interval'),['6','12','24'],true)?(int)praan_option('github_check_interval'):12;
    if(!$force && ($old['repo']??'')===$repo && time()-(int)($old['checked_at']??0)<$hours*3600)return $old;
    $storage=__DIR__.'/../storage';
    if(!is_dir($storage)||!is_writable($storage))return $old+['error'=>'Storage permission নেই'];
    $lock=@fopen($storage.'/github-monitor.lock','c+');if(!$lock)return $old;
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return $old;}
    try{
        $old=praan_github_cached_status();
        if(!$force && ($old['repo']??'')===$repo && time()-(int)($old['checked_at']??0)<$hours*3600)return $old;
        $release=check_github_latest_release($repo);$head=praan_github_commit($repo);
        $same=($old['repo']??'')===$repo;
        $state=['repo'=>$repo,'checked_at'=>time(),'release'=>$release,'head'=>$head,
            'previous_head'=>$same?($old['head']['sha']??''):null];
        $path=$storage.'/github-status-v22.json';
        // The notifier never writes a PHP file; only metadata in protected storage.
        $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(function_exists('um_atomic_file'))um_atomic_file($path,$json);else file_put_contents($path,$json,LOCK_EX);
        if(!isset($release['error'])&&!empty($release['newer'])){
            praan_notification_superadmins($pdo,'নতুন PRAAN Release v'.$release['version'],
                'GitHub '.$repo.'-এ নতুন স্থিতিশীল Release প্রকাশিত হয়েছে। Update Center থেকে স্বাক্ষর যাচাই ও Backup করে ইনস্টল করুন।',
                'update','warning','updates','release:'.$repo.':'.$release['tag']);
        }
        if($same && !empty($old['head']['sha']) && !empty($head['sha']) && !hash_equals((string)$old['head']['sha'],(string)$head['sha'])){
            praan_notification_superadmins($pdo,'GitHub-এ নতুন Commit শনাক্ত',
                'Default main branch পরিবর্তিত হয়েছে ('.substr($head['sha'],0,8).'). এটি শুধু কোড পরিবর্তনের খবর; স্বাক্ষরিত GitHub Release ছাড়া One-Click Update করা যাবে না।',
                'repository','info','updates','commit:'.$repo.':'.$head['sha']);
        }
        return $state;
    }catch(Throwable $e){error_log('PRAAN GitHub Monitor: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());return $old+['error'=>'GitHub পরীক্ষা সম্ভব হয়নি'];}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
function praan_notifications_tick(PDO $pdo,bool $force=false): void {
    if(!admin())return;
    if(!praan_enabled('github_auto_check')&&!$force)return;
    try{praan_github_refresh($pdo,$force);}catch(Throwable $e){error_log('PRAAN notification tick: '.$e->getMessage());}
}
