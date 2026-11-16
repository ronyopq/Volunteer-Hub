<?php
declare(strict_types=1);
/** PRAAN v1.6: additive SQLite RBAC, account lifecycle and revocable hashed sessions. */
function praan_str_length(string $s): int {return function_exists('mb_strlen')?mb_strlen($s):(int)preg_match_all('/./us',$s);}
function praan_initial(string $s): string {return preg_match('/^./us',$s,$m)?$m[0]:'A';}
function praan_permissions(): array {
    return [
      'dashboard.view'=>'ড্যাশবোর্ড দেখা',
      'volunteers.view'=>'স্বেচ্ছাসেবক দেখা',
      'volunteers.sensitive'=>'ব্যক্তিগত ও সংবেদনশীল তথ্য দেখা',
      'volunteers.create'=>'নতুন স্বেচ্ছাসেবক যোগ',
      'volunteers.edit'=>'তথ্য সম্পাদনা',
      'volunteers.approve'=>'যাচাই ও স্ট্যাটাস পরিবর্তন',
      'volunteers.archive'=>'আর্কাইভ ও রিস্টোর',
      'volunteers.export'=>'ব্যক্তিগত তথ্য এক্সপোর্ট/প্রিন্ট',
      'import.use'=>'Excel / CSV ইমপোর্ট',
      'reports.view'=>'রিপোর্ট দেখা',
      'audit.view'=>'অ্যাক্টিভিটি ও লগ দেখা',
      'users.view'=>'ইউজার তালিকা ও সেশন দেখা',
      'users.manage'=>'ইউজার তৈরি/সম্পাদনা/বন্ধ/পাসওয়ার্ড রিসেট',
      'roles.manage'=>'কাস্টম রোল ও অনুমতি সম্পাদনা',
      'sessions.manage'=>'অন্যদের সেশন রিভোক',
      'settings.manage'=>'Control Center পরিবর্তন',
      'updates.manage'=>'সাইট আপডেট ও সাইনিং কী',
      'backup.manage'=>'ডাটাবেইস ও ফাইল ব্যাকআপ',
      'modules.view'=>'Module Registry ও Health দেখা',
      'modules.manage'=>'বিশ্বস্ত মডিউল ও Feature Flags পরিচালনা',
    ];
}
function praan_role_presets(): array {
    $all=array_keys(praan_permissions());
    return [
      'super_admin'=>['Super Admin',$all,true],
      'admin'=>['Administrator',array_values(array_diff($all,['users.manage','roles.manage','sessions.manage','updates.manage','modules.manage'])),true],
      'data_entry'=>['Data Entry Operator',['dashboard.view','volunteers.view','volunteers.sensitive','volunteers.create','volunteers.edit'],true],
      'verifier'=>['Verifier',['dashboard.view','volunteers.view','volunteers.sensitive','volunteers.edit','volunteers.approve','reports.view'],true],
      'viewer'=>['Viewer',['dashboard.view','volunteers.view','reports.view'],true],
    ];
}
function praan_auth_migrate(PDO $pdo): void {
    $columns=array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(),'name');
    foreach(['status'=>"TEXT NOT NULL DEFAULT 'active'",'deleted_at'=>'TEXT','updated_at'=>'TEXT','last_login_at'=>'TEXT'] as $name=>$type){
        if(!in_array($name,$columns,true)) $pdo->exec('ALTER TABLE users ADD COLUMN '.$name.' '.$type);
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roles (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 uid TEXT UNIQUE NOT NULL, slug TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
 is_system INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 deleted_at TEXT
);
CREATE TABLE IF NOT EXISTS role_permissions (
 role_id INTEGER NOT NULL, permission TEXT NOT NULL,
 PRIMARY KEY(role_id,permission), FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS user_roles (
 user_id INTEGER NOT NULL, role_id INTEGER NOT NULL,
 PRIMARY KEY(user_id,role_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS user_permission_overrides (
 user_id INTEGER NOT NULL, permission TEXT NOT NULL, allowed INTEGER NOT NULL,
 PRIMARY KEY(user_id,permission), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS user_sessions (
 uid TEXT PRIMARY KEY, user_id INTEGER NOT NULL, token_hash TEXT UNIQUE NOT NULL,
 ip_masked TEXT, user_agent TEXT, created_at INTEGER NOT NULL,
 last_seen_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, revoked_at INTEGER,
 FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_sessions_owner ON user_sessions(user_id,revoked_at,expires_at);
CREATE TABLE IF NOT EXISTS login_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, attempted_username TEXT NOT NULL,
 ip_masked TEXT, user_agent TEXT, outcome TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_history_time ON login_history(id DESC);
CREATE INDEX IF NOT EXISTS idx_user_status ON users(status,deleted_at);
SQL);
    $select=$pdo->prepare('SELECT id FROM roles WHERE slug=? LIMIT 1');
    $insert=$pdo->prepare('INSERT INTO roles(uid,slug,name,is_system) VALUES(?,?,?,1)');
    $insertPerm=$pdo->prepare('INSERT OR IGNORE INTO role_permissions(role_id,permission) VALUES(?,?)');
    foreach(praan_role_presets() as $slug=>[$name,$perms]){
        $select->execute([$slug]);$id=$select->fetchColumn();
        if(!$id){ $insert->execute([praan_uid('ROL'),$slug,$name]); $id=$pdo->lastInsertId(); }
        // Built-in roles receive their canonical permissions on every additive migration.
        // Custom roles are never overwritten.
        foreach($perms as $perm)$insertPerm->execute([(int)$id,$perm]);
    }
    // Legacy migration: first existing account becomes the Super Admin, other pre-existing
    // accounts retain administrator permissions. Never demote already assigned accounts.
    $legacy=$pdo->query('SELECT id FROM users WHERE NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=users.id) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if($legacy){
        $hasSuper=(int)$pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='super_admin' AND u.deleted_at IS NULL AND u.status='active'")->fetchColumn()>0;
        $roleIds=$pdo->query("SELECT id,slug FROM roles WHERE slug IN ('super_admin','admin')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $a=$pdo->prepare('INSERT OR IGNORE INTO user_roles(user_id,role_id) VALUES(?,?)');
        foreach($legacy as $uid){$role=$hasSuper?'admin':'super_admin';$a->execute([(int)$uid,(int)array_search($role,$roleIds,true)]);$hasSuper=true;}
    }
}
function praan_auth_roles(int $userId): array {
    $q=db()->prepare('SELECT r.slug FROM roles r JOIN user_roles ur ON r.id=ur.role_id WHERE ur.user_id=? AND r.deleted_at IS NULL');
    $q->execute([$userId]);return $q->fetchAll(PDO::FETCH_COLUMN);
}
function praan_is_super(): bool {return admin()&&in_array('super_admin',praan_auth_roles((int)admin_id()),true);}
function praan_can(string $permission): bool {
    static $cache=[];
    if(!admin()||!array_key_exists($permission,praan_permissions()))return false;
    $id=(int)admin_id();
    $key=$id.':'.$permission; if(array_key_exists($key,$cache))return $cache[$key];
    $q=db()->prepare("SELECT status,deleted_at FROM users WHERE id=?");$q->execute([$id]);$user=$q->fetch();
    if(!$user||$user['status']!=='active'||$user['deleted_at']!==null)return $cache[$key]=false;
    if(praan_is_super())return $cache[$key]=true;
    $q=db()->prepare('SELECT allowed FROM user_permission_overrides WHERE user_id=? AND permission=?');
    $q->execute([$id,$permission]);$override=$q->fetchColumn();
    if($override!==false)return $cache[$key]=((int)$override===1);
    $q=db()->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN role_permissions rp ON rp.role_id=r.id WHERE ur.user_id=? AND r.deleted_at IS NULL AND rp.permission=? LIMIT 1');
    $q->execute([$id,$permission]);return $cache[$key]=(bool)$q->fetchColumn();
}
function praan_require(string $permission): void {
    if(!admin()){redirect('login');}
    if(!praan_can($permission)){http_response_code(403);header('Cache-Control: no-store');exit('403 — আপনার এই কাজের অনুমতি নেই।');}
}
function praan_route_permission(string $page): ?string {
    $map=[
      'dashboard'=>'dashboard.view','volunteers'=>'volunteers.view','view'=>'volunteers.view',
      'new'=>'volunteers.create','quick'=>'volunteers.create','edit'=>'volunteers.edit','offline_status'=>'volunteers.create',
      'approve'=>'volunteers.approve','delete'=>'volunteers.archive','restore'=>'volunteers.archive',
      'archived'=>'volunteers.archive','bulk_action'=>'volunteers.edit',
      'import'=>'import.use','import_preview'=>'import.use','import_commit'=>'import.use','import_cancel'=>'import.use',
      'reports'=>'reports.view','report_print'=>'reports.view','report_download'=>'volunteers.export',
      'export'=>'volunteers.export','form_export'=>'volunteers.export',
      'template'=>'import.use','template_full'=>'import.use','template_google'=>'import.use',
      'download_docx'=>'volunteers.export','activity'=>'audit.view',
      'settings'=>'settings.manage','settings_save_pro'=>'settings.manage','settings_save'=>'settings.manage',
      'background_upload'=>'settings.manage','background_delete'=>'settings.manage',
      'system_doctor'=>'updates.manage','system_doctor_export'=>'updates.manage','update_progress'=>'updates.manage','update_recover'=>'updates.manage','update_migrate'=>'updates.manage',
      'updates'=>'updates.manage','update_key_save'=>'updates.manage','update_maintenance'=>'updates.manage',
      'update_install'=>'updates.manage','update_rollback'=>'updates.manage','update_repo_save'=>'updates.manage',
      'update_backup_create'=>'backup.manage','update_backup_verify'=>'backup.manage',
      'update_backup_download'=>'backup.manage','backup'=>'backup.manage',
      'users'=>'users.view','roles'=>'roles.manage','user_save'=>'users.manage','user_state'=>'users.manage',
      'user_password'=>'users.manage','role_save'=>'roles.manage','role_delete'=>'roles.manage',
      'sessions'=>'users.view','session_revoke'=>'sessions.manage',
      'profile'=>'dashboard.view','profile_save'=>'dashboard.view','profile_password'=>'dashboard.view','my_session_revoke'=>'dashboard.view',
      'login_history'=>'audit.view',
      'modules'=>'modules.view','modules_export'=>'modules.view',
      'module_state'=>'modules.manage','module_options'=>'modules.manage','module_flag'=>'modules.manage',
    ];return $map[$page]??null;
}
function praan_authorize_route(string $page): void {
    // Public receipt with a possession token remains available to visitors.
    if(in_array($page,['view','print','photo'],true)&&!admin())return;
    if($page==='save'){
        if(admin() && (string)($_POST['entry_type']??'admin')==='admin'){
            praan_require((int)($_POST['id']??0)>0?'volunteers.edit':'volunteers.create');
        }return;
    }
    if(in_array($page,['media_init','media_chunk','media_status'],true))return;
    if($page==='bulk_action'){
        $action=(string)($_POST['action_type']??'');
        $permission=match($action){'archive','restore'=>'volunteers.archive','active','pending'=>'volunteers.approve','export_xlsx','export_csv'=>'volunteers.export',default=>'volunteers.edit'};
        praan_require($permission);if(in_array($action,['export_xlsx','export_csv'],true))praan_require('volunteers.sensitive');return;
    }
    if(in_array($page,['print','photo'],true)&&admin()){praan_require('volunteers.sensitive');return;}
    if(in_array($page,['export','form_export','report_download','download_docx','update_backup_download','backup'],true)&&admin())praan_require('volunteers.sensitive');
    $needed=praan_route_permission($page);if($needed!==null)praan_require($needed);
}
function praan_mask_phone(string $phone): string {
    $digits=preg_replace('/[^0-9]/','',$phone);
    if(strlen($digits)<7)return $phone!==''?'••••••':'—';
    return substr($digits,0,2).str_repeat('•',max(3,strlen($digits)-5)).substr($digits,-3);
}
function praan_mask_ip(string $ip): string {
    if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
        $pieces=explode('.',$ip);return $pieces[0].'.'.$pieces[1].'.'.$pieces[2].'.0/24';
    }
    if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){
        $packed=inet_pton($ip);return inet_ntop(substr($packed,0,8).str_repeat("\0",8)).'/64';
    }
    return 'unknown';
}
function praan_login_event(?int $id,string $username,string $outcome): void {
    $q=db()->prepare('INSERT INTO login_history(user_id,attempted_username,ip_masked,user_agent,outcome) VALUES(?,?,?,?,?)');
    $q->execute([$id,mb_safe_limit($username,60),praan_mask_ip((string)($_SERVER['REMOTE_ADDR']??'')),mb_safe_limit((string)($_SERVER['HTTP_USER_AGENT']??''),190),$outcome]);
}
function praan_session_begin(int $userId): void {
    $id=praan_uid('SES');$stamp=time();$token=hash('sha256',session_id());
    db()->prepare('INSERT INTO user_sessions(uid,user_id,token_hash,ip_masked,user_agent,created_at,last_seen_at,expires_at) VALUES(?,?,?,?,?,?,?,?)')
       ->execute([$id,$userId,$token,praan_mask_ip((string)($_SERVER['REMOTE_ADDR']??'')),mb_safe_limit((string)($_SERVER['HTTP_USER_AGENT']??''),190),$stamp,$stamp,$stamp+8*3600]);
    $_SESSION['praan_v16_session']=$id;
}
function praan_session_end(): void {
    if(!admin())return;
    $q=db()->prepare('UPDATE user_sessions SET revoked_at=? WHERE uid=? AND user_id=? AND revoked_at IS NULL');
    $q->execute([time(),(string)($_SESSION['praan_v16_session']??''),(int)admin_id()]);
}
function praan_auth_validate(PDO $pdo): void {
    if(!admin())return;
    $id=(int)admin_id();$q=$pdo->prepare('SELECT display_name,status,deleted_at FROM users WHERE id=?');$q->execute([$id]);$u=$q->fetch();
    if(!$u||$u['status']!=='active'||$u['deleted_at']!==null){$_SESSION=[];session_regenerate_id(true);return;}
    $sid=(string)($_SESSION['praan_v16_session']??'');
    if($sid===''){
        // One-time migration of valid v1.5 sessions after an upgrade.
        praan_session_begin($id);$sid=(string)$_SESSION['praan_v16_session'];
    }
    $q=$pdo->prepare('SELECT token_hash,revoked_at,expires_at,last_seen_at FROM user_sessions WHERE uid=? AND user_id=?');
    $q->execute([$sid,$id]);$row=$q->fetch();
    if(!$row||$row['revoked_at']!==null||(int)$row['expires_at']<=time()||!hash_equals((string)$row['token_hash'],hash('sha256',session_id()))){
        $_SESSION=[];session_regenerate_id(true);return;
    }
    if(time()-(int)$row['last_seen_at']>=60){$q=$pdo->prepare('UPDATE user_sessions SET last_seen_at=? WHERE uid=?');$q->execute([time(),$sid]);}
    $_SESSION['admin_name']=$u['display_name'];
}
function praan_active_super_count(): int {
    return (int)db()->query("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='super_admin' AND u.deleted_at IS NULL AND u.status='active'")->fetchColumn();
}
function praan_has_super(int $id): bool {return in_array('super_admin',praan_auth_roles($id),true);}
function praan_safe_actor_target(int $targetId,bool $dangerous=false): void {
    if($targetId<=0)throw new RuntimeException('ইউজার পাওয়া যায়নি।');
    if($dangerous&&$targetId===(int)admin_id())throw new RuntimeException('নিজের অ্যাকাউন্ট বন্ধ বা মুছে ফেলা যাবে না।');
    if($dangerous&&praan_has_super($targetId)&&praan_active_super_count()<=1)throw new RuntimeException('সর্বশেষ সক্রিয় Super Admin বন্ধ করা যাবে না।');
}
