<?php
declare(strict_types=1);
/**
 * PRAAN 2.0 — privacy operations, security overview, bounded retention.
 * Additive schema; no existing volunteer record, receipt or image is migrated/deleted.
 */
function praan_production_migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS privacy_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uid TEXT NOT NULL UNIQUE,
  volunteer_id INTEGER NOT NULL,
  request_type TEXT NOT NULL CHECK(request_type IN ('access','correction','restrict_public','erasure')),
  request_note TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_review','completed','rejected')),
  resolution_note TEXT,
  created_by INTEGER NOT NULL,
  handled_by INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(volunteer_id) REFERENCES volunteers(id),
  FOREIGN KEY(created_by) REFERENCES users(id),
  FOREIGN KEY(handled_by) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_privacy_status_created ON privacy_requests(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_privacy_volunteer_created ON privacy_requests(volunteer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_vol_status_date ON volunteers(deleted_at,status,registered_on);
CREATE INDEX IF NOT EXISTS idx_vol_public_date ON volunteers(deleted_at,public_visible,status,created_at);
CREATE INDEX IF NOT EXISTS idx_vol_created_by ON volunteers(created_by,created_at);
CREATE INDEX IF NOT EXISTS idx_log_action_date ON activity_log(action,created_at);
CREATE INDEX IF NOT EXISTS idx_login_failed_time ON login_history(attempted_username,outcome,created_at);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON user_sessions(expires_at,revoked_at);
SQL);
}
function praan_login_username_locked(PDO $pdo,string $username): bool {
    if ($username==='' || strlen($username)>60) return false;
    $q=$pdo->prepare("SELECT COUNT(*) FROM login_history WHERE attempted_username=? AND outcome='failure' AND created_at >= datetime('now','-15 minutes')");
    $q->execute([$username]);
    return (int)$q->fetchColumn()>=5;
}
function praan_prod_count(PDO $pdo,string $sql,array $params=[]): int {
    $q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
}
function praan_security_summary(PDO $pdo): array {
    $stats=[
      'active_sessions'=>praan_prod_count($pdo,'SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > ?',[time()]),
      'failed_logins'=>praan_prod_count($pdo,"SELECT COUNT(*) FROM login_history WHERE outcome='failure' AND created_at >= datetime('now','-1 day')"),
      'exports'=>praan_prod_count($pdo,"SELECT COUNT(*) FROM activity_log WHERE (action LIKE '%export%' OR action LIKE '%backup_download%' OR action LIKE '%docx_download%') AND created_at >= datetime('now','-30 days')"),
      'pending_privacy'=>praan_prod_count($pdo,"SELECT COUNT(*) FROM privacy_requests WHERE status IN ('open','in_review')"),
      'archived_volunteers'=>praan_prod_count($pdo,'SELECT COUNT(*) FROM volunteers WHERE deleted_at IS NOT NULL'),
    ];
    $integrity='Unknown';
    try{$integrity=(string)$pdo->query('PRAGMA quick_check(1)')->fetchColumn();}catch(Throwable $e){error_log('PRAAN quick_check: '.$e->getMessage());}
    $stats['db_integrity']=$integrity;
    $stats['storage_writable']=is_writable(dirname(DATA_PATH));
    $stats['sqlite_available']=extension_loaded('pdo_sqlite');
    $stats['gd_available']=extension_loaded('gd');
    $stats['https']=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
    return $stats;
}
/** Explicit administrator housekeeping: never remove volunteer, photo, privacy or activity/audit records. */
function praan_production_cleanup(PDO $pdo): array {
    $loginDays=max(30,min(365,(int)praan_option('security_login_retention_days')));
    $sessionDays=max(7,min(90,(int)praan_option('security_session_retention_days')));
    $counts=['login_history'=>0,'sessions'=>0,'rate_limits'=>0,'import_previews'=>0];
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("DELETE FROM login_history WHERE created_at < datetime('now', ?)");
        $q->execute(['-'.$loginDays.' days']);$counts['login_history']=$q->rowCount();
        $q=$pdo->prepare('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?');
        $cut=time()-$sessionDays*86400;$q->execute([$cut,$cut]);$counts['sessions']=$q->rowCount();
        $q=$pdo->prepare('DELETE FROM rate_limits WHERE window_started < ?');
        $q->execute([time()-2*86400]);$counts['rate_limits']=$q->rowCount();
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Stale temporary imports only: exclude session-active previews younger than 24h.
    $folder=dirname(DATA_PATH).'/import_previews';
    if(is_dir($folder)){
        foreach(new DirectoryIterator($folder) as $file){
            if(!$file->isFile() || $file->isLink())continue;
            $name=$file->getFilename();
            if(!preg_match('/^[a-f0-9]{32}\.(csv|xlsx)$/',$name))continue;
            if((int)$file->getMTime()>=time()-86400)continue;
            if(@unlink($file->getPathname()))$counts['import_previews']++;
        }
    }
    try{$pdo->exec('PRAGMA optimize');}catch(Throwable $e){error_log('PRAAN optimize: '.$e->getMessage());}
    return $counts;
}
function praan_privacy_action(PDO $pdo,string $action,array $post): void {
    $actor=(int)admin_id();
    if($action==='privacy_create'){
        $id=filter_var($post['volunteer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $type=(string)($post['request_type']??'');
        $note=trim((string)($post['request_note']??''));
        if(!$id || !in_array($type,['access','correction','restrict_public','erasure'],true)) throw new RuntimeException('রেকর্ড ও অনুরোধের ধরন নির্বাচন করুন।');
        if(mb_safe_length($note)<5 || mb_safe_length($note)>500)throw new RuntimeException('৫ থেকে ৫০০ অক্ষরের বিবরণ প্রয়োজন।');
        $q=$pdo->prepare('SELECT id FROM volunteers WHERE id=? LIMIT 1');$q->execute([$id]);
        if(!$q->fetchColumn())throw new RuntimeException('Volunteer ID পাওয়া যায়নি।');
        $q=$pdo->prepare('INSERT INTO privacy_requests(uid,volunteer_id,request_type,request_note,created_by) VALUES(?,?,?,?,?)');
        $q->execute([praan_uid('PVR'),$id,$type,$note,$actor]);
        log_action((int)$id,'privacy_request_opened','type='.$type.', request_id='.(int)$pdo->lastInsertId());
        return;
    }
    $id=filter_var($post['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $status=(string)($post['status']??'');
    $note=trim((string)($post['resolution_note']??''));
    if(!$id || !in_array($status,['in_review','completed','rejected'],true))throw new RuntimeException('অবৈধ পরিবর্তন।');
    if(in_array($status,['completed','rejected'],true) && mb_safe_length($note)<15)throw new RuntimeException('সমাধানের জন্য অন্তত ১৫ অক্ষরের ব্যাখ্যা প্রয়োজন।');
    if(mb_safe_length($note)>800)throw new RuntimeException('সমাধানের বিবরণ সর্বোচ্চ ৮০০ অক্ষর।');
    $q=$pdo->prepare('SELECT volunteer_id,status FROM privacy_requests WHERE id=?');$q->execute([$id]);$previous=$q->fetch();
    if(!$previous)throw new RuntimeException('Request পাওয়া যায়নি।');
    if(in_array((string)$previous['status'],['completed','rejected'],true))throw new RuntimeException('বন্ধ Request পুনরায় পরিবর্তন করা যাবে না।');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE privacy_requests SET status=?,resolution_note=?,handled_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$note,$actor,$id]);
        // Privacy-positive action: a verified completed public restriction can be enforced immediately.
        $type=$pdo->prepare('SELECT request_type FROM privacy_requests WHERE id=?');$type->execute([$id]);
        if($status==='completed' && $type->fetchColumn()==='restrict_public'){
            $pdo->prepare('UPDATE volunteers SET public_visible=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$previous['volunteer_id']]);
        }
        log_action((int)$previous['volunteer_id'],'privacy_request_status','request_id='.$id.', status='.$status);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mb_safe_length(string $s): int {return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):(int)preg_match_all('/./us',$s);}
function praan_privacy_desk(PDO $pdo): void {
    praan_require('security.view');
    $requests=$pdo->query('SELECT p.*,v.volunteer_code,v.name_bn,v.name_en,u.display_name AS handler_name FROM privacy_requests p JOIN volunteers v ON v.id=p.volunteer_id LEFT JOIN users u ON u.id=p.handled_by ORDER BY p.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    $labels=['access'=>'তথ্য দেখার আবেদন','correction'=>'তথ্য সংশোধন','restrict_public'=>'পাবলিক তালিকা থেকে সরানো','erasure'=>'তথ্য অপসারণের আবেদন'];
    page_start('Privacy Desk','privacy_desk',true);
    ?>
    <div class="praan-v2-intro"><span class="eyebrow">DATA PROTECTION · v<?=e(APP_VERSION)?></span><h2>Privacy Request Management</h2><p>তথ্য দেখা, সংশোধন, প্রকাশ সীমিত করা বা অপসারণের অনুরোধ গ্রহণ ও পর্যালোচনা। আবেদনকারীর পরিচয় আলাদাভাবে যাচাই করুন।</p></div>
    <div class="alert alert-warning" role="alert"><strong>সতর্কতা:</strong> এখানে Request সম্পন্ন করলে স্বেচ্ছাসেবকের তথ্য স্বয়ংক্রিয়ভাবে মুছে যাবে না। প্রকাশ সীমিত করার অনুমোদিত Request সম্পন্ন করলে পাবলিক প্রোফাইল লুকানো হবে; অন্য Request-এ স্বয়ংক্রিয়ভাবে ডাটা মুছবে না। পরিচয়, অনুমোদন ও সংরক্ষণ নীতি যাচাই করুন।</div>
    <?php if(praan_can('security.manage')):?>
    <div class="card dashboard-card mb-4"><div class="card-header"><h3 class="card-title">নতুন Privacy Request</h3></div><div class="card-body"><form method="post" action="<?=e(url('privacy_create'))?>" class="row g-3"><?=csrf_field()?>
      <div class="col-md-3"><label class="form-label">Volunteer Numeric ID<input class="form-control" type="number" name="volunteer_id" min="1" required></label></div>
      <div class="col-md-4"><label class="form-label">আবেদনের ধরন<select class="form-select" name="request_type" required><?php foreach($labels as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?></select></label></div>
      <div class="col-md-5"><label class="form-label">বর্তমান পাসওয়ার্ড<input type="password" name="password" class="form-control" autocomplete="current-password" required></label></div>
      <div class="col-12"><label class="form-label">সংক্ষিপ্ত বিবরণ (NID বা পূর্ণ মোবাইল নম্বর লিখবেন না)<textarea class="form-control" name="request_note" minlength="5" maxlength="500" rows="2" required></textarea></label></div>
      <div class="col-12"><button type="submit" class="btn btn-brand"><i class="bi bi-plus-circle"></i> Request সংরক্ষণ</button></div>
    </form></div></div>
    <?php endif;?>
    <div class="card dashboard-card"><div class="card-header"><h3 class="card-title">সাম্প্রতিক ১০০টি আবেদন</h3></div><div class="card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Request</th><th>Volunteer</th><th>ধরন</th><th>স্ট্যাটাস</th><th>তারিখ</th><th>পরবর্তী পদক্ষেপ</th></tr></thead><tbody>
    <?php foreach($requests as $r):?><tr><td><strong><?=e($r['uid'])?></strong><div class="small text-muted"><?=e($r['request_note'])?></div></td><td><?php if(praan_can('volunteers.view')):?><a href="<?=e(url('view',['id'=>$r['volunteer_id']]))?>"><?=e($r['volunteer_code'])?></a><?php else:?><?=e($r['volunteer_code'])?><?php endif;?></td><td><?=e($labels[$r['request_type']]??$r['request_type'])?></td><td><span class="badge text-bg-secondary"><?=e($r['status'])?></span><div class="small text-muted"><?=e($r['handler_name']??'')?></div></td><td><?=e($r['created_at'])?></td><td>
     <?php if(praan_can('security.manage')&&!in_array($r['status'],['completed','rejected'],true)):?><form action="<?=e(url('privacy_resolve'))?>" method="post" class="praan-privacy-action"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=e($r['id'])?>"><select class="form-select form-select-sm" name="status"><option value="in_review">Review</option><option value="completed">Completed</option><option value="rejected">Rejected</option></select><input class="form-control form-control-sm" name="resolution_note" placeholder="সমাধানের ব্যাখ্যা" maxlength="800"><input type="password" class="form-control form-control-sm" name="password" autocomplete="current-password" placeholder="পাসওয়ার্ড" required><button type="submit" class="btn btn-sm btn-outline-brand">Update</button></form><?php else:?><small><?=e($r['resolution_note']?:'—')?></small><?php endif;?></td></tr><?php endforeach;?>
    <?php if(!$requests):?><tr><td colspan="6" class="text-center text-muted py-4">এখনো কোনো Privacy Request নেই।</td></tr><?php endif;?></tbody></table></div></div>
    <?php page_end(true);
}
function praan_security_center(PDO $pdo): void {
    praan_require('security.view');
    $s=praan_security_summary($pdo);
    page_start('Security & Privacy Center','security_center',true);
    ?>
    <div class="praan-v2-intro"><span class="eyebrow">SECURITY & OPERATIONS · v<?=e(APP_VERSION)?></span><h2>Security & Privacy Center</h2><p>সেশন, লগইন ব্যর্থতা, Export Audit, SQLite Integrity ও Privacy Request এক জায়গায়।</p></div>
    <div class="praan-v2-kpis">
      <?php foreach([
        ['bi-laptop','Active Sessions',$s['active_sessions']],
        ['bi-exclamation-shield','Failed Logins · 24h',$s['failed_logins']],
        ['bi-file-earmark-arrow-down','Downloads · 30d',$s['exports']],
        ['bi-person-lock','Open Privacy Requests',$s['pending_privacy']],
        ['bi-archive','Archived Volunteers',$s['archived_volunteers']],
      ] as [$icon,$label,$value]):?><div class="praan-v2-kpi"><i class="bi <?=e($icon)?>"></i><div class="v2-kpi-num"><?=number_format((int)$value)?></div><div class="v2-kpi-label"><?=e($label)?></div></div><?php endforeach; ?>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-lg-7"><div class="card dashboard-card h-100"><div class="card-header"><h3 class="card-title"><i class="bi bi-heart-pulse me-2"></i> Production Health</h3></div><div class="card-body"><div class="praan-v2-checks">
      <?php foreach([
         ['SQLite quick_check',$s['db_integrity']==='ok','Result: '. $s['db_integrity']],
         ['Private storage writable',$s['storage_writable'],'SQLite/backup writes'],
         ['PHP PDO SQLite',$s['sqlite_available'],'Database engine'],
         ['PHP GD',$s['gd_available'],'EXIF-clean images'],
         ['HTTPS',$s['https'],'HTTPS required for protected login and PWA'],
      ] as [$label,$ok,$detail]):?><div class="praan-v2-check"><span><i class="bi <?= $ok?'bi-check-circle-fill text-success':'bi-exclamation-triangle-fill text-warning'?> me-2"></i><strong><?=e($label)?></strong><small><?=e($detail)?></small></span><span class="badge <?= $ok?'text-bg-success':'text-bg-warning'?>"><?= $ok?'OK':'CHECK'?></span></div><?php endforeach;?></div>
      <p class="small text-muted mt-3 mb-0">Apache/cPanel-এ <code>storage/</code> ও <code>src/</code> URL ব্লক হচ্ছে কি না নিজে যাচাই করুন। Nginx হলে একই সুরক্ষা সার্ভার কনফিগারেশনে দিতে হবে।</p></div></div></div>
      <div class="col-lg-5"><div class="card dashboard-card h-100"><div class="card-header"><h3 class="card-title"><i class="bi bi-shield-lock me-2"></i> Privacy Operations</h3></div><div class="card-body"><p>Privacy Request, Audit ও Retention পরিচালনা করুন। কোনো Volunteer/Photo স্বয়ংক্রিয়ভাবে মোছা হবে না।</p><a class="btn btn-brand mb-3" href="<?=e(url('privacy_desk'))?>"><i class="bi bi-person-lock"></i> Privacy Desk</a>
      <?php if(praan_can('security.manage')):?><form method="post" action="<?=e(url('security_cleanup'))?>" onsubmit="return confirm('শুধু পুরোনো Login History, মেয়াদোত্তীর্ণ Session ও অস্থায়ী Preview মুছবেন? Volunteer, Photo ও Audit Log অক্ষত থাকবে।')"><?=csrf_field()?><p class="small text-muted">Login History retention: <?=e(praan_option('security_login_retention_days'))?> দিন · Revoked Session retention: <?=e(praan_option('security_session_retention_days'))?> দিন</p><label class="form-label">বর্তমান পাসওয়ার্ড<input type="password" name="password" class="form-control" autocomplete="current-password" required></label><button class="btn btn-outline-danger mt-2" type="submit"><i class="bi bi-trash3"></i> Expired Data Cleanup</button></form><?php endif;?></div></div></div>
    </div>
    <?php page_end(true);
}
